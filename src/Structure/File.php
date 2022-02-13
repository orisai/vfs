<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

use Orisai\VFS\Lock;
use Orisai\VFS\StreamWrapper;
use SplObjectStorage;
use function strlen;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

/**
 * @internal
 */
final class File extends Node
{

	private string $data = '';

	private ?StreamWrapper $exclusiveLock = null;

	/** @var SplObjectStorage<StreamWrapper, mixed> */
	private SplObjectStorage $sharedLock;

	/**
	 * @inherit
	 */
	public function __construct(string $basename, int $currentTime, int $uid, int $gid)
	{
		parent::__construct($basename, $currentTime, $uid, $gid);
		$this->sharedLock = new SplObjectStorage();
	}

	public static function getStatType(): int
	{
		return 0_100_000;
	}

	/**
	 * Returns size of file in bytes
	 */
	public function getSize(): int
	{
		return strlen($this->data);
	}

	public function getData(): string
	{
		return $this->data;
	}

	public function setData(string $data): void
	{
		$this->data = $data;
	}

	/**
	 * @phpstan-param Lock::LOCK_* $operation
	 */
	public function lock(StreamWrapper $wrapper, int $operation): bool
	{
		if ($this->exclusiveLock === $wrapper) {
			$this->exclusiveLock = null;
		} else {
			$this->sharedLock->detach($wrapper);
		}

		if (($operation & LOCK_NB) !== 0) {
			$operation -= LOCK_NB;
		}

		$unlock = $operation === LOCK_UN;
		$exclusive = $operation === LOCK_EX;

		if ($unlock) {
			return true;
		}

		if ($this->exclusiveLock !== null) {
			return false;
		}

		if (!$exclusive) {
			$this->sharedLock->attach($wrapper);

			return true;
		}

		if ($this->sharedLock->count() !== 0) {
			return false;
		}

		$this->exclusiveLock = $wrapper;

		return true;
	}

}
