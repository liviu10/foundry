<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

/**
 * Zip Class
 *
 * Creation, listing, and extraction of ZIP archives wrapping ZipArchive.
 *
 * Natively, ZipArchive::open() returns true OR an integer error code (which is truthy, so
 * "if ($zip->open(...))" passes even on error), while addFile()/close() return false without details.
 *
 * On extraction, all entries are validated before writing anything to disk:
 *   - unsafe paths ("../", absolute paths, drive letters) -> exception ("zip slip" protection);
 *   - symlinks (Unix archives) -> exception;
 *   - "zip bomb" protection: optional limits on the real extracted size ($maxBytes, counted while
 *     decompressing, never taken from the archive headers) and on the number of entries ($maxEntries);
 *     an entry that decompresses to more than it declares is always rejected.
 *
 * Usage:
 *   require_once 'Zip.php';
 *   use Foundry\Zip;
 *
 *   Zip::create(__DIR__.'/export.zip', [
 *       __DIR__.'/report.pdf',                       // in archive: report.pdf
 *       'attachments/contract.pdf' => $contractPath, // in archive: attachments/contract.pdf
 *       'documents' => __DIR__.'/storage/docs',      // directory, added recursively
 *   ]);
 *
 *   Zip::createFromStrings(__DIR__.'/data.zip', ['data.csv' => $csv, 'info.txt' => 'Automatically generated']);
 *
 *   $files = Zip::extract($uploadedZip, __DIR__.'/import', maxBytes: 100 * 1024 * 1024);
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
final class Zip
{
    /** @var array<int, string> Error messages for ZipArchive::open() error codes. */
    private const array OPEN_ERRORS = [
        ZipArchive::ER_EXISTS => 'file already exists',
        ZipArchive::ER_INCONS => 'archive is inconsistent (corrupted)',
        ZipArchive::ER_INVAL => 'invalid argument',
        ZipArchive::ER_MEMORY => 'memory allocation failure',
        ZipArchive::ER_NOENT => 'file does not exist',
        ZipArchive::ER_NOZIP => 'not a zip archive',
        ZipArchive::ER_OPEN => 'cannot open file',
        ZipArchive::ER_READ => 'read error',
        ZipArchive::ER_SEEK => 'seek error',
    ];

    /** @var int "Symlink" type from Unix external attributes (S_IFLNK), in bits 16-31. */
    private const int UNIX_SYMLINK = 0xA000;

    private function __construct() {}

    /**
     * Creates an archive from existing files and directories.
     *
     * @param  string  $zipPath  Archive path; the directory must exist.
     * @param  array<int|string, string>  $files  Integer key: archive entry name = file basename;
     *                                            string key: archive entry name (path).
     *                                            Directories are added recursively.
     * @param  bool  $overwrite  true: overwrites an existing archive.
     *
     * @throws InvalidArgumentException If the file list is empty, a file is missing, or a name is unsafe.
     * @throws RuntimeException If the archive already exists or cannot be written.
     */
    public static function create(string $zipPath, array $files, bool $overwrite = false): void
    {
        if ($files === []) {
            throw new InvalidArgumentException('Zip::create(): the list of files cannot be empty.');
        }

        $entries = [];
        foreach ($files as $key => $path) {
            $name = is_string($key) ? $key : basename($path);
            self::assertSafeName('create', $name);

            if (is_dir($path)) {
                foreach (self::directoryFiles($path) as $relative => $file) {
                    $entries[rtrim($name, '/').'/'.$relative] = $file;
                }
            } elseif (is_file($path)) {
                $entries[$name] = $path;
            } else {
                throw new InvalidArgumentException("Zip::create(): file '{$path}' does not exist.");
            }
        }

        $zip = self::open('create', $zipPath, $overwrite);

        foreach ($entries as $name => $path) {
            if (! $zip->addFile($path, $name)) {
                $zip->unchangeAll();
                throw new RuntimeException("Zip::create(): cannot add '{$path}' to '{$zipPath}': {$zip->getStatusString()}.");
            }
        }

        self::close('create', $zip, $zipPath);
    }

    /**
     * Creates an archive from in-memory string contents.
     *
     * @param  string  $zipPath  Archive path; the directory must exist.
     * @param  array<string, string>  $entries  Archive entry name => content.
     * @param  bool  $overwrite  true: overwrites an existing archive.
     *
     * @throws InvalidArgumentException If the entry list is empty or a name is unsafe.
     * @throws RuntimeException If the archive already exists or cannot be written.
     */
    public static function createFromStrings(string $zipPath, array $entries, bool $overwrite = false): void
    {
        if ($entries === []) {
            throw new InvalidArgumentException('Zip::createFromStrings(): the list of entries cannot be empty.');
        }

        foreach (array_keys($entries) as $name) {
            self::assertSafeName('createFromStrings', (string) $name);
        }

        $zip = self::open('createFromStrings', $zipPath, $overwrite);

        foreach ($entries as $name => $content) {
            if (! $zip->addFromString((string) $name, $content)) {
                throw new RuntimeException("Zip::createFromStrings(): cannot add '{$name}' to '{$zipPath}': {$zip->getStatusString()}.");
            }
        }

        self::close('createFromStrings', $zip, $zipPath);
    }

    /**
     * List of entries in the archive.
     *
     * @return list<array{name: string, size: int, compressedSize: int}>
     *
     * @throws RuntimeException If the archive does not exist or cannot be read.
     */
    public static function list(string $zipPath): array
    {
        $zip = self::openForReading('list', $zipPath);

        try {
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException("Zip::list(): cannot read entry #{$index} of '{$zipPath}'.");
                }

                $entries[] = ['name' => $stat['name'], 'size' => $stat['size'], 'compressedSize' => $stat['comp_size']];
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Extracts the archive into $directory after validating ALL entries.
     *
     * Sizes declared in the archive are controlled by whoever built it, so they are never trusted:
     * every entry is decompressed through a stream and the bytes actually produced are counted.
     * An entry that produces more data than it declares is rejected as tampered, and extraction
     * stops as soon as the real total passes $maxBytes. Files written by a failed call are removed.
     * Error messages identify entries by position (entry #3), never by name.
     *
     * @param  string  $zipPath  Archive path.
     * @param  string  $directory  Destination directory; must exist.
     * @param  int|null  $maxBytes  Maximum total uncompressed size; null = unlimited (only for trusted archives).
     * @param  int|null  $maxEntries  Maximum number of entries (files and directories); null = unlimited.
     * @return list<string> Names of extracted entries (files and directories), as stored in the archive.
     *
     * @throws UnexpectedValueException If the archive contains unsafe paths or symlinks, has entries larger than
     *                                  declared, or exceeds $maxBytes / $maxEntries.
     * @throws RuntimeException If the archive cannot be read or extraction fails.
     */
    public static function extract(string $zipPath, string $directory, ?int $maxBytes = null, ?int $maxEntries = null): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Zip::extract(): directory '{$directory}' does not exist.");
        }

        if ($maxBytes !== null && $maxBytes < 0) {
            throw new LogicException('Zip::extract(): $maxBytes cannot be negative.');
        }

        if ($maxEntries !== null && $maxEntries < 1) {
            throw new LogicException('Zip::extract(): $maxEntries must be at least 1.');
        }

        $zip = self::openForReading('extract', $zipPath);

        try {
            if ($maxEntries !== null && $zip->numFiles > $maxEntries) {
                throw new UnexpectedValueException("Zip::extract(): '{$zipPath}' has {$zip->numFiles} entries, more than the maximum of {$maxEntries}.");
            }

            $entries = self::validateEntries($zip, $zipPath, $maxBytes);

            return self::writeEntries($zip, $zipPath, rtrim($directory, '/\\'), $entries, $maxBytes);
        } finally {
            $zip->close();
        }
    }

    /**
     * First pass: names, symlinks and declared sizes, before anything is written.
     *
     * @return list<array{index: int, name: string, size: int}>
     *
     * @throws UnexpectedValueException If an entry is unsafe or the declared total exceeds $maxBytes.
     * @throws RuntimeException If an entry cannot be read.
     */
    private static function validateEntries(ZipArchive $zip, string $zipPath, ?int $maxBytes): array
    {
        $entries = [];
        $declared = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if ($stat === false) {
                throw new RuntimeException("Zip::extract(): cannot read entry #{$index} of '{$zipPath}'.");
            }

            if (! self::isSafeName($stat['name'])) {
                throw new UnexpectedValueException("Zip::extract(): unsafe entry name (entry #{$index}) in '{$zipPath}'.");
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes)
                && $opsys === ZipArchive::OPSYS_UNIX && is_int($attributes) && (($attributes >> 16) & 0xF000) === self::UNIX_SYMLINK) {
                throw new UnexpectedValueException("Zip::extract(): symbolic link entry (entry #{$index}) in '{$zipPath}' is not allowed.");
            }

            $declared += $stat['size'];
            if ($maxBytes !== null && $declared > $maxBytes) {
                throw new UnexpectedValueException("Zip::extract(): '{$zipPath}' exceeds the maximum extracted size of {$maxBytes} bytes.");
            }

            $entries[] = ['index' => $index, 'name' => $stat['name'], 'size' => $stat['size']];
        }

        return $entries;
    }

    /**
     * Second pass: decompresses each entry through a stream, counting the bytes actually produced.
     *
     * @param  list<array{index: int, name: string, size: int}>  $entries
     * @return list<string>
     *
     * @throws UnexpectedValueException If an entry is larger than declared or the real total exceeds $maxBytes.
     * @throws RuntimeException If a directory or file cannot be written or an entry cannot be read.
     */
    private static function writeEntries(ZipArchive $zip, string $zipPath, string $directory, array $entries, ?int $maxBytes): array
    {
        $written = [];
        $total = 0;

        try {
            foreach ($entries as ['index' => $index, 'name' => $name, 'size' => $size]) {
                $target = $directory.'/'.str_replace('\\', '/', $name);

                if (str_ends_with($target, '/')) {
                    self::makeDirectory($target, $zipPath, $index);

                    continue;
                }

                self::makeDirectory(dirname($target), $zipPath, $index);

                [$input, $warning] = self::capture(static fn (): mixed => $zip->getStream($name));
                if (! is_resource($input)) {
                    throw new RuntimeException("Zip::extract(): cannot read entry #{$index} of '{$zipPath}'".($warning !== null ? ": {$warning}" : '').'.');
                }

                [$output, $warning] = self::capture(static fn (): mixed => fopen($target, 'wb'));
                if (! is_resource($output)) {
                    fclose($input);
                    throw new RuntimeException("Zip::extract(): cannot write entry #{$index} to '{$directory}'".($warning !== null ? ": {$warning}" : '').'.');
                }
                $written[] = $target;

                try {
                    $produced = self::copyEntry($input, $output, $size, $maxBytes === null ? null : $maxBytes - $total, $zipPath, $index);
                } finally {
                    fclose($input);
                    fclose($output);
                }

                $total += $produced;
            }
        } catch (Throwable $e) {
            foreach ($written as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            throw $e;
        }

        return array_column($entries, 'name');
    }

    /**
     * Copies one decompressed entry, never producing more than declared nor more than the remaining budget.
     *
     * @param  resource  $input
     * @param  resource  $output
     *
     * @throws UnexpectedValueException If the entry is larger than declared or the budget is exceeded.
     * @throws RuntimeException If reading or writing fails.
     */
    private static function copyEntry($input, $output, int $declared, ?int $budget, string $zipPath, int $index): int
    {
        $produced = 0;

        while (! feof($input)) {
            [$chunk, $warning] = self::capture(static fn (): string|false => fread($input, 65536));
            if ($chunk === false || $warning !== null) {
                throw new RuntimeException("Zip::extract(): cannot decompress entry #{$index} of '{$zipPath}'".($warning !== null ? ": {$warning}" : '').'.');
            }

            $produced += strlen($chunk);
            if ($produced > $declared) {
                throw new UnexpectedValueException("Zip::extract(): entry #{$index} of '{$zipPath}' is larger than its declared size (corrupted or tampered archive).");
            }
            if ($budget !== null && $produced > $budget) {
                throw new UnexpectedValueException("Zip::extract(): '{$zipPath}' exceeds the maximum extracted size.");
            }

            if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException("Zip::extract(): cannot write entry #{$index} of '{$zipPath}' (disk full?).");
            }
        }

        return $produced;
    }

    /**
     * @throws RuntimeException If the directory cannot be created.
     */
    private static function makeDirectory(string $path, string $zipPath, int $index): void
    {
        $path = rtrim($path, '/');
        if ($path === '' || is_dir($path)) {
            return;
        }

        [$created, $warning] = self::capture(static fn (): bool => mkdir($path, 0775, true));
        if (! $created && ! is_dir($path)) {
            throw new RuntimeException("Zip::extract(): cannot create the directory for entry #{$index} of '{$zipPath}'".($warning !== null ? ": {$warning}" : '').'.');
        }
    }

    /**
     * Opens (creates) an archive for writing.
     *
     * @throws RuntimeException If the archive already exists (without $overwrite) or cannot be opened.
     */
    private static function open(string $method, string $zipPath, bool $overwrite): ZipArchive
    {
        self::assertZipAvailable($method);

        if (! is_dir(dirname($zipPath))) {
            throw new RuntimeException("Zip::{$method}(): directory '".dirname($zipPath)."' does not exist.");
        }

        if (! $overwrite && file_exists($zipPath)) {
            throw new RuntimeException("Zip::{$method}(): '{$zipPath}' already exists.");
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new RuntimeException("Zip::{$method}(): cannot create '{$zipPath}': ".self::openError($result).'.');
        }

        return $zip;
    }

    /**
     * @throws RuntimeException If the archive does not exist or is not a valid ZIP archive.
     */
    private static function openForReading(string $method, string $zipPath): ZipArchive
    {
        self::assertZipAvailable($method);

        if (! is_file($zipPath)) {
            throw new RuntimeException("Zip::{$method}(): file '{$zipPath}' does not exist.");
        }

        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        if ($result !== true) {
            throw new RuntimeException("Zip::{$method}(): cannot open '{$zipPath}': ".self::openError($result).'.');
        }

        return $zip;
    }

    /**
     * Writes the archive to disk. Files added via addFile() are physically read here.
     *
     * @throws RuntimeException If writing fails.
     */
    private static function close(string $method, ZipArchive $zip, string $zipPath): void
    {
        [$closed, $warning] = self::capture(static fn (): bool => $zip->close());

        if (! $closed) {
            throw new RuntimeException("Zip::{$method}(): cannot write '{$zipPath}': ".($warning ?? $zip->getStatusString()).'.');
        }
    }

    private static function openError(int|bool $code): string
    {
        return is_int($code) ? (self::OPEN_ERRORS[$code] ?? "error code {$code}") : 'unknown error';
    }

    /**
     * @throws LogicException If the PHP zip extension is not enabled.
     */
    private static function assertZipAvailable(string $method): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new LogicException("Zip::{$method}(): the PHP zip extension is not enabled.");
        }
    }

    /**
     * @throws InvalidArgumentException If the entry name is unsafe.
     */
    private static function assertSafeName(string $method, string $name): void
    {
        if (! self::isSafeName($name)) {
            throw new InvalidArgumentException("Zip::{$method}(): unsafe entry name '{$name}'.");
        }
    }

    /**
     * Relative name, without "..", without absolute path, without drive letter, without control characters.
     */
    private static function isSafeName(string $name): bool
    {
        $normalized = str_replace('\\', '/', $name);

        if ($normalized === '' || str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1
            || preg_match('/[\x00-\x1F]/', $normalized) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $normalized), true);
    }

    /**
     * Files in a directory, recursive: relative path (using "/") => full path.
     *
     * @return array<string, string>
     */
    private static function directoryFiles(string $directory): array
    {
        $files = [];
        $base = strlen(rtrim($directory, '/\\')) + 1;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), $base))] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Runs a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  Operation to run.
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
}
