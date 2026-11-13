<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Number;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class NumberTest extends TestCase
{
    // format

    /**
     * @return iterable<string, array{int|float|string, int, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'float' => [1234.5, 2, '1.234,50'];
        yield 'int' => [1234567, 2, '1.234.567,00'];
        yield 'int fara zecimale' => [1234567, 0, '1.234.567'];
        yield 'sub o mie' => [999, 0, '999'];
        yield 'zero' => [0, 2, '0,00'];
        yield 'negativ' => [-1234.5, 2, '-1.234,50'];
        yield 'float half up' => [1.005, 2, '1,01'];
        yield 'float negativ half up' => [-1.005, 2, '-1,01'];
        yield 'string' => ['1234567.891', 1, '1.234.567,9'];
        yield 'string half up' => ['2.345', 2, '2,35'];
        yield 'string negativ half up' => ['-2.345', 2, '-2,35'];
        yield 'string cu transport' => ['999.995', 2, '1.000,00'];
        yield 'string completat cu zerouri' => ['12.5', 3, '12,500'];
        yield 'string zerouri in fata' => ['0001234.5', 2, '1.234,50'];
        yield 'string mai mare decat float' => ['12345678901234567890.123', 2, '12.345.678.901.234.567.890,12'];
        yield 'fara minus zero' => ['-0.004', 2, '0,00'];
        yield 'fara minus zero float' => [-0.004, 2, '0,00'];
    }

    #[DataProvider('formatProvider')]
    public function test_format(int|float|string $number, int $decimals, string $expected): void
    {
        $this->assertSame($expected, Number::format($number, $decimals));
    }

    public function test_format_custom_separators(): void
    {
        $this->assertSame('1,234.50', Number::format(1234.5, 2, '.', ','));
        $this->assertSame('1 234,50', Number::format(1234.5, 2, ',', ' '));
        $this->assertSame('1234,50', Number::format(1234.5, 2, ',', ''));
    }

    /**
     * @return iterable<string, array{int|float|string}>
     */
    public static function invalidNumberProvider(): iterable
    {
        yield 'NAN' => [NAN];
        yield 'INF' => [INF];
        yield 'exponent' => ['1e3'];
        yield 'text' => ['12abc'];
        yield 'virgula' => ['1,5'];
        yield 'gol' => [''];
        yield 'plus' => ['+1'];
        yield 'spatii' => [' 1'];
        yield 'punct final' => ['1.'];
        yield 'linie noua la final' => ["12\n"];
    }

    #[DataProvider('invalidNumberProvider')]
    public function test_format_rejects_invalid_numbers(int|float|string $number): void
    {
        $this->expectException(InvalidArgumentException::class);

        Number::format($number);
    }

    public function test_format_rejects_negative_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Number::format(): decimals must be >= 0, got -1.');

        Number::format(1, -1);
    }

    public function test_percent(): void
    {
        $this->assertSame('13%', Number::percent(12.5));
        $this->assertSame('12,5%', Number::percent(12.5, 1));
        $this->assertSame('1.250,00%', Number::percent('1250', 2));
    }

    // fileSize

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function fileSizeProvider(): iterable
    {
        yield 'zero' => [0, '0 B'];
        yield 'bytes' => [512, '512 B'];
        yield 'sub 1 KB' => [1023, '1.023 B'];
        yield '1 KB' => [1024, '1,0 KB'];
        yield 'MB' => [2516582, '2,4 MB'];
        yield 'rotunjire spre unitatea urmatoare' => [1048575, '1,0 MB'];
        yield 'GB' => [5 * 1024 ** 3, '5,0 GB'];
        yield 'maxim PB' => [PHP_INT_MAX, '8.192,0 PB'];
    }

    #[DataProvider('fileSizeProvider')]
    public function test_file_size(int $bytes, string $expected): void
    {
        $this->assertSame($expected, Number::fileSize($bytes));
    }

    public function test_file_size_decimals_and_negative(): void
    {
        $this->assertSame('2,40 MB', Number::fileSize(2516582, 2));
        $this->assertSame('2 MB', Number::fileSize(2516582, 0));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Number::fileSize(): size must be >= 0, got -1.');

        Number::fileSize(-1);
    }

    // parse / normalize

    public function test_parse(): void
    {
        $this->assertEqualsWithDelta(1234.5, Number::parse('1.234,50'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1234.5, Number::parse('1234,5'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(-0.5, Number::parse('-0,5'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1234567.0, Number::parse(' 1.234.567 '), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1234.5, Number::parse('1,234.5', '.', ','), PHP_FLOAT_EPSILON);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidFormattedProvider(): iterable
    {
        yield 'punct zecimal in format RO' => ['1.5'];
        yield 'grup incomplet' => ['1.23,4'];
        yield 'grup prea lung' => ['1.2345'];
        yield 'punct zecimal cu 3 zecimale (nu 500)' => ['0.500'];
        yield 'grup de mii care incepe cu zerouri' => ['00.123'];
        yield 'doua virgule' => ['1,2,3'];
        yield 'separator la final' => ['12,'];
        yield 'separator la inceput' => [',5'];
        yield 'text' => ['abc'];
        yield 'gol' => [''];
        yield 'exponent' => ['1e3'];
        yield 'plus' => ['+5'];
    }

    #[DataProvider('invalidFormattedProvider')]
    public function test_parse_is_strict(string $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Number::parse(): value is not a number in the format '1.234,56'");

        Number::parse($value);
    }

    public function test_parse_error_does_not_contain_value(): void
    {
        try {
            Number::parse('salariu 4500');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringNotContainsString('4500', $e->getMessage());
            $this->assertStringContainsString('(length: 12)', $e->getMessage());
        }
    }

    public function test_parse_rejects_same_separators(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Number::parse('1,5', ',', ',');
    }

    public function test_parse_int(): void
    {
        $this->assertSame(1234, Number::parseInt('1.234'));
        $this->assertSame(-5, Number::parseInt('-5'));
        $this->assertSame(0, Number::parseInt('-0'));
        $this->assertSame(PHP_INT_MAX, Number::parseInt((string) PHP_INT_MAX));
    }

    public function test_parse_int_rejects_decimals(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Number::parseInt(): value has decimals.');

        Number::parseInt('12,00');
    }

    public function test_parse_int_rejects_overflow(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Number::parseInt(): value is out of the integer range.');

        Number::parseInt('9223372036854775808');
    }

    public function test_normalize_keeps_precision(): void
    {
        $this->assertSame('1234.50', Number::normalize('1.234,50'));
        $this->assertSame('12345678901234567890.123456789', Number::normalize('12.345.678.901.234.567.890,123456789'));
        $this->assertSame('-1234.5', Number::normalize('-001.234,5'));
        $this->assertSame('0.00', Number::normalize('-0,00'));
        $this->assertSame('0', Number::normalize('000'));
    }
}
