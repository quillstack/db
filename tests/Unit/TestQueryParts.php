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
}
