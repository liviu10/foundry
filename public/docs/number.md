# Number

> Romanian and European number formatting and parsing (1.234,50), independent of thread-unsafe setlocale.

Canonical: https://foundry.insightdevhub.ro/docs/number/  
Namespace: `Foundry\Number` · File: `Number.php` · Version 1.2 · PHP 8.3+

Number formatting and parsing in Romanian/European format (1.234,50), without setlocale().

setlocale() modifies global process state, depends on locales installed on the host OS, and returns false if the locale is absent; number_format() cannot parse back what it produced. Here, delimiters default to ',' (decimal) and '.' (thousands), and are configurable.

Numbers provided as strings (e.g. DECIMAL columns from a database) are rounded at the digit level without converting to float, preventing precision loss. Rounding is half-up (2,345 -> 2,35; -2,345 -> -2,35).

Parsing is strict: '1.5' in Romanian format throws an error (the thousands separator must group digits into triplets), rather than producing 15 or 1,5.

For monetary calculations, use Money (exact calculations with bcmath).

## Usage

```php
require_once 'Number.php';
use Foundry\Number;

echo Number::format(1234.5);              // '1.234,50'
echo Number::format('1234567.891', 1);    // '1.234.567,9'
$value = Number::parse('1.234,50');       // 1234.5
$db = Number::normalize('1.234,50');      // '1234.50' (for DECIMAL or Money)
echo Number::fileSize(2516582);           // '2,4 MB'
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Number.php';

use Foundry\Number;
```

## Methods

### format()

```php
public static function format(int|float|string $number, int $decimals = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Formats a number: 1234.5 -> '1.234,50'.

Parameters:
- `$number` (`int|float|string`, required): String input must be a plain number: '1234.56'.
- `$decimals` (`int`, default `2`)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the number is invalid (NAN, INF, '1e3', '12abc') or $decimals < 0.

### percent()

```php
public static function percent(int|float|string $value, int $decimals = 0, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Formats a percentage: 12.5 -> '12,5%' (with $decimals = 1). The value is already in percent form.

Parameters:
- `$value` (`string|int|float`, required)
- `$decimals` (`int`, default `0`)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the number is invalid or $decimals < 0.

### fileSize()

```php
public static function fileSize(int $bytes, int $decimals = 1, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Formats human-readable file size in multiples of 1024: 2516582 -> '2,4 MB'; below 1 KB without decimals: '512 B'.

Parameters:
- `$bytes` (`int`, required)
- `$decimals` (`int`, default `1`)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $bytes is negative or $decimals < 0.

### parse()

```php
public static function parse(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): float
```

Parses a formatted number: '1.234,50' -> 1234.5. The thousands separator is optional ('1234,50').

Parameters:
- `$value` (`string`, required)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `float`.

Throws:
- `InvalidArgumentException`: If separators are invalid.
- `UnexpectedValueException`: If the string is not a valid number in the given format.

### parseInt()

```php
public static function parseInt(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): int
```

Parses a formatted integer: '1.234' -> 1234. Decimals ('12,00') throw an exception.

Parameters:
- `$value` (`string`, required)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `int`.

Throws:
- `InvalidArgumentException`: If separators are invalid.
- `UnexpectedValueException`: If the string is not an integer in the given format or exceeds PHP_INT_MAX.

### normalize()

```php
public static function normalize(string $value, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Converts a formatted number into its plain numeric representation without precision loss: '1.234,50' -> '1234.50'. Suitable for database DECIMAL columns and Money inputs.

Parameters:
- `$value` (`string`, required)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If separators are invalid.
- `UnexpectedValueException`: If the string is not a valid number in the given format.

