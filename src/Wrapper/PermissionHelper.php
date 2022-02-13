<?php declare(strict_types = 1);

namespace Orisai\VFS\Wrapper;

use Orisai\VFS\Structure\Node;
use function function_exists;
use function posix_getgid;
use function posix_getuid;

/**
 * @internal
 */
final class PermissionHelper
{

	private const MODE_USER_READ = 0_400,
		MODE_USER_WRITE = 0_200,
		MODE_GROUP_READ = 0_040,
		MODE_GROUP_WRITE = 0_020,
		MODE_WORLD_READ = 0_004,
		MODE_WORLD_WRITE = 0_002;

	public const ROOT_ID = 0;

	private int $uid;

	private int $gid;

	public function __construct(?int $uid = null, ?int $gid = null)
	{
		$this->uid = $uid ?? (function_exists('posix_getuid') ? posix_getuid() : self::ROOT_ID);
		$this->gid = $gid ?? (function_exists('posix_getgid') ? posix_getgid() : self::ROOT_ID);
	}

	public function userIsOwner(Node $node): bool
	{
		return $this->uid === $node->getUser();
	}

	public function userCanRead(Node $node): bool
	{
		return $this->userIsOwner($node) && ($node->getMode() & self::MODE_USER_READ) !== 0;
	}

	public function userCanWrite(Node $node): bool
	{
		return $this->userIsOwner($node) && ($node->getMode() & self::MODE_USER_WRITE) !== 0;
	}

	public function groupIsOwner(Node $node): bool
	{
		return $this->gid === $node->getGroup();
	}

	public function groupCanRead(Node $node): bool
	{
		return $this->groupIsOwner($node) && ($node->getMode() & self::MODE_GROUP_READ) !== 0;
	}

	public function groupCanWrite(Node $node): bool
	{
		return $this->groupIsOwner($node) && ($node->getMode() & self::MODE_GROUP_WRITE) !== 0;
	}

	public function worldCanRead(Node $node): bool
	{
		return ($node->getMode() & self::MODE_WORLD_READ) !== 0;
	}

	public function worldCanWrite(Node $node): bool
	{
		return ($node->getMode() & self::MODE_WORLD_WRITE) !== 0;
	}

	public function isReadable(Node $node): bool
	{
		return $this->userCanRead($node) || $this->groupCanRead($node) || $this->worldCanRead($node);
	}

	public function isWritable(Node $node): bool
	{
		return $this->userCanWrite($node) || $this->groupCanWrite($node) || $this->worldCanWrite($node);
	}

	public function userIsRoot(): bool
	{
		return $this->uid === self::ROOT_ID;
	}

}
