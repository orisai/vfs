# Virtual File System

Emulate file system via PHP variable

## Content

- [Setup](#setup)
- [Quickstart](#quickstart)
- [Protocol name](#protocol-name)

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
file_put_contents("$scheme://dir/file", 'content');
$content  = file_get_contents("$scheme://dir/file");

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
