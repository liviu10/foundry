# Config

> Nested PHP configuration arrays with dot notation; missing keys throw exceptions.

Canonical: https://foundry.insightdevhub.ro/docs/config/  
Namespace: `Foundry\Config` · File: `Config.php` · Version 1.0 · PHP 8.3+

Configuration loaded from PHP files returning arrays, with dot-notation access. The first segment of the key is the filename: 'database.connections.mysql.host' reads config/database.php -> ['connections']['mysql']['host'].

Files are loaded on first access and cached in memory for the remainder of the request. A missing key without a default value is an error (does not silently return null).

Example config/database.php:

```text
<?php
return [
    'host' => Env::string('DB_HOST'),
    'port' => Env::int('DB_PORT', 3306),
];
```

## Usage

```php
require_once 'Config.php';
use Foundry\Config;

Config::load(__DIR__.'/config');

$host = Config::string('database.host');
$port = Config::int('database.port');
$debug = Config::bool('app.debug', false);
$mail = Config::array('mail');                 // the entire config/mail.php file
$value = Config::get('app.optional', null);    // any type, with default value
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Config.php';

use Foundry\Config;
```

## Methods

### load()

```php
public static function load(string $directory): void
```

Sets the configuration directory and clears previously loaded files.

Parameters:
- `$directory` (`string`, required)

Throws:
- `RuntimeException`: If the directory does not exist.

### get()

```php
public static function get(string $key, mixed $default = null): mixed
```

Returns the value of a key of any type. With a single argument the key is required; with two arguments, the second acts as the default value.

Parameters:
- `$key` (`string`, required)
- `$default` (`mixed`, default `null`)

Returns `mixed`.

Throws:
- `RuntimeException`: If the key is missing and no default value is provided.

### has()

```php
public static function has(string $key): bool
```

Checks whether a key exists (a missing file means the key does not exist).

Parameters:
- `$key` (`string`, required)

Returns `bool`.

### string()

```php
public static function string(string $key, ?string $default = null): string
```

Returns the value as a string (no type conversion: an int in config throws an error).

Parameters:
- `$key` (`string`, required)
- `$default` (`?string`, default `null`)

Returns `string`.

Throws:
- `RuntimeException`: If the key is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not a string.

### int()

```php
public static function int(string $key, ?int $default = null): int
```

Returns the value as an int (no type conversion: "3306" in config throws an error).

Parameters:
- `$key` (`string`, required)
- `$default` (`?int`, default `null`)

Returns `int`.

Throws:
- `RuntimeException`: If the key is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not an int.

### float()

```php
public static function float(string $key, ?float $default = null): float
```

Returns the value as a float (integers are accepted and converted).

Parameters:
- `$key` (`string`, required)
- `$default` (`?float`, default `null`)

Returns `float`.

Throws:
- `RuntimeException`: If the key is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not a number.

### bool()

```php
public static function bool(string $key, ?bool $default = null): bool
```

Returns the value as a bool (no type conversion: "true" or 1 in config throws an error).

Parameters:
- `$key` (`string`, required)
- `$default` (`?bool`, default `null`)

Returns `bool`.

Throws:
- `RuntimeException`: If the key is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not a bool.

### array()

```php
public static function array(string $key, ?array $default = null): array
```

Returns the value as an array (e.g. a section or an entire file).

Parameters:
- `$key` (`string`, required)
- `$default` (`array<array-key, mixed>|null`, default `null`)

Returns `array<array-key, mixed>`.

Throws:
- `RuntimeException`: If the key is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not an array.

### set()

```php
public static function set(string $key, mixed $value): void
```

Overwrites a value in memory (e.g. during testing or dynamic configuration). The file corresponding to the first segment is loaded beforehand, if it exists.

Parameters:
- `$key` (`string`, required)
- `$value` (`mixed`, required)

Throws:
- `InvalidArgumentException`: If the key is invalid.

### reset()

```php
public static function reset(): void
```

Clears the configured directory and all loaded values (e.g. between tests).

