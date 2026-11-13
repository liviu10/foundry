<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Log Class
 *
 * File-based logger with PSR-3 levels, JSON context, and sensitive data masking.
 *
 * Format (one line per message):
 *   [2026-10-30 10:15:02.123] app.ERROR [a1b2c3d4] Payment failed for policy {polita} {"polita":"RCA-123"}
 *
 * Features:
 *   - Configurable minimum log level (e.g., 'info' ignores 'debug');
 *   - PSR-3 placeholder interpolation: {key} in the message is replaced with the value from context;
 *   - context['exception'] (Throwable) is formatted with class, message, file, line, and stack trace;
 *   - Masking: keys containing password, token, secret, etc. become "******", and in strings
 *     "Bearer" tokens and Romanian CNPs (13 digits) are masked;
 *   - Newlines in messages are escaped (a single log entry cannot forge additional lines);
 *   - Optional daily rotation (app-2026-10-30.log) with automatic pruning of old log files;
 *   - Per-instance identifier (request ID) to correlate logs belonging to the same request.
 *
 * Failed writes throw an exception: a logger that silently drops messages is more dangerous
 * than a visible failure.
 *
 * Usage:
 *   require_once 'Log.php';
 *   use Foundry\Log;
 *
 *   $log = new Log(__DIR__.'/storage/logs/app.log', minLevel: 'info', daily: true, maxFiles: 30);
 *   $log->info('User authenticated', ['user_id' => 15]);
 *   $log->error('Payment failed for policy {polita}', ['polita' => 'RCA-123', 'exception' => $e]);
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final readonly class Log
{
    /** @var array<string, int> PSR-3 levels, from most severe (0) to least severe (7). */
    public const array LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    /** @var list<string> Key name substrings whose values are masked. */
    public const array SENSITIVE_KEYS = [
        'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'auth', 'api_key', 'apikey',
        'private_key', 'card', 'cvv', 'cvc', 'iban', 'cnp',
    ];

    /** @var string Replacement mask for sensitive data. */
    public const string MASK = '******';

    private string $requestId;

    /** @var list<string> */
    private array $sensitiveKeys;

    /**
     * @param  string  $path  The log file path; the directory must exist. With $daily, the date is appended
     *                        before the extension: app.log -> app-2026-10-30.log.
     * @param  string  $minLevel  Minimum level to write (default 'debug' = everything).
     * @param  string  $channel  Channel name included in each line (e.g., 'app', 'payments').
     * @param  bool  $daily  true: one file per day.
     * @param  int  $maxFiles  With $daily: number of files to retain (0 = keep all).
     * @param  list<string>|null  $sensitiveKeys  Key fragments to mask; null = SENSITIVE_KEYS.
     *
     * @throws InvalidArgumentException On invalid parameters.
     * @throws RuntimeException If the directory does not exist or is not writable.
     */
    public function __construct(
        private string $path,
        private string $minLevel = 'debug',
        private string $channel = 'app',
        private bool $daily = false,
        private int $maxFiles = 0,
        ?array $sensitiveKeys = null,
    ) {
        if (! isset(self::LEVELS[$minLevel])) {
            throw new InvalidArgumentException("Log: unknown level '{$minLevel}' (allowed: ".implode(', ', array_keys(self::LEVELS)).').');
        }

        if ($maxFiles < 0) {
            throw new InvalidArgumentException('Log: $maxFiles cannot be negative.');
        }

        if (preg_match('/^[\w.-]+$/', $channel) !== 1) {
            throw new InvalidArgumentException("Log: invalid channel name '{$channel}' (allowed: letters, digits, '_', '.', '-').");
        }

        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException("Log: directory '{$directory}' does not exist or is not writable.");
        }

        $this->sensitiveKeys = array_map(strtolower(...), $sensitiveKeys ?? self::SENSITIVE_KEYS);
        $this->requestId = bin2hex(random_bytes(4));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Writes a log message at the specified level.
     *
     * @param  string  $level  One of the PSR-3 log levels (see LEVELS).
     * @param  array<string, mixed>  $context  Additional context; 'exception' may be a Throwable.
     *
     * @throws InvalidArgumentException If the level does not exist.
     * @throws RuntimeException If writing to the file fails.
     */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        $level = strtolower($level);
        if (! isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("Log::log(): unknown level '{$level}'.");
        }

        if (self::LEVELS[$level] > self::LEVELS[$this->minLevel]) {
            return;
        }

        $this->write($this->format($level, (string) $message, $context));
    }

    /**
     * Returns the active log file path (includes the date if $daily is true).
     */
    public function currentFile(): string
    {
        if (! $this->daily) {
            return $this->path;
        }

        $info = pathinfo($this->path);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        return ($info['dirname'] ?? '.').'/'.$info['filename'].'-'.date('Y-m-d').$extension;
    }

    /**
     * Returns the instance identifier present on each log line.
     */
    public function requestId(): string
    {
        return $this->requestId;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function format(string $level, string $message, array $context): string
    {
        // Only a Throwable gets the exception layout; any other 'exception' value stays in the context as is.
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            unset($context['exception']);
        }

        $context = $this->sanitize($context);
        $message = $this->interpolate($message, $context);

        if ($exception instanceof Throwable) {
            $context['exception'] = $this->describeException($exception);
        }

        $line = sprintf(
            '[%s] %s.%s [%s] %s',
            (new DateTimeImmutable)->format('Y-m-d H:i:s.v'),
            $this->channel,
            strtoupper($level),
            $this->requestId,
            $this->escapeNewLines($this->maskText($message)),
        );

        if ($context !== []) {
            // Iesire partiala: o valoare imposibil de codificat (ex: NAN) devine null, linia nu se pierde.
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $line .= ' '.($json === false ? '{"context_error":"'.json_last_error_msg().'"}' : $json);
        }

        return $line.PHP_EOL;
    }

    /**
     * Replaces {key} placeholders with scalar context values (PSR-3).
     *
     * @param  array<array-key, mixed>  $context  Pre-sanitized context.
     */
    private function interpolate(string $message, array $context): string
    {
        if (! str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replace['{'.$key.'}'] = match (true) {
                    $value === null => 'null',
                    is_bool($value) => $value ? 'true' : 'false',
                    default => (string) $value,
                };
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Recursively masks sensitive key values and sensitive data in strings.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data, int $depth = 0): array
    {
        if ($depth > 10) {
            return ['...' => 'max depth reached'];
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value, $depth + 1);
            } elseif (is_string($value)) {
                $data[$key] = $this->maskText($value);
            } elseif ($value instanceof Throwable) {
                $data[$key] = $this->describeException($value);
            } elseif (is_object($value)) {
                $data[$key] = $value instanceof Stringable ? $this->maskText((string) $value) : '['.$value::class.']';
            } elseif (is_resource($value)) {
                $data[$key] = '[resource '.get_resource_type($value).']';
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach ($this->sensitiveKeys as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masks Bearer tokens, password=value pairs (also JSON: "password":"value"), and CNP numbers in text.
     */
    private function maskText(string $text): string
    {
        return (string) preg_replace(
            [
                '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
                '/\b(password|passwd|pwd|token|secret|api_key)(["\']?\s*[=:]\s*["\']?)[^\s"\'&,;}]+/i',
                '/\b[1-8]\d{12}\b/',
            ],
            [
                'Bearer '.self::MASK,
                '$1$2'.self::MASK,
                self::MASK,
            ],
            $text,
        );
    }

    /**
     * Stack trace without function arguments: arguments (enabled by zend.exception_ignore_args=Off, the
     * development default) would put passwords and personal data passed to functions into the log.
     *
     * @return list<string>
     */
    private function traceWithoutArguments(Throwable $exception): array
    {
        $lines = [];
        foreach (array_slice($exception->getTrace(), 0, 20) as $index => $frame) {
            $location = isset($frame['file']) ? $frame['file'].'('.($frame['line'] ?? 0).')' : '[internal function]';
            $lines[] = "#{$index} {$location}: ".($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()';
        }

        return $lines;
    }

    /**
     * @return array{class: string, message: string, code: int|string, file: string, trace: list<string>, previous?: array<string, mixed>}
     */
    private function describeException(Throwable $exception, int $depth = 0): array
    {
        $description = [
            'class' => $exception::class,
            'message' => $this->maskText($exception->getMessage()),
            'code' => $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
            'trace' => $this->traceWithoutArguments($exception),
        ];

        if ($exception->getPrevious() instanceof Throwable && $depth < 3) {
            $description['previous'] = $this->describeException($exception->getPrevious(), $depth + 1);
        }

        return $description;
    }

    private function escapeNewLines(string $text): string
    {
        return str_replace(["\r\n", "\r", "\n"], '\n', $text);
    }

    /**
     * @throws RuntimeException If writing to the file fails.
     */
    private function write(string $line): void
    {
        $file = $this->currentFile();
        $isNew = ! is_file($file);

        [$written, $warning] = $this->capture(static fn (): int|false => file_put_contents($file, $line, FILE_APPEND | LOCK_EX));

        if ($written !== strlen($line)) {
            throw new RuntimeException("Log: cannot write to '{$file}'".($warning === null ? '.' : ": {$warning}."));
        }

        if ($isNew && $this->daily && $this->maxFiles > 0) {
            $this->removeOldFiles();
        }
    }

    /**
     * Deletes older daily log files, retaining the latest $maxFiles.
     */
    private function removeOldFiles(): void
    {
        $info = pathinfo($this->path);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';
        $pattern = ($info['dirname'] ?? '.').'/'.$info['filename'].'-????-??-??'.$extension;

        $files = glob($pattern) ?: [];
        rsort($files, SORT_STRING); // data ISO in nume: ordinea alfabetica = ordinea cronologica

        foreach (array_slice($files, $this->maxFiles) as $old) {
            $this->capture(static fn (): bool => unlink($old));
        }
    }

    /**
     * Executes a native function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation  The operation to execute.
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private function capture(Closure $operation): array
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
