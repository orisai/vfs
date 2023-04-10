<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use Orisai\VFS\Exception\PathAlreadyExists;
use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\RootDirectory;
use PHPUnit\Framework\TestCase;
use function time;

class DirectoryTest extends TestCase
{

	public function testBasename(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory($d1 = new Directory('dir1', time(), 0, 0));
		$root->addDirectory($d2 = new Directory('dir2', time(), 0, 0));
		$d2->addDirectory($d3 = new Directory('dir3', time(), 0, 0));

		self::assertSame('dir1', $d1->getBasename());
		self::assertSame('dir2', $d2->getBasename());
		self::assertSame('dir3', $d3->getBasename());
	}

	public function testDirnameBuilding(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory($d1 = new Directory('dir1', time(), 0, 0));
		$root->addDirectory($d2 = new Directory('dir2', time(), 0, 0));
		$d2->addDirectory($d3 = new Directory('dir3', time(), 0, 0));

		self::assertNull($root->getDirname());

		self::assertSame('/', $d1->getDirname());
		self::assertSame('/', $d2->getDirname());
		self::assertSame('/dir2', $d3->getDirname());
	}

	public function testPathBuilding(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory($d1 = new Directory('dir1', time(), 0, 0));
		$root->addDirectory($d2 = new Directory('dir2', time(), 0, 0));
		$d2->addDirectory($d3 = new Directory('dir3', time(), 0, 0));

		self::assertSame('/dir1', $d1->getPath());
		self::assertSame('/dir2', $d2->getPath());
		self::assertSame('/dir2/dir3', $d3->getPath());
	}

	public function testChildAtReturnsCorrectNode(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory($d1 = new Directory('dir1', time(), 0, 0));
		$root->addDirectory($d2 = new Directory('dir2', time(), 0, 0));
		$root->addFile($f1 = new File('file1', time(), 0, 0));

		self::assertSame($d1, $root->getChild('dir1'));
		self::assertSame($d2, $root->getChild('dir2'));
		self::assertSame($f1, $root->getChild('file1'));
	}

	public function testChildAtThrowsNotFoundWhenInvalidElementRequested(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory(new Directory('dir1', time(), 0, 0));

		$this->expectException(PathNotFound::class);

		$root->getChild('dir2');
	}

	public function testSizeIsReturnAsNumberOfChildren(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory(new Directory('dir1', time(), 0, 0));
		$root->addDirectory(new Directory('dir2', time(), 0, 0));

		self::assertSame(2 + Directory::EmptyDirSize, $root->getSize());
	}

	public function testThrowsWhenFileNameClashes(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory(new Directory('dir1', time(), 0, 0));

		$this->expectException(PathAlreadyExists::class);
		$root->addDirectory(new Directory('dir1', time(), 0, 0));
	}

	public function testRemove(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$root->addDirectory(new Directory('dir1', time(), 0, 0));
		$root->removeChild('dir1');

		$this->expectException(PathNotFound::class);

		$root->getChild('dir1');
	}

}
