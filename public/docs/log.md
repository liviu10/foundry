# Log

> PSR-3 compliant file logger, automatic sensitive data masking, daily log rotation.

Canonical: https://foundry.insightdevhub.ro/docs/log/  
Namespace: `Foundry\Log` · File: `Log.php` · Version 1.2 · PHP 8.3+

File-based logger with PSR-3 levels, JSON context, and sensitive data masking.

Format (one line per message):

```text
[2026-10-30 10:15:02.123] app.ERROR [a1b2c3d4] Payment failed for policy {polita} {"polita":"RCA-123"}
```

## Features

- Configurable minimum log level (e.g., 'info' ignores 'debug')
- PSR-3 placeholder interpolation: {key} in the message is replaced with the value from context
- context['exception'] (Throwable) is formatted with class, message, file, line, and stack trace
- Masking: keys containing password, token, secret, etc. become "******", and in strings "Bearer" tokens and Romanian CNPs (13 digits) are masked
- Newlines in messages are escaped (a single log entry cannot forge additional lines)
- Optional daily rotation (app-2026-10-30.log) with automatic pruning of old log files
- Per-instance identifier (request ID) to correlate logs belonging to the same request.

Failed writes throw an exception: a logger that silently drops messages is more dangerous than a visible failure.

## Usage

```php
require_once 'Log.php';
use Foundry\Log;

$log = new Log(__DIR__.'/storage/logs/app.log', minLevel: 'info', daily: true, maxFiles: 30);
$log->info('User authenticated', ['user_id' => 15]);
$log->error('Payment failed for policy {polita}', ['polita' => 'RCA-123', 'exception' => $e]);
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Log.php';

use Foundry\Log;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `LEVELS` | `array<string, int>` | `[ 'emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3, 'warning' => 4…` | PSR-3 levels, from most severe (0) to least severe (7). |
| `SENSITIVE_KEYS` | `list<string>` | `[ 'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'auth', 'api_key', 'apikey'…` | Key name substrings whose values are masked. |
| `MASK` | `string` | `'******'` | Replacement mask for sensitive data. |

## Methods

### __construct()

```php
public function __construct(
    private string $path,
    private string $minLevel = 'debug',
    private string $channel = 'app',
    private bool $daily = false,
    private int $maxFiles = 0,
    ?array $sensitiveKeys = null,
)
```

Parameters:
- `$path` (`string`, required): The log file path; the directory must exist. With $daily, the date is appended before the extension: app.log -> app-2026-10-30.log.
- `$minLevel` (`string`, default `'debug'`): Minimum level to write (default 'debug' = everything).
- `$channel` (`string`, default `'app'`): Channel name included in each line (e.g., 'app', 'payments').
- `$daily` (`bool`, default `false`): true: one file per day.
- `$maxFiles` (`int`, default `0`): With $daily: number of files to retain (0 = keep all).
- `$sensitiveKeys` (`list<string>|null`, default `null`): Key fragments to mask; null = SENSITIVE_KEYS.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: If the directory does not exist or is not writable.

### emergency()

```php
public function emergency(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### alert()

```php
public function alert(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### critical()

```php
public function critical(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### error()

```php
public function error(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### warning()

```php
public function warning(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### notice()

```php
public function notice(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### info()

```php
public function info(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### debug()

```php
public function debug(string|Stringable $message, array $context = []): void
```

Parameters:
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`)

### log()

```php
public function log(string $level, string|Stringable $message, array $context = []): void
```

Writes a log message at the specified level.

Parameters:
- `$level` (`string`, required): One of the PSR-3 log levels (see LEVELS).
- `$message` (`Stringable|string`, required)
- `$context` (`array<string, mixed>`, default `[]`): Additional context; 'exception' may be a Throwable.

Throws:
- `InvalidArgumentException`: If the level does not exist.
- `RuntimeException`: If writing to the file fails.

### currentFile()

```php
public function currentFile(): string
```

Returns the active log file path (includes the date if $daily is true).

Returns `string`.

### requestId()

```php
public function requestId(): string
```

Returns the instance identifier present on each log line.

Returns `string`.

