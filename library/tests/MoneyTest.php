<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MoneyTest extends TestCase
{
    // operatii

    public function test_add_is_exact_where_float_is_not(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2, 'premisa: float nu este exact');

        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('100.30', Money::add('100.10', '0.20'));
        $this->assertSame('15.00', Money::add(10, 5));
        $this->assertSame('-0.50', Money::add('1', '-1.5'));
    }

    public function test_sub_mul_div(): void
    {
        $this->assertSame('99.90', Money::sub('100', '0.10'));
        $this->assertSame('-0.10', Money::sub('0', '0.1'));
        $this->assertSame('119.00', Money::mul('100', '1.19'));
        $this->assertSame('497.68', Money::mul('99.99', '4.9773'), '497.680227');
        $this->assertSame('497.6802', Money::mul('99.99', '4.9773', 4));
        $this->assertSame('3.33', Money::div('10', '3'));
        $this->assertSame('6.67', Money::div('20', '3'));
        $this->assertSame('-6.67', Money::div('20', '-3'));
    }

    public function test_div_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money::div(): division by zero.');

        Money::div('10', '0.00');
    }

    public function test_percent(): void
    {
        $this->assertSame('38.00', Money::percent('200', '19'));
        $this->assertSame('19.06', Money::percent('100.30', '19'), '19.057 -> 19.06');
        $this->assertSame('0.13', Money::percent('2.5', '5'), '0.125 -> 0.13');
        $this->assertSame('-0.13', Money::percent('-2.5', '5'));
    }

    public function test_sum(): void
    {
        $this->assertSame('0.60', Money::sum(['0.1', '0.2', '0.3']));
        $this->assertSame('0.00', Money::sum([]));
        $this->assertSame('6.00', Money::sum([1, '2', '3.00']));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function roundProvider(): iterable
    {
        yield 'half up' => ['2.345', 2, '2.35'];
        yield 'sub jumatate' => ['2.3449999', 2, '2.34'];
        yield 'negativ half up' => ['-2.345', 2, '-2.35'];
        yield 'completat cu zerouri' => ['7', 2, '7.00'];
        yield 'transport' => ['9.995', 2, '10.00'];
        yield 'fara minus zero' => ['-0.004', 2, '0.00'];
        yield 'scale 0' => ['2.5', 0, '3'];
        yield 'scale 4' => ['1.23456', 4, '1.2346'];
        yield 'numar mare' => ['12345678901234567890.125', 2, '12345678901234567890.13'];
    }

    #[DataProvider('roundProvider')]
    public function test_round(string $amount, int $scale, string $expected): void
    {
        $this->assertSame($expected, Money::round($amount, $scale));
    }

    public function test_scale_range(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money::add(): scale must be between 0 and 10, got 11.');

        Money::add('1', '1', 11);
    }

    // validare

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAmountProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'virgula' => ['1,5'];
        yield 'exponent' => ['1e3'];
        yield 'plus' => ['+1'];
        yield 'spatiu' => [' 1'];
        yield 'text' => ['abc'];
        yield 'punct final' => ['1.'];
        yield 'punct initial' => ['.5'];
        yield 'separator de mii' => ['1.234.50'];
    }

    #[DataProvider('invalidAmountProvider')]
    public function test_invalid_amount_is_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Money::add(): argument #2 is not a valid amount (expected a plain number like '-1234.56'");

        Money::add('1', $amount);
    }

    public function test_invalid_amount_message_does_not_contain_value(): void
    {
        try {
            Money::sum(['1', '2', 'IBAN RO49AAAA']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('item #2', $e->getMessage());
            $this->assertStringNotContainsString('RO49', $e->getMessage());
        }
    }

    // allocate

    /**
     * @param  int|list<string|int>  $ratios
     * @param  list<string>  $expected
     */
    #[DataProvider('allocateProvider')]
    public function test_allocate(string $amount, int|array $ratios, array $expected): void
    {
        $parts = Money::allocate($amount, $ratios);

        $this->assertSame($expected, $parts);
        $this->assertSame(Money::round($amount), Money::sum($parts), 'totalul se pastreaza');
    }

    /**
     * @return iterable<string, array{string, int|list<string|int>, list<string>}>
     */
    public static function allocateProvider(): iterable
    {
        yield 'trei parti egale' => ['100.00', 3, ['33.34', '33.33', '33.33']];
        yield 'rate lunare' => ['1000', 12, ['83.34', '83.34', '83.34', '83.34', '83.33', '83.33', '83.33', '83.33', '83.33', '83.33', '83.33', '83.33']];
        yield 'ponderi' => ['100.00', [70, 30], ['70.00', '30.00']];
        yield 'ponderi zecimale' => ['10', ['1.5', '1'], ['6.00', '4.00']];
        yield 'bani putini' => ['0.05', [1, 1], ['0.03', '0.02']];
        yield 'pondere zero' => ['10', [0, 1, 1], ['0.00', '5.00', '5.00']];
        yield 'negativ' => ['-0.05', [1, 1], ['-0.03', '-0.02']];
        yield 'zero' => ['0', 2, ['0.00', '0.00']];
        yield 'o parte' => ['12.345', 1, ['12.35']];
    }

    public function test_allocate_remainder_skips_zero_ratios(): void
    {
        $this->assertSame(['0.00', '0.01', '0.00'], Money::allocate('0.01', [0, 1, 0]));
    }

    /**
     * @return iterable<string, array{int|list<string|int>, string}>
     */
    public static function invalidRatiosProvider(): iterable
    {
        yield 'zero parti' => [0, 'number of parts must be >= 1, got 0'];
        yield 'lista goala' => [[], 'ratios must not be empty'];
        yield 'toate zero' => [[0, '0.00'], 'at least one ratio must be greater than 0'];
        yield 'negativa' => [[1, -1], 'ratio #1 must be >= 0'];
        yield 'invalida' => [[1, '1,5'], 'ratio #1 is not a valid amount'];
    }

    /**
     * @param  int|list<string|int>  $ratios
     */
    #[DataProvider('invalidRatiosProvider')]
    public function test_allocate_rejects_invalid_ratios(int|array $ratios, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Money::allocate('100', $ratios);
    }

    // compare / format / parse

    public function test_compare(): void
    {
        $this->assertSame(0, Money::compare('10', '10.00'));
        $this->assertSame(-1, Money::compare('9.99', '10'));
        $this->assertSame(1, Money::compare('0.001', '0'));
        $this->assertSame(1, Money::compare(-1, '-1.5'));
    }

    public function test_format(): void
    {
        $this->assertSame('1.234,50', Money::format('1234.5'));
        $this->assertSame('1.234,50 lei', Money::format('1234.5', 'lei'));
        $this->assertSame('-1.234.567,89 RON', Money::format('-1234567.885', 'RON'));
        $this->assertSame('0,00', Money::format('-0.001'));
        $this->assertSame('1,234.50 EUR', Money::format('1234.5', 'EUR', 2, '.', ','));
        $this->assertSame('1.235', Money::format('1234.5', null, 0));
    }

    public function test_parse(): void
    {
        $this->assertSame('1234.50', Money::parse('1.234,50'));
        $this->assertSame('1234.50', Money::parse(' 1234,5 '));
        $this->assertSame('-10.00', Money::parse('-10'));
        $this->assertSame('0.00', Money::parse('-0'));
        $this->assertSame('1234.50', Money::parse('1,234.50', 2, '.', ','));
    }

    public function test_parse_rejects_more_decimals_than_scale(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Money::parse(): amount has more than 2 decimals.');

        Money::parse('12,345');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputProvider(): iterable
    {
        yield 'punct zecimal' => ['12.5'];
        yield 'grup gresit' => ['1.23,50'];
        yield 'punct zecimal cu 3 zecimale (nu 500)' => ['0.500'];
        yield 'grup de mii care incepe cu zerouri' => ['000.001'];
        yield 'moneda inclusa' => ['12,50 lei'];
        yield 'gol' => [''];
        yield 'text' => ['doisprezece'];
    }

    #[DataProvider('invalidInputProvider')]
    public function test_parse_rejects_invalid_input(string $value): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Money::parse(): value is not an amount in the format '1.234,56'");

        Money::parse($value);
    }
}
