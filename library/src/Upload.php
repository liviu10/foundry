<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use finfo;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * Upload Class
 *
 * Validation and storage of files uploaded through forms ($_FILES).
 *
 * Security rules:
 *   - file MIME type is detected from content (finfo), not from $_FILES['type'] (sent by client);
 *   - the saved file extension comes from the allowed types list, NOT from the client-provided name
 *     (a "invoice.php.jpg" or PHP script disguised as a PDF cannot reach disk with an executable extension);
 *   - the saved file name is randomly generated (or explicitly provided and validated); nothing is overwritten;
 *   - only files genuinely uploaded via HTTP POST are accepted (is_uploaded_file).
 *
 * Exceptions:
 *   - InvalidArgumentException: user file is rejected (missing, too large, disallowed type);
 *                               the message can be translated and displayed to the user;
 *   - RuntimeException:         server issue (missing temporary directory, write failure);
 *   - LogicException:           programming error (invalid $_FILES structure, invalid configuration).
 *
 * Usage:
 *   require_once 'Upload.php';
 *   use Foundry\Upload;
 *
 *   $stored = Upload::store($_FILES['document'], __DIR__.'/storage/uploads', [
 *       'application/pdf' => 'pdf',
 *       'image/jpeg' => 'jpg',
 *       'image/png' => 'png',
 *   ], maxSize: 5 * 1024 * 1024);
 *
 *   // $stored['path'], $stored['name'], $stored['originalName'], $stored['size'], $stored['mimeType']
 *
 *   foreach (Upload::files($_FILES['documents']) as $file) { ... }   // <input type="file" multiple>
 *
 * @category Utilities
 *
 * @version  1.0
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Upload
{
    /** @var array<int, string> Messages for UPLOAD_ERR_* error codes (other than OK). */
    private const array ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'the file exceeds the server upload limit (upload_max_filesize)',
        UPLOAD_ERR_FORM_SIZE => 'the file exceeds the form upload limit (MAX_FILE_SIZE)',
        UPLOAD_ERR_PARTIAL => 'the file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'no file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'the server has no temporary upload directory',
        UPLOAD_ERR_CANT_WRITE => 'the server could not write the uploaded file',
        UPLOAD_ERR_EXTENSION => 'a PHP extension stopped the upload',
    ];

    /** @var list<int> Errors caused by the server, not the user. */
    private const array SERVER_ERRORS = [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION];

    /** @var string Allowed pattern for the saved file name (without extension). */
    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,150}$/';

    private function __construct() {}

    /**
     * Validates the uploaded file and moves it to $directory.
     *
     * @param  array<mixed>  $file  An element from $_FILES (e.g. $_FILES['document']).
     * @param  string  $directory  Destination directory; must exist and be writable.
     * @param  array<string, string>  $allowed  Allowed types: MIME type => saved file extension.
     * @param  int  $maxSize  Maximum allowed size in bytes.
     * @param  string|null  $name  Saved file name without extension; null = randomly generated.
     * @return array{path: string, name: string, originalName: string, size: int, mimeType: string, extension: string}
     *
     * @throws InvalidArgumentException If the user's file is rejected.
     * @throws RuntimeException On server errors or if the target file already exists.
     * @throws LogicException On invalid parameters.
     */
    public static function store(array $file, string $directory, array $allowed, int $maxSize, ?string $name = null): array
    {
        if ($name !== null && preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new LogicException('Upload::store(): $name may contain only letters, digits, "_" and "-", starting with a letter or digit.');
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Upload::store(): directory '{$directory}' does not exist or is not writable.");
        }

        $validated = self::validate($file, $allowed, $maxSize);

        if (! is_uploaded_file($validated['tmpName'])) {
            throw new InvalidArgumentException('Upload::store(): the file was not uploaded via HTTP POST.');
        }

        $storedName = ($name ?? bin2hex(random_bytes(16))).'.'.$validated['extension'];
        $path = rtrim($directory, '/\\').'/'.$storedName;

        if (file_exists($path)) {
            throw new RuntimeException("Upload::store(): target file '{$path}' already exists.");
        }

        [$moved, $warning] = self::capture(static fn (): bool => move_uploaded_file($validated['tmpName'], $path));

        if (! $moved) {
            throw new RuntimeException("Upload::store(): cannot move the uploaded file to '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }

        return [
            'path' => $path,
            'name' => $storedName,
            'originalName' => $validated['originalName'],
            'size' => $validated['size'],
            'mimeType' => $validated['mimeType'],
            'extension' => $validated['extension'],
        ];
    }

    /**
     * Validates the uploaded file without moving it: error code, size, actual MIME type.
     * Does not check is_uploaded_file(); use store() to save files.
     *
     * @param  array<mixed>  $file  An element from $_FILES.
     * @param  array<string, string>  $allowed  Allowed types: MIME type => extension.
     * @param  int  $maxSize  Maximum allowed size in bytes.
     * @return array{tmpName: string, originalName: string, size: int, mimeType: string, extension: string}
     *
     * @throws InvalidArgumentException If the user's file is rejected.
     * @throws RuntimeException On server errors.
     * @throws LogicException On invalid parameters.
     */
    public static function validate(array $file, array $allowed, int $maxSize): array
    {
        if ($allowed === []) {
            throw new LogicException('Upload::validate(): the list of allowed MIME types cannot be empty.');
        }

        foreach ($allowed as $mime => $extension) {
            if (preg_match('/^[a-z0-9]{1,10}$/', $extension) !== 1) {
                throw new LogicException("Upload::validate(): invalid extension '{$extension}' for '{$mime}' (expected lowercase letters/digits, without dot).");
            }
        }

        if ($maxSize <= 0) {
            throw new LogicException('Upload::validate(): $maxSize must be greater than 0.');
        }

        if (! class_exists(finfo::class)) {
            throw new LogicException('Upload::validate(): the PHP fileinfo extension is not enabled.');
        }

        ['tmpName' => $tmpName, 'originalName' => $originalName, 'error' => $error] = self::fields($file);

        if ($error !== UPLOAD_ERR_OK) {
            $reason = self::ERRORS[$error] ?? "unknown upload error code {$error}";

            throw in_array($error, self::SERVER_ERRORS, true)
                ? new RuntimeException("Upload::validate(): {$reason}.")
                : new InvalidArgumentException("Upload::validate(): {$reason}.");
        }

        if (! is_file($tmpName)) {
            throw new RuntimeException('Upload::validate(): the temporary upload file does not exist.');
        }

        // Dimensiunea reala de pe disc; $_FILES['size'] vine din cerere.
        $size = (int) filesize($tmpName);

        if ($size === 0) {
            throw new InvalidArgumentException('Upload::validate(): the uploaded file is empty.');
        }

        if ($size > $maxSize) {
            throw new InvalidArgumentException(sprintf('Upload::validate(): the file is too large (%d bytes, maximum %d bytes).', $size, $maxSize));
        }

        [$mimeType] = self::capture(static fn (): string|false => (new finfo(FILEINFO_MIME_TYPE))->file($tmpName));

        if (! is_string($mimeType) || ! isset($allowed[$mimeType])) {
            throw new InvalidArgumentException(sprintf(
                'Upload::validate(): file type %s is not allowed (allowed: %s).',
                is_string($mimeType) ? "'{$mimeType}'" : 'unknown',
                implode(', ', array_keys($allowed)),
            ));
        }

        return [
            'tmpName' => $tmpName,
            'originalName' => $originalName,
            'size' => $size,
            'mimeType' => $mimeType,
            'extension' => $allowed[$mimeType],
        ];
    }

    /**
     * Converts a multi-file upload field (<input type="file" name="docs[]" multiple>)
     * into a list of individual files, each in standard single-file format.
     * Elements with UPLOAD_ERR_NO_FILE (unfilled fields) are removed.
     *
     * @param  array<mixed>  $field  The $_FILES element (e.g. $_FILES['documents']).
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     *
     * @throws LogicException If the structure does not match a $_FILES field.
     */
    public static function files(array $field): array
    {
        if (! isset($field['name'])) {
            throw new LogicException('Upload::files(): expected an element of $_FILES.');
        }

        if (! is_array($field['name'])) {
            $single = self::fields($field);

            return $single['error'] === UPLOAD_ERR_NO_FILE ? [] : [self::rebuild($field)];
        }

        $files = [];
        foreach (array_keys($field['name']) as $index) {
            $file = [];
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                if (! isset($field[$key]) || ! is_array($field[$key]) || ! array_key_exists($index, $field[$key])) {
                    throw new LogicException("Upload::files(): missing '{$key}' for file #{$index}.");
                }
                $file[$key] = $field[$key][$index];
            }

            if (is_array($file['name'])) {
                throw new LogicException('Upload::files(): nested file arrays (name[a][b]) are not supported.');
            }

            if (self::fields($file)['error'] !== UPLOAD_ERR_NO_FILE) {
                $files[] = self::rebuild($file);
            }
        }

        return $files;
    }

    /**
     * Maximum upload size accepted by the server in bytes:
     * minimum of upload_max_filesize and post_max_size (0 = unlimited).
     */
    public static function maxServerSize(): int
    {
        $limits = array_filter(
            [self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))],
            static fn (int $limit): bool => $limit > 0,
        );

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * Extracts and validates the fields of a $_FILES element.
     *
     * @param  array<mixed>  $file
     * @return array{tmpName: string, originalName: string, error: int, size: int, type: string}
     *
     * @throws LogicException If the structure does not match a single file from $_FILES.
     */
    private static function fields(array $file): array
    {
        if (isset($file['name']) && is_array($file['name'])) {
            throw new LogicException('Upload: multiple files in one field; use Upload::files() and handle each file.');
        }

        $name = $file['name'] ?? null;
        $tmpName = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;
        $type = $file['type'] ?? '';

        if (! is_string($name)) {
            throw self::invalidStructure('name');
        }
        if (! is_string($tmpName)) {
            throw self::invalidStructure('tmp_name');
        }
        if (! is_int($error)) {
            throw self::invalidStructure('error');
        }
        if (! is_int($size)) {
            throw self::invalidStructure('size');
        }

        return [
            'tmpName' => $tmpName,
            'originalName' => self::cleanName($name),
            'error' => $error,
            'size' => $size,
            'type' => is_string($type) ? $type : '',
        ];
    }

    private static function invalidStructure(string $key): LogicException
    {
        return new LogicException("Upload: invalid \$_FILES element (missing or invalid '{$key}').");
    }

    /**
     * @param  array<mixed>  $file
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private static function rebuild(array $file): array
    {
        $fields = self::fields($file);

        return [
            'name' => $fields['originalName'],
            'type' => $fields['type'],
            'tmp_name' => $fields['tmpName'],
            'error' => $fields['error'],
            'size' => $fields['size'],
        ];
    }

    /**
     * Sanitizes original name for display or DB storage: strips paths and control characters.
     */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return mb_substr($name, 0, 255);
    }

    /**
     * Converts a php.ini notation value ("2M", "512K", "1G", "1048576") to bytes.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
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
}
