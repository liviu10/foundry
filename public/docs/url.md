# Url

> Query parameter builder, current URL generation against trusted hosts, local redirect validation (isLocal).

Canonical: https://foundry.insightdevhub.ro/docs/url/  
Namespace: `Foundry\Url` · File: `Url.php` · Version 1.0 · PHP 8.3+

Reliable URL building and parsing without surprises. Natively, parse_url() returns false or partial results for malformed URLs, and absolute URLs constructed from the Host header can be spoofed by clients (e.g. password reset links directed to an attacker's domain).

## Rules

- URLs with spaces, control characters, or backslashes ("\") are rejected
- query parameters are added or replaced without modifying other parameters (null removes a parameter)
- current() only accepts hosts from the provided trusted host list
- isLocal() determines whether a client-provided redirect target (e.g. ?next=) is safe.

## Usage

```php
require_once 'Url.php';
use Foundry\Url;

Url::with('/policies?page=2', ['page' => 3, 'q' => 'auto']);   // '/policies?page=3&q=auto'
Url::with('/policies?page=2&q=x', ['q' => null]);              // '/policies?page=2'
Url::absolute('https://app.example.com/', 'reset', ['token' => $token]);
Url::current(['app.example.com']);                             // 'https://app.example.com/policies?page=2'
$next = Url::isLocal($_GET['next'] ?? '') ? $_GET['next'] : '/';
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Url.php';

use Foundry\Url;
```

## Methods

### parse()

```php
public static function parse(string $url): array
```

Components of a URL. Missing components are null (path is '' when missing).

Parameters:
- `$url` (`string`, required)

Returns `array{scheme: ?string, host: ?string, port: ?int, user: ?string, pass: ?string, path: string, query: ?string, fragment: ?string}`.

Throws:
- `InvalidArgumentException`: If the URL is empty or malformed.

### with()

```php
public static function with(string $url, array $query): string
```

Adds or replaces query parameters. Other parameters remain unchanged; null removes a parameter; arrays are formatted as a[]=1&a[]=2. The fragment (#...) is preserved.

Parameters:
- `$url` (`string`, required)
- `$query` (`array<string, mixed>`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the URL is malformed or a value cannot be placed in a URL.

### without()

```php
public static function without(string $url, string ...$keys): string
```

Removes query parameters (including array forms such as a[]=..., a[x]=...).

Parameters:
- `$url` (`string`, required)
- `$keys` (`string`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the URL is malformed.

### absolute()

```php
public static function absolute(string $base, string $path = '', array $query = []): string
```

Absolute URL built from the application base address (from configuration, not from the request). absolute('https://app.ro/portal/', '/policies', ['id' => 5]) -> 'https://app.ro/portal/policies?id=5'

Parameters:
- `$base` (`string`, required)
- `$path` (`string`, default `''`)
- `$query` (`array<string, mixed>`, default `[]`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the base is not an absolute http(s) URL without query, or if $path is an absolute URL (which would leave the application).

### current()

```php
public static function current(array $trustedHosts, ?array $server = null, bool $trustForwarded = false): string
```

Absolute URL of the current request. The host (without port) must be present in $trustedHosts: exact names ('app.example.com') or wildcards ('*.example.com'). With $trustForwarded, the scheme is read from X-Forwarded-Proto (only behind a trusted proxy).

Parameters:
- `$trustedHosts` (`list<string>`, required)
- `$server` (`array<array-key, mixed>|null`, default `null`): Defaults to $_SERVER.
- `$trustForwarded` (`bool`, default `false`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the trusted hosts list is empty.
- `UnexpectedValueException`: If the request host is missing or not in the trusted list.

### isLocal()

```php
public static function isLocal(string $url): bool
```

Determines whether the address is safe for redirecting within the application: local absolute path ('/policies?id=1'). Rejects external URLs, '//evil.ro' (scheme-relative), '/\evil.ro' (treated as '//' by browsers), 'javascript:', and control characters.

Parameters:
- `$url` (`string`, required)

Returns `bool`.

