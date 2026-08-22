<?php

declare(strict_types=1);

namespace Quillstack\Db\Tests\Unit;

use Quillstack\Db\Connection;
use Quillstack\Db\Dialects\SqliteDialect;
use Quillstack\Db\Exceptions\QueryFailedException;
use Quillstack\Db\Exceptions\TransactionException;
use Quillstack\Db\Tests\Mocks\InMemoryDatabase;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;
use Quillstack\UnitTests\Types\AssertObject;
use RuntimeException;

class TestConnection
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertObject $assertObject,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    /**
     * Building a connection does not open one, so a request never touching the database
     * pays nothing for having one configured.
     */
    public function nothingIsOpenedUntilSomethingIsAsked()
    {
        $connection = new Connection('sqlite:/nowhere/at/all/db.sqlite');

        // Building it is fine; asking anything of it is what fails.
        $this->assertExceptions->expect(QueryFailedException::class);

        $connection->select('SELECT 1');
    }

    public function theDialectComesFromTheDriver()
    {
        $this->assertObject->instanceOf(
            SqliteDialect::class,
            InMemoryDatabase::connection()->dialect()
        );
    }

    public function workInsideATransactionIsKept()
    {
        $connection = InMemoryDatabase::connection();

        $connection->transaction(static function (Connection $db): void {
            $db->table('users')->insert(['email' => 'ada@example.com']);
        });

        $this->assertEqual->equal(1, $connection->table('users')->count());
    }

    /**
     * A transaction which throws leaves nothing behind, and the exception carries on rather
     * than being swallowed by the rollback.
     */
    public function workIsUndoneWhenItThrows()
    {
        $connection = InMemoryDatabase::connection();
        $thrown = false;

        try {
            $connection->transaction(static function (Connection $db): void {
                $db->table('users')->insert(['email' => 'ada@example.com']);

                throw new RuntimeException('no');
            });
        } catch (RuntimeException) {
            $thrown = true;
        }

        $this->assertBoolean->isTrue($thrown);
        $this->assertEqual->equal(0, $connection->table('users')->count());
        $this->assertEqual->equal(0, $connection->transactionDepth());
    }

    /**
     * Nesting works through savepoints: an inner failure undoes its own work and leaves the
     * outer transaction to carry on, rather than throwing everything away.
     */
    public function anInnerFailureLeavesTheOuterWorkAlone()
    {
        $connection = InMemoryDatabase::connection();

        $connection->transaction(static function (Connection $db): void {
            $db->table('users')->insert(['email' => 'ada@example.com']);

            try {
                $db->transaction(static function (Connection $inner): void {
                    $inner->table('users')->insert(['email' => 'nobody@example.com']);

                    throw new RuntimeException('no');
                });
            } catch (RuntimeException) {
                // The outer transaction carries on, which is the point.
            }

            $db->table('users')->insert(['email' => 'grace@example.com']);
        });

        $this->assertEqual->equal(
            ['ada@example.com', 'grace@example.com'],
            $connection->table('users')->orderBy('id')->pluck('email')
        );
    }

    public function committingWithoutATransactionSaysSo()
    {
        $this->assertExceptions->expect(TransactionException::class);

        InMemoryDatabase::connection()->commit();
    }
}
