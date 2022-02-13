<?php declare(strict_types = 1);

namespace Orisai\VFS;

use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use Orisai\VFS\Structure\Node;
use Orisai\VFS\Structure\RootDirectory;
use Orisai\VFS\Wrapper\PermissionHelper;
use function function_exists;
use function posix_getgid;
use function posix_getuid;
use function time;

/**
 * @internal
 */
final class Factory
{

	private int $uid;

	private int $gid;

	/**
	 * Sets user/group to current system user/group.
	 */
	public function __construct()
	{
		$this->uid = function_exists('posix_getuid') ? posix_getuid() : PermissionHelper::ROOT_ID;
		$this->gid = function_exists('posix_getgid') ? posix_getgid() : PermissionHelper::ROOT_ID;
	}

	public function createRoot(): RootDirectory
	{
		return new RootDirectory(time(), $this->uid, $this->gid);
	}

	public function createDir(string $basename): Directory
	{
		return new Directory($basename, time(), $this->uid, $this->gid);
	}

	public function createFile(string $basename): File
	{
		return new File($basename, time(), $this->uid, $this->gid);
	}

	/**
	 * @param Directory|File|Link $destination
	 */
	public function createLink(string $basename, Node $destination): Link
	{
		return new Link($destination, $basename, time(), $this->uid, $this->gid);
	}

}
