<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use Stringable;

/**
 * Cast Class
 *
 * Strict type casting for values originating externally (forms, CSV, APIs, databases).
 * Natively, (int) 'abc' = 0, intval('12abc') = 12, (float) '1.234,50' = 1.234, all without errors.
 * Here, any value that does not precisely represent the requested type throws an exception.
 *
 * Rules:
 *   - leading and trailing whitespace is ignored for strings;
 *   - decimals use a dot separator ("1.5"); for Romanian formatting ("1.234,50") see Number;
 *   - OrNull variants return null for null and empty (or whitespace-only) strings;
 *   - error messages include the value type, never the raw value.
 *
 * Usage:
 *   require_once 'Cast.php';
 *   use Foundry\Cast;
 *
 *   $id = Cast::toInt($_GET['id'] ?? null);          // "12" -> 12; "12abc", "", null -> exception
 *   $amount = Cast::toFloat($row['suma']);           // "150.5" -> 150.5
 *   $active = Cast::toBool($row['activ']);           // "da", "1", "true", "on" -> true
 *   $page = Cast::toIntOrNull($_GET['page'] ?? null) ?? 1;
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
final class Cast
{
    /** @var array<int|string, bool> String values accepted by toBool(); PHP converts '1'/'0' keys to integers. */
    private const array BOOLEANS = [
        'true' => true, '1' => true, 'yes' => true, 'on' => true, 'da' => true,
        'false' => false, '0' => false, 'no' => false, 'off' => false, 'nu' => false,
    ];

    /** @var string Decimal number with dot separator, optionally with exponent: 1, -1.5, .5, 1e3. */
    private const string FLOAT_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/';

    private function __construct() {}

    /**
     * Casts to int: int, float without fractional part (3.0), or numeric string ("12", "-5", "+5").
     *
     * @throws InvalidArgumentException If the value does not represent an exact integer.
     */
    public static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && floor($value) === $value
            // (float) PHP_INT_MAX rounds up to 2^63, which no longer fits: the upper bound is strict.
            && $value >= PHP_INT_MIN && $value < -(float) PHP_INT_MIN) {
            return (int) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            // filter_var: accepta "+5" si "-5", respinge "012" (zerouri in fata) si depasirile de PHP_INT_MAX.
            $int = filter_var($trimmed, FILTER_VALIDATE_INT);
            if ($int !== false) {
                return $int;
            }
        }

        throw self::invalid('toInt', 'an integer', $value);
    }

    /**
     * Casts to float: int, finite float, or numeric string with a dot separator ("1.5", "1e3").
     *
     * @throws InvalidArgumentException If the value is not a valid number (or is NAN/INF).
     */
    public static function toFloat(mixed $value): float
    {
        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value) && is_finite($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match(self::FLOAT_PATTERN, $trimmed) === 1) {
                $float = (float) $trimmed;
                if (is_finite($float)) {
                    return $float;
                }
            }
        }

        throw self::invalid('toFloat', 'a number (use "." as decimal separator)', $value);
    }

    /**
     * Casts to bool: bool, int 0/1, or one of the case-insensitive strings:
     * true/false, 1/0, yes/no, on/off, da/nu.
     *
     * @throws InvalidArgumentException For any other value (including empty string and null).
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return $value === 1;
        }

        if (is_string($value)) {
            $bool = self::BOOLEANS[strtolower(trim($value))] ?? null;
            if ($bool !== null) {
                return $bool;
            }
        }

        throw self::invalid('toBool', 'a boolean (true/false, 1/0, yes/no, on/off, da/nu)', $value);
    }

    /**
     * Casts to string: string, int, finite float, or Stringable object.
     * bool, null, array, and other objects are rejected (their conversion is ambiguous).
     *
     * @throws InvalidArgumentException If the value cannot be unambiguously converted.
     */
    public static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || (is_float($value) && is_finite($value)) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw self::invalid('toString', 'a string, a number or a Stringable object', $value);
    }

    /**
     * Same as toInt(), but null and empty string return null.
     *
     * @throws InvalidArgumentException If the value is non-empty and does not represent an integer.
     */
    public static function toIntOrNull(mixed $value): ?int
    {
        return self::isBlank($value) ? null : self::toInt($value);
    }

    /**
     * Same as toFloat(), but null and empty string return null.
     *
     * @throws InvalidArgumentException If the value is non-empty and is not a number.
     */
    public static function toFloatOrNull(mixed $value): ?float
    {
        return self::isBlank($value) ? null : self::toFloat($value);
    }

    /**
     * Same as toBool(), but null and empty string return null.
     *
     * @throws InvalidArgumentException If the value is non-empty and is not a recognized boolean representation.
     */
    public static function toBoolOrNull(mixed $value): ?bool
    {
        return self::isBlank($value) ? null : self::toBool($value);
    }

    /**
     * Same as toString(), but null and empty (or whitespace-only) string return null.
     *
     * @throws InvalidArgumentException If the value is non-empty and cannot be converted.
     */
    public static function toStringOrNull(mixed $value): ?string
    {
        return self::isBlank($value) ? null : self::toString($value);
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function invalid(string $method, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Cast::%s(): expected %s, got %s.', $method, $expected, self::describe($value)));
    }

    /**
     * Generates a description of the value for error messages: type and length for strings (not content).
     */
    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return $value === '' ? 'empty string' : 'string of '.strlen($value).' bytes';
        }

        if (is_float($value) && ! is_finite($value)) {
            return is_nan($value) ? 'NAN' : 'INF';
        }

        return get_debug_type($value);
    }
}
