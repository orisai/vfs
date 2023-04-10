<?php declare(strict_types = 1);

namespace Orisai\VFS;

use Orisai\VFS\Exception\PathIsNotADirectory;
use Orisai\VFS\Exception\PathIsNotAFile;
use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use Orisai\VFS\Structure\Node;
use Orisai\VFS\Structure\RootDirectory;
use Orisai\VFS\Wrapper\PermissionChecker;
use RuntimeException;
use function array_filter;
use function basename;
use function clearstatcache;
use function dirname;
use function explode;
use function get_class;
use function is_a;
use function sprintf;
use function str_replace;

/**
 * @internal
 */
final class Container
{

	private RootDirectory $root;

	private Factory $factory;

	private PermissionChecker $permissionChecker;

	public function __construct(Factory $factory)
	{
		$this->factory = $factory;
		$this->root = $factory->createRoot();
		$this->permissionChecker = new PermissionChecker($factory->getUid(), $factory->getGid());
	}

	public function getFactory(): Factory
	{
		return $this->factory;
	}

	public function getRootDirectory(): RootDirectory
	{
		return $this->root;
	}

	/**
	 * @return Directory|File|Link
	 * @throws PathNotFound
	 */
	public function getNodeAt(string $path): Node
	{
		$pathParts = array_filter(explode('/', str_replace('\\', '/', $path)), 'strlen');

		$node = $this->getRootDirectory();

		foreach ($pathParts as $level) {
			if ($node instanceof Link) {
				$node = $node->getResolvedDestination();
			}

			if ($node instanceof File) {
				throw new PathNotFound();
			}

			$node = $node->getChild($level);
		}

		return $node;
	}

	public function hasNodeAt(string $path): bool
	{
		try {
			$this->getNodeAt($path);

			return true;
		} catch (PathNotFound $e) {
			return false;
		}
	}

	/**
	 * @throws PathIsNotADirectory
	 * @throws PathNotFound
	 */
	public function getDirectoryAt(string $path): Directory
	{
		$file = $this->getNodeAt($path);

		if (!$file instanceof Directory) {
			throw new PathIsNotADirectory();
		}

		return $file;
	}

	/**
	 * @throws PathIsNotAFile
	 * @throws PathNotFound
	 */
	public function getFileAt(string $path): File
	{
		$file = $this->getNodeAt($path);

		if (!$file instanceof File) {
			throw new PathIsNotAFile();
		}

		return $file;
	}

	/**
	 * @throws PathNotFound
	 */
	public function createDir(
		string $path,
		bool $recursive = false,
		?int $mode = null
	): Directory
	{
		$parentPath = dirname($path);
		$name = basename($path);

		try {
			$parent = $this->getDirectoryAt($parentPath);
		} catch (PathNotFound $e) {
			if (!$recursive) {
				throw new PathNotFound(sprintf('createDir: %s: No such file or directory', $parentPath));
			}

			$parent = $this->createDir($parentPath, $recursive, $mode);
		}

		$parent->addDirectory($newDirectory = $this->getFactory()->createDir($name));

		if ($mode !== null) {
			$newDirectory->setMode($mode);
		}

		return $newDirectory;
	}

	public function createLink(string $path, string $destination): Link
	{
		$node = $this->getNodeAt($destination);

		if ($this->hasNodeAt($path)) {
			throw new RuntimeException(sprintf('%s already exists', $path));
		}

		$parent = $this->getDirectoryAt(dirname($path));

		$newLink = $this->getFactory()->createLink(basename($path), $node);
		$parent->addLink($newLink);

		return $newLink;
	}

	/**
	 * @throws PathNotFound
	 */
	public function createFile(string $path, string $data = ''): File
	{
		if ($this->hasNodeAt($path)) {
			throw new RuntimeException(sprintf('%s already exists', $path));
		}

		$parent = $this->getDirectoryAt(dirname($path));

		$parent->addFile($newFile = $this->getFactory()->createFile(basename($path)));

		$newFile->setData($data);

		return $newFile;
	}

	/**
	 * @throws PathNotFound
	 * @throws RuntimeException
	 */
	public function move(string $fromPath, string $toPath): void
	{
		$fromNode = $this->getNodeAt($fromPath);

		try {
			$nodeToOverride = $this->getNodeAt($toPath);

			if (!is_a($nodeToOverride, get_class($fromNode))) {
				//nodes of a different type
				throw new RuntimeException('Can\'t move.');
			}

			if ($nodeToOverride instanceof Directory && $nodeToOverride->getSize() > Directory::EmptyDirSize) {
				//nodes of a different type
				throw new RuntimeException('Can\'t override non empty directory.');
			}

			$this->remove($toPath, true);

		} catch (PathNotFound $e) {
			//nothing at destination, we're good
		}

		$toParent = $this->getDirectoryAt(dirname($toPath));

		$fromNode->setBasename(basename($toPath));

		$toParent->addChild($fromNode);

		$this->remove($fromPath, true);
	}

	/**
	 * @throws RuntimeException
	 */
	public function remove(string $path, bool $recursive = false): void
	{
		$fileToRemove = $this->getNodeAt($path);

		if (!$recursive && $fileToRemove instanceof Directory) {
			throw new RuntimeException('Won\'t non-recursively remove directory');
		}

		$this->getDirectoryAt(dirname($path))->removeChild(basename($path));

		clearstatcache(true, $path);
	}

	public function getPermissionChecker(): PermissionChecker
	{
		return $this->permissionChecker;
	}

	public function setPermissionChecker(PermissionChecker $permissionChecker): void
	{
		$this->permissionChecker = $permissionChecker;
	}

}
