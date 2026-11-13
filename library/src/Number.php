<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Number Class
 *
 * Number formatting and parsing in Romanian/European format (1.234,50), without setlocale().
 *
 * setlocale() modifies global process state, depends on locales installed on the host OS,
 * and returns false if the locale is absent; number_format() cannot parse back what it
 * produced. Here, delimiters default to ',' (decimal) and '.' (thousands), and are configurable.
 *
 * Numbers provided as strings (e.g. DECIMAL columns from a database) are rounded at the digit level
 * without converting to float, preventing precision loss. Rounding is half-up
 * (2,345 -> 2,35; -2,345 -> -2,35).
 *
 * Parsing is strict: '1.5' in Romanian format throws an error (the thousands separator must
 * group digits into triplets), rather than producing 15 or 1,5.
 *
 * For monetary calculations, use Money (exact calculations with bcmath).
 *
 * Usage:
 *   require_once 'Number.php';
 *   use Foundry\Number;
 *
 *   echo Number::format(1234.5);              // '1.234,50'
 *   echo Number::format('1234567.891', 1);    // '1.234.567,9'
 *   $value = Number::parse('1.234,50');       // 1234.5
 *   $db = Number::normalize('1.234,50');      // '1234.50' (for DECIMAL or Money)
 *   echo Number::fileSize(2516582);           // '2,4 MB'
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Number
{
    /** @var list<string> Units for fileSize() (multiples of 1024). */
    private const array SIZE_UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    /** @var string A plain decimal number: -1234.56 (no exponent, no '+', no whitespace). */
    private const string PLAIN_PATTERN = '/^-?\d+(?:\.\d+)?$/D';

    private function __construct() {}

    /**
     * Formats a number: 1234.5 -> '1.234,50'.
     *
     * @param  int|float|string  $number  String input must be a plain number: '1234.56'.
     *
     * @throws InvalidArgumentException If the number is invalid (NAN, INF, '1e3', '12abc') or $decimals < 0.
     */
    public static function format(int|float|string $number, int $decimals = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        return self::group(self::plain('format', $number, $decimals), $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Formats a percentage: 12.5 -> '12,5%' (with $decimals = 1). The value is already in percent form.
     *
     * @throws InvalidArgumentException If the number is invalid or $decimals < 0.
     */
    public static function percent(int|float|string $value, int $decimals = 0, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        return self::group(self::plain('percent', $value, $decimals), $decimalSeparator, $thousandsSeparator).'%';
    }

    /**
     * Formats human-readable file size in multiples of 1024: 2516582 -> '2,4 MB'; below 1 KB without decimals: '512 B'.
     *
     * @throws InvalidArgumentException If $bytes is negative or $decimals < 0.
     */
    public static function fileSize(int $bytes, int $decimals = 1, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException("Number::fileSize(): size must be >= 0, got {$bytes}.");
        }

        $value = (float) $bytes;
        $unit = 0;
        $last = count(self::SIZE_UNITS) - 1;

        // Trecerea la unitatea urmatoare dupa rotunjire: 1048575 B este '1,0 MB', nu '1.024,0 KB'.
        while ($unit < $last && ($unit === 0 ? $value >= 1024 : round($value, $decimals) >= 1024)) {
            $value /= 1024;
            $unit++;
        }

        if ($unit === 0) {
            return self::group((string) $bytes, $decimalSeparator, $thousandsSeparator).' B';
        }

        return self::format($value, $decimals, $decimalSeparator, $thousandsSeparator).' '.self::SIZE_UNITS[$unit];
    }

    /**
     * Parses a formatted number: '1.234,50' -> 1234.5. The thousands separator is optional ('1234,50').
     *
     * @throws InvalidArgumentException If separators are invalid.
     * @throws UnexpectedValueException If the string is not a valid number in the given format.
     */
    public static function parse(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): float
    {
        $result = (float) self::toPlain('parse', $value, $decimalSeparator, $thousandsSeparator);

        if (! is_finite($result)) {
            throw new UnexpectedValueException('Number::parse(): value is too large for a float.');
        }

        return $result;
    }

    /**
     * Parses a formatted integer: '1.234' -> 1234. Decimals ('12,00') throw an exception.
     *
     * @throws InvalidArgumentException If separators are invalid.
     * @throws UnexpectedValueException If the string is not an integer in the given format or exceeds PHP_INT_MAX.
     */
    public static function parseInt(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): int
    {
        $plain = self::toPlain('parseInt', $value, $decimalSeparator, $thousandsSeparator);

        if (str_contains($plain, '.')) {
            throw new UnexpectedValueException('Number::parseInt(): value has decimals.');
        }

        $result = (int) $plain;
        if ((string) $result !== $plain) {
            throw new UnexpectedValueException('Number::parseInt(): value is out of the integer range.');
        }

        return $result;
    }

    /**
     * Converts a formatted number into its plain numeric representation without precision loss: '1.234,50' -> '1234.50'.
     * Suitable for database DECIMAL columns and Money inputs.
     *
     * @throws InvalidArgumentException If separators are invalid.
     * @throws UnexpectedValueException If the string is not a valid number in the given format.
     */
    public static function normalize(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        return self::toPlain('normalize', $value, $decimalSeparator, $thousandsSeparator);
    }

    /**
     * Converts formatted text to a plain number ('-1234.50'). Leading zeros are stripped.
     *
     * @throws InvalidArgumentException If separators are invalid.
     * @throws UnexpectedValueException If the string is not a valid number in the given format.
     */
    private static function toPlain(string $method, string $value, string $decimalSeparator, string $thousandsSeparator): string
    {
        if ($decimalSeparator === '' || $decimalSeparator === $thousandsSeparator) {
            throw new InvalidArgumentException("Number::{$method}(): the decimal separator must be non-empty and different from the thousands separator.");
        }

        $value = trim($value);
        $decimal = preg_quote($decimalSeparator, '/');
        $integer = $thousandsSeparator === ''
            ? '\d+'
            // the first group of a grouped number must contain a non-zero digit: '0.500' is not 500
            : '(?:(?=\d{0,2}[1-9])\d{1,3}(?:'.preg_quote($thousandsSeparator, '/').'\d{3})+|\d+)';

        if (preg_match('/^(-?)('.$integer.')(?:'.$decimal.'(\d+))?$/D', $value, $match) !== 1) {
            throw new UnexpectedValueException(sprintf(
                "Number::%s(): value is not a number in the format '1%s234%s56' (length: %d).",
                $method,
                $thousandsSeparator,
                $decimalSeparator,
                strlen($value),
            ));
        }

        $digits = ltrim(str_replace($thousandsSeparator, '', $match[2]), '0');
        $plain = ($digits === '' ? '0' : $digits).(isset($match[3]) ? '.'.$match[3] : '');

        return self::isZero($plain) ? $plain : $match[1].$plain;
    }

    /**
     * Formats the number as a plain string rounded to $decimals: '-1234.50'.
     *
     * @throws InvalidArgumentException If the number is invalid or $decimals < 0.
     */
    private static function plain(string $method, int|float|string $number, int $decimals): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException("Number::{$method}(): decimals must be >= 0, got {$decimals}.");
        }

        if (is_float($number)) {
            if (! is_finite($number)) {
                throw new InvalidArgumentException("Number::{$method}(): number must be finite.");
            }
            $plain = number_format($number, $decimals, '.', '');
        } elseif (is_int($number)) {
            $plain = $decimals > 0 ? $number.'.'.str_repeat('0', $decimals) : (string) $number;
        } else {
            if (preg_match(self::PLAIN_PATTERN, $number) !== 1) {
                throw new InvalidArgumentException("Number::{$method}(): string is not a plain number like '-1234.56' (length: ".strlen($number).').');
            }
            $plain = self::roundString($number, $decimals);
        }

        // Fara '-0,00'.
        return self::isZero($plain) ? ltrim($plain, '-') : $plain;
    }

    /**
     * Performs half-up rounding at digit level without converting to float: '2.345' -> '2.35', '-0.995' -> '-1.00'.
     */
    private static function roundString(string $number, int $decimals): string
    {
        $negative = str_starts_with($number, '-');
        [$integer, $fraction] = explode('.', ltrim($number, '-').'.');

        $roundUp = strlen($fraction) > $decimals && $fraction[$decimals] >= '5';
        $digits = $integer.str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        if ($roundUp) {
            // Adunarea unei unitati pe ultima cifra, cu transport.
            $i = strlen($digits) - 1;
            while ($i >= 0 && $digits[$i] === '9') {
                $digits[$i--] = '0';
            }
            $digits = $i < 0 ? '1'.$digits : substr_replace($digits, (string) ((int) $digits[$i] + 1), $i, 1);
        }

        $integer = ltrim(substr($digits, 0, strlen($digits) - $decimals), '0');
        $result = ($integer === '' ? '0' : $integer).($decimals > 0 ? '.'.substr($digits, -$decimals) : '');

        return ($negative ? '-' : '').$result;
    }

    /**
     * Applies thousands and decimal separators to a plain number: '-1234.50' -> '-1.234,50'.
     */
    private static function group(string $plain, string $decimalSeparator, string $thousandsSeparator): string
    {
        $sign = str_starts_with($plain, '-') ? '-' : '';
        [$integer, $fraction] = explode('.', ltrim($plain, '-').'.');

        // Grupuri de cate 3 cifre de la dreapta; separatorul inversat de doua ori ramane corect.
        $grouped = strrev(implode(strrev($thousandsSeparator), str_split(strrev($integer), 3)));

        return $sign.$grouped.($fraction === '' ? '' : $decimalSeparator.$fraction);
    }

    private static function isZero(string $plain): bool
    {
        return trim($plain, '-0.') === '';
    }
}
