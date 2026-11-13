# BotDetection

> Honeypot field verification and HMAC-timestamped minimum submission time checking for public forms.

Canonical: https://foundry.insightdevhub.ro/docs/bot-detection/  
Namespace: `Foundry\BotDetection` · File: `BotDetection.php` · Version 1.0 · PHP 8.3+

Protection for public forms (contact, registration, password reset) against automated submissions:

- honeypot: a hidden field that a human does not see or fill out, but a bot does
- minimum submission time: a human cannot fill and submit a form in less than a few seconds
- maximum time: a page left open for too long (or a saved and reused token) is rejected.

The render timestamp is HMAC-signed and bound to the form name, preventing tampering (unlike a plain timestamp submitted in a hidden input).

## Rules

- secret must be at least 32 bytes; there is no default secret
- check() throws an UnexpectedValueException with code 403; reason() returns only the reason string (for logging)
- stable reasons: honeypot, missing, invalid, too_fast, expired
- request rate limiting per IP should be handled using RateLimiter.

## Usage

```php
require_once 'BotDetection.php';
use Foundry\BotDetection;

$bot = new BotDetection($_ENV['BOT_SECRET'], minSeconds: 3);

<form method="post"><?= $bot->fields('contact') ?> ... </form>

$bot->check($_POST, 'contact');              // throws an exception (code 403) on automated submissions
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/BotDetection.php';

use Foundry\BotDetection;
```

## Methods

### __construct()

```php
public function __construct(
    #[SensitiveParameter]
    private string $secret,
    private int $minSeconds = 3,
    private int $maxSeconds = 7200,
    private string $honeypot = 'website',
    private string $timeField = '_form_time',
)
```

Parameters:
- `$secret` (`string`, required): Minimum 32 bytes (e.g. from .env).
- `$minSeconds` (`int`, default `3`): Minimum elapsed time in seconds between form rendering and submission.
- `$maxSeconds` (`int`, default `7200`): Maximum elapsed time in seconds between form rendering and submission.
- `$honeypot` (`string`, default `'website'`): Name of the honeypot field (a common field name attracts bots: website, url, phone2).
- `$timeField` (`string`, default `'_form_time'`): Name of the hidden field containing the signed timestamp.

Throws:
- `InvalidArgumentException`: If the secret is too short or configuration settings are invalid.

### fields()

```php
public function fields(string $form = 'default', ?int $now = null): string
```

Generates form fields: the visually hidden honeypot (not type="hidden", which bots recognize) and the signed render timestamp.

Parameters:
- `$form` (`string`, default `'default'`)
- `$now` (`?int`, default `null`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the form name is invalid.

### token()

```php
public function token(string $form = 'default', ?int $now = null): string
```

Returns the signed render timestamp ('1767225600.signature') for client-rendered JavaScript forms.

Parameters:
- `$form` (`string`, default `'default'`)
- `$now` (`?int`, default `null`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the form name is invalid.

### reason()

```php
public function reason(array $data, string $form = 'default', ?int $now = null): ?string
```

Returns the reason why the submission appears automated, or null if it appears human.

Parameters:
- `$data` (`array<array-key, mixed>`, required): Form submission data ($_POST).
- `$form` (`string`, default `'default'`)
- `$now` (`?int`, default `null`)

Returns `'honeypot'|'missing'|'invalid'|'too_fast'|'expired'|null`.

Throws:
- `InvalidArgumentException`: If the form name is invalid.

### check()

```php
public function check(array $data, string $form = 'default', ?int $now = null): void
```

Throws an exception if the submission appears automated.

Parameters:
- `$data` (`array<array-key, mixed>`, required): Form submission data ($_POST).
- `$form` (`string`, default `'default'`)
- `$now` (`?int`, default `null`)

Throws:
- `UnexpectedValueException`: With code 403 and the failure reason in the message.
- `InvalidArgumentException`: If the form name is invalid.

