# Money

> Currency amounts as exact strings, bcmath arithmetic, bankers rounding, loss-free installment splitting.

Canonical: https://foundry.insightdevhub.ro/docs/money/  
Namespace: `Foundry\Money` · File: `Money.php` · Version 1.1 · PHP 8.3+ · Extensions: bcmath

Exact monetary calculations on strings using bcmath. Floats are never used: 0.1 + 0.2 in floating-point arithmetic is 0.30000000000000004, causing errors to accumulate in invoices and installments.

Amounts are plain strings ('1234.56', '-10', '0.5') or integers. Floats are rejected via type signatures. The result always has $scale decimals (default 2), rounded half-up (2.345 -> 2.35; -2.345 -> -2.35). Rounding is performed once at the very end.

Built-in bc* functions truncate instead of rounding and silently accepted invalid values in older PHP versions; here each amount is strictly validated.

Requires the bcmath extension (bundled with PHP on Windows; on Linux, the php-bcmath package).

## Usage

```php
require_once 'Money.php';
use Foundry\Money;

$total = Money::add('100.10', '0.20');                 // '100.30'
$vat = Money::percent($total, '19');                   // '19.06'
$rates = Money::allocate('100.00', 3);                 // ['33.34', '33.33', '33.33']
$amount = Money::parse($_POST['suma']);                // '1.234,50' -> '1234.50'
echo Money::format($total, 'lei');                     // '100,30 lei'
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Money.php';

use Foundry\Money;
```

## Methods

### add()

```php
public static function add(string|int $a, string|int $b, int $scale = 2): string
```

$a + $b.

Parameters:
- `$a` (`string|int`, required)
- `$b` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If an amount is invalid.

### sub()

```php
public static function sub(string|int $a, string|int $b, int $scale = 2): string
```

$a - $b.

Parameters:
- `$a` (`string|int`, required)
- `$b` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If an amount is invalid.

### mul()

```php
public static function mul(string|int $amount, string|int $multiplier, int $scale = 2): string
```

$amount * $multiplier (e.g., quantity, exchange rate, coefficient).

Parameters:
- `$amount` (`string|int`, required)
- `$multiplier` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If a value is invalid.

### div()

```php
public static function div(string|int $amount, string|int $divisor, int $scale = 2): string
```

$amount / $divisor.

Parameters:
- `$amount` (`string|int`, required)
- `$divisor` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If a value is invalid or $divisor is zero.

### percent()

```php
public static function percent(string|int $amount, string|int $percent, int $scale = 2): string
```

Calculates $percent percent of $amount: percent('200', '19') -> '38.00'.

Parameters:
- `$amount` (`string|int`, required)
- `$percent` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If a value is invalid.

### sum()

```php
public static function sum(array $amounts, int $scale = 2): string
```

Sum of a list of amounts. Empty list -> '0.00'.

Parameters:
- `$amounts` (`list<string|int>`, required)
- `$scale` (`int`, default `2`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If an amount is invalid.

### round()

```php
public static function round(string|int $amount, int $scale = 2): string
```

Rounds an amount to $scale decimal places: '10.555' -> '10.56', '7' -> '7.00'.

Parameters:
- `$amount` (`string|int`, required)
- `$scale` (`int`, default `2`)

Returns `numeric-string`.

Throws:
- `InvalidArgumentException`: If the amount is invalid.

### allocate()

```php
public static function allocate(string|int $amount, int|array $ratios, int $scale = 2): array
```

Allocates an amount into parts without losing or creating money: the sum of the parts exactly equals the total (rounded to $scale). Any remainder units are distributed to the first non-zero weighted parts.

Money::allocate('100.00', 3)          -> ['33.34', '33.33', '33.33'] Money::allocate('100.00', [70, 30])   -> ['70.00', '30.00'] Money::allocate('0.05', [1, 1])       -> ['0.03', '0.02']

Parameters:
- `$amount` (`string|int`, required)
- `$ratios` (`int|list<string|int>`, required): Number of equal parts or list of weights (>= 0, at least one > 0).
- `$scale` (`int`, default `2`)

Returns `list<string>`.

Throws:
- `InvalidArgumentException`: If the amount, number of parts, or ratios are invalid.

### compare()

```php
public static function compare(string|int $a, string|int $b): int
```

Compares two amounts: -1 ($a < $b), 0 (equal), 1 ($a > $b). '10' and '10.00' are equal.

Parameters:
- `$a` (`string|int`, required)
- `$b` (`string|int`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If an amount is invalid.

### format()

```php
public static function format(string|int $amount, ?string $currency = null, int $scale = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Formats an amount: '1234.5' -> '1.234,50'; with currency: '1.234,50 lei'.

Parameters:
- `$amount` (`string|int`, required)
- `$currency` (`?string`, default `null`)
- `$scale` (`int`, default `2`)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the amount is invalid.

### parse()

```php
public static function parse(string $value, int $scale = 2, string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
```

Parses a user-entered monetary amount: '1.234,50' or '1234,5' -> '1234.50'. More decimals than $scale triggers an error ('12,345' is ambiguous: not silently rounded).

Parameters:
- `$value` (`string`, required)
- `$scale` (`int`, default `2`)
- `$decimalSeparator` (`string`, default `','`)
- `$thousandsSeparator` (`string`, default `'.'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the separators are invalid.
- `UnexpectedValueException`: If the string is not a valid amount.

