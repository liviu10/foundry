<?php

declare(strict_types=1);

namespace Foundry;

use Closure;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use OCILob;
use OutOfBoundsException;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

/**
 * Oracle Class
 *
 * Wrapper over the oci8 extension, sharing a consistent public interface with MySQL and SQLite.
 * Target: Oracle 11.2 (no OFFSET/FETCH, no IDENTITY columns), client oci8 19.x.
 *
 * oci8 shortcomings addressed:
 *   - All oci_* functions return false and emit warnings; errors must be read separately with
 *     oci_error(); here every error becomes an exception with the ORA-xxxxx error code;
 *   - oci_connect() reuses the same connection (and transaction) for identical credentials;
 *     here each instance maintains its own dedicated connection (oci_new_connect);
 *   - oci_execute() commits after each statement by default; here autocommit applies outside
 *     transaction() and commits explicitly at the end of transaction(), matching PDO;
 *   - Date and decimal formatting depend on server NLS settings (e.g. on a Romanian server
 *     1.5 formats as '1,5'); here the session enforces 'YYYY-MM-DD HH24:MI:SS' and decimal dot;
 *   - Positional parameters (?) are unsupported in oci8; they are converted to :p1, :p2...;
 *   - An IN list is capped at 1000 items by Oracle; whereIn() automatically splits large lists.
 *
 * Returned values: NUMBER columns without decimals become int (if within range), others remain string
 * (preserving precision; '.5' becomes '0.5'); DATE is formatted as 'Y-m-d H:i:s'; CLOB/BLOB are strings.
 * Column names default to lowercase (like MySQL); lowercaseKeys: false leaves them
 * as returned by Oracle (usually uppercase).
 *
 * Identifiers are validated, uppercased, and enclosed in double quotes ("USERS"),
 * matching unquoted table declarations. Maximum length is 30 characters (11g limit).
 *
 * Strings longer than 4000 bytes are automatically bound as temporary CLOBs.
 *
 * Error messages include the SQL statement (excluding parameter values) and the ORA error.
 *
 * Usage:
 *   require_once 'Oracle.php';
 *   use Foundry\Oracle;
 *
 *   $db = new Oracle(connection: '//db-server:1521/ORCL', username: Env::string('ORACLE_USER'),
 *       password: Env::string('ORACLE_PASSWORD'));
 *   $users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
 *   $id = $db->insertGetId('users', ['id' => $db->nextVal('users_seq'), 'name' => 'Ion']);
 *   $page = $db->paginate('SELECT * FROM users ORDER BY name', [], 2, 20);   // with ROWNUM
 *
 * @category Utilities
 *
 * @version  1.1
 *
 * @since    PHP 8.3
 *
 * @author   Voica Liviu
 * @license  Proprietary
 */
final class Oracle
{
    /** @var string An identifier segment: letters, digits, '_', '$', '#', not starting with a digit. */
    private const string IDENTIFIER_PATTERN = '/^[A-Za-z][A-Za-z0-9_$#]*$/D';

    /** @var int Maximum identifier length in Oracle 11g. */
    private const int MAX_IDENTIFIER_LENGTH = 30;

    /** @var int Maximum number of elements in an IN list. */
    private const int MAX_IN_LIST = 1000;

    /** @var int Strings exceeding this length are bound as CLOB (SQL VARCHAR2 limit). */
    private const int MAX_VARCHAR_BYTES = 4000;

    /** @var int Rows per INSERT ALL batch in insertMany(). */
    private const int INSERT_ROWS = 500;

    /** @var int Bound parameters limit per INSERT ALL (very large statements parse slowly). */
    private const int INSERT_PARAMETERS = 30_000;

    /** @var int Number of rows prefetched from the server per network round-trip. */
    private const int PREFETCH_ROWS = 1000;

    /** @var int Maximum SQL length included in error messages. */
    private const int MAX_SQL_IN_MESSAGE = 500;

    /** @var string Helper column added by paginate(). */
    private const string ROW_NUMBER_COLUMN = 'PAGINATE_RN';

    /** @var string Output bind variable name used by insertGetId(). */
    private const string RETURNING_BIND = 'insert_id';

    /** @var resource|null */
    private mixed $link = null;

    private bool $transactionActive = false;

    /** @var (Closure(string, array<array-key, mixed>, float): void)|null */
    private ?Closure $queryListener = null;

    /**
     * @param  string  $connection  '//host:1521/SERVICE' (Easy Connect) or a tnsnames.ora alias.
     * @param  string  $charset  Client character set (AL32UTF8 = UTF-8).
     * @param  bool  $lowercaseKeys  Whether column names are lowercased in returned rows.
     *
     * @throws InvalidArgumentException If parameters are invalid.
     */
    public function __construct(
        private readonly string $connection,
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $password,
        private readonly string $charset = 'AL32UTF8',
        private readonly bool $lowercaseKeys = true,
    ) {
        foreach (['connection' => $connection, 'username' => $username, 'charset' => $charset] as $name => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("Oracle::__construct(): {$name} must not be empty.");
            }
        }
    }

    /**
     * Establishes the database connection immediately (otherwise opened lazily on first query). Useful for health checks at boot.
     *
     * @throws RuntimeException If connecting fails.
     */
    public function connect(): void
    {
        $this->connection('connect');
    }

    /**
     * Closes the connection (uncommitted transaction is rolled back); the next query reopens it.
     */
    public function close(): void
    {
        if (is_resource($this->link)) {
            $this->capture(fn (): ?bool => oci_close($this->link));
        }

        $this->link = null;
        $this->transactionActive = false;
    }

    /**
     * Fetches all rows as associative arrays.
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query('fetchAll', $sql, $params);
    }

    /**
     * Fetches a single row or null if not found. Returning multiple rows throws an exception (missing WHERE).
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>|null
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query('fetchOne', $sql, $params, 2);

        if (count($rows) > 1) {
            throw new UnexpectedValueException('Oracle::fetchOne(): query returned more than one row. SQL: '.$this->shorten($sql));
        }

        return $rows[0] ?? null;
    }

    /**
     * Fetches a single row; throws an exception if not found.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchOneOrFail(string $sql, array $params = []): array
    {
        return $this->fetchOne($sql, $params)
            ?? throw new OutOfBoundsException('Oracle::fetchOneOrFail(): query returned no rows. SQL: '.$this->shorten($sql));
    }

    /**
     * Fetches the first column of a single row (e.g., SELECT COUNT(*) FROM ...). Returns null if the value is NULL.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws OutOfBoundsException If no rows are returned.
     * @throws UnexpectedValueException If the query returns more than one row.
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $rows = $this->query('fetchValue', $sql, $params, 2);

        if ($rows === []) {
            throw new OutOfBoundsException('Oracle::fetchValue(): query returned no rows. SQL: '.$this->shorten($sql));
        }

        if (count($rows) > 1) {
            throw new UnexpectedValueException('Oracle::fetchValue(): query returned more than one row. SQL: '.$this->shorten($sql));
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
     * @throws RuntimeException If the query fails.
     */
    public function fetchColumn(string $sql, array $params = []): array
    {
        return array_map(static fn (array $row): mixed => reset($row), $this->query('fetchColumn', $sql, $params));
    }

    /**
     * Fetches the first column as key and second column as value: SELECT id, name -> [1 => 'Ion', 2 => 'Ana'].
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If the query fails.
     * @throws UnexpectedValueException If the row does not have exactly 2 columns, key is not int/string, or keys are duplicate.
     */
    public function fetchPairs(string $sql, array $params = []): array
    {
        $pairs = [];
        foreach ($this->query('fetchPairs', $sql, $params) as $position => $row) {
            if (count($row) !== 2) {
                throw new UnexpectedValueException('Oracle::fetchPairs(): query must select exactly 2 columns, got '.count($row).'.');
            }

            [$key, $value] = array_values($row);
            if (! is_int($key) && ! is_string($key)) {
                throw new UnexpectedValueException("Oracle::fetchPairs(): the key in row #{$position} must be int or string, got ".get_debug_type($key).'.');
            }
            if (array_key_exists($key, $pairs)) {
                throw new UnexpectedValueException("Oracle::fetchPairs(): duplicate key in row #{$position}.");
            }
            $pairs[$key] = $value;
        }

        return $pairs;
    }

    /**
     * Executes a statement (INSERT, UPDATE, DELETE, DDL, PL/SQL block) and returns the number of affected rows.
     *
     * @param  array<array-key, mixed>  $params
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If execution fails.
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->run('execute', $sql, $params);
        $count = oci_num_rows($statement);
        oci_free_statement($statement);

        return $count === false ? 0 : $count;
    }

    /**
     * Inserts a row from an associative array: ['column' => value].
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails.
     */
    public function insert(string $table, array $data): void
    {
        [$sql, $params] = $this->insertSql('insert', $table, $data);
        $this->execute($sql, $params);
    }

    /**
     * Inserts a row from an array and returns the value of $idColumn, retrieved via RETURNING ... INTO
     * (Oracle does not support lastInsertId). The ID can come from $data (e.g. nextVal()) or a trigger.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException If table name, columns, or values are invalid.
     * @throws RuntimeException If insertion fails or the returned ID is NULL.
     */
    public function insertGetId(string $table, array $data, string $idColumn = 'id'): int|string
    {
        [$sql, $params] = $this->insertSql('insertGetId', $table, $data);
        $sql .= ' RETURNING '.$this->quote('insertGetId', $idColumn).' INTO :'.self::RETURNING_BIND;

        $returned = null;
        $statement = $this->run('insertGetId', $sql, $params, $returned);
        oci_free_statement($statement);

        if (! is_string($returned) || $returned === '') {
            throw new RuntimeException("Oracle::insertGetId(): column '{$idColumn}' is NULL after insert (no value and no trigger).");
        }

        return $this->normalizeNumber($returned);
    }

    /**
     * Retrieves the next value from a sequence (Oracle 11g does not have IDENTITY columns).
     *
     * @throws InvalidArgumentException If sequence name is invalid.
     * @throws RuntimeException If the sequence does not exist.
     */
    public function nextVal(string $sequence): int
    {
        $value = $this->fetchValue('SELECT '.$this->quote('nextVal', $sequence).'.NEXTVAL FROM dual');

        return is_int($value) ? $value : throw new UnexpectedValueException("Oracle::nextVal(): sequence '{$sequence}' returned a value outside the integer range.");
    }

    /**
     * Multiple rows, using batched INSERT ALL statements of up to 500 rows (within database parameter limits),
     * executed within a single transaction: either all succeed or none. Returns the number of inserted rows.
     * All rows must share identical columns (in any order). If called within an existing transaction, it joins it.
     *
     * @param  list<array<string, mixed>>  $rows
     *
     * @throws InvalidArgumentException If table, columns, or values are invalid.
     * @throws RuntimeException If insertion fails (none of the batch remains inserted).
     */
    public function insertMany(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        [$columns, $values] = $this->rowValues('insertMany', $rows);
        $into = $this->insertAllInto('insertMany', $table, $columns);
        $perStatement = max(1, min(self::INSERT_ROWS, intdiv(self::INSERT_PARAMETERS, count($columns))));

        $this->transaction(function () use ($values, $perStatement, $into): void {
            foreach (array_chunk($values, $perStatement) as $chunk) {
                $this->execute('INSERT ALL '.implode(' ', array_fill(0, count($chunk), $into)).' SELECT 1 FROM dual', array_merge(...$chunk));
            }
        });

        return count($rows);
    }

    /**
     * Streams rows one by one without buffering the entire result set in memory (for large exports or processing).
     * The query executes immediately (raising query errors upfront); rows are fetched as the generator advances.
     * Exiting the loop (break) closes the cursor.
     *
     * @param  array<array-key, mixed>  $params
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution or row fetching fails.
     */
    public function cursor(string $sql, array $params = []): Generator
    {
        return $this->stream('cursor', $sql, $this->run('cursor', $sql, $params));
    }

    /**
     * UPDATE from an associative array. Conditions are required (refuses table-wide UPDATE).
     * Returns the number of rows matching the conditions.
     *
     * @param  array<string, mixed>  $data  ['column' => new_value]
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     *
     * @throws InvalidArgumentException If table, columns, or values are invalid, or if $where is empty.
     * @throws RuntimeException If update execution fails.
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Oracle::update(): data must not be empty.');
        }

        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->quote('update', (string) $column).' = ?';
            $params[] = self::scalar('update', $value, "column '{$column}'");
        }

        [$condition, $whereParams] = $this->whereSql('update', $where);

        return $this->execute('UPDATE '.$this->quote('update', $table).' SET '.implode(', ', $set).' WHERE '.$condition, [...$params, ...$whereParams]);
    }

    /**
     * DELETE with mandatory conditions (refuses table-wide DELETE). Returns the number of deleted rows.
     *
     * @param  array<string, mixed>  $where  ['column' => value]; null -> IS NULL; list -> IN (...).
     *
     * @throws InvalidArgumentException If table or conditions are invalid, or if $where is empty.
     * @throws RuntimeException If deletion fails.
     */
    public function delete(string $table, array $where): int
    {
        [$condition, $params] = $this->whereSql('delete', $where);

        return $this->execute('DELETE FROM '.$this->quote('delete', $table).' WHERE '.$condition, $params);
    }

    /**
     * Generates an IN condition with placeholders: whereIn('id', [1, 2]) -> ['"ID" IN (?, ?)', [1, 2]].
     * Handles lists exceeding 1000 items: ('"ID" IN (...) OR "ID" IN (...)'). Empty list -> ['1 = 0', []].
     *
     * @param  list<mixed>  $values
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If column or values are invalid.
     */
    public function whereIn(string $column, array $values): array
    {
        if ($values === []) {
            return ['1 = 0', []];
        }

        $quoted = $this->quote('whereIn', $column);
        $params = array_map(static fn (mixed $value): int|float|string|null => self::scalar('whereIn', $value, "column '{$column}'"), $values);

        $groups = array_map(
            static fn (array $chunk): string => $quoted.' IN ('.implode(', ', array_fill(0, count($chunk), '?')).')',
            array_chunk($params, self::MAX_IN_LIST),
        );

        return [count($groups) === 1 ? $groups[0] : '('.implode(' OR ', $groups).')', $params];
    }

    /**
     * Executes the callback within a database transaction: commits on success, rolls back on any exception (re-thrown).
     * If called inside an existing transaction, joins it (the outer transaction handles commit).
     *
     * @template T
     *
     * @param  Closure(self): T  $callback
     * @return T
     *
     * @throws RuntimeException If the transaction cannot be committed.
     * @throws Throwable Exception thrown by the callback, re-thrown after rollback.
     */
    public function transaction(Closure $callback): mixed
    {
        if ($this->transactionActive) {
            return $callback($this);
        }

        $connection = $this->connection('transaction');
        $this->transactionActive = true;

        try {
            $result = $callback($this);

            [$committed, $warning] = $this->capture(static fn (): bool => oci_commit($connection));
            if (! $committed) {
                throw new RuntimeException('Oracle::transaction(): commit failed: '.$this->errorMessage(oci_error($connection), $warning));
            }

            return $result;
        } catch (Throwable $e) {
            // A failed commit or callback exception: nothing in the transaction remains.
            $this->capture(static fn (): bool => oci_rollback($connection));

            throw $e;
        } finally {
            $this->transactionActive = false;
        }
    }

    /**
     * Checks whether a transaction is currently active.
     */
    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    /**
     * Paginated result page with total count: ['items' => [...], 'total' => 120, 'page' => 2, 'per_page' => 20, 'pages' => 6].
     * Pagination relies on ROWNUM (Oracle 11g lacks OFFSET/FETCH). $sql must contain an ORDER BY clause.
     * A page beyond the last returns an empty items array.
     *
     * @param  array<array-key, mixed>  $params
     * @return array{items: list<array<array-key, mixed>>, total: int, page: int, per_page: int, pages: int}
     *
     * @throws InvalidArgumentException If $page or $perPage is less than 1.
     * @throws RuntimeException If the query fails.
     */
    public function paginate(string $sql, array $params, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException("Oracle::paginate(): page and perPage must be >= 1, got {$page} and {$perPage}.");
        }

        $total = $this->fetchValue('SELECT COUNT(*) FROM ('.$sql.')', $params);
        if (! is_int($total)) {
            throw new UnexpectedValueException('Oracle::paginate(): COUNT(*) did not return an integer.');
        }

        $offset = ($page - 1) * $perPage;
        $items = [];
        if ($total > $offset) {
            $rowNumber = $this->lowercaseKeys ? strtolower(self::ROW_NUMBER_COLUMN) : self::ROW_NUMBER_COLUMN;
            foreach ($this->fetchAll($this->paginationSql($sql, $offset, $perPage), $params) as $row) {
                unset($row[$rowNumber]);
                $items[] = $row;
            }
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /**
     * Validates and quotes an identifier (table, column, sequence) in uppercase with double quotes:
     * users -> "USERS", app.users -> "APP"."USERS". Maximum 30 characters per segment.
     *
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    public function quoteIdentifier(string $name): string
    {
        return $this->quote('quoteIdentifier', $name);
    }

    /**
     * Listener callback invoked after each successful query: fn (string $sql, array $params, float $ms).
     * Parameters may include sensitive data; the callback determines what to log. null disables the listener.
     *
     * @param  (Closure(string, array<array-key, mixed>, float): void)|null  $listener
     */
    public function onQuery(?Closure $listener): void
    {
        $this->queryListener = $listener;
    }

    /**
     * @throws InvalidArgumentException If the name is not a valid identifier.
     */
    private function quote(string $method, string $name): string
    {
        $segments = explode('.', $name);

        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER_PATTERN, $segment) !== 1 || strlen($segment) > self::MAX_IDENTIFIER_LENGTH) {
                throw new InvalidArgumentException("Oracle::{$method}(): invalid identifier '{$name}' (allowed: letters, digits, '_', '\$', '#', at most 30 characters, optionally 'schema.name').");
            }
        }

        return '"'.strtoupper(implode('"."', $segments)).'"';
    }

    /**
     * Establishes the database connection on first use with explicit NLS session parameters.
     *
     * @return resource
     *
     * @throws RuntimeException If the connection cannot be established.
     */
    private function connection(string $method): mixed
    {
        if (is_resource($this->link)) {
            return $this->link;
        }

        if (! function_exists('oci_new_connect')) {
            throw new RuntimeException("Oracle::{$method}(): the oci8 extension is required.");
        }

        [$connection, $warning] = $this->capture(fn (): mixed => oci_new_connect($this->username, $this->password, $this->connection, $this->charset));

        if (! is_resource($connection)) {
            throw new RuntimeException("Oracle::{$method}(): cannot connect to '{$this->connection}': ".$this->errorMessage(oci_error(), $warning));
        }

        $this->link = $connection;

        // Formats independent of server locale: MySQL-compatible date formats and standard decimal point.
        $this->execute("ALTER SESSION SET NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS' NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF' NLS_NUMERIC_CHARACTERS = '.,'");

        return $connection;
    }

    /**
     * Executes a query and fetches rows (up to $limit if specified).
     *
     * @param  array<array-key, mixed>  $params
     * @return list<array<array-key, mixed>>
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution or reading fails.
     */
    private function query(string $method, string $sql, array $params, ?int $limit = null): array
    {
        $rows = [];
        foreach ($this->stream($method, $sql, $this->run($method, $sql, $params)) as $row) {
            $rows[] = $row;
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Generator yielding rows from an executed statement as iteration advances;
     * integer NUMBER -> int, column keys lowercased if configured. Statement is freed on completion.
     *
     * @param  resource  $statement
     * @return Generator<int, array<array-key, mixed>>
     *
     * @throws RuntimeException If reading rows fails.
     */
    private function stream(string $method, string $sql, mixed $statement): Generator
    {
        try {
            // NUMBER columns identified for value casting (oci8 returns all numbers as strings).
            $numeric = [];
            $fields = oci_num_fields($statement);
            for ($i = 1; $i <= $fields; $i++) {
                if (oci_field_type($statement, $i) === 'NUMBER') {
                    $name = (string) oci_field_name($statement, $i);
                    $numeric[$this->lowercaseKeys ? strtolower($name) : $name] = true;
                }
            }

            $index = 0;
            while (true) {
                [$row, $warning] = $this->capture(static fn (): array|false => oci_fetch_array($statement, OCI_ASSOC | OCI_RETURN_NULLS | OCI_RETURN_LOBS));

                if ($row === false) {
                    $error = oci_error($statement);
                    if ($error !== false) {
                        throw $this->queryFailed($method, $sql, $this->errorMessage($error, $warning));
                    }

                    break;
                }

                if ($this->lowercaseKeys) {
                    $row = array_change_key_case($row, CASE_LOWER);
                }
                foreach ($row as $column => $value) {
                    if (isset($numeric[$column]) && is_string($value)) {
                        $row[$column] = $this->normalizeNumber($value);
                    }
                }

                yield $index++ => $row;
            }
        } finally {
            oci_free_statement($statement);
        }
    }

    /**
     * Builds the repeated INTO fragment for INSERT ALL: INTO "TABLE" ("A", "B") VALUES (?, ?).
     *
     * @param  non-empty-list<string>  $columns
     *
     * @throws InvalidArgumentException If the table name or columns are invalid.
     */
    private function insertAllInto(string $method, string $table, array $columns): string
    {
        return 'INTO '.$this->quote($method, $table)
            .' ('.implode(', ', array_map(fn (string $column): string => $this->quote($method, $column), $columns)).')'
            .' VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')';
    }

    /**
     * Extracts columns from the first row and aligns values across all rows in that column order.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: non-empty-list<string>, 1: list<list<int|float|string|null>>}
     *
     * @throws InvalidArgumentException If a row has differing columns or unsupported values.
     */
    private function rowValues(string $method, array $rows): array
    {
        $columns = array_map(strval(...), array_keys($rows[0] ?? []));
        if ($columns === []) {
            throw new InvalidArgumentException("Oracle::{$method}(): data must not be empty.");
        }

        $values = [];
        foreach ($rows as $index => $row) {
            if (count($row) !== count($columns)) {
                throw new InvalidArgumentException("Oracle::{$method}(): row #{$index} does not have the same columns as the first row.");
            }

            $list = [];
            foreach ($columns as $column) {
                if (! array_key_exists($column, $row)) {
                    throw new InvalidArgumentException("Oracle::{$method}(): row #{$index} does not have the same columns as the first row.");
                }
                $list[] = self::scalar($method, $row[$column], "row #{$index}, column '{$column}'");
            }
            $values[] = $list;
        }

        return [$columns, $values];
    }

    /**
     * Prepares and executes the query with named bound parameters.
     * With $returned, binds the output parameter for RETURNING ... INTO.
     *
     * @param  array<array-key, mixed>  $params
     * @return resource
     *
     * @throws InvalidArgumentException If parameters are invalid.
     * @throws RuntimeException If query execution fails.
     */
    private function run(string $method, string $sql, array $params, mixed &$returned = null): mixed
    {
        [$sql, $binds] = $this->namedBinds($method, $sql, $params);
        $connection = $this->connection($method);
        $start = hrtime(true);

        [$statement, $warning] = $this->capture(static fn (): mixed => oci_parse($connection, $sql));
        if (! is_resource($statement)) {
            throw $this->queryFailed($method, $sql, $this->errorMessage(oci_error($connection), $warning));
        }

        // Reduces network roundtrips when fetching large result sets.
        oci_set_prefetch($statement, self::PREFETCH_ROWS);

        /** @var list<OCILob> $lobs */
        $lobs = [];

        try {
            foreach ($binds as $name => $value) {
                if (is_string($value) && strlen($value) > self::MAX_VARCHAR_BYTES) {
                    $lob = oci_new_descriptor($connection, OCI_D_LOB);
                    if (! $lob instanceof OCILob || ! $lob->writeTemporary($value, OCI_TEMP_CLOB)) {
                        throw $this->queryFailed($method, $sql, 'cannot create a temporary CLOB for :'.$name);
                    }
                    $lobs[] = $lob;
                    $bound = $this->capture(static fn (): bool => oci_bind_by_name($statement, ':'.$name, $lob, -1, OCI_B_CLOB))[0];
                } else {
                    $binds[$name] = $value;
                    $bound = $this->capture(static function () use ($statement, $name, &$binds): bool {
                        return oci_bind_by_name($statement, ':'.$name, $binds[$name]);
                    })[0];
                }

                if (! $bound) {
                    throw $this->queryFailed($method, $sql, $this->errorMessage(oci_error($statement), null));
                }
            }

            if (func_num_args() > 3) {
                $returned = null;
                $bound = $this->capture(static function () use ($statement, &$returned): bool {
                    return oci_bind_by_name($statement, ':'.self::RETURNING_BIND, $returned, 4000, SQLT_CHR);
                })[0];
                if (! $bound) {
                    throw $this->queryFailed($method, $sql, $this->errorMessage(oci_error($statement), null));
                }
            }

            $mode = $this->transactionActive ? OCI_NO_AUTO_COMMIT : OCI_COMMIT_ON_SUCCESS;
            [$executed, $warning] = $this->capture(static fn (): bool => oci_execute($statement, $mode));
            if (! $executed) {
                throw $this->queryFailed($method, $sql, $this->errorMessage(oci_error($statement), $warning));
            }
        } catch (Throwable $e) {
            oci_free_statement($statement);

            throw $e;
        } finally {
            foreach ($lobs as $lob) {
                $lob->free();
            }
        }

        if ($this->queryListener instanceof Closure) {
            ($this->queryListener)($sql, $params, (hrtime(true) - $start) / 1e6);
        }

        return $statement;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If table, columns, or values are invalid.
     */
    private function insertSql(string $method, string $table, array $data): array
    {
        if ($data === []) {
            throw new InvalidArgumentException("Oracle::{$method}(): data must not be empty.");
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
     * Assembles WHERE conditions from an associative array joined by AND.
     *
     * @param  array<string, mixed>  $where
     * @return array{0: string, 1: list<int|float|string|null>}
     *
     * @throws InvalidArgumentException If $where is empty or contains invalid columns/values.
     */
    private function whereSql(string $method, array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException("Oracle::{$method}(): conditions must not be empty (refusing to affect the whole table).");
        }

        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $quoted = $this->quote($method, (string) $column);

            if ($value === null) {
                $conditions[] = $quoted.' IS NULL';
            } elseif (is_array($value)) {
                [$in, $inParams] = $this->whereIn((string) $column, array_values($value));
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
     * Converts positional SQL parameters to named binds: '?' becomes :p1, :p2... (outside quoted
     * strings and comments); for named parameters, strips leading ':' from keys.
     *
     * @param  array<array-key, mixed>  $params
     * @return array{0: string, 1: array<string, int|float|string|null>}
     *
     * @throws InvalidArgumentException If parameters are mixed, mismatch SQL placeholders, or contain unsupported types.
     */
    private function namedBinds(string $method, string $sql, array $params): array
    {
        if (! array_is_list($params)) {
            $binds = [];
            foreach ($params as $key => $value) {
                $name = is_string($key) ? ltrim($key, ':') : '';
                if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,29}$/D', $name) !== 1) {
                    throw new InvalidArgumentException("Oracle::{$method}(): parameters must be either all positional (?) or all named (:name), got key '{$key}'.");
                }
                $binds[$name] = self::scalar($method, $value, "parameter '{$name}'");
            }

            return [$sql, $binds];
        }

        $result = '';
        $count = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Quoted strings, identifiers, and comments: preserved as-is.
            $end = match (true) {
                $char === "'" => strpos($sql, "'", $i + 1),
                $char === '"' => strpos($sql, '"', $i + 1),
                $char === '-' && $next === '-' => strpos($sql, "\n", $i),
                $char === '/' && $next === '*' => ($close = strpos($sql, '*/', $i + 2)) === false ? false : $close + 1,
                default => null,
            };

            if ($end !== null) {
                $end = $end === false ? $length - 1 : $end;
                $result .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '?') {
                $result .= ':p'.(++$count);
            } else {
                $result .= $char;
            }
        }

        if ($count !== count($params)) {
            throw new InvalidArgumentException("Oracle::{$method}(): SQL has {$count} placeholders but ".count($params).' parameters were given.');
        }

        $binds = [];
        foreach ($params as $index => $value) {
            $binds['p'.($index + 1)] = self::scalar($method, $value, 'parameter #'.($index + 1));
        }

        return [$result, $binds];
    }

    /**
     * Constructs a paginated query using ROWNUM nesting (Oracle 11g lacks OFFSET/FETCH).
     */
    private function paginationSql(string $sql, int $offset, int $limit): string
    {
        $last = $offset + $limit;

        return 'SELECT * FROM (SELECT paginate_inner.*, ROWNUM '.self::ROW_NUMBER_COLUMN.' FROM ('.$sql.') paginate_inner'
            ." WHERE ROWNUM <= {$last}) WHERE ".self::ROW_NUMBER_COLUMN." > {$offset}";
    }

    /**
     * Converts values for database binding: bool -> 1/0, DateTimeInterface -> 'Y-m-d H:i:s', float -> string.
     *
     * @throws InvalidArgumentException If type is unsupported (array, object, resource).
     */
    private static function scalar(string $method, mixed $value, string $name): int|float|string|null
    {
        return match (true) {
            $value === null, is_int($value), is_string($value) => $value,
            is_bool($value) => $value ? 1 : 0,
            is_float($value) => is_finite($value) ? $value : throw new InvalidArgumentException("Oracle::{$method}(): value for {$name} must be a finite number."),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => throw new InvalidArgumentException("Oracle::{$method}(): unsupported type ".get_debug_type($value)." for {$name}."),
        };
    }

    /**
     * Normalizes NUMBER read by oci8: integer -> int (if fits), string otherwise; '.5' -> '0.5', '-.5' -> '-0.5'.
     */
    private function normalizeNumber(string $value): int|string
    {
        if (preg_match('/^-?\d+$/D', $value) === 1) {
            $int = (int) $value;

            return (string) $int === $value ? $int : $value;
        }

        return (string) preg_replace('/^(-?)\./', '${1}0.', $value);
    }

    /**
     * Extracts error message from oci8 error array: 'ORA-00942: table or view does not exist'.
     *
     * @param  array<array-key, mixed>|false  $error  Result from oci_error().
     */
    private function errorMessage(array|false $error, ?string $warning): string
    {
        if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
            return trim($error['message']);
        }

        return $warning ?? 'unknown oci8 error';
    }

    private function queryFailed(string $method, string $sql, string $error): RuntimeException
    {
        return new RuntimeException("Oracle::{$method}(): query failed: {$error} SQL: ".$this->shorten($sql));
    }

    private function shorten(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($sql) > self::MAX_SQL_IN_MESSAGE ? substr($sql, 0, self::MAX_SQL_IN_MESSAGE).'...' : $sql;
    }

    /**
     * Executes an oci8 function and captures any emitted warning instead of displaying it.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return array{0: T, 1: string|null} [result, warning message or null]
     */
    private function capture(Closure $operation): array
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$operation(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
