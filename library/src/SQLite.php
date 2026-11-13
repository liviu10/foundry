<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * SQLite Class
 *
 * Wrapper over PDO (pdo_sqlite), sharing the same public interface as MySQL and Oracle.
 *
 * Differences from direct PDO usage:
 *   - The database file is NOT created silently if missing (an incorrect path would create
 *     a new, empty database); creation must be explicitly requested with create: true;
 *   - foreign_keys = ON (SQLite ignores them by default), busy_timeout configured for concurrent access;
 *   - insert / update / delete from arrays with validated and quoted table and column names;
 *     unconditional update and delete calls are rejected;
 *   - fetchOne() rejects queries returning multiple rows (usually indicating a missing WHERE clause);
 *   - fetchPairs() rejects duplicate keys instead of overwriting them;
 *   - Connection opens on the first query (or explicitly via connect()).
 *
 * Parameters are either positional (?) or named (:id), never mixed. Supported types:
 * int, float, string, bool, null, and DateTimeInterface (formatted as 'Y-m-d H:i:s').
 *
 * Error messages contain the SQL statement (excluding parameter values) and the SQLite error.
 *
 * Usage:
 *   require_once 'SQLite.php';
 *   use Foundry\SQLite;
 *
 *   $db = new SQLite(__DIR__.'/data/app.db');
 *   $users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
 *   $user = $db->fetchOneOrFail('SELECT * FROM users WHERE id = :id', ['id' => $id]);
 *   $id = $db->insertGetId('users', ['name' => 'Ion', 'email' => 'ion@exemplu.ro']);
 *   $db->update('users', ['active' => 0], ['id' => $id]);
 *   $db->transaction(function (SQLite $db) use ($order): void { ... });
 *
 * @category Utilities
 *
 * @version  1.2
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class SQLite
{
    /** @var string An identifier segment: letters, digits, '_', not starting with a digit. */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var int Rows per INSERT batch in insertMany() (also constrained by database parameter limits). */
    private const int INSERT_ROWS = 1000;

    /** @var int Maximum SQL length included in error messages. */
    private const int MAX_SQL_IN_MESSAGE = 500;

    private ?PDO $pdo = null;

    /** @var (Closure(string, array<array-key, mixed>, float): void)|null */
    private ?Closure $queryListener = null;

    /**
     * @param  string  $path  File path or ':memory:'.
     * @param  bool  $create  Creates the file if missing (defaults to false: missing file throws an error).
     * @param  int  $busyTimeout  Milliseconds to wait when a table is locked by another process.
     * @param  bool  $wal  Enables journal_mode = WAL (concurrent reads alongside a write; not suitable for network shares).
     *
     * @throws InvalidArgumentException If parameters are invalid.
     */
    public function __construct(
        private readonly string $path,
        private readonly bool $create = false,
        private readonly int $busyTimeout = 5000,
        private readonly bool $wal = false,
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('SQLite::__construct(): path must not be empty (use ":memory:" for an in-memory database).');
        }

        if ($busyTimeout < 0) {
            throw new InvalidArgumentException("SQLite::__construct(): busyTimeout must be >= 0, got {$busyTimeout}.");
        }
    }

    /**
     * Opens the connection immediately (otherwise opened on first query). Useful for health checks on startup.
     *
     * @throws RuntimeException If the database cannot be opened.
     */
    public function connect(): void
    {
        $this->pdo('connect');
    }

    /**
     * Closes the connection; the next query will reopen it.
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    /**
     * Fetches all rows as associative arrays.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->run('fetchAll', $sql, $params);

        return $this->rows('fetchAll', $sql, $statement);
    }

    /**
     * Fetches a single row or null if none found. Multiple rows trigger an error (missing WHERE or LIMIT 1).
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     * @throws UnexpectedValueException If query returns more than one row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->rows('fetchOne', $sql, $this->run('fetchOne', $sql, $params), 2);

        if (count($rows) > 1) {
            throw new UnexpectedValueException('SQLite::fetchOne(): query returned more than one row. SQL: '.$this->shorten($sql));
        }

        return $rows[0] ?? null;
    }

    /**
     * Fetches a single row; throws an error if missing.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If query returns more than one row.
     */
    public function fetchOneOrFail(string $sql, array $params = []): array
    {
        return $this->fetchOne($sql, $params)
            ?? throw new OutOfBoundsException('SQLite::fetchOneOrFail(): query returned no rows. SQL: '.$this->shorten($sql));
    }

    /**
     * Fetches the first column of the single row (e.g. SELECT COUNT(*)). Returns null if value is NULL.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If query returns more than one row.
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->rows('fetchValue', $sql, $this->run('fetchValue', $sql, $params), 2);

        if ($rows === []) {
            throw new OutOfBoundsException('SQLite::fetchValue(): query returned no rows. SQL: '.$this->shorten($sql));
        }

        if (count($rows) > 1) {
            throw new UnexpectedValueException('SQLite::fetchValue(): query returned more than one row. SQL: '.$this->shorten($sql));
        }

        return reset($rows[0]);
    }

    /**
     * Fetches the first column from all rows.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return array_map(
            static fn (array $row): mixed => reset($row),
            $this->rows('fetchColumn', $sql, $this->run('fetchColumn', $sql, $params)),
        );
    }

    /**
     * Fetches the first column as key and second column as value: SELECT id, name -> [1 => 'Ion', 2 => 'Ana'].
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     * @throws UnexpectedValueException If the query does not return exactly 2 columns, key is not int/string, or keys are duplicated.
     */
    public function fetchPairs(string $sql, array $params = []): array
    {
        $pairs = [];
        foreach ($this->rows('fetchPairs', $sql, $this->run('fetchPairs', $sql, $params)) as $position => $row) {
            if (count($row) !== 2) {
                throw new UnexpectedValueException('SQLite::fetchPairs(): query must select exactly 2 columns, got '.count($row).'.');
            }

            [$key, $value] = array_values($row);
            if (! is_int($key) && ! is_string($key)) {
                throw new UnexpectedValueException("SQLite::fetchPairs(): the key in row #{$position} must be int or string, got ".get_debug_type($key).'.');
            }
            if (array_key_exists($key, $pairs)) {
                throw new UnexpectedValueException("SQLite::fetchPairs(): duplicate key in row #{$position}.");
            }
            $pairs[$key] = $value;
        }

        return $pairs;
    }

    /**
     * Executes a statement (INSERT, UPDATE, DELETE, DDL) and returns the number of affected rows.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If statement execution fails.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run('execute', $sql, $params)->rowCount();
    }

    /**
     * INSERT from an array: ['column' => value].
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails.
     */
    public function insert(string $table, array $data): void
    {
        [$sql, $params] = $this->insertSql('insert', $table, $data);
        $this->run('insert', $sql, $params);
    }

    /**
     * INSERT from an array; returns the generated primary key (AUTOINCREMENT / ROWID).
     * $idColumn is only used by Oracle (RETURNING ... INTO); here it is only validated.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails.
     */
    public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
    {
        $this->quote('insertGetId', $idColumn);
        [$sql, $params] = $this->insertSql('insertGetId', $table, $data);
        $this->run('insertGetId', $sql, $params);

        $id = $this->pdo('insertGetId')->lastInsertId();
        if ($id === false) {
            throw new RuntimeException('SQLite::insertGetId(): the driver did not return the inserted id.');
        }

        return $this->normalizeId($id);
    }

    /**
     * Inserts multiple rows in batches of up to 1000 rows (constrained by database parameter limits),
     * wrapped in a single transaction: all succeed or none are inserted. Returns the number of inserted rows.
     * All rows must have the same columns (in any order). Joins existing transaction if called within one.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails (no rows from the call are committed).
     */
    public function insertMany(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        [$columns, $values] = $this->rowValues('insertMany', $rows);
        $sql = 'INSERT INTO '.$this->quote('insertMany', $table)
            .' ('.implode(', ', array_map(fn (string $column): string => $this->quote('insertMany', $column), $columns)).') VALUES ';
        $tuple = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $perStatement = max(1, min(self::INSERT_ROWS, intdiv($this->maxParameters(), count($columns))));

        $this->transaction(function () use ($values, $perStatement, $sql, $tuple): void {
            foreach (array_chunk($values, $perStatement) as $chunk) {
                $this->run('insertMany', $sql.implode(', ', array_fill(0, count($chunk), $tuple)), array_merge(...$chunk));
            }
        });

        return count($rows);
    }

    /**
     * Streams rows one by one without buffering the entire result set in memory (for large exports or processing).
     * The query executes immediately (errors surface upon invocation); rows are yielded as the loop iterates.
     * Breaking the loop closes the cursor.
     *
     * @param  array<array-key, mixed>  $params
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution or fetching fails.
     */
    public function cursor(string $sql, array $params = []): Generator
    {
        return $this->stream('cursor', $sql, $this->run('cursor', $sql, $params));
    }

    /**
     * UPDATE from an array. Conditions are required (refusing to update entire table without conditions).
     * Returns the number of affected rows.
     *
     * @param  array<string, mixed>  $data  ['column' => new value]
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid or if $where is empty.
     * @throws RuntimeException If update fails.
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('SQLite::update(): data must not be empty.');
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quote('update', (string) $column).' = ?';
            $params[] = self::scalar('update', $value, "column '{$column}'");
        }

        [$condition, $whereParams] = $this->whereSql('update', $where);
        $sql = 'UPDATE '.$this->quote('update', $table).' SET '.implode(', ', $set).' WHERE '.$condition;

        return $this->run('update', $sql, [...$params, ...$whereParams])->rowCount();
    }

    /**
     * DELETE with mandatory conditions (refusing to delete entire table without conditions). Returns the number of deleted rows.
     *
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     *
     * @throws InvalidArgumentException If table name or conditions are invalid or if $where is empty.
     * @throws RuntimeException If deletion fails.
     */
    public function delete(string $table, array $where): int
    {
        [$condition, $params] = $this->whereSql('delete', $where);

        return $this->run('delete', 'DELETE FROM '.$this->quote('delete', $table).' WHERE '.$condition, $params)->rowCount();
    }

    /**
     * Constructs an IN condition with placeholders: whereIn('id', [1, 2]) -> ['"id" IN (?, ?)', [1, 2]].
     * An empty array -> ['1 = 0', []] (matches nothing without causing syntax errors).
     *
     * @param  list<mixed>  $values
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If column name or values are invalid.
     */
    public function whereIn(string $column, array $values): array
    {
        if ($values === []) {
            return ['1 = 0', []];
        }

        $params = array_map(static fn (mixed $value): int|float|string|null => self::scalar('whereIn', $value, "column '{$column}'"), $values);

        return [$this->quote('whereIn', $column).' IN ('.implode(', ', array_fill(0, count($params), '?')).')', $params];
    }

    /**
     * Executes the callback within a transaction: commits on success, rolls back on any exception (re-thrown).
     * When invoked inside an existing transaction, joins it (outer transaction controls commit).
     *
     * @template T
     *
     * @param  Closure(self): T  $callback
     * @return T
     *
     * @throws RuntimeException If the transaction cannot be started or committed.
     * @throws Throwable The exception thrown by the callback, re-thrown after rollback.
     */
    public function transaction(Closure $callback): mixed
    {
        $pdo = $this->pdo('transaction');

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        try {
            $pdo->beginTransaction();
        } catch (PDOException $e) {
            throw new RuntimeException('SQLite::transaction(): cannot begin transaction: '.$e->getMessage(), 0, $e);
        }

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                $pdo->rollBack();
            } catch (PDOException) {
                // Tranzactia era deja inchisa sau conexiunea s-a pierdut: exceptia relevanta este cea originala.
            }

            throw $e;
        }
    }

    /**
     * Checks whether a transaction is currently active.
     */
    public function inTransaction(): bool
    {
        return $this->pdo instanceof PDO && $this->pdo->inTransaction();
    }

    /**
     * Fetches a paginated result set with metadata: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6].
     * $sql is the full query, including ORDER BY (without ordering, pages are unstable).
     * A page beyond the last returns empty items.
     *
     * @param  array<array-key, mixed>  $params
     * @return array{items: list<array<array-key, mixed>>, total: int, page: int, per_page: int, pages: int}
     *
     * @throws InvalidArgumentException If $page or $perPage are less than 1.
     * @throws RuntimeException If query execution fails.
     */
    public function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException("SQLite::paginate(): page and perPage must be >= 1, got {$page} and {$perPage}.");
        }

        $total = $this->fetchValue('SELECT COUNT(*) FROM ('.$sql.') paginate_count', $params);
        // pdo_sqlite pe PHP < 8.1 returneaza numerele ca string.
        if (is_string($total) && ctype_digit($total)) {
            $total = (int) $total;
        }

        if (! is_int($total)) {
            throw new UnexpectedValueException('SQLite::paginate(): COUNT(*) did not return an integer.');
        }

        $offset = ($page - 1) * $perPage;
        $items = $total > $offset ? $this->fetchAll($sql." LIMIT {$perPage} OFFSET {$offset}", $params) : [];

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Validates and quotes a table or column name: users -> "users", main.users -> "main"."users".
     *
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    public function quoteIdentifier(string $name): string
    {
        return $this->quote('quoteIdentifier', $name);
    }

    /**
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    private function quote(string $method, string $name): string
    {
        $segments = explode('.', $name);

        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1) {
                throw new InvalidArgumentException("SQLite::{$method}(): invalid identifier '{$name}' (allowed: letters, digits, '_', optionally 'schema.name').");
            }
        }

        return '"'.implode('"."', $segments).'"';
    }

    /**
     * Callback invoked after each successful query: fn (string $sql, array $params, float $ms).
     * Parameters may contain sensitive data; the callback determines what to log. Passing null disables it.
     *
     * @param  (Closure(string, array<array-key, mixed>, float): void)|null  $listener
     */
    public function onQuery(?Closure $listener): void
    {
        $this->queryListener = $listener;
    }

    /**
     * The connection, opened on first use.
     *
     * @throws RuntimeException If the database cannot be opened.
     */
    private function pdo(string $method): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $memory = $this->path === ':memory:';
        if (! $memory && ! $this->create && ! is_file($this->path)) {
            throw new RuntimeException("SQLite::{$method}(): database file '{$this->path}' does not exist (pass create: true to create it).");
        }

        try {
            $pdo = new PDO('sqlite:'.$this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                // Fara CREATE: fisierul sters intre verificare si deschidere nu este recreat.
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | ($this->create ? PDO::SQLITE_OPEN_CREATE : 0),
            ]);

            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = '.$this->busyTimeout);

            if ($this->wal && ! $memory) {
                $statement = $pdo->query('PRAGMA journal_mode = WAL');
                $mode = $statement === false ? false : $statement->fetchColumn();
                if (! is_string($mode) || strtolower($mode) !== 'wal') {
                    throw new RuntimeException("SQLite::{$method}(): cannot enable WAL mode for '{$this->path}'.");
                }
            }
        } catch (PDOException $e) {
            throw new RuntimeException("SQLite::{$method}(): cannot open database '{$this->path}': ".$e->getMessage(), 0, $e);
        }

        return $this->pdo = $pdo;
    }

    /**
     * Prepares and executes the query with typed parameter binding.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     */
    private function run(string $method, string $sql, array $params): PDOStatement
    {
        $bindings = $this->bindings($method, $params);
        $pdo = $this->pdo($method);
        $start = hrtime(true);

        try {
            $statement = $pdo->prepare($sql);
            foreach ($bindings as [$key, $value, $type]) {
                $statement->bindValue($key, $value, $type);
            }
            $statement->execute();
        } catch (PDOException $e) {
            throw $this->queryFailed($method, $sql, $e);
        }

        if ($this->queryListener instanceof Closure) {
            ($this->queryListener)($sql, $params, (hrtime(true) - $start) / 1e6);
        }

        return $statement;
    }

    /**
     * Fetches result rows (at most $limit, if specified).
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws RuntimeException If fetching fails.
     */
    private function rows(string $method, string $sql, PDOStatement $statement, ?int $limit = null): array
    {
        $rows = [];

        try {
            while (($limit === null || count($rows) < $limit) && is_array($row = $statement->fetch(PDO::FETCH_ASSOC))) {
                $rows[] = $row;
            }
            $statement->closeCursor();
        } catch (PDOException $e) {
            throw $this->queryFailed($method, $sql, $e);
        }

        return $rows;
    }

    /**
     * Generator yielding rows of an executed query as iterated.
     *
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws RuntimeException If fetching fails.
     */
    private function stream(string $method, string $sql, PDOStatement $statement): Generator
    {
        $index = 0;

        try {
            while (true) {
                try {
                    $row = $statement->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    throw $this->queryFailed($method, $sql, $e);
                }

                if (! is_array($row)) {
                    break;
                }

                yield $index++ => $row;
            }
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Maximum parameter limit in a single query: 32766 starting with SQLite 3.32, 999 previously.
     */
    private function maxParameters(): int
    {
        $version = $this->pdo('insertMany')->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) && version_compare($version, '3.32.0', '>=') ? 32766 : 999;
    }

    /**
     * Extracts first row columns and values across all rows in identical column order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: non-empty-list<string>, 1: list<list<int|float|string|null>>}
     *
     * @throws InvalidArgumentException If a row has different columns or unsupported values.
     */
    private function rowValues(string $method, array $rows): array
    {
        $columns = array_map(strval(...), array_keys($rows[0] ?? []));
        if ($columns === []) {
            throw new InvalidArgumentException("SQLite::{$method}(): data must not be empty.");
        }

        $values = [];
        foreach ($rows as $index => $row) {
            if (count($row) !== count($columns)) {
                throw new InvalidArgumentException("SQLite::{$method}(): row #{$index} does not have the same columns as the first row.");
            }

            $list = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    throw new InvalidArgumentException("SQLite::{$method}(): row #{$index} does not have the same columns as the first row.");
                }
                $list[] = self::scalar($method, $row[$column], "row #{$index}, column '{$column}'");
            }
            $values[] = $list;
        }

        return [$columns, $values];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     */
    private function insertSql(string $method, string $table, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException("SQLite::{$method}(): data must not be empty.");
        }

        $columns = [];
        $params = [];
        foreach ($data as $column => $value) {
            $columns[] = $this->quote($method, (string) $column);
            $params[] = self::scalar($method, $value, "column '{$column}'");
        }

        $sql = 'INSERT INTO '.$this->quote($method, $table).' ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($params), '?')).')';

        return [$sql, $params];
    }

    /**
     * WHERE conditions from an array, joined by AND.
     *
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If $where is empty or contains invalid columns/values.
     */
    private function whereSql(string $method, array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException("SQLite::{$method}(): conditions must not be empty (refusing to affect the whole table).");
        }

        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $quoted = $this->quote($method, (string) $column);

            if ($value === null) {
                $conditions[] = $quoted.' IS NULL';
            } elseif (is_array($value)) {
                [$in, $inParams] = $value === [] ? ['1 = 0', []] : $this->whereIn((string) $column, array_values($value));
                $conditions[] = $in;
                array_push($params, ...$inParams);
            } else {
                $conditions[] = $quoted.' = ?';
                $params[] = self::scalar($method, $value, "column '{$column}'");
            }
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Parameters formatted for bindValue: [key, value, PDO param type].
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array{0: int|string, 1: int|float|string|bool|null, 2: int}>
     *
     * @throws InvalidArgumentException If parameters mix positional and named parameters or contain unsupported types.
     */
    private function bindings(string $method, array $params): array
    {
        $positional = array_is_list($params);
        $bindings = [];

        foreach ($params as $key => $value) {
            if (! $positional && ! is_string($key)) {
                throw new InvalidArgumentException("SQLite::{$method}(): parameters must be either all positional (?) or all named (:name).");
            }

            $name = $positional ? $key + 1 : ':'.ltrim((string) $key, ':');
            $label = $positional ? 'parameter #'.($key + 1) : "parameter '{$key}'";

            $bindings[] = match (true) {
                is_bool($value) => [$name, $value, PDO::PARAM_BOOL],
                is_int($value) => [$name, $value, PDO::PARAM_INT],
                $value === null => [$name, null, PDO::PARAM_NULL],
                default => [$name, self::scalar($method, $value, $label), PDO::PARAM_STR],
            };
        }

        return $bindings;
    }

    /**
     * Converts a value for database storage: bool -> 0/1, DateTimeInterface -> 'Y-m-d H:i:s', float -> string.
     *
     * @throws InvalidArgumentException If the type is unsupported (array, object, resource).
     */
    private static function scalar(string $method, mixed $value, string $name): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_string($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_float($value) => is_finite($value) ? $value : throw new InvalidArgumentException("SQLite::{$method}(): value for {$name} must be a finite number."),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => throw new InvalidArgumentException("SQLite::{$method}(): unsupported type ".get_debug_type($value)." for {$name}."),
        };
    }

    private function normalizeId(string $id): int|string
    {
        return preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 ? (int) $id : $id;
    }

    private function queryFailed(string $method, string $sql, PDOException $e): RuntimeException
    {
        $state = is_array($e->errorInfo) && isset($e->errorInfo[0]) && is_string($e->errorInfo[0]) ? $e->errorInfo[0] : (string) $e->getCode();
        $detail = is_array($e->errorInfo) && isset($e->errorInfo[2]) && is_string($e->errorInfo[2]) ? $e->errorInfo[2] : $e->getMessage();

        return new RuntimeException("SQLite::{$method}(): query failed [SQLSTATE {$state}]: {$detail} SQL: ".$this->shorten($sql), 0, $e);
    }

    private function shorten(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($sql) > self::MAX_SQL_IN_MESSAGE ? substr($sql, 0, self::MAX_SQL_IN_MESSAGE).'...' : $sql;
    }
}
