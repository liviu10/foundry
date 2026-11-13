<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Encoding Class
 *
 * Character encoding conversions, with a focus on Romanian text from legacy systems
 * (Excel, CSV exported from Windows, Latin2 databases).
 *
 * Natively, iconv() returns false with a notice on illegal characters, while mb_convert_encoding()
 * silently replaces invalid characters with "?". In addition, mbstring does not recognize Windows-1250,
 * which is why conversions are performed using iconv.
 *
 * About Romanian diacritics:
 *   - the correct form uses a comma: s, t with comma below (U+0219, U+021B, U+0218, U+021A);
 *   - legacy systems use the cedilla form (U+015F, U+0163, U+015E, U+0162);
 *   - Windows-1250 and ISO-8859-2 contain ONLY the cedilla form; therefore fromUtf8() to these
 *     encodings automatically converts comma to cedilla.
 *
 * An 8-bit encoding cannot be reliably detected (the same bytes are valid across many encodings);
 * therefore detect() is not provided, but ensureUtf8() with an explicitly declared fallback encoding.
 *
 * Usage:
 *   require_once 'Encoding.php';
 *   use Foundry\Encoding;
 *
 *   $text = Encoding::ensureUtf8(File::read('import.csv'));        // UTF-8 or Windows-1250 -> UTF-8
 *   $text = Encoding::fixRomanianDiacritics($text);                // cedilla -> comma
 *   $legacy = Encoding::fromUtf8($text, 'Windows-1250');           // for a legacy system
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
final class Encoding
{
    /** @var string UTF-8 BOM. */
    public const string BOM = "\xEF\xBB\xBF";

    /** @var array<string, string> Diacritics with cedilla -> correct form with comma. */
    private const array CEDILLA_TO_COMMA = [
        "\u{015F}" => "\u{0219}", // s
        "\u{0163}" => "\u{021B}", // t
        "\u{015E}" => "\u{0218}", // S
        "\u{0162}" => "\u{021A}", // T
    ];

    /** @var list<string> Normalized encodings that only have the cedilla form. */
    private const array CEDILLA_ONLY = ['CP1250', 'WINDOWS1250', 'ISO88592', 'LATIN2'];

    private function __construct() {}

    /**
     * Checks whether the string is valid UTF-8.
     */
    public static function isUtf8(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8');
    }

    /**
     * Throws an exception if the string is not valid UTF-8.
     *
     * @throws UnexpectedValueException If the string contains invalid UTF-8 sequences.
     */
    public static function assertUtf8(string $text): void
    {
        if (! self::isUtf8($text)) {
            throw new UnexpectedValueException(
                sprintf('Encoding::assertUtf8(): text is not valid UTF-8 (invalid byte at offset %d).', self::invalidOffset($text)),
            );
        }
    }

    /**
     * Converts text from the $from encoding to UTF-8.
     *
     * @param  string  $text  The text to convert.
     * @param  string  $from  The source encoding (e.g. 'Windows-1250', 'ISO-8859-2', 'UTF-16LE').
     *
     * @throws InvalidArgumentException If the encoding is not supported.
     * @throws UnexpectedValueException If the text contains invalid bytes for $from.
     */
    public static function toUtf8(string $text, string $from): string
    {
        if (self::normalize($from) === 'UTF8') {
            self::assertUtf8($text);

            return $text;
        }

        return self::convert('toUtf8', $text, $from, 'UTF-8');
    }

    /**
     * Returns the text in UTF-8: unchanged if already valid UTF-8, otherwise converted from $fallback.
     * Suitable for imported files whose encoding is not known in advance.
     *
     * @param  string  $text  The text.
     * @param  string  $fallback  The assumed encoding if the text is not UTF-8.
     *
     * @throws InvalidArgumentException If $fallback is not supported.
     * @throws UnexpectedValueException If the text is not valid in $fallback either.
     */
    public static function ensureUtf8(string $text, string $fallback = 'Windows-1250'): string
    {
        return self::isUtf8($text) ? $text : self::convert('ensureUtf8', $text, $fallback, 'UTF-8');
    }

    /**
     * Converts UTF-8 text to another encoding.
     * For Windows-1250 / ISO-8859-2, diacritics with comma are first converted to cedilla.
     *
     * @param  string  $text  UTF-8 text.
     * @param  string  $to  The target encoding.
     *
     * @throws InvalidArgumentException If the encoding is not supported.
     * @throws UnexpectedValueException If the text is not valid UTF-8 or contains characters
     *                                  that do not exist in the target encoding.
     */
    public static function fromUtf8(string $text, string $to): string
    {
        self::assertUtf8($text);

        if (self::normalize($to) === 'UTF8') {
            return $text;
        }

        if (in_array(self::normalize($to), self::CEDILLA_ONLY, true)) {
            $text = self::legacyRomanianDiacritics($text);
        }

        return self::convert('fromUtf8', $text, 'UTF-8', $to);
    }

    /**
     * Replaces diacritics with cedilla (legacy form) with the correct form with comma.
     * The text must be UTF-8.
     */
    public static function fixRomanianDiacritics(string $text): string
    {
        return strtr($text, self::CEDILLA_TO_COMMA);
    }

    /**
     * Replaces diacritics with comma with the cedilla form, for systems that do not recognize
     * the correct form. The text must be UTF-8.
     */
    public static function legacyRomanianDiacritics(string $text): string
    {
        return strtr($text, array_flip(self::CEDILLA_TO_COMMA));
    }

    /**
     * Removes the UTF-8 BOM from the beginning of the text, if present.
     */
    public static function stripBom(string $text): string
    {
        return str_starts_with($text, self::BOM) ? substr($text, strlen(self::BOM)) : $text;
    }

    /**
     * Performs the actual conversion with iconv, converting errors to exceptions.
     *
     * @throws InvalidArgumentException If an encoding is not supported.
     * @throws UnexpectedValueException If the text contains invalid or unrepresentable characters.
     */
    private static function convert(string $method, string $text, string $from, string $to): string
    {
        if ($text === '') {
            self::assertSupported($method, $from);
            self::assertSupported($method, $to);

            return '';
        }

        [$result, $warning] = self::capture(static fn (): string|false => iconv($from, $to, $text));

        if ($result !== false) {
            return $result;
        }

        if ($warning !== null && str_contains($warning, 'Wrong encoding')) {
            throw new InvalidArgumentException("Encoding::{$method}(): unsupported conversion from '{$from}' to '{$to}'.");
        }

        $reason = $to === 'UTF-8'
            ? "input contains bytes that are not valid {$from}"
            : "input contains characters that cannot be represented in {$to}";

        throw new UnexpectedValueException("Encoding::{$method}(): {$reason}.");
    }

    /**
     * @throws InvalidArgumentException If iconv does not support the encoding.
     */
    private static function assertSupported(string $method, string $encoding): void
    {
        [$result] = self::capture(static fn (): string|false => iconv($encoding, 'UTF-8', 'a'));

        if ($result === false) {
            throw new InvalidArgumentException("Encoding::{$method}(): unsupported encoding '{$encoding}'.");
        }
    }

    /**
     * Normalizes the encoding name to ignore formatting differences: 'Windows-1250' -> 'WINDOWS1250', 'utf-8' -> 'UTF8'.
     */
    private static function normalize(string $encoding): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $encoding));
    }

    /**
     * The offset (in bytes) of the first invalid UTF-8 sequence.
     */
    private static function invalidOffset(string $text): int
    {
        // Lungimea celui mai lung prefix format din secvente UTF-8 valide (RFC 3629).
        // Fara flag-ul /u: tiparul lucreaza pe octeti. Repetitia posesiva evita backtracking-ul.
        $validPrefix = '/^(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            .'|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

        return preg_match($validPrefix, $text, $match) === 1 ? strlen($match[0]) : 0;
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
