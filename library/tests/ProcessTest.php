<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Process;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Comenzile de test folosesc chiar interpretorul PHP curent, ca sa ruleze identic pe Windows si Linux.
 */
final class ProcessTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function php(string $code): array
    {
        return [PHP_BINARY, '-n', '-r', $code];
    }

    public function test_run_captures_stdout_and_stderr_separately(): void
    {
        $result = Process::run($this->php('echo "iesire"; fwrite(STDERR, "eroare");'));

        $this->assertSame(['exitCode' => 0, 'stdout' => 'iesire', 'stderr' => 'eroare'], $result);
    }

    public function test_arguments_are_passed_literally_without_shell(): void
    {
        $dangerous = 'a b; rm -rf / & echo "x" | $(whoami) %PATH%';

        $result = Process::run([PHP_BINARY, '-n', '-r', 'echo $argv[1];', $dangerous]);

        $this->assertSame($dangerous, $result['stdout']);
    }

    public function test_input_is_sent_to_stdin(): void
    {
        $result = Process::run($this->php('echo strtoupper(stream_get_contents(STDIN));'), input: "linia 1\nlinia 2");

        $this->assertSame("LINIA 1\nLINIA 2", $result['stdout']);
    }

    public function test_large_output_does_not_block(): void
    {
        $result = Process::run($this->php('echo str_repeat("x", 2000000);'));

        $this->assertSame(2000000, strlen($result['stdout']));
    }

    public function test_working_directory(): void
    {
        $dir = (string) realpath(sys_get_temp_dir());

        $result = Process::run($this->php('echo getcwd();'), cwd: $dir);

        $this->assertSame(strtolower($dir), strtolower($result['stdout']));
    }

    public function test_environment_is_added_to_current_environment(): void
    {
        $result = Process::run($this->php('echo getenv("UTEST_PROC"), "|", getenv("PATH") !== false ? "path" : "";'), env: ['UTEST_PROC' => 'valoare']);

        $this->assertSame('valoare|path', $result['stdout']);
    }

    public function test_run_throws_on_non_zero_exit_with_stderr_and_program_name_only(): void
    {
        try {
            Process::run($this->php('fwrite(STDERR, "ceva nu a mers"); exit(3);'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(
                sprintf("Process::run(): '%s' exited with code 3: ceva nu a mers", basename(PHP_BINARY)),
                $e->getMessage(),
            );
            $this->assertStringNotContainsString('fwrite', $e->getMessage(), 'argumentele nu apar in mesaj');
        }
    }

    public function test_long_stderr_is_truncated_in_message(): void
    {
        try {
            Process::run($this->php('fwrite(STDERR, str_repeat("a", 5000)."FINAL"); exit(1);'));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertLessThan(1200, strlen($e->getMessage()));
            $this->assertStringEndsWith('aFINAL', $e->getMessage());
        }
    }

    public function test_run_unchecked_returns_exit_code(): void
    {
        $result = Process::runUnchecked($this->php('echo "partial"; exit(2);'));

        $this->assertSame(2, $result['exitCode']);
        $this->assertSame('partial', $result['stdout']);
    }

    public function test_timeout_stops_the_process(): void
    {
        $start = microtime(true);

        try {
            Process::run($this->php('sleep(10);'), timeout: 0.5);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('timed out after 0.5 seconds', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_timeout_kills_a_process_that_ignores_sigterm(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('On Windows proc_terminate() always ends the process; there is no SIGTERM to ignore.');
        }

        $start = microtime(true);

        try {
            // sh ignora SIGTERM: fara SIGKILL, proc_close() ar astepta 10 secunde
            Process::run(['sh', '-c', 'trap "" TERM; sleep 10 & wait; sleep 10'], timeout: 0.5);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('timed out after 0.5 seconds', $e->getMessage());
        }

        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_missing_program(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'program_inexistent_xyz'");

        Process::run(['program_inexistent_xyz']);
    }

    public function test_invalid_command(): void
    {
        // Cheile asociative si argumentele non-string sunt prinse de PHPStan la apelant;
        // aici raman cazurile permise de tipul list<string>.
        foreach ([[], [''], ['  ']] as $command) {
            try {
                Process::run($command);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_invalid_working_directory_and_timeout(): void
    {
        try {
            Process::run($this->php('echo 1;'), cwd: sys_get_temp_dir().'/nu-exista-'.bin2hex(random_bytes(4)));
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('working directory', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);

        Process::run($this->php('echo 1;'), timeout: 0);
    }

    public function test_temporary_files_are_removed(): void
    {
        $before = glob(sys_get_temp_dir().'/prc*') ?: [];

        Process::run($this->php('echo 1;'));
        Process::runUnchecked($this->php('exit(1);'));

        $this->assertSame($before, glob(sys_get_temp_dir().'/prc*') ?: []);
    }
}
