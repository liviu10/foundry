<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Timer Class
 *
 * Execution time and memory measurement using hrtime (nanoseconds, unaffected by system
 * clock changes, unlike microtime()). Results are arrays, ready to be logged or serialized to JSON.
 *
 * Rules:
 *   - Timer::start() starts a measurement; lap() records intervals; stop() returns the report;
 *   - measure() measures a function; benchmark() runs it multiple times (with warmup) and
 *     returns min / average / median / max;
 *   - durations are in seconds (float), memory in bytes; format() makes them human-readable (12,3 ms).
 *
 * Usage:
 *   require_once 'Timer.php';
 *   use Foundry\Timer;
 *
 *   $timer = Timer::start();
 *   // ... read
 *   $timer->lap('read');
 *   // ... import
 *   $timer->lap('import');
 *   $report = $timer->stop();   // ['seconds' => 2.41, 'laps' => [...], 'memory' => ..., 'peakMemory' => ...]
 *
 *   ['result' => $rows, 'seconds' => $seconds] = Timer::measure(fn () => $db->fetchAll($sql));
 *   $stats = Timer::benchmark(fn () => Str::slug($title), 10_000);
 *   echo Timer::format($stats['average']);   // '1,8 us'
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
final class Timer
{
    /** @var list<array{name: string, seconds: float}> */
    private array $laps = [];

    private int $lastLap;

    private bool $stopped = false;

    private function __construct(private readonly int $startedAt, private readonly int $startMemory)
    {
        $this->lastLap = $startedAt;
    }

    public static function start(): self
    {
        return new self(hrtime(true), memory_get_usage());
    }

    /**
     * Records an interval and returns its duration (from the previous interval or start).
     *
     * @throws InvalidArgumentException If the name is empty or already in use.
     * @throws LogicException If the measurement has been stopped.
     */
    public function lap(string $name): float
    {
        $this->assertRunning('lap');

        if (trim($name) === '') {
            throw new InvalidArgumentException('Timer::lap(): the lap name is empty.');
        }

        if (in_array($name, array_column($this->laps, 'name'), true)) {
            throw new InvalidArgumentException("Timer::lap(): a lap named '{$name}' already exists.");
        }

        $now = hrtime(true);
        $seconds = ($now - $this->lastLap) / 1e9;
        $this->laps[] = ['name' => $name, 'seconds' => $seconds];
        $this->lastLap = $now;

        return $seconds;
    }

    /**
     * Seconds elapsed since start (measurement continues).
     *
     * @throws LogicException If the measurement has been stopped.
     */
    public function elapsed(): float
    {
        $this->assertRunning('elapsed');

        return (hrtime(true) - $this->startedAt) / 1e9;
    }

    /**
     * Stops the measurement and returns the report.
     * memory = memory usage difference from start (can be negative); peakMemory = process peak memory.
     *
     * @return array{seconds: float, laps: list<array{name: string, seconds: float}>, memory: int, peakMemory: int}
     *
     * @throws LogicException If the measurement has already been stopped.
     */
    public function stop(): array
    {
        $this->assertRunning('stop');
        $this->stopped = true;

        return [
            'seconds' => (hrtime(true) - $this->startedAt) / 1e9,
            'laps' => $this->laps,
            'memory' => memory_get_usage() - $this->startMemory,
            'peakMemory' => memory_get_peak_usage(),
        ];
    }

    /**
     * Executes the function once and returns its result and measurements.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return array{result: T, seconds: float, memory: int}
     */
    public static function measure(Closure $callback): array
    {
        $memory = memory_get_usage();
        $start = hrtime(true);
        $result = $callback();
        $seconds = (hrtime(true) - $start) / 1e9;

        return ['result' => $result, 'seconds' => $seconds, 'memory' => memory_get_usage() - $memory];
    }

    /**
     * Executes the function $iterations times (after $warmup unmeasured runs) and returns
     * single-run statistics in seconds.
     *
     * @param  Closure(): mixed  $callback
     * @return array{iterations: int, total: float, min: float, max: float, average: float, median: float}
     *
     * @throws InvalidArgumentException If $iterations < 1 or $warmup < 0.
     */
    public static function benchmark(Closure $callback, int $iterations = 1000, int $warmup = 10): array
    {
        if ($iterations < 1 || $warmup < 0) {
            throw new InvalidArgumentException("Timer::benchmark(): expected iterations >= 1 and warmup >= 0, got {$iterations} and {$warmup}.");
        }

        for ($i = 0; $i < $warmup; $i++) {
            $callback();
        }

        $times = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = hrtime(true);
            $callback();
            $times[] = hrtime(true) - $start;
        }

        sort($times);
        $middle = intdiv($iterations, 2);
        $median = $iterations % 2 === 1 ? $times[$middle] : ($times[$middle - 1] + $times[$middle]) / 2;
        $total = array_sum($times);

        return [
            'iterations' => $iterations,
            'total' => $total / 1e9,
            'min' => $times[0] / 1e9,
            'max' => $times[$iterations - 1] / 1e9,
            'average' => $total / $iterations / 1e9,
            'median' => $median / 1e9,
        ];
    }

    /**
     * Human-readable duration with decimal comma: '850 ns', '12,3 us', '45,6 ms', '2,41 s', '3 min 5 s', '1 h 2 min'.
     *
     * @throws InvalidArgumentException If the duration is negative, NAN, or INF.
     */
    public static function format(float $seconds): string
    {
        if (! is_finite($seconds) || $seconds < 0) {
            throw new InvalidArgumentException('Timer::format(): the duration must be a finite number >= 0.');
        }

        // Rounded first, then the unit is chosen: 0.9999996 s is '1 s', never '1000 ms'.
        $ns = round($seconds * 1e9);
        if ($ns < 1000) {
            return $ns.' ns';
        }

        foreach ([[1e6, 'us'], [1e3, 'ms']] as [$factor, $unit]) {
            $value = self::rounded($seconds * $factor);
            if ($value < 1000) {
                return str_replace('.', ',', (string) $value).' '.$unit;
            }
        }

        if (round($seconds, 2) < 60) {
            return str_replace('.', ',', (string) round($seconds, 2)).' s';
        }

        $total = (int) round($seconds);
        if ($total < 3600) {
            return intdiv($total, 60).' min '.($total % 60).' s';
        }

        return intdiv($total, 3600).' h '.intdiv($total % 3600, 60).' min';
    }

    /**
     * One decimal place for small values (12,3), no decimal places for 100 and above.
     */
    /** One decimal below 100, none above (12.3 / 457). */
    private static function rounded(float $value): float
    {
        return $value < 100 ? round($value, 1) : round($value);
    }

    /**
     * @throws LogicException
     */
    private function assertRunning(string $method): void
    {
        if ($this->stopped) {
            throw new LogicException("Timer::{$method}(): the timer was already stopped.");
        }
    }
}
