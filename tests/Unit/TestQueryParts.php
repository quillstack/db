<?php

declare(strict_types=1);

namespace Quillstack\Db\Tests\Unit;

use Quillstack\Db\Exceptions\DbException;
use Quillstack\Db\Query\Expression;
use Quillstack\Db\Tests\Mocks\InMemoryDatabase;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;

class TestQueryParts
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    public function distinctGroupingAndHaving()
    {
        $connection = InMemoryDatabase::withRows();
        $rows = $connection->table('posts')
            ->select('user_id', new Expression('COUNT(*) AS total'))
            ->groupBy('user_id')
            ->having(new Expression('COUNT(*)'), '>', 1)
            ->get();

        $this->assertEqual->equal([['user_id' => 1, 'total' => 2]], $rows);
    }

    public function selectingTheSameValueOnlyOnce()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            [1, 2],
            $connection->table('posts')->distinct()->orderBy('user_id')->pluck('user_id')
        );
    }

    public function aLeftJoinKeepsRowsWithNothingOnTheOtherSide()
    {
        $connection = InMemoryDatabase::withRows();
        $rows = $connection->table('users')
            ->select('users.email', 'posts.title')
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->orderBy('users.id')
            ->orderBy('posts.id')
            ->get();

        $this->assertEqual->equal(4, count($rows));
        $this->assertEqual->equal(['email' => 'alan@example.com', 'title' => null], $rows[3]);
    }

    public function excludingASet()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            ['grace@example.com'],
            $connection->table('users')->whereNotIn('id', [1, 3])->pluck('email')
        );
    }

    /**
     * Excluding nothing excludes nothing, rather than becoming `NOT IN ()`.
     */
    public function excludingAnEmptySetKeepsEverything()
    {
        $connection = InMemoryDatabase::withRows();
        $query = $connection->table('users')->whereNotIn('id', []);

        $this->assertEqual->equal('SELECT * FROM "users" WHERE 1 = 1', $query->toSql()['sql']);
        $this->assertEqual->equal(3, $query->count());
    }

    public function orWhereAtTheTopLevel()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            2,
            $connection->table('users')
                ->where('email', '=', 'ada@example.com')
                ->orWhere('email', '=', 'grace@example.com')
                ->count()
        );
    }

    public function anUnknownJoinTypeIsRefused()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->join('posts', 'a', '=', 'b', 'SIDEWAYS');
    }

    public function anUnknownDirectionIsRefused()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->orderBy('id', 'SIDEWAYS');
    }

    public function anUnknownJoinOperatorIsRefused()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->join('posts', 'a', 'LIKE', 'b');
    }

    public function insertingNothingSaysSo()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->insert([]);
    }

    public function updatingNothingSaysSo()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->update([]);
    }

    public function aNestedWhereHasToReturnItsQuery()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->where(static fn (): string => 'nonsense');
    }

    /**
     * Handing the whole list to `execute()` binds every value as text, and SQLite will not
     * convert one to compare it against a number with no column behind it: `COUNT(*) > '1'`
     * is false whatever the count. This passed for a while because a comparison against a
     * column borrows that column's type.
     */
    public function aNumberComparedWithoutAColumnIsStillANumber()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            [['user_id' => 1]],
            $connection->table('posts')
                ->select('user_id')
                ->groupBy('user_id')
                ->having(new Expression('COUNT(*)'), '>', 1)
                ->get()
        );
    }

    /**
     * A thousand rows should not be a thousand statements.
     */
    public function manyRowsGoInOneStatement()
    {
        $connection = InMemoryDatabase::connection();
        $rows = [];

        foreach (range(1, 50) as $i) {
            $rows[] = ['email' => "user{$i}@example.com", 'active' => 1, 'deleted_at' => null];
        }

        $before = $connection->queryCount();

        $this->assertEqual->equal(50, $connection->table('users')->insertMany($rows));
        $this->assertEqual->equal(1, $connection->queryCount() - $before);
        $this->assertEqual->equal(50, $connection->table('users')->count());
    }

    /**
     * A database will only bind so many values per statement, and finding that out at a
     * thousand rows is not the moment. SQLite stops at 999.
     */
    public function moreThanOneStatementWhereTheValuesNeedIt()
    {
        $connection = InMemoryDatabase::connection();
        $rows = [];

        foreach (range(1, 500) as $i) {
            $rows[] = ['email' => "user{$i}@example.com", 'active' => 1, 'deleted_at' => null];
        }

        $before = $connection->queryCount();

        $this->assertEqual->equal(500, $connection->table('users')->insertMany($rows));

        // 3 values a row, 900 to a statement: 300 rows, then 200.
        $this->assertEqual->equal(2, $connection->queryCount() - $before);
        $this->assertEqual->equal(500, $connection->table('users')->count());
    }

    public function writingNoRowsWritesNothing()
    {
        $connection = InMemoryDatabase::connection();
        $before = $connection->queryCount();

        $this->assertEqual->equal(0, $connection->table('users')->insertMany([]));
        $this->assertEqual->equal(0, $connection->queryCount() - $before);
    }

    /**
     * Every value is still bound, however many of them there are.
     */
    public function valuesAreStillBoundWhenThereAreMany()
    {
        $connection = InMemoryDatabase::connection();
        $connection->table('users')->insertMany([
            ['email' => "' OR 1=1 --", 'active' => 1, 'deleted_at' => null],
            ['email' => 'grace@example.com', 'active' => 1, 'deleted_at' => null],
        ]);

        $this->assertEqual->equal(2, $connection->table('users')->count());
        $this->assertEqual->equal(
            ["' OR 1=1 --"],
            $connection->table('users')->where('email', 'LIKE', "'%")->pluck('email')
        );
    }

    /**
     * A query inside another one shares its bindings: two of them each numbering their own
     * placeholders from zero would give the same name to different values, and the second
     * would quietly win.
     */
    public function aQueryInsideAnotherSharesItsBindings()
    {
        $connection = InMemoryDatabase::withRows();

        $posts = $connection->table('posts')
            ->select(new Expression('1'))
            ->whereColumn('posts.user_id', '=', 'users.id')
            ->where('title', '=', 'Third');

        $query = $connection->table('users')
            ->where('active', '=', true)
            ->whereExists($posts);

        ['sql' => $sql, 'bindings' => $bindings] = $query->toSql();

        $this->assertEqual->equal(
            'SELECT * FROM "users" WHERE "active" = :p0 AND EXISTS (SELECT 1 FROM "posts"'
            . ' WHERE "posts"."user_id" = "users"."id" AND "title" = :p1)',
            $sql
        );
        $this->assertEqual->equal(['p0' => 1, 'p1' => 'Third'], $bindings);
        $this->assertEqual->equal(['grace@example.com'], $query->pluck('email'));
    }

    public function keepingWhatAnotherQueryFindsNothingFor()
    {
        $connection = InMemoryDatabase::withRows();

        $withoutPosts = $connection->table('users')
            ->whereNotExists(
                $connection->table('posts')
                    ->select(new Expression('1'))
                    ->whereColumn('posts.user_id', '=', 'users.id')
            )
            ->orderBy('id');

        $this->assertEqual->equal(['alan@example.com'], $withoutPosts->pluck('email'));
    }

    public function comparingTwoColumns()
    {
        $connection = InMemoryDatabase::withRows();

        $this->assertEqual->equal(
            2,
            $connection->table('posts')->whereColumn('user_id', '<', 'id')->count()
        );
    }

    public function anUnknownOperatorBetweenColumnsIsRefused()
    {
        $this->assertExceptions->expect(DbException::class);

        InMemoryDatabase::connection()->table('users')->whereColumn('id', 'LIKE', 'email');
    }

    /**
     * Counting rows does not care what order they are in, and sorting a large table to throw
     * the order away is work nobody asked for.
     */
    public function countingDoesNotSort()
    {
        $connection = InMemoryDatabase::withRows();
        $connection->logQueries();

        $this->assertEqual->equal(3, $connection->table('users')->orderBy('email')->count());
        $this->assertEqual->equal(
            false,
            str_contains($connection->queryLog()[0]['sql'], 'ORDER BY')
        );
    }
}
