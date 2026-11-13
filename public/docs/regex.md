# Regex

> Strict preg_* wrapper throwing exceptions on invalid patterns or execution failures.

Canonical: https://foundry.insightdevhub.ro/docs/regex/  
Namespace: `Foundry\Regex` · File: `Regex.php` · Version 1.1 · PHP 8.3+

Wrappers over preg_* functions. Natively, an invalid pattern emits only a warning and returns false/null, while runtime execution errors (backtracking limit, invalid UTF-8) go completely unnoticed. Here:

- invalid pattern    -> InvalidArgumentException (programming error)
- execution error    -> RuntimeException (the message does not contain the analyzed text)

Unmatched capture groups are returned as null (not empty strings).

## Usage

```php
require_once 'Regex.php';
use Foundry\Regex;

if (Regex::test('/^RO\d{2}[A-Z]{4}/', $iban)) { ... }
$m = Regex::match('/^(?<judet>[A-Z]{1,2}) ?(?<numar>\d{2,3})/', 'B 123 ABC');  // $m['judet'] === 'B'
$slug = Regex::replace('/[^a-z0-9]+/', '-', $text);
$words = Regex::split('/\s+/', $text, removeEmpty: true);
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Regex.php';

use Foundry\Regex;
```

## Methods

### test()

```php
public static function test(string $pattern, string $subject): bool
```

Checks whether the pattern matches against the string.

Parameters:
- `$pattern` (`string`, required): The regular expression, including delimiters (e.g. '/^\d+$/').
- `$subject` (`string`, required): The input string.

Returns `bool`: True if a match is found.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### match()

```php
public static function match(string $pattern, string $subject): ?array
```

First match: [0 => full matched text, 1 => group 1, 'name' => named group, ...].

Parameters:
- `$pattern` (`string`, required): The regular expression.
- `$subject` (`string`, required): The input string.

Returns `array<int|string, string|null>|null`: The matches or null if no match was found.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### matchAll()

```php
public static function matchAll(string $pattern, string $subject): array
```

All matches, each with its captured groups (PREG_SET_ORDER).

Parameters:
- `$pattern` (`string`, required): The regular expression.
- `$subject` (`string`, required): The input string.

Returns `list<array<int|string, string|null>>`: List of matches (empty if none found).

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### replace()

```php
public static function replace(string $pattern, string $replacement, string $subject, int $limit = -1): string
```

Replaces matches with replacement text ($1, ${name} are allowed in replacement).

Parameters:
- `$pattern` (`string`, required): The regular expression.
- `$replacement` (`string`, required): The replacement text.
- `$subject` (`string`, required): The input string.
- `$limit` (`int`, default `-1`): The maximum number of replacements; -1 = unlimited.

Returns `string`: The resulting string.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### replaceCallback()

```php
public static function replaceCallback(string $pattern, Closure $callback, string $subject, int $limit = -1): string
```

Replaces matches with the result of a callback function.

Parameters:
- `$pattern` (`string`, required): The regular expression.
- `$callback` (`Closure`, required)
- `$subject` (`string`, required): The input string.
- `$limit` (`int`, default `-1`): The maximum number of replacements; -1 = unlimited.

Returns `string`: The resulting string.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### split()

```php
public static function split(string $pattern, string $subject, int $limit = -1, bool $removeEmpty = false): array
```

Splits a string by a pattern.

Parameters:
- `$pattern` (`string`, required): The regular expression used as a separator.
- `$subject` (`string`, required): The string to split.
- `$limit` (`int`, default `-1`): The maximum number of substrings; -1 = unlimited.
- `$removeEmpty` (`bool`, default `false`): True to remove empty substrings.

Returns `list<string>`: The substrings.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

### filter()

```php
public static function filter(string $pattern, array $items, bool $invert = false): array
```

Keeps only elements that match the pattern (keys are preserved).

Parameters:
- `$pattern` (`string`, required): The regular expression.
- `$items` (`array<array-key, string>`, required): The elements to filter.
- `$invert` (`bool`, default `false`): True to keep elements that do NOT match.

Returns `array<array-key, string>`: The kept elements.

Throws:
- `InvalidArgumentException`: If the pattern is invalid.
- `RuntimeException`: On execution error.

