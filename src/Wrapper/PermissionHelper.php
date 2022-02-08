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

	private Node $node;

	private int $uid;

	private int $gid;

	public function __construct(?int $uid = null, ?int $gid = null)
	{
		$this->uid = $uid ?? (function_exists('posix_getuid') ? posix_getuid() : self::ROOT_ID);
		$this->gid = $gid ?? (function_exists('posix_getgid') ? posix_getgid() : self::ROOT_ID);
	}

	public function setNode(Node $node): void
	{
		$this->node = $node;
	}

	public function userIsOwner(): bool
	{
		return $this->uid === $this->node->getUser();
	}

	public function userCanRead(): bool
	{
		return $this->userIsOwner() && ($this->node->getMode() & self::MODE_USER_READ) !== 0;
	}

	public function userCanWrite(): bool
	{
		return $this->userIsOwner() && ($this->node->getMode() & self::MODE_USER_WRITE) !== 0;
	}

	public function groupIsOwner(): bool
	{
		return $this->gid === $this->node->getGroup();
	}

	public function groupCanRead(): bool
	{
		return $this->groupIsOwner() && ($this->node->getMode() & self::MODE_GROUP_READ) !== 0;
	}

	public function groupCanWrite(): bool
	{
		return $this->groupIsOwner() && ($this->node->getMode() & self::MODE_GROUP_WRITE) !== 0;
	}

	public function worldCanRead(): bool
	{
		return ($this->node->getMode() & self::MODE_WORLD_READ) !== 0;
	}

	public function worldCanWrite(): bool
	{
		return ($this->node->getMode() & self::MODE_WORLD_WRITE) !== 0;
	}

	public function isReadable(): bool
	{
		return $this->userCanRead() || $this->groupCanRead() || $this->worldCanRead();
	}

	public function isWritable(): bool
	{
		return $this->userCanWrite() || $this->groupCanWrite() || $this->worldCanWrite();
	}

	public function userIsRoot(): bool
	{
		return $this->uid === self::ROOT_ID;
	}

}
