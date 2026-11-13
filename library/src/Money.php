<?php

declare(strict_types=1);

namespace Foundry;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Money Class
 *
 * Exact monetary calculations on strings using bcmath. Floats are never used:
 * 0.1 + 0.2 in floating-point arithmetic is 0.30000000000000004, causing errors to accumulate in invoices and installments.
 *
 * Amounts are plain strings ('1234.56', '-10', '0.5') or integers. Floats are rejected via
 * type signatures. The result always has $scale decimals (default 2), rounded half-up
 * (2.345 -> 2.35; -2.345 -> -2.35). Rounding is performed once at the very end.
 *
 * Built-in bc* functions truncate instead of rounding and silently accepted invalid values in
 * older PHP versions; here each amount is strictly validated.
 *
 * Requires the bcmath extension (bundled with PHP on Windows; on Linux, the php-bcmath package).
 *
 * Usage:
 *   require_once 'Money.php';
 *   use Foundry\Money;
 *
 *   $total = Money::add('100.10', '0.20');                 // '100.30'
 *   $vat = Money::percent($total, '19');                   // '19.06'
 *   $rates = Money::allocate('100.00', 3);                 // ['33.34', '33.33', '33.33']
 *   $amount = Money::parse($_POST['suma']);                // '1.234,50' -> '1234.50'
 *   echo Money::format($total, 'lei');                     // '100,30 lei'
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
final class Money
{
    /** @var int Decimal scale for intermediate calculations (prior to final rounding). */
    private const int PRECISION = 20;

    /** @var string A valid monetary amount: -1234.56 (no exponent, no '+', no whitespace). */
    private const string AMOUNT_PATTERN = '/^-?\d+(?:\.\d+)?$/D';

    private function __construct() {}

    /**
     * $a + $b.
     *
     * @throws InvalidArgumentException If an amount is invalid.
     */
    public static function add(string|int $a, string|int $b, int $scale = 2): string
    {
        return self::finish('add', bcadd(self::amount('add', $a, 'argument #1'), self::amount('add', $b, 'argument #2'), self::PRECISION), $scale);
    }

    /**
     * $a - $b.
     *
     * @throws InvalidArgumentException If an amount is invalid.
     */
    public static function sub(string|int $a, string|int $b, int $scale = 2): string
    {
        return self::finish('sub', bcsub(self::amount('sub', $a, 'argument #1'), self::amount('sub', $b, 'argument #2'), self::PRECISION), $scale);
    }

    /**
     * $amount * $multiplier (e.g., quantity, exchange rate, coefficient).
     *
     * @throws InvalidArgumentException If a value is invalid.
     */
    public static function mul(string|int $amount, string|int $multiplier, int $scale = 2): string
    {
        return self::finish('mul', bcmul(self::amount('mul', $amount, 'argument #1'), self::amount('mul', $multiplier, 'argument #2'), self::PRECISION), $scale);
    }

    /**
     * $amount / $divisor.
     *
     * @throws InvalidArgumentException If a value is invalid or $divisor is zero.
     */
    public static function div(string|int $amount, string|int $divisor, int $scale = 2): string
    {
        $divisor = self::amount('div', $divisor, 'argument #2');

        if (bccomp($divisor, '0', self::PRECISION) === 0) {
            throw new InvalidArgumentException('Money::div(): division by zero.');
        }

        return self::finish('div', bcdiv(self::amount('div', $amount, 'argument #1'), $divisor, self::PRECISION), $scale);
    }

    /**
     * Calculates $percent percent of $amount: percent('200', '19') -> '38.00'.
     *
     * @throws InvalidArgumentException If a value is invalid.
     */
    public static function percent(string|int $amount, string|int $percent, int $scale = 2): string
    {
        $product = bcmul(self::amount('percent', $amount, 'argument #1'), self::amount('percent', $percent, 'argument #2'), self::PRECISION);

        return self::finish('percent', bcdiv($product, '100', self::PRECISION), $scale);
    }

    /**
     * Sum of a list of amounts. Empty list -> '0.00'.
     *
     * @param  list<string|int>  $amounts
     *
     * @throws InvalidArgumentException If an amount is invalid.
     */
    public static function sum(array $amounts, int $scale = 2): string
    {
        $total = '0';
        foreach ($amounts as $i => $amount) {
            $total = bcadd($total, self::amount('sum', $amount, 'item #'.$i), self::PRECISION);
        }

        return self::finish('sum', $total, $scale);
    }

    /**
     * Rounds an amount to $scale decimal places: '10.555' -> '10.56', '7' -> '7.00'.
     *
     * @return numeric-string
     *
     * @throws InvalidArgumentException If the amount is invalid.
     */
    public static function round(string|int $amount, int $scale = 2): string
    {
        return self::finish('round', self::amount('round', $amount, 'argument #1'), $scale);
    }

    /**
     * Allocates an amount into parts without losing or creating money: the sum of the parts exactly equals the total
     * (rounded to $scale). Any remainder units are distributed to the first non-zero weighted parts.
     *
     *   Money::allocate('100.00', 3)          -> ['33.34', '33.33', '33.33']
     *   Money::allocate('100.00', [70, 30])   -> ['70.00', '30.00']
     *   Money::allocate('0.05', [1, 1])       -> ['0.03', '0.02']
     *
     * @param  int|list<string|int>  $ratios  Number of equal parts or list of weights (>= 0, at least one > 0).
     * @return list<string>
     *
     * @throws InvalidArgumentException If the amount, number of parts, or ratios are invalid.
     */
    public static function allocate(string|int $amount, int|array $ratios, int $scale = 2): array
    {
        $total = self::round($amount, $scale);

        if (is_int($ratios)) {
            if ($ratios < 1) {
                throw new InvalidArgumentException("Money::allocate(): number of parts must be >= 1, got {$ratios}.");
            }
            $ratios = array_fill(0, $ratios, '1');
        }

        if ($ratios === []) {
            throw new InvalidArgumentException('Money::allocate(): ratios must not be empty.');
        }

        $weights = [];
        foreach ($ratios as $i => $ratio) {
            $weight = self::amount('allocate', $ratio, 'ratio #'.$i);
            if (bccomp($weight, '0', self::PRECISION) < 0) {
                throw new InvalidArgumentException("Money::allocate(): ratio #{$i} must be >= 0.");
            }
            $weights[] = $weight;
        }

        $weightSum = array_reduce($weights, static fn (string $carry, string $weight): string => bcadd($carry, $weight, self::PRECISION), '0');
        if (bccomp($weightSum, '0', self::PRECISION) === 0) {
            throw new InvalidArgumentException('Money::allocate(): at least one ratio must be greater than 0.');
        }

        // Fiecare parte trunchiata spre zero la $scale; restul se distribuie unitate cu unitate.
        $parts = [];
        $allocated = '0';
        foreach ($weights as $weight) {
            $part = bcdiv(bcmul($total, $weight, self::PRECISION), $weightSum, $scale);
            $parts[] = $part;
            $allocated = bcadd($allocated, $part, $scale);
        }

        $unit = bcpow('10', (string) -$scale, $scale);
        $negative = bccomp($total, '0', $scale) < 0;
        $remaining = (int) bcdiv(bcsub($total, $allocated, $scale), $unit, 0);

        for ($i = 0; $remaining !== 0; $i = ($i + 1) % count($parts)) {
            if (bccomp($weights[$i], '0', self::PRECISION) === 0) {
                continue;
            }
            $parts[$i] = $negative ? bcsub($parts[$i], $unit, $scale) : bcadd($parts[$i], $unit, $scale);
            $remaining += $negative ? 1 : -1;
        }

        return array_values(array_map(static fn (string $part): string => self::finish('allocate', $part, $scale), $parts));
    }

    /**
     * Compares two amounts: -1 ($a < $b), 0 (equal), 1 ($a > $b). '10' and '10.00' are equal.
     *
     * @throws InvalidArgumentException If an amount is invalid.
     */
    public static function compare(string|int $a, string|int $b): int
    {
        return bccomp(self::amount('compare', $a, 'argument #1'), self::amount('compare', $b, 'argument #2'), self::PRECISION);
    }

    /**
     * Formats an amount: '1234.5' -> '1.234,50'; with currency: '1.234,50 lei'.
     *
     * @throws InvalidArgumentException If the amount is invalid.
     */
    public static function format(string|int $amount, ?string $currency = null, int $scale = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        $rounded = self::round($amount, $scale);
        $sign = str_starts_with($rounded, '-') ? '-' : '';
        [$integer, $fraction] = explode('.', ltrim($rounded, '-').'.');

        // Grupuri de cate 3 cifre de la dreapta; separatorul inversat de doua ori ramane corect.
        $grouped = strrev(implode(strrev($thousandsSeparator), str_split(strrev($integer), 3)));
        $formatted = $sign.$grouped.($fraction === '' ? '' : $decimalSeparator.$fraction);

        return $currency === null ? $formatted : $formatted.' '.$currency;
    }

    /**
     * Parses a user-entered monetary amount: '1.234,50' or '1234,5' -> '1234.50'.
     * More decimals than $scale triggers an error ('12,345' is ambiguous: not silently rounded).
     *
     * @throws InvalidArgumentException If the separators are invalid.
     * @throws UnexpectedValueException If the string is not a valid amount.
     */
    public static function parse(string $value, int $scale = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        if ($decimalSeparator === '' || $decimalSeparator === $thousandsSeparator) {
            throw new InvalidArgumentException('Money::parse(): the decimal separator must be non-empty and different from the thousands separator.');
        }

        $value = trim($value);

        $integer = $thousandsSeparator === ''
            ? '\d+'
            // the first group of a grouped number must contain a non-zero digit: '0.500' is not 500
            : '(?:(?=\d{0,2}[1-9])\d{1,3}(?:'.preg_quote($thousandsSeparator, '/').'\d{3})+|\d+)';
        $pattern = '/^(-?)('.$integer.')(?:'.preg_quote($decimalSeparator, '/').'(\d+))?$/D';

        if (preg_match($pattern, $value, $match) !== 1) {
            throw new UnexpectedValueException(sprintf(
                "Money::parse(): value is not an amount in the format '1%s234%s56' (length: %d).",
                $thousandsSeparator,
                $decimalSeparator,
                strlen($value),
            ));
        }

        $fraction = $match[3] ?? '';
        if (strlen($fraction) > $scale) {
            throw new UnexpectedValueException("Money::parse(): amount has more than {$scale} decimals.");
        }

        return self::round($match[1].str_replace($thousandsSeparator, '', $match[2]).($fraction === '' ? '' : '.'.$fraction), $scale);
    }

    /**
     * Validates an amount and returns it as a string for bcmath.
     *
     * @param  string  $label  Value description for error messages ('argument #1', 'item #3').
     * @return numeric-string
     *
     * @throws InvalidArgumentException If the amount is invalid.
     * @throws RuntimeException If the bcmath extension is not loaded.
     */
    private static function amount(string $method, string|int $value, string $label): string
    {
        if (! extension_loaded('bcmath')) {
            throw new RuntimeException("Money::{$method}(): the bcmath extension is required.");
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // is_numeric() accepta si '1e3' sau ' 1'; tiparul le respinge.
        if (! is_numeric($value) || preg_match(self::AMOUNT_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "Money::{$method}(): {$label} is not a valid amount (expected a plain number like '-1234.56', length: ".strlen($value).').',
            );
        }

        return $value;
    }

    /**
     * Rounds half-up to $scale decimal places; avoids '-0.00'.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     *
     * @throws InvalidArgumentException If $scale is outside the 0 - 10 range.
     */
    private static function finish(string $method, string $value, int $scale): string
    {
        if ($scale < 0 || $scale > 10) {
            throw new InvalidArgumentException("Money::{$method}(): scale must be between 0 and 10, got {$scale}.");
        }

        // Jumatate de unitate adaugata (sau scazuta pentru negative), apoi trunchiere de catre bcmath.
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);
        $rounded = str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);

        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }
}
