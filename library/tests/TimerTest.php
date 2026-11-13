<?php

declare(strict_types=1);

namespace Foundry\Tests;

use Foundry\Timer;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    /**
     * Asteptare activa masurata cu hrtime (acelasi ceas ca Timer): usleep() pe Windows poate dura
     * mai putin decat cererea, iar testele de durata minima ar pica aleatoriu.
     */
    private static function busyWait(int $microseconds): void
    {
        $end = hrtime(true) + $microseconds * 1000;
        while (hrtime(true) < $end) {
            // asteptare
        }
    }

    public function test_laps_and_stop(): void
    {
        $timer = Timer::start();
        self::busyWait(20_000);
        $first = $timer->lap('citire');
        self::busyWait(10_000);
        $second = $timer->lap('import');
        $elapsed = $timer->elapsed();
        $report = $timer->stop();

        $this->assertGreaterThanOrEqual(0.015, $first);
        $this->assertGreaterThanOrEqual(0.005, $second);
        $this->assertSame([['name' => 'citire', 'seconds' => $first], ['name' => 'import', 'seconds' => $second]], $report['laps']);
        $this->assertGreaterThanOrEqual($first + $second, $report['seconds']);
        $this->assertGreaterThanOrEqual($elapsed, $report['seconds']);
        $this->assertGreaterThan(0, $report['peakMemory']);
    }

    public function test_memory_difference(): void
    {
        $timer = Timer::start();
        $data = str_repeat('x', 1_000_000);
        $report = $timer->stop();

        $this->assertGreaterThanOrEqual(1_000_000, $report['memory']);
        $this->assertSame(1_000_000, strlen($data));
    }

    public function test_stopped_timer_cannot_be_used(): void
    {
        $timer = Timer::start();
        $timer->stop();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Timer::lap(): the timer was already stopped.');

        $timer->lap('x');
    }

    public function test_stop_twice(): void
    {
        $timer = Timer::start();
        $timer->stop();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Timer::stop(): the timer was already stopped.');

        $timer->stop();
    }

    public function test_invalid_lap_names(): void
    {
        $timer = Timer::start();
        $timer->lap('a');

        try {
            $timer->lap('a');
            $this->fail('Expected exception.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame("Timer::lap(): a lap named 'a' already exists.", $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer::lap(): the lap name is empty.');

        $timer->lap(' ');
    }

    public function test_measure(): void
    {
        $result = Timer::measure(static function (): string {
            self::busyWait(10_000);

            return 'gata';
        });

        $this->assertSame('gata', $result['result']);
        $this->assertGreaterThanOrEqual(0.005, $result['seconds']);
        $this->assertArrayHasKey('memory', $result);
    }

    public function test_benchmark(): void
    {
        $calls = 0;
        $stats = Timer::benchmark(static function () use (&$calls): void {
            $calls++;
        }, iterations: 100, warmup: 5);

        $this->assertSame(105, $calls);
        $this->assertSame(100, $stats['iterations']);
        $this->assertLessThanOrEqual($stats['median'], $stats['min']);
        $this->assertLessThanOrEqual($stats['max'], $stats['median']);
        $this->assertEqualsWithDelta($stats['total'] / 100, $stats['average'], 1e-12);
    }

    public function test_benchmark_median_with_even_iterations(): void
    {
        $stats = Timer::benchmark(static fn (): int => 1, iterations: 2, warmup: 0);

        $this->assertEqualsWithDelta(($stats['min'] + $stats['max']) / 2, $stats['median'], 1e-12);
    }

    public function test_benchmark_rejects_invalid_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timer::benchmark(): expected iterations >= 1 and warmup >= 0, got 0 and 10.');

        Timer::benchmark(static fn (): int => 1, iterations: 0);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function formatProvider(): iterable
    {
        yield 'zero' => [0.0, '0 ns'];
        yield 'nanosecunde' => [850e-9, '850 ns'];
        yield 'microsecunde' => [12.34e-6, '12,3 us'];
        yield 'milisecunde' => [0.0456, '45,6 ms'];
        yield 'milisecunde peste 100' => [0.4567, '457 ms'];
        yield 'secunde' => [2.414, '2,41 s'];
        yield 'secunde intregi' => [3.0, '3 s'];
        yield 'minute' => [185.4, '3 min 5 s'];
        yield 'ore' => [3725.0, '1 h 2 min'];
        yield 'rotunjit peste pragul de secunde' => [59.996, '1 min 0 s'];
        yield 'rotunjit peste pragul de milisecunde' => [0.9999996, '1 s'];
        yield 'rotunjit peste pragul de nanosecunde' => [9.9999e-7, '1 us'];
    }

    #[DataProvider('formatProvider')]
    public function test_format(float $seconds, string $expected): void
    {
        $this->assertSame($expected, Timer::format($seconds));
    }

    public function test_format_rejects_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Timer::format(-1.0);
    }
}
