<?php

declare(strict_types=1);

namespace Foundry\Tests;

use DateTimeImmutable;
use Foundry\Oracle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Testele fara server ruleaza mereu; functiile interne care construiesc SQL-ul sunt verificate
 * prin reflexie, pentru ca nu exista un server Oracle de test.
 * Testele de integrare ruleaza doar daca sunt setate ORACLE_TEST_CONNECTION, ORACLE_TEST_USERNAME,
 * ORACLE_TEST_PASSWORD; ele creeaza si sterg tabela UTILITIES_TEST_USERS si secventa ei.
 */
final class OracleTest extends TestCase
{
    // fara server

    public function test_constructor_validation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Oracle::__construct(): connection must not be empty.');

        new Oracle('', 'user', 'secret');
    }

    public function test_quote_identifier_uppercases_and_limits_length(): void
    {
        $db = $this->unreachable();

        $this->assertSame('"USERS"', $db->quoteIdentifier('users'));
        $this->assertSame('"APP"."USER_ROLES"', $db->quoteIdentifier('app.user_roles'));
        $this->assertSame('"ORDERS$HIST"', $db->quoteIdentifier('orders$hist'));
        $this->assertSame('"'.str_repeat('A', 30).'"', $db->quoteIdentifier(str_repeat('a', 30)));

        foreach ([str_repeat('a', 31), '_users', 'us"ers', 'users; DROP TABLE x', ''] as $invalid) {
            try {
                $db->quoteIdentifier($invalid);
                $this->fail("Expected InvalidArgumentException for '{$invalid}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('at most 30 characters', $e->getMessage());
            }
        }
    }

    public function test_where_in_splits_lists_over_1000(): void
    {
        $db = $this->unreachable();

        $this->assertSame(['"ID" IN (?, ?)', [1, 2]], $db->whereIn('id', [1, 2]));
        $this->assertSame(['1 = 0', []], $db->whereIn('id', []));

        [$sql, $params] = $db->whereIn('id', range(1, 2500));

        $this->assertCount(2500, $params);
        $this->assertSame(3, substr_count($sql, '"ID" IN ('));
        $this->assertSame(2, substr_count($sql, ' OR '));
        $this->assertStringStartsWith('("ID" IN (?, ?', $sql);
        $this->assertSame(2500, substr_count($sql, '?'));
    }

    public function test_validation_happens_before_connecting(): void
    {
        $db = $this->unreachable();

        foreach ([
            static function () use ($db): void {
                $db->insert('users', []);
            },
            static fn (): int => $db->update('users', ['name' => 'x'], []),
            static fn (): int => $db->delete('users', []),
            static fn (): int => $db->delete('a.b.c d', ['id' => 1]),
            static fn (): array => $db->fetchAll('SELECT * FROM t WHERE a = ? AND b = ?', [1]),
            static fn (): array => $db->fetchAll('SELECT * FROM t WHERE a = :a', ['a' => [1]]),
            static fn (): array => $db->paginate('SELECT 1 FROM dual', [], 0, 10),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('Oracle::', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string, array<string, mixed>}>
     */
    public static function positionalProvider(): iterable
    {
        yield 'simplu' => [
            'SELECT * FROM t WHERE a = ? AND b = ?', [1, 'x'],
            'SELECT * FROM t WHERE a = :p1 AND b = :p2', ['p1' => 1, 'p2' => 'x'],
        ];
        yield 'semn intrebare in text' => [
            "SELECT 'Ce? Unde?' FROM t WHERE a = ?", [5],
            "SELECT 'Ce? Unde?' FROM t WHERE a = :p1", ['p1' => 5],
        ];
        yield 'ghilimele duble si apostrof dublat' => [
            'SELECT "A?B", \'it\'\'s ?\' FROM t WHERE x = ?', [true],
            'SELECT "A?B", \'it\'\'s ?\' FROM t WHERE x = :p1', ['p1' => 1],
        ];
        yield 'comentarii' => [
            "SELECT 1 -- de ce?\nFROM t /* a = ? */ WHERE b = ?", [null],
            "SELECT 1 -- de ce?\nFROM t /* a = ? */ WHERE b = :p1", ['p1' => null],
        ];
        yield 'data' => [
            'INSERT INTO t (d) VALUES (?)', [new DateTimeImmutable('2026-03-15 10:20:30')],
            'INSERT INTO t (d) VALUES (:p1)', ['p1' => '2026-03-15 10:20:30'],
        ];
    }

    /**
     * @param  list<mixed>  $params
     * @param  array<string, mixed>  $expectedBinds
     */
    #[DataProvider('positionalProvider')]
    public function test_positional_parameters_become_named(string $sql, array $params, string $expectedSql, array $expectedBinds): void
    {
        $this->assertSame([$expectedSql, $expectedBinds], (new ReflectionMethod(Oracle::class, 'namedBinds'))->invoke($this->unreachable(), 'fetchAll', $sql, $params));
    }

    public function test_named_parameters_are_kept(): void
    {
        $result = (new ReflectionMethod(Oracle::class, 'namedBinds'))->invoke($this->unreachable(), 'fetchAll', 'SELECT * FROM t WHERE a = :a AND b = :b', [':a' => 1, 'b' => 2.5]);

        $this->assertSame(['SELECT * FROM t WHERE a = :a AND b = :b', ['a' => 1, 'b' => 2.5]], $result);
    }

    public function test_pagination_sql_uses_rownum(): void
    {
        $sql = (new ReflectionMethod(Oracle::class, 'paginationSql'))->invoke($this->unreachable(), 'SELECT * FROM users ORDER BY name', 20, 10);

        $this->assertSame(
            'SELECT * FROM (SELECT paginate_inner.*, ROWNUM PAGINATE_RN FROM (SELECT * FROM users ORDER BY name) paginate_inner WHERE ROWNUM <= 30) WHERE PAGINATE_RN > 20',
            $sql,
        );
    }

    public function test_number_normalization(): void
    {
        $normalize = new ReflectionMethod(Oracle::class, 'normalizeNumber');

        $this->assertSame(42, $normalize->invoke($this->unreachable(), '42'));
        $this->assertSame(-7, $normalize->invoke($this->unreachable(), '-7'));
        $this->assertSame('0.5', $normalize->invoke($this->unreachable(), '.5'));
        $this->assertSame('-0.25', $normalize->invoke($this->unreachable(), '-.25'));
        $this->assertSame('12.5', $normalize->invoke($this->unreachable(), '12.5'));
        $this->assertSame('99999999999999999999', $normalize->invoke($this->unreachable(), '99999999999999999999'), 'peste PHP_INT_MAX ramane string');
    }

    public function test_insert_all_fragment(): void
    {
        $into = (new ReflectionMethod(Oracle::class, 'insertAllInto'))->invoke($this->unreachable(), 'insertMany', 'users', ['name', 'email']);

        $this->assertSame('INTO "USERS" ("NAME", "EMAIL") VALUES (?, ?)', $into);
    }

    public function test_insert_many_validates_before_connecting(): void
    {
        $db = $this->unreachable();

        try {
            $db->insertMany('users', [['a' => 1], ['b' => 2]]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Oracle::insertMany(): row #1 does not have the same columns as the first row.', $e->getMessage());
        }

        $this->assertSame(0, $db->insertMany('users', []));
    }

    public function test_connection_failure_message_has_no_password(): void
    {
        $db = $this->unreachable();

        try {
            $db->connect();
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith("Oracle::connect(): cannot connect to '//127.0.0.1:1/XE':", $e->getMessage());
            $this->assertStringNotContainsString('parola-secreta', $e->getMessage());
            $this->assertStringNotContainsString('parola-secreta', $e->getTraceAsString());
        }
    }

    // integrare (server real)

    public function test_integration_crud_types_and_transactions(): void
    {
        $db = $this->server();

        $id = $db->insertGetId('utilities_test_users', [
            'id' => $db->nextVal('utilities_test_users_seq'),
            'name' => 'Ion',
            'score' => '0.5',
            'created_at' => new DateTimeImmutable('2026-03-15 10:20:30'),
            'notes' => str_repeat('x', 5000),
        ]);

        $this->assertIsInt($id);
        $this->assertSame(
            ['id' => $id, 'name' => 'Ion', 'score' => '0.5', 'created_at' => '2026-03-15 10:20:30', 'notes' => str_repeat('x', 5000)],
            $db->fetchOneOrFail('SELECT id, name, score, created_at, notes FROM utilities_test_users WHERE id = ?', [$id]),
        );
        $this->assertSame(1, $db->update('utilities_test_users', ['name' => 'Ion Pop'], ['id' => $id]));
        $this->assertSame([$id => 'Ion Pop'], $db->fetchPairs('SELECT id, name FROM utilities_test_users WHERE id = :id', ['id' => $id]));

        try {
            $db->transaction(static function (Oracle $db): void {
                $db->insert('utilities_test_users', ['id' => $db->nextVal('utilities_test_users_seq'), 'name' => 'Eva']);
                $db->execute('INSERT INTO missing_table VALUES (1)');
            });
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ORA-00942', $e->getMessage());
        }
        $this->assertSame(1, $db->fetchValue('SELECT COUNT(*) FROM utilities_test_users'), 'rollback');

        for ($i = 1; $i <= 24; $i++) {
            $db->insert('utilities_test_users', ['id' => $db->nextVal('utilities_test_users_seq'), 'name' => sprintf('U%02d', $i)]);
        }
        $page = $db->paginate('SELECT name FROM utilities_test_users ORDER BY name', [], 3, 10);

        $this->assertSame(25, $page['total']);
        $this->assertSame([['name' => 'U21'], ['name' => 'U22'], ['name' => 'U23'], ['name' => 'U24']], array_slice($page['items'], 0, 4));
        $this->assertSame(2, $db->delete('utilities_test_users', ['name' => ['U01', 'U02']]));
    }

    public function test_integration_insert_many_and_cursor(): void
    {
        $db = $this->server();

        $rows = array_map(static fn (int $i): array => ['id' => $i, 'name' => "U{$i}", 'score' => '0.5'], range(1, 1200));
        $this->assertSame(1200, $db->insertMany('utilities_test_users', $rows));

        $count = 0;
        foreach ($db->cursor('SELECT id, score FROM utilities_test_users ORDER BY id') as $row) {
            $this->assertSame(['id' => $count + 1, 'score' => '0.5'], $row);
            $count++;
        }
        $this->assertSame(1200, $count);

        $rows[1100]['id'] = 1; // cheie primara duplicata in al treilea INSERT ALL
        try {
            $db->insertMany('utilities_test_users', $rows);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ORA-00001', $e->getMessage());
        }
        $this->assertSame(1200, $db->fetchValue('SELECT COUNT(*) FROM utilities_test_users'), 'tot sau nimic');
    }

    private function unreachable(): Oracle
    {
        return new Oracle(connection: '//127.0.0.1:1/XE', username: 'user', password: 'parola-secreta');
    }

    /**
     * Conexiunea la serverul de test, cu tabela si secventa recreate; testul este sarit fara server.
     */
    private function server(): Oracle
    {
        $connection = getenv('ORACLE_TEST_CONNECTION');
        $username = getenv('ORACLE_TEST_USERNAME');
        $password = getenv('ORACLE_TEST_PASSWORD');

        if (! is_string($connection) || ! is_string($username) || ! is_string($password)) {
            self::markTestSkipped('Oracle server not configured (ORACLE_TEST_* environment variables).');
        }

        $db = new Oracle($connection, $username, $password);
        foreach (['DROP TABLE utilities_test_users', 'DROP SEQUENCE utilities_test_users_seq'] as $drop) {
            try {
                $db->execute($drop);
            } catch (RuntimeException) {
                // Nu exista inca.
            }
        }
        $db->execute('CREATE TABLE utilities_test_users (id NUMBER(10) PRIMARY KEY, name VARCHAR2(50) NOT NULL, score NUMBER(10, 2), created_at DATE, notes CLOB)');
        $db->execute('CREATE SEQUENCE utilities_test_users_seq');

        return $db;
    }
}
