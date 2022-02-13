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
		$node = new File('file', time());
		$node->setData('12345');

		$link = new Link($node, 'link', time());

		self::assertEquals($node->getSize(), $link->getSize());

		$dir = new Directory('/d', time());

		new Link($dir, 'link', time());

		self::assertEquals($dir->getSize(), $dir->getSize());

		$dir->addFile($node);

		self::assertEquals($dir->getSize(), $dir->getSize());
	}

}
