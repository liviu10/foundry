# Json

> Strict encode/decode, atomic file I/O; preserves large integers, strips BOM safely.

Canonical: https://foundry.insightdevhub.ro/docs/json/  
Namespace: `Foundry\Json` · File: `Json.php` · Version 1.1 · PHP 8.3+

Wrappers for json_encode() / json_decode(). Any error throws an exception; null or false is never returned. Error messages never contain the JSON payload itself (which may contain sensitive personal data).

## Usage

```php
require_once 'Json.php';
use Foundry\Json;

$data = Json::decodeArray($body);               // array or exception
$json = Json::encode(['oras' => 'Iasi']);       // {"oras":"Iasi"}
$config = Json::readArray(__DIR__.'/config.json');
Json::writeFile(__DIR__.'/out.json', $data);             // atomic: temp file + rename
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Json.php';

use Foundry\Json;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `ENCODE_FLAGS` | `int` | `JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES \| JSON_PRESERVE_ZERO_FRACTION` | Flags for encode(): readable UTF-8, unescaped slashes, 1.0 preserved as 1.0. |
| `DEPTH` | `int` | `512` | Default maximum depth. |

## Methods

### decode()

```php
public static function decode(string $json, bool $associative = true, int $depth = self::DEPTH): mixed
```

Decodes a JSON string. By default, objects are converted to associative arrays.

Parameters:
- `$json` (`string`, required): The JSON string.
- `$associative` (`bool`, default `true`): true: objects as associative arrays; false: objects as stdClass.
- `$depth` (`int`, default `self::DEPTH`): Maximum depth.

Returns `mixed`: The decoded value (array, scalar, or null for the "null" literal).

Throws:
- `JsonException`: If the JSON is invalid or empty.
- `InvalidArgumentException`: If $depth < 1.

### decodeArray()

```php
public static function decodeArray(string $json, int $depth = self::DEPTH): array
```

Decodes a JSON string that must represent an object or array.

Parameters:
- `$json` (`string`, required): The JSON string.
- `$depth` (`int`, default `self::DEPTH`): Maximum depth.

Returns `array<mixed>`: The decoded array.

Throws:
- `JsonException`: If the JSON is invalid.
- `InvalidArgumentException`: If $depth < 1.
- `UnexpectedValueException`: If the JSON is valid but not an object/array (e.g., "42").

### encode()

```php
public static function encode(mixed $value, bool $pretty = false, int $flags = 0): string
```

Encodes a value as JSON.

Parameters:
- `$value` (`mixed`, required): The value to encode.
- `$pretty` (`bool`, default `false`): true for formatted, indented JSON.
- `$flags` (`int`, default `0`): Additional JSON_* flags combined with ENCODE_FLAGS. JSON_PARTIAL_OUTPUT_ON_ERROR is ignored as it would suppress errors.

Returns `string`: The JSON string.

Throws:
- `JsonException`: On invalid UTF-8, INF/NAN, recursion, or maximum depth exceeded.

### isValid()

```php
public static function isValid(string $json): bool
```

Checks whether a string is valid JSON without throwing an exception.

Parameters:
- `$json` (`string`, required): The string to validate.

Returns `bool`: true if valid.

### readFile()

```php
public static function readFile(string $path): mixed
```

Reads and decodes a JSON file. The UTF-8 BOM is stripped if present.

Parameters:
- `$path` (`string`, required): The file path.

Returns `mixed`: The decoded value.

Throws:
- `RuntimeException`: If the file does not exist or cannot be read.
- `JsonException`: If the file does not contain valid JSON.

### readArray()

```php
public static function readArray(string $path): array
```

Reads a JSON file that must contain an object or array.

Parameters:
- `$path` (`string`, required): The file path.

Returns `array<mixed>`: The decoded array.

Throws:
- `RuntimeException`: If the file does not exist or cannot be read.
- `JsonException`: If the file does not contain valid JSON.
- `UnexpectedValueException`: If the JSON is not an object or array.

### writeFile()

```php
public static function writeFile(string $path, mixed $value, bool $pretty = true): void
```

Encodes a value and writes it to a file atomically: the content goes to a temporary file in the same directory, which then replaces the target with rename(). Readers see either the old or the new content, never an empty or truncated file, and a crash mid-write leaves the old file intact.

Parameters:
- `$path` (`string`, required): The file path; the directory must already exist.
- `$value` (`mixed`, required): The value to encode.
- `$pretty` (`bool`, default `true`): true for formatted, indented JSON (default for files).

Throws:
- `JsonException`: If the value cannot be encoded.
- `RuntimeException`: If the file cannot be written.

