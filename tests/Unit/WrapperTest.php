<?php declare(strict_types = 1);

namespace Tests\Orisai\VFS\Unit;

use DirectoryIterator;
use finfo;
use Orisai\VFS\Container;
use Orisai\VFS\Factory;
use Orisai\VFS\FileSystem;
use Orisai\VFS\StreamWrapper;
use Orisai\VFS\Structure\Directory;
use Orisai\VFS\Structure\File;
use Orisai\VFS\Structure\Link;
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
use function stream_context_set_default;
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

final class WrapperTest extends TestCase
{

	private int $uid;

	private int $gid;

	public function setUp(): void
	{
		parent::setUp();
		$this->uid = function_exists('posix_getuid') ? posix_getuid() : PermissionHelper::ROOT_ID;
		$this->gid = function_exists('posix_getgid') ? posix_getgid() : PermissionHelper::ROOT_ID;

		$na = [];
		@$na['n/a']; //putting error in known state
	}

	public function testSchemeStripping(): void
	{
		$c = new StreamWrapper();

		self::assertEquals('/1/2/3/4', $c->stripScheme('test://1/2/3/4'));
		self::assertEquals('/', $c->stripScheme('test://'));
		self::assertEquals('/', $c->stripScheme('test:///'));
		self::assertEquals('/dir', $c->stripScheme('test:///dir'));

	}

	public function testContainerIsReturnedFromContext(): void
	{
		$container = new Container(new Factory());
		stream_context_set_default(['contextContainerTest' => ['Container' => $container]]);

		$c = new StreamWrapper();

		self::assertEquals($container, $c->getContainerFromContext('contextContainerTest://file'));
		self::assertEquals($container, $c->getContainerFromContext('contextContainerTest://'));
		self::assertEquals($container, $c->getContainerFromContext('contextContainerTest:///file'));

	}

	public function testFileExists(): void
	{
		$fs = new FileSystem();
		$fs->getRoot()->addDirectory($d = new Directory('dir'));
		$d->addFile(new File('file'));
		$d->addDirectory(new Directory('dir'));

		self::assertFileExists($fs->getPathWithScheme('/dir/file'));
		self::assertFileExists($fs->getPathWithScheme('/dir'));
		self::assertFileDoesNotExist($fs->getPathWithScheme('/dir/fileNotExist'));

	}

	public function testIsDir(): void
	{
		$fs = new FileSystem();
		$fs->getRoot()->addDirectory($d = new Directory('dir'));
		$d->addFile(new File('file'));
		$d->addDirectory(new Directory('dir'));

		self::assertDirectoryDoesNotExist($fs->getPathWithScheme('/dir/file'));
		self::assertDirectoryExists($fs->getPathWithScheme('/dir'));
		self::assertDirectoryExists($fs->getPathWithScheme('/dir/dir'));
		self::assertDirectoryExists($fs->getPathWithScheme('/'));

	}

	public function testIsLink(): void
	{
		$fs = new FileSystem();
		$fs->getRoot()->addDirectory($d = new Directory('dir'));
		$d->addLink(new Link('link', $d));

		self::assertTrue(is_link($fs->getPathWithScheme('/dir/link')));
	}

	public function testIsFile(): void
	{
		$fs = new FileSystem();
		$fs->getRoot()->addDirectory($d = new Directory('dir'));
		$d->addFile(new File('file'));
		$fs->getRoot()->addFile(new File('file2'));
		$d->addDirectory(new Directory('dir'));

		self::assertTrue(is_file($fs->getPathWithScheme('/dir/file')));
		self::assertFalse(is_file($fs->getPathWithScheme('/dir')));
		self::assertFalse(is_file($fs->getPathWithScheme('/dir/dir')));
		self::assertFalse(is_file($fs->getPathWithScheme('/')));
		self::assertTrue(is_file($fs->getPathWithScheme('/file2')));

	}

	public function testChmod(): void
	{
		$fs = new FileSystem();
		$path = $fs->getPathWithScheme('/');

		chmod($path, 0_777);
		self::assertEquals(0_777 | Directory::getStatType(), $fs->getRoot()->getMode());

		$fs->getRoot()->setMode(0_755);
		self::assertEquals(0_755 | Directory::getStatType(), fileperms($path));

		//accessing non existent file should return false
		self::assertFalse(chmod($fs->getPathWithScheme('/nonExistingFile'), 0_777));

	}

	public function testChownByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. ' .
				'Php unit shouldn\'t be run as root user. (Unless you are a windows user!)',
			);
		}

		$fs = new FileSystem();
		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		chown($fs->getPathWithScheme('/'), 'root');
		self::assertEquals('root', posix_getpwuid(fileowner($fs->getPathWithScheme('/')))['name']);
	}

	public function testChownById(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if user is already root. Php unit shouldn\'t be run as root user.',
			);
		}

		$fs = new FileSystem();
		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		chown($fs->getPathWithScheme('/'), 0);

		self::assertEquals(0, fileowner($fs->getPathWithScheme('/')));

	}

	public function testChgrpByName(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$fs = new FileSystem();
		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$group = posix_getgrgid(posix_getpwuid(0)['gid'])['name'];

		chgrp($fs->getPathWithScheme('/'), $group);

		self::assertEquals($group, posix_getgrgid(filegroup($fs->getPathWithScheme('/')))['name']);
	}

	public function testChgrpById(): void
	{
		if ($this->gid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$fs = new FileSystem();
		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		$group = posix_getpwuid(0)['gid'];

		chgrp($fs->getPathWithScheme('/'), $group);

		self::assertEquals($group, filegroup($fs->getPathWithScheme('/')));
	}

	public function testMkdir(): void
	{
		$fs = new FileSystem();

		mkdir($fs->getPathWithScheme('/dir'));

		self::assertFileExists($fs->getPathWithScheme('/dir'));
		self::assertDirectoryExists($fs->getPathWithScheme('/dir'));

		mkdir($fs->getPathWithScheme('/dir2'), 0_000, false);

		$dir = $fs->getContainer()->getNodeAt('/dir2');

		self::assertEquals(0_000 | Directory::getStatType(), $dir->getMode());

	}

	public function testMkdirCatchesClashes(): void
	{
		$fs = new FileSystem();

		mkdir($fs->getPathWithScheme('/dir'));
		@mkdir($fs->getPathWithScheme('/dir'));

		$error = error_get_last();

		self::assertEquals('dir already exists', $error['message']);
	}

	public function testMkdirRecursive(): void
	{
		$fs = new FileSystem();

		mkdir($fs->getPathWithScheme('/dir/dir2'), 0_777, true);

		self::assertFileExists($fs->getPathWithScheme('/dir/dir2'));
		self::assertDirectoryExists($fs->getPathWithScheme('/dir/dir2'));

		@mkdir($fs->getPathWithScheme('/dir/a/b'), 0_777, false);

		$error = error_get_last();

		self::assertStringMatchesFormat('mkdir: %s: No such file or directory', $error['message']);

	}

	public function testStreamWriting(): void
	{
		$fs = new FileSystem();

		file_put_contents($fs->getPathWithScheme('/file'), 'data');

		self::assertEquals('data', $fs->getContainer()->getFileAt('/file')->getData());

		//long strings
		file_put_contents($fs->getPathWithScheme('/file2'), str_repeat('data ', 5_000));

		self::assertEquals(str_repeat('data ', 5_000), $fs->getContainer()->getFileAt('/file2')->getData());

		//truncating
		file_put_contents($fs->getPathWithScheme('/file'), 'data2');

		self::assertEquals('data2', $fs->getContainer()->getFileAt('/file')->getData());

		//appending
		file_put_contents($fs->getPathWithScheme('/file'), 'data3', FILE_APPEND);

		self::assertEquals('data2data3', $fs->getContainer()->getFileAt('/file')->getData());

		$handle = fopen($fs->getPathWithScheme('/file2'), 'w');

		fwrite($handle, 'data');
		self::assertEquals('data', $fs->getContainer()->getFileAt('/file2')->getData());

		fwrite($handle, '2');
		self::assertEquals('data2', $fs->getContainer()->getFileAt('/file2')->getData(), 'Pointer advanced');

		fwrite($handle, 'data', 1);
		self::assertEquals(
			'data2d',
			$fs->getContainer()->getFileAt('/file2')->getData(),
			'Written with limited length',
		);

	}

	public function testStreamReading(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file', 'test data');

		self::assertEquals('test data', file_get_contents($fs->getPathWithScheme('/file')));

		//long string
		$fs->getContainer()->createFile('/file2', str_repeat('test data', 5_000));
		self::assertEquals(str_repeat('test data', 5_000), file_get_contents($fs->getPathWithScheme('/file2')));

		$fs->getContainer()->createDir('/dir');

		self::assertEmpty(file_get_contents($fs->getPathWithScheme('/dir')));

	}

	public function testStreamFlushing(): void
	{
		$fs = new FileSystem();

		$handle = fopen($fs->getPathWithScheme('/file'), 'w');

		self::assertTrue(fflush($handle));
	}

	public function testOpeningForReadingOnNonExistingFails(): void
	{
		$fs = new FileSystem();

		self::assertFalse(@fopen($fs->getPathWithScheme('/nonExistingFile'), 'r'));

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
		$fs = new FileSystem();

		$handle = fopen($fs->getPathWithScheme('/nonExistingFile'), 'w');

		self::assertIsResource($handle);

		$file = $fs->getContainer()->createFile('/file', 'data');

		$handle = fopen($fs->getPathWithScheme('/file'), 'w');

		self::assertIsResource($handle);
		self::assertEmpty($file->getData());
	}

	public function testOpeningForAppendingDoesNotTruncateFile(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createFile('/file', 'data');

		$handle = fopen($fs->getPathWithScheme('/file'), 'a');

		self::assertIsResource($handle);
		self::assertEquals('data', $file->getData());

	}

	public function testCreatingFileWhileOpeningFailsCorrectly(): void
	{
		$fs = new FileSystem();

		self::assertFalse(@fopen($fs->getPathWithScheme('/dir/file'), 'w'));

		$error = error_get_last();

		if (PHP_VERSION_ID >= 8_00_00) {
			self::assertStringMatchesFormat('fopen(%s://dir/file): Failed to open stream: %s', $error['message']);
		} else {
			self::assertStringMatchesFormat('fopen(%s://dir/file): failed to open stream: %s', $error['message']);
		}
	}

	public function testFileGetContentsOffsetsAndLimitsCorrectly(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file', '--data--');

		self::assertEquals('data', file_get_contents($fs->getPathWithScheme('/file'), false, null, 2, 4));

	}

	public function testFileSeeking(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file', 'data');

		$handle = fopen($fs->getPathWithScheme('/file'), 'r');

		fseek($handle, 2);
		self::assertEquals(2, ftell($handle));

		fseek($handle, 1, SEEK_CUR);
		self::assertEquals(3, ftell($handle));

		fseek($handle, 6, SEEK_END);
		self::assertEquals(10, ftell($handle), 'End of file + 6 is 10');
	}

	public function testFileTruncating(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createFile('/file', 'data--');

		//has to opened for append otherwise file is automatically truncated by 'w' opening mode
		$handle = fopen($fs->getPathWithScheme('/file'), 'a');

		ftruncate($handle, 4);

		self::assertEquals('data', $file->getData());

	}

	public function testOpeningModesAreHandledCorrectly(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createFile('/file', 'data');

		$handle = fopen($fs->getPathWithScheme('/file'), 'r');
		self::assertEquals('data', fread($handle, 4), 'Contents can be read in read mode');
		self::assertEquals(0, fwrite($handle, '--'), '0 bytes should be written in readonly mode');

		$handle = fopen($fs->getPathWithScheme('/file'), 'r+');
		self::assertEquals('data', fread($handle, 4), 'Contents can be read in extended read mode');
		self::assertEquals(2, fwrite($handle, '--'), '2 bytes should be written in extended readonly mode');

		$handle = fopen($fs->getPathWithScheme('/file'), 'w');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in writeonly mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in write only mode');

		$handle = fopen($fs->getPathWithScheme('/file'), 'w+');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended writeonly mode');
		fseek($handle, 0);
		self::assertEquals('data', fread($handle, 4), 'Bytes read in extended write only mode');

		$file->setData('data');

		$handle = fopen($fs->getPathWithScheme('/file'), 'a');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in append mode');
		fseek($handle, 0);
		self::assertEmpty(fread($handle, 4), 'No bytes read in append mode');

		$handle = fopen($fs->getPathWithScheme('/file'), 'a+');
		self::assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended append mode');
		fseek($handle, 0);
		self::assertEquals('datadata', fread($handle, 8), 'Bytes read in extended append mode');

	}

	public function testFileTimesAreModifiedCorrectly(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createFile('/file', 'data');

		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertNotEquals(0, $stat['atime']);
		self::assertNotEquals(0, $stat['mtime']);
		self::assertNotEquals(0, $stat['ctime']);

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_get_contents($fs->getPathWithScheme('/file'));
		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertNotEquals(10, $stat['atime'], 'Access time has changed after read');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after read');
		self::assertEquals(10, $stat['ctime'], 'inode change time has not changed after read');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		file_put_contents($fs->getPathWithScheme('/file'), 'data');
		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after write');
		self::assertNotEquals(10, $stat['mtime'], 'Modification time has changed after write');
		self::assertNotEquals(10, $stat['ctime'], 'inode change time has changed after write');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		chmod($fs->getPathWithScheme('/file'), 0_777);
		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after inode change');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after inode change');
		self::assertNotEquals(10, $stat['ctime'], 'inode change time has changed after inode change');

		$file->setAccessTime(10);
		$file->setModificationTime(10);
		$file->setChangeTime(10);

		clearstatcache();

		fopen($fs->getPathWithScheme('/file'), 'r');
		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertEquals(10, $stat['atime'], 'Access time has not changed after opening for reading');
		self::assertEquals(10, $stat['mtime'], 'Modification time has not changed after opening for reading');
		self::assertEquals(10, $stat['ctime'], 'inode change time has not changed after opening for reading');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		fopen($fs->getPathWithScheme('/file'), 'w');
		$stat = stat($fs->getPathWithScheme('/file'));

		self::assertEquals(20, $stat['atime'], 'Access time has not changed after opening for writing');
		self::assertNotEquals(20, $stat['mtime'], 'Modification time has changed after opnening for writing');
		self::assertNotEquals(20, $stat['ctime'], 'inode change time has changed after opnening for writing');

	}

	public function testTouchFileCreation(): void
	{
		$fs = new FileSystem();

		touch($fs->getPathWithScheme('/file2'));

		self::assertFileExists($fs->getPathWithScheme('/file2'));

		@touch($fs->getPathWithScheme('/dir/file'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'touch: %s: No such file or directory.',
			$error['message'],
			'Fails when no parent',
		);

		$file = $fs->getContainer()->getNodeAt('/file2');

		$file->setAccessTime(20);
		$file->setModificationTime(20);
		$file->setChangeTime(20);

		touch($fs->getPathWithScheme('/file2'));
		$stat = stat($fs->getPathWithScheme('/file2'));

		self::assertNotEquals(20, $stat['atime'], 'Access time has changed after touch');
		self::assertNotEquals(20, $stat['mtime'], 'Modification time has changed after touch');
		self::assertNotEquals(20, $stat['ctime'], 'inode change time has changed after touch');

	}

	public function testTouchUpdatesTimes(): void
	{
		$fs = new FileSystem();
		$path = $fs->getPathWithScheme('/file');

		$time = 1_500_020_720;
		$atime = 1_500_204_791;

		touch($path, $time, $atime);

		self::assertEquals($time, filectime($path));
		self::assertEquals($time, filemtime($path));
		self::assertEquals($atime, fileatime($path));
	}

	public function testRenamesMovesFileCorrectly(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file', 'data');

		rename($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/file2'));

		self::assertTrue($fs->getContainer()->hasNodeAt('/file2'));
		self::assertFalse($fs->getContainer()->hasNodeAt('/file'));
		self::assertEquals('data', $fs->getContainer()->getFileAt('/file2')->getData());
	}

	public function testRenameReturnsCorrectWarnings(): void
	{
		$fs = new FileSystem();

		@rename($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/dir/file2'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: No such file or directory',
			$error['message'],
			'Triggers when moving non existing file',
		);

		$fs->getContainer()->createFile('/file');

		@rename($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/dir/file2'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: No such file or directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);

		$fs->getContainer()->createDir('/dir');

		@rename($fs->getPathWithScheme('/dir'), $fs->getPathWithScheme('/file'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mv: rename %s to %s: Not a directory',
			$error['message'],
			'Triggers when moving to non existing directory',
		);

	}

	public function testRenameFailsCorrectly(): void
	{
		$fs = new FileSystem();

		self::assertFalse(@rename($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/dir/file2')));

		$fs->getContainer()->createFile('/file');

		self::assertFalse(@rename($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/dir/file2')));

		$fs->getContainer()->createDir('/dir');

		self::assertFalse(@rename($fs->getPathWithScheme('/dir'), $fs->getPathWithScheme('/file')));
	}

	public function testUnlinkRemovesFile(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file');

		unlink($fs->getPathWithScheme('/file'));

		self::assertFalse($fs->getContainer()->hasNodeAt('/file'));
	}

	public function testUnlinkThrowsWarnings(): void
	{
		$fs = new FileSystem();

		@unlink($fs->getPathWithScheme('/file'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rm: %s: No such file or directory',
			$error['message'],
			'Warning when file does not exist',
		);

		$fs->getContainer()->createDir('/dir');

		@unlink($fs->getPathWithScheme('/dir'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rm: %s: is a directory',
			$error['message'],
			'Warning when trying to remove directory',
		);

	}

	public function testRmdirRemovesDirectories(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir');

		rmdir($fs->getPathWithScheme('/dir'));

		self::assertFalse($fs->getContainer()->hasNodeAt('/dir'), 'Directory has been removed');
	}

	public function testRmdirErrorsWithNonEmptyDirectories(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir/dir', true);

		@rmdir($fs->getPathWithScheme('/dir'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): Directory not empty',
			$error['message'],
			'Warning triggered when removing non empty directory',
		);
	}

	public function testRmdirErrorsWhenRemovingNonExistingDirectory(): void
	{
		$fs = new FileSystem();

		@rmdir($fs->getPathWithScheme('/dir'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): No such file or directory',
			$error['message'],
			'Warning triggered when removing non existing directory',
		);
	}

	public function testRmdirErrorsWhenRemovingFile(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file');

		@rmdir($fs->getPathWithScheme('/file'));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'Warning: rmdir(%s): Not a directory',
			$error['message'],
			'Warning triggered when trying to remove a file',
		);
	}

	public function testStreamOpenWarnsWhenFlagPassed(): void
	{
		$fs = new FileSystem();
		$opened_path = null;

		$wrapper = new StreamWrapper();

		self::assertFalse(
			$wrapper->stream_open($fs->getPathWithScheme('/file'), 'r', 0, $opened_path),
			'No warning when no flag',
		);

		@$wrapper->stream_open($fs->getPathWithScheme('/file'), 'r', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream.',
			$error['message'],
			'Stream open errors when flag passed',
		);

	}

	public function testDirectoryOpensForReading(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir');

		$wrapper = new StreamWrapper();

		$handle = $wrapper->dir_opendir($fs->getPathWithScheme('/dir'), STREAM_BUFFER_NONE);

		self::assertTrue($handle, 'Directory opened for reading');

		$handle = @$wrapper->dir_opendir($fs->getPathWithScheme('/nonExistingDir'), STREAM_BUFFER_NONE);

		self::assertFalse($handle, 'Non existing directory not opened for reading');

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'opendir(%s): failed to open dir: No such file or directory',
			$error['message'],
			'Opening non existing directory triggers warning',
		);

		$handle = opendir($fs->getPathWithScheme('/dir'));

		self::assertIsResource($handle, 'opendir uses dir_opendir');
	}

	public function testDirectoryOpenDoesNotOpenFiles(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createFile('/file');

		$wrapper = new StreamWrapper();

		$handle = @$wrapper->dir_opendir($fs->getPathWithScheme('/file'), STREAM_BUFFER_NONE);

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
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir');

		$wrapper = new StreamWrapper();

		self::assertFalse($wrapper->dir_closedir(), 'Returns false when no dir opened');

		$wrapper->dir_opendir($fs->getPathWithScheme('/dir'), STREAM_BUFFER_NONE);

		self::assertTrue($wrapper->dir_closedir());
	}

	public function testDirectoryReading(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir1');
		$fs->getContainer()->createDir('/dir2');
		$fs->getContainer()->createDir('/dir3');

		$wr = new StreamWrapper();
		$wr->dir_opendir($fs->getPathWithScheme('/'), STREAM_BUFFER_NONE);

		self::assertEquals('dir1', $wr->dir_readdir());
		self::assertEquals('dir2', $wr->dir_readdir());
		self::assertEquals('dir3', $wr->dir_readdir());
		self::assertFalse($wr->dir_readdir());

		$wr->dir_rewinddir();
		self::assertEquals('dir1', $wr->dir_readdir(), 'Directory rewound');

	}

	public function testDirectoryIterationWithDirectoryIterator(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir1');
		$fs->getContainer()->createDir('/dir2');
		$fs->getContainer()->createDir('/dir3');

		$result = [];

		foreach (new DirectoryIterator($fs->getPathWithScheme('/')) as $fileInfo) {
			$result[] = $fileInfo->getBasename();
		}

		self::assertEquals(['dir1', 'dir2', 'dir3'], $result, 'All directories found');

	}

	public function testStreamOpenDoesNotOpenDirectoriesForWriting(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir');

		self::assertFalse(@fopen($fs->getPathWithScheme('/dir'), 'w'));
		self::assertFalse(@fopen($fs->getPathWithScheme('/dir'), 'r+'));
		self::assertFalse(@fopen($fs->getPathWithScheme('/dir'), 'w+'));
		self::assertFalse(@fopen($fs->getPathWithScheme('/dir'), 'a'));
		self::assertFalse(@fopen($fs->getPathWithScheme('/dir'), 'a+'));

		$opened_path = null;

		$wr = new StreamWrapper();
		@$wr->stream_open($fs->getPathWithScheme('/dir'), 'w', STREAM_REPORT_ERRORS, $opened_path);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'fopen(%s): failed to open stream: Is a directory',
			$error['message'],
			'Stream does not open directories',
		);
	}

	public function testStreamOpenAllowsForDirectoryOpeningForReadingAndReturnsEmptyStrings(): void
	{
		$fs = new FileSystem();
		$fs->getContainer()->createDir('/dir');

		$handle = fopen($fs->getPathWithScheme('/dir'), 'r');

		self::assertIsResource($handle);

		self::assertEmpty(fread($handle, 1));
	}

	public function testPermissionsAreCheckedWhenOpeningFiles(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createFile('/file');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/file'), 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'r', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'r+', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'w', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'w+', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'a', 0, $openedPath));
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/file'), 'a+', 0, $openedPath));

	}

	public function testTemporaryFileCreatedToReadDirectoriesWithStreamOpenInheritsPermissions(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createDir('/dir');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a+', 0, $openedPath));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a+', 0, $openedPath));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a+', 0, $openedPath));

		$file->setMode(0_600);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue($wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'r+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'w+', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a', 0, $openedPath));
		self::assertFalse($wr->stream_open($fs->getPathWithScheme('/dir'), 'a+', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenOpeningDirectories(): void
	{
		$fs = new FileSystem();
		$file = $fs->getContainer()->createDir('/dir');
		$openedPath = null;

		$wr = new StreamWrapper();

		$file->setMode(0_000);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse(@$wr->dir_opendir($fs->getPathWithScheme('/dir'), STREAM_BUFFER_NONE));

		$file->setMode(0_200);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertFalse(@$wr->dir_opendir($fs->getPathWithScheme('/dir'), STREAM_BUFFER_NONE));

		$file->setMode(0_400);
		$file->setUser($this->uid);
		$file->setGroup(PermissionHelper::ROOT_ID);
		self::assertTrue(@$wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));

		$file->setMode(0_040);
		$file->setUser(PermissionHelper::ROOT_ID);
		$file->setGroup($this->gid);
		self::assertTrue(@$wr->stream_open($fs->getPathWithScheme('/dir'), 'r', 0, $openedPath));
	}

	public function testPermissionsAreCheckedWhenCreatingFilesWithinDirectories(): void
	{
		$fs = new FileSystem();
		$dir = $fs->createDirectory('/dir');

		$dir->setMode(0_000);
		self::assertFalse(@file_put_contents($fs->getPathWithScheme('/dir/file'), 'data'));

		$dir->setMode(0_400);
		self::assertFalse(@file_put_contents($fs->getPathWithScheme('/dir/file'), 'data'));

		$dir->setMode(0_200);
		self::assertGreaterThan(0, @file_put_contents($fs->getPathWithScheme('/dir/file'), 'data'));
	}

	public function testStreamOpenReportsErrorsOnPermissionDenied(): void
	{
		$fs = new FileSystem();
		$dir = $fs->createDirectory('/dir');
		$file = $fs->createFile('/file');
		$dir->setMode(0_000);
		$na = [];
		$openedPath = null;

		$wr = new StreamWrapper();

		@$wr->stream_open($fs->getPathWithScheme('/dir/file'), 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state
		$file->setMode(0_000);
		@$wr->stream_open($fs->getPathWithScheme('/file'), 'r', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state
		$file->setMode(0_000);
		@$wr->stream_open($fs->getPathWithScheme('/file'), 'w', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state
		$file->setMode(0_000);
		@$wr->stream_open($fs->getPathWithScheme('/file'), 'a', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state
		$file->setMode(0_000);
		@$wr->stream_open($fs->getPathWithScheme('/file'), 'w+', STREAM_REPORT_ERRORS, $openedPath);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'%s: failed to open stream: Permission denied',
			$error['message'],
		);

	}

	public function testPermissionsAreCheckedWhenCreatingDirectories(): void
	{
		$fs = new FileSystem();
		$fs->createDirectory('/test', false, 0_000);

		$wr = new StreamWrapper();

		self::assertFalse(@$wr->mkdir($fs->getPathWithScheme('/test/dir'), 0_777, 0));

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'mkdir: %s: Permission denied',
			$error['message'],
		);
	}

	public function testPermissionsAreCheckedWhenRemovingFiles(): void
	{
		$fs = new FileSystem();
		$file = $fs->createFile('/file');
		$file->setMode(0_000);

		$wr = new StreamWrapper();
		self::assertTrue($wr->unlink($fs->getPathWithScheme('/file')), 'Allows removals with writable parent');

		$fs->getRoot()->setMode(0_500);

		self::assertFalse(
			@$wr->unlink($fs->getPathWithScheme('/file')),
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
		$fs = new FileSystem();
		$dir = $fs->createDirectory('/dir');

		$wr = new StreamWrapper();

		$dir->setMode(0_000);
		@rmdir($fs->getPathWithScheme('/dir'));
		self::assertFalse(
			@$wr->rmdir($fs->getPathWithScheme('/dir'), STREAM_REPORT_ERRORS),
			'Directory not removed with no permissions',
		);

		$dir->setMode(0_100);
		self::assertFalse(
			@$wr->rmdir($fs->getPathWithScheme('/dir'), STREAM_REPORT_ERRORS),
			'Directory not removed with exec only',
		);

		$dir->setMode(0_200);
		self::assertFalse(
			@$wr->rmdir($fs->getPathWithScheme('/dir'), STREAM_REPORT_ERRORS),
			'Directory not removed with write',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'rmdir: %s: Permission denied',
			$error['message'],
		);

		$dir->setMode(0_400);
		self::assertTrue(
			$wr->rmdir($fs->getPathWithScheme('/dir'), STREAM_REPORT_ERRORS),
			'Directory removed with read permission, yes that is how it normally behaves ;)',
		);
	}

	public function testChmodNotAllowedIfNotOwner(): void
	{
		$fs = new FileSystem();
		$file = $fs->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_ACCESS, 0_000),
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
		$fs = new FileSystem();
		$file = $fs->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		$uid = $this->uid + 1;

		$wr = new StreamWrapper();

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme($fileName), STREAM_META_OWNER, $uid),
		);

		$file = $fs->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme($fileName), STREAM_META_OWNER_NAME, 'user'),
		);

		$file = $fs->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme($fileName), STREAM_META_GROUP, $uid),
		);

		$file = $fs->createFile($fileName = uniqid('/', true));
		$file->setUser($this->uid); //set to current

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme($fileName), STREAM_META_GROUP_NAME, 'userGroup'),
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

		$fs = new FileSystem();
		$file = $fs->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_OWNER, 1),
			'Not allowed to chown if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chown: %s: Permission denied',
			$error['message'],
		);

		$na = [];
		@$na['n/a']; //putting error in known state

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_OWNER_NAME, 'user'),
			'Not allowed to chown by name if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chown: %s: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_GROUP, 1),
			'Not allowed to chgrp if not root',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'chgrp: %s: Permission denied',
			$error['message'],
		);

		@$na['n/a']; //putting error in known state

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_GROUP_NAME, 'group'),
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
		$fs = new FileSystem();
		$file = $fs->createFile('/file');
		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_000);

		$wr = new StreamWrapper();

		self::assertFalse(
			@$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_TOUCH, 0),
			'Not allowed to touch if not owner and no permission',
		);

		$error = error_get_last();

		self::assertStringMatchesFormat(
			'touch: %s: Permission denied',
			$error['message'],
		);

		$file->setUser($this->uid);

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_TOUCH, 0),
			'Allowed to touch if owner and no permission',
		);

		$file->setUser($this->uid + 1); //set to non-current
		$file->setMode(0_002);

		self::assertTrue(
			$wr->stream_metadata($fs->getPathWithScheme('/file'), STREAM_META_TOUCH, 0),
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

		$fs = new FileSystem();
		$directory = $fs->createDirectory('/dir');
		$link = new Link('link', $directory);
		$directory->addLink($link);

		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		lchown($fs->getPathWithScheme('/dir/link'), 'root');
		self::assertEquals('root', posix_getpwuid(fileowner($fs->getPathWithScheme('/dir/link')))['name']);

	}

	public function testLchgrp(): void
	{
		if ($this->uid === 0) {
			self::markTestSkipped(
				'No point testing if group is already root. ' .
				'Php unit shouldn\'t be run as root group. (Unless you are on Windows - then we skip)',
			);
		}

		$fs = new FileSystem();
		$directory = $fs->createDirectory('/dir');
		$link = new Link('link', $directory);
		$directory->addLink($link);

		$fs->getContainer()->setPermissionHelper(
			new PermissionHelper(PermissionHelper::ROOT_ID, PermissionHelper::ROOT_ID),
		);

		//lets workout available group
		//this is needed to find string name of group root belongs to
		$group = posix_getgrgid(posix_getpwuid(0)['gid'])['name'];

		chgrp($fs->getPathWithScheme('/dir/link'), $group);

		self::assertEquals($group, posix_getgrgid(filegroup($fs->getPathWithScheme('/dir/link')))['name']);
	}

	public function testFileCopy(): void
	{
		$fs = new FileSystem();
		$fs->createFile('/file', 'data');

		copy($fs->getPathWithScheme('/file'), $fs->getPathWithScheme('/file2'));

		self::assertFileExists($fs->getPathWithScheme('/file2'));

		self::assertEquals('data', $fs->getContainer()->getFileAt('/file2')->getData());

	}

	public function testLinkCopyCreatesHardCopyOfFile(): void
	{
		$fs = new FileSystem();
		$fs->createFile('/file', 'data');
		$fs->createLink('/link', '/file');

		copy($fs->getPathWithScheme('/link'), $fs->getPathWithScheme('/file2'));

		self::assertFileExists($fs->getPathWithScheme('/file2'));
		self::assertEquals('data', $fs->getContainer()->getFileAt('/file2')->getData());

	}

	public function testLinkReading(): void
	{
		$fs = new FileSystem();
		$fs->createFile('/file', 'data');
		$fs->createLink('/link', '/file');

		self::assertEquals('data', file_get_contents($fs->getPathWithScheme('/link')));
	}

	public function testLinkWriting(): void
	{
		$fs = new FileSystem();
		$fs->createFile('/file', 'ubots!');
		$fs->createLink('/link', '/file');

		file_put_contents($fs->getPathWithScheme('/link'), 'data');

		self::assertEquals('data', file_get_contents($fs->getPathWithScheme('/link')));

	}

	public function testChmodViaLink(): void
	{
		$fs = new FileSystem();
		$name = $fs->getPathWithScheme($fs->createFile('/file')->getPath());
		$link = $fs->getPathWithScheme($fs->createLink('/link', '/file')->getPath());

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
		$fs = new FileSystem();
		$fs->createFile('/file');

		chmod($fs->getPathWithScheme('/file'), 0_000);

		self::assertFalse(is_executable($fs->getPathWithScheme('/file')));

		chmod($fs->getPathWithScheme('/file'), 0_777);

		self::assertTrue(is_executable($fs->getPathWithScheme('/file')));
	}

	public function testExclusiveLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testSharedLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');
		$fh3 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
		self::assertFalse(flock($fh3, LOCK_EX | LOCK_NB));
	}

	public function testUnlockSharedLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testUnlockExclusiveLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_UN | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_EX | LOCK_NB));
	}

	public function testDowngradeExclusiveLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLock(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh1, LOCK_EX | LOCK_NB));
		self::assertFalse(flock($fh2, LOCK_SH | LOCK_NB));
	}

	public function testUpgradeSharedLockImpossible(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file')->getPath());

		$fh1 = fopen($file, 'c');
		$fh2 = fopen($file, 'c');

		self::assertTrue(flock($fh1, LOCK_SH | LOCK_NB));
		self::assertTrue(flock($fh2, LOCK_SH | LOCK_NB));
		self::assertFalse(flock($fh1, LOCK_EX | LOCK_NB));
	}

	public function testFileSize(): void
	{
		$fs = new FileSystem();
		$file = $fs->getPathWithScheme($fs->createFile('/file', '12345')->getPath());

		self::assertEquals(5, filesize($file));
	}

	public function testRmdirAfterUrlStatCall(): void
	{
		$fs = new FileSystem();

		$path = $fs->getPathWithScheme('dir');

		mkdir($path);

		self::assertFileExists($path);

		rmdir($path);

		self::assertFileDoesNotExist($path);
	}

	public function testUnlinkAfterUrlStatCall(): void
	{
		$fs = new FileSystem();

		$path = $fs->getPathWithScheme('file');

		touch($path);

		self::assertFileExists($path);

		unlink($path);

		self::assertFileDoesNotExist($path);
	}

	public function testFinfoSupport(): void
	{
		$fs = new FileSystem();

		$fs->createFile(
			'/file.gif',
			base64_decode('R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==', true),
		);

		$finfo = new finfo(FILEINFO_MIME_TYPE);

		self::assertEquals('image/gif', $finfo->file($fs->getPathWithScheme('/file.gif')));

	}

	public function testRequire(): void
	{
		$fs = new FileSystem();
		// phpcs:disable SlevomatCodingStandard.Functions.RequireSingleLineCall
		$fs->createFile(
			'/file.php',
			<<<'PHP'
<?php return 1;
PHP,
		);
		// phpcs:enable

		self::assertSame(1, require $fs->getPathWithScheme('/file.php'));
	}

}
