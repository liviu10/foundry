# SQLite

> PDO; missing files are never silently created, foreign_keys PRAGMA enabled.

Canonical: https://foundry.insightdevhub.ro/docs/sqlite/  
Namespace: `Foundry\SQLite` · File: `SQLite.php` · Version 1.2 · PHP 8.3+ · Extensions: pdo_sqlite

Wrapper over PDO (pdo_sqlite), sharing the same public interface as MySQL and Oracle.

## Differences from direct PDO usage

- The database file is NOT created silently if missing (an incorrect path would create a new, empty database); creation must be explicitly requested with create: true
- foreign_keys = ON (SQLite ignores them by default), busy_timeout configured for concurrent access
- insert / update / delete from arrays with validated and quoted table and column names; unconditional update and delete calls are rejected
- fetchOne() rejects queries returning multiple rows (usually indicating a missing WHERE clause)
- fetchPairs() rejects duplicate keys instead of overwriting them
- Connection opens on the first query (or explicitly via connect()).

Parameters are either positional (?) or named (:id), never mixed. Supported types: int, float, string, bool, null, and DateTimeInterface (formatted as 'Y-m-d H:i:s').

Error messages contain the SQL statement (excluding parameter values) and the SQLite error.

## Usage

```php
require_once 'SQLite.php';
use Foundry\SQLite;

$db = new SQLite(__DIR__.'/data/app.db');
$users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
$user = $db->fetchOneOrFail('SELECT * FROM users WHERE id = :id', ['id' => $id]);
$id = $db->insertGetId('users', ['name' => 'Ion', 'email' => 'ion@exemplu.ro']);
$db->update('users', ['active' => 0], ['id' => $id]);
$db->transaction(function (SQLite $db) use ($order): void { ... });
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/SQLite.php';

use Foundry\SQLite;
```

## Methods

### __construct()

```php
public function __construct(
    private readonly string $path,
    private readonly bool $create = false,
    private readonly int $busyTimeout = 5000,
    private readonly bool $wal = false,
)
```

Parameters:
- `$path` (`string`, required): File path or ':memory:'.
- `$create` (`bool`, default `false`): Creates the file if missing (defaults to false: missing file throws an error).
- `$busyTimeout` (`int`, default `5000`): Milliseconds to wait when a table is locked by another process.
- `$wal` (`bool`, default `false`): Enables journal_mode = WAL (concurrent reads alongside a write; not suitable for network shares).

Throws:
- `InvalidArgumentException`: If parameters are invalid.

### connect()

```php
public function connect(): void
```

Opens the connection immediately (otherwise opened on first query). Useful for health checks on startup.

Throws:
- `RuntimeException`: If the database cannot be opened.

### close()

```php
public function close(): void
```

Closes the connection; the next query will reopen it.

### fetchAll()

```php
public function fetchAll(string $sql, array $params = []): array
```

Fetches all rows as associative arrays.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `list<array<array-key, mixed>>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.

### fetchOne()

```php
public function fetchOne(string $sql, array $params = []): ?array
```

Fetches a single row or null if none found. Multiple rows trigger an error (missing WHERE or LIMIT 1).

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `array<array-key, mixed>|null`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.
- `UnexpectedValueException`: If query returns more than one row.

### fetchOneOrFail()

```php
public function fetchOneOrFail(string $sql, array $params = []): array
```

Fetches a single row; throws an error if missing.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `array<array-key, mixed>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.
- `OutOfBoundsException`: If no rows are returned.
- `UnexpectedValueException`: If query returns more than one row.

### fetchValue()

```php
public function fetchValue(string $sql, array $params = []): mixed
```

Fetches the first column of the single row (e.g. SELECT COUNT(*)). Returns null if value is NULL.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `mixed`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.
- `OutOfBoundsException`: If no rows are returned.
- `UnexpectedValueException`: If query returns more than one row.

### fetchColumn()

```php
public function fetchColumn(string $sql, array $params = []): array
```

Fetches the first column from all rows.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `list<mixed>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.

### fetchPairs()

```php
public function fetchPairs(string $sql, array $params = []): array
```

Fetches the first column as key and second column as value: SELECT id, name -> [1 => 'Ion', 2 => 'Ana'].

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `array<array-key, mixed>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution fails.
- `UnexpectedValueException`: If the query does not return exactly 2 columns, key is not int/string, or keys are duplicated.

### execute()

```php
public function execute(string $sql, array $params = []): int
```

Executes a statement (INSERT, UPDATE, DELETE, DDL) and returns the number of affected rows.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `int`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If statement execution fails.

### insert()

```php
public function insert(string $table, array $data): void
```

INSERT from an array: ['column' => value].

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required)

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If insertion fails.

### insertGetId()

```php
public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
```

INSERT from an array; returns the generated primary key (AUTOINCREMENT / ROWID). $idColumn is only used by Oracle (RETURNING ... INTO); here it is only validated.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required)
- `$idColumn` (`string`, default `'id'`)

Returns `string|int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If insertion fails.

### insertMany()

```php
public function insertMany(string $table, array $rows): int
```

Inserts multiple rows in batches of up to 1000 rows (constrained by database parameter limits), wrapped in a single transaction: all succeed or none are inserted. Returns the number of inserted rows. All rows must have the same columns (in any order). Joins existing transaction if called within one.

Parameters:
- `$table` (`string`, required)
- `$rows` (`list<array<string, mixed>>`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If insertion fails (no rows from the call are committed).

### cursor()

```php
public function cursor(string $sql, array $params = []): Generator
```

Streams rows one by one without buffering the entire result set in memory (for large exports or processing). The query executes immediately (errors surface upon invocation); rows are yielded as the loop iterates. Breaking the loop closes the cursor.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `Generator<int, array<array-key, mixed>>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution or fetching fails.

### update()

```php
public function update(string $table, array $data, array $where): int
```

UPDATE from an array. Conditions are required (refusing to update entire table without conditions). Returns the number of affected rows.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required): ['column' => new value]
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid or if $where is empty.
- `RuntimeException`: If update fails.

### delete()

```php
public function delete(string $table, array $where): int
```

DELETE with mandatory conditions (refusing to delete entire table without conditions). Returns the number of deleted rows.

Parameters:
- `$table` (`string`, required)
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name or conditions are invalid or if $where is empty.
- `RuntimeException`: If deletion fails.

### whereIn()

```php
public function whereIn(string $column, array $values): array
```

Constructs an IN condition with placeholders: whereIn('id', [1, 2]) -> ['"id" IN (?, ?)', [1, 2]]. An empty array -> ['1 = 0', []] (matches nothing without causing syntax errors).

Parameters:
- `$column` (`string`, required)
- `$values` (`list<mixed>`, required)

Returns `array{0: string, 1: list<int|float|string|null>}`.

Throws:
- `InvalidArgumentException`: If column name or values are invalid.

### transaction()

```php
public function transaction(Closure $callback): mixed
```

Executes the callback within a transaction: commits on success, rolls back on any exception (re-thrown). When invoked inside an existing transaction, joins it (outer transaction controls commit).

Parameters:
- `$callback` (`Closure`, required)

Returns `T`.

Throws:
- `RuntimeException`: If the transaction cannot be started or committed.
- `Throwable`: The exception thrown by the callback, re-thrown after rollback.

### inTransaction()

```php
public function inTransaction(): bool
```

Checks whether a transaction is currently active.

Returns `bool`.

### paginate()

```php
public function paginate(string $sql, array $params, int $page, int $perPage): array
```

Fetches a paginated result set with metadata: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6]. $sql is the full query, including ORDER BY (without ordering, pages are unstable). A page beyond the last returns empty items.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, required)
- `$page` (`int`, required)
- `$perPage` (`int`, required)

Returns `array{items: list<array<array-key, mixed>>, total: int, page: int, per_page: int, pages: int}`.

Throws:
- `InvalidArgumentException`: If $page or $perPage are less than 1.
- `RuntimeException`: If query execution fails.

### quoteIdentifier()

```php
public function quoteIdentifier(string $name): string
```

Validates and quotes a table or column name: users -> "users", main.users -> "main"."users".

Parameters:
- `$name` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the name is not a valid identifier.

### onQuery()

```php
public function onQuery(?Closure $listener): void
```

Callback invoked after each successful query: fn (string $sql, array $params, float $ms). Parameters may contain sensitive data; the callback determines what to log. Passing null disables it.

Parameters:
- `$listener` (`(Closure(string, array<array-key, mixed>, float): void)|null`, required)

