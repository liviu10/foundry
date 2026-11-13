# Env

> .env file loader (phpdotenv syntax), schema validation, and typed casting.

Canonical: https://foundry.insightdevhub.ro/docs/env/  
Namespace: `Foundry\Env` · File: `Env.php` · Version 1.1 · PHP 8.3+

Loads environment variables from .env files and reads them as typed values. The file syntax is compatible with vlucas/phpdotenv.

.env syntax:

```text
# comment
APP_ENV=production
export DB_HOST=localhost            # "export" is optional; trailing comment
DB_PASSWORD=                        # empty value
APP_NAME="Customer Portal"          # double quotes: escape sequences (\n \t \" \\ \$) and ${VAR}
REGEX='^\d+$'                       # single quotes: literal text, no interpolation
DB_DSN="mysql:host=${DB_HOST}"      # interpolation from previously defined variables
CERT="-----BEGIN-----
...
-----END-----"                      # multiline value (enclosed in quotes)
```

## Rules

- variables already present in the environment are NOT overwritten (unless $override = true)
- unquoted values cannot contain whitespace
- a duplicate key in the same file or an undefined ${VAR} throws an exception
- error messages contain the file, line, and variable name, never the value.

## Usage

```php
require_once 'Env.php';
use Foundry\Env;

Env::load(__DIR__.'/.env');
Env::loadIfExists(__DIR__.'/.env.local', override: true);
Env::required('DB_HOST', 'DB_USER', 'DB_PASSWORD');

$host = Env::string('DB_HOST');
$port = Env::int('DB_PORT', 3306);
$debug = Env::bool('APP_DEBUG', false);
$hosts = Env::list('ALLOWED_HOSTS');            // "a.com, b.com" -> ['a.com', 'b.com']
$env = Env::oneOf('APP_ENV', ['local', 'test', 'production']);
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Env.php';

use Foundry\Env;
```

## Methods

### load()

```php
public static function load(string $path, bool $override = false, bool $putenv = false): array
```

Loads a .env file into $_ENV and $_SERVER (and optionally into getenv()).

Parameters:
- `$path` (`string`, required): The .env file path.
- `$override` (`bool`, default `false`): true: overwrites existing environment variables.
- `$putenv` (`bool`, default `false`): true: also sets via putenv(), for code relying on getenv(). putenv() is not thread-safe; avoid it in multithreaded environments.

Returns `array<string, string>`: The variables actually set (excluding skipped ones).

Throws:
- `RuntimeException`: If the file does not exist or cannot be read.
- `UnexpectedValueException`: If the file contains syntax errors.

### loadIfExists()

```php
public static function loadIfExists(string $path, bool $override = false, bool $putenv = false): bool
```

Like load(), but without throwing an error if the file does not exist (e.g. optional .env.local). Syntax errors still throw exceptions.

Parameters:
- `$path` (`string`, required)
- `$override` (`bool`, default `false`)
- `$putenv` (`bool`, default `false`)

Returns `bool`: true if the file existed and was loaded.

Throws:
- `RuntimeException`: If the file exists but cannot be read.
- `UnexpectedValueException`: If the file contains syntax errors.

### parse()

```php
public static function parse(string $content, string $source = '.env'): array
```

Parses the contents of a .env file without modifying the environment. ${VAR} references are resolved first from preceding variables in the same content, then from the environment.

Parameters:
- `$content` (`string`, required): The file contents.
- `$source` (`string`, default `'.env'`): Source name used in error messages.

Returns `array<string, string>`: The variables, in the order defined in the file.

Throws:
- `UnexpectedValueException`: On syntax errors.

### required()

```php
public static function required(string ...$names): void
```

Verifies that all specified variables exist (empty values are allowed). Reports all missing variables in a single exception.

Parameters:
- `$names` (`string`)

Throws:
- `RuntimeException`: If at least one variable is missing.

### notEmpty()

```php
public static function notEmpty(string ...$names): void
```

Verifies that all specified variables exist and are not empty (whitespace-only is considered empty).

Parameters:
- `$names` (`string`)

Throws:
- `RuntimeException`: If at least one variable is missing or empty.

### has()

```php
public static function has(string $name): bool
```

Checks whether an environment variable exists (even if empty).

Parameters:
- `$name` (`string`, required)

Returns `bool`.

### string()

```php
public static function string(string $name, ?string $default = null): string
```

Value as a string. An empty value is returned as ''.

Parameters:
- `$name` (`string`, required): The variable name.
- `$default` (`string|null`, default `null`): The fallback value if the variable is missing; null = required.

Returns `string`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.

### nullable()

```php
public static function nullable(string $name): ?string
```

Value or null if the variable is missing or empty.

Parameters:
- `$name` (`string`, required)

Returns `?string`.

### int()

```php
public static function int(string $name, ?int $default = null): int
```

Value as an integer (e.g. "3306", "-1").

Parameters:
- `$name` (`string`, required): The variable name.
- `$default` (`int|null`, default `null`): The fallback value if the variable is missing; null = required.

Returns `int`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not a valid integer.

### float()

```php
public static function float(string $name, ?float $default = null): float
```

Value as a float, using a dot (e.g. "0.19", "1e3"). Comma is not accepted.

Parameters:
- `$name` (`string`, required)
- `$default` (`?float`, default `null`)

Returns `float`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not a valid float.

### bool()

```php
public static function bool(string $name, ?bool $default = null): bool
```

Value as a boolean. Accepts (case-insensitively): true/false, 1/0, yes/no, on/off. Any other value results in an error.

Parameters:
- `$name` (`string`, required)
- `$default` (`?bool`, default `null`)

Returns `bool`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not one of the accepted representations.

### list()

```php
public static function list(string $name, ?array $default = null, string $separator = ','): array
```

Value as a list: "a, b ,c" -> ['a', 'b', 'c']. Empty elements are filtered out.

Parameters:
- `$name` (`string`, required): The variable name.
- `$default` (`list<string>|null`, default `null`): The fallback list if the variable is missing; null = required.
- `$separator` (`non-empty-string`, default `','`): The separator.

Returns `list<string>`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.

### oneOf()

```php
public static function oneOf(string $name, array $allowed, ?string $default = null): string
```

Value that must match one of the allowed values (strict comparison).

Parameters:
- `$name` (`string`, required): The variable name.
- `$allowed` (`list<string>`, required): The allowed values.
- `$default` (`string|null`, default `null`): The fallback value if the variable is missing; null = required.

Returns `string`.

Throws:
- `RuntimeException`: If the variable is missing and no $default is provided.
- `UnexpectedValueException`: If the value is not allowed.

