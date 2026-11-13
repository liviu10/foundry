<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Base64;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class Base64Test extends TestCase
{
    // Octeti care produc "+" si "/" in Base64 standard.
    private const string BINARY = "\xFB\xEF\xBE\xFF\xFF";

    public function test_encode_decode_round_trip(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'hello', self::BINARY, random_bytes(100)] as $data) {
            $this->assertSame($data, Base64::decode(Base64::encode($data)));
        }
    }

    public function test_decode_accepts_whitespace_and_missing_padding(): void
    {
        $this->assertSame('hello', Base64::decode("aGVs\r\nbG8="));
        $this->assertSame('hello', Base64::decode('aGVsbG8'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBase64Provider(): iterable
    {
        yield 'caracter invalid' => ['aGV*bG8='];
        yield 'padding in exces' => ['aGVsbG8=='];
        yield 'doar padding' => ['a==='];
        yield 'url-safe in decode standard' => ['-_-_'];
        yield 'text oarecare' => ['acesta nu este base64!'];
    }

    #[DataProvider('invalidBase64Provider')]
    public function test_decode_rejects_invalid_input(string $encoded): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Base64::decode(): input is not valid Base64');

        Base64::decode($encoded);
    }

    public function test_decode_error_does_not_contain_input(): void
    {
        try {
            Base64::decode('secret*token');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('(12 bytes)', $e->getMessage());
        }
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Base64::isValid('aGVsbG8='));
        $this->assertTrue(Base64::isValid(''));
        $this->assertFalse(Base64::isValid('aGV*'));
    }

    // URL-safe

    public function test_encode_url_uses_url_safe_alphabet_without_padding(): void
    {
        $this->assertMatchesRegularExpression('#[+/]#', Base64::encode(self::BINARY), 'premisa: Base64 standard contine + sau /');

        $encoded = Base64::encodeUrl(self::BINARY);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
        $this->assertSame(self::BINARY, Base64::decodeUrl($encoded));
    }

    public function test_decode_url_round_trip_and_optional_padding(): void
    {
        foreach (['', 'a', 'ab', 'abc', random_bytes(33)] as $data) {
            $encoded = Base64::encodeUrl($data);

            $this->assertSame($data, Base64::decodeUrl($encoded));
            $this->assertSame($data, Base64::decodeUrl($encoded.str_repeat('=', (4 - strlen($encoded) % 4) % 4)));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'alfabet standard +' => ['ab+d'];
        yield 'alfabet standard /' => ['ab/d'];
        yield 'lungime imposibila' => ['abcde'];
        yield 'spatiu' => ['ab cd'];
        yield 'padding in mijloc' => ['ab=cd'];
    }

    #[DataProvider('invalidUrlProvider')]
    public function test_decode_url_rejects_invalid_input(string $encoded): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Base64::decodeUrl()');

        Base64::decodeUrl($encoded);
    }

    // hex

    public function test_hex_round_trip(): void
    {
        $this->assertSame('a1b2c3', Base64::toHex("\xA1\xB2\xC3"));
        $this->assertSame("\xA1\xB2\xC3", Base64::fromHex('a1b2c3'));
        $this->assertSame("\xA1\xB2\xC3", Base64::fromHex('A1B2C3'));
        $this->assertSame('', Base64::fromHex(''));
    }

    public function test_from_hex_odd_length(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('odd length (3 bytes)');

        Base64::fromHex('abc');
    }

    public function test_from_hex_invalid_characters(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('non-hexadecimal characters');

        Base64::fromHex('zz');
    }

    public function test_only_the_canonical_encoding_is_accepted(): void
    {
        $this->assertSame('a', Base64::decode('YQ=='));
        $this->assertFalse(Base64::isValid('YR=='), "'YR==' decodeaza tot la 'a', dar nu este forma canonica");
        $this->assertSame('a', Base64::decodeUrl('YQ'));

        $this->expectException(UnexpectedValueException::class);

        Base64::decodeUrl('YR');
    }

    public function test_decode_url_rejects_trailing_new_line(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Base64::decodeUrl("YQ\n");
    }

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Base64::fromHex('xyz');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
