<?php declare(strict_types = 1);

namespace Orisai\VFS\Structure;

/**
 * @internal
 */
final class Link extends Node
{

	/** @var Directory|File|Link */
	private Node $destination;

	/**
	 * @param Directory|File|Link $destination
	 */
	public function __construct(string $basename, Node $destination)
	{
		parent::__construct($basename);
		$this->destination = $destination;
	}

	public static function getStatType(): int
	{
		return 0_120_000;
	}

	/**
	 * Returns destination size, not size of link itself
	 */
	public function getSize(): int
	{
		return $this->destination->getSize();
	}

	public function getDestination(): Node
	{
		return $this->destination;
	}

	/**
	 * @return Directory|File
	 */
	public function getResolvedDestination(): Node
	{
		return $this->resolve($this->destination);
	}

	/**
	 * @param Directory|File|Link $node
	 * @return Directory|File
	 */
	private function resolve(Node $node): Node
	{
		if ($node instanceof Link) {
			return $node->getResolvedDestination();
		}

		return $node;
	}

}
