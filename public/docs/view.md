# View

> Isolated PHP template renderer confined to a base directory.

Canonical: https://foundry.insightdevhub.ro/docs/view/  
Namespace: `Foundry\View` · File: `View.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

Renders PHP templates from a fixed directory with isolated variables and output buffering. Natively, an include with an externally supplied name can read arbitrary files (../../config.php), and an exception midway through rendering leaves partial output on screen with open buffers.

## Rules

- template name is a relative path without extension ('policies/list' -> policies/list.php); '..', absolute paths, and symlinks traversing outside the directory are rejected
- the template only sees passed variables, plus $e (HTML escaping) and $view (for partials); variable names starting with "__" are reserved
- on exception within a template, partial output is discarded and the exception is rethrown
- escaping rejects invalid UTF-8 text (throws an exception rather than corrupting text).

## Usage

```php
require_once 'View.php';
use Foundry\View;

$view = new View(__DIR__.'/views', shared: ['application' => 'Policies']);
echo $view->render('policies/list', ['policies' => $policies]);

// views/policies/list.php
<h1><?= $e($application) ?></h1>
<?php foreach ($policies as $policy): ?>
    <?= $view->render('policies/row', ['policy' => $policy]) ?>
<?php endforeach ?>
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/View.php';

use Foundry\View;
```

## Methods

### __construct()

```php
public function __construct(string $directory, array $shared = [])
```

Parameters:
- `$directory` (`string`, required): Template directory.
- `$shared` (`array<string, mixed>`, default `[]`): Variables available across all templates.

Throws:
- `RuntimeException`: If the directory does not exist.
- `InvalidArgumentException`: If a variable name is invalid or reserved.

### render()

```php
public function render(string $name, array $data = []): string
```

Renders a template and returns the rendered HTML.

Parameters:
- `$name` (`string`, required)
- `$data` (`array<string, mixed>`, default `[]`): Template variables (override shared variables).

Returns `string`.

Throws:
- `InvalidArgumentException`: If template name or any variable name is invalid.
- `RuntimeException`: If template does not exist or resolves outside the directory.
- `Throwable`: Exception thrown by the template, after partial output has been discarded.

### exists()

```php
public function exists(string $name): bool
```

Checks whether a template exists (invalid name throws an exception, rather than returning false).

Parameters:
- `$name` (`string`, required)

Returns `bool`.

Throws:
- `InvalidArgumentException`: If the name is invalid.

### escape()

```php
public static function escape(string|int|float|Stringable|null $value): string
```

Escapes text for HTML (content and attributes); null becomes an empty string.

Parameters:
- `$value` (`Stringable|string|int|float|null`, required)

Returns `string`.

Throws:
- `UnexpectedValueException`: If text is not valid UTF-8.

