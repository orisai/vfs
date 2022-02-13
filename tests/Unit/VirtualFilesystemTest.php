<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use Orisai\VFS\Exception\PathNotFound;
use Orisai\VFS\FileSystem;
use Orisai\VFS\Structure\Directory;
use PHPUnit\Framework\TestCase;
use function is_array;
use function stream_context_get_default;
use function stream_context_get_options;
use function stream_context_set_default;
use function stream_get_wrappers;

final class VirtualFilesystemTest extends TestCase
{

	/**
	 * @param array<string, string|array> $structure
	 * @throws PathNotFound
	 */
	private function createStructure(FileSystem $fs, array $structure, string $parent = '/'): void
	{
		$container = $fs->getContainer();
		foreach ($structure as $key => $value) {
			if (is_array($value)) {
				$container->createDir($parent . $key);
				$this->createStructure($fs, $value, $parent . $key . '/');
			} else {
				$container->createFile($parent . $key, $value);
			}
		}
	}

	public function testWrapperIsRegisteredDuringObjectLifetime(): void
	{
		$fs = new FileSystem();
		$scheme = $fs->getScheme();

		self::assertContains($scheme, stream_get_wrappers(), 'Wrapper registered in __construct()');

		unset($fs); //provoking __destruct
		self::assertNotContains($scheme, stream_get_wrappers(), 'Wrapper unregistered in __destruct()');
	}

	public function testFilesystemFactoryAddedToDefaultContextDuringObjectLifetime(): void
	{
		$fs = new FileSystem();
		$scheme = $fs->getScheme();

		$options = stream_context_get_options(stream_context_get_default());

		self::assertArrayHasKey($scheme, $options, 'Wrapper key registered in context');
		self::assertArrayHasKey('Container', $options[$scheme], 'Container registered in context key');

	}

	public function testDefaultContextOptionsAreExtended(): void
	{
		stream_context_set_default(['someContext' => ['a' => 'b']]);

		$fs = new FileSystem();
		$scheme = $fs->getScheme();

		$options = stream_context_get_options(stream_context_get_default());

		self::assertArrayHasKey($scheme, $options, 'FS Context option present');
		self::assertArrayHasKey('someContext', $options, 'Previously existing context option present');

	}

	public function testCreateDirectoryCreatesDirectories(): void
	{
		$fs = new FileSystem();

		$directory = $fs->createDirectory('/dir/dir', true);

		self::assertEquals('/dir/dir', $directory->getPath());
	}

	public function testCreateFileCreatesFile(): void
	{
		$fs = new FileSystem();

		$file = $fs->createFile('/file', 'data');

		self::assertEquals('/file', $file->getPath());
		self::assertEquals('data', $file->getData());
	}

	public function testCreateStuctureMirrorsStructure(): void
	{
		$fs = new FileSystem();
		$this->createStructure($fs, ['file' => 'omg', 'file2' => 'gmo']);

		$file = $fs->getContainer()->getFileAt('/file');
		$file2 = $fs->getContainer()->getFileAt('/file2');

		self::assertEquals('omg', $file->getData());
		self::assertEquals('gmo', $file2->getData());

		$this->createStructure($fs, ['dir' => [], 'dir2' => []]);

		$dir = $fs->getContainer()->getNodeAt('/dir');
		$dir2 = $fs->getContainer()->getNodeAt('/dir2');

		self::assertInstanceOf(Directory::class, $dir);
		self::assertInstanceOf(Directory::class, $dir2);

		$this->createStructure($fs, [
			'dir3' => [
				'file' => 'nested',
				'dir4' => [
					'dir5' => [
						'file5' => 'nestednested',
					],
				],
			],
		]);

		$fs->getContainer()->getDirectoryAt('/dir3');

		$file = $fs->getContainer()->getFileAt('/dir3/file');

		self::assertEquals('nested', $file->getData());

		$fs->getContainer()->getDirectoryAt('/dir3/dir4/dir5');

		$file = $fs->getContainer()->getFileAt('/dir3/dir4/dir5/file5');

		self::assertEquals('nestednested', $file->getData());
	}

}
