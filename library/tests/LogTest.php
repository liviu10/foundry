<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\File;
use Foundry\Log;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

final class LogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/utilities-log-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDir($this->dir);
    }

    /**
     * @return list<string>
     */
    private function lines(string $file = 'app.log'): array
    {
        return file($this->dir.'/'.$file, FILE_IGNORE_NEW_LINES) ?: [];
    }

    /**
     * Contextul JSON de la finalul unei linii.
     *
     * @return array<string, mixed>
     */
    private function context(string $line): array
    {
        $json = substr($line, (int) strpos($line, ' {"'));
        $decoded = json_decode(trim($json), true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_line_format(): void
    {
        $log = new Log($this->dir.'/app.log', channel: 'plati');

        $log->info('Plata inregistrata', ['polita' => 'RCA-123', 'suma' => 150.5]);

        $lines = $this->lines();
        $this->assertCount(1, $lines);
        $this->assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\] plati\.INFO \['.$log->requestId().'\] Plata inregistrata \{"polita":"RCA-123","suma":150\.5\}$/',
            $lines[0],
        );
    }

    public function test_all_levels_and_min_level(): void
    {
        $log = new Log($this->dir.'/app.log', minLevel: 'warning');

        foreach (array_keys(Log::LEVELS) as $level) {
            $log->{$level}("mesaj {$level}");
        }

        $levels = array_map(static fn (string $line): string => (string) preg_replace('/^.*? app\.(\w+) .*$/', '$1', $line), $this->lines());

        $this->assertSame(['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING'], $levels);
    }

    public function test_unknown_level(): void
    {
        $log = new Log($this->dir.'/app.log');

        $this->expectException(InvalidArgumentException::class);

        $log->log('fatal', 'x');
    }

    public function test_interpolation(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->error('Polita {polita} are {zile} zile intarziere; activ={activ}; lipsa={lipsa}', [
            'polita' => 'RCA-1',
            'zile' => 3,
            'activ' => false,
        ]);

        $this->assertStringContainsString('Polita RCA-1 are 3 zile intarziere; activ=false; lipsa={lipsa}', $this->lines()[0]);
    }

    public function test_sensitive_keys_are_masked_recursively(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->info('Autentificare {password}', [
            'user' => 'ion',
            'password' => 'secret123',
            'request' => ['api_key' => 'abc', 'headers' => ['Authorization' => 'Bearer xyz']],
            'card_number' => '4111111111111111',
        ]);

        $line = $this->lines()[0];
        $this->assertStringNotContainsString('secret123', $line);
        $this->assertStringNotContainsString('4111111111111111', $line);
        $this->assertStringNotContainsString('xyz', $line);
        $this->assertStringContainsString('Autentificare ******', $line, 'interpolarea foloseste valoarea mascata');
        $this->assertSame([
            'user' => 'ion',
            'password' => '******',
            'request' => ['api_key' => '******', 'headers' => ['Authorization' => '******']],
            'card_number' => '******',
        ], $this->context($line));
    }

    public function test_sensitive_text_is_masked_in_messages_and_values(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->warning('Header Authorization: Bearer eyJhbGciOi.abc.def, url ?token=abc123&x=1, cnp 1800101123456', [
            'note' => 'clientul 2850505123456 a trimis password=parola',
        ]);

        $line = $this->lines()[0];
        foreach (['eyJhbGciOi', 'abc123', '1800101123456', '2850505123456', 'parola'] as $secret) {
            $this->assertStringNotContainsString($secret, $line);
        }
        $this->assertStringContainsString('Bearer ******', $line);
    }

    public function test_secrets_inside_json_strings_are_masked(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->info('request', ['body' => '{"user":"ion","password":"Secret123","api_key": "k-987"}']);

        $line = $this->lines()[0];
        $this->assertStringNotContainsString('Secret123', $line);
        $this->assertStringNotContainsString('k-987', $line);
        $this->assertStringContainsString('ion', $line, 'restul continutului ramane lizibil');
    }

    public function test_non_throwable_exception_value_is_kept(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->warning('Import oprit', ['exception' => 'timeout la furnizor']);

        $this->assertSame(['exception' => 'timeout la furnizor'], $this->context($this->lines()[0]));
    }

    public function test_exception_trace_contains_no_arguments(): void
    {
        $log = new Log($this->dir.'/app.log');
        $login = static function (string $user, string $password): never {
            throw new RuntimeException('Autentificare esuata');
        };

        try {
            $login('ion', 'ParolaSecreta!');
        } catch (RuntimeException $e) {
            $log->error('Eroare', ['exception' => $e]);
        }

        $line = $this->lines()[0];
        $this->assertStringNotContainsString('ParolaSecreta', $line);
        $context = $this->context($line);
        $this->assertIsArray($context['exception']);
        $this->assertIsArray($context['exception']['trace']);
        $this->assertIsString($context['exception']['trace'][0]);
        $this->assertStringContainsString('{closure}()', $context['exception']['trace'][0]);
    }

    public function test_custom_sensitive_keys(): void
    {
        $log = new Log($this->dir.'/app.log', sensitiveKeys: ['nume']);

        $log->info('x', ['nume_client' => 'Ion', 'password' => 'vizibil']);

        $this->assertSame(['nume_client' => '******', 'password' => 'vizibil'], $this->context($this->lines()[0]));
    }

    public function test_new_lines_cannot_forge_log_entries(): void
    {
        $log = new Log($this->dir.'/app.log');

        $log->info("utilizator ion\n[2026-01-01 00:00:00.000] app.INFO [fals] Autentificare admin reusita");

        $this->assertCount(1, $this->lines());
        $this->assertStringContainsString('utilizator ion\n[2026', $this->lines()[0]);
    }

    public function test_exception_in_context(): void
    {
        $log = new Log($this->dir.'/app.log');
        $exception = new RuntimeException('Conexiune refuzata', 7, new LogicException('cauza'));

        $log->error('Eroare', ['exception' => $exception]);

        $context = $this->context($this->lines()[0]);
        $this->assertIsArray($context['exception']);
        $this->assertSame(RuntimeException::class, $context['exception']['class']);
        $this->assertSame('Conexiune refuzata', $context['exception']['message']);
        $this->assertSame(7, $context['exception']['code']);
        $this->assertIsString($context['exception']['file']);
        $this->assertStringContainsString('LogTest.php:', $context['exception']['file']);
        $this->assertIsArray($context['exception']['previous'] ?? null);
        $this->assertSame(LogicException::class, $context['exception']['previous']['class']);
    }

    public function test_non_scalar_values_are_described(): void
    {
        $log = new Log($this->dir.'/app.log');
        $handle = fopen('php://memory', 'r');

        $log->info('x', ['obj' => new stdClass, 'res' => $handle, 'utf8' => "a\xB1b", 'nan' => NAN]);

        $context = $this->context($this->lines()[0]);
        $this->assertSame('[stdClass]', $context['obj']);
        $this->assertSame('[resource stream]', $context['res']);
        $this->assertSame("a\u{FFFD}b", $context['utf8']);
        $this->assertSame(0, $context['nan'], 'valorile imposibil de codificat nu pierd linia');
    }

    public function test_daily_rotation_and_max_files(): void
    {
        foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            touch($this->dir."/app-{$day}.log");
        }
        touch($this->dir.'/alt-2026-10-01.log');

        $log = new Log($this->dir.'/app.log', daily: true, maxFiles: 3);
        $log->info('azi');

        $this->assertSame($this->dir.'/app-'.date('Y-m-d').'.log', $log->currentFile());
        $this->assertFileExists($log->currentFile());

        $remaining = array_map(basename(...), File::glob($this->dir.'/app-*.log'));
        $this->assertSame(['app-2026-10-03.log', 'app-2026-10-04.log', 'app-'.date('Y-m-d').'.log'], $remaining);
        $this->assertFileExists($this->dir.'/alt-2026-10-01.log', 'alte fisiere nu sunt atinse');
    }

    public function test_request_id_is_shared_by_instance(): void
    {
        $log = new Log($this->dir.'/app.log');
        $log->info('a');
        $log->info('b');
        $other = new Log($this->dir.'/app.log');
        $other->info('c');

        $lines = $this->lines();
        $this->assertStringContainsString('['.$log->requestId().']', $lines[0]);
        $this->assertStringContainsString('['.$log->requestId().']', $lines[1]);
        $this->assertNotSame($log->requestId(), $other->requestId());
    }

    public function test_constructor_validation(): void
    {
        $cases = [
            fn (): Log => new Log($this->dir.'/lipsa/app.log'),
            fn (): Log => new Log($this->dir.'/app.log', minLevel: 'verbose'),
            fn (): Log => new Log($this->dir.'/app.log', channel: 'cu spatiu'),
            fn (): Log => new Log($this->dir.'/app.log', maxFiles: -1),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected exception');
            } catch (InvalidArgumentException|RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_write_failure_throws(): void
    {
        mkdir($this->dir.'/app.log'); // calea fisierului este un director

        $log = new Log($this->dir.'/app.log');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot write to');

        $log->info('x');
    }
}
