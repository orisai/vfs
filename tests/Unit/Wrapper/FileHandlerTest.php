<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Wrapper;

use Orisai\VFS\Structure\File;
use Orisai\VFS\Wrapper\FileHandler;
use PHPUnit\Framework\TestCase;

final class FileHandlerTest extends TestCase
{

	public function testPointerPositionInitializedToZero(): void
	{
		$file = new File('/file');
		$pointer = new FileHandler($file);

		self::assertEquals(0, $pointer->position());
	}

	public function testPointerPositionSetterGetter(): void
	{
		$file = new File('/file');
		$pointer = new FileHandler($file);

		$pointer->position(15);
		self::assertEquals(15, $pointer->position());
	}

	public function testPointerFindsEndOfFile(): void
	{
		$file = new File('/file');
		$file->setData('1234567');

		$pointer = new FileHandler($file);

		$pointer->seekToEnd();

		self::assertEquals(7, $pointer->position());
	}

	public function testDataIsReadInChunks(): void
	{
		$file = new File('/file');
		$file->setData('1234567');

		$pointer = new FileHandler($file);

		self::assertEquals('12', $pointer->read(2));
		self::assertEquals(2, $pointer->position());
		self::assertEquals('345', $pointer->read(3));
		self::assertEquals(5, $pointer->position());
		self::assertEquals('67', $pointer->read(10));
		self::assertEquals(7, $pointer->position());
	}

	public function testCheckingEOF(): void
	{
		$file = new File('/file');

		$handler = new FileHandler($file);

		self::assertTrue($handler->isAtEof());

		$file->setData('1');

		self::assertFalse($handler->isAtEof());

		$handler->position(1);
		self::assertTrue($handler->isAtEof());

		$handler->position(2);
		self::assertTrue($handler->isAtEof());

	}

	public function testTruncateRemovesDataAndResetsPointer(): void
	{
		$file = new File('/file');
		$file->setData('data');

		$handler = new FileHandler($file);

		$handler->truncate();

		self::assertEmpty($file->getData());
		self::assertEquals(0, $handler->position());

		//truncate to size
		$file->setData('data--');

		$handler->truncate(4);
		self::assertEquals(0, $handler->position());
		self::assertEquals('data', $file->getData());

	}

	public function testOffsetPositionMovesPointerCorrectly(): void
	{
		$file = new File('/file');
		$file->setData('data');

		$handler = new FileHandler($file);

		$handler->offsetPosition(2);
		self::assertEquals(2, $handler->position());

		$handler->offsetPosition(2);
		self::assertEquals(4, $handler->position());

	}

}
