---
title: Databases
description: MySQL, Oracle and SQLite share one API - fetch helpers, array-based insert/update/delete, streaming cursors, transactions and safe pagination.
---

`MySQL`, `Oracle` and `SQLite` are thin, strict wrappers with an **identical public API**. Code written against one of them reads the same with the others; only the constructor changes.

## Connecting

```php
use Foundry\Env;
use Foundry\MySQL;
use Foundry\SQLite;

$db = new MySQL(
    host: Env::string('DB_HOST'),
    database: Env::string('DB_DATABASE'),
    username: Env::string('DB_USERNAME'),
    password: Env::string('DB_PASSWORD'),
);

$local = new SQLite(__DIR__.'/data/app.db');          // a missing file is an error, never silently created
```

Connections open lazily on the first query. Credentials have no defaults, and the password parameter is marked `#[SensitiveParameter]`, so it never appears in stack traces.

## The shared API

| Method | Purpose |
| --- | --- |
| `fetchAll()` | All rows as associative arrays. |
| `fetchOne()` | One row or `null`; fails if the query returns more than one row. |
| `fetchOneOrFail()` | One row, or an exception when there is none. |
| `fetchValue()` | The first column of a single row, for example `SELECT COUNT(*)`. |
| `fetchColumn()` | One column as a list. |
| `fetchPairs()` | Key / value pairs; duplicate keys are rejected instead of overwritten. |
| `execute()` | Any statement; returns the number of affected rows. |
| `insert()`, `insertGetId()` | Insert one row from an array. |
| `insertMany()` | Insert many rows, chunked, inside a single transaction. |
| `update()`, `delete()` | Array-based conditions; unconditional updates and deletes are rejected. |
| `cursor()` | A generator that streams rows one at a time. |
| `transaction()` | Runs a closure in a transaction; commits on success, rolls back on any exception. |
| `paginate()` | One page of results plus the total count. |
| `whereIn()` | Builds a safe `IN (...)` fragment with its parameters. |

## Queries and parameters

```php
$users = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
$user = $db->fetchOneOrFail('SELECT * FROM users WHERE id = :id', ['id' => $id]);

$id = $db->insertGetId('users', ['name' => 'Ion', 'email' => 'ion@example.com']);
$db->update('users', ['active' => 0], ['id' => $id]);

$db->transaction(function (MySQL $db) use ($order): void {
    $db->insert('orders', $order);
    $db->execute('UPDATE stock SET qty = qty - 1 WHERE product_id = ?', [$order['product_id']]);
});
```

Parameters are positional (`?`) or named (`:id`), never mixed. Table and column names passed to `insert()`, `update()` and `delete()` are validated and quoted. With MySQL, native prepared statements are used (no emulation), the connection is `utf8mb4`, and several statements in one call are rejected.

## Engine specifics

- **MySQL**: `INT` and `FLOAT` columns come back as `int` / `float`; `DECIMAL` stays a string to keep its precision.
- **Oracle** (oci8): ORA errors become exceptions; targets Oracle 11.2.0.4+ (`ROWNUM` pagination), with `nextVal()` for sequences.
- **SQLite**: the `foreign_keys` pragma is enabled; missing database files are never created implicitly.

## Pagination from the URL

`Paginator` reads the page, sort and search parameters from the query string and accepts only whitelisted columns, so they are safe to put into SQL. See the `Paginator` reference for the full API.
