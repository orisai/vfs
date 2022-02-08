<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use Orisai\VFS\Exception\PathAlreadyExists;
use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\RootDirectory;
use PHPUnit\Framework\TestCase;

class DirectoryTest extends TestCase
{

	public function testBasename(): void
	{
		$root = new RootDirectory();
		$root->addDirectory($d1 = new Directory('dir1'));
		$root->addDirectory($d2 = new Directory('dir2'));
		$d2->addDirectory($d3 = new Directory('dir3'));

		self::assertEquals('dir1', $d1->getBasename());
		self::assertEquals('dir2', $d2->getBasename());
		self::assertEquals('dir3', $d3->getBasename());
	}

	public function testDirnameBuilding(): void
	{
		$root = new RootDirectory();
		$root->addDirectory($d1 = new Directory('dir1'));
		$root->addDirectory($d2 = new Directory('dir2'));
		$d2->addDirectory($d3 = new Directory('dir3'));

		self::assertEquals(null, $root->getDirname());

		self::assertEquals('/', $d1->getDirname());
		self::assertEquals('/', $d2->getDirname());
		self::assertEquals('/dir2', $d3->getDirname());

	}

	public function testPathBuilding(): void
	{
		$root = new RootDirectory();
		$root->addDirectory($d1 = new Directory('dir1'));
		$root->addDirectory($d2 = new Directory('dir2'));
		$d2->addDirectory($d3 = new Directory('dir3'));

		self::assertEquals('/dir1', $d1->getPath());
		self::assertEquals('/dir2', $d2->getPath());
		self::assertEquals('/dir2/dir3', $d3->getPath());

	}

	public function testChildAtReturnsCorrectNode(): void
	{
		$root = new RootDirectory();
		$root->addDirectory($d1 = new Directory('dir1'));
		$root->addDirectory($d2 = new Directory('dir2'));
		$root->addFile($f1 = new File('file1'));

		self::assertEquals($d1, $root->getChild('dir1'));
		self::assertEquals($d2, $root->getChild('dir2'));
		self::assertEquals($f1, $root->getChild('file1'));
	}

	public function testChildAtThrowsNotFoundWhenInvalidElementRequested(): void
	{
		$root = new RootDirectory();
		$root->addDirectory(new Directory('dir1'));

		$this->expectException(PathNotFound::class);

		$root->getChild('dir2');
	}

	public function testSizeIsReturnAsNumberOfChildren(): void
	{
		$root = new RootDirectory();
		$root->addDirectory(new Directory('dir1'));
		$root->addDirectory(new Directory('dir2'));

		self::assertEquals(2, $root->getSize());
	}

	public function testThrowsWhenFileNameClashes(): void
	{
		$root = new RootDirectory();
		$root->addDirectory(new Directory('dir1'));

		$this->expectException(PathAlreadyExists::class);
		$root->addDirectory(new Directory('dir1'));

	}

	public function testRemove(): void
	{
		$root = new RootDirectory();
		$root->addDirectory(new Directory('dir1'));
		$root->removeChild('dir1');

		$this->expectException(PathNotFound::class);

		$root->getChild('dir1');
	}

}
