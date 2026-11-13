<?php

declare(strict_types=1);

namespace Foundry;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * RoValidator Class
 *
 * Validation of Romanian identifiers: CNP (Personal Numeric Code), CUI/CIF (Fiscal Code),
 * IBAN, phone numbers, postal codes, and identity card series and numbers.
 * Validation methods return bool (they do not throw exceptions for invalid data),
 * so they can be directly used as custom rules in Validator:
 *   'cnp' => ['required', 'cnp' => RoValidator::cnp(...)]
 *
 * Rules:
 *   - Leading and trailing whitespace is trimmed; CNP and CUI are also accepted as int;
 *   - Lowercase letters are accepted where values contain letters (IBAN, RO prefix, ID card series);
 *   - Exception messages do not disclose the sensitive value (e.g. CNP is personal data).
 *
 * Usage:
 *   require_once 'RoValidator.php';
 *   use Foundry\RoValidator;
 *
 *   RoValidator::cnp('1800101420010');              // control digit, birth date, county
 *   RoValidator::cnpInfo($cnp)['birthDate'];         // DateTimeImmutable
 *   RoValidator::cui('RO6291812');                   // with or without RO prefix
 *   RoValidator::iban('RO49 AAAA 1B31 0075 9384 0000');
 *   RoValidator::normalizePhone('0721 234 567');     // '+40721234567'
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
final class RoValidator
{
    /** @var string Weights for CNP check digit verification. */
    private const string CNP_WEIGHTS = '279146358279';

    /** @var string Weights for CUI check digit verification (right-aligned). */
    private const string CUI_WEIGHTS = '753217532';

    /** @var array<int, int> First digit of CNP -> birth century (7, 8, 9: residents and foreign citizens, undetermined century). */
    private const array CNP_CENTURY = [1 => 1900, 2 => 1900, 3 => 1800, 4 => 1800, 5 => 2000, 6 => 2000];

    /**
     * County codes in CNP: 01-46 counties and Bucharest sectors 1-6, 47-48 former sectors 7-8,
     * 51 Calarasi, 52 Giurgiu, 70 for all CNPs issued starting May 2024 (independent of county).
     */
    private const string CNP_COUNTY_PATTERN = '/^(0[1-9]|[1-3]\d|4[0-8]|5[12]|70)$/';

    private function __construct() {}

    /**
     * Validates a CNP: 13 digits, sex/century, valid birth date not in the future, county code,
     * serial number 001-999, and control digit.
     */
    public static function cnp(mixed $value): bool
    {
        return self::cnpProblem($value) === null;
    }

    /**
     * Extracts information from CNP. For leading digit 7, 8, or 9 the century is not encoded:
     * year 20xx is selected if not in the future, otherwise 19xx.
     *
     * @return array{birthDate: DateTimeImmutable, sex: 'M'|'F'|null, county: string, foreigner: bool}
     *                                                                                                 sex is null for leading digit 9 (foreign citizens).
     *
     * @throws InvalidArgumentException If the CNP is invalid (contains reason without disclosing the value).
     */
    public static function cnpInfo(string|int $cnp): array
    {
        $problem = self::cnpProblem($cnp);
        if ($problem !== null) {
            throw new InvalidArgumentException("RoValidator::cnpInfo(): invalid CNP: {$problem}.");
        }

        $digits = trim((string) $cnp);
        $first = (int) $digits[0];
        $birthDate = self::cnpBirthDate($digits);
        if (! $birthDate instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('RoValidator::cnpInfo(): invalid CNP: invalid birth date.');
        }

        return [
            'birthDate' => $birthDate,
            'sex' => $first === 9 ? null : ($first % 2 === 1 ? 'M' : 'F'),
            'county' => substr($digits, 7, 2),
            'foreigner' => $first >= 7,
        ];
    }

    /**
     * Validates a CUI / CIF, with or without RO prefix (VAT payer): 2-10 digits and check digit.
     */
    public static function cui(mixed $value): bool
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/^(?:RO\s?)?([1-9]\d{1,9})$/i', trim($value), $match) !== 1) {
            return false;
        }

        $digits = $match[1];
        $body = str_pad(substr($digits, 0, -1), 9, '0', STR_PAD_LEFT);

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $body[$i] * (int) self::CUI_WEIGHTS[$i];
        }

        return ($sum * 10 % 11) % 10 === (int) substr($digits, -1);
    }

    /**
     * Validates an IBAN (mod 97). By default Romanian only: RO + 2 check digits + 4 bank letters + 16 characters.
     * With $anyCountry, any country is accepted (general ISO 13616 structure, without country-specific length).
     * Whitespace between character groups is accepted.
     */
    public static function iban(mixed $value, bool $anyCountry = false): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $iban = strtoupper(str_replace(' ', '', trim($value)));
        $pattern = $anyCountry ? '/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/' : '/^RO\d{2}[A-Z]{4}[A-Z0-9]{16}$/';
        if (preg_match($pattern, $iban) !== 1) {
            return false;
        }

        // Tara si cifrele de control merg la final; literele devin numere (A = 10 ... Z = 35).
        $numeric = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        // Restul pe bucati, ca numarul sa nu depaseasca int.
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Validates a Romanian phone number, mobile (07...) or landline (02..., 03...), in 0..., +40..., or 0040... format.
     * Spaces, dots, dashes, and parentheses are accepted as delimiters.
     */
    public static function phone(mixed $value): bool
    {
        return self::nationalPhone($value) !== null;
    }

    /**
     * Same as phone(), but restricted to mobile numbers (07...).
     */
    public static function mobilePhone(mixed $value): bool
    {
        return str_starts_with(self::nationalPhone($value) ?? '', '7');
    }

    /**
     * Formats a phone number in international standard without separators: '0721 234 567' -> '+40721234567'.
     *
     * @throws InvalidArgumentException If the value is not a valid Romanian phone number.
     */
    public static function normalizePhone(string $value): string
    {
        $national = self::nationalPhone($value) ?? throw new InvalidArgumentException('RoValidator::normalizePhone(): value is not a valid Romanian phone number.');

        return '+40'.$national;
    }

    /**
     * Validates a Romanian postal code: 6 digits.
     */
    public static function postalCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{6}$/', trim($value)) === 1;
    }

    /**
     * Validates identity card series and number: 2 letters + 6 digits ('RX 123456' or 'RX123456').
     * Checks format validity, not whether the series was officially issued.
     */
    public static function identityCard(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Z]{2}\s?\d{6}$/i', trim($value)) === 1;
    }

    /**
     * Reason why CNP is invalid, or null if valid. Does not include the value.
     */
    private static function cnpProblem(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return 'expected a string of 13 digits, got '.get_debug_type($value);
        }

        $cnp = trim($value);
        if (preg_match('/^[1-9]\d{12}$/', $cnp) !== 1) {
            return 'expected 13 digits, the first one 1-9';
        }

        $birthDate = self::cnpBirthDate($cnp);
        if (! $birthDate instanceof DateTimeImmutable || $birthDate > new DateTimeImmutable('today')) {
            return 'invalid birth date';
        }

        if (preg_match(self::CNP_COUNTY_PATTERN, substr($cnp, 7, 2)) !== 1) {
            return 'invalid county code';
        }

        if (substr($cnp, 9, 3) === '000') {
            return 'invalid serial number';
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnp[$i] * (int) self::CNP_WEIGHTS[$i];
        }

        $control = $sum % 11;
        if (($control === 10 ? 1 : $control) !== (int) $cnp[12]) {
            return 'invalid control digit';
        }

        return null;
    }

    /**
     * Birth date from CNP (13 digits already verified), or null if not a real calendar date.
     */
    private static function cnpBirthDate(string $cnp): ?DateTimeImmutable
    {
        $yy = (int) substr($cnp, 1, 2);
        $month = (int) substr($cnp, 3, 2);
        $day = (int) substr($cnp, 5, 2);

        $century = self::CNP_CENTURY[(int) $cnp[0]] ?? null;
        if ($century !== null) {
            return self::date($century + $yy, $month, $day);
        }

        // Secol necodificat: 20xx daca data nu este in viitor, altfel 19xx.
        $recent = self::date(2000 + $yy, $month, $day);

        return $recent instanceof DateTimeImmutable && $recent <= new DateTimeImmutable('today') ? $recent : self::date(1900 + $yy, $month, $day);
    }

    private static function date(int $year, int $month, int $day): ?DateTimeImmutable
    {
        return checkdate($month, $day, $year) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)) : null;
    }

    /**
     * The 9 national digits (without 0 / +40 / 0040), or null if not a Romanian phone number.
     */
    private static function nationalPhone(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $phone = str_replace([' ', '.', '-', '(', ')'], '', trim($value));
        if (preg_match('/^(?:\+40|0040|0)([237]\d{8})$/', $phone, $match) !== 1) {
            return null;
        }

        return $match[1];
    }
}
