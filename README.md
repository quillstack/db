# Quillstack Db

[![Tests](https://github.com/quillstack/db/actions/workflows/tests.yml/badge.svg)](https://github.com/quillstack/db/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/quillstack/db.svg)](https://packagist.org/packages/quillstack/db)
[![Downloads](https://img.shields.io/packagist/dt/quillstack/db.svg)](https://packagist.org/packages/quillstack/db)
[![PHP Version](https://img.shields.io/packagist/php-v/quillstack/db)](https://packagist.org/packages/quillstack/db)
[![StyleCI](https://github.styleci.io/repos/1343163928/shield?branch=main)](https://github.styleci.io/repos/1343163928?branch=main)
[![CodeFactor](https://www.codefactor.io/repository/github/quillstack/db/badge)](https://www.codefactor.io/repository/github/quillstack/db)
[![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=quillstack_db&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=quillstack_db)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=quillstack_db&metric=coverage)](https://sonarcloud.io/summary/new_code?id=quillstack_db)
[![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_db&metric=sqale_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_db)
[![Reliability](https://sonarcloud.io/api/project_badges/measure?project=quillstack_db&metric=reliability_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_db)
[![Security](https://sonarcloud.io/api/project_badges/measure?project=quillstack_db&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=quillstack_db)
[![License](https://img.shields.io/packagist/l/quillstack/db)](https://github.com/quillstack/db/blob/main/LICENSE)

Connections and a query builder. The layer the ORM is built on, and useful on its own where
an ORM would be too much.

## Requirements

- PHP 8.1 or newer
- `ext-pdo`, and the driver for your database

## Installing

```shell
composer require quillstack/db
```

## A connection

Building one does not open it. A request which never asks the database anything pays nothing
for having one configured.

```php
use Quillstack\Db\Connection;

$db = new Connection('mysql:host=localhost;dbname=shop', 'user', 'secret');
```

SQLite, MySQL and PostgreSQL each have a dialect; the connection picks the right one from the
driver. A driver with no dialect says so rather than writing SQL that database will not read.

## Queries

Every method hands back a new query, so one can be branched, stored or passed on without
either side changing under the other.

```php
$users = $db->table('users')
    ->select('id', 'email')
    ->where('active', '=', true)
    ->whereNull('deleted_at')
    ->orderBy('id')
    ->limit(20)
    ->get();
```

`where()`, `orWhere()`, `whereIn()`, `whereNotIn()`, `whereNull()`, `whereNotNull()`,
`join()`, `leftJoin()`, `groupBy()`, `having()`, `orderBy()`, `limit()`, `offset()` and
`distinct()` build it; `get()`, `first()`, `pluck()`, `count()` and `exists()` run it;
`insert()`, `update()` and `delete()` write.

Brackets are written with a closure, so `a AND (b OR c)` means what it says:

```php
$db->table('users')
    ->where('active', '=', true)
    ->where(fn (Query $q) => $q->where('email', 'LIKE', 'a%')->orWhere('email', 'LIKE', 'g%'));
```

A whole set in one query, which is what the ORM above this builds on:

```php
$db->table('posts')->whereIn('user_id', [1, 2, 3])->get();
```

An empty set matches nothing rather than becoming `IN ()`, which is not SQL.

A query can ask another one a question. It shares the bindings of the one around it, because
two of them each numbering their own placeholders from zero would give the same name to
different values:

```php
$db->table('users')->whereExists(
    $db->table('posts')
        ->select(new Expression('1'))
        ->whereColumn('posts.user_id', '=', 'users.id')
);
```

`whereColumn()` compares two columns rather than a column against a value — neither side can
be bound, so both are names and the operator is one of a known few.

Many rows go in one statement rather than one each, split into as many as the values need —
a database will only bind so many per statement, and finding that out at a thousand rows is
not the moment:

```php
$db->table('users')->insertMany($rows);
```

## Values are bound, never written

No value reaches the statement. What goes into the SQL is a placeholder; the value travels
beside it, typed:

```php
$db->table('users')->where('email', '=', "' OR 1=1 --")->toSql();
// SELECT * FROM "users" WHERE "email" = :p0     ['p0' => "' OR 1=1 --"]
```

Operators, join types and directions cannot be bound, so anything not on a known list is
refused rather than passed through.

Values are bound with their own type. Handing a list to PDO's `execute()` binds every one of
them as text, and a database will not always convert: `COUNT(*) > '1'` is false in SQLite
whatever the count.

`toSql()` builds without running, so a query is something you can look at, log or assert on.

Where the builder has no words for something — an aggregate, a `CASE` — an `Expression` goes
in as written. It is the one place a string reaches the database unbound, and it looks like
it: what it holds is written by the application, never by a request.

```php
$db->table('posts')
    ->select('user_id', new Expression('COUNT(*) AS total'))
    ->groupBy('user_id')
    ->having(new Expression('COUNT(*)'), '>', 1)
    ->get();
```

## Transactions

Committed when the callback returns, rolled back when it throws — and the exception carries
on rather than being swallowed.

```php
$db->transaction(function (Connection $db) {
    $db->table('orders')->insert([...]);
    $db->table('stock')->where('id', '=', 7)->update([...]);
});
```

Nesting works. The inner ones become savepoints, so an inner failure undoes its own work and
leaves the outer transaction to carry on.

## Unit tests

```shell
composer test
```

The suite runs against a real SQLite database held in memory: building SQL can be checked by
reading it, but only running it says whether it works.

```shell
composer test:coverage
composer stan
```

## License

MIT. See [LICENSE](LICENSE).
