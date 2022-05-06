<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Node;
use PHPUnit\Framework\TestCase;
use function time;

final class NodeTest extends TestCase
{

	public function testChmod(): void
	{
		$file = new File('file', time(), 0, 0);

		self::assertSame(Node::DefaultMode | File::getStatType(), $file->getMode());

		$file->setMode(0_200);
		self::assertSame(0_200 | File::getStatType(), $file->getMode());

		$file->setMode(0_777);
		self::assertSame(0_777 | File::getStatType(), $file->getMode());
	}

	public function testToStringReturnsPath(): void
	{
		$dir = new Directory('dir', time(), 0, 0);
		$dir->addFile($file = new File('file', time(), 0, 0));

		self::assertSame($file->getPath(), (string) $file, '__toString() invoked and returned path');
	}

	public function testSizeIsReturned(): void
	{
		$file = new File('file', time(), 0, 0);
		$file->setData('1234567890');

		self::assertSame(10, $file->getSize());
	}

}
