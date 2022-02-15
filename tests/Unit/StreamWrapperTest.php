<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use DirectoryIterator;
use finfo;
use Orisai\VFS\StreamWrapper;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\VFS;
use Orisai\VFS\Wrapper\PermissionHelper;
use PHPUnit\Framework\TestCase;
use function base64_decode;
use function chgrp;
use function chmod;
use function chown;
use function clearstatcache;
use function copy;
use function error_get_last;
use function fflush;
use function file_get_contents;
use function file_put_contents;
use function fileatime;
use function filectime;
use function filegroup;
use function filemtime;
use function fileowner;
use function fileperms;
use function filesize;
use function flock;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function ftruncate;
use function function_exists;
use function fwrite;
use function is_executable;
use function is_file;
use function is_link;
use function is_readable;
use function is_writable;
use function lchown;
use function mkdir;
use function opendir;
use function posix_getgid;
use function posix_getgrgid;
use function posix_getpwuid;
use function posix_getuid;
use function rename;
use function rmdir;
use function stat;
use function str_repeat;
use function touch;
use function uniqid;
use function unlink;
use const FILE_APPEND;
use const FILEINFO_MIME_TYPE;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;
use const PHP_VERSION_ID;
use const SEEK_CUR;
use const SEEK_END;
use const STREAM_BUFFER_NONE;
use const STREAM_META_ACCESS;
use const STREAM_META_GROUP;
use const STREAM_META_GROUP_NAME;
use const STREAM_META_OWNER;
use const STREAM_META_OWNER_NAME;
use const STREAM_META_TOUCH;
use const STREAM_REPORT_ERRORS;

final class StreamWrapperTest extends TestCase
{

	private int $uid;

	private int $gid;

	public function setUp(): void
	{
		parent::setUp();
		$this->uid = function_exists('posix_getuid') ? posix_getuid() : PermissionHelper::ROOT_ID;
		$this->gid = function_exists('posix_getgid') ? posix_getgid() : PermissionHelper::ROOT_ID;
	}

	public function testSchemeStripping(): void
	{
		self::assertEquals('/1/2/3/4', StreamWrapper::stripScheme('test://1/2/3/4'));
		self::assertEquals('/', StreamWrapper::stripScheme('test://'));
		self::assertEquals('/', StreamWrapper::stripScheme('test:///'));
		self::assertEquals('/dir', StreamWrapper::stripScheme('test:///dir'));
	}

	public function testContainerIsReturnedFromContext(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		self::assertSame($container, StreamWrapper::getContainer("$scheme://file"));
		self::assertSame($container, StreamWrapper::getContainer("$scheme://"));
		self::assertSame($container, StreamWrapper::getContainer("$scheme:///file"));
	}

	public function testFileExists(): void
	{
		$scheme = VFS::register();

		mkdir($dir = "$scheme://dir");

		touch("$dir/file");
		mkdir("$dir/dir");

		self::assertFileExists("$dir/file");
		self::assertFileExists($dir);
		self::assertFileDoesNotExist("$dir/fileNotExist");
	}

	public function testIsDir(): void
	{
		$scheme = VFS::register();

		mkdir($dir = "$scheme://dir");

		touch("$dir/file");
		mkdir("$dir/dir");

		self::assertDirectoryDoesNotExist("$dir/file");
		self::assertDirectoryExists($dir);
		self::assertDirectoryExists("$dir/dir");
		self::assertDirectoryExists("$scheme://");
	}

	public function testIsLink(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$factory = $container->getFactory();

		$container->getRootDirectory()->addDirectory($d = $factory->createDir('dir'));
		$d->addLink($factory->createLink('link', $d));

		self::assertTrue(is_link("$scheme://dir/link"));
	}

	public function testIsFile(): void
	{
		$scheme = VFS::register();

		mkdir($dir = "$scheme://dir");

		touch("$dir/file");
		touch("$scheme://file2");
		mkdir("$dir/dir");

		self::assertTrue(is_file("$dir/file"));
		self::assertFalse(is_file($dir));
		self::assertFalse(is_file("$dir/dir"));
		self::assertFalse(is_file("$scheme://"));
		self::assertTrue(is_file("$scheme://file2"));
	}

	public function testChmod(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$root = $container->getRootDirectory();

		$path = "$scheme://";

		chmod($path, 0_777);
		self::assertEquals(0_777 | Directory::getStatType(), $root->getMode());

		$root->setMode(0_755);
		self::assertEquals(0_755 | Directory::getStatType(), fileperms($path));

		//accessing non existent file should return false
		self::assertFalse(chmod("$scheme://nonExistingFile", 0_777));
	}

	public function testChownByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. ' .
				'Php unit shouldn\'t be run as root user. (Unless you are a windows user!)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		chown("$scheme://", 'root');
		self::assertEquals('root', posix_getpwuid(fileowner("$scheme://"))['name']);
	}

	public function testChownById(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. Php unit shouldn\'t be run as root user.',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		chown("$scheme://", 0);

		self::assertEquals(0, fileowner("$scheme://"));
	}

	public function testChgrpByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$group = posix_getgrgid(posix_getpwuid(0)['gid'])['name'];

		chgrp("$scheme://", $group);

		self::assertEquals($group, posix_getgrgid(filegroup("$scheme://"))['name']);
	}

	public function testChgrpById(): void
	{
		if ($this->gid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		$group = posix_getpwuid(0)['gid'];

		chgrp("$scheme://", $group);

		self::assertEquals($group, filegroup("$scheme://"));
	}

	public function testMkdir(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		mkdir("$scheme://dir");

		self::assertFileExists("$scheme://dir");
		self::assertDirectoryExists("$scheme://dir");

		mkdir("$scheme://dir2", 0_000, false);

		$dir = $container->getNodeAt('/dir2');

		self::assertEquals(0_000 | Directory::getStatType(), $dir->getMode());
	}

	public function testMkdirCatchesClashes(): void
	{
		$scheme = VFS::register();

		mkdir("$scheme://dir");
		@mkdir("$scheme://dir");

		$error = error_get_last();

		self::assertEquals('dir already exists', $error['message']);
	}

	public function testMkdirRecursive(): void
	{
		$scheme = VFS::register();

		mkdir("$scheme://dir/dir2", 0_777, true);

		self::assertFileExists("$scheme://dir/dir2");
		self::assertDirectoryExists("$scheme://dir/dir2");

		@mkdir("$scheme://dir/a/b", 0_777, false);

		$error = error_get_last();

		self::assertStringMatchesFormat('mkdir: %s: No such file or directory', $error['message']);
	}

	public function testStreamWriting(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		file_put_contents("$scheme://file", 'data');

		self::assertEquals('data', $container->getFileAt('/file')->getData());

		//long strings
		file_put_contents("$scheme://file2", str_repeat('data ', 5_000));

		self::assertEquals(str_repeat('data ', 5_000), $container->getFileAt('/file2')->getData());

		//truncating
		file_put_contents("$scheme://file", 'data2');

		self::assertEquals('data2', $container->getFileAt('/file')->getData());

		//appending
		file_put_contents("$scheme://file", 'data3', FILE_APPEND);

		self::assertEquals('data2data3', $container->getFileAt('/file')->getData());

		$handle = fopen("$scheme://file2", 'w');

		fwrite($handle, 'data');
		self::assertEquals('data', $container->getFileAt('/file2')->getData());

		fwrite($handle, '2');
		self::assertEquals('data2', $container->getFileAt('/file2')->getData(), 'Pointer advanced');

		fwrite($handle, 'data', 1);
		self::assertEquals(
			'data2d',
			$container->getFileAt('/file2')->getData(),
			'Written with limited length',
		);
	}

	public function testStreamReading(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'test data');

		self::assertEquals('test data', file_get_contents("$scheme://file"));

		//long string
		$container->createFile('/file2', str_repeat('test data', 5_000));
		self::assertEquals(str_repeat('test data', 5_000), file_get_contents("$scheme://file2"));

		$container->createDir('/dir');

		self::assertEmpty(file_get_contents("$scheme://dir"));
	}

	public function testStreamFlushing(): void
	{
		$scheme = VFS::register();

		$handle = fopen("$scheme://file", 'w');

		self::assertTrue(fflush($handle));
	}

	public function testOpeningForReadingOnNonExistingFails(): void
	{
		$scheme = VFS::register();

		self::assertFalse(@fopen("$scheme://nonExistingFile", 'r'));

		$error = error_get_last();

		if (PHP_VERSION_ID >= 8_00_00) {
			self::assertStringMatchesFormat(
				'fopen(%s://nonExistingFile): Failed to open stream: %s',
				$error['message'],
			);
		} else {
			self::assertStringMatchesFormat(
				'fopen(%s://nonExistingFile): failed to open stream: %s',
				$error['message'],
			);
		}
	}

	public function testOpeningForWritingCorrectlyOpensAndTruncatesFile(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		$handle = fopen("$scheme://nonExistingFile", 'w');

		self::assertIsResource($handle);

		$file = $container->createFile('/file', 'data');

		$handle = fopen("$scheme://file", 'w');

		self::assertIsResource($handle);
		self::assertEmpty($file->getData());
	}

	public function testOpeningForAppendingDoesNotTruncateFile(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file', 'data');

		$handle = fopen("$scheme://file", 'a');

		self::assertIsResource($handle);
		self::assertEquals('data', $file->getData());
	}

	public function testCreatingFileWhileOpeningFailsCorrectly(): void
	{
		$scheme = VFS::register();

		self::assertFalse(@fopen("$scheme://dir/file", 'w'));

		$error = error_get_last();

		if (PHP_VERSION_ID >= 8_00_00) {
			self::assertStringMatchesFormat('fopen(%s://dir/file): Failed to open stream: %s', $error['message']);
		} else {
			self::assertStringMatchesFormat('fopen(%s://dir/file): failed to open stream: %s', $error['message']);
		}
	}

	public function testFileGetContentsOffsetsAndLimitsCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', '--data--');

		self::assertEquals('data', file_get_contents("$scheme://file", false, null, 2, 4));
	}

	public function testFileSeeking(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'data');

		$handle = fopen("$scheme://file", 'r');

		fseek($handle, 2);
		self::assertEquals(2, ftell($handle));

		fseek($handle, 1, SEEK_CUR);
		self::assertEquals(3, ftell($handle));

		fseek($handle, 6, SEEK_END);
		self::assertEquals(10, ftell($handle), 'End of file + 6 is 10');
	}

	public function testFileTruncating(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file', 'data--');

		//has to opened for append otherwise file is automatically truncated by 'w' opening mode
		$handle = fopen("$scheme://file", 'a');

		ftruncate($handle, 4);

		self::assertEquals('data', $file->getData());
	}

	public function testOpeningModesAreHandledCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file', 'data');

		$handle = fopen("$scheme://file", 'r');
		self::assertEquals('data', fread($handle, 4), 'Contents can be read in read mode');
		self::assertEquals(0, fwrite($handle, '--'), '0 bytes should be written in readonly mode');

		$handle = fopen("$scheme://file", 'r+');
		self::assertEquals('data', fread($handle, 4), 'Contents can be read in extended read mode');
		self::assertEquals(2, fwrite($handle, '--'), '2 bytes should be written in extended readonly mode');

		$handle = fopen("$scheme://file", 'w');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in writeonly mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in write only mode');

		$handle = fopen("$scheme://file", 'w+');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended writeonly mode');
		fseek($handle, 0);
		self::assertEquals('data', fread($handle, 4), 'Bytes read in extended write only mode');

		$file->setData('data');

		$handle = fopen("$scheme://file", 'a');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in append mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in append mode');

		$handle = fopen("$scheme://file", 'a+');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended append mode');
		fseek($handle, 0);
		self::assertEquals('datadata', fread($handle, 8), 'Bytes read in extended append mode');
	}

	public function testFileTimesAreModifiedCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file', 'data');

		$stat = stat("$scheme://file");

		self::assertNotEquals(0, $stat['atime']);
		self::assertNotEquals(0, $stat['mtime']);
		self::assertNotEquals(0, $stat['ctime']);

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_get_contents("$scheme://file");
		$stat = stat("$scheme://file");

		self::assertNotEquals(10, $stat['atime'], 'Access time has changed after read');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after read');
		self::assertEquals(10, $stat['ctime'], 'inode change time has not changed after read');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_put_contents("$scheme://file", 'data');
		$stat = stat("$scheme://file");

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after write');
		self::assertNotEquals(10, $stat['mtime'], 'Modification time has changed after write');
		self::assertNotEquals(10, $stat['ctime'], 'inode change time has changed after write');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		chmod("$scheme://file", 0_777);
		$stat = stat("$scheme://file");

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after inode change');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after inode change');
		self::assertNotEquals(10, $stat['ctime'], 'inode change time has changed after inode change');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		clearstatcache();

		fopen("$scheme://file", 'r');
		$stat = stat("$scheme://file");

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after opening for reading');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after opening for reading');
		self::assertEquals(10, $stat['ctime'], 'inode change time has not changed after opening for reading');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		fopen("$scheme://file", 'w');
		$stat = stat("$scheme://file");

		self::assertEquals(20, $stat['atime'], 'Access time has not changed after opening for writing');
		self::assertNotEquals(20, $stat['mtime'], 'Modification time has changed after opnening for writing');
		self::assertNotEquals(20, $stat['ctime'], 'inode change time has changed after opnening for writing');
	}

	public function testTouchFileCreation(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		touch("$scheme://file2");

		self::assertFileExists("$scheme://file2");

		@touch("$scheme://dir/file");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'touch: %s: No such file or directory.',
			$error['message'],
			'Fails when no parent',
		);

		$file = $container->getNodeAt('/file2');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		touch("$scheme://file2");
		$stat = stat("$scheme://file2");

		self::assertNotEquals(20, $stat['atime'], 'Access time has changed after touch');
		self::assertNotEquals(20, $stat['mtime'], 'Modification time has changed after touch');
		self::assertNotEquals(20, $stat['ctime'], 'inode change time has changed after touch');
	}

	public function testTouchUpdatesTimes(): void
	{
		$scheme = VFS::register();
		$path = "$scheme://file";

		$time = 1_500_020_720;
		$atime = 1_500_204_791;

		touch($path, $time, $atime);

		self::assertEquals($time, filectime($path));
		self::assertEquals($time, filemtime($path));
		self::assertEquals($atime, fileatime($path));
	}

	public function testRenamesMovesFileCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'data');

		rename("$scheme://file", "$scheme://file2");

		self::assertTrue($container->hasNodeAt('/file2'));
		self::assertFalse($container->hasNodeAt('/file'));
		self::assertEquals('data', $container->getFileAt('/file2')->getData());
	}

	public function testRenameReturnsCorrectWarnings(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		@rename("$scheme://file", "$scheme://dir/file2");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: No such file or directory',
			$error['message'],
			'Triggers when moving non existing file',
		);

		$container->createFile('/file');

		@rename("$scheme://file", "$scheme://dir/file2");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: No such file or directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);

		$container->createDir('/dir');

		@rename("$scheme://dir", "$scheme://file");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: Not a directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);
	}

	public function testRenameFailsCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		self::assertFalse(@rename("$scheme://file", "$scheme://dir/file2"));

		$container->createFile('/file');

		self::assertFalse(@rename("$scheme://file", "$scheme://dir/file2"));

		$container->createDir('/dir');

		self::assertFalse(@rename("$scheme://dir", "$scheme://file"));
	}

	public function testUnlinkRemovesFile(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file');

		unlink("$scheme://file");

		self::assertFalse($container->hasNodeAt('/file'));
	}

	public function testUnlinkThrowsWarnings(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		@unlink("$scheme://file");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rm: %s: No such file or directory',
			$error['message'],
			'Warning when file does not exist',
		);

		$container->createDir('/dir');

		@unlink("$scheme://dir");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rm: %s: is a directory',
			$error['message'],
			'Warning when trying to remove directory',
		);
	}

	public function testRmdirRemovesDirectories(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir');

		rmdir("$scheme://dir");

		self::assertFalse($container->hasNodeAt('/dir'), 'Directory has been removed');
	}

	public function testRmdirErrorsWithNonEmptyDirectories(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir/dir', true);

		@rmdir("$scheme://dir");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): Directory not empty',
			$error['message'],
			'Warning triggered when removing non empty directory',
		);
	}

	public function testRmdirErrorsWhenRemovingNonExistingDirectory(): void
	{
		$scheme = VFS::register();

		@rmdir("$scheme://dir");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): No such file or directory',
			$error['message'],
			'Warning triggered when removing non existing directory',
		);
	}

	public function testRmdirErrorsWhenRemovingFile(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file');

		@rmdir("$scheme://file");

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): Not a directory',
			$error['message'],
			'Warning triggered when trying to remove a file',
		);
	}

	public function testStreamOpenWarnsWhenFlagPassed(): void
	{
		$scheme = VFS::register();
		$opened_path = null;

		$wrapper = new StreamWrapper();

		self::assertFalse(
			$wrapper->stream_open("$scheme://file", 'r', 0, $opened_path),
			'No warning when no flag',
		);

		@$wrapper->stream_open("$scheme://file", 'r', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream.',
			$error['message'],
			'Stream open errors when flag passed',
		);
	}

	public function testDirectoryOpensForReading(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir');

		$wrapper = new StreamWrapper();

		$handle = $wrapper->dir_opendir("$scheme://dir", STREAM_BUFFER_NONE);

		self::assertTrue($handle, 'Directory opened for reading');

		$handle = @$wrapper->dir_opendir("$scheme://nonExistingDir", STREAM_BUFFER_NONE);

		self::assertFalse($handle, 'Non existing directory not opened for reading');

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'opendir(%s): failed to open dir: No such file or directory',
			$error['message'],
			'Opening non existing directory triggers warning',
		);

		$handle = opendir("$scheme://dir");

		self::assertIsResource($handle, 'opendir uses dir_opendir');
	}

	public function testDirectoryOpenDoesNotOpenFiles(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file');

		$wrapper = new StreamWrapper();

		$handle = @$wrapper->dir_opendir("$scheme://file", STREAM_BUFFER_NONE);

		self::assertFalse($handle, 'Opening fiels with opendir fails');

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'opendir(%s): failed to open dir: Not a directory',
			$error['message'],
			'Opening fiels with opendir triggers warning',
		);
	}

	public function testDirectoryCloses(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir');

		$wrapper = new StreamWrapper();

		self::assertFalse($wrapper->dir_closedir(), 'Returns false when no dir opened');

		$wrapper->dir_opendir("$scheme://dir", STREAM_BUFFER_NONE);

		self::assertTrue($wrapper->dir_closedir());
	}

	public function testDirectoryReading(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir1');
		$container->createDir('/dir2');
		$container->createDir('/dir3');

		$wr = new StreamWrapper();
		$wr->dir_opendir("$scheme://", STREAM_BUFFER_NONE);

		self::assertEquals('dir1', $wr->dir_readdir());
		self::assertEquals('dir2', $wr->dir_readdir());
		self::assertEquals('dir3', $wr->dir_readdir());
		self::assertFalse($wr->dir_readdir());

		$wr->dir_rewinddir();
		self::assertEquals('dir1', $wr->dir_readdir(), 'Directory rewound');
	}

	public function testDirectoryIterationWithDirectoryIterator(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir1');
		$container->createDir('/dir2');
		$container->createDir('/dir3');

		$result = [];

		foreach (new DirectoryIterator("$scheme://") as $fileInfo) {
			$result[] = $fileInfo->getBasename();
		}

		self::assertEquals(['dir1', 'dir2', 'dir3'], $result, 'All directories found');
	}

	public function testStreamOpenDoesNotOpenDirectoriesForWriting(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir');

		self::assertFalse(@fopen("$scheme://dir", 'w'));
		self::assertFalse(@fopen("$scheme://dir", 'r+'));
		self::assertFalse(@fopen("$scheme://dir", 'w+'));
		self::assertFalse(@fopen("$scheme://dir", 'a'));
		self::assertFalse(@fopen("$scheme://dir", 'a+'));

		$opened_path = null;

		$wr = new StreamWrapper();
		@$wr->stream_open("$scheme://dir", 'w', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'fopen(%s): failed to open stream: Is a directory',
			$error['message'],
			'Stream does not open directories',
		);
	}

	public function testStreamOpenAllowsForDirectoryOpeningForReadingAndReturnsEmptyStrings(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/dir');

		$handle = fopen("$scheme://dir", 'r');

		self::assertIsResource($handle);

		self::assertEmpty(fread($handle, 1));
	}

	public function testPermissionsAreCheckedWhenOpeningFiles(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open("$scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open("$scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open("$scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open("$scheme://file", 'r', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'w', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'a', 0, $openedPath));
		self::assertTrue($wr->stream_open("$scheme://file", 'a+', 0, $openedPath));
	}

	public function testTemporaryFileCreatedToReadDirectoriesWithStreamOpenInheritsPermissions(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createDir('/dir');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open("$scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open("$scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open("$scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open("$scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$scheme://dir", 'a+', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenOpeningDirectories(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createDir('/dir');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse(@$wr->dir_opendir("$scheme://dir", STREAM_BUFFER_NONE));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse(@$wr->dir_opendir("$scheme://dir", STREAM_BUFFER_NONE));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue(@$wr->stream_open("$scheme://dir", 'r', 0, $openedPath));

		$file->setMode(0_040);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup($this->gid);
		self::assertTrue(@$wr->stream_open("$scheme://dir", 'r', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenCreatingFilesWithinDirectories(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$dir = $container->createDir('/dir');

		$dir->setMode(0_000);
		self::assertFalse(@file_put_contents("$scheme://dir/file", 'data'));

		$dir->setMode(0_400);
		self::assertFalse(@file_put_contents("$scheme://dir/file", 'data'));

		$dir->setMode(0_200);
		self::assertGreaterThan(0, @file_put_contents("$scheme://dir/file", 'data'));
	}

	public function testStreamOpenReportsErrorsOnPermissionDenied(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$dir = $container->createDir('/dir');
		$file = $container->createFile('/file');
		$dir->setMode(0_000);

		$openedPath = null;

		$wr = new StreamWrapper();

		@$wr->stream_open("$scheme://dir/file", 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$scheme://file", 'r', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$scheme://file", 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$scheme://file", 'a', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$scheme://file", 'w+', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);
	}

	public function testPermissionsAreCheckedWhenCreatingDirectories(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createDir('/test', false, 0_000);

		$wr = new StreamWrapper();

		self::assertFalse(@$wr->mkdir("$scheme://test/dir", 0_777, 0));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mkdir: %s: Permission denied',
			$error['message'],
		);
	}

	public function testPermissionsAreCheckedWhenRemovingFiles(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file');
		$file->setMode(0_000);

		$wr = new StreamWrapper();
		self::assertTrue($wr->unlink("$scheme://file"), 'Allows removals with writable parent');

		$container->getRootDirectory()->setMode(0_500);

		self::assertFalse(
			@$wr->unlink("$scheme://file"),
			'Does not allow removals with non-writable parent',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rm: %s: Permission denied',
			$error['message'],
		);
	}

	public function testRmDirNotAllowedWhenDirectoryNotWritable(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$dir = $container->createDir('/dir');

		$wr = new StreamWrapper();

		$dir->setMode(0_000);
		@rmdir("$scheme://dir");
		self::assertFalse(
			@$wr->rmdir("$scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with no permissions',
		);

		$dir->setMode(0_100);
		self::assertFalse(
			@$wr->rmdir("$scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with exec only',
		);

		$dir->setMode(0_200);
		self::assertFalse(
			@$wr->rmdir("$scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with write',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rmdir: %s: Permission denied',
			$error['message'],
		);

		$dir->setMode(0_400);
		self::assertTrue(
			$wr->rmdir("$scheme://dir", STREAM_REPORT_ERRORS),
			'Directory removed with read permission, yes that is how it normally behaves ;)',
		);
	}

	public function testChmodNotAllowedIfNotOwner(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_ACCESS, 0_000),
			'Not allowed to chmod if not owner',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chmod: %s: Permission denied',
			$error['message'],
		);
	}

	public function testChownAndChgrpAllowedIfOwner(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		$uid = $this->uid + 1;

		$wr = new StreamWrapper();

		self::assertTrue(
			$wr->stream_metadata("$scheme://$fileName", STREAM_META_OWNER, $uid),
		);

		$file = $container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$scheme://$fileName", STREAM_META_OWNER_NAME, 'user'),
		);

		$file = $container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$scheme://$fileName", STREAM_META_GROUP, $uid),
		);

		$file = $container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$scheme://$fileName", STREAM_META_GROUP_NAME, 'userGroup'),
		);
	}

	public function testChownAndChgrpNotAllowedIfNotRoot(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. ' .
				'Php unit shouldn\'t be run as root user. (Unless you are a windows user!)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_OWNER, 1),
			'Not allowed to chown if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chown: %s: Permission denied',
			$error['message'],
		);

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_OWNER_NAME, 'user'),
			'Not allowed to chown by name if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chown: %s: Permission denied',
			$error['message'],
		);

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_GROUP, 1),
			'Not allowed to chgrp if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chgrp: %s: Permission denied',
			$error['message'],
		);

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_GROUP_NAME, 'group'),
			'Not allowed to chgrp by name if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chgrp: %s: Permission denied',
			$error['message'],
		);
	}

	public function testTouchNotAllowedIfNotOwnerOrNotWritable(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = $container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_000);

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$scheme://file", STREAM_META_TOUCH, 0),
			'Not allowed to touch if not owner and no permission',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'touch: %s: Permission denied',
			$error['message'],
		);

		$file->setUser($this->uid);

		self::assertTrue(
			$wr->stream_metadata("$scheme://file", STREAM_META_TOUCH, 0),
			'Allowed to touch if owner and no permission',
		);

		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_002);

		self::assertTrue(
			$wr->stream_metadata("$scheme://file", STREAM_META_TOUCH, 0),
			'Allowed to touch if not owner but with write permission',
		);
	}

	public function testLchown(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. ' .
				'Php unit shouldn\'t be run as root user. (Unless you are a windows user!)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$factory = $container->getFactory();
		$directory = $container->createDir('/dir');
		$link = $factory->createLink('link', $directory);
		$directory->addLink($link);

		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		lchown("$scheme://dir/link", 'root');
		self::assertEquals('root', posix_getpwuid(fileowner("$scheme://dir/link"))['name']);
	}

	public function testLchgrp(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$factory = $container->getFactory();
		$directory = $container->createDir('/dir');
		$link = $factory->createLink('link', $directory);
		$directory->addLink($link);

		$container->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$group = posix_getgrgid(posix_getpwuid(0)['gid'])['name'];

		chgrp("$scheme://dir/link", $group);

		self::assertEquals($group, posix_getgrgid(filegroup("$scheme://dir/link"))['name']);
	}

	public function testFileCopy(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'data');

		copy("$scheme://file", "$scheme://file2");

		self::assertFileExists("$scheme://file2");

		self::assertEquals('data', $container->getFileAt('/file2')->getData());
	}

	public function testLinkCopyCreatesHardCopyOfFile(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'data');
		$container->createLink('/link', '/file');

		copy("$scheme://link", "$scheme://file2");

		self::assertFileExists("$scheme://file2");
		self::assertEquals('data', $container->getFileAt('/file2')->getData());
	}

	public function testLinkReading(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'data');
		$container->createLink('/link', '/file');

		self::assertEquals('data', file_get_contents("$scheme://link"));
	}

	public function testLinkWriting(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file', 'ubots!');
		$container->createLink('/link', '/file');

		file_put_contents("$scheme://link", 'data');

		self::assertEquals('data', file_get_contents("$scheme://link"));
	}

	public function testChmodViaLink(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$name = "$scheme://{$container->createFile('/file')->getPath()}";
		$link = "$scheme://{$container->createLink('/link', '/file')->getPath()}";

		chmod($link, 0_000);

		self::assertFalse(is_readable($name));
		self::assertFalse(is_writable($name));
		self::assertFalse(is_executable($name));

		chmod($link, 0_700);

		self::assertTrue(is_readable($name));
		self::assertTrue(is_writable($name));
		self::assertTrue(is_executable($name));
	}

	public function testIsExecutableReturnsCorrectly(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$container->createFile('/file');

		chmod("$scheme://file", 0_000);

		self::assertFalse(is_executable("$scheme://file"));

		chmod("$scheme://file", 0_777);

		self::assertTrue(is_executable("$scheme://file"));
	}

	public function testExclusiveLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testSharedLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');
		$fh3 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
		self::assertFalse(flock($fh3, LOCK_EX | LOCK_NB));
	}

	public function testUnlockSharedLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testUnlockExclusiveLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testDowngradeExclusiveLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLock(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLockImpossible(): void
	{
		$scheme = VFS::register();
		$file = "$scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
		self::assertFalse(flock($fh1, LOCK_EX | LOCK_NB));
	}

	public function testFileSize(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		$file = "$scheme://{$container->createFile('/file', '12345')->getPath()}";

		self::assertEquals(5, filesize($file));
	}

	public function testRmdirAfterUrlStatCall(): void
	{
		$scheme = VFS::register();

		$path = "$scheme://dir";

		mkdir($path);

		self::assertFileExists($path);

		rmdir($path);

		self::assertFileDoesNotExist($path);
	}

	public function testUnlinkAfterUrlStatCall(): void
	{
		$scheme = VFS::register();

		$path = "$scheme://file";

		touch($path);

		self::assertFileExists($path);

		unlink($path);

		self::assertFileDoesNotExist($path);
		VFS::unregister($scheme);
	}

	public function testFinfoSupport(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);

		$container->createFile(
			'/file.gif',
			base64_decode('R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', true),
		);

		$finfo = new finfo(FILEINFO_MIME_TYPE);

		self::assertEquals('image/gif', $finfo->file("$scheme://file.gif"));
	}

	public function testRequire(): void
	{
		$scheme = VFS::register();
		$container = StreamWrapper::getContainer($scheme);
		// phpcs:disable SlevomatCodingStandard.Functions.RequireSingleLineCall
		$container->createFile(
			'/file.php',
			<<<'PHP'
<?php return 1;
PHP,
		);
		// phpcs:enable

		self::assertSame(1, require "$scheme://file.php");
	}

}
