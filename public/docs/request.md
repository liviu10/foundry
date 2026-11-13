# Request

> Encapsulates incoming HTTP requests with typed getters (int('id'): missing or non-integer throws exception), JSON body decoding, trusted proxy IP resolution.

Canonical: https://foundry.insightdevhub.ro/docs/request/  
Namespace: `Foundry\Request` · File: `Request.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

Current HTTP request reading with strict types. Natively, $_GET['id'] might be missing, could be an array (?id[]=1), or arbitrary text, while (int) 'abc' = 0. Here, typed getters return exactly the requested type or throw an exception.

## Rules

- int('id'), string('name'), ... : missing, empty, or invalid parameter = UnexpectedValueException (in application: 400 response); OrNull variants return null when missing or empty
- input() looks in the request body (form, JSON, urlencoded PUT/PATCH/DELETE), then in the query string
- invalid UTF-8 strings and arrays passed where a string is expected are rejected
- ip() only uses X-Forwarded-For from explicitly specified trusted proxies
- error messages contain the parameter name, not the value.

## Usage

```php
require_once 'Request.php';
use Foundry\Request;

$request = Request::fromGlobals();
$id = $request->int('id');                        // '12' -> 12; missing, '12abc', ?id[]=1 -> throws exception
$page = $request->intOrNull('page') ?? 1;
$name = $request->string('name');
$date = $request->date('date', 'd.m.Y');          // DateTimeImmutable, real calendar date
$active = $request->boolOrNull('active') ?? false; // checkbox
if ($request->isPost() && $request->wantsJson()) { ... }
$ip = $request->ip(['10.0.0.0/8']);               // behind an internal proxy
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Request.php';

use Foundry\Request;
```

## Methods

### __construct()

```php
public function __construct(
    private readonly array $query = [],
    private readonly array $post = [],
    private readonly array $server = [],
    private readonly array $files = [],
    private readonly array $cookies = [],
    private ?string $body = null,
)
```

Parameters:
- `$query` (`array<array-key, mixed>`, default `[]`): URL query parameters ($_GET).
- `$post` (`array<array-key, mixed>`, default `[]`): Form parameters ($_POST).
- `$server` (`array<array-key, mixed>`, default `[]`): $_SERVER variables.
- `$files` (`array<array-key, mixed>`, default `[]`): Uploaded files ($_FILES).
- `$cookies` (`array<array-key, mixed>`, default `[]`): Cookie values ($_COOKIE).
- `$body` (`string|null`, default `null`): Raw request body; null = read from php://input on first access.

### fromGlobals()

```php
public static function fromGlobals(): self
```

Returns `Foundry\Request`.

### method()

```php
public function method(): string
```

HTTP method in uppercase (defaults to GET).

Returns `string`.

Throws:
- `UnexpectedValueException`: If the method is not a valid HTTP method name.

### isMethod()

```php
public function isMethod(string $method): bool
```

Parameters:
- `$method` (`string`, required)

Returns `bool`.

### isGet()

```php
public function isGet(): bool
```

Returns `bool`.

### isPost()

```php
public function isPost(): bool
```

Returns `bool`.

### isAjax()

```php
public function isAjax(): bool
```

Request initiated via JavaScript (X-Requested-With: XMLHttpRequest).

Returns `bool`.

### isJson()

```php
public function isJson(): bool
```

The request body is JSON (Content-Type application/json or +json).

Returns `bool`.

### wantsJson()

```php
public function wantsJson(): bool
```

The client expects JSON (Accept header contains json or the request is AJAX).

Returns `bool`.

### isSecure()

```php
public function isSecure(bool $trustForwarded = false): bool
```

HTTPS connection. With $trustForwarded, X-Forwarded-Proto is used (only behind a trusted proxy).

Parameters:
- `$trustForwarded` (`bool`, default `false`)

Returns `bool`.

### uri()

```php
public function uri(): string
```

Request URI path and query ('/polite?page=2'), without scheme and host.

Returns `string`.

### path()

```php
public function path(): string
```

Request path without query string ('/polite'), unencoded.

Returns `string`.

### header()

```php
public function header(string $name): ?string
```

Header value (case-insensitive) or null.

Parameters:
- `$name` (`string`, required)

Returns `?string`.

### headers()

```php
public function headers(): array
```

All headers, with names in normalized 'content-type' format.

Returns `array<string, string>`.

### bearerToken()

```php
public function bearerToken(): ?string
```

Token extracted from 'Authorization: Bearer ...' or null.

Returns `?string`.

### userAgent()

```php
public function userAgent(): ?string
```

Returns `?string`.

### cookie()

```php
public function cookie(string $name): ?string
```

Parameters:
- `$name` (`string`, required)

Returns `?string`.

### ip()

```php
public function ip(array $trustedProxies = []): string
```

Client IP address. If REMOTE_ADDR matches one of $trustedProxies (IPs or CIDR: '10.0.0.0/8'), X-Forwarded-For is traversed from right to left, returning the first non-trusted IP. Without trusted proxies, the header is ignored (it could be spoofed by the client).

Parameters:
- `$trustedProxies` (`list<string>`, default `[]`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If an entry in $trustedProxies is invalid.
- `UnexpectedValueException`: If REMOTE_ADDR is missing or not a valid IP address.

### query()

```php
public function query(string $key, mixed $default = null): mixed
```

Parameter from query string as received (string, array, or null if missing).

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

### post()

```php
public function post(string $key, mixed $default = null): mixed
```

Parameter from request body (form, JSON, urlencoded PUT/PATCH/DELETE) as received.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### input()

```php
public function input(string $key, mixed $default = null): mixed
```

Parameter from body or, if missing, from query string, as received.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### all()

```php
public function all(): array
```

All parameters: query string overridden by body.

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### only()

```php
public function only(string ...$keys): array
```

Parameters:
- `$keys` (`string`)

Returns `array<array-key, mixed>`: Only requested keys that exist.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### except()

```php
public function except(string ...$keys): array
```

Parameters:
- `$keys` (`string`)

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### has()

```php
public function has(string $key): bool
```

Checks if the parameter exists (even if empty).

Parameters:
- `$key` (`string`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### filled()

```php
public function filled(string $key): bool
```

Checks if the parameter exists and is not blank (null, '', whitespace-only, []).

Parameters:
- `$key` (`string`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If JSON body is invalid.

### string()

```php
public function string(string $key): string
```

String value (unmodified: whitespace is preserved).

Parameters:
- `$key` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If missing, empty, not a string, or invalid UTF-8.

### stringOrNull()

```php
public function stringOrNull(string $key): ?string
```

Parameters:
- `$key` (`string`, required)

Returns `?string`.

Throws:
- `UnexpectedValueException`: If not a string or invalid UTF-8.

### int()

```php
public function int(string $key): int
```

Integer value: '12', '-5' (trimmed whitespace; '012', '1.0', '12abc' rejected).

Parameters:
- `$key` (`string`, required)

Returns `int`.

Throws:
- `UnexpectedValueException`: If missing, empty, or not an integer.

### intOrNull()

```php
public function intOrNull(string $key): ?int
```

Parameters:
- `$key` (`string`, required)

Returns `?int`.

Throws:
- `UnexpectedValueException`: If not an integer.

### float()

```php
public function float(string $key): float
```

Float value with decimal point: '1.5', '-3' (no comma, exponent, or thousands separator).

Parameters:
- `$key` (`string`, required)

Returns `float`.

Throws:
- `UnexpectedValueException`: If missing, empty, or not a number.

### floatOrNull()

```php
public function floatOrNull(string $key): ?float
```

Parameters:
- `$key` (`string`, required)

Returns `?float`.

Throws:
- `UnexpectedValueException`: If not a number.

### bool()

```php
public function bool(string $key): bool
```

Boolean: true/false, 1/0, yes/no, on/off, da/nu (case-insensitive).

Parameters:
- `$key` (`string`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If missing, empty, or not a recognized boolean value.

### boolOrNull()

```php
public function boolOrNull(string $key): ?bool
```

An unchecked checkbox is omitted entirely: boolOrNull('activ') ?? false.

Parameters:
- `$key` (`string`, required)

Returns `?bool`.

Throws:
- `UnexpectedValueException`: If not a recognized boolean value.

### date()

```php
public function date(string $key, string $format = 'Y-m-d'): DateTimeImmutable
```

Real calendar date strictly matching the format (e.g. 31.02 and 2-digit years rejected).

Parameters:
- `$key` (`string`, required)
- `$format` (`string`, default `'Y-m-d'`)

Returns `DateTimeImmutable`.

Throws:
- `UnexpectedValueException`: If missing, empty, or not a valid date.

### dateOrNull()

```php
public function dateOrNull(string $key, string $format = 'Y-m-d'): ?DateTimeImmutable
```

Parameters:
- `$key` (`string`, required)
- `$format` (`string`, default `'Y-m-d'`)

Returns `?DateTimeImmutable`.

Throws:
- `UnexpectedValueException`: If not a valid date in the given format.

### array()

```php
public function array(string $key): array
```

List of values (multi-select, checkboxes name="x[]"). Missing or empty = [].

Parameters:
- `$key` (`string`, required)

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: If value is not an array.

### body()

```php
public function body(): string
```

Raw request body.

Returns `string`.

Throws:
- `RuntimeException`: If php://input cannot be read.

### json()

```php
public function json(): array
```

Decoded JSON body. Must be a JSON object or array.

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: If body is empty, invalid JSON, or a scalar.

### file()

```php
public function file(string $key): ?array
```

The $_FILES entry for a field or null if no file was uploaded. Validation (MIME inspection, size, storage) is performed via Upload.

Parameters:
- `$key` (`string`, required)

Returns `array<array-key, mixed>|null`.

