# Encoding

> Windows-1250 <-> UTF-8 conversion, Romanian cedilla <-> comma normalization, UTF-8 validity verification, BOM detection.

Canonical: https://foundry.insightdevhub.ro/docs/encoding/  
Namespace: `Foundry\Encoding` · File: `Encoding.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring, iconv

Character encoding conversions, with a focus on Romanian text from legacy systems (Excel, CSV exported from Windows, Latin2 databases).

Natively, iconv() returns false with a notice on illegal characters, while mb_convert_encoding() silently replaces invalid characters with "?". In addition, mbstring does not recognize Windows-1250, which is why conversions are performed using iconv.

## About Romanian diacritics

- the correct form uses a comma: s, t with comma below (U+0219, U+021B, U+0218, U+021A)
- legacy systems use the cedilla form (U+015F, U+0163, U+015E, U+0162)
- Windows-1250 and ISO-8859-2 contain ONLY the cedilla form; therefore fromUtf8() to these encodings automatically converts comma to cedilla.

An 8-bit encoding cannot be reliably detected (the same bytes are valid across many encodings); therefore detect() is not provided, but ensureUtf8() with an explicitly declared fallback encoding.

## Usage

```php
require_once 'Encoding.php';
use Foundry\Encoding;

$text = Encoding::ensureUtf8(File::read('import.csv'));        // UTF-8 or Windows-1250 -> UTF-8
$text = Encoding::fixRomanianDiacritics($text);                // cedilla -> comma
$legacy = Encoding::fromUtf8($text, 'Windows-1250');           // for a legacy system
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Encoding.php';

use Foundry\Encoding;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `BOM` | `string` | `"\xEF\xBB\xBF"` | UTF-8 BOM. |

## Methods

### isUtf8()

```php
public static function isUtf8(string $text): bool
```

Checks whether the string is valid UTF-8.

Parameters:
- `$text` (`string`, required)

Returns `bool`.

### assertUtf8()

```php
public static function assertUtf8(string $text): void
```

Throws an exception if the string is not valid UTF-8.

Parameters:
- `$text` (`string`, required)

Throws:
- `UnexpectedValueException`: If the string contains invalid UTF-8 sequences.

### toUtf8()

```php
public static function toUtf8(string $text, string $from): string
```

Converts text from the $from encoding to UTF-8.

Parameters:
- `$text` (`string`, required): The text to convert.
- `$from` (`string`, required): The source encoding (e.g. 'Windows-1250', 'ISO-8859-2', 'UTF-16LE').

Returns `string`.

Throws:
- `InvalidArgumentException`: If the encoding is not supported.
- `UnexpectedValueException`: If the text contains invalid bytes for $from.

### ensureUtf8()

```php
public static function ensureUtf8(string $text, string $fallback = 'Windows-1250'): string
```

Returns the text in UTF-8: unchanged if already valid UTF-8, otherwise converted from $fallback. Suitable for imported files whose encoding is not known in advance.

Parameters:
- `$text` (`string`, required): The text.
- `$fallback` (`string`, default `'Windows-1250'`): The assumed encoding if the text is not UTF-8.

Returns `string`.

Throws:
- `InvalidArgumentException`: If $fallback is not supported.
- `UnexpectedValueException`: If the text is not valid in $fallback either.

### fromUtf8()

```php
public static function fromUtf8(string $text, string $to): string
```

Converts UTF-8 text to another encoding. For Windows-1250 / ISO-8859-2, diacritics with comma are first converted to cedilla.

Parameters:
- `$text` (`string`, required): UTF-8 text.
- `$to` (`string`, required): The target encoding.

Returns `string`.

Throws:
- `InvalidArgumentException`: If the encoding is not supported.
- `UnexpectedValueException`: If the text is not valid UTF-8 or contains characters that do not exist in the target encoding.

### fixRomanianDiacritics()

```php
public static function fixRomanianDiacritics(string $text): string
```

Replaces diacritics with cedilla (legacy form) with the correct form with comma. The text must be UTF-8.

Parameters:
- `$text` (`string`, required)

Returns `string`.

### legacyRomanianDiacritics()

```php
public static function legacyRomanianDiacritics(string $text): string
```

Replaces diacritics with comma with the cedilla form, for systems that do not recognize the correct form. The text must be UTF-8.

Parameters:
- `$text` (`string`, required)

Returns `string`.

### stripBom()

```php
public static function stripBom(string $text): string
```

Removes the UTF-8 BOM from the beginning of the text, if present.

Parameters:
- `$text` (`string`, required)

Returns `string`.

