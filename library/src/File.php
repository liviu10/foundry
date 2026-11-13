<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use FilesystemIterator;
use finfo;
use Generator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * File Class
 *
 * File and directory operations.
 * Native PHP functions return false and emit warnings; here any error throws
 * a RuntimeException with a clear message (operation, path, cause).
 *
 * Usage:
 *   require_once 'File.php';
 *   use Foundry\File;
 *
 *   File::ensureDir(__DIR__.'/storage/exports');
 *   File::write(__DIR__.'/storage/exports/report.txt', $content);   // atomic write
 *   foreach (File::lines($path) as $number => $line) { ... }          // large files, line by line
 *   $csvFiles = File::files(__DIR__.'/import', extension: 'csv');
 *   File::copyStream($httpBody, __DIR__.'/storage/download.pdf');     // stream -> file, atomic
 *   File::assertWritable(__DIR__.'/storage/logs');                    // exception with exact reason
 *
 * @category Utilities
 *
 * @version  1.1
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class File
{
    private function __construct() {}

    /**
     * Reads the entire contents of a file.
     *
     * @param  string  $path  The file path.
     * @return string The contents.
     *
     * @throws RuntimeException If the file does not exist or cannot be read.
     */
    public static function read(string $path): string
    {
        self::assertFile($path, 'read');

        [$content, $warning] = self::capture(static fn (): string|false => file_get_contents($path));

        if ($content === false) {
            throw self::failure('read', $warning, $path);
        }

        return $content;
    }

    /**
     * Reads a file line by line without loading it completely into memory.
     * Line terminators ("\n", "\r\n") are stripped.
     *
     * @param  string  $path  The file path.
     * @return Generator<int, string> The key is the 1-based line number.
     *
     * @throws RuntimeException If the file does not exist or cannot be opened.
     */
    public static function lines(string $path): Generator
    {
        // Validarea si deschiderea se fac imediat, la apel, nu la prima iteratie.
        self::assertFile($path, 'lines');

        [$handle, $warning] = self::capture(static fn () => fopen($path, 'rb'));

        if ($handle === false) {
            throw self::failure('lines', $warning, $path);
        }

        return self::readLines($handle, $path);
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private static function readLines($handle, string $path): Generator
    {
        try {
            $number = 0;
            while (($line = fgets($handle)) !== false) {
                yield ++$number => rtrim($line, "\r\n");
            }

            if (! feof($handle)) {
                throw self::failure('lines', "read error after line {$number}", $path);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes content to a file atomically: writes to a temporary file in the same
     * directory, then renames it. A reader sees either the old file or the complete new one.
     * Permissions of an existing file are preserved.
     *
     * @param  string  $path  The file path; the directory must exist.
     * @param  string  $content  The content to write.
     *
     * @throws RuntimeException If the directory does not exist or writing fails.
     */
    public static function write(string $path, string $content): void
    {
        self::writeAtomically('write', $path, static function (string $temp) use ($content, $path): void {
            [$written, $warning] = self::capture(static fn (): int|false => file_put_contents($temp, $content));
            if ($written !== strlen($content)) {
                throw self::failure('write', $warning, $path);
            }
        });
    }

    /**
     * Copies a stream (e.g. an HTTP response body, php://input, an open file) into a file
     * atomically without buffering the entire stream in memory. The stream is read to EOF.
     *
     * @param  resource  $source  The source stream, opened for reading.
     * @param  string  $path  The destination file; the directory must exist.
     * @return int The number of bytes written.
     *
     * @throws InvalidArgumentException If $source is not a stream resource.
     * @throws RuntimeException If reading or writing fails, or stops before EOF.
     */
    public static function copyStream($source, string $path): int
    {
        if (! is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new InvalidArgumentException('File::copyStream(): $source must be an open stream resource.');
        }

        $bytes = 0;
        self::writeAtomically('copyStream', $path, static function (string $temp) use ($source, $path, &$bytes): void {
            [$handle, $warning] = self::capture(static fn () => fopen($temp, 'wb'));
            if ($handle === false) {
                throw self::failure('copyStream', $warning, $path);
            }

            try {
                [$copied, $warning] = self::capture(static fn (): int|false => stream_copy_to_stream($source, $handle));
            } finally {
                fclose($handle);
            }

            // stream_copy_to_stream() returneaza si o copie partiala daca sursa se intrerupe.
            if ($copied === false || ! feof($source)) {
                throw self::failure('copyStream', $warning ?? 'the source stream ended before EOF', $path);
            }

            $bytes = $copied;
        });

        return $bytes;
    }

    /**
     * Writes all data to an open stream. fwrite() may write fewer bytes than
     * provided (sockets, pipes, disk full) without error; this method continues until completion
     * or throws an exception.
     *
     * @param  resource  $handle  Stream resource open for writing.
     * @param  string  $data  The data to write.
     *
     * @throws InvalidArgumentException If $handle is not a stream resource.
     * @throws RuntimeException If writing fails or makes no progress.
     */
    public static function writeAll($handle, string $data): void
    {
        if (! is_resource($handle) || get_resource_type($handle) !== 'stream') {
            throw new InvalidArgumentException('File::writeAll(): $handle must be an open stream resource.');
        }

        $total = strlen($data);
        $offset = 0;

        while ($offset < $total) {
            $chunk = substr($data, $offset);
            [$written, $warning] = self::capture(static fn (): int|false => fwrite($handle, $chunk));

            if ($written === false || $written === 0) {
                $target = stream_get_meta_data($handle)['uri'] ?? 'stream';
                throw self::failure('writeAll', ($warning ?? 'no progress').", {$offset} of {$total} bytes written", $target);
            }

            $offset += $written;
        }
    }

    /**
     * Appends content to the end of a file (creates it if it does not exist), using an exclusive lock.
     *
     * @param  string  $path  The file path; the directory must exist.
     * @param  string  $content  The content to append.
     *
     * @throws RuntimeException If the directory does not exist or writing fails.
     */
    public static function append(string $path, string $content): void
    {
        self::assertPath($path, 'append');
        self::assertParentDir($path, 'append');

        [$written, $warning] = self::capture(static fn (): int|false => file_put_contents($path, $content, FILE_APPEND | LOCK_EX));

        if ($written !== strlen($content)) {
            throw self::failure('append', $warning, $path);
        }
    }

    /**
     * Deletes a file. A missing file is considered an error.
     *
     * @param  string  $path  The file path.
     *
     * @throws RuntimeException If the file does not exist or cannot be deleted.
     */
    public static function delete(string $path): void
    {
        self::assertFile($path, 'delete');

        [$deleted, $warning] = self::capture(static fn (): bool => unlink($path));

        if (! $deleted) {
            throw self::failure('delete', $warning, $path);
        }
    }

    /**
     * Deletes a file if it exists.
     *
     * @param  string  $path  The file path.
     * @return bool true if the file was deleted, false if it did not exist.
     *
     * @throws RuntimeException If the file exists but cannot be deleted.
     */
    public static function deleteIfExists(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        self::delete($path);

        return true;
    }

    /**
     * Copies a file. An existing destination file is overwritten.
     *
     * @param  string  $from  The source file.
     * @param  string  $to  The destination file; the directory must exist.
     *
     * @throws RuntimeException If the source file does not exist or copying fails.
     */
    public static function copy(string $from, string $to): void
    {
        self::assertFile($from, 'copy');
        self::assertParentDir($to, 'copy');

        [$copied, $warning] = self::capture(static fn (): bool => copy($from, $to));

        if (! $copied) {
            throw self::failure('copy', $warning, $from, $to);
        }
    }

    /**
     * Moves or renames a file. An existing destination file is overwritten.
     * Across different partitions/drives PHP automatically performs a copy + delete; in that case
     * the move is no longer atomic.
     *
     * @param  string  $from  The source file.
     * @param  string  $to  The destination file; the directory must exist.
     *
     * @throws RuntimeException If the source does not exist or moving fails.
     */
    public static function move(string $from, string $to): void
    {
        self::assertFile($from, 'move');
        self::assertParentDir($to, 'move');

        [$moved, $warning] = self::capture(static fn (): bool => rename($from, $to));

        if (! $moved) {
            throw self::failure('move', $warning, $from, $to);
        }
    }

    /**
     * Ensures that a directory exists (creates it recursively if missing).
     * Safe under concurrent requests: if another process created it concurrently, no error is thrown.
     *
     * @param  string  $dir  The directory path.
     * @param  int  $mode  Permissions (Linux), affected by umask.
     *
     * @throws RuntimeException If a file with the same name exists or creation fails.
     */
    public static function ensureDir(string $dir, int $mode = 0775): void
    {
        self::assertPath($dir, 'ensureDir');

        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw self::failure('ensureDir', 'path exists but is not a directory', $dir);
        }

        [$created, $warning] = self::capture(static fn (): bool => mkdir($dir, $mode, true));

        if (! $created && ! is_dir($dir)) {
            throw self::failure('ensureDir', $warning, $dir);
        }
    }

    /**
     * Recursively deletes a directory and all of its contents.
     * Symlinks are deleted as links; target contents are untouched.
     *
     * @param  string  $dir  The directory path.
     *
     * @throws RuntimeException If the directory does not exist, is a filesystem root, or deletion fails.
     */
    public static function deleteDir(string $dir): void
    {
        self::assertPath($dir, 'deleteDir');

        if (! is_dir($dir) || is_link($dir)) {
            throw self::failure('deleteDir', 'directory does not exist', $dir);
        }

        $real = (string) realpath($dir);
        if (dirname($real) === $real) {
            throw self::failure('deleteDir', 'refusing to delete a filesystem root', $dir);
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $item */
        foreach ($items as $item) {
            $path = $item->getPathname();
            $removeAsDir = $item->isDir() && (! $item->isLink() || PHP_OS_FAMILY === 'Windows');

            [$removed, $warning] = self::capture(static fn (): bool => $removeAsDir ? rmdir($path) : unlink($path));
            if (! $removed) {
                throw self::failure('deleteDir', $warning, $path);
            }
        }

        [$removed, $warning] = self::capture(static fn (): bool => rmdir($dir));
        if (! $removed) {
            throw self::failure('deleteDir', $warning, $dir);
        }
    }

    /**
     * Lists files in a directory (excluding subdirectories by default), sorted alphabetically.
     *
     * @param  string  $dir  The directory path.
     * @param  bool  $recursive  true to include files in subdirectories.
     * @param  string|null  $extension  Filter by extension without leading dot (e.g. 'csv'); case-insensitive.
     * @return list<string> Absolute paths of the files.
     *
     * @throws RuntimeException If the directory does not exist.
     */
    public static function files(string $dir, bool $recursive = false, ?string $extension = null): array
    {
        self::assertPath($dir, 'files');

        if (! is_dir($dir)) {
            throw self::failure('files', 'directory does not exist', $dir);
        }

        $iterator = $recursive
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS))
            : new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

        $extension = $extension === null ? null : strtolower(ltrim($extension, '.'));
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($extension !== null && strtolower($file->getExtension()) !== $extension) {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * File size in bytes.
     *
     * @throws RuntimeException If the file does not exist.
     */
    public static function size(string $path): int
    {
        self::assertFile($path, 'size');

        [$size, $warning] = self::capture(static fn (): int|false => filesize($path));

        if ($size === false) {
            throw self::failure('size', $warning, $path);
        }

        return $size;
    }

    /**
     * Real MIME type detected from file content (not from extension). E.g.: 'application/pdf'.
     * Requires the PHP fileinfo extension.
     *
     * @throws LogicException If the fileinfo extension is not enabled.
     * @throws RuntimeException If the file does not exist or the type cannot be detected.
     */
    public static function mimeType(string $path): string
    {
        if (! class_exists(finfo::class)) {
            throw new LogicException('File::mimeType(): the PHP fileinfo extension is not enabled.');
        }

        self::assertFile($path, 'mimeType');

        [$mime, $warning] = self::capture(static fn (): string|false => (new finfo(FILEINFO_MIME_TYPE))->file($path));

        if ($mime === false || $mime === '') {
            throw self::failure('mimeType', $warning, $path);
        }

        return $mime;
    }

    /**
     * Creates an empty temporary file and returns its path. Cleanup is the caller's responsibility.
     *
     * @param  string  $prefix  Filename prefix (on Windows only the first 3 characters are used).
     * @param  string|null  $dir  Directory; defaults to the system temporary directory.
     *
     * @throws RuntimeException If the file cannot be created.
     */
    public static function tempFile(string $prefix = 'tmp', ?string $dir = null): string
    {
        $dir ??= sys_get_temp_dir();

        if (! is_dir($dir)) {
            throw self::failure('tempFile', 'directory does not exist', $dir);
        }

        [$path, $warning] = self::capture(static fn (): string|false => tempnam($dir, $prefix));

        if ($path === false) {
            throw self::failure('tempFile', $warning, $dir);
        }

        return $path;
    }

    /**
     * Returns sorted files and directories matching a glob pattern (e.g. '/var/log/app-*.log').
     * Natively, glob() returns false on error and [] when nothing is matched; here an error throws an exception.
     *
     * @param  string  $pattern  The pattern (*, ?, [abc]).
     * @param  int  $flags  GLOB_* flags (e.g. GLOB_ONLYDIR). GLOB_BRACE is not supported on all platforms.
     * @return list<string> Matching paths (empty list if no matches found).
     *
     * @throws RuntimeException If the search fails.
     */
    public static function glob(string $pattern, int $flags = 0): array
    {
        self::assertPath($pattern, 'glob');

        [$paths, $warning] = self::capture(static fn (): array|false => glob($pattern, $flags | GLOB_ERR));

        if ($paths === false) {
            throw self::failure('glob', $warning ?? 'cannot read the directory', $pattern);
        }

        return $paths;
    }

    /**
     * Absolute, canonical path (resolving "..", ".", and symlinks) of an existing file or directory.
     *
     * @throws RuntimeException If the path does not exist.
     */
    public static function realpath(string $path): string
    {
        self::assertPath($path, 'realpath');

        $real = realpath($path);

        if ($real === false) {
            throw self::failure('realpath', 'path does not exist', $path);
        }

        return $real;
    }

    /**
     * Creates an empty file or updates the modification timestamp of an existing one.
     *
     * @param  string  $path  The file path; the directory must exist.
     * @param  int|null  $time  Modification timestamp; null = current time.
     *
     * @throws RuntimeException If the directory does not exist or the operation fails.
     */
    public static function touch(string $path, ?int $time = null): void
    {
        self::assertPath($path, 'touch');
        self::assertParentDir($path, 'touch');

        [$touched, $warning] = self::capture(static fn (): bool => touch($path, $time));

        if (! $touched) {
            throw self::failure('touch', $warning, $path);
        }
    }

    /**
     * Changes permissions of a file or directory (e.g. 0644, 0755).
     * On Windows, only affects the read-only attribute (0444 / 0666).
     *
     * @throws InvalidArgumentException If $mode is not between 0 and 07777.
     * @throws RuntimeException If the file does not exist or the operation fails.
     */
    public static function chmod(string $path, int $mode): void
    {
        self::assertPath($path, 'chmod');

        if ($mode < 0 || $mode > 07777) {
            throw new InvalidArgumentException(sprintf('File::chmod(): invalid mode %o (expected 0 - 7777 octal).', $mode));
        }

        if (! file_exists($path)) {
            throw self::failure('chmod', 'path does not exist', $path);
        }

        [$changed, $warning] = self::capture(static fn (): bool => chmod($path, $mode));

        if (! $changed) {
            throw self::failure('chmod', $warning, $path);
        }
    }

    /**
     * Verifies that $path is writable and throws an exception with the exact reason if not:
     *   - existing directory: must be writable;
     *   - existing file: must be writable;
     *   - non-existent path: parent directory must exist and be writable.
     *
     * @throws RuntimeException Explaining why the path is not writable.
     */
    public static function assertWritable(string $path): void
    {
        self::assertPath($path, 'assertWritable');
        clearstatcache(true, $path);

        if (file_exists($path)) {
            if (! is_writable($path)) {
                throw self::failure('assertWritable', (is_dir($path) ? 'directory' : 'file').' is not writable (check permissions or the read-only attribute)', $path);
            }

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw self::failure('assertWritable', "directory '{$directory}' does not exist", $path);
        }

        if (! is_writable($directory)) {
            throw self::failure('assertWritable', "directory '{$directory}' is not writable", $path);
        }
    }

    /**
     * Joins path segments with '/', avoiding duplicate separators.
     * E.g.: File::join('/var/www/', '/storage', 'logs/') => '/var/www/storage/logs'
     *
     * @throws InvalidArgumentException If no non-empty path segment is provided.
     */
    public static function join(string ...$parts): string
    {
        $segments = [];
        foreach ($parts as $index => $part) {
            $part = $index === 0 ? rtrim($part, '/\\') : trim($part, '/\\');
            if ($part !== '') {
                $segments[] = $part;
            }
        }

        if ($segments === []) {
            // Prima bucata poate fi chiar radacina: join('/') => '/'
            if (isset($parts[0]) && $parts[0] !== '' && trim($parts[0], '/\\') === '') {
                return '/';
            }
            throw new InvalidArgumentException('File::join(): at least one non-empty path segment is required.');
        }

        return implode('/', $segments);
    }

    /**
     * Atomic write: $fill writes the content to a temporary file in the same directory,
     * which then replaces $path via rename(). Permissions of an existing file are preserved.
     *
     * @param  Closure(string): void  $fill  Receives the temporary file path; throws on failure.
     *
     * @throws RuntimeException If writing or renaming fails.
     */
    private static function writeAtomically(string $operation, string $path, Closure $fill): void
    {
        self::assertPath($path, $operation);
        self::assertParentDir($path, $operation);

        if (is_dir($path)) {
            throw self::failure($operation, 'path is a directory', $path);
        }

        $directory = dirname($path);
        [$temp, $warning] = self::capture(static fn (): string|false => tempnam($directory, '.tmp-'));

        if ($temp === false) {
            throw self::failure($operation, $warning ?? "cannot create a temporary file in '{$directory}'", $path);
        }

        // tempnam() poate cadea pe directorul temporar al sistemului; atunci rename() nu mai e atomic.
        if (self::normalize((string) realpath(dirname($temp))) !== self::normalize((string) realpath($directory))) {
            self::capture(static fn (): bool => unlink($temp));
            throw self::failure($operation, "cannot create a temporary file in '{$directory}'", $path);
        }

        try {
            $fill($temp);

            $mode = is_file($path) ? (int) fileperms($path) & 0777 : 0666 & ~umask();
            self::capture(static fn (): bool => chmod($temp, $mode)); // pe Windows nu are efect

            [$renamed, $warning] = self::capture(static fn (): bool => rename($temp, $path));
            if (! $renamed) {
                throw self::failure($operation, $warning, $path);
            }
        } finally {
            if (is_file($temp)) {
                self::capture(static fn (): bool => unlink($temp));
            }
        }
    }

    /**
     * @throws InvalidArgumentException If the path is empty.
     */
    private static function assertPath(string $path, string $operation): void
    {
        if (trim($path) === '') {
            throw new InvalidArgumentException("File::{$operation}(): path cannot be empty.");
        }
    }

    /**
     * @throws RuntimeException If $path is not an existing file.
     */
    private static function assertFile(string $path, string $operation): void
    {
        self::assertPath($path, $operation);

        if (is_dir($path)) {
            throw self::failure($operation, 'path is a directory, not a file', $path);
        }

        if (! is_file($path)) {
            throw self::failure($operation, 'file does not exist', $path);
        }
    }

    /**
     * @throws RuntimeException If the parent directory of $path does not exist.
     */
    private static function assertParentDir(string $path, string $operation): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw self::failure($operation, "directory '{$directory}' does not exist", $path);
        }
    }

    /**
     * Constructs the standard exception: "File::write() failed for '/path': reason."
     * For copy/move: "File::copy() failed for '/a' -> '/b': reason."
     */
    private static function failure(string $operation, ?string $reason, string ...$paths): RuntimeException
    {
        return new RuntimeException(sprintf(
            "File::%s() failed for '%s': %s.",
            $operation,
            implode("' -> '", $paths),
            $reason ?? 'unknown error',
        ));
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     * Works consistently regardless of the host project's error handler.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Normalizes a path for comparison (separators, lowercase on Windows).
     */
    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
