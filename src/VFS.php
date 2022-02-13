<?php declare(strict_types = 1);

namespace Orisai\VFS;

use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function uniqid;

final class VFS
{

	public static function register(?string $scheme = null): string
	{
		$scheme ??= uniqid('ori.var.', true);

		stream_wrapper_register($scheme, StreamWrapper::class);

		StreamWrapper::$containers[$scheme] = new Container(new Factory());

		return $scheme;
	}

	public static function unregister(string $scheme): bool
	{
		unset(StreamWrapper::$containers[$scheme]);

		return stream_wrapper_unregister($scheme);
	}

}
