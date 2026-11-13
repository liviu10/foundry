<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Cast;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

final class CastTest extends TestCase
{
    // toInt

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function validIntProvider(): iterable
    {
        yield 'int' => [42, 42];
        yield 'string' => ['42', 42];
        yield 'negativ' => ['-5', -5];
        yield 'cu plus' => ['+5', 5];
        yield 'cu spatii' => [' 12 ', 12];
        yield 'zero' => ['0', 0];
        yield 'float fara zecimale' => [3.0, 3];
        yield 'max' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'cel mai mare float sub 2^63' => [9223372036854774784.0, 9223372036854774784];
        yield 'min ca float' => [(float) PHP_INT_MIN, PHP_INT_MIN];
    }

    #[DataProvider('validIntProvider')]
    public function test_to_int(mixed $value, int $expected): void
    {
        $this->assertSame($expected, Cast::toInt($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIntProvider(): iterable
    {
        yield 'text' => ['abc'];
        yield 'sufix' => ['12abc'];
        yield 'zecimal string' => ['1.5'];
        yield 'zecimal float' => [1.5];
        yield 'zerouri in fata' => ['012'];
        yield 'gol' => [''];
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'overflow' => ['99999999999999999999'];
        yield 'float urias' => [1e30];
        yield 'float 2^63 (ar deveni PHP_INT_MIN)' => [9223372036854775808.0];
        yield 'nan' => [NAN];
        yield 'array' => [[1]];
        yield 'separator mii' => ['1.000'];
    }

    #[DataProvider('invalidIntProvider')]
    public function test_to_int_rejects(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cast::toInt(): expected an integer');

        Cast::toInt($value);
    }

    // toFloat

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function validFloatProvider(): iterable
    {
        yield 'float' => [1.5, 1.5];
        yield 'int' => [2, 2.0];
        yield 'string' => ['150.5', 150.5];
        yield 'negativ' => ['-0.19', -0.19];
        yield 'fara parte intreaga' => ['.5', 0.5];
        yield 'exponent' => ['1e3', 1000.0];
        yield 'cu spatii' => [' 2 ', 2.0];
    }

    #[DataProvider('validFloatProvider')]
    public function test_to_float(mixed $value, float $expected): void
    {
        $this->assertSame($expected, Cast::toFloat($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidFloatProvider(): iterable
    {
        yield 'virgula' => ['1,5'];
        yield 'format ro' => ['1.234,50'];
        yield 'text' => ['abc'];
        yield 'sufix' => ['1.5lei'];
        yield 'gol' => [''];
        yield 'null' => [null];
        yield 'bool' => [false];
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
        yield 'string inf' => ['1e999'];
        yield 'doar punct' => ['.'];
    }

    #[DataProvider('invalidFloatProvider')]
    public function test_to_float_rejects(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cast::toFloat(): expected a number');

        Cast::toFloat($value);
    }

    // toBool

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function validBoolProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        foreach (['true', 'TRUE', '1', 'yes', 'On', 'da', ' Da '] as $text) {
            yield "'{$text}'" => [$text, true];
        }
        foreach (['false', '0', 'no', 'OFF', 'nu', 'NU'] as $text) {
            yield "'{$text}'" => [$text, false];
        }
    }

    #[DataProvider('validBoolProvider')]
    public function test_to_bool(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Cast::toBool($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidBoolProvider(): iterable
    {
        yield 'gol' => [''];
        yield 'null' => [null];
        yield 'int 2' => [2];
        yield 'text' => ['poate'];
        yield 'float' => [1.0];
    }

    #[DataProvider('invalidBoolProvider')]
    public function test_to_bool_rejects(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cast::toBool(): expected a boolean');

        Cast::toBool($value);
    }

    // toString

    public function test_to_string(): void
    {
        $stringable = new class implements Stringable
        {
            public function __toString(): string
            {
                return 'obiect';
            }
        };

        $this->assertSame('abc', Cast::toString('abc'));
        $this->assertSame('', Cast::toString(''));
        $this->assertSame('42', Cast::toString(42));
        $this->assertSame('1.5', Cast::toString(1.5));
        $this->assertSame('obiect', Cast::toString($stringable));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStringProvider(): iterable
    {
        yield 'null' => [null];
        yield 'bool' => [true];
        yield 'array' => [['a']];
        yield 'obiect' => [new stdClass];
        yield 'nan' => [NAN];
    }

    #[DataProvider('invalidStringProvider')]
    public function test_to_string_rejects(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cast::toString($value);
    }

    // OrNull

    public function test_or_null_variants(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $this->assertNull(Cast::toIntOrNull($blank));
            $this->assertNull(Cast::toFloatOrNull($blank));
            $this->assertNull(Cast::toBoolOrNull($blank));
            $this->assertNull(Cast::toStringOrNull($blank));
        }

        $this->assertSame(5, Cast::toIntOrNull('5'));
        $this->assertEqualsWithDelta(0.5, Cast::toFloatOrNull('0.5'), PHP_FLOAT_EPSILON);
        $this->assertFalse(Cast::toBoolOrNull('nu'));
        $this->assertSame('x', Cast::toStringOrNull('x'));
    }

    public function test_or_null_still_rejects_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cast::toIntOrNull('abc');
    }

    // mesaje

    public function test_messages_describe_type_without_value(): void
    {
        try {
            Cast::toInt('1800101123456x');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Cast::toInt(): expected an integer, got string of 14 bytes.', $e->getMessage());
        }

        try {
            Cast::toFloat(INF);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringEndsWith('got INF.', $e->getMessage());
        }

        try {
            Cast::toBool('');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringEndsWith('got empty string.', $e->getMessage());
        }
    }
}
