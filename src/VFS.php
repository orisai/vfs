<?php declare(strict_types = 1);

namespace Orisai\VFS;

use Orisai\Exceptions\Logic\InvalidArgument;
use Orisai\Exceptions\Message;
use function preg_match;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function uniqid;

final class VFS
{

	public static function register(?string $scheme = null): string
	{
		if ($scheme !== null && preg_match('/^[a-zA-Z0-9.+\-]+/', $scheme) !== 1) {
			$message = Message::create()
				->withContext("Registering VFS with scheme '$scheme'.")
				->withProblem('Only alphanumerics, dots (.), pluses (+) and hyphens (-) are allowed.');

			throw InvalidArgument::create()
				->withMessage($message);
		}

		$scheme ??= uniqid('ori.var.', true);

		stream_wrapper_register($scheme, VfsStreamWrapper::class);
		VfsStreamWrapper::$containers[$scheme] = new Container(new Factory());

		return $scheme;
	}

	public static function unregister(string $scheme): void
	{
		unset(VfsStreamWrapper::$containers[$scheme]);
		@stream_wrapper_unregister($scheme);
	}

}
