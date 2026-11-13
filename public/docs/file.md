# File

> Streaming reads, atomic writes, stream-based copying, safe directory creation (concurrency-safe), MIME detection from file bytes.

Canonical: https://foundry.insightdevhub.ro/docs/file/  
Namespace: `Foundry\File` · File: `File.php` · Version 1.1 · PHP 8.3+ · Extensions: fileinfo

File and directory operations. Native PHP functions return false and emit warnings; here any error throws a RuntimeException with a clear message (operation, path, cause).

## Usage

```php
require_once 'File.php';
use Foundry\File;

File::ensureDir(__DIR__.'/storage/exports');
File::write(__DIR__.'/storage/exports/report.txt', $content);   // atomic write
foreach (File::lines($path) as $number => $line) { ... }          // large files, line by line
$csvFiles = File::files(__DIR__.'/import', extension: 'csv');
File::copyStream($httpBody, __DIR__.'/storage/download.pdf');     // stream -> file, atomic
File::assertWritable(__DIR__.'/storage/logs');                    // exception with exact reason
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/File.php';

use Foundry\File;
```

## Methods

### read()

```php
public static function read(string $path): string
```

Reads the entire contents of a file.

Parameters:
- `$path` (`string`, required): The file path.

Returns `string`: The contents.

Throws:
- `RuntimeException`: If the file does not exist or cannot be read.

### lines()

```php
public static function lines(string $path): Generator
```

Reads a file line by line without loading it completely into memory. Line terminators ("\n", "\r\n") are stripped.

Parameters:
- `$path` (`string`, required): The file path.

Returns `Generator<int, string>`: The key is the 1-based line number.

Throws:
- `RuntimeException`: If the file does not exist or cannot be opened.

### write()

```php
public static function write(string $path, string $content): void
```

Writes content to a file atomically: writes to a temporary file in the same directory, then renames it. A reader sees either the old file or the complete new one. Permissions of an existing file are preserved.

Parameters:
- `$path` (`string`, required): The file path; the directory must exist.
- `$content` (`string`, required): The content to write.

Throws:
- `RuntimeException`: If the directory does not exist or writing fails.

### copyStream()

```php
public static function copyStream($source, string $path): int
```

Copies a stream (e.g. an HTTP response body, php://input, an open file) into a file atomically without buffering the entire stream in memory. The stream is read to EOF.

Parameters:
- `$source` (`resource`, required): The source stream, opened for reading.
- `$path` (`string`, required): The destination file; the directory must exist.

Returns `int`: The number of bytes written.

Throws:
- `InvalidArgumentException`: If $source is not a stream resource.
- `RuntimeException`: If reading or writing fails, or stops before EOF.

### writeAll()

```php
public static function writeAll($handle, string $data): void
```

Writes all data to an open stream. fwrite() may write fewer bytes than provided (sockets, pipes, disk full) without error; this method continues until completion or throws an exception.

Parameters:
- `$handle` (`resource`, required): Stream resource open for writing.
- `$data` (`string`, required): The data to write.

Throws:
- `InvalidArgumentException`: If $handle is not a stream resource.
- `RuntimeException`: If writing fails or makes no progress.

### append()

```php
public static function append(string $path, string $content): void
```

Appends content to the end of a file (creates it if it does not exist), using an exclusive lock.

Parameters:
- `$path` (`string`, required): The file path; the directory must exist.
- `$content` (`string`, required): The content to append.

Throws:
- `RuntimeException`: If the directory does not exist or writing fails.

### delete()

```php
public static function delete(string $path): void
```

Deletes a file. A missing file is considered an error.

Parameters:
- `$path` (`string`, required): The file path.

Throws:
- `RuntimeException`: If the file does not exist or cannot be deleted.

### deleteIfExists()

```php
public static function deleteIfExists(string $path): bool
```

Deletes a file if it exists.

Parameters:
- `$path` (`string`, required): The file path.

Returns `bool`: true if the file was deleted, false if it did not exist.

Throws:
- `RuntimeException`: If the file exists but cannot be deleted.

### copy()

```php
public static function copy(string $from, string $to): void
```

Copies a file. An existing destination file is overwritten.

Parameters:
- `$from` (`string`, required): The source file.
- `$to` (`string`, required): The destination file; the directory must exist.

Throws:
- `RuntimeException`: If the source file does not exist or copying fails.

### move()

```php
public static function move(string $from, string $to): void
```

Moves or renames a file. An existing destination file is overwritten. Across different partitions/drives PHP automatically performs a copy + delete; in that case the move is no longer atomic.

Parameters:
- `$from` (`string`, required): The source file.
- `$to` (`string`, required): The destination file; the directory must exist.

Throws:
- `RuntimeException`: If the source does not exist or moving fails.

### ensureDir()

```php
public static function ensureDir(string $dir, int $mode = 0775): void
```

Ensures that a directory exists (creates it recursively if missing). Safe under concurrent requests: if another process created it concurrently, no error is thrown.

Parameters:
- `$dir` (`string`, required): The directory path.
- `$mode` (`int`, default `509`): Permissions (Linux), affected by umask.

Throws:
- `RuntimeException`: If a file with the same name exists or creation fails.

### deleteDir()

```php
public static function deleteDir(string $dir): void
```

Recursively deletes a directory and all of its contents. Symlinks are deleted as links; target contents are untouched.

Parameters:
- `$dir` (`string`, required): The directory path.

Throws:
- `RuntimeException`: If the directory does not exist, is a filesystem root, or deletion fails.

### files()

```php
public static function files(string $dir, bool $recursive = false, ?string $extension = null): array
```

Lists files in a directory (excluding subdirectories by default), sorted alphabetically.

Parameters:
- `$dir` (`string`, required): The directory path.
- `$recursive` (`bool`, default `false`): true to include files in subdirectories.
- `$extension` (`string|null`, default `null`): Filter by extension without leading dot (e.g. 'csv'); case-insensitive.

Returns `list<string>`: Absolute paths of the files.

Throws:
- `RuntimeException`: If the directory does not exist.

### size()

```php
public static function size(string $path): int
```

File size in bytes.

Parameters:
- `$path` (`string`, required)

Returns `int`.

Throws:
- `RuntimeException`: If the file does not exist.

### mimeType()

```php
public static function mimeType(string $path): string
```

Real MIME type detected from file content (not from extension). E.g.: 'application/pdf'. Requires the PHP fileinfo extension.

Parameters:
- `$path` (`string`, required)

Returns `string`.

Throws:
- `LogicException`: If the fileinfo extension is not enabled.
- `RuntimeException`: If the file does not exist or the type cannot be detected.

### tempFile()

```php
public static function tempFile(string $prefix = 'tmp', ?string $dir = null): string
```

Creates an empty temporary file and returns its path. Cleanup is the caller's responsibility.

Parameters:
- `$prefix` (`string`, default `'tmp'`): Filename prefix (on Windows only the first 3 characters are used).
- `$dir` (`string|null`, default `null`): Directory; defaults to the system temporary directory.

Returns `string`.

Throws:
- `RuntimeException`: If the file cannot be created.

### glob()

```php
public static function glob(string $pattern, int $flags = 0): array
```

Returns sorted files and directories matching a glob pattern (e.g. '/var/log/app-*.log'). Natively, glob() returns false on error and [] when nothing is matched; here an error throws an exception.

Parameters:
- `$pattern` (`string`, required): The pattern (*, ?, [abc]).
- `$flags` (`int`, default `0`): GLOB_* flags (e.g. GLOB_ONLYDIR). GLOB_BRACE is not supported on all platforms.

Returns `list<string>`: Matching paths (empty list if no matches found).

Throws:
- `RuntimeException`: If the search fails.

### realpath()

```php
public static function realpath(string $path): string
```

Absolute, canonical path (resolving "..", ".", and symlinks) of an existing file or directory.

Parameters:
- `$path` (`string`, required)

Returns `string`.

Throws:
- `RuntimeException`: If the path does not exist.

### touch()

```php
public static function touch(string $path, ?int $time = null): void
```

Creates an empty file or updates the modification timestamp of an existing one.

Parameters:
- `$path` (`string`, required): The file path; the directory must exist.
- `$time` (`int|null`, default `null`): Modification timestamp; null = current time.

Throws:
- `RuntimeException`: If the directory does not exist or the operation fails.

### chmod()

```php
public static function chmod(string $path, int $mode): void
```

Changes permissions of a file or directory (e.g. 0644, 0755). On Windows, only affects the read-only attribute (0444 / 0666).

Parameters:
- `$path` (`string`, required)
- `$mode` (`int`, required)

Throws:
- `InvalidArgumentException`: If $mode is not between 0 and 07777.
- `RuntimeException`: If the file does not exist or the operation fails.

### assertWritable()

```php
public static function assertWritable(string $path): void
```

Verifies that $path is writable and throws an exception with the exact reason if not: - existing directory: must be writable; - existing file: must be writable; - non-existent path: parent directory must exist and be writable.

Parameters:
- `$path` (`string`, required)

Throws:
- `RuntimeException`: Explaining why the path is not writable.

### join()

```php
public static function join(string ...$parts): string
```

Joins path segments with '/', avoiding duplicate separators. E.g.: File::join('/var/www/', '/storage', 'logs/') => '/var/www/storage/logs'

Parameters:
- `$parts` (`string`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If no non-empty path segment is provided.

