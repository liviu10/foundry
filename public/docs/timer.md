# Timer

> High-precision stage profiling and memory tracking; includes a statistical benchmark() helper.

Canonical: https://foundry.insightdevhub.ro/docs/timer/  
Namespace: `Foundry\Timer` · File: `Timer.php` · Version 1.1 · PHP 8.3+

Execution time and memory measurement using hrtime (nanoseconds, unaffected by system clock changes, unlike microtime()). Results are arrays, ready to be logged or serialized to JSON.

## Rules

- Timer::start() starts a measurement; lap() records intervals; stop() returns the report
- measure() measures a function; benchmark() runs it multiple times (with warmup) and returns min / average / median / max
- durations are in seconds (float), memory in bytes; format() makes them human-readable (12,3 ms).

## Usage

```php
require_once 'Timer.php';
use Foundry\Timer;

$timer = Timer::start();
// ... read
$timer->lap('read');
// ... import
$timer->lap('import');
$report = $timer->stop();   // ['seconds' => 2.41, 'laps' => [...], 'memory' => ..., 'peakMemory' => ...]

['result' => $rows, 'seconds' => $seconds] = Timer::measure(fn () => $db->fetchAll($sql));
$stats = Timer::benchmark(fn () => Str::slug($title), 10_000);
echo Timer::format($stats['average']);   // '1,8 us'
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Timer.php';

use Foundry\Timer;
```

## Methods

### start()

```php
public static function start(): self
```

Returns `Foundry\Timer`.

### lap()

```php
public function lap(string $name): float
```

Records an interval and returns its duration (from the previous interval or start).

Parameters:
- `$name` (`string`, required)

Returns `float`.

Throws:
- `InvalidArgumentException`: If the name is empty or already in use.
- `LogicException`: If the measurement has been stopped.

### elapsed()

```php
public function elapsed(): float
```

Seconds elapsed since start (measurement continues).

Returns `float`.

Throws:
- `LogicException`: If the measurement has been stopped.

### stop()

```php
public function stop(): array
```

Stops the measurement and returns the report. memory = memory usage difference from start (can be negative); peakMemory = process peak memory.

Returns `array{seconds: float, laps: list<array{name: string, seconds: float}>, memory: int, peakMemory: int}`.

Throws:
- `LogicException`: If the measurement has already been stopped.

### measure()

```php
public static function measure(Closure $callback): array
```

Executes the function once and returns its result and measurements.

Parameters:
- `$callback` (`Closure`, required)

Returns `array{result: T, seconds: float, memory: int}`.

### benchmark()

```php
public static function benchmark(Closure $callback, int $iterations = 1000, int $warmup = 10): array
```

Executes the function $iterations times (after $warmup unmeasured runs) and returns single-run statistics in seconds.

Parameters:
- `$callback` (`Closure`, required)
- `$iterations` (`int`, default `1000`)
- `$warmup` (`int`, default `10`)

Returns `array{iterations: int, total: float, min: float, max: float, average: float, median: float}`.

Throws:
- `InvalidArgumentException`: If $iterations < 1 or $warmup < 0.

### format()

```php
public static function format(float $seconds): string
```

Human-readable duration with decimal comma: '850 ns', '12,3 us', '45,6 ms', '2,41 s', '3 min 5 s', '1 h 2 min'.

Parameters:
- `$seconds` (`float`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the duration is negative, NAN, or INF.

