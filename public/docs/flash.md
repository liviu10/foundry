# Flash

> Flash messaging for sequential requests (success, warning, error, info), exposed as raw arrays.

Canonical: https://foundry.insightdevhub.ro/docs/flash/  
Namespace: `Foundry\Flash` · File: `Flash.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring, session

User notification messages stored in the session until displayed (typically after a redirect): "Policy was saved successfully.", "Access denied.", etc. Returns raw data, not HTML: type, title, message, and metadata (e.g. toast duration); the application template determines presentation.

## Rules

- the PHP session must be active beforehand (Session::start() or session_start())
- types: success, error, warning, info; multiple messages can be queued in a single request
- pull() retrieves messages and clears them from session (display once); peek() inspects them without clearing
- strings are unescaped: HTML escaping must be performed at render time
- meta only accepts simple values (string, number, bool, null, array) to ensure safe session serialization.

## Usage

```php
require_once 'Flash.php';
use Foundry\Flash;

Flash::success('Policy was saved successfully.');
Flash::error('Could not send email.', 'Error', ['delay' => 10000]);
Response::redirect('/policies')->send();

// on the next page
foreach (Flash::pull() as $flash) { ... $flash['type'], $flash['title'], $flash['message'], $flash['meta'] ... }
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Flash.php';

use Foundry\Flash;
```

## Methods

### add()

```php
public static function add(string $type, string $message, ?string $title = null, array $meta = []): void
```

Parameters:
- `$type` (`string`, required)
- `$message` (`string`, required)
- `$title` (`?string`, default `null`)
- `$meta` (`array<string, mixed>`, default `[]`): Display metadata (e.g. ['delay' => 5000, 'redirect' => '/x']).

Throws:
- `InvalidArgumentException`: If type, message, or meta are invalid.
- `LogicException`: If the session is not started.

### success()

```php
public static function success(string $message, ?string $title = null, array $meta = []): void
```

Parameters:
- `$message` (`string`, required)
- `$title` (`?string`, default `null`)
- `$meta` (`array<string, mixed>`, default `[]`)

Throws:
- `InvalidArgumentException`
- `LogicException`

### error()

```php
public static function error(string $message, ?string $title = null, array $meta = []): void
```

Parameters:
- `$message` (`string`, required)
- `$title` (`?string`, default `null`)
- `$meta` (`array<string, mixed>`, default `[]`)

Throws:
- `InvalidArgumentException`
- `LogicException`

### warning()

```php
public static function warning(string $message, ?string $title = null, array $meta = []): void
```

Parameters:
- `$message` (`string`, required)
- `$title` (`?string`, default `null`)
- `$meta` (`array<string, mixed>`, default `[]`)

Throws:
- `InvalidArgumentException`
- `LogicException`

### info()

```php
public static function info(string $message, ?string $title = null, array $meta = []): void
```

Parameters:
- `$message` (`string`, required)
- `$title` (`?string`, default `null`)
- `$meta` (`array<string, mixed>`, default `[]`)

Throws:
- `InvalidArgumentException`
- `LogicException`

### pull()

```php
public static function pull(): array
```

Retrieves messages in the order they were added and clears them from the session.

Returns `list<array{type: string, title: ?string, message: string, meta: array<string, mixed>}>`.

Throws:
- `LogicException`: If the session is not started.
- `UnexpectedValueException`: If session flash data is corrupted.

### peek()

```php
public static function peek(): array
```

Retrieves messages without clearing them from the session.

Returns `list<array{type: string, title: ?string, message: string, meta: array<string, mixed>}>`.

Throws:
- `LogicException`: If the session is not started.
- `UnexpectedValueException`: If session flash data is corrupted.

### has()

```php
public static function has(?string $type = null): bool
```

Checks if any messages exist (optionally filtered by type).

Parameters:
- `$type` (`?string`, default `null`)

Returns `bool`.

Throws:
- `LogicException`: If the session is not started.
- `UnexpectedValueException`: If session flash data is corrupted.

### clear()

```php
public static function clear(): void
```

Throws:
- `LogicException`: If the session is not started.

