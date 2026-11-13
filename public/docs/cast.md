# Cast

> Strict type conversions toInt/toFloat/toBool/toString (rejects '12abc', '1,5', '').

Canonical: https://foundry.insightdevhub.ro/docs/cast/  
Namespace: `Foundry\Cast` · File: `Cast.php` · Version 1.1 · PHP 8.3+

Strict type casting for values originating externally (forms, CSV, APIs, databases). Natively, (int) 'abc' = 0, intval('12abc') = 12, (float) '1.234,50' = 1.234, all without errors. Here, any value that does not precisely represent the requested type throws an exception.

## Rules

- leading and trailing whitespace is ignored for strings
- decimals use a dot separator ("1.5"); for Romanian formatting ("1.234,50") see Number
- OrNull variants return null for null and empty (or whitespace-only) strings
- error messages include the value type, never the raw value.

## Usage

```php
require_once 'Cast.php';
use Foundry\Cast;

$id = Cast::toInt($_GET['id'] ?? null);          // "12" -> 12; "12abc", "", null -> exception
$amount = Cast::toFloat($row['suma']);           // "150.5" -> 150.5
$active = Cast::toBool($row['activ']);           // "da", "1", "true", "on" -> true
$page = Cast::toIntOrNull($_GET['page'] ?? null) ?? 1;
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Cast.php';

use Foundry\Cast;
```

## Methods

### toInt()

```php
public static function toInt(mixed $value): int
```

Casts to int: int, float without fractional part (3.0), or numeric string ("12", "-5", "+5").

Parameters:
- `$value` (`mixed`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If the value does not represent an exact integer.

### toFloat()

```php
public static function toFloat(mixed $value): float
```

Casts to float: int, finite float, or numeric string with a dot separator ("1.5", "1e3").

Parameters:
- `$value` (`mixed`, required)

Returns `float`.

Throws:
- `InvalidArgumentException`: If the value is not a valid number (or is NAN/INF).

### toBool()

```php
public static function toBool(mixed $value): bool
```

Casts to bool: bool, int 0/1, or one of the case-insensitive strings: true/false, 1/0, yes/no, on/off, da/nu.

Parameters:
- `$value` (`mixed`, required)

Returns `bool`.

Throws:
- `InvalidArgumentException`: For any other value (including empty string and null).

### toString()

```php
public static function toString(mixed $value): string
```

Casts to string: string, int, finite float, or Stringable object. bool, null, array, and other objects are rejected (their conversion is ambiguous).

Parameters:
- `$value` (`mixed`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the value cannot be unambiguously converted.

### toIntOrNull()

```php
public static function toIntOrNull(mixed $value): ?int
```

Same as toInt(), but null and empty string return null.

Parameters:
- `$value` (`mixed`, required)

Returns `?int`.

Throws:
- `InvalidArgumentException`: If the value is non-empty and does not represent an integer.

### toFloatOrNull()

```php
public static function toFloatOrNull(mixed $value): ?float
```

Same as toFloat(), but null and empty string return null.

Parameters:
- `$value` (`mixed`, required)

Returns `?float`.

Throws:
- `InvalidArgumentException`: If the value is non-empty and is not a number.

### toBoolOrNull()

```php
public static function toBoolOrNull(mixed $value): ?bool
```

Same as toBool(), but null and empty string return null.

Parameters:
- `$value` (`mixed`, required)

Returns `?bool`.

Throws:
- `InvalidArgumentException`: If the value is non-empty and is not a recognized boolean representation.

### toStringOrNull()

```php
public static function toStringOrNull(mixed $value): ?string
```

Same as toString(), but null and empty (or whitespace-only) string return null.

Parameters:
- `$value` (`mixed`, required)

Returns `?string`.

Throws:
- `InvalidArgumentException`: If the value is non-empty and cannot be converted.

