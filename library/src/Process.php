<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Process Class
 *
 * Executes external commands with verified exit codes, separated stdout/stderr, and timeout enforcement.
 *
 * Natively, shell_exec() returns null on both error and empty output, exec() ignores exit codes
 * unless explicitly captured, and arguments require manual escaping (posing command injection risks).
 * Here, commands are supplied as an argument list: PHP launches them directly without invoking a shell,
 * so arguments are never shell-interpreted (no escaping required, immune to injection).
 *
 * Output is directed to temporary files rather than pipes: on Windows, stream_select() fails
 * on pipes, and a saturated pipe deadlocks the process.
 *
 * Error messages expose only the program name, omitting arguments (which may contain sensitive credentials).
 * run() does include the last 1000 bytes of stderr when the exit code is not 0, to explain the failure;
 * for programs that may print secrets or personal data on stderr, use runUnchecked() and handle the
 * output yourself.
 *
 * On timeout the process receives SIGTERM, then SIGKILL after 2 seconds if it is still running.
 * Processes it started itself (e.g. through "sh -c") are not stopped and may keep running.
 *
 * Usage:
 *   require_once 'Process.php';
 *   use Foundry\Process;
 *
 *   $result = Process::run(['git', 'log', '-1', '--format=%H'], cwd: __DIR__);
 *   echo $result['stdout'];
 *
 *   $result = Process::run(['mysqldump', '--result-file='.$file, 'database'], timeout: 600);
 *
 *   $result = Process::runUnchecked(['ping', '-n', '1', 'server']);  // does not throw on non-zero exit
 *   if ($result['exitCode'] !== 0) { ... }
 *
 * @category Utilities
 *
 * @version  1.1
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Process
{
    /** @var int Number of bytes from stderr to include in the error message. */
    private const int ERROR_OUTPUT_LIMIT = 1000;

    /** @var int Pause between process status checks, in microseconds. */
    private const int POLL_INTERVAL = 10_000;

    /** @var float Seconds between SIGTERM and SIGKILL on timeout. */
    private const float KILL_GRACE = 2.0;

    private function __construct() {}

    /**
     * Executes the command and throws an exception if it terminates with a non-zero exit code.
     *
     * @param  list<string>  $command  The program and its arguments, e.g. ['git', 'status'].
     * @param  string|null  $cwd  The working directory; null = current working directory.
     * @param  array<string, string>|null  $env  Environment variables to merge over the current environment; null = inherit.
     * @param  string|null  $input  Text sent to stdin.
     * @param  float  $timeout  Maximum execution time in seconds.
     * @return array{exitCode: int, stdout: string, stderr: string}
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException If the process fails to start, times out, or exits with code != 0.
     */
    public static function run(array $command, ?string $cwd = null, ?array $env = null, ?string $input = null, float $timeout = 60.0): array
    {
        $result = self::execute('run', $command, $cwd, $env, $input, $timeout);

        if ($result['exitCode'] !== 0) {
            $stderr = trim($result['stderr']);
            if (strlen($stderr) > self::ERROR_OUTPUT_LIMIT) {
                $stderr = '...'.substr($stderr, -self::ERROR_OUTPUT_LIMIT);
            }

            throw new RuntimeException(sprintf(
                "Process::run(): '%s' exited with code %d%s",
                self::program($command),
                $result['exitCode'],
                $stderr === '' ? '.' : ": {$stderr}",
            ));
        }

        return $result;
    }

    /**
     * Same as run(), but does NOT throw an exception for a non-zero exit code.
     * Startup failures and timeouts still throw exceptions.
     *
     * @param  list<string>  $command  The program and its arguments.
     * @param  array<string, string>|null  $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException If the process fails to start or times out.
     */
    public static function runUnchecked(array $command, ?string $cwd = null, ?array $env = null, ?string $input = null, float $timeout = 60.0): array
    {
        return self::execute('runUnchecked', $command, $cwd, $env, $input, $timeout);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private static function execute(string $method, array $command, ?string $cwd, ?array $env, ?string $input, float $timeout): array
    {
        self::assertCommand($method, $command);

        if ($cwd !== null && ! is_dir($cwd)) {
            throw new InvalidArgumentException("Process::{$method}(): working directory '{$cwd}' does not exist.");
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException("Process::{$method}(): timeout must be greater than 0.");
        }

        $files = [
            'stdin' => self::tempFile($method),
            'stdout' => self::tempFile($method),
            'stderr' => self::tempFile($method),
        ];

        try {
            if ($input !== null && file_put_contents($files['stdin'], $input) !== strlen($input)) {
                throw new RuntimeException("Process::{$method}(): cannot write the input to a temporary file.");
            }

            $descriptors = [
                0 => ['file', $files['stdin'], 'r'],
                1 => ['file', $files['stdout'], 'w'],
                2 => ['file', $files['stderr'], 'w'],
            ];

            $environment = $env === null ? null : array_merge(getenv(), $env);

            [$process, $warning] = self::capture(static fn () => proc_open($command, $descriptors, $pipes, $cwd, $environment));

            if (! is_resource($process)) {
                throw new RuntimeException(sprintf(
                    "Process::%s(): cannot start '%s'%s",
                    $method,
                    self::program($command),
                    $warning === null ? '.' : ': '.preg_replace('/^proc_open\(\):\s*/', '', $warning).'.',
                ));
            }

            $exitCode = self::wait($method, $process, $command, $timeout);

            return [
                'exitCode' => $exitCode,
                'stdout' => (string) file_get_contents($files['stdout']),
                'stderr' => (string) file_get_contents($files['stderr']),
            ];
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    self::capture(static fn (): bool => unlink($file));
                }
            }
        }
    }

    /**
     * Waits for the process to complete; terminates it if execution exceeds timeout.
     *
     * @param  resource  $process
     * @param  list<string>  $command
     *
     * @throws RuntimeException On timeout.
     */
    private static function wait(string $method, $process, array $command, float $timeout): int
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                proc_close($process);

                return $status['exitcode'];
            }

            if (microtime(true) >= $deadline) {
                self::terminate($process);

                throw new RuntimeException(sprintf("Process::%s(): '%s' timed out after %s seconds.", $method, self::program($command), $timeout));
            }

            usleep(self::POLL_INTERVAL);
        }
    }

    /**
     * Stops a process for good: SIGTERM first, then SIGKILL if it is still running after a short grace period.
     * A process that ignores SIGTERM can no longer keep the caller waiting in proc_close().
     * (On Windows proc_terminate() always ends the process immediately.)
     *
     * @param  resource  $process
     */
    private static function terminate($process): void
    {
        proc_terminate($process);

        $grace = microtime(true) + self::KILL_GRACE;
        while (proc_get_status($process)['running'] && microtime(true) < $grace) {
            usleep(self::POLL_INTERVAL);
        }

        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9); // SIGKILL (the constant needs ext-pcntl)
        }

        proc_close($process);
    }

    /**
     * @param  array<mixed>  $command
     *
     * @throws InvalidArgumentException If the command is not a non-empty list of strings.
     */
    private static function assertCommand(string $method, array $command): void
    {
        if ($command === [] || ! array_is_list($command)) {
            throw new InvalidArgumentException("Process::{$method}(): command must be a non-empty list, e.g. ['git', 'status'].");
        }

        foreach ($command as $index => $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException("Process::{$method}(): command argument #{$index} must be a string.");
            }
        }

        if (trim($command[0]) === '') {
            throw new InvalidArgumentException("Process::{$method}(): the program name cannot be empty.");
        }
    }

    /**
     * The program name for error messages (without path or arguments).
     *
     * @param  list<string>  $command
     */
    private static function program(array $command): string
    {
        return basename(str_replace('\\', '/', $command[0]));
    }

    private static function tempFile(string $method): string
    {
        [$path] = self::capture(static fn (): string|false => tempnam(sys_get_temp_dir(), 'prc'));

        if ($path === false) {
            throw new RuntimeException("Process::{$method}(): cannot create a temporary file in '".sys_get_temp_dir()."'.");
        }

        return $path;
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private static function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
