<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

use Orisai\VFS\Exception\PathAlreadyExists;
use Orisai\VFS\Exception\PathNotFound;
use function array_key_exists;
use function count;

/**
 * @internal
 */
class Directory extends Node
{

	/** @var array<string, Directory|File|Link> */
	private array $children = [];

	public static function getStatType(): int
	{
		return 0_040_000;
	}

	/**
	 * @param Directory|File|Link $node
	 * @throws PathAlreadyExists
	 */
	public function addChild(Node $node): void
	{
		if (array_key_exists($node->getBasename(), $this->children)) {
			throw new PathAlreadyExists("{$node->getBasename()} already exists");
		}

		$this->children[$node->getBasename()] = $node;
		$node->setParent($this);
	}

	public function addDirectory(Directory $directory): void
	{
		$this->addChild($directory);
	}

	public function addFile(File $file): void
	{
		$this->addChild($file);
	}

	public function addLink(Link $link): void
	{
		$this->addChild($link);
	}

	/**
	 * Returns number of (direct) children
	 */
	public function getSize(): int
	{
		return count($this->children);
	}

	/**
	 * @return Directory|File|Link
	 * @throws PathNotFound
	 */
	public function getChild(string $path): Node
	{
		if (!array_key_exists($path, $this->children)) {
			throw new PathNotFound("Could not find child $path in {$this->getPath()}");
		}

		return $this->children[$path];
	}

	public function removeChild(string $basename): void
	{
		unset($this->children[$basename]);
	}

	/**
	 * @return array<string, Directory|File|Link>
	 */
	public function getChildren(): array
	{
		return $this->children;
	}

}
