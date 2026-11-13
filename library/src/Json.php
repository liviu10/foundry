<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Json Class
 *
 * Wrappers for json_encode() / json_decode().
 * Any error throws an exception; null or false is never returned.
 * Error messages never contain the JSON payload itself (which may contain sensitive personal data).
 *
 * Usage:
 *   require_once 'Json.php';
 *   use Foundry\Json;
 *
 *   $data = Json::decodeArray($body);               // array or exception
 *   $json = Json::encode(['oras' => 'Iasi']);       // {"oras":"Iasi"}
 *   $config = Json::readArray(__DIR__.'/config.json');
 *   Json::writeFile(__DIR__.'/out.json', $data);             // atomic: temp file + rename
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
final class Json
{
    /** @var int Flags for encode(): readable UTF-8, unescaped slashes, 1.0 preserved as 1.0. */
    public const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** @var int Default maximum depth. */
    public const int DEPTH = 512;

    /** @var string UTF-8 BOM, often added by Windows text editors. */
    private const string BOM = "\xEF\xBB\xBF";

    private function __construct() {}

    /**
     * Decodes a JSON string. By default, objects are converted to associative arrays.
     *
     * @param  string  $json  The JSON string.
     * @param  bool  $associative  true: objects as associative arrays; false: objects as stdClass.
     * @param  int  $depth  Maximum depth.
     * @return mixed The decoded value (array, scalar, or null for the "null" literal).
     *
     * @throws JsonException If the JSON is invalid or empty.
     * @throws InvalidArgumentException If $depth < 1.
     */
    public static function decode(string $json, bool $associative = true, int $depth = self::DEPTH): mixed
    {
        if ($depth < 1) {
            throw new InvalidArgumentException("Json::decode(): depth must be at least 1, got {$depth}.");
        }

        if (trim($json) === '') {
            throw new JsonException('Json::decode(): cannot decode an empty string.');
        }

        try {
            return json_decode($json, $associative, $depth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new JsonException(
                sprintf('Json::decode(): %s (input length: %d bytes).', $e->getMessage(), strlen($json)),
                $e->getCode(),
                $e,
            );
        }
    }

    /**
     * Decodes a JSON string that must represent an object or array.
     *
     * @param  string  $json  The JSON string.
     * @param  int  $depth  Maximum depth.
     * @return array<mixed> The decoded array.
     *
     * @throws JsonException If the JSON is invalid.
     * @throws InvalidArgumentException If $depth < 1.
     * @throws UnexpectedValueException If the JSON is valid but not an object/array (e.g., "42").
     */
    public static function decodeArray(string $json, int $depth = self::DEPTH): array
    {
        $value = self::decode($json, true, $depth);

        if (! is_array($value)) {
            throw new UnexpectedValueException(
                sprintf('Json::decodeArray(): expected a JSON object or array, got %s.', get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * Encodes a value as JSON.
     *
     * @param  mixed  $value  The value to encode.
     * @param  bool  $pretty  true for formatted, indented JSON.
     * @param  int  $flags  Additional JSON_* flags combined with ENCODE_FLAGS.
     *                      JSON_PARTIAL_OUTPUT_ON_ERROR is ignored as it would suppress errors.
     * @return string The JSON string.
     *
     * @throws JsonException On invalid UTF-8, INF/NAN, recursion, or maximum depth exceeded.
     */
    public static function encode(mixed $value, bool $pretty = false, int $flags = 0): string
    {
        $flags = ($flags | self::ENCODE_FLAGS | JSON_THROW_ON_ERROR) & ~JSON_PARTIAL_OUTPUT_ON_ERROR;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            $json = json_encode($value, $flags, self::DEPTH);
        } catch (JsonException $e) {
            throw new JsonException(
                sprintf('Json::encode(): %s (value type: %s).', $e->getMessage(), get_debug_type($value)),
                $e->getCode(),
                $e,
            );
        }

        // Cu JSON_THROW_ON_ERROR nu se ajunge aici; verificarea ramane ca plasa de siguranta.
        if ($json === false) {
            throw new JsonException('Json::encode(): '.json_last_error_msg().'.');
        }

        return $json;
    }

    /**
     * Checks whether a string is valid JSON without throwing an exception.
     *
     * @param  string  $json  The string to validate.
     * @return bool true if valid.
     */
    public static function isValid(string $json): bool
    {
        return json_validate($json);
    }

    /**
     * Reads and decodes a JSON file. The UTF-8 BOM is stripped if present.
     *
     * @param  string  $path  The file path.
     * @return mixed The decoded value.
     *
     * @throws RuntimeException If the file does not exist or cannot be read.
     * @throws JsonException If the file does not contain valid JSON.
     */
    public static function readFile(string $path): mixed
    {
        if (! is_file($path)) {
            throw new RuntimeException("Json::readFile(): file '{$path}' does not exist.");
        }

        [$content, $warning] = self::capture(static fn (): string|false => file_get_contents($path));

        if (! is_string($content)) {
            throw new RuntimeException("Json::readFile(): cannot read '{$path}'".self::reason($warning));
        }

        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        try {
            return self::decode($content);
        } catch (JsonException $e) {
            throw new JsonException("Json::readFile(): invalid JSON in '{$path}'. ".$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Reads a JSON file that must contain an object or array.
     *
     * @param  string  $path  The file path.
     * @return array<mixed> The decoded array.
     *
     * @throws RuntimeException If the file does not exist or cannot be read.
     * @throws JsonException If the file does not contain valid JSON.
     * @throws UnexpectedValueException If the JSON is not an object or array.
     */
    public static function readArray(string $path): array
    {
        $value = self::readFile($path);

        if (! is_array($value)) {
            throw new UnexpectedValueException(
                sprintf("Json::readArray(): '%s' must contain a JSON object or array, got %s.", $path, get_debug_type($value)),
            );
        }

        return $value;
    }

    /**
     * Encodes a value and writes it to a file atomically: the content goes to a temporary file in the
     * same directory, which then replaces the target with rename(). Readers see either the old or the
     * new content, never an empty or truncated file, and a crash mid-write leaves the old file intact.
     *
     * @param  string  $path  The file path; the directory must already exist.
     * @param  mixed  $value  The value to encode.
     * @param  bool  $pretty  true for formatted, indented JSON (default for files).
     *
     * @throws JsonException If the value cannot be encoded.
     * @throws RuntimeException If the file cannot be written.
     */
    public static function writeFile(string $path, mixed $value, bool $pretty = true): void
    {
        $json = self::encode($value, $pretty)."\n";

        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException("Json::writeFile(): directory '{$directory}' does not exist.");
        }

        if (is_dir($path)) {
            throw new RuntimeException("Json::writeFile(): cannot write '{$path}': the path is a directory.");
        }

        [$temp, $warning] = self::capture(static fn (): string|false => tempnam($directory, '.json-'));

        // tempnam() may fall back to the system temp directory, where rename() would no longer be atomic.
        if (! is_string($temp) || realpath(dirname($temp)) !== realpath($directory)) {
            if (is_string($temp)) {
                self::capture(static fn (): bool => unlink($temp));
            }

            throw new RuntimeException("Json::writeFile(): cannot create a temporary file next to '{$path}'".self::reason($warning));
        }

        try {
            [$written, $warning] = self::capture(static fn (): int|false => file_put_contents($temp, $json));
            if ($written !== strlen($json)) {
                throw new RuntimeException("Json::writeFile(): cannot write '{$path}'".self::reason($warning));
            }

            // tempnam() creates 0600 files: keep the existing file's permissions, or the umask default.
            $mode = is_file($path) ? (int) fileperms($path) & 0777 : 0666 & ~umask();
            self::capture(static fn (): bool => chmod($temp, $mode));

            [$renamed, $warning] = self::capture(static fn (): bool => rename($temp, $path));
            if (! $renamed) {
                throw new RuntimeException("Json::writeFile(): cannot write '{$path}'".self::reason($warning));
            }
        } finally {
            if (is_file($temp)) {
                self::capture(static fn (): bool => unlink($temp));
            }
        }
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     * Behaves consistently regardless of the host project's error handler.
     *
     * @param  Closure(): mixed  $operation  The operation to execute.
     * @return array{0: mixed, 1: string|null} [result, warning message or null]
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
     * Formats an error cause for inclusion in an exception message.
     */
    private static function reason(?string $warning): string
    {
        return $warning === null ? '.' : ": {$warning}";
    }
}
