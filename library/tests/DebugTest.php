<?php

declare(strict_types=1);

namespace Foundry\Tests;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use Foundry\Debug;

use function Foundry\dump;

use Foundry\Tests\Support\WebServer;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class DebugTest extends TestCase
{
    protected function tearDown(): void
    {
        Debug::enable(false);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function scalarProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [5, 'int(5)'];
        yield 'int negativ' => [-12, 'int(-12)'];
        yield 'float' => [1.5, 'float(1.5)'];
        yield 'float intreg' => [2.0, 'float(2.0)'];
        yield 'float NAN' => [NAN, 'float(NAN)'];
        yield 'string' => ['abc', 'string(3) "abc"'];
        yield 'string numeric' => ['5', 'string(1) "5"'];
        yield 'string gol' => ['', 'string(0) ""'];
        yield 'UTF-8 in caractere' => ["Ia\u{0219}i", "string(4) \"Ia\u{0219}i\""];
        yield 'caractere speciale' => ["a\"b\\c\nd\te\x01", 'string(10) "a\"b\\\\c\nd\te\x01"'];
        yield 'binar' => ["\xFF\xFE", 'binary(2) "fffe"'];
        yield 'enum' => [DebugTestStatus::Activ, 'enum('.DebugTestStatus::class.'::Activ)'];
    }

    #[DataProvider('scalarProvider')]
    public function test_export_scalars(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Debug::export($value));
    }

    public function test_export_array(): void
    {
        $expected = <<<'TXT'
            array(3) [
              [0] => int(1)
              ["nume"] => string(3) "Ion"
              ["lista"] => array(1) [
                [0] => null
              ]
            ]
            TXT;

        $this->assertSame($expected, Debug::export([1, 'nume' => 'Ion', 'lista' => [null]]));
        $this->assertSame('array(0) []', Debug::export([]));
    }

    public function test_export_object_with_visibility(): void
    {
        $object = new DebugTestPolicy;
        $id = spl_object_id($object);
        $class = DebugTestPolicy::class;

        $expected = <<<TXT
            {$class}#{$id} {
              +numar: string(5) "RO123"
              #suma: float(150.5)
              -activ: true
            }
            TXT;

        $this->assertSame($expected, Debug::export($object));
    }

    public function test_sensitive_keys_and_properties_are_masked(): void
    {
        $object = new stdClass;
        $object->api_token = 'abc';
        $object->nume = 'Ion';

        $text = Debug::export(['password' => 'secret123', 'CNP_client' => '1800101420010', 'user' => $object]);

        $this->assertStringContainsString('["password"] => ******', $text);
        $this->assertStringContainsString('["CNP_client"] => ******', $text);
        $this->assertStringContainsString('+api_token: ******', $text);
        $this->assertStringContainsString('+nume: string(3) "Ion"', $text);
        $this->assertStringNotContainsString('secret123', $text);
        $this->assertStringNotContainsString('1800101420010', $text);
    }

    public function test_recursion_is_detected(): void
    {
        $a = new stdClass;
        $a->self = $a;

        $this->assertStringContainsString('+self: stdClass#'.spl_object_id($a).' *RECURSION*', Debug::export($a));
    }

    public function test_max_depth(): void
    {
        $this->assertSame("array(1) [\n  [0] => array(1) [...]\n]", Debug::export([[[1]]], maxDepth: 1));

        $object = new stdClass;
        $object->a = 1;
        $this->assertSame("array(1) [\n  [0] => stdClass#".spl_object_id($object)." {...}\n]", Debug::export([$object], maxDepth: 1));
    }

    public function test_special_objects(): void
    {
        $date = new DateTimeImmutable('2026-03-15 10:30:00', new DateTimeZone('Europe/Bucharest'));
        $closure = static fn (): int => 1;
        $exception = new RuntimeException('eroare');
        $stream = fopen('php://memory', 'r');
        $this->assertIsResource($stream);

        $this->assertSame('DateTimeImmutable#'.spl_object_id($date).' 2026-03-15 10:30:00.000000 +02:00 (Europe/Bucharest)', Debug::export($date));
        $this->assertSame('Closure#'.spl_object_id($closure), Debug::export($closure));
        $this->assertStringStartsWith('RuntimeException#'.spl_object_id($exception).' "eroare" at ', Debug::export($exception));
        $this->assertSame('resource (stream)#'.get_resource_id($stream), Debug::export($stream));
        $this->assertSame('stdClass#'.spl_object_id($empty = new stdClass).' {}', Debug::export($empty));
        $this->assertStringStartsWith('ArrayObject#', Debug::export(new ArrayObject([1])));

        fclose($stream);
    }

    public function test_long_strings_and_arrays_are_truncated(): void
    {
        $this->assertStringEndsWith('" ... (+5 chars)', Debug::export(str_repeat('a', 10_005)));
        $this->assertStringContainsString('... (+10 items)', Debug::export(range(1, 510)));
    }

    // dump

    public function test_dump_is_disabled_by_default(): void
    {
        $this->assertFalse(Debug::isEnabled());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Debug::dump(): debugging is disabled; call Debug::enable() in development only.');

        Debug::dump(1);
    }

    public function test_dump_in_cli_shows_caller(): void
    {
        Debug::enable();

        ob_start();
        Debug::dump(5, 'x');
        $line = __LINE__ - 1;
        $output = (string) ob_get_clean();

        $this->assertSame(__FILE__.":{$line}\nint(5)\nstring(1) \"x\"\n", $output);
    }

    public function test_namespaced_dump_function(): void
    {
        Debug::enable();

        ob_start();
        dump([1]);
        $line = __LINE__ - 1;
        $output = (string) ob_get_clean();

        $this->assertStringStartsWith(__FILE__.":{$line}\narray(1) [", $output);
    }

    public function test_dd_prints_and_exits(): void
    {
        $script = 'require '.var_export(__DIR__.'/../src/Debug.php', true).'; '
            .'Foundry\Debug::enable(); Foundry\dd(["a" => 1]); echo "dupa";';

        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        $this->assertSame(1, $code);
        $this->assertStringContainsString("[\"a\"] => int(1)\n]", $output);
        $this->assertStringNotContainsString('dupa', $output);
    }

    public function test_dump_in_browser_is_escaped_pre(): void
    {
        $body = WebServer::request('GET', '/debug/dump')['body'];

        $this->assertStringStartsWith('<pre style="', $body);
        $this->assertStringContainsString('string(18) &quot;&lt;script&gt;x&lt;/script&gt;&quot;', $body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertMatchesRegularExpression('/web-router\.php:\d+\n/', $body);
    }
}

enum DebugTestStatus
{
    case Activ;
}

final class DebugTestPolicy
{
    public string $numar = 'RO123';

    protected float $suma = 150.5;

    private bool $activ = true;

    public function activ(): bool
    {
        return $this->activ;
    }
}
