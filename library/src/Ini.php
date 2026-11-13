<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use RuntimeException;
use UnexpectedValueException;

/**
 * Ini Class
 *
 * INI file parsing with clear error reporting and predictable value conversions.
 *
 * Natively, parse_ini_*() returns false with a warning on syntax errors, and in default mode
 * (INI_SCANNER_NORMAL) "yes" becomes "1", "null" becomes "", and values matching defined PHP
 * constant names are replaced with the constant's value.
 *
 * Modes:
 *   - default (raw):   all values remain strings, exactly as in the file; double quotes
 *                      are stripped, single quotes remain part of the value;
 *   - typed: true:     "true"/"yes"/"on" -> true, "false"/"no"/"off"/"none" -> false,
 *                      "null" -> null, numeric strings -> int/float (INI_SCANNER_TYPED); PHP constants,
 *                      ${ENV} variables and expressions ("E_ALL & ~E_NOTICE", "a|b") are rejected
 *                      with an exception instead of being silently substituted or evaluated.
 *
 * UTF-8 BOM is stripped (otherwise the first key would include it).
 * Duplicate keys are permitted by PHP; last occurrence takes precedence.
 *
 * Usage:
 *   require_once 'Ini.php';
 *   use Foundry\Ini;
 *
 *   $config = Ini::parseFile(__DIR__.'/config.ini', sections: true);
 *   $host = $config['database']['host'];
 *
 *   $flags = Ini::parseString("debug = yes\nport = 8080", typed: true);   // ['debug' => true, 'port' => 8080]
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
final class Ini
{
    /** @var string UTF-8 BOM. */
    private const string BOM = "\xEF\xBB\xBF";

    private function __construct() {}

    /**
     * Reads and parses an INI file.
     *
     * @param  string  $path  The file path.
     * @param  bool  $sections  true: results are grouped by section ([section]).
     * @param  bool  $typed  true: converts values to bool/null/int/float; false: strings only.
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException If the file does not exist or cannot be read.
     * @throws UnexpectedValueException On syntax errors.
     */
    public static function parseFile(string $path, bool $sections = false, bool $typed = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Ini::parseFile(): file '{$path}' does not exist.");
        }

        [$content, $warning] = self::capture(static fn (): string|false => file_get_contents($path));

        if ($content === false) {
            throw new RuntimeException("Ini::parseFile(): cannot read '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }

        return self::parse('parseFile', $content, $sections, $typed, " in '{$path}'");
    }

    /**
     * Parses an INI string.
     *
     * @param  string  $content  The INI content.
     * @param  bool  $sections  true: results are grouped by section ([section]).
     * @param  bool  $typed  true: converts values to bool/null/int/float; false: strings only.
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException On syntax errors.
     */
    public static function parseString(string $content, bool $sections = false, bool $typed = false): array
    {
        return self::parse('parseString', $content, $sections, $typed, '');
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException On syntax errors.
     */
    private static function parse(string $method, string $content, bool $sections, bool $typed, string $source): array
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        $raw = self::scan($method, $content, $sections, INI_SCANNER_RAW, $source);

        if (! $typed) {
            return $raw;
        }

        // INI_SCANNER_TYPED also substitutes PHP constants (PHP_OS, E_ALL), environment variables (${PATH})
        // and evaluates expressions ("abc|def" -> 0, "1 | 2" -> 3). Every typed value is therefore checked
        // against the raw text: anything other than the documented conversions is rejected.
        $result = self::scan($method, $content, $sections, INI_SCANNER_TYPED, $source);
        self::assertPlainConversions($method, $raw, $result, $source, '');

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException On syntax errors.
     */
    private static function scan(string $method, string $content, bool $sections, int $mode, string $source): array
    {
        [$result, $warning] = self::capture(static fn (): array|false => parse_ini_string($content, $sections, $mode));

        if ($result === false) {
            // Ex: "syntax error, unexpected end of file, expecting ']' in Unknown on line 2"
            $reason = $warning === null ? 'invalid INI content' : trim(str_replace(' in Unknown', '', $warning));

            throw new UnexpectedValueException("Ini::{$method}(): {$reason}{$source}.");
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @param  array<array-key, mixed>  $typed
     *
     * @throws UnexpectedValueException If a typed value is not a plain conversion of its raw text.
     */
    private static function assertPlainConversions(string $method, array $raw, array $typed, string $source, string $prefix): void
    {
        foreach ($typed as $key => $value) {
            $path = $prefix.$key;
            $text = $raw[$key] ?? null;

            if (is_array($value) && is_array($text)) {
                self::assertPlainConversions($method, $text, $value, $source, $path.'.');

                continue;
            }

            if (! is_string($text) || ! self::isPlainConversion($text, $value)) {
                throw new UnexpectedValueException(
                    "Ini::{$method}(): the value of '{$path}'{$source} uses a PHP constant, an environment variable or an expression, "
                    .'which typed mode does not allow (put the value in double quotes to keep it as text).',
                );
            }
        }
    }

    /** The conversions typed mode documents: keywords to bool/null, numeric strings to int/float, everything else unchanged. */
    private static function isPlainConversion(string $text, mixed $value): bool
    {
        return match (true) {
            is_string($value) => $value === $text,
            is_bool($value) => in_array(strtolower($text), $value ? ['true', 'yes', 'on', '1'] : ['false', 'no', 'off', 'none', '0', ''], true),
            $value === null => strtolower($text) === 'null',
            is_int($value), is_float($value) => is_numeric($text) && (float) $text === (float) $value,
            default => false,
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
