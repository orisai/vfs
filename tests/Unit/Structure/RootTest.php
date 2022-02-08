<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Structure;

use LogicException;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\RootDirectory;
use PHPUnit\Framework\TestCase;

final class RootTest extends TestCase
{

	public function testBaseName(): void
	{
		$root = new RootDirectory();
		self::assertEquals('/', $root->getBasename());
	}

	public function testPath(): void
	{
		$root = new RootDirectory();
		self::assertEquals('/', $root->getPath());
	}

	public function testDirname(): void
	{
		$root = new RootDirectory();
		self::assertEquals('', $root->getDirname());
	}

	public function testThrowsWhenTryingToSetParent(): void
	{
		$root = new RootDirectory();
		$dir = new Directory('a');

		$this->expectException(LogicException::class);

		$dir->addDirectory($root);
	}

	public function testRootPathReturnsWithScheme(): void
	{
		$root = new RootDirectory();

		$root->setScheme('scheme://');
		self::assertEquals('/', $root, 'No scheme when one is set');

	}

	public function testURLIsReturned(): void
	{
		$root = new RootDirectory();

		$root->setScheme('scheme://');
		self::assertEquals('scheme://', $root->getUrl());

		$root->setScheme('scheme');
		self::assertEquals('scheme://', $root->getUrl(), 'Scheme reformatted');

	}

	public function testURLThrowsWhenNoScheme(): void
	{
		$root = new RootDirectory();

		$this->expectException('RuntimeException');

		$root->getUrl();
	}

	public function testRootPathReturnsWithoutScheme(): void
	{
		$root = new RootDirectory();

		self::assertEquals('/', $root);

	}

}
