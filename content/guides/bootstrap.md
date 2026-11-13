---
title: Application bootstrap
nav: Bootstrap
description: The few lines to run once in your entry point - environment, logging, error handling, security headers and sessions.
---

Including a Foundry file never changes global state. Classes that do change it (`ErrorHandler`, `Session`, `SecurityHeaders`) only act when you call them explicitly, once, at the start of each request.

## A typical entry point

```php
<?php

declare(strict_types=1);

require __DIR__.'/../bootstrap/autoload.php';    // require_once lines or the SPL autoloader

use Foundry\Env;
use Foundry\ErrorHandler;
use Foundry\Log;
use Foundry\SecurityHeaders;
use Foundry\Session;

Env::load(__DIR__.'/../.env');
Env::required('DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD');

$log = new Log(__DIR__.'/../storage/logs/app.log', minLevel: 'info', daily: true, maxFiles: 30);

ErrorHandler::register(
    debug: Env::bool('APP_DEBUG', false),             // true only in local development
    logger: fn (Throwable $e) => $log->error('Uncaught exception', ['exception' => $e]),
);
SecurityHeaders::send();
Session::start(['name' => 'APPSESSID', 'idleTimeout' => 1800]);
```

## What each step does

1. `Env::load()` reads the `.env` file (phpdotenv syntax) and `Env::required()` fails immediately if a variable is missing, instead of failing later on first use.
2. `Log` writes PSR-3 levels with a JSON context, masks sensitive keys and rotates files daily, keeping the last 30.
3. `ErrorHandler::register()` converts PHP warnings into `ErrorException`, logs deprecations, and turns uncaught exceptions and fatal errors into a logged, generic HTTP 500 response. Stack traces are shown only when `debug` is true.
4. `SecurityHeaders::send()` sends a Content-Security-Policy with a per-request nonce, HSTS on HTTPS, and the other defensive headers.
5. `Session::start()` starts the session with hardened cookie options and clears it after 30 minutes of inactivity.

> **Tip:** Keep `debug: false` in production. Detailed traces can expose file paths and configuration values to visitors.

## Development helpers

`Debug` is disabled by default: a `dump()` left in the code throws an exception in production instead of leaking data. Enable it explicitly, only in development:

```php
use Foundry\Debug;

Debug::enable(Env::bool('APP_DEBUG', false));
```

## Ending a request

`Response::send()` sends the response but does not stop the script. Always follow it with `return` (or `exit`):

```php
use Foundry\Response;

Response::redirect('/clients')->send();

return;
```
