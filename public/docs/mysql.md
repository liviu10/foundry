# MySQL

> Secure PDO wrapper, verified SQL identifiers, enforced credentials.

Canonical: https://foundry.insightdevhub.ro/docs/mysql/  
Namespace: `Foundry\MySQL` · File: `MySQL.php` · Version 1.4 · PHP 8.3+ · Extensions: pdo_mysql

Wrapper over PDO (pdo_mysql), sharing a consistent public interface with SQLite and Oracle.

## Key differences compared to direct PDO usage

- Secure defaults: ERRMODE_EXCEPTION, native prepared statements (no emulation), utf8mb4, multiple queries in a single call are disabled ('...; DROP TABLE ...' is rejected)
- Required credentials without defaults; password marked with #[SensitiveParameter] (excluded from stack traces)
- Array-driven insert / update / delete with validated table and column names escaped in backticks; unqualified update and delete operations without conditions are rejected
- fetchOne() fails if multiple rows are returned (usually indicating a missing WHERE clause)
- fetchPairs() rejects duplicate keys instead of overwriting them
- Connection is opened lazily upon the first query (or explicitly with connect()).

Parameters are either positional (?) or named (:id), never mixed. Accepted values: int, float, string, bool, null, and DateTimeInterface (formatted as 'Y-m-d H:i:s'). INT and FLOAT are retrieved as int/float; DECIMAL remains string (preserving precision).

update() returns the number of actually changed rows (rows matching existing values are not counted).

Error messages contain the SQL statement (excluding parameter values) and MySQL error; values in "Duplicate entry '...'" and every "... value: '...'" (incorrect, truncated) are masked.

## Usage

```php
require_once 'MySQL.php';
use Foundry\MySQL;

$db = new MySQL(host: Env::string('DB_HOST'), database: Env::string('DB_DATABASE'),
    username: Env::string('DB_USERNAME'), password: Env::string('DB_PASSWORD'));
$users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
$user = $db->fetchOneOrFail('SELECT * FROM users WHERE id = :id', ['id' => $id]);
$id = $db->insertGetId('users', ['name' => 'Ion', 'email' => 'ion@example.com']);
$db->update('users', ['active' => 0], ['id' => $id]);
$db->transaction(function (MySQL $db) use ($order): void { ... });
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/MySQL.php';

use Foundry\MySQL;
```

## Methods

### __construct()

```php
public function __construct(
    private readonly string $host,
    private readonly string $database,
    private readonly string $username,
    #[SensitiveParameter]
    private readonly string $password,
    private readonly int $port = 3306,
    private readonly string $charset = 'utf8mb4',
    private readonly int $timeout = 5,
)
```

Parameters:
- `$host` (`string`, required): Server hostname or IP address.
- `$database` (`string`, required): Database name.
- `$username` (`string`, required)
- `$password` (`string`, required)
- `$port` (`int`, default `3306`)
- `$charset` (`string`, default `'utf8mb4'`)
- `$timeout` (`int`, default `5`): Connection timeout in seconds.

Throws:
- `InvalidArgumentException`: If parameters are invalid.

### connect()

```php
public function connect(): void
```

Establishes the database connection immediately (otherwise opened lazily on first query). Useful for health checks at boot.

Throws:
- `RuntimeException`: If connecting to the database fails.

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
- `RuntimeException`: If the query fails.

### fetchOne()

```php
public function fetchOne(string $sql, array $params = []): ?array
```

Fetches a single row or null if not found. Returning multiple rows throws an exception (missing WHERE or LIMIT 1).

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `array<array-key, mixed>|null`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If the query fails.
- `UnexpectedValueException`: If the query returns more than one row.

### fetchOneOrFail()

```php
public function fetchOneOrFail(string $sql, array $params = []): array
```

Fetches a single row; throws an exception if not found.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `array<array-key, mixed>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If the query fails.
- `OutOfBoundsException`: If no rows are returned.
- `UnexpectedValueException`: If the query returns more than one row.

### fetchValue()

```php
public function fetchValue(string $sql, array $params = []): mixed
```

Fetches the first column of a single row (e.g., SELECT COUNT(*)). Returns null if the value is NULL.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `mixed`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If the query fails.
- `OutOfBoundsException`: If no rows are returned.
- `UnexpectedValueException`: If the query returns more than one row.

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
- `RuntimeException`: If the query fails.

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
- `RuntimeException`: If the query fails.
- `UnexpectedValueException`: If the row does not have exactly 2 columns, key is not int/string, or keys are duplicate.

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
- `RuntimeException`: If the execution fails.

### insert()

```php
public function insert(string $table, array $data): void
```

Inserts a row from an associative array: ['column' => value].

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required)

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If the insert fails.

### insertGetId()

```php
public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
```

Inserts a row from an array and returns the generated primary key (AUTO_INCREMENT). $idColumn is used only by Oracle (RETURNING ... INTO); here it is only validated.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required)
- `$idColumn` (`string`, default `'id'`)

Returns `string|int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If the insert fails.

### insertMany()

```php
public function insertMany(string $table, array $rows): int
```

Inserts multiple rows in batches of up to 1000 rows (within database parameter limits) inside a single transaction: all succeed or none. Returns the number of inserted rows. All rows must have the same columns (in any order). Joins an active transaction if one exists.

Parameters:
- `$table` (`string`, required)
- `$rows` (`list<array<string, mixed>>`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If insertion fails (no rows from this call remain committed).

### cursor()

```php
public function cursor(string $sql, array $params = []): Generator
```

Streams rows one by one without loading the entire result into memory (exports, large data processing). The query is executed immediately (errors surface upon call); rows are fetched as the loop iterates. Exiting the loop early (break) closes the cursor. The cursor uses a separate, unbuffered connection (rows are delivered from the server as they are read); the main connection remains free for write operations during the loop. Being a separate connection, the cursor does not see uncommitted changes from an open transaction on the main connection.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `Generator<int, array<array-key, mixed>>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If the query or stream fetching fails.

### update()

```php
public function update(string $table, array $data, array $where): int
```

Updates rows matching conditions from an associative array. Conditions are required (refuses unqualified updates). Returns the number of affected rows.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required): ['column' => new value]
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid, or if $where is empty.
- `RuntimeException`: If the update fails.

### delete()

```php
public function delete(string $table, array $where): int
```

Deletes rows matching required conditions (refuses unqualified deletes). Returns the number of deleted rows.

Parameters:
- `$table` (`string`, required)
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table name or conditions are invalid, or if $where is empty.
- `RuntimeException`: If the delete fails.

### whereIn()

```php
public function whereIn(string $column, array $values): array
```

Builds an IN condition with placeholders: whereIn('id', [1, 2]) -> ['`id` IN (?, ?)', [1, 2]]. An empty list produces ['1 = 0', []] (matches nothing, avoiding a SQL syntax error).

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

Executes the callback within a database transaction: commits on success, rolls back on any exception (re-thrown). Joins an existing outer transaction if one is active (the outermost transaction performs the commit). DDL statements (CREATE, ALTER, DROP...) commit implicitly in MySQL: work done before them cannot be rolled back.

Parameters:
- `$callback` (`Closure`, required)

Returns `T`.

Throws:
- `RuntimeException`: If the transaction cannot be started or committed.
- `Throwable`: Exception thrown by the callback, re-thrown after rollback.

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

Returns a page of results and total count: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6]. $sql must be the complete query including ORDER BY (without ordering, pagination is non-deterministic). Requesting a page beyond the last page returns an empty items list.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, required)
- `$page` (`int`, required)
- `$perPage` (`int`, required)

Returns `array{items: list<array<array-key, mixed>>, total: int, page: int, per_page: int, pages: int}`.

Throws:
- `InvalidArgumentException`: If $page or $perPage is less than 1.
- `RuntimeException`: If the query fails.

### quoteIdentifier()

```php
public function quoteIdentifier(string $name): string
```

Validates and quotes a table or column identifier: users -> `users`, app.users -> `app`.`users`.

Parameters:
- `$name` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the name is not a valid identifier.

### onQuery()

```php
public function onQuery(?Closure $listener): void
```

Callback invoked after each successful query: fn (string $sql, array $params, float $ms). Parameters may contain sensitive data; the callback determines what to log. null disables it.

Parameters:
- `$listener` (`(Closure(string, array<array-key, mixed>, float): void)|null`, required)

