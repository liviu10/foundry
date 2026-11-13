# Csrf

> Session-bound anti-CSRF token verification for POST/PUT/PATCH/DELETE requests.

Canonical: https://foundry.insightdevhub.ro/docs/csrf/  
Namespace: `Foundry\Csrf` · File: `Csrf.php` · Version 1.0 · PHP 8.3+ · Extensions: session

Protection against Cross-Site Request Forgery: a session-scoped random token, embedded in forms and validated on every state-changing request (POST, PUT, PATCH, DELETE).

## Rules

- the PHP session must be started beforehand (Session::start() or session_start())
- the token contains 256 random bits and is compared in constant time
- check() accepts the token from the form field or from the X-CSRF-TOKEN header (AJAX); GET, HEAD, and OPTIONS requests are not checked (they must not modify data)
- an invalid token throws an UnexpectedValueException with code 419 (in application: 419 / 403 response)
- call regenerate() after authentication.

## Usage

```php
require_once 'Csrf.php';
use Foundry\Csrf;

<form method="post"><?= Csrf::field() ?> ... </form>
<meta name="csrf-token" content="<?= Csrf::token() ?>">     // for fetch(): X-CSRF-TOKEN header

Csrf::check();                                              // at the start of request handling, before processing
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Csrf.php';

use Foundry\Csrf;
```

## Methods

### token()

```php
public static function token(): string
```

Returns the current session token (generated on first access), 64 hexadecimal characters.

Returns `string`.

Throws:
- `LogicException`: If the session is not started.

### field()

```php
public static function field(string $name = '_token'): string
```

Generates a hidden form input: <input type="hidden" name="_token" value="...">.

Parameters:
- `$name` (`string`, default `'_token'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the field name is invalid.
- `LogicException`: If the session is not started.

### verify()

```php
public static function verify(mixed $token): bool
```

Verifies an incoming token. Returns false for anything that is not exactly equal to the session token.

Parameters:
- `$token` (`mixed`, required)

Returns `bool`.

Throws:
- `LogicException`: If the session is not started.

### check()

```php
public static function check(?array $post = null, ?array $server = null, string $field = '_token'): void
```

Validates the current request: for state-changing HTTP methods, the token in $field (or the X-CSRF-TOKEN header) must match the session token.

Parameters:
- `$post` (`array<array-key, mixed>|null`, default `null`): Defaults to $_POST.
- `$server` (`array<array-key, mixed>|null`, default `null`): Defaults to $_SERVER.
- `$field` (`string`, default `'_token'`)

Throws:
- `UnexpectedValueException`: With code 419 if the token is missing or invalid.
- `InvalidArgumentException`: If the field name is invalid.
- `LogicException`: If the session is not started.

### regenerate()

```php
public static function regenerate(): string
```

Generates a new token (after authentication or privilege changes); previous forms become invalid.

Returns `string`.

Throws:
- `LogicException`: If the session is not started.

