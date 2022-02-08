<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Node;
use Orisai\VFS\Structure\RootDirectory;
use PHPUnit\Framework\TestCase;

final class NodeTest extends TestCase
{

	public function testChmod(): void
	{
		$file = new File('file');

		self::assertEquals(Node::DEFAULT_MODE | File::getStatType(), $file->getMode());

		$file->setMode(0_200);
		self::assertEquals(0_200 | File::getStatType(), $file->getMode());

		$file->setMode(0_777);
		self::assertEquals(0_777 | File::getStatType(), $file->getMode());

	}

	public function testToStringReturnsPath(): void
	{
		$dir = new Directory('dir');
		$dir->addFile($file = new File('file'));

		self::assertEquals($file->getPath(), $file, '__toString() invoked and returned path');

	}

	public function testSizeIsReturned(): void
	{
		$file = new File('file');
		$file->setData('1234567890');

		self::assertEquals(10, $file->getSize());
	}

	public function testURLConstruction(): void
	{
		$root = new RootDirectory();
		$root->setScheme('s://');

		$root->addDirectory($dir = new Directory('dir'));
		$dir->addDirectory($dir = new Directory('dir'));
		$dir->addFile($file = new File('file'));

		self::assertEquals('s://dir/dir/file', $file->getUrl());
	}

}
