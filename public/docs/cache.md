# Cache

> JSON file-based cache with atomic file writes and remember() closure caching.

Canonical: https://foundry.insightdevhub.ro/docs/cache/  
Namespace: `Foundry\Cache` · File: `Cache.php` · Version 1.1 · PHP 8.3+

File-based cache with expiration. Each key is stored in a separate JSON file (filename = key hash).

Values are stored as JSON rather than using serialize(): a tampered cache file cannot instantiate arbitrary objects. Only JSON-serializable values (scalars, null, arrays) can be cached; anything else throws an exception on set() rather than returning an unexpected value on get().

Writes are atomic (temporary file + rename): readers never see partially written files. remember() computes a missing value in one process only: concurrent requests wait for it (no stampede). Corrupted cache files (modified externally) throw an exception instead of silently resulting in a cache miss.

## Usage

```php
require_once 'Cache.php';
use Foundry\Cache;

$cache = new Cache(__DIR__.'/storage/cache');

$rates = $cache->remember('bnr-rates', 3600, fn () => HttpClient::json('GET', $bnrUrl));
$cache->set('settings', ['theme' => 'dark'], ttl: null);   // no expiration
$value = $cache->get('settings', default: []);
$cache->forget('settings');
$cache->prune();                                           // removes expired files (e.g. from cron)
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Cache.php';

use Foundry\Cache;
```

## Methods

### __construct()

```php
public function __construct(string $directory)
```

Parameters:
- `$directory` (`string`, required): Cache directory; must exist and be writable. Use a dedicated directory: clear() deletes all cache files inside it.

Throws:
- `RuntimeException`: If the directory does not exist or is not writable.

### get()

```php
public function get(string $key, mixed $default = null): mixed
```

Returns the cached value or $default if it does not exist or has expired.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `InvalidArgumentException`: If the key is empty.
- `UnexpectedValueException`: If the cache file is corrupted.

### has()

```php
public function has(string $key): bool
```

Checks whether the key exists and has not expired (a stored null value counts as existing).

Parameters:
- `$key` (`string`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If the cache file is corrupted.

### set()

```php
public function set(string $key, mixed $value, ?int $ttl = null): void
```

Stores a value in the cache.

Parameters:
- `$key` (`string`, required): The key (any non-empty string).
- `$value` (`mixed`, required): JSON-serializable value: scalar, null, or array (no objects, resources, NAN/INF).
- `$ttl` (`int|null`, default `null`): Time to live in seconds; null = no expiration.

Throws:
- `InvalidArgumentException`: If the key is empty, $ttl < 1, or the value cannot be stored.
- `RuntimeException`: If the file cannot be written.

### remember()

```php
public function remember(string $key, ?int $ttl, Closure $callback): mixed
```

Gets a value from the cache; if missing, computes it via $callback, caches, and returns it.

Parameters:
- `$key` (`string`, required)
- `$ttl` (`int|null`, required): Time to live in seconds; null = no expiration.
- `$callback` (`Closure`, required)

Returns `mixed`: Cached value (decoded JSON) or the computed value.

Throws:
- `InvalidArgumentException`: If the computed value cannot be stored.

### forget()

```php
public function forget(string $key): bool
```

Deletes a key (does not throw if it does not exist).

Parameters:
- `$key` (`string`, required)

Returns `bool`: True if the key existed and was deleted.

Throws:
- `RuntimeException`: If the file exists but cannot be deleted.

### clear()

```php
public function clear(): int
```

Deletes all cache files in the directory.

Returns `int`: Number of deleted files.

Throws:
- `RuntimeException`: If a file cannot be deleted.

### prune()

```php
public function prune(): int
```

Deletes expired cache files (suitable for a cron job). Corrupted files are also deleted.

Returns `int`: Number of deleted files.

