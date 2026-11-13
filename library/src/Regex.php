<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Regex Class
 *
 * Wrappers over preg_* functions.
 * Natively, an invalid pattern emits only a warning and returns false/null, while runtime
 * execution errors (backtracking limit, invalid UTF-8) go completely unnoticed. Here:
 *   - invalid pattern    -> InvalidArgumentException (programming error)
 *   - execution error    -> RuntimeException (the message does not contain the analyzed text)
 *
 * Unmatched capture groups are returned as null (not empty strings).
 *
 * Usage:
 *   require_once 'Regex.php';
 *   use Foundry\Regex;
 *
 *   if (Regex::test('/^RO\d{2}[A-Z]{4}/', $iban)) { ... }
 *   $m = Regex::match('/^(?<judet>[A-Z]{1,2}) ?(?<numar>\d{2,3})/', 'B 123 ABC');  // $m['judet'] === 'B'
 *   $slug = Regex::replace('/[^a-z0-9]+/', '-', $text);
 *   $words = Regex::split('/\s+/', $text, removeEmpty: true);
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
final class Regex
{
    private function __construct() {}

    /**
     * Checks whether the pattern matches against the string.
     *
     * @param  string  $pattern  The regular expression, including delimiters (e.g. '/^\d+$/').
     * @param  string  $subject  The input string.
     * @return bool True if a match is found.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function test(string $pattern, string $subject): bool
    {
        return self::run('test', $pattern, static fn (): int|false => preg_match($pattern, $subject)) === 1;
    }

    /**
     * First match: [0 => full matched text, 1 => group 1, 'name' => named group, ...].
     *
     * @param  string  $pattern  The regular expression.
     * @param  string  $subject  The input string.
     * @return array<int|string, string|null>|null The matches or null if no match was found.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function match(string $pattern, string $subject): ?array
    {
        $matches = [];
        $found = self::run('match', $pattern, static function () use ($pattern, $subject, &$matches): int|false {
            return preg_match($pattern, $subject, $matches, PREG_UNMATCHED_AS_NULL);
        });

        return $found === 1 ? $matches : null;
    }

    /**
     * All matches, each with its captured groups (PREG_SET_ORDER).
     *
     * @param  string  $pattern  The regular expression.
     * @param  string  $subject  The input string.
     * @return list<array<int|string, string|null>> List of matches (empty if none found).
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function matchAll(string $pattern, string $subject): array
    {
        $matches = [];
        self::run('matchAll', $pattern, static function () use ($pattern, $subject, &$matches): int|false {
            return preg_match_all($pattern, $subject, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        });

        return $matches;
    }

    /**
     * Replaces matches with replacement text ($1, ${name} are allowed in replacement).
     *
     * @param  string  $pattern  The regular expression.
     * @param  string  $replacement  The replacement text.
     * @param  string  $subject  The input string.
     * @param  int  $limit  The maximum number of replacements; -1 = unlimited.
     * @return string The resulting string.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
    {
        return self::run('replace', $pattern, static fn (): ?string => preg_replace($pattern, $replacement, $subject, $limit));
    }

    /**
     * Replaces matches with the result of a callback function.
     *
     * @param  string  $pattern  The regular expression.
     * @param  Closure(array<int|string, string|null>): string  $callback  Receives matches and returns the replacement.
     * @param  string  $subject  The input string.
     * @param  int  $limit  The maximum number of replacements; -1 = unlimited.
     * @return string The resulting string.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function replaceCallback(string $pattern, Closure $callback, string $subject, int $limit = -1): string
    {
        return self::run(
            'replaceCallback',
            $pattern,
            static fn (): ?string => preg_replace_callback($pattern, $callback, $subject, $limit, $count, PREG_UNMATCHED_AS_NULL),
        );
    }

    /**
     * Splits a string by a pattern.
     *
     * @param  string  $pattern  The regular expression used as a separator.
     * @param  string  $subject  The string to split.
     * @param  int  $limit  The maximum number of substrings; -1 = unlimited.
     * @param  bool  $removeEmpty  True to remove empty substrings.
     * @return list<string> The substrings.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function split(string $pattern, string $subject, int $limit = -1, bool $removeEmpty = false): array
    {
        $flags = $removeEmpty ? PREG_SPLIT_NO_EMPTY : 0;

        return self::run('split', $pattern, static fn (): array|false => preg_split($pattern, $subject, $limit, $flags));
    }

    /**
     * Keeps only elements that match the pattern (keys are preserved).
     *
     * @param  string  $pattern  The regular expression.
     * @param  array<array-key, string>  $items  The elements to filter.
     * @param  bool  $invert  True to keep elements that do NOT match.
     * @return array<array-key, string> The kept elements.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    public static function filter(string $pattern, array $items, bool $invert = false): array
    {
        $flags = $invert ? PREG_GREP_INVERT : 0;

        $kept = self::run('filter', $pattern, static fn (): array|false => preg_grep($pattern, $items, $flags));

        // preg_grep() pastreaza cheile; rezultatul se ia din $items ca sa ramana tipizat.
        return array_intersect_key($items, $kept);
    }

    /**
     * Runs a preg_* function and converts failures into exceptions.
     *
     * @template T
     *
     * @param  string  $method  The public method name, for the exception message.
     * @param  string  $pattern  The pattern, for the exception message.
     * @param  Closure(): (T|false|null)  $operation  The preg_* call.
     * @return T The result, guaranteed not to be false/null.
     *
     * @throws InvalidArgumentException If the pattern is invalid.
     * @throws RuntimeException On execution error.
     */
    private static function run(string $method, string $pattern, Closure $operation): mixed
    {
        // Se capteaza doar warning-urile emise de preg_*. Cele din callback-ul lui
        // replaceCallback() (codul apelantului) merg mai departe la handler-ul anterior.
        $warning = null;
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use (&$warning, &$previous): bool {
            if (str_starts_with($message, 'preg_')) {
                $warning ??= $message;

                return true;
            }

            return is_callable($previous) && (bool) $previous($severity, $message, $file, $line);
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        if ($warning !== null) {
            // Ex: "preg_match(): Compilation failed: missing closing parenthesis at offset 3"
            $reason = preg_replace('/^\w+\(\):\s*/', '', $warning) ?? $warning;

            throw new InvalidArgumentException("Regex::{$method}(): invalid pattern (length: ".strlen($pattern)."): {$reason}.");
        }

        if ($result === false || $result === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new RuntimeException("Regex::{$method}(): matching failed (pattern length: ".strlen($pattern).'): '.preg_last_error_msg().'.');
        }

        return $result;
    }
}
