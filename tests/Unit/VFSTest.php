<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\VFS\VFS;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function file_put_contents;
use function stream_get_wrappers;

final class VFSTest extends TestCase
{

	public function testRegister(): void
	{
		$scheme = VFS::register();
		self::assertContains($scheme, stream_get_wrappers());

		VFS::unregister($scheme);
		self::assertNotContains($scheme, stream_get_wrappers());
	}

	public function testCustomScheme(): void
	{
		$name = 'aA1.+-';

		self::assertNotContains($name, stream_get_wrappers());

		VFS::register($name);
		self::assertContains($name, stream_get_wrappers());

		VFS::unregister($name);
		self::assertNotContains($name, stream_get_wrappers());
	}

	public function testCustomSchemeInvalid(): void
	{
		$this->expectException(InvalidArgument::class);
		$this->expectExceptionMessage(<<<'MSG'
Context: Registering VFS with scheme '@'.
Problem: Only alphanumerics, dots (.), pluses (+) and hyphens (-) are allowed.
MSG);

		VFS::register('@');
	}

	public function testUnregisterNonExistent(): void
	{
		self::assertNotContains('nonexistent', stream_get_wrappers());
		VFS::unregister('nonexistent');
		self::assertNotContains('nonexistent', stream_get_wrappers());
	}

	public function testWrapper(): void
	{
		$scheme = VFS::register();
		file_put_contents("$scheme://file", 'content');
		$content = file_get_contents("$scheme://file");

		self::assertSame($content, 'content');
	}

}
