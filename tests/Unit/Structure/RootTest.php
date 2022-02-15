<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use LogicException;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\RootDirectory;
use PHPUnit\Framework\TestCase;
use function time;

final class RootTest extends TestCase
{

	public function testBaseName(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		self::assertSame('/', $root->getBasename());
	}

	public function testPath(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		self::assertSame('/', $root->getPath());
	}

	public function testDirname(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		self::assertNull($root->getDirname());
	}

	public function testThrowsWhenTryingToSetParent(): void
	{
		$root = new RootDirectory(time(), 0, 0);
		$dir = new Directory('a', time(), 0, 0);

		$this->expectException(LogicException::class);

		$dir->addDirectory($root);
	}

	public function testRootPathReturnsWithScheme(): void
	{
		$root = new RootDirectory(time(), 0, 0);

		self::assertSame('/', (string) $root, 'No scheme when one is set');
	}

	public function testRootPathReturnsWithoutScheme(): void
	{
		$root = new RootDirectory(time(), 0, 0);

		self::assertSame('/', (string) $root);
	}

}
