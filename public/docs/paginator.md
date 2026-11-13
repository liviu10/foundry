# Paginator

> URL-based pagination, sorting, and search limited strictly to whitelisted columns.

Canonical: https://foundry.insightdevhub.ro/docs/paginator/  
Namespace: `Foundry\Paginator` · File: `Paginator.php` · Version 1.0 · PHP 8.3+ · Extensions: mbstring

Pagination, sorting, and search parameters from the URL, validated and transformed into safe SQL fragments. Works with MySQL, Oracle, and SQLite (via the wrapper's paginate() method).

URL parameters represent user input: an invalid value (?page=abc, an unknown sort column, ?per_page=100000) falls back to the default value rather than producing an error. Misconfiguration (by the developer) throws an exception.

## Security

- Sorting is only permitted on keys defined in $sortable; the SQL receives the expression associated with the key (written by the developer), never raw URL input
- Search uses bound parameters, and %, _, and ! in the search term are escaped (LIKE ... ESCAPE '!', supported across MySQL, Oracle, and SQLite).

## Usage

```php
require_once 'Paginator.php';
use Foundry\Paginator;

$pager = Paginator::fromRequest($_GET,
    sortable: ['name' => ['u.last_name', 'u.first_name'], 'created' => 'u.created_at'],
    defaultSort: 'created', defaultOrder: 'desc');

[$search, $searchParams] = $pager->searchCondition(['u.last_name', 'u.email']);
$result = $db->paginate(
    "SELECT u.* FROM users u WHERE u.active = ? AND {$search} ORDER BY ".$pager->orderBy(),
    [1, ...$searchParams], $pager->page, $pager->perPage);

foreach ($pager->pageNumbers($result['total']) as $number) {
    echo $number === null ? '...' : '<a href="?'.http_build_query($pager->query($number)).'">'.$number.'</a>';
}
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Paginator.php';

use Foundry\Paginator;
```

## Constants

| Constant | Type | Value | Description |
| --- | --- | --- | --- |
| `MAX_PAGE` | `int` | `1_000_000` | Maximum page accepted from the URL (prevents huge offsets). |
| `MAX_SEARCH_LENGTH` | `int` | `100` | Maximum search term length in characters. |
| `MAX_SEARCH_WORDS` | `int` | `10` | Maximum number of search words (subsequent words are ignored). |

## Properties

- `$page` (`int`, readonly)
- `$perPage` (`int`, readonly)
- `$sort` (`string`, readonly)
- `$order` (`string`, readonly)
- `$search` (`string`, readonly)

## Methods

### fromRequest()

```php
public static function fromRequest(
    array $query,
    array $sortable,
    string $defaultSort,
    string $defaultOrder = 'asc',
    int $perPage = 20,
    int $maxPerPage = 100,
): self
```

Reads page, per_page, sort, order, and search from URL parameters (typically $_GET).

Parameters:
- `$query` (`array<array-key, mixed>`, required)
- `$sortable` (`array<string, string|list<string>>`, required): URL key -> SQL column(s) mapping (e.g., 'name' => ['u.last_name', 'u.first_name']).
- `$defaultSort` (`string`, required)
- `$defaultOrder` (`string`, default `'asc'`)
- `$perPage` (`int`, default `20`)
- `$maxPerPage` (`int`, default `100`)

Returns `Foundry\Paginator`.

Throws:
- `InvalidArgumentException`: If the configuration is invalid.

### offset()

```php
public function offset(): int
```

Offset of the first row on the current page (for LIMIT ... OFFSET, if not using paginate()).

Returns `int`.

### orderBy()

```php
public function orderBy(): string
```

The ORDER BY clause fragment (without the keyword): 'u.last_name DESC, u.first_name DESC'. For a stable order across pages, the last column should be unique (e.g., u.id).

Returns `string`.

### searchCondition()

```php
public function searchCondition(array $columns, bool $caseInsensitive = false, bool $named = false): array
```

Search condition: each word must match in at least one of the columns. With an empty search string: ['1 = 1', []]. Parameters are positional (?), or named (:paginator_search_0, ...) when $named = true, to match the query style.

Parameters:
- `$columns` (`list<string>`, required): SQL columns to search in (written by the developer).
- `$caseInsensitive` (`bool`, default `false`)
- `$named` (`bool`, default `false`)

Returns `array{0: string, 1: array<array-key, string>}`.

Throws:
- `InvalidArgumentException`: If the column list is empty.

### pages()

```php
public function pages(int $total): int
```

Total number of pages for a given record count (minimum 1).

Parameters:
- `$total` (`int`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If total is negative.

### pageNumbers()

```php
public function pageNumbers(int $total, int $window = 2): array
```

Page numbers for navigation, with null for skipped ranges: page 6 of 20, window 2 -> [1, null, 4, 5, 6, 7, 8, null, 20].

Parameters:
- `$total` (`int`, required)
- `$window` (`int`, default `2`)

Returns `list<int|null>`.

Throws:
- `InvalidArgumentException`: If total or window is negative.

### query()

```php
public function query(int $page): array
```

URL parameters for page $page: incoming parameters (including custom application filters) with normalized values. For links: '?'.http_build_query($pager->query(3)).

Parameters:
- `$page` (`int`, required)

Returns `array<array-key, mixed>`.

Throws:
- `InvalidArgumentException`: If $page is less than 1.

