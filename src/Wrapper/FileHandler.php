<?php declare(strict_types = 1);

namespace Orisai\VFS\Wrapper;

use Orisai\VFS\Lock;
use Orisai\VFS\Structure\File;
use Orisai\VFS\VfsStreamWrapper;
use function assert;
use function min;
use function strlen;
use function substr;
use function time;

/**
 * @internal
 */
final class FileHandler
{

	private const ReadMode = 1,
		WriteMode = 2;

	/** @var int<0, max> */
	private int $position = 0;

	private int $mode = 0;

	private File $file;

	public function __construct(File $file)
	{
		$this->file = $file;
	}

	public function getFile(): File
	{
		return $this->file;
	}

	/**
	 * Writes to file and moves pointer.
	 *
	 * @return int<0, max> number of written bytes
	 */
	public function write(string $data): int
	{
		$content = $this->file->getData();
		$content = substr($content, 0, $this->getPosition());
		$content .= $data;
		$this->file->setData($content);
		$written = strlen($data);
		$this->offsetPosition($written);
		$this->file->setModificationTime(time());
		$this->file->setChangeTime(time());

		return $written;
	}

	/**
	 * Reads from file and moves pointer.
	 */
	public function read(int $bytes): string
	{
		$content = $this->file->getData();

		$return = substr($content, $this->getPosition(), $bytes);

		$newPosition = $this->getPosition() + $bytes;
		$newPosition = min($newPosition, strlen($content));
		assert($newPosition >= 0);
		$this->setPosition($newPosition);

		$this->file->setAccessTime(time());

		return $return;
	}

	/**
	 * @return int<0, max>
	 */
	public function getPosition(): int
	{
		return $this->position;
	}

	/**
	 * @param int<0, max> $position
	 */
	public function setPosition(int $position): void
	{
		$this->position = $position;
	}

	/**
	 * Moves pointer to the end of file
	 */
	public function seekToEnd(): int
	{
		$position = strlen($this->file->getData());
		$this->setPosition($position);

		return $position;
	}

	/**
	 * @param int<0, max> $offset
	 */
	public function offsetPosition(int $offset): void
	{
		$this->position += $offset;
	}

	public function isAtEof(): bool
	{
		$data = $this->file->getData();

		return $this->getPosition() >= strlen($data);
	}

	/**
	 * Removes all data from file and sets pointer to 0
	 */
	public function truncate(int $newSize = 0): void
	{
		$this->setPosition(0);
		$data = $this->file->getData();
		$newData = substr($data, 0, $newSize);
		$this->file->setData($newData);
		$this->file->setModificationTime(time());
		$this->file->setChangeTime(time());
	}

	public function setReadOnlyMode(): void
	{
		$this->mode = self::ReadMode;
	}

	public function setReadWriteMode(): void
	{
		$this->mode = self::ReadMode | self::WriteMode;
	}

	public function setWriteOnlyMode(): void
	{
		$this->mode = self::WriteMode;
	}

	public function isOpenedForWriting(): bool
	{
		return (bool) ($this->mode & self::WriteMode);
	}

	public function isOpenedForReading(): bool
	{
		return (bool) ($this->mode & self::ReadMode);
	}

	/**
	 * @phpstan-param Lock::LOCK_* $operation
	 */
	public function lock(VfsStreamWrapper $wrapper, int $operation): bool
	{
		return $this->file->lock($wrapper, $operation);
	}

}
