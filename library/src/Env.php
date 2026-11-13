<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use RuntimeException;
use UnexpectedValueException;

/**
 * Env Class
 *
 * Loads environment variables from .env files and reads them as typed values.
 * The file syntax is compatible with vlucas/phpdotenv.
 *
 * .env syntax:
 *   # comment
 *   APP_ENV=production
 *   export DB_HOST=localhost            # "export" is optional; trailing comment
 *   DB_PASSWORD=                        # empty value
 *   APP_NAME="Customer Portal"          # double quotes: escape sequences (\n \t \" \\ \$) and ${VAR}
 *   REGEX='^\d+$'                       # single quotes: literal text, no interpolation
 *   DB_DSN="mysql:host=${DB_HOST}"      # interpolation from previously defined variables
 *   CERT="-----BEGIN-----
 *   ...
 *   -----END-----"                      # multiline value (enclosed in quotes)
 *
 * Rules:
 *   - variables already present in the environment are NOT overwritten (unless $override = true);
 *   - unquoted values cannot contain whitespace;
 *   - a duplicate key in the same file or an undefined ${VAR} throws an exception;
 *   - error messages contain the file, line, and variable name, never the value.
 *
 * Usage:
 *   require_once 'Env.php';
 *   use Foundry\Env;
 *
 *   Env::load(__DIR__.'/.env');
 *   Env::loadIfExists(__DIR__.'/.env.local', override: true);
 *   Env::required('DB_HOST', 'DB_USER', 'DB_PASSWORD');
 *
 *   $host = Env::string('DB_HOST');
 *   $port = Env::int('DB_PORT', 3306);
 *   $debug = Env::bool('APP_DEBUG', false);
 *   $hosts = Env::list('ALLOWED_HOSTS');            // "a.com, b.com" -> ['a.com', 'b.com']
 *   $env = Env::oneOf('APP_ENV', ['local', 'test', 'production']);
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
final class Env
{
    /** @var string Valid variable name pattern (as in phpdotenv: letters, digits, "_" and "."). */
    private const string NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    /** @var string UTF-8 BOM, often added by Windows editors. */
    private const string BOM = "\xEF\xBB\xBF";

    /** @var array<int|string, bool> Values accepted by bool(); PHP converts '1'/'0' keys to int. */
    private const array BOOLEANS = [
        'true' => true, '1' => true, 'yes' => true, 'on' => true,
        'false' => false, '0' => false, 'no' => false, 'off' => false,
    ];

    private function __construct() {}

    // Incarcare

    /**
     * Loads a .env file into $_ENV and $_SERVER (and optionally into getenv()).
     *
     * @param  string  $path  The .env file path.
     * @param  bool  $override  true: overwrites existing environment variables.
     * @param  bool  $putenv  true: also sets via putenv(), for code relying on getenv().
     *                        putenv() is not thread-safe; avoid it in multithreaded environments.
     * @return array<string, string> The variables actually set (excluding skipped ones).
     *
     * @throws RuntimeException If the file does not exist or cannot be read.
     * @throws UnexpectedValueException If the file contains syntax errors.
     */
    public static function load(string $path, bool $override = false, bool $putenv = false): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Env::load(): file '{$path}' does not exist.");
        }

        [$content, $warning] = self::capture(static fn (): string|false => file_get_contents($path));

        if ($content === false) {
            throw new RuntimeException("Env::load(): cannot read '{$path}'".($warning === null ? '.' : ": {$warning}."));
        }

        $loaded = [];
        foreach (self::parse($content, $path) as $name => $value) {
            if (! $override && self::read($name) !== null) {
                continue;
            }

            self::write($name, $value, $putenv);
            $loaded[$name] = $value;
        }

        return $loaded;
    }

    /**
     * Like load(), but without throwing an error if the file does not exist (e.g. optional .env.local).
     * Syntax errors still throw exceptions.
     *
     * @return bool true if the file existed and was loaded.
     *
     * @throws RuntimeException If the file exists but cannot be read.
     * @throws UnexpectedValueException If the file contains syntax errors.
     */
    public static function loadIfExists(string $path, bool $override = false, bool $putenv = false): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        self::load($path, $override, $putenv);

        return true;
    }

    /**
     * Parses the contents of a .env file without modifying the environment.
     * ${VAR} references are resolved first from preceding variables in the same content, then from the environment.
     *
     * @param  string  $content  The file contents.
     * @param  string  $source  Source name used in error messages.
     * @return array<string, string> The variables, in the order defined in the file.
     *
     * @throws UnexpectedValueException On syntax errors.
     */
    public static function parse(string $content, string $source = '.env'): array
    {
        if (str_starts_with($content, self::BOM)) {
            $content = substr($content, strlen(self::BOM));
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $count = count($lines);
        $vars = [];

        for ($i = 0; $i < $count; $i++) {
            $lineNumber = $i + 1;
            $line = ltrim($lines[$i]);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $equals = strpos($line, '=');
            if ($equals === false) {
                throw self::syntaxError($source, $lineNumber, 'expected NAME=value');
            }

            $name = rtrim(substr($line, 0, $equals));
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw self::syntaxError($source, $lineNumber, 'invalid variable name');
            }

            if (array_key_exists($name, $vars)) {
                throw self::syntaxError($source, $lineNumber, "duplicate variable '{$name}'");
            }

            $raw = substr($line, $equals + 1);
            $trimmed = ltrim($raw);
            $quote = $trimmed[0] ?? '';

            if ($quote === '"' || $quote === "'") {
                // Valoare intre ghilimele: poate continua pe liniile urmatoare.
                $body = substr($trimmed, 1);
                while (($end = self::closingQuote($body, $quote)) === null) {
                    if (++$i >= $count) {
                        throw self::syntaxError($source, $lineNumber, "unterminated quoted value for '{$name}'");
                    }
                    $body .= "\n".$lines[$i];
                }

                $rest = trim(substr($body, $end + 1));
                if ($rest !== '' && ! str_starts_with($rest, '#')) {
                    throw self::syntaxError($source, $i + 1, "unexpected characters after the closing quote of '{$name}'");
                }

                $inner = substr($body, 0, $end);
                $vars[$name] = $quote === "'"
                    ? $inner
                    : self::expandDoubleQuoted($inner, $vars, $source, $lineNumber);

                continue;
            }

            // Valoare fara ghilimele: comentariul incepe cu spatiu + "#".
            $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));

            if (preg_match('/\s/', $value) === 1) {
                throw self::syntaxError($source, $lineNumber, "unquoted value of '{$name}' contains whitespace; wrap it in quotes");
            }

            $vars[$name] = self::interpolate($value, $vars, $source, $lineNumber);
        }

        return $vars;
    }

    // Validare

    /**
     * Verifies that all specified variables exist (empty values are allowed).
     * Reports all missing variables in a single exception.
     *
     * @throws RuntimeException If at least one variable is missing.
     */
    public static function required(string ...$names): void
    {
        $missing = array_values(array_filter($names, static fn (string $name): bool => self::read($name) === null));

        if ($missing !== []) {
            throw new RuntimeException('Env::required(): missing environment variable(s): '.implode(', ', $missing).'.');
        }
    }

    /**
     * Verifies that all specified variables exist and are not empty (whitespace-only is considered empty).
     *
     * @throws RuntimeException If at least one variable is missing or empty.
     */
    public static function notEmpty(string ...$names): void
    {
        $invalid = array_values(array_filter($names, static fn (string $name): bool => trim(self::read($name) ?? '') === ''));

        if ($invalid !== []) {
            throw new RuntimeException('Env::notEmpty(): missing or empty environment variable(s): '.implode(', ', $invalid).'.');
        }
    }

    // Citire

    /**
     * Checks whether an environment variable exists (even if empty).
     */
    public static function has(string $name): bool
    {
        return self::read($name) !== null;
    }

    /**
     * Value as a string. An empty value is returned as ''.
     *
     * @param  string  $name  The variable name.
     * @param  string|null  $default  The fallback value if the variable is missing; null = required.
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     */
    public static function string(string $name, ?string $default = null): string
    {
        $value = self::read($name) ?? $default;

        return $value ?? throw self::missing('string', $name);
    }

    /**
     * Value or null if the variable is missing or empty.
     */
    public static function nullable(string $name): ?string
    {
        $value = self::read($name);

        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * Value as an integer (e.g. "3306", "-1").
     *
     * @param  string  $name  The variable name.
     * @param  int|null  $default  The fallback value if the variable is missing; null = required.
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not a valid integer.
     */
    public static function int(string $name, ?int $default = null): int
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default ?? throw self::missing('int', $name);
        }

        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);
        if ($value === false) {
            throw self::invalid('int', $name, 'an integer');
        }

        return $value;
    }

    /**
     * Value as a float, using a dot (e.g. "0.19", "1e3"). Comma is not accepted.
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not a valid float.
     */
    public static function float(string $name, ?float $default = null): float
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default ?? throw self::missing('float', $name);
        }

        $value = filter_var(trim($raw), FILTER_VALIDATE_FLOAT);
        if ($value === false) {
            throw self::invalid('float', $name, 'a number (use "." as decimal separator)');
        }

        return $value;
    }

    /**
     * Value as a boolean. Accepts (case-insensitively):
     * true/false, 1/0, yes/no, on/off. Any other value results in an error.
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not one of the accepted representations.
     */
    public static function bool(string $name, ?bool $default = null): bool
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default ?? throw self::missing('bool', $name);
        }

        return self::BOOLEANS[strtolower(trim($raw))] ?? throw self::invalid('bool', $name, 'one of: true, false, 1, 0, yes, no, on, off');
    }

    /**
     * Value as a list: "a, b ,c" -> ['a', 'b', 'c']. Empty elements are filtered out.
     *
     * @param  string  $name  The variable name.
     * @param  list<string>|null  $default  The fallback list if the variable is missing; null = required.
     * @param  non-empty-string  $separator  The separator.
     * @return list<string>
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     */
    public static function list(string $name, ?array $default = null, string $separator = ','): array
    {
        $raw = self::read($name);
        if ($raw === null) {
            return $default ?? throw self::missing('list', $name);
        }

        return array_values(array_filter(
            array_map(trim(...), explode($separator, $raw)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Value that must match one of the allowed values (strict comparison).
     *
     * @param  string  $name  The variable name.
     * @param  list<string>  $allowed  The allowed values.
     * @param  string|null  $default  The fallback value if the variable is missing; null = required.
     *
     * @throws RuntimeException If the variable is missing and no $default is provided.
     * @throws UnexpectedValueException If the value is not allowed.
     */
    public static function oneOf(string $name, array $allowed, ?string $default = null): string
    {
        $value = self::string($name, $default);

        if (! in_array($value, $allowed, true)) {
            throw self::invalid('oneOf', $name, 'one of: '.implode(', ', $allowed));
        }

        return $value;
    }

    // Intern

    /**
     * Reads a variable from $_ENV, $_SERVER, or getenv(), in that order.
     * HTTP_* variables are not read from $_SERVER: those are request headers controlled
     * by the client (see the "httpoxy" vulnerability).
     */
    private static function read(string $name): ?string
    {
        if (isset($_ENV[$name]) && is_scalar($_ENV[$name])) {
            return (string) $_ENV[$name];
        }

        if (! str_starts_with($name, 'HTTP_') && isset($_SERVER[$name]) && is_scalar($_SERVER[$name])) {
            return (string) $_SERVER[$name];
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }

    private static function write(string $name, string $value, bool $putenv): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;

        if ($putenv) {
            putenv("{$name}={$value}");
        }
    }

    /**
     * Offset of the closing quote or null if not yet found.
     * In double quotes, \" does not close the value; single quotes have no escape mechanism.
     */
    private static function closingQuote(string $body, string $quote): ?int
    {
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            if ($quote === '"' && $body[$i] === '\\') {
                $i++;

                continue;
            }

            if ($body[$i] === $quote) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Double-quoted value: escape sequences (\n \r \t \" \\ \$) and ${VAR}.
     * Other backslash sequences remain unchanged (e.g. "C:\xampp").
     *
     * @param  array<string, string>  $vars  Variables already defined in the file.
     */
    private static function expandDoubleQuoted(string $value, array $vars, string $source, int $line): string
    {
        $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", '"' => '"', '\\' => '\\', '$' => '$'];
        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $next = $value[$i + 1];
                $result .= $escapes[$next] ?? '\\'.$next;
                $i++;

                continue;
            }

            if ($char === '$' && ($value[$i + 1] ?? '') === '{') {
                $end = strpos($value, '}', $i);
                if ($end === false) {
                    throw self::syntaxError($source, $line, 'unterminated ${...} reference');
                }

                $result .= self::resolve(substr($value, $i + 2, $end - $i - 2), $vars, $source, $line);
                $i = $end;

                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    /**
     * Replaces ${VAR} in an unquoted value.
     *
     * @param  array<string, string>  $vars  Variables already defined in the file.
     */
    private static function interpolate(string $value, array $vars, string $source, int $line): string
    {
        if (! str_contains($value, '${')) {
            return $value;
        }

        if (preg_match('/\$\{(?![^}]*\})/', $value) === 1) {
            throw self::syntaxError($source, $line, 'unterminated ${...} reference');
        }

        return (string) preg_replace_callback(
            '/\$\{([^}]*)\}/',
            static fn (array $match): string => self::resolve($match[1], $vars, $source, $line),
            $value,
        );
    }

    /**
     * Resolves the value of a variable referenced by ${NAME}: first from the file, then from the environment.
     *
     * @param  array<string, string>  $vars  Variables already defined in the file.
     *
     * @throws UnexpectedValueException If the variable is not defined anywhere.
     */
    private static function resolve(string $name, array $vars, string $source, int $line): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw self::syntaxError($source, $line, 'invalid variable name in ${...} reference');
        }

        $value = $vars[$name] ?? self::read($name);

        return $value ?? throw self::syntaxError($source, $line, "undefined variable '{$name}' referenced");
    }

    private static function syntaxError(string $source, int $line, string $reason): UnexpectedValueException
    {
        return new UnexpectedValueException("Env::parse(): {$source} line {$line}: {$reason}.");
    }

    private static function missing(string $method, string $name): RuntimeException
    {
        return new RuntimeException("Env::{$method}(): environment variable '{$name}' is not set.");
    }

    private static function invalid(string $method, string $name, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException("Env::{$method}(): environment variable '{$name}' must be {$expected}.");
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
