<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

use LogicException;
use Orisai\VFS\Wrapper\PermissionHelper;
use function time;

/**
 * @internal
 */
abstract class Node
{

	public const DEFAULT_MODE = 0_755;

	private string $basename;

	private ?Directory $parent = null;

	private int $uid;

	private int $gid;

	private ?int $atime = null;

	private ?int $mtime = null;

	private ?int $ctime = null;

	private int $mode;

	public function __construct(string $basename)
	{
		$this->basename = $basename;
		$this->setMode(self::DEFAULT_MODE);
	}

	abstract public static function getStatType(): int;

	/**
	 * Changes access to file.
	 *
	 * This will apply the DIR/FILE type mask for use by stat to distinguish between file and directory.
	 *
	 * @see https://man7.org/linux/man-pages/man2/lstat.2.html
	 */
	public function setMode(int $mode): void
	{
		$this->mode = $mode | static::getStatType();
	}

	/**
	 * Returns file mode
	 */
	public function getMode(): int
	{
		return $this->mode;
	}

	public function setUser(int $uid): void
	{
		$this->uid = $uid;
	}

	public function getUser(): int
	{
		return $this->uid ?? PermissionHelper::ROOT_ID;
	}

	public function setGroup(int $gid): void
	{
		$this->gid = $gid;
	}

	public function getGroup(): int
	{
		return $this->gid ?? PermissionHelper::ROOT_ID;
	}

	/**
	 * @return int<0, max>
	 */
	abstract public function getSize(): int;

	protected function setParent(Directory $parent): void
	{
		$this->parent = $parent;
	}

	public function getBasename(): string
	{
		return $this->basename;
	}

	public function setBasename(string $basename): void
	{
		$this->basename = $basename;
	}

	public function getPath(): string
	{
		$dirname = $this->getDirname();
		$basename = $this->getBasename();

		return $this->parent instanceof RootDirectory
			? "$dirname$basename"
			: "$dirname/$basename";
	}

	public function getUrl(): string
	{
		if ($this->parent === null) {
			throw new LogicException('Node has no parent');
		}

		$url = $this->parent->getUrl();
		$basename = $this->getBasename();

		return $this->parent instanceof RootDirectory
			? "$url$basename"
			: "$url/$basename";
	}

	public function getDirname(): ?string
	{
		if ($this->parent !== null) {
			return $this->parent->getPath();
		}

		return null;
	}

	public function setAccessTime(int $time): void
	{
		$this->atime = $time;
	}

	public function getAccessTime(): int
	{
		return $this->atime ?? time();
	}

	public function setModificationTime(int $time): void
	{
		$this->mtime = $time;
	}

	public function getModificationTime(): int
	{
		return $this->mtime ?? time();
	}

	public function setChangeTime(int $time): void
	{
		$this->ctime = $time;
	}

	/**
	 * Returns last inode change time (chown etc.)
	 */
	public function getChangeTime(): int
	{
		return $this->ctime ?? time();
	}

	public function __toString(): string
	{
		return $this->getPath();
	}

}
