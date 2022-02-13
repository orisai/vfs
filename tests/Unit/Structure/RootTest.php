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
		$root = new RootDirectory(time());
		self::assertEquals('/', $root->getBasename());
	}

	public function testPath(): void
	{
		$root = new RootDirectory(time());
		self::assertEquals('/', $root->getPath());
	}

	public function testDirname(): void
	{
		$root = new RootDirectory(time());
		self::assertEquals('', $root->getDirname());
	}

	public function testThrowsWhenTryingToSetParent(): void
	{
		$root = new RootDirectory(time());
		$dir = new Directory('a', time());

		$this->expectException(LogicException::class);

		$dir->addDirectory($root);
	}

	public function testRootPathReturnsWithScheme(): void
	{
		$root = new RootDirectory(time());

		$root->setScheme('scheme://');
		self::assertEquals('/', $root, 'No scheme when one is set');

	}

	public function testURLIsReturned(): void
	{
		$root = new RootDirectory(time());

		$root->setScheme('scheme://');
		self::assertEquals('scheme://', $root->getUrl());

		$root->setScheme('scheme');
		self::assertEquals('scheme://', $root->getUrl(), 'Scheme reformatted');

	}

	public function testURLThrowsWhenNoScheme(): void
	{
		$root = new RootDirectory(time());

		$this->expectException('RuntimeException');

		$root->getUrl();
	}

	public function testRootPathReturnsWithoutScheme(): void
	{
		$root = new RootDirectory(time());

		self::assertEquals('/', $root);

	}

}
