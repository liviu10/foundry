# Base64

> Strict decoding, URL-safe Base64 (RFC 4648), hex conversion.

Canonical: https://foundry.insightdevhub.ro/docs/base64/  
Namespace: `Foundry\Base64` · File: `Base64.php` · Version 1.1 · PHP 8.3+

Base64 (standard and URL-safe) and hexadecimal encoding/decoding with strict validation.

Natively, base64_decode() without $strict accepts any text and returns corrupted data, while hex2bin() returns false with a warning. Here, invalid data throws an exception.

decode() accepts whitespace and newlines (MIME Base64 split across lines) as well as omitted "=" padding. Error messages include the data length rather than the raw data itself (which may contain tokens or keys).

## Usage

```php
require_once 'Base64.php';
use Foundry\Base64;

$pdf = Base64::decode($request['document']);      // throws an exception if not valid Base64
$token = Base64::encodeUrl(random_bytes(32));     // for URLs and JWTs: without + / =
$hash = Base64::fromHex('a1b2c3');
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Base64.php';

use Foundry\Base64;
```

## Methods

### encode()

```php
public static function encode(string $data): string
```

Encodes data in standard Base64 (RFC 4648) with padding.

Parameters:
- `$data` (`string`, required)

Returns `string`.

### decode()

```php
public static function decode(string $encoded): string
```

Decodes standard Base64. Whitespace, newlines, and missing padding are accepted.

Parameters:
- `$encoded` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If the string contains characters outside the Base64 alphabet or invalid padding.

### isValid()

```php
public static function isValid(string $encoded): bool
```

Checks whether the string is valid Base64 without throwing an exception.

Parameters:
- `$encoded` (`string`, required)

Returns `bool`.

### encodeUrl()

```php
public static function encodeUrl(string $data): string
```

Encodes data in URL-safe Base64 (RFC 4648 section 5): "-" and "_" instead of "+" and "/", without "=". Suitable for URLs, filenames, and JWTs.

Parameters:
- `$data` (`string`, required)

Returns `string`.

### decodeUrl()

```php
public static function decodeUrl(string $encoded): string
```

Decodes URL-safe Base64. The "=" padding is optional.

Parameters:
- `$encoded` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If the string is not valid URL-safe Base64 (including if it uses the standard alphabet with "+" or "/").

### toHex()

```php
public static function toHex(string $data): string
```

Encodes data as lowercase hexadecimal text.

Parameters:
- `$data` (`string`, required)

Returns `string`.

### fromHex()

```php
public static function fromHex(string $hex): string
```

Decodes hexadecimal text (uppercase or lowercase).

Parameters:
- `$hex` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If the string has an odd length or contains non-hexadecimal characters.

