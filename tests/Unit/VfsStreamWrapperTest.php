<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use DirectoryIterator;
use FilesystemIterator;
use finfo;
use Orisai\VFS\Container;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\VFS;
use Orisai\VFS\VfsStreamWrapper;
use Orisai\VFS\Wrapper\PermissionChecker;
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
use function fwrite;
use function is_executable;
use function is_file;
use function is_link;
use function is_readable;
use function is_writable;
use function iterator_count;
use function lchown;
use function mkdir;
use function opendir;
use function posix_getgrgid;
use function posix_getpwuid;
use function rename;
use function rmdir;
use function scandir;
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
use const SCANDIR_SORT_DESCENDING;
use const SCANDIR_SORT_NONE;
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

final class VfsStreamWrapperTest extends TestCase
{

	private string $scheme;

	private Container $container;

	private int $uid;

	private int $gid;

	public function setUp(): void
	{
		parent::setUp();
		$this->scheme = VFS::register();
		$this->container = VfsStreamWrapper::getContainer($this->scheme);
		$factory = $this->container->getFactory();
		$this->uid = $factory->getUid();
		$this->gid = $factory->getGid();
	}

	protected function tearDown(): void
	{
		parent::tearDown();
		VFS::unregister($this->scheme);
	}

	public function testSchemeStripping(): void
	{
		self::assertSame('/1/2/3/4', VfsStreamWrapper::stripScheme('test://1/2/3/4'));
		self::assertSame('/', VfsStreamWrapper::stripScheme('test://'));
		self::assertSame('/', VfsStreamWrapper::stripScheme('test:///'));
		self::assertSame('/dir', VfsStreamWrapper::stripScheme('test:///dir'));
	}

	public function testContainerIsReturnedFromContext(): void
	{
		self::assertSame($this->container, VfsStreamWrapper::getContainer("$this->scheme://file"));
		self::assertSame($this->container, VfsStreamWrapper::getContainer("$this->scheme://"));
		self::assertSame($this->container, VfsStreamWrapper::getContainer("$this->scheme:///file"));
	}

	public function testFileExists(): void
	{
		mkdir($dir = "$this->scheme://dir");

		touch("$dir/file");
		mkdir("$dir/dir");

		self::assertFileExists("$dir/file");
		self::assertFileExists($dir);
		self::assertFileDoesNotExist("$dir/fileNotExist");
	}

	public function testIsDir(): void
	{
		mkdir($dir = "$this->scheme://dir");

		touch("$dir/file");
		mkdir("$dir/dir");

		self::assertDirectoryDoesNotExist("$dir/file");
		self::assertDirectoryExists($dir);
		self::assertDirectoryExists("$dir/dir");
		self::assertDirectoryExists("$this->scheme://");
	}

	public function testIsLink(): void
	{
		$factory = $this->container->getFactory();

		$this->container->getRootDirectory()->addDirectory($d = $factory->createDir('dir'));
		$d->addLink($factory->createLink('link', $d));

		self::assertTrue(is_link("$this->scheme://dir/link"));
	}

	public function testIsFile(): void
	{
		mkdir($dir = "$this->scheme://dir");

		touch("$dir/file");
		touch("$this->scheme://file2");
		mkdir("$dir/dir");

		self::assertTrue(is_file("$dir/file"));
		self::assertFalse(is_file($dir));
		self::assertFalse(is_file("$dir/dir"));
		self::assertFalse(is_file("$this->scheme://"));
		self::assertTrue(is_file("$this->scheme://file2"));
	}

	public function testChmod(): void
	{
		$root = $this->container->getRootDirectory();

		$path = "$this->scheme://";

		chmod($path, 0_777);
		self::assertSame(0_777 | Directory::getStatType(), $root->getMode());

		$root->setMode(0_755);
		self::assertSame(0_755 | Directory::getStatType(), fileperms($path));

		//accessing non existent file should return false
		self::assertFalse(chmod("$this->scheme://nonExistingFile", 0_777));
	}

	public function testChownByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. ' .
				'Php unit shouldn\'t be run as root user. (Unless you are a windows user!)',
			);
		}

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		chown("$this->scheme://", 'root');
		$userInfo = posix_getpwuid(fileowner("$this->scheme://"));
		self::assertNotFalse($userInfo);

		self::assertSame('root', $userInfo['name']);
	}

	public function testChownById(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. Php unit shouldn\'t be run as root user.',
			);
		}

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		chown("$this->scheme://", 0);

		self::assertSame(0, fileowner("$this->scheme://"));
	}

	public function testChgrpByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$oldUserInfo = posix_getpwuid(0);
		self::assertNotFalse($oldUserInfo);

		$oldGroupInfo = posix_getgrgid($oldUserInfo['gid']);
		self::assertNotFalse($oldGroupInfo);

		$groupName = $oldGroupInfo['name'];

		chgrp("$this->scheme://", $groupName);

		$userInfo = posix_getpwuid(0);
		self::assertNotFalse($userInfo);
		$groupInfo = posix_getgrgid($userInfo['gid']);
		self::assertNotFalse($groupInfo);

		self::assertSame($groupName, $groupInfo['name']);
	}

	public function testChgrpById(): void
	{
		if ($this->gid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		//lets workout available group
		$userInfo = posix_getpwuid(0);
		self::assertNotFalse($userInfo);

		$group = $userInfo['gid'];

		chgrp("$this->scheme://", $group);

		self::assertSame($group, filegroup("$this->scheme://"));
	}

	public function testMkdir(): void
	{
		mkdir("$this->scheme://dir");

		self::assertFileExists("$this->scheme://dir");
		self::assertDirectoryExists("$this->scheme://dir");

		mkdir("$this->scheme://dir2", 0_000, false);

		$dir = $this->container->getNodeAt('/dir2');

		self::assertSame(0_000 | Directory::getStatType(), $dir->getMode());
	}

	public function testMkdirCatchesClashes(): void
	{
		mkdir("$this->scheme://dir");
		@mkdir("$this->scheme://dir");

		$error = error_get_last();

		self::assertSame('dir already exists', $error['message']);
	}

	public function testMkdirRecursive(): void
	{
		mkdir("$this->scheme://dir/dir2", 0_777, true);

		self::assertFileExists("$this->scheme://dir/dir2");
		self::assertDirectoryExists("$this->scheme://dir/dir2");

		@mkdir("$this->scheme://dir/a/b", 0_777, false);

		$error = error_get_last();

		self::assertSame('mkdir: /dir/a: No such file or directory', $error['message']);
	}

	public function testStreamWriting(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		self::assertSame('data', file_get_contents("$this->scheme://file"));

		//long strings
		file_put_contents("$this->scheme://file2", str_repeat('data ', 5_000));

		self::assertSame(str_repeat('data ', 5_000), file_get_contents("$this->scheme://file2"));

		//truncating
		file_put_contents("$this->scheme://file", 'data2');

		self::assertSame('data2', file_get_contents("$this->scheme://file"));

		//appending
		file_put_contents("$this->scheme://file", 'data3', FILE_APPEND);

		self::assertSame('data2data3', file_get_contents("$this->scheme://file"));

		$handle = fopen("$this->scheme://file2", 'w');

		fwrite($handle, 'data');
		self::assertSame('data', file_get_contents("$this->scheme://file2"));

		fwrite($handle, '2');
		self::assertSame('data2', file_get_contents("$this->scheme://file2"), 'Pointer advanced');

		fwrite($handle, 'data', 1);
		self::assertSame(
			'data2d',
			file_get_contents("$this->scheme://file2"),
			'Written with limited length',
		);
	}

	public function testStreamReading(): void
	{
		file_put_contents("$this->scheme://file", 'test data');

		self::assertSame('test data', file_get_contents("$this->scheme://file"));

		//long string
		file_put_contents("$this->scheme://file2", str_repeat('test data', 5_000));
		self::assertSame(str_repeat('test data', 5_000), file_get_contents("$this->scheme://file2"));

		mkdir("$this->scheme://dir");

		self::assertEmpty(file_get_contents("$this->scheme://dir"));
	}

	public function testStreamFlushing(): void
	{
		$handle = fopen("$this->scheme://file", 'w');

		self::assertTrue(fflush($handle));
	}

	public function testOpeningForReadingOnNonExistingFails(): void
	{
		self::assertFalse(@fopen("$this->scheme://nonExistingFile", 'r'));

		$class = VfsStreamWrapper::class;
		$error = error_get_last();

		if (PHP_VERSION_ID >= 8_00_00) {
			self::assertSame(
				"fopen($this->scheme://nonExistingFile): Failed to open stream: \"$class::stream_open\" call failed",
				$error['message'],
			);
		} else {
			self::assertSame(
				"fopen($this->scheme://nonExistingFile): failed to open stream: \"$class::stream_open\" call failed",
				$error['message'],
			);
		}
	}

	public function testOpeningForWritingCorrectlyOpensAndTruncatesFile(): void
	{
		$handle = fopen("$this->scheme://nonExistingFile", 'w');

		self::assertIsResource($handle);

		file_put_contents("$this->scheme://file", 'data');

		$handle = fopen("$this->scheme://file", 'w');

		self::assertIsResource($handle);
		self::assertSame('', file_get_contents("$this->scheme://file"));
	}

	public function testOpeningForAppendingDoesNotTruncateFile(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		$handle = fopen("$this->scheme://file", 'a');

		self::assertIsResource($handle);
		self::assertSame('data', file_get_contents("$this->scheme://file"));
	}

	public function testCreatingFileWhileOpeningFailsCorrectly(): void
	{
		self::assertFalse(@fopen("$this->scheme://dir/file", 'w'));

		$class = VfsStreamWrapper::class;
		$error = error_get_last();

		if (PHP_VERSION_ID >= 8_00_00) {
			self::assertSame(
				"fopen($this->scheme://dir/file): Failed to open stream: \"$class::stream_open\" call failed",
				$error['message'],
			);
		} else {
			self::assertSame(
				"fopen($this->scheme://dir/file): failed to open stream: \"$class::stream_open\" call failed",
				$error['message'],
			);
		}
	}

	public function testFileGetContentsOffsetsAndLimitsCorrectly(): void
	{
		file_put_contents("$this->scheme://file", '--data--');

		self::assertSame('data', file_get_contents("$this->scheme://file", false, null, 2, 4));
	}

	public function testFileSeeking(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		$handle = fopen("$this->scheme://file", 'r');

		fseek($handle, 2);
		self::assertSame(2, ftell($handle));

		fseek($handle, 1, SEEK_CUR);
		self::assertSame(3, ftell($handle));

		fseek($handle, 6, SEEK_END);
		self::assertSame(10, ftell($handle), 'End of file + 6 is 10');
	}

	public function testFileTruncating(): void
	{
		file_put_contents("$this->scheme://file", 'data--');

		//has to opened for append otherwise file is automatically truncated by 'w' opening mode
		$handle = fopen("$this->scheme://file", 'a');

		ftruncate($handle, 4);

		self::assertSame('data', file_get_contents("$this->scheme://file"));
	}

	public function testOpeningModesAreHandledCorrectly(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		$handle = fopen("$this->scheme://file", 'r');
		self::assertSame('data', fread($handle, 4), 'Contents can be read in read mode');
		self::assertSame(0, fwrite($handle, '--'), '0 bytes should be written in readonly mode');

		$handle = fopen("$this->scheme://file", 'r+');
		self::assertSame('data', fread($handle, 4), 'Contents can be read in extended read mode');
		self::assertSame(2, fwrite($handle, '--'), '2 bytes should be written in extended readonly mode');

		$handle = fopen("$this->scheme://file", 'w');
		self::assertSame(4, fwrite($handle, 'data'), '4 bytes written in writeonly mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in write only mode');

		$handle = fopen("$this->scheme://file", 'w+');
		self::assertSame(4, fwrite($handle, 'data'), '4 bytes written in extended writeonly mode');
		fseek($handle, 0);
		self::assertSame('data', fread($handle, 4), 'Bytes read in extended write only mode');

		file_put_contents("$this->scheme://file", 'data');

		$handle = fopen("$this->scheme://file", 'a');
		self::assertSame(4, fwrite($handle, 'data'), '4 bytes written in append mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in append mode');

		$handle = fopen("$this->scheme://file", 'a+');
		self::assertSame(4, fwrite($handle, 'data'), '4 bytes written in extended append mode');
		fseek($handle, 0);
		self::assertSame('datadata', fread($handle, 8), 'Bytes read in extended append mode');
	}

	public function testFileTimesAreModifiedCorrectly(): void
	{
		$file = $this->container->createFile('/file', 'data');

		$stat = stat("$this->scheme://file");

		self::assertNotSame(0, $stat['atime']);
		self::assertNotSame(0, $stat['mtime']);
		self::assertNotSame(0, $stat['ctime']);

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_get_contents("$this->scheme://file");
		$stat = stat("$this->scheme://file");

		self::assertNotSame(10, $stat['atime'], 'Access time has changed after read');
		self::assertSame(10, $stat['mtime'], 'Modification time has not changed after read');
		self::assertSame(10, $stat['ctime'], 'inode change time has not changed after read');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_put_contents("$this->scheme://file", 'data');
		$stat = stat("$this->scheme://file");

		self::assertSame(10, $stat['atime'], 'Access time has not changed after write');
		self::assertNotSame(10, $stat['mtime'], 'Modification time has changed after write');
		self::assertNotSame(10, $stat['ctime'], 'inode change time has changed after write');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		chmod("$this->scheme://file", 0_777);
		$stat = stat("$this->scheme://file");

		self::assertSame(10, $stat['atime'], 'Access time has not changed after inode change');
		self::assertSame(10, $stat['mtime'], 'Modification time has not changed after inode change');
		self::assertNotSame(10, $stat['ctime'], 'inode change time has changed after inode change');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		clearstatcache();

		fopen("$this->scheme://file", 'r');
		$stat = stat("$this->scheme://file");

		self::assertSame(10, $stat['atime'], 'Access time has not changed after opening for reading');
		self::assertSame(10, $stat['mtime'], 'Modification time has not changed after opening for reading');
		self::assertSame(10, $stat['ctime'], 'inode change time has not changed after opening for reading');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		fopen("$this->scheme://file", 'w');
		$stat = stat("$this->scheme://file");

		self::assertSame(20, $stat['atime'], 'Access time has not changed after opening for writing');
		self::assertNotSame(20, $stat['mtime'], 'Modification time has changed after opnening for writing');
		self::assertNotSame(20, $stat['ctime'], 'inode change time has changed after opnening for writing');
	}

	public function testTouchFileCreation(): void
	{
		touch("$this->scheme://file2");

		self::assertFileExists("$this->scheme://file2");

		@touch("$this->scheme://dir/file");

		$error = error_get_last();

		self::assertSame(
			'touch: /dir/file: No such file or directory.',
			$error['message'],
			'Fails when no parent',
		);

		$file = $this->container->getNodeAt('/file2');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		touch("$this->scheme://file2");
		$stat = stat("$this->scheme://file2");

		self::assertNotSame(20, $stat['atime'], 'Access time has changed after touch');
		self::assertNotSame(20, $stat['mtime'], 'Modification time has changed after touch');
		self::assertNotSame(20, $stat['ctime'], 'inode change time has changed after touch');
	}

	public function testTouchUpdatesTimes(): void
	{
		$path = "$this->scheme://file";

		$time = 1_500_020_720;
		$atime = 1_500_204_791;

		touch($path, $time, $atime);

		self::assertSame($time, filectime($path));
		self::assertSame($time, filemtime($path));
		self::assertSame($atime, fileatime($path));
	}

	public function testRenamesMovesFileCorrectly(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		rename("$this->scheme://file", "$this->scheme://file2");

		self::assertFileExists("$this->scheme://file2");
		self::assertFileDoesNotExist("$this->scheme://file");
		self::assertSame('data', file_get_contents("$this->scheme://file2"));
	}

	public function testRenameReturnsCorrectWarnings(): void
	{
		@rename("$this->scheme://file", "$this->scheme://dir/file2");

		$error = error_get_last();

		self::assertSame(
			'mv: rename /file to /dir/file2: No such file or directory',
			$error['message'],
			'Triggers when moving non existing file',
		);

		touch("$this->scheme://file");

		@rename("$this->scheme://file", "$this->scheme://dir/file2");

		$error = error_get_last();

		self::assertSame(
			'mv: rename /file to /dir/file2: No such file or directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);

		mkdir("$this->scheme://dir");

		@rename("$this->scheme://dir", "$this->scheme://file");

		$error = error_get_last();

		self::assertSame(
			'mv: rename /dir to /file: Not a directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);
	}

	public function testRenameFailsCorrectly(): void
	{
		self::assertFalse(@rename("$this->scheme://file", "$this->scheme://dir/file2"));

		touch("$this->scheme://file");

		self::assertFalse(@rename("$this->scheme://file", "$this->scheme://dir/file2"));

		mkdir("$this->scheme://dir");

		self::assertFalse(@rename("$this->scheme://dir", "$this->scheme://file"));
	}

	public function testUnlinkRemovesFile(): void
	{
		touch("$this->scheme://file");

		unlink("$this->scheme://file");

		self::assertFileDoesNotExist("$this->scheme://file");
	}

	public function testUnlinkThrowsWarnings(): void
	{
		@unlink("$this->scheme://file");

		$error = error_get_last();

		self::assertSame(
			'rm: /file: No such file or directory',
			$error['message'],
			'Warning when file does not exist',
		);

		mkdir("$this->scheme://dir");

		@unlink("$this->scheme://dir");

		$error = error_get_last();

		self::assertSame(
			'rm: /dir: is a directory',
			$error['message'],
			'Warning when trying to remove directory',
		);
	}

	public function testRmdirRemovesDirectories(): void
	{
		mkdir("$this->scheme://dir");

		rmdir("$this->scheme://dir");

		self::assertFileDoesNotExist("$this->scheme://dir", 'Directory has been removed');
	}

	public function testRmdirErrorsWithNonEmptyDirectories(): void
	{
		mkdir("$this->scheme://dir/dir", 0_777, true);

		@rmdir("$this->scheme://dir");

		$error = error_get_last();

		self::assertSame(
			'Warning: rmdir(/dir): Directory not empty',
			$error['message'],
			'Warning triggered when removing non empty directory',
		);
	}

	public function testRmdirErrorsWhenRemovingNonExistingDirectory(): void
	{
		@rmdir("$this->scheme://dir");

		$error = error_get_last();

		self::assertSame(
			'Warning: rmdir(/dir): No such file or directory',
			$error['message'],
			'Warning triggered when removing non existing directory',
		);
	}

	public function testRmdirErrorsWhenRemovingFile(): void
	{
		touch("$this->scheme://file");

		@rmdir("$this->scheme://file");

		$error = error_get_last();

		self::assertSame(
			'Warning: rmdir(/file): Not a directory',
			$error['message'],
			'Warning triggered when trying to remove a file',
		);
	}

	public function testStreamOpenWarnsWhenFlagPassed(): void
	{
		$opened_path = null;

		$wrapper = new VfsStreamWrapper();

		self::assertFalse(
			$wrapper->stream_open("$this->scheme://file", 'r', 0, $opened_path),
			'No warning when no flag',
		);

		@$wrapper->stream_open("$this->scheme://file", 'r', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertSame(
			'/file: failed to open stream.',
			$error['message'],
			'Stream open errors when flag passed',
		);
	}

	public function testDirectoryOpensForReading(): void
	{
		mkdir("$this->scheme://dir");

		$wrapper = new VfsStreamWrapper();

		$handle = $wrapper->dir_opendir("$this->scheme://dir", STREAM_BUFFER_NONE);

		self::assertTrue($handle, 'Directory opened for reading');

		$handle = @$wrapper->dir_opendir("$this->scheme://nonExistingDir", STREAM_BUFFER_NONE);

		self::assertFalse($handle, 'Non existing directory not opened for reading');

		$error = error_get_last();

		self::assertSame(
			'opendir(/nonExistingDir): failed to open dir: No such file or directory',
			$error['message'],
			'Opening non existing directory triggers warning',
		);

		$handle = opendir("$this->scheme://dir");

		self::assertIsResource($handle, 'opendir uses dir_opendir');
	}

	public function testDirectoryOpenDoesNotOpenFiles(): void
	{
		touch("$this->scheme://file");

		$wrapper = new VfsStreamWrapper();

		$handle = @$wrapper->dir_opendir("$this->scheme://file", STREAM_BUFFER_NONE);

		self::assertFalse($handle, 'Opening fiels with opendir fails');

		$error = error_get_last();

		self::assertSame(
			'opendir(/file): failed to open dir: Not a directory',
			$error['message'],
			'Opening fiels with opendir triggers warning',
		);
	}

	public function testDirectoryCloses(): void
	{
		mkdir("$this->scheme://dir");

		$wrapper = new VfsStreamWrapper();

		self::assertFalse($wrapper->dir_closedir(), 'Returns false when no dir opened');

		$wrapper->dir_opendir("$this->scheme://dir", STREAM_BUFFER_NONE);

		self::assertTrue($wrapper->dir_closedir());
	}

	public function testDirectoryReading(): void
	{
		mkdir("$this->scheme://dir1");
		mkdir("$this->scheme://dir2");
		mkdir("$this->scheme://dir3");

		$wr = new VfsStreamWrapper();
		$wr->dir_opendir("$this->scheme://", STREAM_BUFFER_NONE);

		self::assertSame('.', $wr->dir_readdir());
		self::assertSame('..', $wr->dir_readdir());
		self::assertSame('dir1', $wr->dir_readdir());
		self::assertSame('dir2', $wr->dir_readdir());
		self::assertSame('dir3', $wr->dir_readdir());
		self::assertFalse($wr->dir_readdir());

		$wr->dir_rewinddir();
		self::assertSame('.', $wr->dir_readdir(), 'Directory rewound');
	}

	public function testDirectoryIterationWithDirectoryIterator(): void
	{
		mkdir("$this->scheme://dir1");
		mkdir("$this->scheme://dir2");
		mkdir("$this->scheme://dir3");

		$result = [];

		foreach (new DirectoryIterator("$this->scheme://") as $fileInfo) {
			$result[] = $fileInfo->getBasename();
		}

		self::assertSame(
			[
				'.',
				'..',
				'dir1',
				'dir2',
				'dir3',
			],
			$result,
			'All directories found',
		);
	}

	public function testStreamOpenDoesNotOpenDirectoriesForWriting(): void
	{
		mkdir("$this->scheme://dir");

		self::assertFalse(@fopen("$this->scheme://dir", 'w'));
		self::assertFalse(@fopen("$this->scheme://dir", 'r+'));
		self::assertFalse(@fopen("$this->scheme://dir", 'w+'));
		self::assertFalse(@fopen("$this->scheme://dir", 'a'));
		self::assertFalse(@fopen("$this->scheme://dir", 'a+'));

		$opened_path = null;

		$wr = new VfsStreamWrapper();
		@$wr->stream_open("$this->scheme://dir", 'w', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertSame(
			'fopen(/dir): failed to open stream: Is a directory',
			$error['message'],
			'Stream does not open directories',
		);
	}

	public function testStreamOpenAllowsForDirectoryOpeningForReadingAndReturnsEmptyStrings(): void
	{
		mkdir("$this->scheme://dir");

		$handle = fopen("$this->scheme://dir", 'r');

		self::assertIsResource($handle);

		self::assertEmpty(fread($handle, 1));
	}

	public function testPermissionsAreCheckedWhenOpeningFiles(): void
	{
		$file = $this->container->createFile('/file');
		$openedPath = null;

		$wr = new VfsStreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($wr->stream_open("$this->scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($wr->stream_open("$this->scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($wr->stream_open("$this->scheme://file", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://file", 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($wr->stream_open("$this->scheme://file", 'r', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'w', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'a', 0, $openedPath));
		self::assertTrue($wr->stream_open("$this->scheme://file", 'a+', 0, $openedPath));
	}

	public function testTemporaryFileCreatedToReadDirectoriesWithStreamOpenInheritsPermissions(): void
	{
		$file = $this->container->createDir('/dir');
		$openedPath = null;

		$wr = new VfsStreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue($wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open("$this->scheme://dir", 'a+', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenOpeningDirectories(): void
	{
		$file = $this->container->createDir('/dir');
		$openedPath = null;

		$wr = new VfsStreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse(@$wr->dir_opendir("$this->scheme://dir", STREAM_BUFFER_NONE));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertFalse(@$wr->dir_opendir("$this->scheme://dir", STREAM_BUFFER_NONE));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionChecker::RootId);
		self::assertTrue(@$wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));

		$file->setMode(0_040);
		$file->setUser(PermissionChecker::RootId);
		$file->setGroup($this->gid);
		self::assertTrue(@$wr->stream_open("$this->scheme://dir", 'r', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenCreatingFilesWithinDirectories(): void
	{
		$dir = $this->container->createDir('/dir');

		$dir->setMode(0_000);
		self::assertFalse(@file_put_contents("$this->scheme://dir/file", 'data'));

		$dir->setMode(0_400);
		self::assertFalse(@file_put_contents("$this->scheme://dir/file", 'data'));

		$dir->setMode(0_200);
		self::assertGreaterThan(0, @file_put_contents("$this->scheme://dir/file", 'data'));
	}

	public function testStreamOpenReportsErrorsOnPermissionDenied(): void
	{
		$dir = $this->container->createDir('/dir');
		$file = $this->container->createFile('/file');
		$dir->setMode(0_000);

		$openedPath = null;

		$wr = new VfsStreamWrapper();

		@$wr->stream_open("$this->scheme://dir/file", 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertSame(
			'fopen(/dir/file): failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$this->scheme://file", 'r', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertSame(
			'fopen(/file): failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$this->scheme://file", 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertSame(
			'fopen(/file): failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$this->scheme://file", 'a', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertSame(
			'fopen(/file): failed to open stream: Permission denied',
			$error['message'],
		);

		$file->setMode(0_000);
		@$wr->stream_open("$this->scheme://file", 'w+', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertSame(
			'fopen(/file): failed to open stream: Permission denied',
			$error['message'],
		);
	}

	public function testPermissionsAreCheckedWhenCreatingDirectories(): void
	{
		mkdir("$this->scheme://test", 0_000);

		$wr = new VfsStreamWrapper();

		self::assertFalse(@$wr->mkdir("$this->scheme://test/dir", 0_777, 0));

		$error = error_get_last();

		self::assertSame('mkdir: /test: Permission denied', $error['message']);
	}

	public function testPermissionsAreCheckedWhenRemovingFiles(): void
	{
		$file = $this->container->createFile('/file');
		$file->setMode(0_000);

		$wr = new VfsStreamWrapper();
		self::assertTrue($wr->unlink("$this->scheme://file"), 'Allows removals with writable parent');

		$this->container->getRootDirectory()->setMode(0_500);

		self::assertFalse(
			@$wr->unlink("$this->scheme://file"),
			'Does not allow removals with non-writable parent',
		);

		$error = error_get_last();

		self::assertSame('rm: /file: Permission denied', $error['message']);
	}

	public function testRmDirNotAllowedWhenDirectoryNotWritable(): void
	{
		$dir = $this->container->createDir('/dir');

		$wr = new VfsStreamWrapper();

		$dir->setMode(0_000);
		@rmdir("$this->scheme://dir");
		self::assertFalse(
			@$wr->rmdir("$this->scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with no permissions',
		);

		$dir->setMode(0_100);
		self::assertFalse(
			@$wr->rmdir("$this->scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with exec only',
		);

		$dir->setMode(0_200);
		self::assertFalse(
			@$wr->rmdir("$this->scheme://dir", STREAM_REPORT_ERRORS),
			'Directory not removed with write',
		);

		$error = error_get_last();

		self::assertSame('rmdir: /dir: Permission denied', $error['message']);

		$dir->setMode(0_400);
		self::assertTrue(
			$wr->rmdir("$this->scheme://dir", STREAM_REPORT_ERRORS),
			'Directory removed with read permission, yes that is how it normally behaves ;)',
		);
	}

	public function testChmodNotAllowedIfNotOwner(): void
	{
		$file = $this->container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new VfsStreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_ACCESS, 0_000),
			'Not allowed to chmod if not owner',
		);

		$error = error_get_last();

		self::assertSame('chmod: /file: Permission denied', $error['message']);
	}

	public function testChownAndChgrpAllowedIfOwner(): void
	{
		$file = $this->container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		$uid = $this->uid + 1;

		$wr = new VfsStreamWrapper();

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://$fileName", STREAM_META_OWNER, $uid),
		);

		$file = $this->container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://$fileName", STREAM_META_OWNER_NAME, 'user'),
		);

		$file = $this->container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://$fileName", STREAM_META_GROUP, $uid),
		);

		$file = $this->container->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://$fileName", STREAM_META_GROUP_NAME, 'userGroup'),
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

		$file = $this->container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new VfsStreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_OWNER, 1),
			'Not allowed to chown if not root',
		);

		$error = error_get_last();

		self::assertSame('chown: /file: Permission denied', $error['message']);

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_OWNER_NAME, 'user'),
			'Not allowed to chown by name if not root',
		);

		$error = error_get_last();

		self::assertSame('chown: /file: Permission denied', $error['message']);

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_GROUP, 1),
			'Not allowed to chgrp if not root',
		);

		$error = error_get_last();

		self::assertSame('chgrp: /file: Permission denied', $error['message']);

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_GROUP_NAME, 'group'),
			'Not allowed to chgrp by name if not root',
		);

		$error = error_get_last();

		self::assertSame('chgrp: /file: Permission denied', $error['message']);
	}

	public function testTouchNotAllowedIfNotOwnerOrNotWritable(): void
	{
		$file = $this->container->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_000);

		$wr = new VfsStreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata("$this->scheme://file", STREAM_META_TOUCH, 0),
			'Not allowed to touch if not owner and no permission',
		);

		$error = error_get_last();

		self::assertSame('touch: /file: Permission denied', $error['message']);

		$file->setUser($this->uid);

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://file", STREAM_META_TOUCH, 0),
			'Allowed to touch if owner and no permission',
		);

		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_002);

		self::assertTrue(
			$wr->stream_metadata("$this->scheme://file", STREAM_META_TOUCH, 0),
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

		$factory = $this->container->getFactory();
		$directory = $this->container->createDir('/dir');
		$link = $factory->createLink('link', $directory);
		$directory->addLink($link);

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		lchown("$this->scheme://dir/link", 'root');

		$userInfo = posix_getpwuid(fileowner("$this->scheme://dir/link"));
		self::assertNotFalse($userInfo);

		self::assertSame('root', $userInfo['name']);
	}

	public function testLchgrp(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$factory = $this->container->getFactory();
		$directory = $this->container->createDir('/dir');
		$link = $factory->createLink('link', $directory);
		$directory->addLink($link);

		$this->container->setPermissionChecker(
			new PermissionChecker(PermissionChecker::RootId, PermissionChecker::RootId),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$userInfo = posix_getpwuid(0);
		self::assertNotFalse($userInfo);

		$oldGroupInfo = posix_getgrgid($userInfo['gid']);
		self::assertNotFalse($oldGroupInfo);

		$group = $oldGroupInfo['name'];

		chgrp("$this->scheme://dir/link", $group);
		$groupInfo = posix_getgrgid(filegroup("$this->scheme://dir/link"));
		self::assertNotFalse($groupInfo);

		self::assertSame($group, $groupInfo['name']);
	}

	public function testFileCopy(): void
	{
		file_put_contents("$this->scheme://file", 'data');

		copy("$this->scheme://file", "$this->scheme://file2");

		self::assertFileExists("$this->scheme://file2");

		self::assertSame('data', file_get_contents("$this->scheme://file2"));
	}

	public function testLinkCopyCreatesHardCopyOfFile(): void
	{
		file_put_contents("$this->scheme://file", 'data');
		$this->container->createLink('/link', '/file');

		copy("$this->scheme://link", "$this->scheme://file2");

		self::assertFileExists("$this->scheme://file2");
		self::assertSame('data', file_get_contents("$this->scheme://file2"));
	}

	public function testLinkReading(): void
	{
		file_put_contents("$this->scheme://file", 'data');
		$this->container->createLink('/link', '/file');

		self::assertSame('data', file_get_contents("$this->scheme://link"));
	}

	public function testLinkWriting(): void
	{
		file_put_contents("$this->scheme://file", 'ubots!');
		$this->container->createLink('/link', '/file');

		file_put_contents("$this->scheme://link", 'data');

		self::assertSame('data', file_get_contents("$this->scheme://link"));
	}

	public function testChmodViaLink(): void
	{
		$file = "$this->scheme://file";
		$link = "$this->scheme://link";
		touch($file);
		$this->container->createLink('/link', '/file');

		chmod($link, 0_000);

		self::assertFalse(is_readable($file));
		self::assertFalse(is_writable($file));
		self::assertFalse(is_executable($file));

		chmod($link, 0_700);

		self::assertTrue(is_readable($file));
		self::assertTrue(is_writable($file));
		self::assertTrue(is_executable($file));
	}

	public function testIsExecutableReturnsCorrectly(): void
	{
		touch("$this->scheme://file");

		chmod("$this->scheme://file", 0_000);

		self::assertFalse(is_executable("$this->scheme://file"));

		chmod("$this->scheme://file", 0_777);

		self::assertTrue(is_executable("$this->scheme://file"));
	}

	public function testExclusiveLock(): void
	{
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testSharedLock(): void
	{
		$file = "$this->scheme://file";
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
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testUnlockExclusiveLock(): void
	{
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testDowngradeExclusiveLock(): void
	{
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLock(): void
	{
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLockImpossible(): void
	{
		$file = "$this->scheme://file";
		touch($file);

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
		self::assertFalse(flock($fh1, LOCK_EX | LOCK_NB));
	}

	public function testFileSize(): void
	{
		file_put_contents("$this->scheme://file", '12345');

		self::assertSame(5, filesize("$this->scheme://file"));
	}

	public function testRmdirAfterUrlStatCall(): void
	{
		$path = "$this->scheme://dir";

		mkdir($path);

		self::assertFileExists($path);

		rmdir($path);

		self::assertFileDoesNotExist($path);
	}

	public function testUnlinkAfterUrlStatCall(): void
	{
		$path = "$this->scheme://file";

		touch($path);

		self::assertFileExists($path);

		unlink($path);

		self::assertFileDoesNotExist($path);
	}

	public function testFinfoSupport(): void
	{
		file_put_contents(
			"$this->scheme://file.gif",
			base64_decode('R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', true),
		);

		$finfo = new finfo(FILEINFO_MIME_TYPE);

		self::assertSame('image/gif', $finfo->file("$this->scheme://file.gif"));
	}

	public function testRequire(): void
	{
		file_put_contents("$this->scheme://file.php", '<?php return 1;');

		self::assertSame(1, require "$this->scheme://file.php");
	}

	public function testScanDir(): void
	{
		self::assertSame(
			[
				'.',
				'..',
			],
			scandir("$this->scheme://"),
		);
		self::assertSame(
			[
				'..',
				'.',
			],
			scandir("$this->scheme://", SCANDIR_SORT_DESCENDING),
		);
		self::assertSame(
			[
				'.',
				'..',
			],
			scandir("$this->scheme://", SCANDIR_SORT_NONE),
		);

		mkdir("$this->scheme://dir1");
		file_put_contents("$this->scheme://file.txt", 'content');
		mkdir("$this->scheme://dir2");

		self::assertSame(
			[
				'.',
				'..',
				'dir1',
				'dir2',
				'file.txt',
			],
			scandir("$this->scheme://"),
		);
		self::assertSame(
			[
				'file.txt',
				'dir2',
				'dir1',
				'..',
				'.',
			],
			scandir("$this->scheme://", SCANDIR_SORT_DESCENDING),
		);
		self::assertSame(
			[
				'.',
				'..',
				'dir1',
				'file.txt',
				'dir2',
			],
			scandir("$this->scheme://", SCANDIR_SORT_NONE),
		);
	}

	public function testIteratorCount(): void
	{
		mkdir("$this->scheme://dir1");
		file_put_contents("$this->scheme://file.txt", 'content');

		$fi = new FilesystemIterator(
			"$this->scheme://",
			FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO,
		);
		if (PHP_VERSION_ID < 8_02_00) {
			// SKIP_DOTS is always set and cannot be removed on PHP < 8.2
			self::assertSame(2, iterator_count($fi));
		} else {
			self::assertSame(4, iterator_count($fi));
		}

		$fi = new FilesystemIterator("$this->scheme://");
		self::assertSame(2, iterator_count($fi));
	}

}
