<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Regex;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegexTest extends TestCase
{
    // test

    public function test_test(): void
    {
        $this->assertTrue(Regex::test('/^RO\d{2}/', 'RO49AAAA1B31007593840000'));
        $this->assertFalse(Regex::test('/^RO\d{2}/', 'DE89370400440532013000'));
    }

    // match

    public function test_match_returns_groups_including_named(): void
    {
        $m = Regex::match('/^(?<judet>[A-Z]{1,2}) ?(?<numar>\d{2,3})/', 'B 123 ABC');

        $this->assertNotNull($m);
        $this->assertSame('B 123', $m[0]);
        $this->assertSame('B', $m['judet']);
        $this->assertSame('123', $m['numar']);
    }

    public function test_match_returns_null_without_match(): void
    {
        $this->assertNull(Regex::match('/\d+/', 'fara cifre'));
    }

    public function test_match_unmatched_groups_are_null(): void
    {
        $m = Regex::match('/(a)(b)?(c)/', 'ac');

        $this->assertSame(['ac', 'a', null, 'c'], $m);
    }

    // matchAll

    public function test_match_all(): void
    {
        $all = Regex::matchAll('/(\d+)-(\d+)/', '1-2, 30-40');

        $this->assertSame([['1-2', '1', '2'], ['30-40', '30', '40']], $all);
    }

    public function test_match_all_without_matches_returns_empty_list(): void
    {
        $this->assertSame([], Regex::matchAll('/\d+/', 'abc'));
    }

    // replace / replaceCallback

    public function test_replace(): void
    {
        $this->assertSame('polita-rca-2026', Regex::replace('/[^a-z0-9]+/', '-', 'polita rca 2026'));
        $this->assertSame('2026-10-30', Regex::replace('/(\d{2})\.(\d{2})\.(\d{4})/', '$3-$2-$1', '30.10.2026'));
    }

    public function test_replace_with_limit(): void
    {
        $this->assertSame('x b a', Regex::replace('/a/', 'x', 'a b a', limit: 1));
    }

    public function test_replace_callback(): void
    {
        $result = Regex::replaceCallback('/\d+/', static fn (array $m): string => (string) ((int) $m[0] * 2), 'a1 b20');

        $this->assertSame('a2 b40', $result);
    }

    public function test_replace_callback_passes_null_for_unmatched_groups(): void
    {
        $result = Regex::replaceCallback('/(a)(b)?/', static fn (array $m): string => $m[2] === null ? 'N' : 'Y', 'a ab');

        $this->assertSame('N Y', $result);
    }

    public function test_replace_callback_exception_propagates(): void
    {
        $this->expectException(\DomainException::class);

        Regex::replaceCallback('/a/', static fn (): string => throw new \DomainException('din callback'), 'a');
    }

    public function test_replace_callback_warning_is_not_reported_as_invalid_pattern(): void
    {
        $forwarded = [];
        set_error_handler(static function (int $severity, string $message) use (&$forwarded): bool {
            $forwarded[] = $message;

            return true;
        });

        try {
            $result = Regex::replaceCallback('/a/', static function (): string {
                trigger_error('warning din callback', E_USER_WARNING);

                return 'b';
            }, 'a');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('b', $result);
        $this->assertSame(['warning din callback'], $forwarded);
    }

    // split / filter

    public function test_split(): void
    {
        $this->assertSame(['a', 'b', 'c'], Regex::split('/\s*,\s*/', 'a , b,c'));
        $this->assertSame(['a', '', 'b'], Regex::split('/,/', 'a,,b'));
        $this->assertSame(['a', 'b'], Regex::split('/,/', 'a,,b', removeEmpty: true));
        $this->assertSame(['a', 'b,c'], Regex::split('/,/', 'a,b,c', limit: 2));
    }

    public function test_filter_keeps_keys(): void
    {
        $items = ['x' => 'abc', 'y' => '123', 'z' => 'a1'];

        $this->assertSame(['y' => '123', 'z' => 'a1'], Regex::filter('/\d/', $items));
        $this->assertSame(['x' => 'abc'], Regex::filter('/\d/', $items, invert: true));
    }

    // erori

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPatternProvider(): iterable
    {
        yield 'missing parenthesis' => ['/(abc/'];
        yield 'missing delimiter' => ['abc'];
        yield 'unknown modifier' => ['/abc/k'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPatternProvider')]
    public function test_invalid_pattern_throws_invalid_argument(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Regex::test(): invalid pattern');

        Regex::test($pattern, 'abc');
    }

    public function test_invalid_pattern_message_contains_reason(): void
    {
        try {
            Regex::replace('/(abc/', '', 'abc');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Regex::replace(): invalid pattern (length: 6): Compilation failed', $e->getMessage());
            $this->assertStringNotContainsString('/(abc/', $e->getMessage(), 'pattern-ul poate contine input preluat de la utilizator');
            $this->assertStringNotContainsString('preg_replace()', $e->getMessage());
        }
    }

    public function test_invalid_utf8_subject_throws_runtime_exception_without_subject(): void
    {
        try {
            Regex::test('/a/u', "secret\xB1");
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Malformed UTF-8', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_backtrack_limit_throws_runtime_exception(): void
    {
        $previous = ini_set('pcre.backtrack_limit', '100');
        $jit = ini_set('pcre.jit', '0');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Backtrack limit exhausted');

            Regex::test('/(a+)+[bc]/', str_repeat('a', 30).'!'); // [bc] evita optimizarea "caracter obligatoriu"
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
            ini_set('pcre.jit', (string) $jit);
        }
    }

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Regex::test('/(abc/', 'abc');
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
