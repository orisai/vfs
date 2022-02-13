<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

use LogicException;
use RuntimeException;
use function explode;

/**
 * @internal
 */
final class RootDirectory extends Directory
{

	private const BASENAME = '/';

	private ?string $scheme = null;

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

	/**
	 * Set root scheme for use in path method.
	 */
	public function setScheme(string $scheme): void
	{
		[$scheme] = explode(':', $scheme);
		$this->scheme = $scheme . '://';
	}

	public function getPath(): string
	{
		return self::BASENAME;
	}

	public function getUrl(): string
	{
		if ($this->scheme === null) {
			throw new RuntimeException('No scheme set');
		}

		return $this->scheme;
	}

}
