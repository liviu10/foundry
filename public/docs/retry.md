# Retry

> Exponential backoff retry handler with jitter for transient failures.

Canonical: https://foundry.insightdevhub.ro/docs/retry/  
Namespace: `Foundry\Retry` · File: `Retry.php` · Version 1.1 · PHP 8.3+

Retries an operation that may fail transiently (network, external API, database deadlock), with increasing delay between attempts (exponential backoff) and a random component (jitter), so multiple concurrent processes do not all retry at the exact same moment.

Only exceptions in $retryOn are retried (defaults to all). Programming errors - Error (TypeError, ArgumentCountError...) and LogicException (InvalidArgumentException...) - fail the same way on every attempt, so the broad entries Throwable and Exception never retry them; list such a class explicitly in $retryOn if you really need it. Prefer restricting $retryOn to transient exceptions. After the final attempt, the original exception is thrown (same type, same message).

## Usage

```php
require_once 'Retry.php';
use Foundry\Retry;

$curs = Retry::run(
    fn () => HttpClient::json('GET', $url),
    times: 3,
    delayMs: 200,                              // 200ms, then ~400ms
    retryOn: [RuntimeException::class],
);

Retry::run(fn (int $attempt) => $db->transaction(...), times: 5, when: fn (Throwable $e) => str_contains($e->getMessage(), 'Deadlock'));
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Retry.php';

use Foundry\Retry;
```

## Methods

### run()

```php
public static function run(
    Closure $operation,
    int $times = 3,
    int $delayMs = 100,
    float $multiplier = 2.0,
    int $maxDelayMs = 10_000,
    bool $jitter = true,
    array $retryOn = [Throwable::class],
    ?Closure $when = null,
): mixed
```

Executes $operation up to $times times until it succeeds.

Parameters:
- `$operation` (`Closure`, required)
- `$times` (`int`, default `3`): Total number of attempts (minimum 1).
- `$delayMs` (`int`, default `100`): Delay after the first failed attempt, in milliseconds.
- `$multiplier` (`float`, default `2.0`): Factor by which delay increases after each failure (1 = constant).
- `$maxDelayMs` (`int`, default `10000`): Maximum delay cap, in milliseconds.
- `$jitter` (`bool`, default `true`): True: actual sleep is randomized between 50% and 100% of calculated delay.
- `$retryOn` (`list<class-string<Throwable>>`, default `[…]`): Exception classes to retry; all others are rethrown immediately.
- `$when` (`(Closure(Throwable, int): bool)|null`, default `null`): Additional filter: returning false stops retrying.

Returns `T`: The return value of the first successful attempt.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `Throwable`: The exception from the last attempt (or first non-retried exception).

### delays()

```php
public static function delays(int $times, int $delayMs = 100, float $multiplier = 2.0, int $maxDelayMs = 10_000): array
```

The delays (in milliseconds, without jitter) that would be used between attempts; useful for inspecting configuration: Retry::delays(4, 100) = [100, 200, 400].

Parameters:
- `$times` (`int`, required)
- `$delayMs` (`int`, default `100`)
- `$multiplier` (`float`, default `2.0`)
- `$maxDelayMs` (`int`, default `10000`)

Returns `list<int>`.

Throws:
- `InvalidArgumentException`: On invalid parameters.

