# SecurityHeaders

> Content Security Policy (CSP) with nonce generation, HSTS on HTTPS, and essential defensive HTTP headers.

Canonical: https://foundry.insightdevhub.ro/docs/security-headers/  
Namespace: `Foundry\SecurityHeaders` · File: `SecurityHeaders.php` · Version 1.0 · PHP 8.3+

HTTP security headers with strict, configurable defaults: Content-Security-Policy (with per-request nonce), Strict-Transport-Security, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy.

## Rules

- headers() only computes the header array (without side effects); send() emits them and strips X-Powered-By
- HSTS is sent only over HTTPS (it would be ignored on HTTP and would break local development)
- Default CSP: only 'self', scripts only with nonce, no inline styles, no frames; directives are overridden individually, null removes a directive
- Options and values are validated (a misspelled option throws an exception, rather than silently omitting the header).

## Usage

```php
require_once 'SecurityHeaders.php';
use Foundry\SecurityHeaders;

SecurityHeaders::send([
    'csp' => ['img-src' => "'self' data: https://cdn.example.com", 'style-src' => "'self' 'unsafe-inline'"],
    'frameOptions' => 'SAMEORIGIN',
]);

<script nonce="<?= SecurityHeaders::nonce() ?>">...</script>

// Together with Response:
$response = Response::html($html);
foreach (SecurityHeaders::headers() as $name => $value) {
    $response = $response->withHeader($name, $value);
}
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/SecurityHeaders.php';

use Foundry\SecurityHeaders;
```

## Methods

### nonce()

```php
public static function nonce(): string
```

Per-request CSP nonce (consistent across the entire request), for <script nonce="...">.

Returns `string`.

### headers()

```php
public static function headers(array $options = [], ?array $server = null): array
```

Computes security headers without sending them.

Options: csp                      array directive => value (null removes directive) or false (no CSP); '{nonce}' in value is replaced with per-request nonce cspReportOnly            true = Content-Security-Policy-Report-Only (testing without blocking) hsts                     max-age in seconds (default 1 year) or false hstsSubdomains           includeSubDomains (default true); hstsPreload (default false) frameOptions             'DENY' (default), 'SAMEORIGIN' or false referrerPolicy           defaults to 'strict-origin-when-cross-origin' or false permissionsPolicy        array feature => allowlist ('()' = disabled) overriding defaults; false = no header crossOriginOpenerPolicy  'same-origin' (default), 'same-origin-allow-popups', 'unsafe-none' or false trustForwarded           Detect HTTPS via X-Forwarded-Proto (only behind a trusted proxy)

Parameters:
- `$options` (`array<string, mixed>`, default `[]`)
- `$server` (`array<array-key, mixed>|null`, default `null`): Defaults to $_SERVER (for HTTPS detection).

Returns `array<string, string>`.

Throws:
- `InvalidArgumentException`: If an option is unrecognized or has an invalid value.

### send()

```php
public static function send(array $options = []): void
```

Emits headers and strips X-Powered-By (PHP version).

Parameters:
- `$options` (`array<string, mixed>`, default `[]`): See headers().

Throws:
- `InvalidArgumentException`: If an option is invalid.
- `LogicException`: If headers have already been sent (with output file and line).

