# Breadcrumbs

> Hierarchical trail management with automated schema.org JSON-LD generation for search engines.

Canonical: https://foundry.insightdevhub.ro/docs/breadcrumbs/  
Namespace: `Foundry\Breadcrumbs` · File: `Breadcrumbs.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

Navigation breadcrumbs (Home > Policies > Policy 123) represented as data rather than HTML: markup and CSS classes remain in the application template.

## Rules

- the object is immutable: add() returns a clone (no global state, no singleton)
- URLs are local paths ('/policies') or http(s); 'javascript:', '//evil.example' and control characters are rejected
- the last item is the current page (active = true); should not be rendered as a link in templates
- labels are not escaped: escaping should be performed at rendering time.

## Usage

```php
require_once 'Breadcrumbs.php';
use Foundry\Breadcrumbs;

$breadcrumbs = (new Breadcrumbs)
    ->add('Home', '/')
    ->add('Policies', '/policies')
    ->add('Policy 123');

foreach ($breadcrumbs->items() as $item) { ... $item['label'], $item['url'], $item['active'] ... }

<script type="application/ld+json"><?= $breadcrumbs->schemaJson('https://app.example.com') ?></script>
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Breadcrumbs.php';

use Foundry\Breadcrumbs;
```

## Methods

### add()

```php
public function add(string $label, ?string $url = null): self
```

Adds an item (at the end). Omit URL for items that should not be links.

Parameters:
- `$label` (`string`, required)
- `$url` (`?string`, default `null`)

Returns `Foundry\Breadcrumbs`.

Throws:
- `InvalidArgumentException`: If the label is empty or the URL is invalid.

### items()

```php
public function items(): array
```

Returns the breadcrumb items in order; the last item is the current page.

Returns `list<array{label: string, url: ?string, active: bool, position: int}>`.

### count()

```php
public function count(): int
```

Returns `int`.

### isEmpty()

```php
public function isEmpty(): bool
```

Returns `bool`.

### schema()

```php
public function schema(string $baseUrl): array
```

Generates schema.org BreadcrumbList structured data (JSON-LD, for search engines) with absolute URLs.

Parameters:
- `$baseUrl` (`string`, required): Application base URL ('https://app.example.com'), used for local paths.

Returns `array{'@context': string, '@type': string, itemListElement: list<array<string, int|string>>}`.

Throws:
- `InvalidArgumentException`: If the base URL is not an absolute http(s) URL.

### schemaJson()

```php
public function schemaJson(string $baseUrl): string
```

Returns schema() JSON-encoded, safe for <script type="application/ld+json">: '<', '>', '&' and quotes are encoded so that a label containing '</script>' cannot close the HTML tag.

Parameters:
- `$baseUrl` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the base URL is invalid or the data cannot be encoded.

