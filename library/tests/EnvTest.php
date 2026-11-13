<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class EnvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-env-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        // scandir() vede si fisierele care incep cu punct (.env), glob('*') nu.
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $file) {
            unlink($this->dir.'/'.$file);
        }
        rmdir($this->dir);

        // Toate variabilele de test incep cu UTEST_.
        foreach ([...array_keys($_ENV), ...array_keys($_SERVER), ...array_keys(getenv())] as $name) {
            if (is_string($name) && str_starts_with($name, 'UTEST_')) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            }
        }
    }

    private function envFile(string $content, string $name = '.env'): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    // parse: sintaxa

    public function test_parse_basic_syntax(): void
    {
        $vars = Env::parse(<<<'ENV'
            # comentariu
            UTEST_A=simplu

            export UTEST_B=cu_export
            UTEST_C = cu_spatii_in_jurul_egalului
            UTEST_D=
            UTEST_E=valoare # comentariu la final
            UTEST_F=parola#fara_spatiu_nu_e_comentariu
            UTEST_G.H=punct_in_nume
            ENV);

        $this->assertSame([
            'UTEST_A' => 'simplu',
            'UTEST_B' => 'cu_export',
            'UTEST_C' => 'cu_spatii_in_jurul_egalului',
            'UTEST_D' => '',
            'UTEST_E' => 'valoare',
            'UTEST_F' => 'parola#fara_spatiu_nu_e_comentariu',
            'UTEST_G.H' => 'punct_in_nume',
        ], $vars);
    }

    public function test_parse_double_quotes_with_escapes(): void
    {
        $vars = Env::parse(<<<'ENV'
            UTEST_A="Portal Clienti"
            UTEST_B="linia1\nlinia2\ttab \"citat\" back\\slash \$literal"
            UTEST_C="C:\xampp\htdocs"
            UTEST_D="cu # in interior" # comentariu dupa
            UTEST_E=""
            ENV);

        $this->assertSame('Portal Clienti', $vars['UTEST_A']);
        $this->assertSame("linia1\nlinia2\ttab \"citat\" back\\slash \$literal", $vars['UTEST_B']);
        $this->assertSame('C:\xampp\htdocs', $vars['UTEST_C']);
        $this->assertSame('cu # in interior', $vars['UTEST_D']);
        $this->assertSame('', $vars['UTEST_E']);
    }

    public function test_parse_single_quotes_are_literal(): void
    {
        $vars = Env::parse(<<<'ENV'
            UTEST_A='^\d+$ ${NU_SE_INTERPOLEAZA} \n'
            UTEST_B='cu "ghilimele" duble'
            ENV);

        $this->assertSame('^\d+$ ${NU_SE_INTERPOLEAZA} \n', $vars['UTEST_A']);
        $this->assertSame('cu "ghilimele" duble', $vars['UTEST_B']);
    }

    public function test_parse_multiline_values(): void
    {
        $vars = Env::parse("UTEST_CERT=\"-----BEGIN-----\nabc\n-----END-----\"\nUTEST_NEXT=ok\n");

        $this->assertSame("-----BEGIN-----\nabc\n-----END-----", $vars['UTEST_CERT']);
        $this->assertSame('ok', $vars['UTEST_NEXT']);
    }

    public function test_parse_interpolation(): void
    {
        $_ENV['UTEST_FROM_ENV'] = 'mediu';

        $vars = Env::parse(<<<'ENV_WRAP'
        UTEST_HOST=localhost
        UTEST_DSN="mysql:host=${UTEST_HOST};port=3306"
        UTEST_URL=http://${UTEST_HOST}/app
        UTEST_MIX=${UTEST_FROM_ENV}-${UTEST_HOST}
        ENV_WRAP);

        $this->assertSame('mysql:host=localhost;port=3306', $vars['UTEST_DSN']);
        $this->assertSame('http://localhost/app', $vars['UTEST_URL']);
        $this->assertSame('mediu-localhost', $vars['UTEST_MIX']);
    }

    public function test_parse_handles_crlf_and_bom(): void
    {
        $vars = Env::parse("\xEF\xBB\xBFUTEST_A=1\r\nUTEST_B=2\r\n");

        $this->assertSame(['UTEST_A' => '1', 'UTEST_B' => '2'], $vars);
    }

    // parse: erori

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function syntaxErrorProvider(): iterable
    {
        yield 'fara egal' => ["UTEST_A=1\nUTEST_B", 'line 2: expected NAME=value'];
        yield 'nume invalid' => ['1UTEST=x', 'line 1: invalid variable name'];
        yield 'nume cu spatiu' => ['UTEST A=x', 'line 1: invalid variable name'];
        yield 'duplicat' => ["UTEST_A=1\nUTEST_A=2", "line 2: duplicate variable 'UTEST_A'"];
        yield 'spatiu fara ghilimele' => ['UTEST_A=doua cuvinte', "unquoted value of 'UTEST_A' contains whitespace"];
        yield 'ghilimele neinchise' => ["UTEST_A=\"abc\nUTEST_B=1", "line 1: unterminated quoted value for 'UTEST_A'"];
        yield 'text dupa ghilimele' => ['UTEST_A="abc"def', "unexpected characters after the closing quote of 'UTEST_A'"];
        yield 'variabila nedefinita' => ['UTEST_A=${UTEST_NU_EXISTA}', "undefined variable 'UTEST_NU_EXISTA' referenced"];
        yield 'variabila nedefinita in ghilimele' => ['UTEST_A="${UTEST_NU_EXISTA}"', "undefined variable 'UTEST_NU_EXISTA' referenced"];
        yield 'referinta neinchisa' => ['UTEST_A=${UTEST_B', 'unterminated ${...} reference'];
        yield 'referinta invalida' => ['UTEST_A="${1X}"', 'invalid variable name in ${...} reference'];
    }

    #[DataProvider('syntaxErrorProvider')]
    public function test_parse_syntax_errors(string $content, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        Env::parse($content, 'test.env');
    }

    public function test_parse_error_does_not_leak_value(): void
    {
        try {
            Env::parse('UTEST_PASSWORD=secret parola');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
            $this->assertStringContainsString('UTEST_PASSWORD', $e->getMessage());
        }
    }

    // load

    public function test_load_sets_env_and_server(): void
    {
        $loaded = Env::load($this->envFile("UTEST_A=1\nUTEST_B=doi"));

        $this->assertSame(['UTEST_A' => '1', 'UTEST_B' => 'doi'], $loaded);
        $this->assertSame('1', $_ENV['UTEST_A']);
        $this->assertSame('doi', $_SERVER['UTEST_B']);
        $this->assertFalse(getenv('UTEST_A'), 'putenv() nu trebuie folosit implicit');
    }

    public function test_load_with_putenv(): void
    {
        Env::load($this->envFile('UTEST_A=1'), putenv: true);

        $this->assertSame('1', getenv('UTEST_A'));
    }

    public function test_load_does_not_override_existing_variables(): void
    {
        $_ENV['UTEST_A'] = 'din_sistem';

        $loaded = Env::load($this->envFile("UTEST_A=din_fisier\nUTEST_B=nou"));

        $this->assertSame(['UTEST_B' => 'nou'], $loaded);
        $this->assertSame('din_sistem', Env::string('UTEST_A'));
    }

    public function test_load_override(): void
    {
        $_ENV['UTEST_A'] = 'din_sistem';

        Env::load($this->envFile('UTEST_A=din_fisier'), override: true);

        $this->assertSame('din_fisier', Env::string('UTEST_A'));
    }

    public function test_second_file_does_not_override_first_unless_requested(): void
    {
        Env::load($this->envFile("UTEST_A=env\nUTEST_B=env", '.env'));
        Env::load($this->envFile('UTEST_A=local', '.env.local'));
        Env::load($this->envFile('UTEST_B=local', '.env.override'), override: true);

        $this->assertSame('env', Env::string('UTEST_A'));
        $this->assertSame('local', Env::string('UTEST_B'));
    }

    public function test_load_throws_if_file_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        Env::load($this->dir.'/.env.missing');
    }

    public function test_load_reports_file_path_on_syntax_error(): void
    {
        $path = $this->envFile("UTEST_A=1\nUTEST_A=2");

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("{$path} line 2");

        Env::load($path);
    }

    public function test_load_if_exists(): void
    {
        $this->assertFalse(Env::loadIfExists($this->dir.'/.env.missing'));
        $this->assertTrue(Env::loadIfExists($this->envFile('UTEST_A=1')));
        $this->assertSame('1', Env::string('UTEST_A'));
    }

    public function test_load_if_exists_still_reports_syntax_errors(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Env::loadIfExists($this->envFile('UTEST_A=a b'));
    }

    // required / notEmpty / has

    public function test_required_lists_all_missing_variables(): void
    {
        $_ENV['UTEST_A'] = '';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing environment variable(s): UTEST_B, UTEST_C.');

        Env::required('UTEST_A', 'UTEST_B', 'UTEST_C');
    }

    public function test_required_accepts_empty_values(): void
    {
        $_ENV['UTEST_DB_PASSWORD'] = '';

        Env::required('UTEST_DB_PASSWORD');

        $this->addToAssertionCount(1);
    }

    public function test_not_empty_rejects_missing_and_blank(): void
    {
        $_ENV['UTEST_A'] = 'ok';
        $_ENV['UTEST_B'] = '   ';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or empty environment variable(s): UTEST_B, UTEST_C.');

        Env::notEmpty('UTEST_A', 'UTEST_B', 'UTEST_C');
    }

    public function test_has(): void
    {
        $_ENV['UTEST_A'] = '';

        $this->assertTrue(Env::has('UTEST_A'));
        $this->assertFalse(Env::has('UTEST_B'));
    }

    // citire: surse

    public function test_reads_from_server_and_getenv(): void
    {
        $_SERVER['UTEST_SERVER'] = 'server';
        putenv('UTEST_GETENV=getenv');

        $this->assertSame('server', Env::string('UTEST_SERVER'));
        $this->assertSame('getenv', Env::string('UTEST_GETENV'));
    }

    public function test_env_has_priority_over_server(): void
    {
        $_ENV['UTEST_A'] = 'env';
        $_SERVER['UTEST_A'] = 'server';

        $this->assertSame('env', Env::string('UTEST_A'));
    }

    public function test_http_headers_from_server_are_ignored(): void
    {
        $_SERVER['HTTP_UTEST_PROXY'] = 'http://atacator'; // header "Utest-Proxy" trimis de client

        try {
            $this->assertFalse(Env::has('HTTP_UTEST_PROXY'));
        } finally {
            unset($_SERVER['HTTP_UTEST_PROXY']);
        }
    }

    // citire: tipuri

    public function test_string(): void
    {
        $_ENV['UTEST_A'] = 'valoare';
        $_ENV['UTEST_EMPTY'] = '';

        $this->assertSame('valoare', Env::string('UTEST_A'));
        $this->assertSame('', Env::string('UTEST_EMPTY'));
        $this->assertSame('implicit', Env::string('UTEST_MISSING', 'implicit'));
    }

    public function test_string_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Env::string(): environment variable 'UTEST_MISSING' is not set.");

        Env::string('UTEST_MISSING');
    }

    public function test_nullable(): void
    {
        $_ENV['UTEST_A'] = 'x';
        $_ENV['UTEST_EMPTY'] = ' ';

        $this->assertSame('x', Env::nullable('UTEST_A'));
        $this->assertNull(Env::nullable('UTEST_EMPTY'));
        $this->assertNull(Env::nullable('UTEST_MISSING'));
    }

    public function test_int(): void
    {
        $_ENV['UTEST_PORT'] = '3306';
        $_ENV['UTEST_NEG'] = ' -1 ';

        $this->assertSame(3306, Env::int('UTEST_PORT'));
        $this->assertSame(-1, Env::int('UTEST_NEG'));
        $this->assertSame(80, Env::int('UTEST_MISSING', 80));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIntProvider(): iterable
    {
        yield 'text' => ['abc'];
        yield 'sufix' => ['12abc'];
        yield 'zecimal' => ['1.5'];
        yield 'gol' => [''];
        yield 'overflow' => ['99999999999999999999'];
    }

    #[DataProvider('invalidIntProvider')]
    public function test_int_rejects_invalid_values(string $value): void
    {
        $_ENV['UTEST_A'] = $value;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'UTEST_A' must be an integer");

        Env::int('UTEST_A');
    }

    public function test_int_uses_default_only_when_missing_not_when_invalid(): void
    {
        $_ENV['UTEST_A'] = 'abc';

        $this->expectException(UnexpectedValueException::class);

        Env::int('UTEST_A', 10);
    }

    public function test_float(): void
    {
        $_ENV['UTEST_A'] = '0.19';
        $_ENV['UTEST_B'] = '1e3';

        $this->assertEqualsWithDelta(0.19, Env::float('UTEST_A'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1000.0, Env::float('UTEST_B'), PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(1.5, Env::float('UTEST_MISSING', 1.5), PHP_FLOAT_EPSILON);
    }

    public function test_float_rejects_comma(): void
    {
        $_ENV['UTEST_A'] = '0,19';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('use "." as decimal separator');

        Env::float('UTEST_A');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function boolProvider(): iterable
    {
        foreach (['true', 'TRUE', '1', 'yes', 'On', ' true '] as $value) {
            yield "'{$value}'" => [$value, true];
        }
        foreach (['false', 'False', '0', 'no', 'OFF'] as $value) {
            yield "'{$value}'" => [$value, false];
        }
    }

    #[DataProvider('boolProvider')]
    public function test_bool(string $value, bool $expected): void
    {
        $_ENV['UTEST_A'] = $value;

        $this->assertSame($expected, Env::bool('UTEST_A'));
    }

    public function test_bool_default_and_invalid(): void
    {
        $this->assertFalse(Env::bool('UTEST_MISSING', false));

        $_ENV['UTEST_A'] = 'poate';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'UTEST_A' must be one of: true, false");

        Env::bool('UTEST_A');
    }

    public function test_bool_rejects_empty(): void
    {
        $_ENV['UTEST_A'] = '';

        $this->expectException(UnexpectedValueException::class);

        Env::bool('UTEST_A');
    }

    public function test_list(): void
    {
        $_ENV['UTEST_HOSTS'] = ' a.ro, b.ro ,,c.ro ';
        $_ENV['UTEST_EMPTY'] = '';
        $_ENV['UTEST_PIPE'] = 'x|y';

        $this->assertSame(['a.ro', 'b.ro', 'c.ro'], Env::list('UTEST_HOSTS'));
        $this->assertSame([], Env::list('UTEST_EMPTY'));
        $this->assertSame(['x', 'y'], Env::list('UTEST_PIPE', separator: '|'));
        $this->assertSame(['implicit'], Env::list('UTEST_MISSING', ['implicit']));
    }

    public function test_list_throws_if_missing(): void
    {
        $this->expectException(RuntimeException::class);

        Env::list('UTEST_MISSING');
    }

    public function test_one_of(): void
    {
        $_ENV['UTEST_APP_ENV'] = 'production';

        $this->assertSame('production', Env::oneOf('UTEST_APP_ENV', ['local', 'production']));
        $this->assertSame('local', Env::oneOf('UTEST_MISSING', ['local', 'production'], 'local'));
    }

    public function test_one_of_rejects_other_values(): void
    {
        $_ENV['UTEST_APP_ENV'] = 'Production';

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage("'UTEST_APP_ENV' must be one of: local, production.");

        Env::oneOf('UTEST_APP_ENV', ['local', 'production']);
    }

    public function test_type_errors_do_not_leak_value(): void
    {
        $_ENV['UTEST_SECRET_PORT'] = 'parola-secreta';

        try {
            Env::int('UTEST_SECRET_PORT');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertStringNotContainsString('parola-secreta', $e->getMessage());
        }
    }
}
