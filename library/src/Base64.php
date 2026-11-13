<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use UnexpectedValueException;

/**
 * Base64 Class
 *
 * Base64 (standard and URL-safe) and hexadecimal encoding/decoding with strict validation.
 *
 * Natively, base64_decode() without $strict accepts any text and returns corrupted data, while
 * hex2bin() returns false with a warning. Here, invalid data throws an exception.
 *
 * decode() accepts whitespace and newlines (MIME Base64 split across lines) as well as omitted "=" padding.
 * Error messages include the data length rather than the raw data itself (which may contain tokens or keys).
 *
 * Usage:
 *   require_once 'Base64.php';
 *   use Foundry\Base64;
 *
 *   $pdf = Base64::decode($request['document']);      // throws an exception if not valid Base64
 *   $token = Base64::encodeUrl(random_bytes(32));     // for URLs and JWTs: without + / =
 *   $hash = Base64::fromHex('a1b2c3');
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
final class Base64
{
    private function __construct() {}

    /**
     * Encodes data in standard Base64 (RFC 4648) with padding.
     */
    public static function encode(string $data): string
    {
        return base64_encode($data);
    }

    /**
     * Decodes standard Base64. Whitespace, newlines, and missing padding are accepted.
     *
     * @throws UnexpectedValueException If the string contains characters outside the Base64 alphabet
     *                                  or invalid padding.
     */
    public static function decode(string $encoded): string
    {
        return self::strictDecode($encoded)
            ?? throw new UnexpectedValueException(sprintf('Base64::decode(): input is not valid Base64 (%d bytes).', strlen($encoded)));
    }

    /**
     * Checks whether the string is valid Base64 without throwing an exception.
     */
    public static function isValid(string $encoded): bool
    {
        return self::strictDecode($encoded) !== null;
    }

    /**
     * base64_decode() in strict mode, plus a canonical-form check: unused trailing bits must be zero, so each
     * value has exactly one encoding ('YR==' and 'YQ==' would otherwise both decode to 'a').
     */
    private static function strictDecode(string $encoded): ?string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return null;
        }

        $normalized = rtrim((string) preg_replace('/\s+/', '', $encoded), '=');

        return rtrim(base64_encode($decoded), '=') === $normalized ? $decoded : null;
    }

    /**
     * Encodes data in URL-safe Base64 (RFC 4648 section 5): "-" and "_" instead of "+" and "/", without "=".
     * Suitable for URLs, filenames, and JWTs.
     */
    public static function encodeUrl(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodes URL-safe Base64. The "=" padding is optional.
     *
     * @throws UnexpectedValueException If the string is not valid URL-safe Base64
     *                                  (including if it uses the standard alphabet with "+" or "/").
     */
    public static function decodeUrl(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/D', $encoded) !== 1 || strlen(rtrim($encoded, '=')) % 4 === 1) {
            throw new UnexpectedValueException(sprintf('Base64::decodeUrl(): input is not valid URL-safe Base64 (%d bytes).', strlen($encoded)));
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        // Canonical form only: unused trailing bits must be zero (one encoding per value).
        if ($decoded === false || self::encodeUrl($decoded) !== rtrim($encoded, '=')) {
            throw new UnexpectedValueException(sprintf('Base64::decodeUrl(): input is not valid URL-safe Base64 (%d bytes).', strlen($encoded)));
        }

        return $decoded;
    }

    /**
     * Encodes data as lowercase hexadecimal text.
     */
    public static function toHex(string $data): string
    {
        return bin2hex($data);
    }

    /**
     * Decodes hexadecimal text (uppercase or lowercase).
     *
     * @throws UnexpectedValueException If the string has an odd length or contains non-hexadecimal characters.
     */
    public static function fromHex(string $hex): string
    {
        [$decoded, $warning] = self::capture(static fn (): string|false => hex2bin($hex));

        if ($decoded === false) {
            $reason = $warning !== null && str_contains($warning, 'even length')
                ? 'odd length'
                : 'non-hexadecimal characters';

            throw new UnexpectedValueException(sprintf('Base64::fromHex(): input is not valid hexadecimal: %s (%d bytes).', $reason, strlen($hex)));
        }

        return $decoded;
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
