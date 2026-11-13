# ErrorHandler

> Explicitly registered: converts warnings to ErrorException, logs deprecations, handles uncaught exceptions and fatal errors with logging and a generic 500 response (detailed traces in debug mode only).

Canonical: https://foundry.insightdevhub.ro/docs/error-handler/  
Namespace: `Foundry\ErrorHandler` · File: `ErrorHandler.php` · Version 1.1 · PHP 8.3+

Application safety net, explicitly activated:

- PHP warnings and notices are converted into ErrorException (no longer pass silently)
- deprecations are only forwarded to the logger (a PHP update won't break pages)
- uncaught exceptions are forwarded to the logger and return a 500 response: generic in production, detailed only in debug mode
- fatal errors (out of memory, execution time exceeded, syntax error in an include) are caught at shutdown and handled identically, with reserved memory freed beforehand.

## Rules

- including this file changes nothing; register() enables handling, unregister() restores previous handlers
- error_reporting() and the @ operator are respected
- default response is plain text or JSON (based on Accept header), without HTML or data leaks in production; custom response rendering can be provided via $renderer
- in CLI, details are sent to STDERR with exit code 255
- in production, display_errors must be Off (server configuration is not modified).

## Usage

```php
require_once 'ErrorHandler.php';
use Foundry\ErrorHandler;

ErrorHandler::register(
    debug: $config['debug'],
    logger: fn (Throwable $e) => $log->error('Uncaught exception', ['exception' => $e]),
    renderer: fn (Throwable $e, bool $debug) => print $view->render('errors/500', ['debug' => $debug]),
);

// inside a front controller with its own try/catch:
catch (Throwable $e) { ErrorHandler::handleException($e); }
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/ErrorHandler.php';

use Foundry\ErrorHandler;
```

## Methods

### register()

```php
public static function register(bool $debug = false, ?Closure $logger = null, ?Closure $renderer = null): void
```

Registers error and exception handlers.

Parameters:
- `$debug` (`bool`, default `false`): true = detailed output in response (development only).
- `$logger` (`(Closure(Throwable): void)|null`, default `null`): Receives uncaught exceptions, fatal errors, and deprecations.
- `$renderer` (`(Closure(Throwable, bool): void)|null`, default `null`): Renders the error response (500 status code is already set).

Throws:
- `LogicException`: If the handler is already registered.

### unregister()

```php
public static function unregister(): void
```

Unregisters the handlers and restores previous error and exception handlers.

Throws:
- `LogicException`: If the handler is not registered.

### isRegistered()

```php
public static function isRegistered(): bool
```

Returns `bool`.

### handleError()

```php
public static function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
```

Warning / notice -> ErrorException; deprecation -> logger.

Parameters:
- `$severity` (`int`, required)
- `$message` (`string`, required)
- `$file` (`string`, default `''`)
- `$line` (`int`, default `0`)

Returns `bool`.

Throws:
- `ErrorException`

### handleException()

```php
public static function handleException(Throwable $exception): void
```

Uncaught exception: logs the error, then renders the error response. Can also be called from application try/catch.

Parameters:
- `$exception` (`Throwable`, required)

### handleShutdown()

```php
public static function handleShutdown(): void
```

Executed on shutdown: fatal error (if any) is handled like an uncaught exception.

