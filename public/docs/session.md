# Session

> Explicit session initialization with hardened cookie options, flash data, old() form retention, idle timeout invalidation.

Canonical: https://foundry.insightdevhub.ro/docs/session/  
Namespace: `Foundry\Session` · File: `Session.php` · Version 1.1 · PHP 8.3+ · Extensions: session

PHP sessions started with secure defaults and managed via explicit methods. Natively, session_start() after output produces only a warning, client-supplied IDs are accepted (session fixation if use_strict_mode is disabled), and values that cannot be serialized (Closure) are silently lost at the end of the request.

## Rules

- start() must be called explicitly; cookie HttpOnly, SameSite=Lax, Secure on HTTPS (also behind a TLS proxy), strict mode, session ID accepted only from the cookie (never from the URL)
- Accessing the session before calling start() = LogicException (not a standard lost $_SESSION)
- get() without a default value throws an exception if the key is missing
- After authentication call regenerate(); on logout call destroy()
- flash() retains a value only for the subsequent request (flash messages after redirect), flashInput() / old() repopulates forms after failed validation
- idleTimeout clears the session after a specified period of inactivity.

## Usage

```php
require_once 'Session.php';
use Foundry\Session;

Session::start(['name' => 'APPSESSID', 'idleTimeout' => 1800]);
Session::set('user_id', 5);
$userId = Session::get('user_id');             // missing -> throws exception
$tema = Session::get('tema', 'light');         // missing -> 'light'
Session::regenerate();                         // after login
Session::flash('status', 'Polita a fost salvata.');
$status = Session::flashed('status');          // in the next request
Session::destroy();                            // logout
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Session.php';

use Foundry\Session;
```

## Methods

### start()

```php
public static function start(array $options = []): void
```

Starts the session. If already active (e.g. started by legacy code), cookie options are ignored, but idleTimeout and the flash rotation are still applied (once per request).

Options: name         Cookie name (defaults to php.ini setting); lifetime     Cookie lifetime in seconds (0 = until browser is closed); path, domain Cookie path and domain (defaults to '/' and current domain); secure       Defaults to true over HTTPS, including behind a proxy sending X-Forwarded-Proto: https; sameSite     Lax (default), Strict, or None; savePath     Session files directory path; idleTimeout  Seconds of inactivity after which session is cleared (see expired()).

Parameters:
- `$options` (`array<string, mixed>`, default `[]`)

Throws:
- `InvalidArgumentException`: If an option is unrecognized or invalid.
- `LogicException`: If headers have already been sent.
- `RuntimeException`: If session cannot be started.

### isStarted()

```php
public static function isStarted(): bool
```

Returns `bool`.

### expired()

```php
public static function expired(): bool
```

Checks if the session was cleared during start() due to inactivity (idleTimeout).

Returns `bool`.

### id()

```php
public static function id(): string
```

Returns `string`.

Throws:
- `LogicException`: If the session is not started.

### get()

```php
public static function get(string $key, mixed $default = null): mixed
```

Retrieves the value of a key. With a single argument, the key is required; with two arguments, the second is the default.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `LogicException`: If the session is not started.
- `OutOfBoundsException`: If the key does not exist and no default value was provided.

### set()

```php
public static function set(string $key, mixed $value): void
```

Parameters:
- `$key` (`string`, required)
- `$value` (`mixed`, required)

Throws:
- `LogicException`: If the session is not started.
- `InvalidArgumentException`: If the key is reserved or the value cannot be stored (Closure, resource).

### has()

```php
public static function has(string $key): bool
```

Parameters:
- `$key` (`string`, required)

Returns `bool`.

Throws:
- `LogicException`: If the session is not started.

### forget()

```php
public static function forget(string $key): void
```

Parameters:
- `$key` (`string`, required)

Throws:
- `LogicException`: If the session is not started.

### pull()

```php
public static function pull(string $key, mixed $default = null): mixed
```

Retrieves the value and removes it from the session.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `LogicException`: If the session is not started.
- `OutOfBoundsException`: If the key does not exist and no default value was provided.

### all()

```php
public static function all(): array
```

All application values (excluding internal metadata).

Returns `array<array-key, mixed>`.

Throws:
- `LogicException`: If the session is not started.

### clear()

```php
public static function clear(): void
```

Clears all application values; the session remains active with the same ID.

Throws:
- `LogicException`: If the session is not started.

### regenerate()

```php
public static function regenerate(): void
```

Generates a new session ID while preserving data; invalidates the previous ID. Call after authentication and privilege changes (mitigates session fixation).

Throws:
- `LogicException`: If the session is not started or headers were sent.
- `RuntimeException`: If the session ID cannot be regenerated.

### destroy()

```php
public static function destroy(): void
```

Logout: clears session data, deletes the session cookie, and destroys server-side session.

Throws:
- `LogicException`: If the session is not started or headers were sent.
- `RuntimeException`: If the session cannot be destroyed.

### close()

```php
public static function close(): void
```

Writes session data and releases the file lock (for long-running requests: exports, reports). After close(), the session can no longer be modified within the current request.

Throws:
- `LogicException`: If the session is not started.
- `RuntimeException`: If the session cannot be written.

### flash()

```php
public static function flash(string $key, mixed $value): void
```

Stores a value available only in the subsequent request (e.g. flash message after redirect).

Parameters:
- `$key` (`string`, required)
- `$value` (`mixed`, required)

Throws:
- `LogicException`: If the session is not started.
- `InvalidArgumentException`: If the value cannot be stored.

### flashed()

```php
public static function flashed(string $key, mixed $default = null): mixed
```

Retrieves a value flashed in the previous request or returns $default.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `LogicException`: If the session is not started.

### flashInput()

```php
public static function flashInput(array $input): void
```

Retains form input for the subsequent request (after validation failure and redirect). Do not save passwords: omit them from $input.

Parameters:
- `$input` (`array<array-key, mixed>`, required)

Throws:
- `LogicException`: If the session is not started.
- `InvalidArgumentException`: If input data cannot be stored.

### old()

```php
public static function old(string $key, mixed $default = null): mixed
```

Retrieves form input flashed in the previous request (for repopulation) or returns $default.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `LogicException`: If the session is not started.

