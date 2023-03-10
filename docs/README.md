# Virtual File System

Emulate file system via PHP variable

## Content

- [Setup](#setup)
- [Quickstart](#quickstart)
- [Protocol name](#protocol-name)
- [Use cases](#use-cases)
- [Supported functions](#supported-functions)
- [Known limitations](#known-limitations)

## Setup

Install with [Composer](https://getcomposer.org)

```sh
composer require orisai/vfs
```

## Quickstart

```php
use Orisai\VFS\VFS;

// Register VFS protocol
$scheme = VFS::register();

// Do anything you want with filesystem functions, like read and write
file_put_contents("$scheme://file", 'content');
$content = file_get_contents("$scheme://file");

// Unregister protocol, delete the virtual filesystem
VFS::unregister($scheme);
```

## Protocol name

`VFS::register()` creates new protocol with random scheme starting with `ori.var.`,
like `ori.var.635305d500f6b9.72259230`.

We may also assign static scheme:

```php
use Orisai\VFS\VFS;

$scheme = VFS::register('static.scheme');
```

## Use cases

What is VFS good for?

- Testing file system without writing to disk
- Working with libraries which require files without creating real files
- Evaluating PHP code without `eval()` (via write to VFS file and `require`)

## Supported functions

List of knowingly supported functions (see [known limitations](#known-limitations) for unsupported functions):

- `chgrp()`
- `chmod()`
- `chown()`
- `clearstatcache()`
- `copy()`
- `fflush()`
- `file_get_contents()`
- `file_put_contents()`
- `fileatime()`
- `filectime()`
- `filegroup()`
- `filemtime()`
- `fileowner()`
- `fileperms()`
- `filesize()`
- `flock()`
- `fopen()`
- `fread()`
- `fseek()`
- `ftell()`
- `ftruncate()`
- `fwrite()`
- `is_executable()`
- `is_file()`
- `is_link()`
- `is_readable()`
- `is_writable()`
- `lchown()`
- `mkdir()`
- `opendir()`
- `posix_getgrgid()`
- `posix_getpwuid()`
- `rename()`
- `rmdir()`
- `stat()`
- `touch()`
- `unlink()`

## Known limitations

- `umask()` - not implemented
- `scandir()` - current dir (`.`) and parent dir (`..`) are not listed
- `stream_set_blocking()`, `stream_set_timeout()`, `stream_set_write_buffer()` and `stream_set_option()` - not implemented
- Windows `fopen()` `t` mode (e.g. `w+t`) - not implemented

Cannot be implemented, because stream wrapper is not supported by PHP:

- `chdir()`
- `ini_set('error_log')`
- `glob()` ([here](https://wiki.php.net/rfc/glob_streamwrapper_support) is an RFC to support it)
- `realpath()`, `SplFileInfo::getRealPath()`
- `link()`, `symlink()`, `readlink()`, `linkinfo()`
- `tempnam()`
- `ext/zip`
