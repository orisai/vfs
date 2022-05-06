<?php declare(strict_types = 1);

namespace Orisai\VFS\Wrapper;

use Orisai\VFS\Structure\Node;

/**
 * @internal
 */
final class PermissionChecker
{

	private const ModeUserRead = 0_400,
		ModeUserWrite = 0_200,
		ModeGroupRead = 0_040,
		ModeGroupWrite = 0_020,
		ModeWorldRead = 0_004,
		ModeWorldWrite = 0_002;

	public const RootId = 0;

	private int $uid;

	private int $gid;

	public function __construct(int $uid, int $gid)
	{
		$this->uid = $uid;
		$this->gid = $gid;
	}

	public function userIsOwner(Node $node): bool
	{
		return $this->uid === $node->getUser();
	}

	public function userCanRead(Node $node): bool
	{
		return $this->userIsOwner($node) && ($node->getMode() & self::ModeUserRead) !== 0;
	}

	public function userCanWrite(Node $node): bool
	{
		return $this->userIsOwner($node) && ($node->getMode() & self::ModeUserWrite) !== 0;
	}

	public function groupIsOwner(Node $node): bool
	{
		return $this->gid === $node->getGroup();
	}

	public function groupCanRead(Node $node): bool
	{
		return $this->groupIsOwner($node) && ($node->getMode() & self::ModeGroupRead) !== 0;
	}

	public function groupCanWrite(Node $node): bool
	{
		return $this->groupIsOwner($node) && ($node->getMode() & self::ModeGroupWrite) !== 0;
	}

	public function worldCanRead(Node $node): bool
	{
		return ($node->getMode() & self::ModeWorldRead) !== 0;
	}

	public function worldCanWrite(Node $node): bool
	{
		return ($node->getMode() & self::ModeWorldWrite) !== 0;
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
		return $this->uid === self::RootId;
	}

}
