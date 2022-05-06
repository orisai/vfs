<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Wrapper;

use Orisai\VFS\Structure\File;
use Orisai\VFS\Wrapper\PermissionChecker;
use PHPUnit\Framework\TestCase;
use function time;

final class PermissionCheckerTest extends TestCase
{

	public function testUserPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time(), 0, 0);
		$file->setUser(1);

		$checker = new PermissionChecker(1, 1);

		$file->setMode(0_000);
		self::assertFalse($checker->userCanRead($file), 'User can\'t read with 0000');
		self::assertFalse($checker->userCanWrite($file), 'User can\'t write with 0000');

		$file->setMode(0_100);
		self::assertFalse($checker->userCanRead($file), 'User can\'t read with 0100');
		self::assertFalse($checker->userCanWrite($file), 'User can\'t write with 0100');

		$file->setMode(0_200);
		self::assertFalse($checker->userCanRead($file), 'User can\'t read with 0200');
		self::assertTrue($checker->userCanWrite($file), 'User can write with 0200');

		$file->setMode(0_400);
		self::assertTrue($checker->userCanRead($file), 'User can read with 0400');
		self::assertFalse($checker->userCanWrite($file), 'User can\'t write with 0400');

		$file->setMode(0_500);
		self::assertTrue($checker->userCanRead($file), 'User can read with 0500');
		self::assertFalse($checker->userCanWrite($file), 'User can\'t write with 0500');

		$file->setMode(0_600);
		self::assertTrue($checker->userCanRead($file), 'User can read with 0600');
		self::assertTrue($checker->userCanWrite($file), 'User can read with 0600');

		$file->setUser(PermissionChecker::RootId);
		$file->setMode(0_666);

		self::assertFalse($checker->userCanRead($file), 'User can\'t read without ownership');
		self::assertFalse($checker->userCanWrite($file), 'User can\'t without ownership');
	}

	public function testGroupPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time(), 0, 0);
		$file->setGroup(1);

		$checker = new PermissionChecker(1, 1);

		$file->setMode(0_000);
		self::assertFalse($checker->groupCanRead($file), 'group can\'t read with 0000');
		self::assertFalse($checker->groupCanWrite($file), 'group can\'t write with 0000');

		$file->setMode(0_010);
		self::assertFalse($checker->groupCanRead($file), 'group can\'t read with 0010');
		self::assertFalse($checker->groupCanWrite($file), 'group can\'t write with 0010');

		$file->setMode(0_020);
		self::assertFalse($checker->groupCanRead($file), 'group can\'t read with 0020');
		self::assertTrue($checker->groupCanWrite($file), 'group can write with 0020');

		$file->setMode(0_040);
		self::assertTrue($checker->groupCanRead($file), 'group can read with 0040');
		self::assertFalse($checker->groupCanWrite($file), 'group can\'t write with 0040');

		$file->setMode(0_050);
		self::assertTrue($checker->groupCanRead($file), 'group can read with 0050');
		self::assertFalse($checker->groupCanWrite($file), 'group can\'t write with 0050');

		$file->setMode(0_060);
		self::assertTrue($checker->groupCanRead($file), 'group can read with 0060');
		self::assertTrue($checker->groupCanWrite($file), 'group can read with 0060');

		$file->setGroup(PermissionChecker::RootId);
		$file->setMode(0_666);

		self::assertFalse($checker->groupCanRead($file), 'group can\'t read without ownership');
		self::assertFalse($checker->groupCanWrite($file), 'group can\'t without ownership');
	}

	public function testWorldPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time(), 0, 0);

		$checker = new PermissionChecker(1, 1);

		$file->setMode(0_000);
		self::assertFalse($checker->worldCanRead($file), 'world can\'t read with 0000');
		self::assertFalse($checker->worldCanWrite($file), 'world can\'t write with 0000');

		$file->setMode(0_001);
		self::assertFalse($checker->worldCanRead($file), 'world can\'t read with 0001');
		self::assertFalse($checker->worldCanWrite($file), 'world can\'t write with 0001');

		$file->setMode(0_002);
		self::assertFalse($checker->worldCanRead($file), 'world can\'t read with 0002');
		self::assertTrue($checker->worldCanWrite($file), 'world can write with 0002');

		$file->setMode(0_004);
		self::assertTrue($checker->worldCanRead($file), 'world can read with 0004');
		self::assertFalse($checker->worldCanWrite($file), 'world can\'t write with 0004');

		$file->setMode(0_005);
		self::assertTrue($checker->worldCanRead($file), 'world can read with 0005');
		self::assertFalse($checker->worldCanWrite($file), 'world can\'t write with 0005');

		$file->setMode(0_006);
		self::assertTrue($checker->worldCanRead($file), 'world can read with 0006');
		self::assertTrue($checker->worldCanWrite($file), 'world can read with 0006');
	}

	public function testIsReadable(): void
	{
		$file = new File('file', time(), 0, 0);

		$checker = new PermissionChecker(1, 1);

		$file->setMode(0_000);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($checker->isReadable($file), 'File is not readable root:root 0000');

		$file->setMode(0_400);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($checker->isReadable($file), 'File is not readable root:root 0400');

		$file->setMode(0_040);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($checker->isReadable($file), 'File is not readable root:root 0040');

		$file->setMode(0_004);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($checker->isReadable($file), 'File is readable root:root 0004');

		$file->setMode(0_000);
		$file->setUser(1);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($checker->isReadable($file), 'File is not readable user:root 0000');

		$file->setMode(0_400);
		$file->setUser(1);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($checker->isReadable($file), 'File is readable user:root 0400');

		$file->setMode(0_040);
		$file->setUser(1);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($checker->isReadable($file), 'File is not readable user:root 0040');

		$file->setMode(0_004);
		$file->setUser(1);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($checker->isReadable($file), 'File is readable user:root 0004');

		$file->setMode(0_000);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(1);
		self::assertFalse($checker->isReadable($file), 'File is not readable root:user 0000');

		$file->setMode(0_040);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(1);
		self::assertTrue($checker->isReadable($file), 'File is readable root:user 0040');

		$file->setMode(0_400);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(1);
		self::assertFalse($checker->isReadable($file), 'File is not readable root:user 0400');

		$file->setMode(0_004);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(1);
		self::assertTrue($checker->isReadable($file), 'File is readable root:user 0004');
	}

}
