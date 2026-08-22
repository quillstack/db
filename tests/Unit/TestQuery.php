<?php

declare(strict_types=1);

namespace Quillstack\Db\Tests\Unit;

use Quillstack\Db\Exceptions\DbException;
use Quillstack\Db\Query\Query;
use Quillstack\Db\Tests\Mocks\InMemoryDatabase;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestQuery
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    public function everythingFromATable()
    {
        $query = InMemoryDatabase::connection()->table('users');

        $this->assertEqual->equal('SELECT * FROM "users"', $query->toSql()['sql']);
    }

    /**
     * A value never reaches the statement. What goes in is a placeholder, and the value
     * travels beside it — which is what makes an injected value impossible rather than
     * unlikely.
     */
    public function valuesAreBoundAndNeverWritten()
    {
        $query = InMemoryDatabase::connection()
            ->table('users')
            ->where('email', '=', "' OR 1=1 --");

        ['sql' => $sql, 'bindings' => $bindings] = $query->toSql();

        $this->assertEqual->equal('SELECT * FROM "users" WHERE "email" = :p0', $sql);
        $this->assertEqual->equal(['p0' => "' OR 1=1 --"], $bindings);
    }

    /**
     * The same thing end to end: a value which would be SQL if it were written in finds
     * nothing, rather than everything.
     */
    public function anInjectedValueFindsNothing()
    {
        $rows = InMemoryDatabase::withRows()
            ->table('users')
            ->where('email', '=', "' OR '1'='1")
            ->get();

        $this->assertEqual->equal([], $rows);
    }

    public function anUnknownOperatorIsRefused()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->where('email', 'DROP TABLE', 'x');
    }

    /**
     * Every method hands back a new query, so one can be branched without either side
     * changing under the other.
     */
    public function aQueryIsNeverChangedInPlace()
    {
        $base = InMemoryDatabase::connection()->table('users');
        $active = $base->where('active', '=', true);

        $this->assertEqual->equal('SELECT * FROM "users"', $base->toSql()['sql']);
        $this->assertEqual->equal('SELECT * FROM "users" WHERE "active" = :p0', $active->toSql()['sql']);
        $this->assertBoolean->isFalse($base === $active);
    }

    /**
     * `false` bound as a string becomes the empty string, and a comparison against it
     * matches nothing. It goes to the database as a number instead.
     */
    public function aBooleanIsBoundAsANumber()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            ['ada@example.com', 'grace@example.com'],
            $connection->table('users')->where('active', '=', true)->orderBy('id')->pluck('email')
        );
        $this->assertEqual->equal(
            ['alan@example.com'],
            $connection->table('users')->where('active', '=', false)->pluck('email')
        );
    }

    public function aSetOfRowsInOneQuery()
    {
        $connection = InMemoryDatabase::withRows();
        $query = $connection->table('users')->whereIn('id', [1, 3]);

        $this->assertEqual->equal(
            'SELECT * FROM "users" WHERE "id" IN (:p0, :p1)',
            $query->toSql()['sql']
        );
        $this->assertEqual->equal(
            ['ada@example.com', 'alan@example.com'],
            $query->orderBy('id')->pluck('email')
        );
    }

    /**
     * `IN ()` is not SQL, and an empty set matches nothing — which is said as a condition
     * that is false rather than left to blow up.
     */
    public function anEmptySetMatchesNothing()
    {
        $connection = InMemoryDatabase::withRows();
        $query = $connection->table('users')->whereIn('id', []);

        $this->assertEqual->equal('SELECT * FROM "users" WHERE 1 = 0', $query->toSql()['sql']);
        $this->assertEqual->equal([], $query->get());
    }

    /**
     * Without brackets `a AND b OR c` is not what anybody writing it meant.
     */
    public function conditionsCanBeGrouped()
    {
        $query = InMemoryDatabase::connection()
            ->table('users')
            ->where('active', '=', true)
            ->where(static fn (Query $q): Query => $q
                ->where('email', 'LIKE', 'a%')
                ->orWhere('email', 'LIKE', 'g%'));

        $this->assertEqual->equal(
            'SELECT * FROM "users" WHERE "active" = :p0 AND ("email" LIKE :p1 OR "email" LIKE :p2)',
            $query->toSql()['sql']
        );
    }

    public function nullIsAskedAboutRatherThanComparedTo()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(2, $connection->table('users')->whereNull('deleted_at')->count());
        $this->assertEqual->equal(1, $connection->table('users')->whereNotNull('deleted_at')->count());
    }

    public function joiningTwoTables()
    {
        $connection = InMemoryDatabase::withRows();
        $rows = $connection->table('posts')
            ->select('posts.title', 'users.email')
            ->join('users', 'users.id', '=', 'posts.user_id')
            ->orderBy('posts.id')
            ->get();

        $this->assertEqual->equal(
            [
                ['title' => 'First', 'email' => 'ada@example.com'],
                ['title' => 'Second', 'email' => 'ada@example.com'],
                ['title' => 'Third', 'email' => 'grace@example.com'],
            ],
            $rows
        );
    }

    public function readingWritingAndRemoving()
    {
        $connection = InMemoryDatabase::connection();

        $this->assertEqual->equal(1, $connection->table('users')->insert(['email' => 'ada@example.com']));
        $this->assertEqual->equal('1', $connection->lastInsertId());

        $this->assertEqual->equal(
            1,
            $connection->table('users')->where('id', '=', 1)->update(['email' => 'ada2@example.com'])
        );
        $this->assertEqual->equal(
            'ada2@example.com',
            $connection->table('users')->where('id', '=', 1)->first()['email'] ?? null
        );

        $this->assertEqual->equal(1, $connection->table('users')->where('id', '=', 1)->delete());
        $this->assertBoolean->isFalse($connection->table('users')->exists());
    }

    public function orderingLimitingAndSkipping()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            ['grace@example.com'],
            $connection->table('users')->orderBy('id', 'DESC')->limit(1)->offset(1)->pluck('email')
        );
    }

    public function aQueryWithoutATableSaysSo()
    {
        $this->assertExceptions->expect(DbException::class);

        (new Query(InMemoryDatabase::connection()))->toSql();
    }
}
