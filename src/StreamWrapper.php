<?php declare(strict_types = 1);

namespace Orisai\VFS;

use Orisai\VFS\Exception\PathAlreadyExists;
use Orisai\VFS\Exception\PathIsNotADirectory;
use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use Orisai\VFS\Structure\RootDirectory;
use Orisai\VFS\Wrapper\DirectoryHandler;
use Orisai\VFS\Wrapper\FileHandler;
use Orisai\VFS\Wrapper\PermissionHelper;
use RuntimeException;
use function array_merge;
use function array_values;
use function assert;
use function basename;
use function clearstatcache;
use function current;
use function dirname;
use function end;
use function function_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function ltrim;
use function posix_getgrnam;
use function posix_getpwnam;
use function preg_split;
use function sprintf;
use function str_replace;
use function str_split;
use function time;
use function trigger_error;
use const E_USER_WARNING;
use const SEEK_CUR;
use const SEEK_END;
use const SEEK_SET;
use const STREAM_META_ACCESS;
use const STREAM_META_GROUP;
use const STREAM_META_GROUP_NAME;
use const STREAM_META_OWNER;
use const STREAM_META_OWNER_NAME;
use const STREAM_META_TOUCH;
use const STREAM_MKDIR_RECURSIVE;
use const STREAM_REPORT_ERRORS;

/**
 * phpcs:disable Generic.NamingConventions.CamelCapsFunctionName.ScopeNotCamelCaps
 *
 * @see  https://www.php.net/streamwrapper
 * @internal
 */
final class StreamWrapper
{

	private ?FileHandler $currentFile;

	private ?DirectoryHandler $currentDir = null;

	/** @var array<string, Container> */
	public static array $containers = [];

	/**
	 * Returns path stripped of url scheme (http://, ftp://, test:// etc.)
	 */
	public function stripScheme(string $path): string
	{
		$scheme = preg_split('#://#', $path, 2);
		assert($scheme !== false);
		$scheme = end($scheme);
		assert($scheme !== false);

		return '/' . ltrim($scheme, '/');
	}

	public static function getContainer(string $path): Container
	{
		$scheme = preg_split('#://#', $path);
		assert($scheme !== false);
		$scheme = current($scheme);
		assert($scheme !== false);

		return self::$containers[$scheme];
	}

	/**
	 * @see https://www.php.net/streamwrapper.stream-tell
	 */
	public function stream_tell(): int
	{
		assert($this->currentFile !== null);

		return $this->currentFile->position();
	}

	/**
	 * @return false
	 *
	 * @see https://www.php.net/streamwrapper.stream-cast
	 */
	public function stream_cast(int $cast_as): bool
	{
		return false;
	}

	/**
	 * @see https://www.php.net/streamwrapper.stream-close
	 */
	public function stream_close(): void
	{
		$this->currentFile = null;
	}

	/**
	 * @see  https://www.php.net/streamwrapper.stream-open
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
	{
		$container = self::getContainer($path);
		$path = $this->stripScheme($path);

		$modes = str_split(str_replace('b', '', $mode));

		$accessDeniedError = static function () use ($path, $options): bool {
			if (($options & STREAM_REPORT_ERRORS) !== 0) {
				trigger_error(sprintf('fopen(%s): failed to open stream: Permission denied', $path), E_USER_WARNING);
			}

			return false;
		};

		$appendMode = in_array('a', $modes, true);
		$readMode = in_array('r', $modes, true);
		$writeMode = in_array('w', $modes, true);
		$extended = in_array('+', $modes, true);

		if (!$container->hasNodeAt($path)) {
			if ($readMode || !$container->hasNodeAt(dirname($path))) {
				if (($options & STREAM_REPORT_ERRORS) !== 0) {
					trigger_error(sprintf('%s: failed to open stream.', $path), E_USER_WARNING);
				}

				return false;
			}

			$parent = $container->getDirectoryAt(dirname($path));
			$permissionHelper = $container->getPermissionHelper();
			if (!$permissionHelper->isWritable($parent)) {
				return $accessDeniedError();
			}

			$parent->addFile($container->getFactory()->createFile(basename($path)));
		}

		$file = $container->getNodeAt($path);

		if ($file instanceof Link) {
			$file = $file->getResolvedDestination();
		}

		if (($extended || $writeMode || $appendMode) && $file instanceof Directory) {
			if (($options & STREAM_REPORT_ERRORS) !== 0) {
				trigger_error(sprintf('fopen(%s): failed to open stream: Is a directory', $path), E_USER_WARNING);
			}

			return false;
		}

		if ($file instanceof Directory) {
			$dir = $file;
			$file = $container->getFactory()->createFile('tmp');
			$file->setMode($dir->getMode());
			$file->setUser($dir->getUser());
			$file->setGroup($dir->getGroup());
		}

		$permissionHelper = $container->getPermissionHelper();

		$this->currentFile = new FileHandler($file);

		if ($extended) {
			if (!$permissionHelper->isReadable($file) || !$permissionHelper->isWritable($file)) {
				return $accessDeniedError();
			}

			$this->currentFile->setReadWriteMode();
		} elseif ($readMode) {
			if (!$permissionHelper->isReadable($file)) {
				return $accessDeniedError();
			}

			$this->currentFile->setReadOnlyMode();
		} else { // a or w are for write only
			if (!$permissionHelper->isWritable($file)) {
				return $accessDeniedError();
			}

			$this->currentFile->setWriteOnlyMode();
		}

		if ($appendMode) {
			$this->currentFile->seekToEnd();
		} elseif ($writeMode) {
			$this->currentFile->truncate();
			clearstatcache();
		}

		$opened_path = $file->getPath();

		return true;
	}

	/**
	 * @see https://www.php.net/streamwrapper.stream-write
	 */
	public function stream_write(string $data): int
	{
		assert($this->currentFile !== null);

		if (!$this->currentFile->isOpenedForWriting()) {
			return 0;
		}

		//file access time changes so stat cache needs to be cleared
		$written = $this->currentFile->write($data);
		clearstatcache();

		return $written;
	}

	/**
	 * @return string|false
	 *
	 * @see https://www.php.net/streamwrapper.stream-read
	 */
	public function stream_read(int $count)
	{
		assert($this->currentFile !== null);
		if (!$this->currentFile->isOpenedForReading()) {
			return false;
		}

		$data = $this->currentFile->read($count);
		//file access time changes so stat cache needs to be cleared
		clearstatcache();

		return $data !== '' ? $data : false;
	}

	/**
	 * @see https://www.php.net/streamwrapper.stream-eof
	 */
	public function stream_eof(): bool
	{
		assert($this->currentFile !== null);

		return $this->currentFile->isAtEof();
	}

	/**
	 * @see https://www.php.net/streamwrapper.stream-flush
	 */
	public function stream_flush(): bool
	{
		// Because we don't buffer
		return true;
	}

	/**
	 * @param int|string|array<int> $value
	 *
	 * @see https://www.php.net/streamwrapper.stream-metadata
	 */
	public function stream_metadata(string $path, int $option, $value): bool
	{
		$container = self::getContainer($path);
		$strippedPath = $this->stripScheme($path);

		if ($option === STREAM_META_TOUCH) {
			assert(is_array($value) || is_int($value));

			if (!$container->hasNodeAt($strippedPath)) {
				try {
					$container->createFile($strippedPath);
				} catch (PathNotFound $e) {
					trigger_error(
						sprintf('touch: %s: No such file or directory.', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}
			}

			$node = $container->getNodeAt($strippedPath);

			$permissionHelper = $container->getPermissionHelper();

			if (!$permissionHelper->userIsOwner($node) && !$permissionHelper->isWritable($node)) {
				trigger_error(
					sprintf('touch: %s: Permission denied', $strippedPath),
					E_USER_WARNING,
				);

				return false;
			}

			if (is_array($value)) {
				$time = $value[0] ?? time();
				$atime = $value[1] ?? $time;
			} else {
				$time = $atime = time();
			}

			$node->setChangeTime($time);
			$node->setModificationTime($time);
			$node->setAccessTime($atime);

			clearstatcache(true, $path);

			return true;
		}

		try {
			$node = $container->getNodeAt($strippedPath);
		} catch (PathNotFound $exception) {
			return false;
		}

		$permissionHelper = $container->getPermissionHelper();

		switch ($option) {
			case STREAM_META_ACCESS:
				assert(is_int($value));

				if ($node instanceof Link) {
					$node = $node->getDestination();
				}

				if (!$permissionHelper->userIsOwner($node)) {
					trigger_error(
						sprintf('chmod: %s: Permission denied', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}

				$node->setMode($value);
				$node->setChangeTime(time());

				break;
			case STREAM_META_OWNER_NAME:
				assert(is_string($value));

				if (!$permissionHelper->userIsRoot() && !$permissionHelper->userIsOwner($node)) {
					trigger_error(
						sprintf('chown: %s: Permission denied', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}

				$uid = PermissionHelper::ROOT_ID;

				if (function_exists('posix_getpwnam')) {
					$user = posix_getpwnam($value);

					if ($user !== false) {
						$uid = $user['uid'];
					}
				}

				$node->setUser($uid);
				$node->setChangeTime(time());

				break;
			case STREAM_META_OWNER:
				assert(is_int($value));

				if (!$permissionHelper->userIsRoot() && !$permissionHelper->userIsOwner($node)) {
					trigger_error(
						sprintf('chown: %s: Permission denied', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}

				$node->setUser($value);
				$node->setChangeTime(time());

				break;
			case STREAM_META_GROUP_NAME:
				assert(is_string($value));

				if (!$permissionHelper->userIsRoot() && !$permissionHelper->userIsOwner($node)) {
					trigger_error(
						sprintf('chgrp: %s: Permission denied', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}

				$gid = PermissionHelper::ROOT_ID;

				if (function_exists('posix_getgrnam')) {
					$group = posix_getgrnam($value);

					if ($group !== false) {
						$gid = $group['gid'];
					}
				}

				$node->setGroup($gid);
				$node->setChangeTime(time());

				break;
			case STREAM_META_GROUP:
				assert(is_int($value));

				if (!$permissionHelper->userIsRoot() && !$permissionHelper->userIsOwner($node)) {
					trigger_error(
						sprintf('chgrp: %s: Permission denied', $strippedPath),
						E_USER_WARNING,
					);

					return false;
				}

				$node->setGroup($value);
				$node->setChangeTime(time());

				break;
		}

		clearstatcache(true, $path);

		return true;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.stream-seek.php
	 */
	public function stream_seek(int $offset, int $whence = SEEK_SET): bool
	{
		assert($this->currentFile !== null);

		switch ($whence) {
			case SEEK_SET:
				$this->currentFile->position($offset);

				break;
			case SEEK_CUR:
				$this->currentFile->offsetPosition($offset);

				break;
			case SEEK_END:
				$this->currentFile->seekToEnd();
				$this->currentFile->offsetPosition($offset);
		}

		return true;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.stream-truncate.php
	 */
	public function stream_truncate(int $new_size): bool
	{
		assert($this->currentFile !== null);

		$this->currentFile->truncate($new_size);
		clearstatcache();

		return true;
	}

	/**
	 * @phpstan-param Lock::LOCK_* $operation
	 */
	public function stream_lock(int $operation): bool
	{
		assert($this->currentFile !== null);

		return $this->currentFile->lock($this, $operation);
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.stream-set-option.php
	 */
	public function stream_set_option(int $option, int $arg1, int $arg2): bool
	{
		return false;
	}

	/**
	 * @return array<int|string, int>
	 *
	 * @see https://www.php.net/streamwrapper.stream-stat
	 */
	public function stream_stat(): array
	{
		assert($this->currentFile !== null);
		$file = $this->currentFile->getFile();

		return array_merge($this->getStatDefault(), [
			'mode' => $file->getMode(),
			'uid' => $file->getUser(),
			'gid' => $file->getGroup(),
			'atime' => $file->getAccessTime(),
			'mtime' => $file->getModificationTime(),
			'ctime' => $file->getChangeTime(),
			'size' => $file->getSize(),
		]);
	}

	/**
	 * @return array<int|string, int>|false
	 *
	 * @see https://www.php.net/stat
	 */
	public function url_stat(string $path, int $flags)
	{
		try {
			$file = self::getContainer($path)->getNodeAt($this->stripScheme($path));

			return array_merge($this->getStatDefault(), [
				'mode' => $file->getMode(),
				'uid' => $file->getUser(),
				'gid' => $file->getGroup(),
				'atime' => $file->getAccessTime(),
				'mtime' => $file->getModificationTime(),
				'ctime' => $file->getChangeTime(),
				'size' => $file->getSize(),
			]);
		} catch (PathNotFound $e) {
			return false;
		}
	}

	/**
	 * @return array<int|string, int>
	 *
	 * @see https://www.php.net/stat
	 */
	private function getStatDefault(): array
	{
		$assoc = [
			'dev' => 0,
			'ino' => 0,
			'mode' => 0,
			'nlink' => 0,
			'uid' => 0,
			'gid' => 0,
			'rdev' => 0,
			'size' => 123,
			'atime' => 0,
			'mtime' => 0,
			'ctime' => 0,
			'blksize' => -1,
			'blocks' => -1,
		];

		return array_merge(array_values($assoc), $assoc);
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.rename.php
	 */
	public function rename(string $path_from, string $path_to): bool
	{
		$container = self::getContainer($path_to);
		$path_from = $this->stripScheme($path_from);
		$path_to = $this->stripScheme($path_to);

		try {
			$container->move($path_from, $path_to);
		} catch (PathNotFound $e) {
			trigger_error(
				sprintf('mv: rename %s to %s: No such file or directory', $path_from, $path_to),
				E_USER_WARNING,
			);

			return false;
		} catch (RuntimeException $e) {
			trigger_error(
				sprintf('mv: rename %s to %s: Not a directory', $path_from, $path_to),
				E_USER_WARNING,
			);

			return false;
		}

		return true;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.unlink.php
	 */
	public function unlink(string $path): bool
	{
		$container = self::getContainer($path);
		$permissionHelper = $container->getPermissionHelper();

		try {

			$path = $this->stripScheme($path);

			$parent = $container->getNodeAt(dirname($path));

			if (!$permissionHelper->isWritable($parent)) {
				trigger_error(
					sprintf('rm: %s: Permission denied', $path),
					E_USER_WARNING,
				);

				return false;
			}

			$container->remove($path = $this->stripScheme($path));
		} catch (PathNotFound $e) {
			trigger_error(
				sprintf('rm: %s: No such file or directory', $path),
				E_USER_WARNING,
			);

			return false;
		} catch (RuntimeException $e) {
			trigger_error(
				sprintf('rm: %s: is a directory', $path),
				E_USER_WARNING,
			);

			return false;
		}

		return true;
	}

	/**
	 * @see https://www.php.net/streamwrapper.mkdir
	 */
	public function mkdir(string $path, int $mode, int $options): bool
	{
		$container = self::getContainer($path);
		$path = $this->stripScheme($path);
		$recursive = (bool) ($options & STREAM_MKDIR_RECURSIVE);
		$permissionHelper = $container->getPermissionHelper();

		try {
			//need to check all parents for permissions
			$parentPath = $path;
			while ($parentPath = dirname($parentPath)) {
				try {
					$parent = $container->getNodeAt($parentPath);
					if (!$permissionHelper->isWritable($parent)) {
						trigger_error(sprintf('mkdir: %s: Permission denied', dirname($path)), E_USER_WARNING);

						return false;
					}

					if ($parent instanceof RootDirectory) {
						break;
					}
				} catch (PathNotFound $e) {
					break; //will sort missing parent below
				}
			}

			$container->createDir($path, $recursive, $mode);
		} catch (PathAlreadyExists $e) {
			trigger_error($e->getMessage(), E_USER_WARNING);

			return false;
		} catch (PathNotFound $e) {
			trigger_error(sprintf('mkdir: %s: No such file or directory', dirname($path)), E_USER_WARNING);

			return false;
		}

		return true;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.dir-opendir.php
	 */
	public function dir_opendir(string $path, int $options): bool
	{
		$container = self::getContainer($path);

		$path = $this->stripScheme($path);

		if (!$container->hasNodeAt($path)) {
			trigger_error(sprintf('opendir(%s): failed to open dir: No such file or directory', $path), E_USER_WARNING);

			return false;
		}

		try {

			$dir = $container->getDirectoryAt($path);

		} catch (PathIsNotADirectory $e) {
			trigger_error(sprintf('opendir(%s): failed to open dir: Not a directory', $path), E_USER_WARNING);

			return false;
		}

		$permissionHelper = $container->getPermissionHelper();

		if (!$permissionHelper->isReadable($dir)) {
			trigger_error(sprintf('opendir(%s): failed to open dir: Permission denied', $path), E_USER_WARNING);

			return false;
		}

		$this->currentDir = new DirectoryHandler($dir);

		return true;
	}

	/**
	 * @return string|false
	 *
	 * @see https://www.php.net/manual/en/streamwrapper.dir-readdir.php
	 */
	public function dir_readdir()
	{
		assert($this->currentDir !== null);

		$iterator = $this->currentDir->getIterator();
		if (!$iterator->valid()) {
			return false;
		}

		$node = $iterator->current();
		$iterator->next();

		return $node->getBasename();
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.dir-closedir.php
	 */
	public function dir_closedir(): bool
	{
		if ($this->currentDir !== null) {
			$this->currentDir = null;

			return true;
		}

		return false;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.dir-rewinddir.php
	 */
	public function dir_rewinddir(): bool
	{
		assert($this->currentDir !== null);
		$this->currentDir->getIterator()->rewind();

		return true;
	}

	/**
	 * @see https://www.php.net/manual/en/streamwrapper.rmdir.php
	 */
	public function rmdir(string $path, int $options): bool
	{
		$container = self::getContainer($path);
		$path = $this->stripScheme($path);

		try {
			$directory = $container->getNodeAt($path);

			if ($directory instanceof File) {
				trigger_error(
					sprintf('Warning: rmdir(%s): Not a directory', $path),
					E_USER_WARNING,
				);

				return false;
			}

			$permissionHelper = $container->getPermissionHelper();
			if (!$permissionHelper->isReadable($directory)) {
				trigger_error(
					sprintf('rmdir: %s: Permission denied', $path),
					E_USER_WARNING,
				);

				return false;
			}
		} catch (PathNotFound $e) {
			trigger_error(
				sprintf('Warning: rmdir(%s): No such file or directory', $path),
				E_USER_WARNING,
			);

			return false;
		}

		if ($directory->getSize() !== 0) {
			trigger_error(
				sprintf('Warning: rmdir(%s): Directory not empty', $path),
				E_USER_WARNING,
			);

			return false;
		}

		$container->remove($path, true);

		return true;
	}

}
