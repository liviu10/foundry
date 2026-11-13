# Token

> Cryptographically secure random tokens and HMAC-SHA256 JWTs with mandatory exp.

Canonical: https://foundry.insightdevhub.ro/docs/token/  
Namespace: `Foundry\Token` · File: `Token.php` · Version 1.1 · PHP 8.3+

Random tokens and signed tokens with expiration (JWT HS256).

Random tokens (password reset, email confirmation, API keys):

- random() generates a secure URL-safe token
- store only hash($token) in the database, never the token itself (a database leak will not expose valid tokens)
- comparison is performed with equals() in constant time.

Signed tokens (webhooks, temporary links, service-to-service authentication):

- sign() produces a standard JWT (HS256), compatible with other systems
- verify() validates the algorithm (HS256 only: blocks the "alg: none" attack), the signature in constant time, expiration (exp), and "not before" (nbf)
- the secret must be at least 32 bytes long; there is NO default secret.

## Usage

```php
require_once 'Token.php';
use Foundry\Token;

$token = Token::random();                              // sent via email
$db->insert('password_resets', ['token_hash' => Token::hash($token), ...]);
// on confirmation: lookup by Token::hash($receivedToken)

$jwt = Token::sign(['sub' => 15, 'scope' => 'webhook'], $secret, ttl: 300);
$claims = Token::verify($jwt, $secret);               // throws an exception if invalid or expired
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Token.php';

use Foundry\Token;
```

## Methods

### random()

```php
public static function random(int $bytes = 32): string
```

Random URL-safe token (base64url without padding).

Parameters:
- `$bytes` (`int`, default `32`): Entropy in bytes (minimum 16; 32 = 43 characters).

Returns `string`.

Throws:
- `InvalidArgumentException`: If $bytes < 16.

### hash()

```php
public static function hash(string $token): string
```

SHA-256 hash (hex) of a token for database storage.

Parameters:
- `$token` (`string`, required)

Returns `string`.

### equals()

```php
public static function equals(string $known, string $given): bool
```

Compares two tokens in constant time.

Parameters:
- `$known` (`string`, required): Known value (from session / database).
- `$given` (`string`, required): Value received from user.

Returns `bool`.

### sign()

```php
public static function sign(array $claims, string $secret, int $ttl = 3600, ?int $now = null): string
```

Creates a signed HS256 JWT. Automatically adds iat (now) and exp (now + $ttl).

Parameters:
- `$claims` (`array<string, mixed>`, required): Token payload data (e.g., sub, scope); do not store secrets: JWT payload content is merely encoded, not encrypted.
- `$secret` (`string`, required): Secret key (at least 32 bytes).
- `$ttl` (`int`, default `3600`): Time-to-live in seconds.
- `$now` (`int|null`, default `null`): Current timestamp; null = current time. Useful in tests.

Returns `string`.

Throws:
- `InvalidArgumentException`: On short secret, $ttl < 1, or claims that cannot be encoded.

### verify()

```php
public static function verify(string $token, string $secret, int $leeway = 0, ?int $now = null): array
```

Verifies an HS256 JWT and returns its claims.

Parameters:
- `$token` (`string`, required): Received JWT.
- `$secret` (`string`, required): Same secret as used in sign().
- `$leeway` (`int`, default `0`): Tolerance in seconds for unsynchronized clocks between servers.
- `$now` (`int|null`, default `null`): Current timestamp; null = current time. Useful in tests.

Returns `array<array-key, mixed>`: Claims, including iat and exp.

Throws:
- `InvalidArgumentException`: On short secret or negative $leeway.
- `UnexpectedValueException`: If the token is malformed, has an unsupported algorithm, an invalid signature, has expired, or is not yet valid.

