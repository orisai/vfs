<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit\Wrapper;

use Orisai\VFS\Structure\File;
use Orisai\VFS\Wrapper\PermissionHelper;
use PHPUnit\Framework\TestCase;
use function time;

final class PermissionHelperTest extends TestCase
{

	public function testUserPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time());
		$file->setUser(1);

		$ph = new PermissionHelper(1, 1);

		$file->setMode(0_000);
		self::assertFalse($ph->userCanRead($file), 'User can\'t read with 0000');
		self::assertFalse($ph->userCanWrite($file), 'User can\'t write with 0000');

		$file->setMode(0_100);
		self::assertFalse($ph->userCanRead($file), 'User can\'t read with 0100');
		self::assertFalse($ph->userCanWrite($file), 'User can\'t write with 0100');

		$file->setMode(0_200);
		self::assertFalse($ph->userCanRead($file), 'User can\'t read with 0200');
		self::assertTrue($ph->userCanWrite($file), 'User can write with 0200');

		$file->setMode(0_400);
		self::assertTrue($ph->userCanRead($file), 'User can read with 0400');
		self::assertFalse($ph->userCanWrite($file), 'User can\'t write with 0400');

		$file->setMode(0_500);
		self::assertTrue($ph->userCanRead($file), 'User can read with 0500');
		self::assertFalse($ph->userCanWrite($file), 'User can\'t write with 0500');

		$file->setMode(0_600);
		self::assertTrue($ph->userCanRead($file), 'User can read with 0600');
		self::assertTrue($ph->userCanWrite($file), 'User can read with 0600');

		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setMode(0_666);

		self::assertFalse($ph->userCanRead($file), 'User can\'t read without ownership');
		self::assertFalse($ph->userCanWrite($file), 'User can\'t without ownership');
	}

	public function testGroupPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time());
		$file->setGroup(1);

		$ph = new PermissionHelper(1, 1);

		$file->setMode(0_000);
		self::assertFalse($ph->groupCanRead($file), 'group can\'t read with 0000');
		self::assertFalse($ph->groupCanWrite($file), 'group can\'t write with 0000');

		$file->setMode(0_010);
		self::assertFalse($ph->groupCanRead($file), 'group can\'t read with 0010');
		self::assertFalse($ph->groupCanWrite($file), 'group can\'t write with 0010');

		$file->setMode(0_020);
		self::assertFalse($ph->groupCanRead($file), 'group can\'t read with 0020');
		self::assertTrue($ph->groupCanWrite($file), 'group can write with 0020');

		$file->setMode(0_040);
		self::assertTrue($ph->groupCanRead($file), 'group can read with 0040');
		self::assertFalse($ph->groupCanWrite($file), 'group can\'t write with 0040');

		$file->setMode(0_050);
		self::assertTrue($ph->groupCanRead($file), 'group can read with 0050');
		self::assertFalse($ph->groupCanWrite($file), 'group can\'t write with 0050');

		$file->setMode(0_060);
		self::assertTrue($ph->groupCanRead($file), 'group can read with 0060');
		self::assertTrue($ph->groupCanWrite($file), 'group can read with 0060');

		$file->setGroup(PermissionHelper::ROOT_ID);
		$file->setMode(0_666);

		self::assertFalse($ph->groupCanRead($file), 'group can\'t read without ownership');
		self::assertFalse($ph->groupCanWrite($file), 'group can\'t without ownership');
	}

	public function testWorldPermissionsAreCalculatedCorrectly(): void
	{
		$file = new File('file', time());

		$ph = new PermissionHelper(1, 1);

		$file->setMode(0_000);
		self::assertFalse($ph->worldCanRead($file), 'world can\'t read with 0000');
		self::assertFalse($ph->worldCanWrite($file), 'world can\'t write with 0000');

		$file->setMode(0_001);
		self::assertFalse($ph->worldCanRead($file), 'world can\'t read with 0001');
		self::assertFalse($ph->worldCanWrite($file), 'world can\'t write with 0001');

		$file->setMode(0_002);
		self::assertFalse($ph->worldCanRead($file), 'world can\'t read with 0002');
		self::assertTrue($ph->worldCanWrite($file), 'world can write with 0002');

		$file->setMode(0_004);
		self::assertTrue($ph->worldCanRead($file), 'world can read with 0004');
		self::assertFalse($ph->worldCanWrite($file), 'world can\'t write with 0004');

		$file->setMode(0_005);
		self::assertTrue($ph->worldCanRead($file), 'world can read with 0005');
		self::assertFalse($ph->worldCanWrite($file), 'world can\'t write with 0005');

		$file->setMode(0_006);
		self::assertTrue($ph->worldCanRead($file), 'world can read with 0006');
		self::assertTrue($ph->worldCanWrite($file), 'world can read with 0006');

	}

	public function testIsReadable(): void
	{
		$file = new File('file', time());

		$ph = new PermissionHelper(1, 1);

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($ph->isReadable($file), 'File is not readable root:root 0000');

		$file->setMode(0_400);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($ph->isReadable($file), 'File is not readable root:root 0400');

		$file->setMode(0_040);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($ph->isReadable($file), 'File is not readable root:root 0040');

		$file->setMode(0_004);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($ph->isReadable($file), 'File is readable root:root 0004');

		$file->setMode(0_000);
		$file->setUser(1);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($ph->isReadable($file), 'File is not readable user:root 0000');

		$file->setMode(0_400);
		$file->setUser(1);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($ph->isReadable($file), 'File is readable user:root 0400');

		$file->setMode(0_040);
		$file->setUser(1);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($ph->isReadable($file), 'File is not readable user:root 0040');

		$file->setMode(0_004);
		$file->setUser(1);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($ph->isReadable($file), 'File is readable user:root 0004');

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(1);
		self::assertFalse($ph->isReadable($file), 'File is not readable root:user 0000');

		$file->setMode(0_040);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(1);
		self::assertTrue($ph->isReadable($file), 'File is readable root:user 0040');

		$file->setMode(0_400);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(1);
		self::assertFalse($ph->isReadable($file), 'File is not readable root:user 0400');

		$file->setMode(0_004);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(1);
		self::assertTrue($ph->isReadable($file), 'File is readable root:user 0004');
	}

}
