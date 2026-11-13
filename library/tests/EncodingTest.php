<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Encoding;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class EncodingTest extends TestCase
{
    // Text de test: cuvantul "Stiinta" cu S/t cu sedila si cu S/t cu virgula, scris prin escape.
    private const string CEDILLA = "\u{015E}tiin\u{0163}\u{0103}";

    private const string COMMA = "\u{0218}tiin\u{021B}\u{0103}";

    // a cu caciula, s cu sedila, t cu sedila in Windows-1250 (identic in ISO-8859-2): E3, BA, FE.
    private const string CP1250 = "\xE3\xBA\xFE";

    // isUtf8 / assertUtf8

    public function test_is_utf8(): void
    {
        $this->assertTrue(Encoding::isUtf8('ascii'));
        $this->assertTrue(Encoding::isUtf8(self::COMMA));
        $this->assertTrue(Encoding::isUtf8(''));
        $this->assertFalse(Encoding::isUtf8(self::CP1250));
        $this->assertFalse(Encoding::isUtf8("\xC3")); // caracter multibyte incomplet
        $this->assertFalse(Encoding::isUtf8("\xED\xA0\x80")); // surogat UTF-16, invalid in UTF-8
    }

    public function test_assert_utf8_reports_offset_of_first_invalid_byte(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid byte at offset 4');

        // "ab" + a cu caciula (2 bytes) + octet invalid
        Encoding::assertUtf8("ab\u{0103}\xFFcd");
    }

    public function test_assert_utf8_accepts_valid_text(): void
    {
        Encoding::assertUtf8(self::COMMA);

        $this->addToAssertionCount(1);
    }

    // toUtf8

    public function test_to_utf8_from_windows_1250(): void
    {
        $this->assertSame("\u{0103}\u{015F}\u{0163}", Encoding::toUtf8(self::CP1250, 'Windows-1250'));
        $this->assertSame("\u{0103}\u{015F}\u{0163}", Encoding::toUtf8(self::CP1250, 'CP1250'));
    }

    public function test_to_utf8_from_iso_8859_2(): void
    {
        $this->assertSame("\u{0103}\u{015F}\u{0163}", Encoding::toUtf8(self::CP1250, 'ISO-8859-2'));
    }

    public function test_to_utf8_from_utf8_validates_only(): void
    {
        $this->assertSame(self::COMMA, Encoding::toUtf8(self::COMMA, 'utf-8'));

        $this->expectException(UnexpectedValueException::class);

        Encoding::toUtf8(self::CP1250, 'UTF-8');
    }

    public function test_to_utf8_rejects_undefined_bytes(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('input contains bytes that are not valid Windows-1250');

        Encoding::toUtf8("abc\x81", 'Windows-1250'); // 0x81 nu este definit in Windows-1250
    }

    public function test_to_utf8_rejects_unknown_encoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unsupported conversion from 'NU-EXISTA' to 'UTF-8'");

        Encoding::toUtf8('abc', 'NU-EXISTA');
    }

    public function test_to_utf8_rejects_unknown_encoding_even_for_empty_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Encoding::toUtf8('', 'NU-EXISTA');
    }

    public function test_to_utf8_empty_text(): void
    {
        $this->assertSame('', Encoding::toUtf8('', 'Windows-1250'));
    }

    // ensureUtf8

    public function test_ensure_utf8_keeps_valid_utf8(): void
    {
        $this->assertSame(self::COMMA, Encoding::ensureUtf8(self::COMMA));
    }

    public function test_ensure_utf8_converts_from_fallback(): void
    {
        $this->assertSame("\u{0103}\u{015F}\u{0163}", Encoding::ensureUtf8(self::CP1250));
    }

    // fromUtf8

    public function test_from_utf8_to_windows_1250_converts_comma_to_cedilla(): void
    {
        $this->assertSame("\xAA\x74\x69\x69\x6E\xFE\xE3", Encoding::fromUtf8(self::COMMA, 'Windows-1250'));
    }

    public function test_round_trip_through_windows_1250(): void
    {
        $legacy = Encoding::fromUtf8(self::COMMA, 'Windows-1250');

        $this->assertSame(self::COMMA, Encoding::fixRomanianDiacritics(Encoding::toUtf8($legacy, 'Windows-1250')));
    }

    public function test_from_utf8_rejects_unrepresentable_characters(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('input contains characters that cannot be represented in Windows-1250');

        Encoding::fromUtf8("pre\u{0163} 100 \u{20B9}", 'Windows-1250'); // simbolul rupiei nu exista in CP1250
    }

    public function test_from_utf8_rejects_invalid_utf8_input(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Encoding::assertUtf8()');

        Encoding::fromUtf8(self::CP1250, 'Windows-1250');
    }

    public function test_from_utf8_to_utf8_is_noop(): void
    {
        $this->assertSame(self::COMMA, Encoding::fromUtf8(self::COMMA, 'UTF-8'));
    }

    public function test_from_utf8_to_utf16(): void
    {
        $this->assertSame("a\x00", Encoding::fromUtf8('a', 'UTF-16LE'));
    }

    // diacritice

    public function test_fix_romanian_diacritics(): void
    {
        $this->assertSame(self::COMMA, Encoding::fixRomanianDiacritics(self::CEDILLA));
        $this->assertSame(self::COMMA, Encoding::fixRomanianDiacritics(self::COMMA));
    }

    public function test_legacy_romanian_diacritics(): void
    {
        $this->assertSame(self::CEDILLA, Encoding::legacyRomanianDiacritics(self::COMMA));
    }

    // stripBom

    public function test_strip_bom(): void
    {
        $this->assertSame('abc', Encoding::stripBom("\xEF\xBB\xBFabc"));
        $this->assertSame('abc', Encoding::stripBom('abc'));
    }

    // integrare cu error handler-ul gazdei

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Encoding::toUtf8("\x81", 'Windows-1250');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
