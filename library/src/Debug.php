<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeInterface;
use LogicException;
use Throwable;
use UnitEnum;

/**
 * Debug Class
 *
 * dump() and dd() for development: displays the exact type of each value, caller file and line number,
 * plain text in CLI and <pre> formatted in browser.
 * Disabled by default: an accidental dump() left in code throws an exception in production rather than leaking data.
 *
 * Rules:
 *   - Debug::enable() must be called explicitly, only in development (e.g. during bootstrap after application config);
 *   - sensitive keys and properties (password, token, cnp, iban...) are masked;
 *   - recursion depth and string lengths are capped; circular references are detected;
 *   - dd() outputs values and terminates script execution;
 *   - functions dump() and dd() in namespace Foundry provide shortcuts
 *     (use function Foundry\dump;).
 *
 * Usage:
 *   require_once 'Debug.php';
 *   use Foundry\Debug;
 *
 *   Debug::enable($config['debug']);   // development only
 *   Debug::dump($policy, $_POST);
 *   Debug::dd($result);                // dumps and exits
 *   $text = Debug::export($value);     // string representation without printing
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
final class Debug
{
    /** @var list<string> Substrings of masked keys (as in Log). */
    public const array SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'auth', 'api_key', 'apikey',
        'private_key', 'card', 'cvv', 'cvc', 'iban', 'cnp',
    ];

    private const string MASK = '******';

    private const int MAX_STRING = 10_000;

    private const int MAX_ITEMS = 500;

    private static bool $enabled = false;

    private function __construct() {}

    /**
     * Enables (or disables) dump() and dd(). Disabled by default.
     */
    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    public static function isEnabled(): bool
    {
        return self::$enabled;
    }

    /**
     * Dumps values along with the caller's file and line number.
     *
     * @throws LogicException If Debug is not enabled.
     */
    public static function dump(mixed ...$values): void
    {
        self::output('dump', $values);
    }

    /**
     * Dumps values and terminates script execution.
     *
     * @throws LogicException If Debug is not enabled.
     */
    public static function dd(mixed ...$values): never
    {
        self::output('dd', $values);

        exit(1);
    }

    /**
     * Returns string representation of a value (without printing and without checking enable()).
     */
    public static function export(mixed $value, int $maxDepth = 10): string
    {
        return self::render($value, 0, max(1, $maxDepth), []);
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @throws LogicException
     */
    private static function output(string $method, array $values): void
    {
        if (! self::$enabled) {
            throw new LogicException("Debug::{$method}(): debugging is disabled; call Debug::enable() in development only.");
        }

        $text = self::caller();
        foreach ($values as $value) {
            $text .= "\n".self::export($value);
        }

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            echo $text."\n";

            return;
        }

        echo '<pre style="background:#1e1e1e;color:#dcdcdc;padding:8px 12px;margin:8px 0;font:12px/1.4 monospace;white-space:pre-wrap;text-align:left">'
            .htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .'</pre>';
    }

    /**
     * Determines caller file and line number from application code calling dump()/dd().
     */
    private static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file !== null && $file !== __FILE__) {
                return $file.':'.($frame['line'] ?? 0);
            }
        }

        return 'unknown';
    }

    /**
     * @param  list<int>  $path  Object IDs visited in current traversal (for circular reference detection).
     */
    private static function render(mixed $value, int $depth, int $maxDepth, array $path): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => "int({$value})",
            is_float($value) => 'float('.self::float($value).')',
            is_string($value) => self::string($value),
            is_array($value) => self::arrayValue($value, $depth, $maxDepth, $path),
            is_object($value) => self::objectValue($value, $depth, $maxDepth, $path),
            default => get_debug_type($value).(is_resource($value) ? '#'.get_resource_id($value) : ''),
        };
    }

    private static function float(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return (string) $value;
        }

        $text = var_export($value, true);

        return str_contains($text, '.') || str_contains($text, 'E') ? $text : $text.'.0';
    }

    private static function string(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return 'binary('.strlen($value).') "'.bin2hex(substr($value, 0, 64)).(strlen($value) > 64 ? '...' : '').'"';
        }

        $length = mb_strlen($value, 'UTF-8');
        $shown = $length > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING, 'UTF-8') : $value;
        $escaped = self::escape($shown);

        return "string({$length}) \"{$escaped}\"".($length > self::MAX_STRING ? ' ... (+'.($length - self::MAX_STRING).' chars)' : '');
    }

    /**
     * Converts special and control characters to visible escape sequences (\n, \t, \x01).
     */
    private static function escape(string $text): string
    {
        $escaped = strtr($text, ['\\' => '\\\\', '"' => '\\"', "\n" => '\n', "\r" => '\r', "\t" => '\t', "\0" => '\0']);

        return preg_replace_callback('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', static fn (array $m): string => sprintf('\x%02X', ord($m[0])), $escaped) ?? $escaped;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  list<int>  $path
     */
    private static function arrayValue(array $value, int $depth, int $maxDepth, array $path): string
    {
        $count = count($value);
        if ($count === 0) {
            return 'array(0) []';
        }

        if ($depth >= $maxDepth) {
            return "array({$count}) [...]";
        }

        $lines = [];
        $indent = str_repeat('  ', $depth + 1);
        $shown = 0;
        foreach ($value as $key => $item) {
            if ($shown++ === self::MAX_ITEMS) {
                $lines[] = $indent.'... (+'.($count - self::MAX_ITEMS).' items)';

                break;
            }

            $label = is_int($key) ? "[{$key}]" : '["'.(mb_check_encoding($key, 'UTF-8') ? self::escape($key) : bin2hex($key)).'"]';
            $lines[] = $indent.$label.' => '.(self::isSensitive((string) $key) ? self::MASK : self::render($item, $depth + 1, $maxDepth, $path));
        }

        return "array({$count}) [\n".implode("\n", $lines)."\n".str_repeat('  ', $depth).']';
    }

    /**
     * @param  list<int>  $path
     */
    private static function objectValue(object $value, int $depth, int $maxDepth, array $path): string
    {
        $id = spl_object_id($value);
        $class = $value::class;

        if ($value instanceof UnitEnum) {
            return 'enum('.$class.'::'.$value->name.')';
        }

        if ($value instanceof Closure) {
            return "Closure#{$id}";
        }

        if ($value instanceof DateTimeInterface) {
            return "{$class}#{$id} ".$value->format('Y-m-d H:i:s.u P (e)');
        }

        if (in_array($id, $path, true)) {
            return "{$class}#{$id} *RECURSION*";
        }

        if ($value instanceof Throwable) {
            return "{$class}#{$id} \"".$value->getMessage().'" at '.$value->getFile().':'.$value->getLine();
        }

        // (array) pastreaza si proprietatile protejate ("\0*\0nume") si private ("\0Clasa\0nume").
        $properties = (array) $value;
        if ($properties === []) {
            return "{$class}#{$id} {}";
        }

        if ($depth >= $maxDepth) {
            return "{$class}#{$id} {...}";
        }

        $path[] = $id;
        $lines = [];
        $indent = str_repeat('  ', $depth + 1);
        foreach ($properties as $key => $item) {
            $parts = explode("\0", (string) $key);
            $name = $parts[count($parts) - 1];
            $visibility = count($parts) === 1 ? '+' : ($parts[1] === '*' ? '#' : '-');
            $lines[] = $indent.$visibility.$name.': '.(self::isSensitive($name) ? self::MASK : self::render($item, $depth + 1, $maxDepth, $path));
        }

        return "{$class}#{$id} {\n".implode("\n", $lines)."\n".str_repeat('  ', $depth).'}';
    }

    private static function isSensitive(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists(__NAMESPACE__.'\dump')) {
    /**
     * Shortcut for Debug::dump().
     *
     * @throws LogicException If Debug is not enabled.
     */
    function dump(mixed ...$values): void
    {
        Debug::dump(...$values);
    }
}

if (! function_exists(__NAMESPACE__.'\dd')) {
    /**
     * Shortcut for Debug::dd().
     *
     * @throws LogicException If Debug is not enabled.
     */
    function dd(mixed ...$values): never
    {
        Debug::dd(...$values);
    }
}
