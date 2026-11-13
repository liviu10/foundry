<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\File;
use Foundry\SQLite;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class SQLiteTest extends TestCase
{
    private SQLite $db;

    protected function setUp(): void
    {
        $this->db = new SQLite(':memory:');
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT UNIQUE, active INTEGER NOT NULL DEFAULT 1, score REAL, created_at TEXT)');
        $this->db->execute('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), total TEXT)');
        $this->db->insert('users', ['name' => 'Ion', 'email' => 'ion@x.ro', 'score' => 1.5]);
        $this->db->insert('users', ['name' => 'Ana', 'email' => 'ana@x.ro', 'active' => 0]);
        $this->db->insert('users', ['name' => 'Dan', 'email' => null]);
    }

    // conexiune

    public function test_missing_file_is_not_created_silently(): void
    {
        $path = sys_get_temp_dir().'/utilities-sqlite-'.bin2hex(random_bytes(6)).'.db';
        $db = new SQLite($path);

        try {
            $db->connect();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("database file '{$path}' does not exist (pass create: true to create it)", $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_create_and_wal_on_file(): void
    {
        $dir = sys_get_temp_dir().'/utilities-sqlite-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $path = $dir.'/app.db';

        try {
            $db = new SQLite($path, create: true, wal: true);
            $db->execute('CREATE TABLE t (x INTEGER)');
            $this->assertFileExists($path);
            $this->assertSame('wal', $db->fetchValue('PRAGMA journal_mode'));
            $db->close();

            $reopened = new SQLite($path);
            $this->assertSame(0, $reopened->fetchValue('SELECT COUNT(*) FROM t'));
            $reopened->close();
        } finally {
            File::deleteDir($dir);
        }
    }

    public function test_foreign_keys_are_enforced(): void
    {
        $this->assertSame(1, $this->db->fetchValue('PRAGMA foreign_keys'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FOREIGN KEY constraint failed');

        $this->db->insert('orders', ['user_id' => 999, 'total' => '10.00']);
    }

    public function test_constructor_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SQLite('');
    }

    // fetch

    public function test_fetch_all_returns_native_types(): void
    {
        $rows = $this->db->fetchAll('SELECT id, name, active, score FROM users ORDER BY id');

        $this->assertCount(3, $rows);
        $this->assertSame(['id' => 1, 'name' => 'Ion', 'active' => 1, 'score' => 1.5], $rows[0]);
        $this->assertNull($rows[2]['score']);
        $this->assertSame([], $this->db->fetchAll('SELECT * FROM users WHERE id > ?', [100]));
    }

    public function test_positional_and_named_parameters(): void
    {
        $this->assertSame('Ana', $this->db->fetchValue('SELECT name FROM users WHERE email = ? AND active = ?', ['ana@x.ro', 0]));
        $this->assertSame('Ana', $this->db->fetchValue('SELECT name FROM users WHERE email = :email', ['email' => 'ana@x.ro']));
        $this->assertSame('Ana', $this->db->fetchValue('SELECT name FROM users WHERE email = :email', [':email' => 'ana@x.ro']));
    }

    public function test_mixed_parameters_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('parameters must be either all positional (?) or all named (:name)');

        $this->db->fetchAll('SELECT * FROM users WHERE id = ? AND name = :name', [0 => 1, 'name' => 'Ion']);
    }

    public function test_parameter_types(): void
    {
        $date = new DateTimeImmutable('2026-03-15 10:20:30');
        $this->db->update('users', ['created_at' => $date, 'active' => false, 'score' => 2.25], ['id' => 1]);

        $this->assertSame(
            ['created_at' => '2026-03-15 10:20:30', 'active' => 0, 'score' => 2.25],
            $this->db->fetchOne('SELECT created_at, active, score FROM users WHERE id = ?', [1]),
        );
        $this->assertSame(['Dan'], $this->db->fetchColumn('SELECT name FROM users WHERE active = ?', [true]), 'true -> 1');
    }

    public function test_unsupported_parameter_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite::fetchAll(): unsupported type array for parameter #1.');

        $this->db->fetchAll('SELECT * FROM users WHERE id = ?', [[1, 2]]);
    }

    public function test_fetch_one(): void
    {
        $this->assertSame(['name' => 'Ion'], $this->db->fetchOne('SELECT name FROM users WHERE id = ?', [1]));
        $this->assertNull($this->db->fetchOne('SELECT name FROM users WHERE id = ?', [100]));
    }

    public function test_fetch_one_rejects_multiple_rows(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('SQLite::fetchOne(): query returned more than one row. SQL: SELECT name FROM users');

        $this->db->fetchOne('SELECT name FROM users');
    }

    public function test_fetch_one_or_fail(): void
    {
        $this->assertSame('Ion', $this->db->fetchOneOrFail('SELECT * FROM users WHERE id = 1')['name']);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('SQLite::fetchOneOrFail(): query returned no rows.');

        $this->db->fetchOneOrFail('SELECT * FROM users WHERE id = ?', [100]);
    }

    public function test_fetch_value(): void
    {
        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertNull($this->db->fetchValue('SELECT email FROM users WHERE id = 3'), 'NULL este valoare, nu lipsa');

        $this->expectException(OutOfBoundsException::class);
        $this->db->fetchValue('SELECT email FROM users WHERE id = 100');
    }

    public function test_fetch_value_rejects_multiple_rows(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->db->fetchValue('SELECT id FROM users');
    }

    public function test_fetch_column_and_pairs(): void
    {
        $this->assertSame(['Ion', 'Ana', 'Dan'], $this->db->fetchColumn('SELECT name FROM users ORDER BY id'));
        $this->assertSame([1 => 'Ion', 2 => 'Ana', 3 => 'Dan'], $this->db->fetchPairs('SELECT id, name FROM users ORDER BY id'));
    }

    public function test_fetch_pairs_rejects_duplicates_and_wrong_shape(): void
    {
        try {
            $this->db->fetchPairs('SELECT active, name FROM users ORDER BY id');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertSame('SQLite::fetchPairs(): duplicate key in row #2.', $e->getMessage());
        }

        try {
            $this->db->fetchPairs('SELECT id, name, email FROM users');
            $this->fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            $this->assertSame('SQLite::fetchPairs(): query must select exactly 2 columns, got 3.', $e->getMessage());
        }

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the key in row #0 must be int or string, got null');
        $this->db->fetchPairs('SELECT email, name FROM users WHERE id = 3');
    }

    public function test_query_error_contains_sql_and_driver_message_but_not_params(): void
    {
        try {
            $this->db->fetchAll('SELECT * FROM missing_table WHERE secret = ?', ['date-personale']);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SQLite::fetchAll(): query failed [SQLSTATE HY000]: no such table: missing_table', $e->getMessage());
            $this->assertStringContainsString('SQL: SELECT * FROM missing_table WHERE secret = ?', $e->getMessage());
            $this->assertStringNotContainsString('date-personale', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    // insert / update / delete

    public function test_insert_get_id(): void
    {
        $id = $this->db->insertGetId('users', ['name' => 'Eva']);

        $this->assertSame(4, $id);
        $this->assertSame('Eva', $this->db->fetchValue('SELECT name FROM users WHERE id = ?', [$id]));
    }

    public function test_unique_violation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite::insert(): query failed [SQLSTATE 23000]: UNIQUE constraint failed: users.email');

        $this->db->insert('users', ['name' => 'Alt Ion', 'email' => 'ion@x.ro']);
    }

    public function test_update_and_delete(): void
    {
        $this->assertSame(2, $this->db->update('users', ['active' => 0], ['active' => 1]));
        $this->assertSame(0, $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE active = 1'));

        $this->assertSame(1, $this->db->delete('users', ['email' => null]), 'null -> IS NULL');
        $this->assertSame(2, $this->db->delete('users', ['id' => [1, 2, 99]]), 'lista -> IN');
        $this->assertSame(0, $this->db->delete('users', ['id' => []]), 'lista goala -> nimic');
        $this->assertSame(0, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_update_and_delete_require_conditions(): void
    {
        foreach ([
            fn (): int => $this->db->update('users', ['active' => 0], []),
            fn (): int => $this->db->delete('users', []),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('conditions must not be empty (refusing to affect the whole table)', $e->getMessage());
            }
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_empty_data_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite::insert(): data must not be empty.');

        $this->db->insert('users', []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifierProvider(): iterable
    {
        yield 'injection' => ['users; DROP TABLE users'];
        yield 'ghilimele' => ['us"ers'];
        yield 'backtick' => ['us`ers'];
        yield 'spatiu' => ['user name'];
        yield 'cifra la inceput' => ['1users'];
        yield 'gol' => [''];
        yield 'segment gol' => ['main.'];
        yield 'comentariu' => ['users--'];
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_invalid_identifiers_are_rejected(string $name): void
    {
        foreach ([
            function () use ($name): void {
                $this->db->insert($name, ['name' => 'x']);
            },
            function () use ($name): void {
                $this->db->insert('users', [$name => 'x']);
            },
            fn (): mixed => $this->db->update('users', ['name' => 'x'], [$name => 1]),
            fn (): mixed => $this->db->delete($name, ['id' => 1]),
            fn (): mixed => $this->db->whereIn($name, [1]),
            fn (): mixed => $this->db->quoteIdentifier($name),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("invalid identifier '{$name}'", $e->getMessage());
            }
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_quote_identifier(): void
    {
        $this->assertSame('"users"', $this->db->quoteIdentifier('users'));
        $this->assertSame('"main"."users"', $this->db->quoteIdentifier('main.users'));
    }

    public function test_where_in(): void
    {
        [$sql, $params] = $this->db->whereIn('id', [1, 3]);

        $this->assertSame('"id" IN (?, ?)', $sql);
        $this->assertSame([1, 3], $params);
        $this->assertSame(['Ion', 'Dan'], $this->db->fetchColumn("SELECT name FROM users WHERE {$sql} ORDER BY id", $params));
        $this->assertSame(['1 = 0', []], $this->db->whereIn('id', []));
    }

    // tranzactii

    public function test_transaction_commits_and_returns_value(): void
    {
        $id = $this->db->transaction(static fn (SQLite $db): int|string => $db->insertGetId('users', ['name' => 'Eva']));

        $this->assertSame(4, $id);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(4, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_transaction_rolls_back_and_rethrows(): void
    {
        try {
            $this->db->transaction(static function (SQLite $db): void {
                $db->insert('users', ['name' => 'Eva']);

                if ($db->inTransaction()) {
                    throw new LogicException('eroare in aplicatie');
                }
            });
            $this->fail('Expected LogicException');
        } catch (LogicException $e) {
            $this->assertSame('eroare in aplicatie', $e->getMessage());
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_nested_transaction_joins_outer(): void
    {
        try {
            $this->db->transaction(static function (SQLite $db): void {
                $db->transaction(static function (SQLite $inner): void {
                    $inner->insert('users', ['name' => 'Eva']);
                });
                $db->insert('users', ['name' => 'Ion', 'email' => 'ion@x.ro']); // UNIQUE -> exceptie
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE name = 'Eva'"), 'interiorul este anulat odata cu exteriorul');
    }

    // insertMany / cursor

    public function test_insert_many_in_several_statements(): void
    {
        $statements = 0;
        $this->db->onQuery(static function (string $sql) use (&$statements): void {
            if (str_starts_with($sql, 'INSERT')) {
                $statements++;
            }
        });

        $rows = array_map(static fn (int $i): array => $i % 2 === 0 ? ['name' => "U{$i}", 'email' => "u{$i}@x.ro"] : ['email' => "u{$i}@x.ro", 'name' => "U{$i}"], range(1, 2500));

        $this->assertSame(2500, $this->db->insertMany('users', $rows));
        $this->assertSame(3, $statements, '1000 + 1000 + 500');
        $this->assertSame(2503, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame('U2500', $this->db->fetchValue('SELECT name FROM users WHERE email = ?', ['u2500@x.ro']), 'ordinea coloanelor poate diferi intre randuri');
        $this->assertSame(0, $this->db->insertMany('users', []));
    }

    public function test_insert_many_is_all_or_nothing(): void
    {
        $rows = array_map(static fn (int $i): array => ['name' => "U{$i}", 'email' => "u{$i}@x.ro"], range(1, 1500));
        $rows[1200]['email'] = 'ion@x.ro'; // UNIQUE, in al doilea INSERT

        try {
            $this->db->insertMany('users', $rows);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('SQLite::insertMany(): query failed [SQLSTATE 23000]: UNIQUE constraint failed: users.email', $e->getMessage());
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'), 'primul INSERT este anulat si el');
        $this->assertFalse($this->db->inTransaction());
    }

    public function test_insert_many_joins_outer_transaction(): void
    {
        try {
            $this->db->transaction(static function (SQLite $db): void {
                $db->insertMany('users', [['name' => 'A'], ['name' => 'B']]);
                $db->insert('users', ['name' => 'Ion', 'email' => 'ion@x.ro']);
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_insert_many_validates_rows_before_writing(): void
    {
        foreach ([
            [[['name' => 'A'], ['email' => 'b@x.ro']], 'SQLite::insertMany(): row #1 does not have the same columns as the first row.'],
            [[['name' => 'A'], ['name' => 'B', 'email' => 'b@x.ro']], 'row #1 does not have the same columns as the first row.'],
            [[['name' => 'A'], ['name' => [1]]], "SQLite::insertMany(): unsupported type array for row #1, column 'name'."],
            [[[]], 'SQLite::insertMany(): data must not be empty.'],
            [[['na me' => 'A']], "invalid identifier 'na me'"],
        ] as [$rows, $message]) {
            try {
                $this->db->insertMany('users', $rows);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_cursor_streams_rows(): void
    {
        $this->db->insertMany('users', array_map(static fn (int $i): array => ['name' => "U{$i}"], range(1, 5000)));

        $count = 0;
        foreach ($this->db->cursor('SELECT id, name FROM users WHERE id > ? ORDER BY id', [3]) as $index => $row) {
            $this->assertSame($count, $index);
            $this->assertSame('U'.($count + 1), $row['name']);
            $count++;
        }

        $this->assertSame(5000, $count);
    }

    public function test_cursor_can_be_stopped_and_connection_reused(): void
    {
        foreach ($this->db->cursor('SELECT name FROM users ORDER BY id') as $row) {
            $this->assertSame('Ion', $row['name']);

            break;
        }

        $this->assertSame(3, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
        $this->db->insert('users', ['name' => 'Eva']);
        $this->assertSame(4, $this->db->fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function test_cursor_errors_appear_at_call(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SQLite::cursor(): query failed [SQLSTATE HY000]: no such table: missing');

        $this->db->cursor('SELECT * FROM missing');
    }

    // paginare

    public function test_paginate(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->db->insert('users', ['name' => 'U'.$i]);
        }

        $page = $this->db->paginate('SELECT id, name FROM users WHERE active = :active ORDER BY id', ['active' => 1], 3, 10);

        $this->assertSame(24, $page['total'], 'Ana este inactiva');
        $this->assertSame(3, $page['pages']);
        $this->assertSame(3, $page['page']);
        $this->assertSame(10, $page['per_page']);
        $this->assertCount(4, $page['items']);
        $this->assertSame(22, $page['items'][0]['id']);
    }

    public function test_paginate_past_last_page_and_empty(): void
    {
        $this->assertSame([], $this->db->paginate('SELECT * FROM users ORDER BY id', [], 5, 10)['items']);

        $empty = $this->db->paginate('SELECT * FROM users WHERE id > ? ORDER BY id', [100], 1, 10);
        $this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 10, 'pages' => 1], $empty);
    }

    public function test_paginate_rejects_invalid_page(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SQLite::paginate(): page and perPage must be >= 1, got 0 and 10.');

        $this->db->paginate('SELECT * FROM users', [], 0, 10);
    }

    // log

    public function test_on_query_listener(): void
    {
        $log = [];
        $this->db->onQuery(static function (string $sql, array $params, float $ms) use (&$log): void {
            $log[] = [$sql, $params, $ms >= 0];
        });

        $this->db->fetchAll('SELECT * FROM users WHERE id = ?', [1]);
        $this->db->onQuery(null);
        $this->db->fetchAll('SELECT 1');

        $this->assertSame([['SELECT * FROM users WHERE id = ?', [1], true]], $log);
    }

    public function test_no_warnings_reach_host_error_handler(): void
    {
        $triggered = false;
        set_error_handler(static function () use (&$triggered): bool {
            $triggered = true;

            return true;
        });

        try {
            foreach (['SELECT * FROM missing', 'INVALID SQL', 'SELECT ?'] as $sql) {
                try {
                    $this->db->fetchAll($sql);
                } catch (RuntimeException) {
                }
            }
            try {
                (new SQLite(sys_get_temp_dir().'/missing-dir-'.bin2hex(random_bytes(4)).'/x.db', create: true))->connect();
            } catch (RuntimeException) {
            }
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($triggered);
    }
}
