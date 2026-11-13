# RateLimiter

> Sliding/fixed window rate limiting by key (e.g., client IP), concurrency-safe.

Canonical: https://foundry.insightdevhub.ro/docs/rate-limiter/  
Namespace: `Foundry\RateLimiter` · File: `RateLimiter.php` · Version 1.1 · PHP 8.3+

Limits the number of attempts on a key within a given time window (e.g., 5 login attempts per minute per IP). The counter is persisted in files with an exclusive lock (flock), ensuring correctness even when multiple concurrent PHP requests execute simultaneously.

The window is fixed: the first attempt initiates the $decaySeconds window; upon expiration, the counter resets.

Storage errors (missing directory, locked file) throw an exception: a limiter that permits everything to pass through when unable to write provides no protection.

## Usage

```php
require_once 'RateLimiter.php';
use Foundry\RateLimiter;

$limiter = new RateLimiter(__DIR__.'/storage/rate-limits');

// Approach 1: Exception (caught globally and converted to HTTP 429)
$limiter->enforce('login:'.$ip, maxAttempts: 5, decaySeconds: 60);

// Approach 2: Explicit check
if (! $limiter->attempt('contact:'.$ip, 3, 3600)) {
    $wait = $limiter->availableIn('contact:'.$ip);   // seconds until reset
}

$limiter->clear('login:'.$ip);                        // after successful authentication

$limiter->prune();                                    // cron: deletes files of expired windows
```

Read-only methods (attempts, remaining, tooManyAttempts, availableIn) never create files.

## Installation

```php
require_once __DIR__.'/lib/Utilities/RateLimiter.php';

use Foundry\RateLimiter;
```

## Methods

### __construct()

```php
public function __construct(string $directory)
```

Parameters:
- `$directory` (`string`, required): Directory for state files; must exist and be writable.

Throws:
- `RuntimeException`: If the directory does not exist or is not writable.

### attempt()

```php
public function attempt(string $key, int $maxAttempts, int $decaySeconds): bool
```

Records an attempt if the rate limit has not been reached.

Parameters:
- `$key` (`string`, required)
- `$maxAttempts` (`int`, required)
- `$decaySeconds` (`int`, required)

Returns `bool`: true if the attempt is allowed (and counted), false if the limit was reached.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: On storage error.

### enforce()

```php
public function enforce(string $key, int $maxAttempts, int $decaySeconds): void
```

Similar to attempt(), but throws an exception when the rate limit is reached.

Parameters:
- `$key` (`string`, required)
- `$maxAttempts` (`int`, required)
- `$decaySeconds` (`int`, required)

Throws:
- `OverflowException`: If the limit has been reached (message specifies seconds to wait).
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: On storage error.

### hit()

```php
public function hit(string $key, int $decaySeconds): int
```

Increments the attempt counter without verifying limits (e.g., following an invalid password).

Parameters:
- `$key` (`string`, required)
- `$decaySeconds` (`int`, required)

Returns `int`: Number of attempts recorded in the current window.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: On storage error.

### attempts()

```php
public function attempts(string $key): int
```

Number of attempts recorded in the current window.

Parameters:
- `$key` (`string`, required)

Returns `int`.

### remaining()

```php
public function remaining(string $key, int $maxAttempts): int
```

Remaining attempts permitted within the current window.

Parameters:
- `$key` (`string`, required)
- `$maxAttempts` (`int`, required)

Returns `int`.

### tooManyAttempts()

```php
public function tooManyAttempts(string $key, int $maxAttempts): bool
```

Checks whether the limit has been reached without incrementing the counter.

Parameters:
- `$key` (`string`, required)
- `$maxAttempts` (`int`, required)

Returns `bool`.

### availableIn()

```php
public function availableIn(string $key): int
```

Seconds remaining until the counter resets (0 if no attempts are active).

Parameters:
- `$key` (`string`, required)

Returns `int`.

### prune()

```php
public function prune(): int
```

Deletes state files whose window has expired. Each key keeps its file until then, so with unbounded keys (one per IP or username) call this periodically, e.g. from a cron job. Files locked by a concurrent request are skipped and removed on a later run.

Returns `int`: Number of files deleted.

### clear()

```php
public function clear(string $key): void
```

Resets the counter (e.g., after successful authentication).

Parameters:
- `$key` (`string`, required)

Throws:
- `RuntimeException`: If the state file cannot be deleted.

