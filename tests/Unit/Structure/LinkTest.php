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
		$nodeLink = new Link($node, 'link', time(), 0, 0);
		self::assertSame($node->getSize(), $nodeLink->getSize());

		$dir = new Directory('/d', time(), 0, 0);
		$dirLink = new Link($dir, 'link', time(), 0, 0);
		self::assertSame($dir->getSize(), $dirLink->getSize());

		$dir->addFile($node);
		self::assertSame($dir->getSize(), $dirLink->getSize());
	}

}
