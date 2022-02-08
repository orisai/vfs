<?php declare(strict_types = 1);

namespace Orisai\VFS;

use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
use Orisai\VFS\Structure\RootDirectory;
use function ltrim;
use function stream_context_get_default;
use function stream_context_get_options;
use function stream_context_set_default;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function uniqid;

/**
 * @internal
 */
final class FileSystem
{

	private string $scheme;

	private Container $container;

	public function __construct()
	{
		$this->scheme = uniqid('ori.var', true);

		$this->container = $container = new Container(new Factory());
		$this->container->getRootDirectory()->setScheme($this->scheme);

		$this->registerContextOptions($container);

		stream_wrapper_register($this->scheme, StreamWrapper::class);
	}

	public function getScheme(): string
	{
		return $this->scheme;
	}

	private function registerContextOptions(Container $container): void
	{
		/**	@see StreamWrapper::getContainerFromContext */
		$options = stream_context_get_options(stream_context_get_default());
		$options[$this->scheme] = ['Container' => $container];
		stream_context_set_default($options);
	}

	public function getContainer(): Container
	{
		return $this->container;
	}

	public function getRoot(): RootDirectory
	{
		return $this->getContainer()->getRootDirectory();
	}

	public function getPathWithScheme(string $path): string
	{
		$path = ltrim($path, '/');

		return $this->getScheme() . '://' . $path;
	}

	public function createDirectory(string $path, bool $recursive = false, ?int $mode = null): Directory
	{
		return $this->getContainer()->createDir($path, $recursive, $mode);
	}

	public function createFile(string $path, string $data = ''): File
	{
		return $this->getContainer()->createFile($path, $data);
	}

	public function createLink(string $path, string $destinationPath): Link
	{
		return $this->getContainer()->createLink($path, $destinationPath);
	}

	public function __destruct()
	{
		stream_wrapper_unregister($this->scheme);
	}

}
