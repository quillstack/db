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
        $connection = new Connection('sqlite::memory:');

        $this->assertBoolean->isFalse($connection->isOpen());

        $connection->select('SELECT 1');

        $this->assertBoolean->isTrue($connection->isOpen());
    }

    /**
     * Building one against a database that is not there is still fine; asking anything of it
     * is what fails.
     */
    public function whatCannotBeOpenedSaysSoWhenItIsAsked()
    {
        $connection = new Connection('sqlite:/nowhere/at/all/db.sqlite');

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

    /**
     * Counting what was run is the only honest way to tell whether something asks the
     * database once or once per row, which is what the ORM above this is built to answer.
     */
    public function whatWasRunIsCounted()
    {
        $connection = InMemoryDatabase::connection();
        $before = $connection->queryCount();

        $connection->logQueries();
        $connection->table('users')->insert(['email' => 'ada@example.com']);
        $connection->table('users')->count();

        $this->assertEqual->equal(2, $connection->queryCount() - $before);
        $this->assertEqual->equal(2, count($connection->queryLog()));
        $this->assertEqual->equal(
            ['p0' => 'ada@example.com'],
            $connection->queryLog()[0]['bindings']
        );
    }
}
