# Response

> Immutable HTTP response object dispatched via send(): JSON, redirects, streaming downloads, secure cookies.

Canonical: https://foundry.insightdevhub.ro/docs/response/  
Namespace: `Foundry\Response` · File: `Response.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

HTTP response constructed as an object and explicitly emitted with send(). Natively, header() after output emits only the "headers already sent" warning and the response is sent improperly, while a "\r\n" inside a header value allows header injection. Here, send() throws an exception with the file and line where output began, and headers, cookies, and redirects are validated upon construction.

## Rules

- The object is immutable: with...() returns a copy; nothing is sent until send()
- send() does not terminate the script (no exit): the application decides what follows
- Cookies default to HttpOnly, Secure, SameSite=Lax, Path=/
- redirect() only accepts paths and http(s) URLs; for addresses received from clients see Url::isLocal()
- Downloaded files are streamed in chunks without buffering entire contents in memory.

## Usage

```php
require_once 'Response.php';
use Foundry\Response;

Response::json(['id' => 5], 201)->send();
Response::redirect('/login')->send();
Response::html($html)->noCache()->withCookie('tema', 'dark', expires: time() + 86400)->send();
Response::download('/data/raport.pdf', 'Raport martie.pdf')->send();
Response::error(404)->send();
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Response.php';

use Foundry\Response;
```

## Methods

### make()

```php
public static function make(string $body = '', int $status = 200, array $headers = []): self
```

Generic response.

Parameters:
- `$body` (`string`, default `''`)
- `$status` (`int`, default `200`)
- `$headers` (`array<string, string>`, default `[]`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If status code or a header is invalid.

### html()

```php
public static function html(string $html, int $status = 200): self
```

Parameters:
- `$html` (`string`, required)
- `$status` (`int`, default `200`)

Returns `Foundry\Response`.

### text()

```php
public static function text(string $text, int $status = 200): self
```

Parameters:
- `$text` (`string`, required)
- `$status` (`int`, default `200`)

Returns `Foundry\Response`.

### json()

```php
public static function json(mixed $data, int $status = 200): self
```

JSON in UTF-8 with human-readable unicode and unescaped slashes; 1.0 remains 1.0.

Parameters:
- `$data` (`mixed`, required)
- `$status` (`int`, default `200`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If data cannot be encoded (invalid UTF-8, resources, NAN).

### redirect()

```php
public static function redirect(string $url, int $status = 302): self
```

Redirects to a path ('/login', 'polite?id=1') or an http(s) URL.

Parameters:
- `$url` (`string`, required)
- `$status` (`int`, default `302`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the URL is empty, contains control characters, specifies an unsupported scheme (javascript:, data:), or if the status code is not a redirect status.

### error()

```php
public static function error(int $status, ?string $message = null): self
```

Plain text error response; without a message, the standard reason phrase is used (e.g. 'Not Found').

Parameters:
- `$status` (`int`, required)
- `$message` (`?string`, default `null`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the status code is not 4xx or 5xx.

### noContent()

```php
public static function noContent(): self
```

Returns `Foundry\Response`.

### download()

```php
public static function download(string $path, ?string $name = null, string $mimeType = 'application/octet-stream'): self
```

Downloads a file from disk, streamed in chunks on send(). The displayed name defaults to the file name; default MIME type is application/octet-stream.

Parameters:
- `$path` (`string`, required)
- `$name` (`?string`, default `null`)
- `$mimeType` (`string`, default `'application/octet-stream'`)

Returns `Foundry\Response`.

Throws:
- `RuntimeException`: If the file does not exist or is not readable.
- `InvalidArgumentException`: If the name or MIME type is invalid.

### downloadContent()

```php
public static function downloadContent(string $content, string $name, string $mimeType = 'application/octet-stream'): self
```

Downloads dynamically generated content (CSV, PDF, etc.) as a file.

Parameters:
- `$content` (`string`, required)
- `$name` (`string`, required)
- `$mimeType` (`string`, default `'application/octet-stream'`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the name or MIME type is invalid.

### withStatus()

```php
public function withStatus(int $status): self
```

Parameters:
- `$status` (`int`, required)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If status is not between 100 and 599.

### withHeader()

```php
public function withHeader(string $name, string $value, bool $replace = true): self
```

Sets a header; with $replace = false, appends an additional value (e.g. Link).

Parameters:
- `$name` (`string`, required)
- `$value` (`string`, required)
- `$replace` (`bool`, default `true`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the name is invalid or the value contains newlines.

### withoutHeader()

```php
public function withoutHeader(string $name): self
```

Parameters:
- `$name` (`string`, required)

Returns `Foundry\Response`.

### withCookie()

```php
public function withCookie(
    string $name,
    string $value,
    int $expires = 0,
    string $path = '/',
    string $domain = '',
    bool $secure = true,
    bool $httpOnly = true,
    string $sameSite = 'Lax',
): self
```

Adds a cookie. Defaults: session cookie (expires 0), HttpOnly, Secure, SameSite=Lax, Path=/.

Parameters:
- `$name` (`string`, required)
- `$value` (`string`, required)
- `$expires` (`int`, default `0`)
- `$path` (`string`, default `'/'`)
- `$domain` (`string`, default `''`)
- `$secure` (`bool`, default `true`)
- `$httpOnly` (`bool`, default `true`)
- `$sameSite` (`string`, default `'Lax'`): Lax, Strict, or None (None requires secure: true).

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the name, value, or options are invalid.

### withoutCookie()

```php
public function withoutCookie(string $name, string $path = '/', string $domain = ''): self
```

Deletes a cookie from the browser (using the same path and domain as when created).

Parameters:
- `$name` (`string`, required)
- `$path` (`string`, default `'/'`)
- `$domain` (`string`, default `''`)

Returns `Foundry\Response`.

Throws:
- `InvalidArgumentException`: If the cookie name is invalid.

### noCache()

```php
public function noCache(): self
```

Disallows caching (pages with private data, forms with CSRF tokens).

Returns `Foundry\Response`.

### status()

```php
public function status(): int
```

Returns `int`.

### header()

```php
public function header(string $name): ?string
```

The first value of a header or null.

Parameters:
- `$name` (`string`, required)

Returns `?string`.

### headers()

```php
public function headers(): array
```

Returns `array<string, list<string>>`: Header names as provided -> values.

### cookies()

```php
public function cookies(): array
```

Returns `array<string, array{value: string, options: array{expires: int, path: string, domain: string, secure: bool, httponly: bool, samesite: 'Lax'|'Strict'|'None'}}>`.

### body()

```php
public function body(): string
```

The response body; for download() this is the file content (read on demand).

Returns `string`.

Throws:
- `RuntimeException`: If the file cannot be read.

### send()

```php
public function send(): void
```

Sends the status code, headers, cookies, and body. Does not terminate the script.

Throws:
- `LogicException`: If headers have already been sent (including output file and line).
- `RuntimeException`: If a cookie cannot be set or the file cannot be read.

