# HttpClient

> cURL wrapper converting network failures and non-2xx statuses to exceptions, SSL verification enabled, default timeouts.

Canonical: https://foundry.insightdevhub.ro/docs/http-client/  
Namespace: `Foundry\HttpClient` · File: `HttpClient.php` · Version 1.2 · PHP 8.3+ · Extensions: curl

HTTP client built on cURL, with clear error handling and safe defaults.

Natively, curl_exec() returns false (errors must be retrieved separately), while a 404/500 response is treated as "successful". Here:

- network error (DNS, timeout, SSL) -> RuntimeException
- non-2xx response -> RuntimeException (or returned as response with throwOnError: false)
- only http/https protocols allowed, including across redirects (no file://, gopher://, etc.)
- SSL certificate is verified by default (disabling requires explicit verifySsl: false)
- mandatory timeout (default 30s total, 10s connection), covering the whole redirect chain
- response body capped by maxBytes (default 50 MB, counted after gzip/deflate decompression)
- redirects (only when followRedirects > 0): https -> http is refused; 303, and 301/302 after a POST, continue as GET without a body; when the origin (scheme, host, port) changes, auth, bearer and custom headers are not sent to the new host
- unknown option (e.g. 'timout') -> InvalidArgumentException.

Error messages omit the query string (which may contain sensitive tokens) and response body (which may contain personal data), including only method, query-stripped URL, status code, and size.

## Options

```text
query           array        parameters appended to URL
headers         array        ['Name' => 'value']
json            mixed        JSON payload (sets Content-Type: application/json)
form            array        application/x-www-form-urlencoded payload
body            string       raw body payload
timeout         float        total timeout in seconds (default 30)
connectTimeout  float        connection timeout in seconds (default 10)
followRedirects int          max redirects to follow (default 0 = do not follow)
maxBytes        int          maximum response body size in bytes (default 50 MB)
auth            array        [username, password] for Basic Auth
bearer          string       token for "Authorization: Bearer ..."
proxy           string       e.g. 'http://proxy.example.com:8080'
verifySsl       bool         default true
caInfo          string       CA bundle file path (when PHP has no curl.cainfo configured)
throwOnError    bool         default true; false = return non-2xx responses without throwing
```

## Usage

```php
require_once 'HttpClient.php';
use Foundry\HttpClient;

$response = HttpClient::get('https://api.example.com/policies', ['query' => ['page' => 2], 'bearer' => $token]);
$data = HttpClient::json('POST', 'https://api.example.com/payments', ['json' => ['amount' => 150]]);

$response = HttpClient::post($url, ['json' => $payload, 'throwOnError' => false]);
if ($response['status'] === 422) { $errors = Json::decodeArray($response['body']); }
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/HttpClient.php';

use Foundry\HttpClient;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `DEFAULT_MAX_BYTES` | `int` | `52_428_800` | Default response body limit (bytes, after decompression): 50 MB. |

## Methods

### get()

```php
public static function get(string $url, array $options = []): array
```

Parameters:
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `array{status: int, headers: array<string, string>, body: string}`.

### post()

```php
public static function post(string $url, array $options = []): array
```

Parameters:
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `array{status: int, headers: array<string, string>, body: string}`.

### put()

```php
public static function put(string $url, array $options = []): array
```

Parameters:
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `array{status: int, headers: array<string, string>, body: string}`.

### patch()

```php
public static function patch(string $url, array $options = []): array
```

Parameters:
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `array{status: int, headers: array<string, string>, body: string}`.

### delete()

```php
public static function delete(string $url, array $options = []): array
```

Parameters:
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `array{status: int, headers: array<string, string>, body: string}`.

### json()

```php
public static function json(string $method, string $url, array $options = []): mixed
```

Sends the request and decodes the JSON response.

Parameters:
- `$method` (`string`, required)
- `$url` (`string`, required)
- `$options` (`array<string, mixed>`, default `[]`)

Returns `mixed`: The decoded response (objects become associative arrays).

Throws:
- `RuntimeException`: On network error, non-2xx response, or invalid JSON.

### request()

```php
public static function request(string $method, string $url, array $options = []): array
```

Sends an HTTP request.

Parameters:
- `$method` (`string`, required): GET, POST, PUT, PATCH, DELETE, HEAD, OPTIONS.
- `$url` (`string`, required): Absolute http(s) URL.
- `$options` (`array<string, mixed>`, default `[]`): See class docblock for available options.

Returns `array{status: int, headers: array<string, string>, body: string}`: Header names are lowercased.

Throws:
- `InvalidArgumentException`: On invalid method, URL, or options.
- `RuntimeException`: On network error or non-2xx response (when throwOnError is true).
- `LogicException`: If the curl extension is not enabled.

