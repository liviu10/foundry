# Str

> URL slugging, diacritic stripping, data masking (credit card, IBAN, email), case conversions, e() helper; fully UTF-8 compliant.

Canonical: https://foundry.insightdevhub.ro/docs/str/  
Namespace: `Foundry\Str` · File: `Str.php` · Version 1.1 · PHP 8.3+ · Extensions: mbstring

UTF-8 text operations: slugs, diacritic removal, truncation, casing conversions (camelCase, snake_case, etc.), masking, and substring extraction between delimiters.

All methods operate on characters rather than bytes: limit('Iasi', 2) will not split a multibyte Romanian character in half. Invalid UTF-8 text throws an exception (error message contains only length, not raw text).

Diacritics are removed using an internal translation table (Romanian, including comma/cedilla variants, plus common characters across European languages), ensuring results are independent of the intl extension or operating system (iconv //TRANSLIT behaves differently across Windows and Linux).

This file also defines the e() helper function for HTML escaping.

## Usage

```php
require_once 'Str.php';
use Foundry\Str;
use function Foundry\e;

$slug = Str::slug($title);                       // 'Situatia platilor - 2026' -> 'situatia-platilor-2026'
$short = Str::limit($description, 100);
$card = Str::mask($cardNumber, '*', 0, -4);      // '************1111'
$column = Str::snake('createdAt');               // 'created_at'
echo '<td>'.e($row['name']).'</td>';
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Str.php';

use Foundry\Str;
```

## Methods

### slug()

```php
public static function slug(string $text, string $separator = '-'): string
```

URL- and filename-safe slug: lowercase ASCII letters, digits, and separator. Characters without an ASCII equivalent (e.g. Cyrillic) are removed.

Parameters:
- `$text` (`string`, required)
- `$separator` (`string`, default `'-'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If separator is empty.
- `UnexpectedValueException`: If string is not valid UTF-8.

### removeDiacritics()

```php
public static function removeDiacritics(string $text): string
```

Replaces accented letters with their ASCII equivalents (s with comma and s with cedilla -> s, a with breve -> a, German ss -> ss). Other characters remain unchanged.

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### limit()

```php
public static function limit(string $text, int $limit, string $end = '...'): string
```

Truncates string to $limit characters and appends $end if shortened. $end is not included in the limit count.

Parameters:
- `$text` (`string`, required)
- `$limit` (`int`, required)
- `$end` (`string`, default `'...'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $limit is negative.
- `UnexpectedValueException`: If string is not valid UTF-8.

### words()

```php
public static function words(string $text, int $words, string $end = '...'): string
```

Retains the first $words words and appends $end if the string was shortened.

Parameters:
- `$text` (`string`, required)
- `$words` (`int`, required)
- `$end` (`string`, default `'...'`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $words is less than 1.
- `UnexpectedValueException`: If string is not valid UTF-8.

### camel()

```php
public static function camel(string $text): string
```

'user_name', 'user-name', 'User Name' -> 'userName'.

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### studly()

```php
public static function studly(string $text): string
```

'user_name', 'userName', 'user name' -> 'UserName'. Acronyms are treated as words: 'XMLHttpRequest' -> 'XmlHttpRequest'.

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### snake()

```php
public static function snake(string $text, string $delimiter = '_'): string
```

'userName', 'UserName', 'user name' -> 'user_name'; 'XMLHttpRequest' -> 'xml_http_request'.

Parameters:
- `$text` (`string`, required)
- `$delimiter` (`string`, default `'_'`)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### kebab()

```php
public static function kebab(string $text): string
```

'userName', 'user_name' -> 'user-name'.

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### mask()

```php
public static function mask(string $text, string $character, int $index, ?int $length = null): string
```

Replaces a portion of the string with $character, preserving length. Negative $index counts from the end; null $length masks to the end; negative leaves trailing characters visible.

Str::mask('4111111111111111', '*', 0, -4)  -> '************1111'

Str::mask('ion@exemplu.ro', '*', 1, 2)     -> 'i**@exemplu.ro'

Parameters:
- `$text` (`string`, required)
- `$character` (`string`, required)
- `$index` (`int`, required)
- `$length` (`?int`, default `null`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $character is not exactly one character.
- `UnexpectedValueException`: If string is not valid UTF-8.

### random()

```php
public static function random(int $length = 16): string
```

Cryptographically secure random alphanumeric string (random_int). For security tokens, Token::random() is recommended.

Parameters:
- `$length` (`int`, default `16`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $length is less than 1.

### between()

```php
public static function between(string $text, string $from, string $to): string
```

Extracts text between the first occurrence of $from and the next occurrence of $to.

Parameters:
- `$text` (`string`, required)
- `$from` (`string`, required)
- `$to` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If a delimiter is empty.
- `UnexpectedValueException`: If a delimiter is not found in the string.

### before()

```php
public static function before(string $text, string $search): string
```

Extracts text before the first occurrence of $search.

Parameters:
- `$text` (`string`, required)
- `$search` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $search is empty.
- `UnexpectedValueException`: If $search is not found in the string.

### beforeLast()

```php
public static function beforeLast(string $text, string $search): string
```

Extracts text before the last occurrence of $search ('report.final.pdf', '.' -> 'report.final').

Parameters:
- `$text` (`string`, required)
- `$search` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $search is empty.
- `UnexpectedValueException`: If $search is not found in the string.

### after()

```php
public static function after(string $text, string $search): string
```

Extracts text after the first occurrence of $search.

Parameters:
- `$text` (`string`, required)
- `$search` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $search is empty.
- `UnexpectedValueException`: If $search is not found in the string.

### afterLast()

```php
public static function afterLast(string $text, string $search): string
```

Extracts text after the last occurrence of $search ('report.final.pdf', '.' -> 'pdf').

Parameters:
- `$text` (`string`, required)
- `$search` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $search is empty.
- `UnexpectedValueException`: If $search is not found in the string.

### squish()

```php
public static function squish(string $text): string
```

Trims leading/trailing whitespace and reduces any whitespace sequence (including tabs, newlines, non-breaking spaces, zero-width spaces) to a single space.

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### padLeft()

```php
public static function padLeft(string $text, int $length, string $pad = ' '): string
```

Pads the string on the left up to $length characters ('7', 3, '0' -> '007'). Strings longer than $length remain untouched (not truncated).

Parameters:
- `$text` (`string`, required)
- `$length` (`int`, required)
- `$pad` (`string`, default `' '`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $pad is empty.
- `UnexpectedValueException`: If string is not valid UTF-8.

### padRight()

```php
public static function padRight(string $text, int $length, string $pad = ' '): string
```

Pads the string on the right up to $length characters. Strings longer than $length remain untouched (not truncated).

Parameters:
- `$text` (`string`, required)
- `$length` (`int`, required)
- `$pad` (`string`, default `' '`)

Returns `string`.

Throws:
- `InvalidArgumentException`: If $pad is empty.
- `UnexpectedValueException`: If string is not valid UTF-8.

### ucfirst()

```php
public static function ucfirst(string $text): string
```

Capitalizes first character, supporting multibyte and accented characters (native ucfirst() handles ASCII only).

Parameters:
- `$text` (`string`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

### e()

```php
public static function e(string|int|float|Stringable|null $value): string
```

Escapes text for HTML (content and attribute values, with single or double quotes). null becomes an empty string.

Parameters:
- `$value` (`Stringable|string|int|float|null`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

## Functions

### e()

```php
function e(string|int|float|Stringable|null $value): string
```

HTML escaping: shortcut for Str::e().

Parameters:
- `$value` (`Stringable|string|int|float|null`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If string is not valid UTF-8.

