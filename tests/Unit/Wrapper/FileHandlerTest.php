<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Wrapper;

use Orisai\VFS\Structure\File;
use Orisai\VFS\Wrapper\FileHandler;
use PHPUnit\Framework\TestCase;
use function time;

final class FileHandlerTest extends TestCase
{

	public function testPointerPositionInitializedToZero(): void
	{
		$file = new File('/file', time(), 0, 0);
		$pointer = new FileHandler($file);

		self::assertSame(0, $pointer->getPosition());
	}

	public function testPointerPositionSetterGetter(): void
	{
		$file = new File('/file', time(), 0, 0);
		$pointer = new FileHandler($file);

		$pointer->setPosition(15);
		self::assertSame(15, $pointer->getPosition());
	}

	public function testPointerFindsEndOfFile(): void
	{
		$file = new File('/file', time(), 0, 0);
		$file->setData('1234567');

		$pointer = new FileHandler($file);

		$pointer->seekToEnd();

		self::assertSame(7, $pointer->getPosition());
	}

	public function testDataIsReadInChunks(): void
	{
		$file = new File('/file', time(), 0, 0);
		$file->setData('1234567');

		$pointer = new FileHandler($file);

		self::assertSame('12', $pointer->read(2));
		self::assertSame(2, $pointer->getPosition());
		self::assertSame('345', $pointer->read(3));
		self::assertSame(5, $pointer->getPosition());
		self::assertSame('67', $pointer->read(10));
		self::assertSame(7, $pointer->getPosition());
	}

	public function testCheckingEOF(): void
	{
		$file = new File('/file', time(), 0, 0);

		$handler = new FileHandler($file);

		self::assertTrue($handler->isAtEof());

		$file->setData('1');

		self::assertFalse($handler->isAtEof());

		$handler->setPosition(1);
		self::assertTrue($handler->isAtEof());

		$handler->setPosition(2);
		self::assertTrue($handler->isAtEof());
	}

	public function testTruncateRemovesDataAndResetsPointer(): void
	{
		$file = new File('/file', time(), 0, 0);
		$file->setData('data');

		$handler = new FileHandler($file);

		$handler->truncate();

		self::assertEmpty($file->getData());
		self::assertSame(0, $handler->getPosition());

		//truncate to size
		$file->setData('data--');

		$handler->truncate(4);
		self::assertSame(0, $handler->getPosition());
		self::assertSame('data', $file->getData());
	}

	public function testOffsetPositionMovesPointerCorrectly(): void
	{
		$file = new File('/file', time(), 0, 0);
		$file->setData('data');

		$handler = new FileHandler($file);

		$handler->offsetPosition(2);
		self::assertSame(2, $handler->getPosition());

		$handler->offsetPosition(2);
		self::assertSame(4, $handler->getPosition());
	}

}
