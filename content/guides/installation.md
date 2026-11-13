---
title: Installation
description: Download the Foundry archive, copy the classes you need into your project and load them with require_once, an SPL autoloader or Composer PSR-4.
---

Foundry is not installed with a package manager. Every class is a single, self-contained PHP file: you copy the files you need into your project, and they become part of your code base. There is no vendor tree to update and no third-party code to audit.

## Download {#download}

The archive contains the {{class_count}} classes as PHP 8.3 source files, in a single `foundry/` folder.

<p><a class="btn btn-primary" href="{{download_url}}" download>Download {{download_name}}</a> <span class="muted">{{download_size}}</span></p>

SHA-256 checksum: `{{download_sha256}}`

Verify the archive after downloading it:

```bash
sha256sum {{download_name}}                         # Linux
shasum -a 256 {{download_name}}                     # macOS
```

```powershell
Get-FileHash .\{{download_name}} -Algorithm SHA256   # Windows
```

## Copy the files

Copy only the classes you need into a dedicated folder of your project, for example `lib/Utilities/` (or `src/Utilities/` if your project already uses `src/`). The classes do not depend on each other, so any subset works.

```text
your-project/
├── lib/
│   └── Utilities/
│       ├── Json.php
│       ├── Validator.php
│       └── Csv.php
└── public/
    └── index.php
```

## Loading the classes

All classes live in the `Foundry` namespace. Pick one of the three approaches below.

### With require_once

The simplest option: one line per class you use.

```php
require_once __DIR__.'/lib/Utilities/Json.php';

use Foundry\Json;

$data = Json::decode($body);
```

### With an SPL autoloader

Register the autoloader once, in your application bootstrap file. Classes are then loaded the first time they are used.

```php
spl_autoload_register(static function (string $class): void {
    $prefix = 'Foundry\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {      // also works on PHP 7.4
        require __DIR__.'/lib/Utilities/'.substr($class, strlen($prefix)).'.php';
    }
});
```

### With Composer

If your project already uses Composer, map the namespace in your own `composer.json`, then run `composer dump-autoload`:

```json
"autoload": {
    "psr-4": { "Foundry\\": "lib/Utilities/" },
    "files": ["lib/Utilities/Str.php", "lib/Utilities/Debug.php"]
}
```

> **Note:** The `files` entries are only needed if you use the global helper functions `e()` (defined in `Str.php`) or `dump()` / `dd()` (defined in `Debug.php`). An autoloader loads a file only when its class is first referenced, so functions called before that would not exist yet.

## PHP extensions

Some classes need PHP extensions. Check what your server has with `php -m`. The table below is generated from the source code of each class.

{{extensions_table}}

Classes not listed above run on a default PHP installation. Each class page also lists its requirements at the top.

## Updating

When a class changes, replace its file with the newer version. Every file has a `@version` tag in its header, so version differences are visible at a glance, and every class page in this documentation shows the current version.

> **Warning:** Do not edit copied files directly. A fix made in one project is lost the next time the file is replaced. Report the problem instead, so the fix lands in the library for every project.
