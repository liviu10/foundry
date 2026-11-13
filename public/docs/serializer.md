# Serializer

> Secure unserialize wrapper disabling arbitrary class instantiation and enforcing max_depth; prefer Json for new data.

Canonical: https://foundry.insightdevhub.ro/docs/serializer/  
Namespace: `Foundry\Serializer` · File: `Serializer.php` · Version 1.0 · PHP 8.3+

Secure serialize() and unserialize(). For new data, prefer Json: it is portable and cannot instantiate objects. Serializer is intended for existing data (sessions, cache, legacy columns).

## Native issues resolved

- unserialize() on untrusted data can instantiate arbitrary classes (object injection); here, by default NO classes are allowed, and permitted classes must be explicitly provided
- disallowed classes silently become __PHP_Incomplete_Class; here, an exception is thrown
- invalid data or trailing data ("Extra data") only emits a warning; here, an exception is thrown
- serialize() silently converts resources (file handles, connections) to 0; here, an exception is thrown.

## Usage

```php
require_once 'Serializer.php';
use Foundry\Serializer;

$data = Serializer::unserialize($row['payload']);                         // scalars and arrays only
$dto = Serializer::unserialize($cached, allowedClasses: [Factura::class]);  // plus permitted classes
$text = Serializer::serialize($data);
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Serializer.php';

use Foundry\Serializer;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `DEPTH` | `int` | `64` | Default maximum depth for unserialize(). |

## Methods

### serialize()

```php
public static function serialize(mixed $value): string
```

Serializes a value.

Parameters:
- `$value` (`mixed`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the value contains resources, closures, or other non-serializable values.

### unserialize()

```php
public static function unserialize(string $data, array $allowedClasses = [], int $maxDepth = self::DEPTH): mixed
```

Unserializes data. By default, no classes are allowed.

Parameters:
- `$data` (`string`, required): The serialized string.
- `$allowedClasses` (`list<class-string>`, default `[]`): Classes allowed to be instantiated.
- `$maxDepth` (`int`, default `self::DEPTH`): Maximum depth of the data structure.

Returns `mixed`: The unserialized value.

Throws:
- `UnexpectedValueException`: If the data is invalid, contains trailing data, or contains a disallowed class.

