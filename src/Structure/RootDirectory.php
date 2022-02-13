<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

use LogicException;

/**
 * @internal
 */
final class RootDirectory extends Directory
{

	private const BASENAME = '/';

	public function __construct(int $currentTime, int $uid, int $gid)
	{
		parent::__construct(self::BASENAME, $currentTime, $uid, $gid);
	}

	/**
	 * Defined to prevent setting parent on Root.
	 *
	 * @throws LogicException
	 */
	protected function setParent(Directory $parent): void
	{
		throw new LogicException('Root cannot have a parent.');
	}

	public function getPath(): string
	{
		return self::BASENAME;
	}

}
