<?php declare(strict_types = 1);

namespace Orisai\VFS\Wrapper;

use ArrayIterator;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;

/**
 * @internal
 */
final class DirectoryHandler
{

	/** @var ArrayIterator<string, Directory|File|Link> */
	private ArrayIterator $iterator;

	public function __construct(Directory $directory)
	{
		$this->iterator = new ArrayIterator($directory->getChildren());
	}

	/**
	 * @return ArrayIterator<string, Directory|File|Link>
	 */
	public function getIterator(): ArrayIterator
	{
		return $this->iterator;
	}

}
