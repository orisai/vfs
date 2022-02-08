<?php declare(strict_types = 1);

namespace Orisai\VFS\Wrapper;

use Orisai\VFS\Lock;
use Orisai\VFS\StreamWrapper;
use Orisai\VFS\Structure\File;
use function min;
use function strlen;
use function substr;
use function time;

/**
 * @internal
 */
final class FileHandler
{

	private const READ_MODE = 1,
		WRITE_MODE = 2;

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
	 * Returns number of written bytes.
	 */
	public function write(string $data): int
	{
		$content = $this->file->getData();
		$content = substr($content, 0, $this->position());
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

		$return = substr($content, $this->position(), $bytes);

		$newPosition = $this->position() + $bytes;
		$newPosition = min($newPosition, strlen($content));
		$this->position($newPosition);

		$this->file->setAccessTime(time());

		return $return;
	}

	public function position(?int $position = null): int
	{
		return $position === null ? $this->position : $this->position = $position;
	}

	/**
	 * Moves pointer to the end of file
	 */
	public function seekToEnd(): int
	{
		return $this->position(strlen($this->file->getData()));
	}

	public function offsetPosition(int $offset): void
	{
		$this->position += $offset;
	}

	public function isAtEof(): bool
	{
		$data = $this->file->getData();

		return $this->position() >= strlen($data);
	}

	/**
	 * Removes all data from file and sets pointer to 0
	 */
	public function truncate(int $newSize = 0): void
	{
		$this->position(0);
		$data = $this->file->getData();
		$newData = substr($data, 0, $newSize);
		$this->file->setData($newData);
		$this->file->setModificationTime(time());
		$this->file->setChangeTime(time());
	}

	public function setReadOnlyMode(): void
	{
		$this->mode = self::READ_MODE;
	}

	public function setReadWriteMode(): void
	{
		$this->mode = self::READ_MODE | self::WRITE_MODE;
	}

	public function setWriteOnlyMode(): void
	{
		$this->mode = self::WRITE_MODE;
	}

	public function isOpenedForWriting(): bool
	{
		return (bool) ($this->mode & self::WRITE_MODE);
	}

	public function isOpenedForReading(): bool
	{
		return (bool) ($this->mode & self::READ_MODE);
	}

	/**
	 * @phpstan-param Lock::LOCK_* $operation
	 */
	public function lock(StreamWrapper $wrapper, int $operation): bool
	{
		return $this->file->lock($wrapper, $operation);
	}

}
