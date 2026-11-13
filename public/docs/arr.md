# Arr

> Dot notation access (missing keys throw exceptions), pluck, keyBy, groupBy, sortBy, strict searches.

Canonical: https://foundry.insightdevhub.ro/docs/arr/  
Namespace: `Foundry\Arr` · File: `Arr.php` · Version 1.1 · PHP 8.3+

Array operations with strict comparisons and explicit errors.

## Pitfalls of native functions avoided

- array_column() silently skips rows missing the requested key; pluck() throws an exception
- array_column() with an index silently overwrites duplicates; keyBy() throws an exception
- in_array() / array_search() perform loose comparisons by default ('abc' == 0 in PHP 7); here everything is strict
- array_search() returns 0 or false, easily confused; search() throws an exception if missing
- array_find(), array_any(), array_all() only exist starting from PHP 8.4.

Dot notation: 'user.address.city' reads $array['user']['address']['city']. A literal existing key ('user.name' => ...) takes precedence over dot notation. For object elements, only public properties are read.

The field used by pluck/keyBy/groupBy/sortBy/where/sum is a key (dot notation supported) or a Closure. A string is never interpreted as a function name ('count' is a key).

Callbacks receive ($value, $key) and must return bool (otherwise an exception is thrown).

Error messages include the requested keys and item position, never raw values from the array.

## Usage

```php
require_once 'Arr.php';
use Foundry\Arr;

$city = Arr::get($data, 'user.address.city');          // throws an exception if missing
$city = Arr::get($data, 'user.address.city', '');      // default value
$input = Arr::require($_POST, ['name', 'email']);      // all missing keys reported at once
$emails = Arr::pluck($users, 'email');
$byId = Arr::keyBy($users, 'id');
$admin = Arr::first($users, fn (array $user): bool => $user['role'] === 'admin');
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Arr.php';

use Foundry\Arr;
```

## Methods

### get()

```php
public static function get(array $array, string|int $key, mixed $default = null): mixed
```

Gets the value of a key (with dot notation support). With two arguments the key is required; with a third argument, it acts as the default value.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$key` (`string|int`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `OutOfBoundsException`: If the key is missing and no default value is provided.
- `InvalidArgumentException`: If the key contains an empty segment ('a..b').

### has()

```php
public static function has(array $array, string|int $key): bool
```

Checks whether a key exists (a null value counts as existing).

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$key` (`string|int`, required)

Returns `bool`.

Throws:
- `InvalidArgumentException`: If the key contains an empty segment.

### require()

```php
public static function require(array $array, array $keys): array
```

Returns the values of the requested keys, all of which are required. All missing keys are reported at once.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$keys` (`list<string|int>`, required)

Returns `array<array-key, mixed>`: Requested key => value, in the order specified in $keys.

Throws:
- `OutOfBoundsException`: If at least one key is missing.
- `InvalidArgumentException`: If a key contains an empty segment.

### set()

```php
public static function set(array $array, string|int $key, mixed $value): array
```

Returns a copy of the array with the value set (using dot notation); missing levels are created.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$key` (`string|int`, required)
- `$value` (`mixed`, required)

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: If an intermediate level exists and is not an array.
- `InvalidArgumentException`: If the key contains an empty segment.

### forget()

```php
public static function forget(array $array, string|int $key): array
```

Returns a copy of the array without the given key (using dot notation). A missing key is not an error.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$key` (`string|int`, required)

Returns `array<array-key, mixed>`.

Throws:
- `InvalidArgumentException`: If the key contains an empty segment.

### only()

```php
public static function only(array $array, array $keys): array
```

Returns only the specified keys (top-level only, without dot notation); missing keys are ignored.

Parameters:
- `$array` (`array<TKey, TValue>`, required)
- `$keys` (`list<array-key>`, required)

Returns `array<TKey, TValue>`.

### except()

```php
public static function except(array $array, array $keys): array
```

Returns all keys except the specified ones (top-level only, without dot notation).

Parameters:
- `$array` (`array<TKey, TValue>`, required)
- `$keys` (`list<array-key>`, required)

Returns `array<TKey, TValue>`.

### pluck()

```php
public static function pluck(array $items, string|int|Closure $value, string|int|Closure|null $key = null): array
```

Returns the values of a field from each item; with $key, the result is keyed by another field.

Arr::pluck($users, 'email')            -> ['a@x.ro', 'b@x.ro'] Arr::pluck($users, 'email', 'id')      -> [10 => 'a@x.ro', 11 => 'b@x.ro']

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$value` (`Closure|string|int`, required)
- `$key` (`Closure|string|int|null`, default `null`)

Returns `array<array-key, mixed>`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.
- `UnexpectedValueException`: If the index is not an int/string or is duplicated.

### keyBy()

```php
public static function keyBy(array $items, string|int|Closure $key): array
```

Returns the items keyed by a field. A duplicate index is an error (preventing accidental item loss).

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$key` (`Closure|string|int`, required)

Returns `array<array-key, TValue>`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.
- `UnexpectedValueException`: If the index is not an int/string or is duplicated.

### groupBy()

```php
public static function groupBy(array $items, string|int|Closure $key): array
```

Groups items by a field: [field value => list of items].

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$key` (`Closure|string|int`, required)

Returns `array<array-key, list<TValue>>`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.
- `UnexpectedValueException`: If the grouping value is not an int/string.

### first()

```php
public static function first(array $array, ?Closure $callback = null, mixed $default = null): mixed
```

Returns the first element (matching the callback, if provided). With $default, it is returned instead of throwing an exception.

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`(Closure(TValue, array-key): bool)|null`, default `null`)
- `$default` (`mixed`, default `null`)

Returns `TValue|mixed`.

Throws:
- `OutOfBoundsException`: If no matching element is found and no $default is provided.
- `UnexpectedValueException`: If the callback does not return a bool.

### last()

```php
public static function last(array $array, ?Closure $callback = null, mixed $default = null): mixed
```

Returns the last element (matching the callback, if provided). With $default, it is returned instead of throwing an exception.

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`(Closure(TValue, array-key): bool)|null`, default `null`)
- `$default` (`mixed`, default `null`)

Returns `TValue|mixed`.

Throws:
- `OutOfBoundsException`: If no matching element is found and no $default is provided.
- `UnexpectedValueException`: If the callback does not return a bool.

### find()

```php
public static function find(array $array, Closure $callback): mixed
```

Returns the first element that satisfies the callback, or null (like array_find() in PHP 8.4). When null can be a valid element value, use first().

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`Closure`, required)

Returns `TValue|null`.

Throws:
- `UnexpectedValueException`: If the callback does not return a bool.

### findKey()

```php
public static function findKey(array $array, Closure $callback): string|int|null
```

Returns the key of the first element that satisfies the callback, or null (like array_find_key() in PHP 8.4).

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`Closure`, required)

Returns `string|int|null`.

Throws:
- `UnexpectedValueException`: If the callback does not return a bool.

### any()

```php
public static function any(array $array, Closure $callback): bool
```

Checks whether at least one element satisfies the callback (like array_any() in PHP 8.4). Empty array -> false.

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`Closure`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If the callback does not return a bool.

### all()

```php
public static function all(array $array, Closure $callback): bool
```

Checks whether all elements satisfy the callback (like array_all() in PHP 8.4). Empty array -> true.

Parameters:
- `$array` (`array<array-key, TValue>`, required)
- `$callback` (`Closure`, required)

Returns `bool`.

Throws:
- `UnexpectedValueException`: If the callback does not return a bool.

### contains()

```php
public static function contains(array $array, mixed $value): bool
```

Checks strictly (===) whether the value exists in the array.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$value` (`mixed`, required)

Returns `bool`.

### search()

```php
public static function search(array $array, mixed $value): string|int
```

Returns the key of the first occurrence of the value (strict comparison).

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$value` (`mixed`, required)

Returns `string|int`.

Throws:
- `OutOfBoundsException`: If the value does not exist in the array.

### flatten()

```php
public static function flatten(array $array, int $depth = PHP_INT_MAX): array
```

Flattens nested arrays into a single list up to the specified depth. Keys are not preserved.

Parameters:
- `$array` (`array<array-key, mixed>`, required)
- `$depth` (`int`, default `PHP_INT_MAX`)

Returns `list<mixed>`.

Throws:
- `InvalidArgumentException`: If $depth is less than 1.

### wrap()

```php
public static function wrap(mixed $value): array
```

Wraps a value into an array: null -> [], array -> unchanged, anything else -> [value].

Parameters:
- `$value` (`mixed`, required)

Returns `array<array-key, mixed>`.

### sortBy()

```php
public static function sortBy(array $items, string|int|Closure $key, bool $descending = false): array
```

Returns the items sorted by a field (stable sort). A list remains a list; for associative arrays, keys are preserved. Values must be of the same kind: numbers, strings, bool, or dates (DateTimeInterface); a mixed set (e.g. string and int, null) is an error.

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$key` (`Closure|string|int`, required)
- `$descending` (`bool`, default `false`)

Returns `array<array-key, TValue>`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.
- `UnexpectedValueException`: If the values cannot be compared with each other.

### where()

```php
public static function where(array $items, string|int|Closure $key, mixed $value): array
```

Returns the items whose field is strictly equal (===) to the given value. A list remains a list; for associative arrays, keys are preserved.

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$key` (`Closure|string|int`, required)
- `$value` (`mixed`, required)

Returns `array<array-key, TValue>`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.

### sum()

```php
public static function sum(array $items, string|int|Closure|null $key = null): int|float
```

Returns the sum of values (or of a specific field). Values must be int or float: a numeric string (e.g. from a database) is an error and must be cast explicitly. For monetary amounts, use Money rather than float.

Parameters:
- `$items` (`array<array-key, TValue>`, required)
- `$key` (`Closure|string|int|null`, default `null`)

Returns `int|float`.

Throws:
- `OutOfBoundsException`: If an item does not have the requested field.
- `UnexpectedValueException`: If a value is not an int or float.

### isAssoc()

```php
public static function isAssoc(array $array): bool
```

Checks whether the array has associative keys (is not a sequential list 0, 1, 2...). Empty array -> false.

Parameters:
- `$array` (`array<array-key, mixed>`, required)

Returns `bool`.

