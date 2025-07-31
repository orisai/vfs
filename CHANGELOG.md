# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased](https://github.com/orisai/vfs/compare/...v1.x)

These are the changes made since fork of [michael-donat/php-vfs](https://github.com/michael-donat/php-vfs),
[v1.4.2](https://github.com/michael-donat/php-vfs/releases/tag/v1.4.2).

### Added

- Support for iOS and Windows
	- tests pass on these systems, differences from Linux filesystem are not implemented
- `.` and `..` (dot links) support for `scandir()`
- Documentation of supported and unsupported functions

### Changed

- Root namespace is `Orisai\VFS`
- Package name is `orisai/vfs`
- Compatibility with PHP 7.4 - 8.4 (was 5.4 - 8.0)
- Many internals to fix deprecations and improve type safety

### Removed

- All public interfaces except stream wrapper registration / unregistration
