<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use PHPUnit\Framework\TestCase;
use function time;

final class LinkTest extends TestCase
{

	public function testFileSizeAssumesTargetSize(): void
	{
		$node = new File('file', time(), 0, 0);
		$node->setData('12345');

		$link = new Link($node, 'link', time(), 0, 0);

		self::assertEquals($node->getSize(), $link->getSize());

		$dir = new Directory('/d', time(), 0, 0);

		new Link($dir, 'link', time(), 0, 0);

		self::assertEquals($dir->getSize(), $dir->getSize());

		$dir->addFile($node);

		self::assertEquals($dir->getSize(), $dir->getSize());
	}

}
