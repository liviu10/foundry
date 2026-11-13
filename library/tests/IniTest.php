<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Ini;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class IniTest extends TestCase
{
    private const string CONTENT = <<<'INI'
        ; comentariu
        [app]
        name = "Portal Clienti"
        debug = yes
        token = null
        version = PHP_VERSION

        [database]
        host = localhost
        port = 3306
        ratio = 0.5
        hosts[] = a.ro
        hosts[] = b.ro
        INI;

    public function test_raw_mode_keeps_values_as_written(): void
    {
        $this->assertSame([
            'name' => 'Portal Clienti',
            'debug' => 'yes',
            'token' => 'null',
            'version' => 'PHP_VERSION', // nu este inlocuit cu valoarea constantei
            'host' => 'localhost',
            'port' => '3306',
            'ratio' => '0.5',
            'hosts' => ['a.ro', 'b.ro'],
        ], Ini::parseString(self::CONTENT));
    }

    public function test_sections(): void
    {
        $config = Ini::parseString(self::CONTENT, sections: true);

        $this->assertSame(['app', 'database'], array_keys($config));
        $this->assertSame(['host' => 'localhost', 'port' => '3306', 'ratio' => '0.5', 'hosts' => ['a.ro', 'b.ro']], $config['database']);
    }

    public function test_typed_mode(): void
    {
        $config = Ini::parseString("a = yes\nb = off\nc = null\nd = 8080\ne = 1.5\nf = text", typed: true);

        $this->assertSame(['a' => true, 'b' => false, 'c' => null, 'd' => 8080, 'e' => 1.5, 'f' => 'text'], $config);
    }

    public function test_typed_mode_keeps_quoted_values_as_text(): void
    {
        $config = Ini::parseString("a = \"yes\"\nb = \"8080\"\nc = \"PHP_OS\"\nd = \"E_ALL & ~E_NOTICE\"\ne = -3\nf[] = 1\nf[] = on", typed: true);

        $this->assertSame(['a' => 'yes', 'b' => '8080', 'c' => 'PHP_OS', 'd' => 'E_ALL & ~E_NOTICE', 'e' => -3, 'f' => [1, true]], $config);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function substitutionProvider(): iterable
    {
        yield 'constanta PHP' => ['value = PHP_OS'];
        yield 'expresie cu constante' => ['value = E_ALL & ~E_NOTICE'];
        yield 'variabila de mediu' => ['value = ${FOUNDRY_INI_TEST}'];
        yield 'operator pe text' => ['value = abc|def'];
        yield 'operator pe numere' => ['value = 1 | 2'];
    }

    #[DataProvider('substitutionProvider')]
    public function test_typed_mode_rejects_constants_env_and_expressions(string $ini): void
    {
        putenv('FOUNDRY_INI_TEST=secret');

        try {
            Ini::parseString("[app]\n".$ini, sections: true, typed: true);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString("the value of 'app.value' uses a PHP constant", $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        } finally {
            putenv('FOUNDRY_INI_TEST');
        }
    }

    public function test_raw_mode_never_substitutes(): void
    {
        $this->assertSame(['a' => 'PHP_OS', 'b' => 'abc|def'], Ini::parseString("a = PHP_OS\nb = abc|def"));
    }

    public function test_bom_is_removed(): void
    {
        $this->assertSame(['key' => 'value'], Ini::parseString("\xEF\xBB\xBFkey = value"));
    }

    public function test_empty_content(): void
    {
        $this->assertSame([], Ini::parseString(''));
        $this->assertSame([], Ini::parseString("; doar comentariu\n"));
    }

    public function test_syntax_error_has_reason_and_line(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("Ini::parseString(): syntax error, unexpected end of file, expecting ']' on line 2.");

        Ini::parseString("a = 1\n[sectiune");
    }

    public function test_parse_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ini');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, self::CONTENT);

            $config = Ini::parseFile($path, sections: true);

            $this->assertSame(Ini::parseString(self::CONTENT, sections: true), $config);
        } finally {
            unlink($path);
        }
    }

    public function test_parse_file_syntax_error_includes_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ini');
        $this->assertNotFalse($path);
        file_put_contents($path, "[sectiune\n");

        try {
            Ini::parseFile($path);
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringContainsString("in '{$path}'", $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_parse_file_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Ini::parseFile(sys_get_temp_dir().'/lipsa-'.bin2hex(random_bytes(4)).'.ini');
    }

    public function test_warnings_are_not_leaked_to_host_error_handler(): void
    {
        $leaked = [];
        set_error_handler(static function (int $severity, string $message) use (&$leaked): bool {
            $leaked[] = $message;

            return true;
        });

        try {
            Ini::parseString('[x');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException) {
            // asteptat
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $leaked);
    }
}
