<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Config Class
 *
 * Configuration loaded from PHP files returning arrays, with dot-notation access.
 * The first segment of the key is the filename: 'database.connections.mysql.host'
 * reads config/database.php -> ['connections']['mysql']['host'].
 *
 * Files are loaded on first access and cached in memory for the remainder of the request.
 * A missing key without a default value is an error (does not silently return null).
 *
 * Example config/database.php:
 *   <?php
 *   return [
 *       'host' => Env::string('DB_HOST'),
 *       'port' => Env::int('DB_PORT', 3306),
 *   ];
 *
 * Usage:
 *   require_once 'Config.php';
 *   use Foundry\Config;
 *
 *   Config::load(__DIR__.'/config');
 *
 *   $host = Config::string('database.host');
 *   $port = Config::int('database.port');
 *   $debug = Config::bool('app.debug', false);
 *   $mail = Config::array('mail');                 // the entire config/mail.php file
 *   $value = Config::get('app.optional', null);    // any type, with default value
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
final class Config
{
    /** @var string Allowed configuration filename pattern. */
    private const string FILE_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private static ?string $directory = null;

    /** @var array<string, array<array-key, mixed>> Loaded files, keyed by name. */
    private static array $items = [];

    private function __construct() {}

    /**
     * Sets the configuration directory and clears previously loaded files.
     *
     * @throws RuntimeException If the directory does not exist.
     */
    public static function load(string $directory): void
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Config::load(): directory '{$directory}' does not exist.");
        }

        self::$directory = rtrim($directory, '/\\');
        self::$items = [];
    }

    /**
     * Returns the value of a key of any type.
     * With a single argument the key is required; with two arguments, the second acts as the default value.
     *
     * @throws RuntimeException If the key is missing and no default value is provided.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $hasDefault = func_num_args() > 1;

        [$found, $value] = self::lookup('get', $key, $hasDefault);

        if (! $found) {
            return $hasDefault ? $default : throw self::missing('get', $key);
        }

        return $value;
    }

    /**
     * Checks whether a key exists (a missing file means the key does not exist).
     */
    public static function has(string $key): bool
    {
        return self::lookup('has', $key, true)[0];
    }

    /**
     * Returns the value as a string (no type conversion: an int in config throws an error).
     *
     * @throws RuntimeException If the key is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not a string.
     */
    public static function string(string $key, ?string $default = null): string
    {
        $value = self::typed('string', $key, $default);

        return is_string($value) ? $value : throw self::invalid('string', $key, 'a string', $value);
    }

    /**
     * Returns the value as an int (no type conversion: "3306" in config throws an error).
     *
     * @throws RuntimeException If the key is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not an int.
     */
    public static function int(string $key, ?int $default = null): int
    {
        $value = self::typed('int', $key, $default);

        return is_int($value) ? $value : throw self::invalid('int', $key, 'an int', $value);
    }

    /**
     * Returns the value as a float (integers are accepted and converted).
     *
     * @throws RuntimeException If the key is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not a number.
     */
    public static function float(string $key, ?float $default = null): float
    {
        $value = self::typed('float', $key, $default);

        if (is_int($value)) {
            return (float) $value;
        }

        return is_float($value) ? $value : throw self::invalid('float', $key, 'a float', $value);
    }

    /**
     * Returns the value as a bool (no type conversion: "true" or 1 in config throws an error).
     *
     * @throws RuntimeException If the key is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not a bool.
     */
    public static function bool(string $key, ?bool $default = null): bool
    {
        $value = self::typed('bool', $key, $default);

        return is_bool($value) ? $value : throw self::invalid('bool', $key, 'a bool', $value);
    }

    /**
     * Returns the value as an array (e.g. a section or an entire file).
     *
     * @param  array<array-key, mixed>|null  $default
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException If the key is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not an array.
     */
    public static function array(string $key, ?array $default = null): array
    {
        $value = self::typed('array', $key, $default);

        return is_array($value) ? $value : throw self::invalid('array', $key, 'an array', $value);
    }

    /**
     * Overwrites a value in memory (e.g. during testing or dynamic configuration).
     * The file corresponding to the first segment is loaded beforehand, if it exists.
     *
     * @throws InvalidArgumentException If the key is invalid.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = self::segments('set', $key);
        $file = array_shift($segments);

        if (! isset(self::$items[$file]) && self::$directory !== null && is_file(self::path($file))) {
            self::loadFile('set', $file);
        }

        if ($segments === []) {
            if (! is_array($value)) {
                throw new InvalidArgumentException("Config::set(): the value of a whole file ('{$file}') must be an array.");
            }
            self::$items[$file] = $value;

            return;
        }

        $current = &self::$items[$file];
        foreach ($segments as $segment) {
            if (! is_array($current)) {
                $current = [];
            }
            $current = &$current[$segment];
        }
        $current = $value;
        unset($current);
    }

    /**
     * Clears the configured directory and all loaded values (e.g. between tests).
     */
    public static function reset(): void
    {
        self::$directory = null;
        self::$items = [];
    }

    /**
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private static function lookup(string $method, string $key, bool $missingFileIsMiss): array
    {
        $segments = self::segments($method, $key);
        $file = array_shift($segments);

        if (! isset(self::$items[$file])) {
            if (self::$directory === null) {
                throw new LogicException("Config::{$method}(): call Config::load(\$directory) first.");
            }

            if (! is_file(self::path($file))) {
                if ($missingFileIsMiss) {
                    return [false, null];
                }
                throw new RuntimeException("Config::{$method}(): configuration file '".self::path($file)."' does not exist.");
            }

            self::loadFile($method, $file);
        }

        $value = self::$items[$file];
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return [false, null];
            }
            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * Resolves values for typed getters: $default (if not null) replaces a missing key.
     */
    private static function typed(string $method, string $key, mixed $default): mixed
    {
        [$found, $value] = self::lookup($method, $key, $default !== null);

        if (! $found) {
            return $default ?? throw self::missing($method, $key);
        }

        return $value;
    }

    /**
     * @throws UnexpectedValueException If the file does not return an array.
     */
    private static function loadFile(string $method, string $file): void
    {
        $path = self::path($file);
        $loaded = (static fn (): mixed => require $path)();

        if (! is_array($loaded)) {
            throw new UnexpectedValueException("Config::{$method}(): '{$path}' must return an array, got ".get_debug_type($loaded).'.');
        }

        self::$items[$file] = $loaded;
    }

    /**
     * @return non-empty-list<string> Key segments; the first segment is the file name.
     *
     * @throws InvalidArgumentException If the key is empty or the file name is invalid.
     */
    private static function segments(string $method, string $key): array
    {
        $segments = explode('.', $key);

        if (in_array('', $segments, true)) {
            throw new InvalidArgumentException("Config::{$method}(): invalid key '{$key}' (empty segment).");
        }

        if (preg_match(self::FILE_PATTERN, $segments[0]) !== 1) {
            throw new InvalidArgumentException("Config::{$method}(): invalid configuration file name '{$segments[0]}' (allowed: letters, digits, '_', '-').");
        }

        return $segments;
    }

    private static function path(string $file): string
    {
        return self::$directory.'/'.$file.'.php';
    }

    private static function missing(string $method, string $key): RuntimeException
    {
        return new RuntimeException("Config::{$method}(): configuration key '{$key}' is not set.");
    }

    private static function invalid(string $method, string $key, string $expected, mixed $value): UnexpectedValueException
    {
        return new UnexpectedValueException("Config::{$method}(): configuration key '{$key}' must be {$expected}, got ".get_debug_type($value).'.');
    }
}
