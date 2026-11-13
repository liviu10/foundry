# Debug

> Safe dump() / dd() utilities disabled by default (Debug::enable() required), with automated sensitive data redaction.

Canonical: https://foundry.insightdevhub.ro/docs/debug/  
Namespace: `Foundry\Debug` · File: `Debug.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

dump() and dd() for development: displays the exact type of each value, caller file and line number, plain text in CLI and <pre> formatted in browser. Disabled by default: an accidental dump() left in code throws an exception in production rather than leaking data.

## Rules

- Debug::enable() must be called explicitly, only in development (e.g. during bootstrap after application config)
- sensitive keys and properties (password, token, cnp, iban...) are masked
- recursion depth and string lengths are capped; circular references are detected
- dd() outputs values and terminates script execution
- functions dump() and dd() in namespace Foundry provide shortcuts (use function Foundry\dump;).

## Usage

```php
require_once 'Debug.php';
use Foundry\Debug;

Debug::enable($config['debug']);   // development only
Debug::dump($policy, $_POST);
Debug::dd($result);                // dumps and exits
$text = Debug::export($value);     // string representation without printing
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Debug.php';

use Foundry\Debug;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `SENSITIVE_KEYS` | `list<string>` | `[ 'password', 'passwd', 'pass', 'pwd', 'secret', 'token', 'auth', 'api_key', 'apikey'…` | Substrings of masked keys (as in Log). |

## Methods

### enable()

```php
public static function enable(bool $enabled = true): void
```

Enables (or disables) dump() and dd(). Disabled by default.

Parameters:
- `$enabled` (`bool`, default `true`)

### isEnabled()

```php
public static function isEnabled(): bool
```

Returns `bool`.

### dump()

```php
public static function dump(mixed ...$values): void
```

Dumps values along with the caller's file and line number.

Parameters:
- `$values` (`mixed`)

Throws:
- `LogicException`: If Debug is not enabled.

### dd()

```php
public static function dd(mixed ...$values): never
```

Dumps values and terminates script execution.

Parameters:
- `$values` (`mixed`)

Returns `never`.

Throws:
- `LogicException`: If Debug is not enabled.

### export()

```php
public static function export(mixed $value, int $maxDepth = 10): string
```

Returns string representation of a value (without printing and without checking enable()).

Parameters:
- `$value` (`mixed`, required)
- `$maxDepth` (`int`, default `10`)

Returns `string`.

## Functions

### dump()

```php
function dump(mixed ...$values): void
```

Shortcut for Debug::dump().

Parameters:
- `$values` (`mixed`)

Throws:
- `LogicException`: If Debug is not enabled.

### dd()

```php
function dd(mixed ...$values): never
```

Shortcut for Debug::dd().

Parameters:
- `$values` (`mixed`)

Returns `never`.

Throws:
- `LogicException`: If Debug is not enabled.

