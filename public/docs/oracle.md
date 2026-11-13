# Oracle

> oci8 with ORA errors as exceptions; targets Oracle 11.2.0.4+ (ROWNUM pagination, sequences).

Canonical: https://foundry.insightdevhub.ro/docs/oracle/  
Namespace: `Foundry\Oracle` · File: `Oracle.php` · Version 1.1 · PHP 8.3+ · Extensions: oci8

Wrapper over the oci8 extension, sharing a consistent public interface with MySQL and SQLite. Target: Oracle 11.2 (no OFFSET/FETCH, no IDENTITY columns), client oci8 19.x.

## oci8 shortcomings addressed

- All oci_* functions return false and emit warnings; errors must be read separately with oci_error(); here every error becomes an exception with the ORA-xxxxx error code
- oci_connect() reuses the same connection (and transaction) for identical credentials; here each instance maintains its own dedicated connection (oci_new_connect)
- oci_execute() commits after each statement by default; here autocommit applies outside transaction() and commits explicitly at the end of transaction(), matching PDO
- Date and decimal formatting depend on server NLS settings (e.g. on a Romanian server 1.5 formats as '1,5'); here the session enforces 'YYYY-MM-DD HH24:MI:SS' and decimal dot
- Positional parameters (?) are unsupported in oci8; they are converted to :p1, :p2...
- An IN list is capped at 1000 items by Oracle; whereIn() automatically splits large lists.

Returned values: NUMBER columns without decimals become int (if within range), others remain string (preserving precision; '.5' becomes '0.5'); DATE is formatted as 'Y-m-d H:i:s'; CLOB/BLOB are strings. Column names default to lowercase (like MySQL); lowercaseKeys: false leaves them as returned by Oracle (usually uppercase).

Identifiers are validated, uppercased, and enclosed in double quotes ("USERS"), matching unquoted table declarations. Maximum length is 30 characters (11g limit).

Strings longer than 4000 bytes are automatically bound as temporary CLOBs.

Error messages include the SQL statement (excluding parameter values) and the ORA error.

## Usage

```php
require_once 'Oracle.php';
use Foundry\Oracle;

$db = new Oracle(connection: '//db-server:1521/ORCL', username: Env::string('ORACLE_USER'),
    password: Env::string('ORACLE_PASSWORD'));
$users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
$id = $db->insertGetId('users', ['id' => $db->nextVal('users_seq'), 'name' => 'Ion']);
$page = $db->paginate('SELECT * FROM users ORDER BY name', [], 2, 20);   // with ROWNUM
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Oracle.php';

use Foundry\Oracle;
```

## Methods

### __construct()

```php
public function __construct(
    private readonly string $connection,
    private readonly string $username,
    #[SensitiveParameter]
    private readonly string $password,
    private readonly string $charset = 'AL32UTF8',
    private readonly bool $lowercaseKeys = true,
)
```

Parameters:
- `$connection` (`string`, required): '//host:1521/SERVICE' (Easy Connect) or a tnsnames.ora alias.
- `$username` (`string`, required)
- `$password` (`string`, required)
- `$charset` (`string`, default `'AL32UTF8'`): Client character set (AL32UTF8 = UTF-8).
- `$lowercaseKeys` (`bool`, default `true`): Whether column names are lowercased in returned rows.

Throws:
- `InvalidArgumentException`: If parameters are invalid.

### connect()

```php
public function connect(): void
```

Establishes the database connection immediately (otherwise opened lazily on first query). Useful for health checks at boot.

Throws:
- `RuntimeException`: If connecting fails.

### close()

```php
public function close(): void
```

Closes the connection (uncommitted transaction is rolled back); the next query reopens it.

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

Fetches a single row or null if not found. Returning multiple rows throws an exception (missing WHERE).

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

Fetches the first column of a single row (e.g., SELECT COUNT(*) FROM ...). Returns null if the value is NULL.

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

Executes a statement (INSERT, UPDATE, DELETE, DDL, PL/SQL block) and returns the number of affected rows.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `int`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If execution fails.

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
- `RuntimeException`: If insertion fails.

### insertGetId()

```php
public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
```

Inserts a row from an array and returns the value of $idColumn, retrieved via RETURNING ... INTO (Oracle does not support lastInsertId). The ID can come from $data (e.g. nextVal()) or a trigger.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required)
- `$idColumn` (`string`, default `'id'`)

Returns `string|int`.

Throws:
- `InvalidArgumentException`: If table name, columns, or values are invalid.
- `RuntimeException`: If insertion fails or the returned ID is NULL.

### nextVal()

```php
public function nextVal(string $sequence): int
```

Retrieves the next value from a sequence (Oracle 11g does not have IDENTITY columns).

Parameters:
- `$sequence` (`string`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If sequence name is invalid.
- `RuntimeException`: If the sequence does not exist.

### insertMany()

```php
public function insertMany(string $table, array $rows): int
```

Multiple rows, using batched INSERT ALL statements of up to 500 rows (within database parameter limits), executed within a single transaction: either all succeed or none. Returns the number of inserted rows. All rows must share identical columns (in any order). If called within an existing transaction, it joins it.

Parameters:
- `$table` (`string`, required)
- `$rows` (`list<array<string, mixed>>`, required)

Returns `int`.

Throws:
- `InvalidArgumentException`: If table, columns, or values are invalid.
- `RuntimeException`: If insertion fails (none of the batch remains inserted).

### cursor()

```php
public function cursor(string $sql, array $params = []): Generator
```

Streams rows one by one without buffering the entire result set in memory (for large exports or processing). The query executes immediately (raising query errors upfront); rows are fetched as the generator advances. Exiting the loop (break) closes the cursor.

Parameters:
- `$sql` (`string`, required)
- `$params` (`array<array-key, mixed>`, default `[]`)

Returns `Generator<int, array<array-key, mixed>>`.

Throws:
- `InvalidArgumentException`: If parameters are invalid.
- `RuntimeException`: If query execution or row fetching fails.

### update()

```php
public function update(string $table, array $data, array $where): int
```

UPDATE from an associative array. Conditions are required (refuses table-wide UPDATE). Returns the number of rows matching the conditions.

Parameters:
- `$table` (`string`, required)
- `$data` (`array<string, mixed>`, required): ['column' => new_value]
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table, columns, or values are invalid, or if $where is empty.
- `RuntimeException`: If update execution fails.

### delete()

```php
public function delete(string $table, array $where): int
```

DELETE with mandatory conditions (refuses table-wide DELETE). Returns the number of deleted rows.

Parameters:
- `$table` (`string`, required)
- `$where` (`array<string, mixed>`, required): ['column' => value]; null -> IS NULL; list -> IN (...).

Returns `int`.

Throws:
- `InvalidArgumentException`: If table or conditions are invalid, or if $where is empty.
- `RuntimeException`: If deletion fails.

### whereIn()

```php
public function whereIn(string $column, array $values): array
```

Generates an IN condition with placeholders: whereIn('id', [1, 2]) -> ['"ID" IN (?, ?)', [1, 2]]. Handles lists exceeding 1000 items: ('"ID" IN (...) OR "ID" IN (...)'). Empty list -> ['1 = 0', []].

Parameters:
- `$column` (`string`, required)
- `$values` (`list<mixed>`, required)

Returns `array{0: string, 1: list<int|float|string|null>}`.

Throws:
- `InvalidArgumentException`: If column or values are invalid.

### transaction()

```php
public function transaction(Closure $callback): mixed
```

Executes the callback within a database transaction: commits on success, rolls back on any exception (re-thrown). If called inside an existing transaction, joins it (the outer transaction handles commit).

Parameters:
- `$callback` (`Closure`, required)

Returns `T`.

Throws:
- `RuntimeException`: If the transaction cannot be committed.
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

Paginated result page with total count: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6]. Pagination relies on ROWNUM (Oracle 11g lacks OFFSET/FETCH). $sql must contain an ORDER BY clause. A page beyond the last returns an empty items array.

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

Validates and quotes an identifier (table, column, sequence) in uppercase with double quotes: users -> "USERS", app.users -> "APP"."USERS". Maximum 30 characters per segment.

Parameters:
- `$name` (`string`, required)

Returns `string`.

Throws:
- `InvalidArgumentException`: If the name is not a valid identifier.

### onQuery()

```php
public function onQuery(?Closure $listener): void
```

Listener callback invoked after each successful query: fn (string $sql, array $params, float $ms). Parameters may include sensitive data; the callback determines what to log. null disables the listener.

Parameters:
- `$listener` (`(Closure(string, array<array-key, mixed>, float): void)|null`, required)

