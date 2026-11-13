# Ini

> Strict .ini file parser with line-accurate error reporting and no implicit type coercion.

Canonical: https://foundry.insightdevhub.ro/docs/ini/  
Namespace: `Foundry\Ini` · File: `Ini.php` · Version 1.1 · PHP 8.3+

INI file parsing with clear error reporting and predictable value conversions.

Natively, parse_ini_*() returns false with a warning on syntax errors, and in default mode (INI_SCANNER_NORMAL) "yes" becomes "1", "null" becomes "", and values matching defined PHP constant names are replaced with the constant's value.

## Modes

- default (raw):   all values remain strings, exactly as in the file; double quotes are stripped, single quotes remain part of the value
- typed: true:     "true"/"yes"/"on" -> true, "false"/"no"/"off"/"none" -> false, "null" -> null, numeric strings -> int/float (INI_SCANNER_TYPED); PHP constants, ${ENV} variables and expressions ("E_ALL & ~E_NOTICE", "a|b") are rejected with an exception instead of being silently substituted or evaluated.

UTF-8 BOM is stripped (otherwise the first key would include it). Duplicate keys are permitted by PHP; last occurrence takes precedence.

## Usage

```php
require_once 'Ini.php';
use Foundry\Ini;

$config = Ini::parseFile(__DIR__.'/config.ini', sections: true);
$host = $config['database']['host'];

$flags = Ini::parseString("debug = yes\nport = 8080", typed: true);   // ['debug' => true, 'port' => 8080]
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Ini.php';

use Foundry\Ini;
```

## Methods

### parseFile()

```php
public static function parseFile(string $path, bool $sections = false, bool $typed = false): array
```

Reads and parses an INI file.

Parameters:
- `$path` (`string`, required): The file path.
- `$sections` (`bool`, default `false`): true: results are grouped by section ([section]).
- `$typed` (`bool`, default `false`): true: converts values to bool/null/int/float; false: strings only.

Returns `array<array-key, mixed>`.

Throws:
- `RuntimeException`: If the file does not exist or cannot be read.
- `UnexpectedValueException`: On syntax errors.

### parseString()

```php
public static function parseString(string $content, bool $sections = false, bool $typed = false): array
```

Parses an INI string.

Parameters:
- `$content` (`string`, required): The INI content.
- `$sections` (`bool`, default `false`): true: results are grouped by section ([section]).
- `$typed` (`bool`, default `false`): true: converts values to bool/null/int/float; false: strings only.

Returns `array<array-key, mixed>`.

Throws:
- `UnexpectedValueException`: On syntax errors.

