# Foundry — Modern Standalone PHP Utility Library

[![PHP](https://img.shields.io/badge/PHP-8.3%2B%20%7C%207.4-8892BF.svg)](https://php.net)
[![Dependencies](https://img.shields.io/badge/dependencies-0-success.svg)](composer.json)

**Foundry** is a curated suite of 51 production-grade, single-file PHP utility classes designed for modern web applications. Any issue triggers an explicit, descriptive exception — never silent `false`, unexpected `null`, or an unhandled warning.

Each utility is **a single standalone file**: copy only what you need, with zero Composer vendor trees and zero third-party dependencies in production.

## Highlights

- **Zero Third-Party Dependencies:** 100% native PHP.
- **Dual PHP Target:** Written in modern PHP 8.3+ with automated downgrade compatibility targeting PHP 7.4.
- **Single-File Isolation:** Copy only the files you need into your project; zero vendor bloat.
- **Strict Typing & Fail-Fast:** `declare(strict_types=1)` with explicit, descriptive exceptions instead of silent failures.

## Table of Contents

- [Installation in Your Project](#installation-in-your-project)
- [Usage](#usage)
- [Design Principles](#design-principles)
- [Catalog](#catalog)
- [PHP Versions](#php-versions)
- [Development](#development)

---

## Installation in Your Project

**1. Copy the files** you need into a dedicated folder within your project, for example `lib/Utilities/` (or `src/Utilities/` if your project already uses `src/`). Source directories:

- For PHP 8.3 or newer: copy directly from `src/`;
- For PHP 7.4: generated via Rector downgrade (`php rector-downgrade.php`).

**2. Load them** using one of the approaches below.

*Without Composer*, using `require_once` for each required utility:

```php
require_once __DIR__.'/lib/Utilities/Json.php';

use Foundry\Json;
```

*Without Composer, using an SPL autoloader* (registered once in your application bootstrap file):

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Foundry\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {      // compatible with PHP 7.4 as well
        require __DIR__.'/lib/Utilities/'.substr($class, strlen($prefix)).'.php';
    }
});
```

*With Composer*, in your project's `composer.json`, followed by `composer dump-autoload`:

```json
"autoload": {
    "psr-4": { "Foundry\\": "lib/Utilities/" },
    "files": ["lib/Utilities/Str.php", "lib/Utilities/Debug.php"]
}
```

The lines in `files` are only required if you use global helper functions like `e()` (from `Str.php`) or `dump()` / `dd()` (from `Debug.php`). Autoloading only loads a file when the class is first referenced, meaning global functions called beforehand would not yet exist.

**3. Check PHP extensions** required by the copied utilities (`php -m`):

| Extension | Utilities |
| --- | --- |
| `mbstring` | Most text-processing utilities (`Str`, `Validator`, `Request`, `Csv`, `Xlsx`...) |
| `iconv` | `Encoding`, `Csv` |
| `fileinfo` | `File`, `Upload`, `Mailer` |
| `session` | `Session`, `Csrf`, `Flash` |
| `bcmath` | `Money` |
| `curl` | `HttpClient` |
| `openssl` | `Crypt` (and `Mailer` for TLS) |
| `zip`, `xmlreader`, `xmlwriter` | `Xlsx`, `Zip`, `Xml` |
| `gd` | `Image` |
| `pdo_mysql` / `oci8` / `pdo_sqlite` | `MySQL` / `Oracle` / `SQLite` |

**4. When updating**, simply replace the file with the newer version. Each file includes `@version` in its file header, making version differences immediately visible. Do not modify copied files directly: a patch made in only one consuming project will be lost upon the next sync. All improvements and bug fixes should be made in this repository.

## Usage

All classes reside in the `Foundry` namespace. The header of each file documents its purpose, rules, and code examples; methods provide standard docblocks specifying thrown exceptions (`@throws`).

```php
use Foundry\Cast;
use Foundry\Date;
use Foundry\Money;

$id = Cast::toInt($_GET['id'] ?? null);               // '12' -> 12; 'abc', '' or missing -> exception
$date = Date::parse('15.03.2026', 'd.m.Y');          // 31.02.2026 -> exception, never rolled over to 03.03.2026
$total = Money::add('1250.50', '99.99');              // '1350.49', without floating-point inaccuracies
```

**Exceptions** follow standard PHP SPL classes. You do not need `try/catch` blocks everywhere: unhandled exceptions normally bubble up to `ErrorHandler`, which logs them and returns a secure HTTP 500. Only catch what you can actively recover from, such as invalid user input:

```php
try {
    $amount = Number::parse($_POST['amount']);         // '1.234,50' -> 1234.5
} catch (UnexpectedValueException $e) {
    $errors['amount'] = 'The amount is invalid.';
}
```

**Initial bootstrap setup** (invoked once in the application entrypoint):

```php
use Foundry\ErrorHandler;
use Foundry\Log;
use Foundry\SecurityHeaders;
use Foundry\Session;

$log = new Log(__DIR__.'/storage/logs/app.log', minLevel: 'info', daily: true, maxFiles: 30);

ErrorHandler::register(
    debug: false,                                     // true only in local development
    logger: fn (Throwable $e) => $log->error('Uncaught exception', ['exception' => $e]),
);
SecurityHeaders::send();
Session::start(['name' => 'APPSESSID', 'idleTimeout' => 1800]);
```

**Full form processing workflow** (reading, validation, persistence, feedback, redirect):

```php
$request = Request::fromGlobals();

if ($request->isPost()) {
    Csrf::check();                                    // invalid token -> exception (HTTP status 419)

    $validator = new Validator($request->all(), [
        'email' => 'required|email|max:255',
        'cnp' => ['required', 'cnp' => RoValidator::cnp(...)],
        'birth_date' => 'required|date:d.m.Y',
    ]);

    if ($validator->fails()) {
        Session::flashInput($request->only('email', 'birth_date'));
        Session::flash('errors', $validator->errors());
        Response::redirect('/clients/new')->send();

        return;
    }

    $db->insert('clients', $validator->validated());
    Flash::success('Client was saved successfully.');
    Response::redirect('/clients')->send();

    return;
}

// In view templates: Session::old('email'), Session::flashed('errors'), Flash::pull(), Csrf::field()
```

`Response::send()` does not terminate the script execution: always follow it with `return` (or `exit`). UI presentation utilities (`Flash`, `Breadcrumbs`, `FormBuilder`) return pure data structures, leaving escaping and HTML markup to templates (`View::escape()` or `e()`).

## Design Principles

- **Explicit errors.** Any error throws an exception detailing the operation, context, and root cause; no silent `false` or `null` return values for error states.
- **One file = one tool.** Zero internal dependencies between utility files; slight code duplication across files is intentionally preferred over cross-dependencies.
- **Pure native PHP.** Composer is strictly a dev dependency in this repo for testing and quality tools.
- **Standard SPL exceptions** (`InvalidArgumentException`, `RuntimeException`, `UnexpectedValueException`, `LogicException`, etc.), avoiding custom exception classes.
- **`Foundry` namespace** to eliminate collision risks with host application classes (`Log`, `Str`, `Arr`).
- **No side effects upon require.** Including a file never mutates global state; state-modifying components (`ErrorHandler`, `Session`) require explicit activation.
- **Native warnings converted internally.** Native PHP warnings are caught internally and converted into exceptions, regardless of host error handler configuration.
- **No PII in error messages.** Exceptions report operational metadata (type, length, path), never customer or sensitive payload data.
- **Data over HTML.** UI helpers return arrays and data models; HTML tags and styling remain strictly in templates.

## Catalog

### Errors & Exception Handling

| Utility | Overview |
| --- | --- |
| `ErrorHandler` | Explicitly registered: converts warnings to `ErrorException`, logs deprecations, handles uncaught exceptions and fatal errors with logging and a generic 500 response (detailed traces in debug mode only). |

### Native Function Wrappers

| Utility | Overview |
| --- | --- |
| `Json` | Strict encode/decode, atomic file I/O; preserves large integers, strips BOM safely. |
| `File` | Streaming reads, atomic writes, stream-based copying, safe directory creation (concurrency-safe), MIME detection from file bytes. |
| `Regex` | Strict `preg_*` wrapper throwing exceptions on invalid patterns or execution failures. |
| `Cast` | Strict type conversions `toInt`/`toFloat`/`toBool`/`toString` (rejects `'12abc'`, `'1,5'`, `''`). |
| `Encoding` | Windows-1250 <-> UTF-8 conversion, Romanian cedilla <-> comma normalization, UTF-8 validity verification, BOM detection. |
| `Upload` | Secure file uploads: MIME detection from file inspection, whitelist-checked extension, cryptographically random names. |
| `Process` | Safe command execution without shell expansion, exit code validation, stdin support, configurable timeouts. |
| `Zip` | Secure archive creation and extraction (protected against zip slip, symlink attacks, and zip bombs). |
| `Image` | Image metadata, validation against decompression bombs, aspect-ratio resizing, thumbnail generation; requires `gd`. |
| `Ini` | Strict `.ini` file parser with line-accurate error reporting and no implicit type coercion. |
| `Base64` | Strict decoding, URL-safe Base64 (RFC 4648), hex conversion. |
| `Serializer` | Secure `unserialize` wrapper disabling arbitrary class instantiation and enforcing `max_depth`; prefer `Json` for new data. |

### Text, Dates, and Numbers

| Utility | Overview |
| --- | --- |
| `Str` | URL slugging, diacritic stripping, data masking (credit card, IBAN, email), case conversions, `e()` helper; fully UTF-8 compliant. |
| `Arr` | Dot notation access (missing keys throw exceptions), `pluck`, `keyBy`, `groupBy`, `sortBy`, strict searches. |
| `Date` | Strict parsing (31.02 fails), localization formatting, Romanian public holidays, business day calculations. |
| `Number` | Romanian and European number formatting and parsing (`1.234,50`), independent of thread-unsafe `setlocale`. |
| `Money` | Currency amounts as exact strings, `bcmath` arithmetic, bankers rounding, loss-free installment splitting. |

### Validation

| Utility | Overview |
| --- | --- |
| `Validator` | Rule-based attribute validation (`'required|email|max:255'`), single error per field, stable translation error codes, custom callbacks. |
| `RoValidator` | Romanian specific validators: CNP, CUI/CIF, IBAN, phone numbers, postal codes, identity card series; returns boolean, usable directly in `Validator`. |

### Databases

| Utility | Overview |
| --- | --- |
| `MySQL` | Secure PDO wrapper, verified SQL identifiers, enforced credentials. |
| `Oracle` | `oci8` with ORA errors as exceptions; targets Oracle 11.2.0.4+ (`ROWNUM` pagination, sequences). |
| `SQLite` | PDO; missing files are never silently created, `foreign_keys` PRAGMA enabled. |
| `Paginator` | URL-based pagination, sorting, and search limited strictly to whitelisted columns. |

`MySQL`, `Oracle`, and `SQLite` share an **identical API interface**: `fetchAll`, `fetchOne`, `fetchValue`, `execute`, `insert`, `update`, `delete`, `insertMany` (chunked within a single transaction), `cursor` (streaming generator), `transaction`, `paginate`, `whereIn`. Integration tests for MySQL and Oracle run only when `MYSQL_TEST_*` / `ORACLE_TEST_*` environment variables are configured.

### Import / Export

| Utility | Overview |
| --- | --- |
| `Csv` | Streaming reader (auto-detected delimiter, BOM, Windows-1250 support), atomic writer, batch validation import. |
| `Xlsx` | Zero-dependency `.xlsx` reader/writer: streaming generation, split-archive ZIP exports when exceeding sheet limits, batch import. |
| `Xml` | Secure XML parsing (immune to XXE), XSD schema validation (e.g., ANAF e-Factura), XPath querying, streaming I/O. |

`Csv` and `Xlsx` process millions of rows with constant $O(1)$ memory consumption. Batch transaction management and timeouts remain in application space. Imports can be resumed from stored cursors; multi-request `.xlsx` uploads are supported via `Xlsx::stage()`. Benchmark on 1,000,000 rows: CSV import 8.1s / 71 MB, `.xlsx` import 13.5s / 69 MB.

```php
$start = microtime(true);
foreach (Csv::import($files, $schema, batchSize: 5000, cursor: $cursor) as $batch) {
    // persist $batch['rows'] to database, append $batch['errors'] to execution audit log
    $cursor = $batch['cursor'];
    if ($batch['done'] || microtime(true) - $start > 20) {
        break;
    }
}
```

### Configuration & Infrastructure

| Utility | Overview |
| --- | --- |
| `Env` | `.env` file loader (`phpdotenv` syntax), schema validation, and typed casting. |
| `Config` | Nested PHP configuration arrays with dot notation; missing keys throw exceptions. |
| `Log` | PSR-3 compliant file logger, automatic sensitive data masking, daily log rotation. |
| `HttpClient` | cURL wrapper converting network failures and non-2xx statuses to exceptions, SSL verification enabled, default timeouts. |
| `Cache` | JSON file-based cache with atomic file writes and `remember()` closure caching. |
| `Retry` | Exponential backoff retry handler with jitter for transient failures. |
| `RateLimiter` | Sliding/fixed window rate limiting by key (e.g., client IP), concurrency-safe. |
| `Crypt` | AES-256-GCM authenticated encryption with key rotation support and HMAC validation. |
| `Token` | Cryptographically secure random tokens and HMAC-SHA256 JWTs with mandatory `exp`. |
| `Mailer` | Native SMTP transport (TLS, proxy support), multipart HTML/plain-text, attachments. |

### Web & HTTP

| Utility | Overview |
| --- | --- |
| `Request` | Encapsulates incoming HTTP requests with typed getters (`int('id')`: missing or non-integer throws exception), JSON body decoding, trusted proxy IP resolution. |
| `Response` | Immutable HTTP response object dispatched via `send()`: JSON, redirects, streaming downloads, secure cookies. |
| `Session` | Explicit session initialization with hardened cookie options, flash data, `old()` form retention, idle timeout invalidation. |
| `Csrf` | Session-bound anti-CSRF token verification for POST/PUT/PATCH/DELETE requests. |
| `View` | Isolated PHP template renderer confined to a base directory. |
| `Url` | Query parameter builder, current URL generation against trusted hosts, local redirect validation (`isLocal`). |
| `SecurityHeaders` | Content Security Policy (CSP) with nonce generation, HSTS on HTTPS, and essential defensive HTTP headers. |
| `BotDetection` | Honeypot field verification and HMAC-timestamped minimum submission time checking for public forms. |

### UI Rendering Helpers

| Utility | Overview |
| --- | --- |
| `Breadcrumbs` | Hierarchical trail management with automated schema.org JSON-LD generation for search engines. |
| `Flash` | Flash messaging for sequential requests (success, warning, error, info), exposed as raw arrays. |
| `FormBuilder` | Schema-driven form structure generation and `Validator` rule mapping directly from table column metadata. |

### Development Tools

| Utility | Overview |
| --- | --- |
| `Debug` | Safe `dump()` / `dd()` utilities disabled by default (`Debug::enable()` required), with automated sensitive data redaction. |
| `Timer` | High-precision stage profiling and memory tracking; includes a statistical `benchmark()` helper. |
| `Faker` | Generates realistic Romanian test data (valid CNP, CUI, IBAN, physical addresses), with optional deterministic seeding. |

**Intentionally excluded from the library:** custom exception hierarchies and `Assert` libraries (native PHP types, `Validator`, and `Cast` are used instead), `Html` and `Lang` engines (which would constrain template engines and translation stores), license plate validation, and polyfills for features already native in PHP 8.3 (`str_contains`, `array_is_list`, `json_validate`, `password_hash`, etc.).

## PHP Versions

| Directory | PHP Version | Role |
| --- | --- | --- |
| `src/` | 8.3+ | Source code: all utilities are authored and tested here |
| `dist/php74/` | 7.4 | Generated via `composer build`, committed to git: directly copyable without build tools |

Files in `dist/php74/` must never be modified manually; they are automatically regenerated from `src/`. The build process downgrades syntax using Rector, injects missing PHP 8 polyfills (`get_debug_type`, etc.), and validates the output with PHP 7.4.33 (syntax check followed by real execution in `tools/smoke74.php`). The main PHPUnit test suite runs on PHP 8.3+.

Behavioral differences in PHP 7.4 stemming from the PHP engine:

- `SQLite` (pdo_sqlite) returns numeric values as strings (`'2'`) instead of integers; native driver types were introduced in PHP 8.1.
- `gd` image handles are resources rather than `GdImage` objects (transparent when using `Image`).

PHP 5.x is unsupported and out of scope.

## Development

Requirements: PHP 8.3 in `PATH` and Composer, followed by `composer install`. Running `composer build` additionally requires PHP 7.4 (see below).

| Command | Action |
| --- | --- |
| `composer check` | **Runs all quality checks (Pint, Rector, PHPStan max, PHPUnit); must pass before commit** |
| `composer fix` | Applies Rector refactorings and Pint code style fixes |
| `composer test` / `analyse` / `lint` | Runs individually: PHPUnit / PHPStan / Pint |
| `composer build` | Regenerates `dist/php74/` and verifies compatibility against PHP 7.4 (syntax & smoke test) |

### Directory Structure

```text
src/             One file per utility (PHP 8.3)
tests/           <Utility>Test.php; fixtures/ = test servers (HTTP, SMTP, web scenarios)
dist/php74/      Generated by composer build (tracked in git)
tools/           build.php (8.3 -> 7.4 transpilation) and smoke74.php (PHP 7.4 execution test)
```

### Conventions

- Class names, methods, and variables in English; comments and docblocks in English; exception messages in English.
- `declare(strict_types=1)`, `final` classes, strict type comparisons; Pint (preset `laravel`); PHPStan max level without `@phpstan-ignore` or baseline workarounds.
- File header: description, usage example, `@version`, `@since PHP 8.3`, `@author`, `@license`.
- Error message pattern: `<Class>::<method>(): <what happened> [<context>]: <root cause>.`, for example: `Json::decode(): Syntax error (input length: 24 bytes).`
- Native PHP functions that produce warnings run under a temporary error handler; the warning is caught and converted into the exception cause. The `@` error-suppression operator is strictly prohibited.

### Adding or Modifying a Utility

1. Create or edit `src/<Utility>.php` with standard headers and add corresponding tests in `tests/<Utility>Test.php`.
2. Test thoroughly: standard and edge cases, exception messages, verify warnings never escape to the global handler, and ensure error messages contain zero sensitive user data.
3. Run `composer fix`, then `composer check`.
4. Run `composer build` so `dist/php74/` remains synchronized; include the generated files in the same commit.
5. Update the [Catalog](#catalog).
6. Increment `@version` (`1.0` -> `1.1`) upon any modification so consuming projects can track versions.

### PHP 7.4 alongside 8.3 (Windows Setup)

PHP 7.4 should not be added to system `PATH`; point to it via the `PHP74_BIN` environment variable.

```powershell
Invoke-WebRequest "https://downloads.php.net/~windows/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip" -OutFile "$env:TEMP\php74.zip"
Expand-Archive "$env:TEMP\php74.zip" -DestinationPath "C:\php74"
Copy-Item "C:\php74\php.ini-development" "C:\php74\php.ini"
# in php.ini: extension_dir = "ext", extension=mbstring, extension=openssl, extension=pdo_sqlite
[Environment]::SetEnvironmentVariable("PHP74_BIN", "C:\php74\php.exe", "User")
```

Archive SHA256: `14ae3250d4447c8ccfc4c45a70d90adfbcd61e728d85f0be56a7ddf8f9c8aace`. Verification in a new terminal: `& $env:PHP74_BIN -v` -> 7.4.33.
