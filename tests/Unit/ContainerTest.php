<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use Orisai\VFS\Container;
use Orisai\VFS\Exception\PathIsNotADirectory;
use Orisai\VFS\Exception\PathIsNotAFile;
use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\Factory;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ContainerTest extends TestCase
{

	public function testRootCreatedAfterRegistration(): void
	{
		$container = new Container(new Factory());

		self::assertEquals('/', $container->getRootDirectory()->getBasename());
		self::assertEquals('/', $container->getRootDirectory()->getPath());

	}

	public function testNodeAtAddressReturned(): void
	{
		$container = new Container(new Factory());
		$container->getRootDirectory()->addDirectory(new Directory('dir1.1'));
		$container->getRootDirectory()->addDirectory($d12 = new Directory('dir1.2'));

		$d12->addDirectory($d21 = new Directory('dir2.1'));
		$d21->addDirectory($d221 = new Directory('dir2.2.1'));
		$d221->addFile($file = new File('testFile'));

		self::assertEquals($d221, $container->getNodeAt('/dir1.2/dir2.1/dir2.2.1'));

	}

	public function testHasNodeAtReturnsCorrectly(): void
	{
		$container = new Container(new Factory());
		$container->getRootDirectory()->addDirectory(new Directory('dir1.1'));

		self::assertTrue($container->hasNodeAt('/dir1.1'));
		self::assertFalse($container->hasNodeAt('/dir'));

	}

	public function testDirectoryCreation(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir1');

		self::assertInstanceOf(Directory::class, $container->getNodeAt('/dir1'));

		//now recursive
		$container = new Container(new Factory());
		$container->createDir('/dir1/dir2', true);

		self::assertInstanceOf(Directory::class, $container->getNodeAt('/dir1/dir2'));

		//and mode
		$container = new Container(new Factory());
		$dir = $container->createDir('/dir1/dir2/dir3', true, 0_000);

		self::assertEquals(0_000 | Directory::getStatType(), $dir->getMode());

	}

	public function testMkdirThrowsWhenNoParent(): void
	{
		$this->expectException(PathNotFound::class);

		$container = new Container(new Factory());
		$container->createDir('/dir1/dir2');

	}

	public function testFileCreation(): void
	{
		$container = new Container(new Factory());

		$container->createFile('/file');

		self::assertInstanceOf(File::class, $container->getNodeAt('/file'));

		//with content

		$container->createFile('/file2', 'someData');

		self::assertEquals('someData', $container->getFileAt('/file2')->getData());

	}

	public function testFileCreationThrowsWhenNoParent(): void
	{
		$this->expectException(PathNotFound::class);

		$container = new Container(new Factory());

		$container->createFile('/dir/file');

	}

	public function testFileCreationThrowsWhenTryingToOverride(): void
	{
		$container = new Container(new Factory());

		$container->createFile('/file');

		$this->expectException(RuntimeException::class);

		$container->createFile('/file');

	}

	public function testMovingFilesWithinParent(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$container->move('/file', '/file2');

		self::assertTrue($container->hasNodeAt('/file2'), 'File exists at new location.');
		self::assertFalse($container->hasNodeAt('/file'), 'File does not exist at old location.');
	}

	public function testMovingDirectoriesWithinParent(): void
	{
		$container = new Container(new Factory());
		$container->getRootDirectory()->addDirectory($dir = new Directory('dir1'));
		$container->getRootDirectory()->addDirectory(new Directory('dir2'));
		$dir->addDirectory(new Directory('dir11'));
		$dir->addDirectory(new Directory('dir12'));
		$dir->addFile(new File('file'));

		$container->move('/dir1', '/dirMoved');

		self::assertTrue($container->hasNodeAt('/dir2'), 'Other parent directories not moved');
		self::assertTrue($container->hasNodeAt('/dirMoved'), 'Directory moved to new location');
		self::assertFalse($container->hasNodeAt('/dir1'), 'Directory does not exist at old location');
		self::assertTrue($container->hasNodeAt('/dirMoved/dir11'), 'Directory child of type Dir moved');
		self::assertTrue($container->hasNodeAt('/dirMoved/file'), 'Directory child of type File moved');

	}

	public function testMovingToDifferentParent(): void
	{
		$container = new Container(new Factory());
		$container->getRootDirectory()->addDirectory($dir = new Directory('dir1'));
		$container->getRootDirectory()->addDirectory(new Directory('dir2'));
		$dir->addDirectory(new Directory('dir11'));
		$dir->addDirectory(new Directory('dir12'));
		$dir->addFile(new File('file'));

		$container->move('/dir1', '/dir2/dirMoved');

		self::assertTrue($container->hasNodeAt('/dir2'), 'Other parent directories not moved');
		self::assertTrue($container->hasNodeAt('/dir2/dirMoved'), 'Directory moved to new location');
		self::assertFalse($container->hasNodeAt('/dir1'), 'Directory does not exist at old location');
		self::assertTrue($container->hasNodeAt('/dir2/dirMoved/dir11'), 'Directory child of type Dir moved');
		self::assertTrue($container->hasNodeAt('/dir2/dirMoved/file'), 'Directory child of type File moved');
	}

	public function testMovingFileOntoExistingFileOverridesTarget(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file1', 'file1');
		$container->createFile('/file2', 'file2');

		$container->move('/file1', '/file2');

		self::assertTrue($container->hasNodeAt('/file2'));
		self::assertFalse($container->hasNodeAt('/file1'));
		self::assertEquals('file1', $container->getFileAt('/file2')->getData());
	}

	public function testMovingDirectoryOntoExistingDirectoryOverridesTarget(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir1');
		$container->createDir('/dir2');

		$container->move('/dir1', '/dir2');

		self::assertTrue($container->hasNodeAt('/dir2'));
		self::assertFalse($container->hasNodeAt('/dir1'));
	}

	public function testMovingNonEmptyDirectoryOntoExistingDirectoryFails(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir1');
		$container->createDir('/dir2');
		$container->createFile('/dir2/file1', 'file');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Can\'t override non empty directory.');

		$container->move('/dir1', '/dir2');

	}

	public function testMovingDirectoryOntoExistingFileThrows(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir1');
		$container->createFile('/file2', 'file2');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Can\'t move.');

		$container->move('/dir1', '/file2');

	}

	public function testMovingFileOntoExistingDirectoryThrows(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir1');
		$container->createFile('/file2', 'file2');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Can\'t move.');

		$container->move('/file2', '/dir1');

	}

	public function testMovingFileOntoInvalidPathWithFileParentThrows(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file1');
		$container->createFile('/file2', 'file2');

		$this->expectException(PathIsNotADirectory::class);

		$container->move('/file1', '/file2/file1');

	}

	public function testRemoveDeletesNodeFromParent(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$container->remove('/file');

		self::assertFalse($container->hasNodeAt('/file'), 'File was removed');

		$container->createDir('/dir');

		$container->remove('/dir', true);

		self::assertFalse($container->hasNodeAt('/dir'), 'Directory was removed');
	}

	public function testRemoveThrowsWhenDeletingDirectoriesWithRecursiveFlag(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Won\'t non-recursively remove directory');

		$container->remove('/dir');
	}

	public function testLinkCreation(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');
		$container->createLink('/link', '/file');

		self::assertInstanceOf(Link::class, $container->getNodeAt('/link'));

	}

	public function testLinkCreationThrowsWhenTryingToOverride(): void
	{
		$container = new Container(new Factory());

		$container->createFile('/file');
		$container->createLink('/link', '/file');

		$this->expectException(RuntimeException::class);

		$container->createLink('/link', '/file');

	}

	public function testCreatingDirectoryOnPathThrowsWhenParentIsAFile(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$this->expectException(PathIsNotADirectory::class);

		$container->createDir('/file/dir');
	}

	public function testFileAtThrowsWhenFileOnParentPath(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$this->expectException(PathNotFound::class);

		$container->getNodeAt('/file/file2');
	}

	public function testCreateFileThrowsNonDirWhenParentNotDirectory(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$this->expectException(PathIsNotADirectory::class);

		$container->createFile('/file/file2');
	}

	public function testDirectoryAtThrowsNonDirIfReturnedNotDir(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		$this->expectException(PathIsNotADirectory::class);

		$container->getDirectoryAt('/file');
	}

	public function testDirectoryAtBubblesNotFoundOnBadPath(): void
	{
		$container = new Container(new Factory());

		$this->expectException(PathNotFound::class);

		$container->getDirectoryAt('/dir');
	}

	public function testDirectoryAtReturnsDirectory(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir');

		self::assertInstanceOf(Directory::class, $container->getDirectoryAt('/dir'));
	}

	public function testFileAtThrowsNonFileIfReturnedNotFile(): void
	{
		$container = new Container(new Factory());
		$container->createDir('/dir');

		$this->expectException(PathIsNotAFile::class);

		$container->getFileAt('/dir');
	}

	public function testFileAtBubblesNotFoundOnBadPath(): void
	{
		$container = new Container(new Factory());

		$this->expectException(PathNotFound::class);

		$container->getFileAt('/file');
	}

	public function testFileAtReturnsFile(): void
	{
		$container = new Container(new Factory());
		$container->createFile('/file');

		self::assertInstanceOf(File::class, $container->getFileAt('/file'));
	}

}
