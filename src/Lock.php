<?php declare(strict_types = 1);

namespace Orisai\VFS;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

/**
 * @internal
 */
final class Lock
{

	public const LOCK_SH = LOCK_SH,
		LOCK_EX = LOCK_EX,
		LOCK_UN = LOCK_UN,
		LOCK_NB = LOCK_NB;

}
