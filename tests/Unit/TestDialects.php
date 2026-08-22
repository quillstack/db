<?php

declare(strict_types=1);

namespace Quillstack\Db\Tests\Unit;

use Quillstack\Db\Dialects\MySqlDialect;
use Quillstack\Db\Dialects\PostgresDialect;
use Quillstack\Db\Dialects\SqliteDialect;
use Quillstack\Db\Exceptions\UnknownDialectException;
use Quillstack\Db\Dialects\Dialects;
use Quillstack\UnitTests\AssertEqual;
use Quillstack\UnitTests\AssertExceptions;
use Quillstack\UnitTests\Types\AssertBoolean;

class TestDialects
{
    public function __construct(
        private AssertEqual $assertEqual,
        private AssertBoolean $assertBoolean,
        private AssertExceptions $assertExceptions
    ) {
        //
    }

    /**
     * A name holding the quote character would close the quoting and let the rest be read
     * as SQL. Names come from the application rather than from a request, but doubling it
     * is what makes that a guarantee instead of a hope.
     */
    public function aQuoteInsideANameCannotCloseIt()
    {
        $this->assertEqual->equal('"a""b"', (new SqliteDialect())->quoteIdentifier('a"b'));
        $this->assertEqual->equal('`a``b`', (new MySqlDialect())->quoteIdentifier('a`b'));
        $this->assertEqual->equal('"a""b"', (new PostgresDialect())->quoteIdentifier('a"b'));
    }

    /**
     * SQLite and MySQL will not take an offset without a limit; Postgres will. Each says so
     * in the way its own database understands.
     */
    public function anOffsetWithoutALimit()
    {
        $this->assertEqual->equal(' LIMIT -1 OFFSET 10', (new SqliteDialect())->limitClause(null, 10));
        $this->assertEqual->equal(' LIMIT ' . PHP_INT_MAX . ' OFFSET 10', (new MySqlDialect())->limitClause(null, 10));
        $this->assertEqual->equal(' OFFSET 10', (new PostgresDialect())->limitClause(null, 10));
    }

    public function neitherLimitNorOffsetSaysNothing()
    {
        $this->assertEqual->equal('', (new SqliteDialect())->limitClause(null, null));
        $this->assertEqual->equal('', (new MySqlDialect())->limitClause(null, null));
        $this->assertEqual->equal('', (new PostgresDialect())->limitClause(null, null));
    }

    public function aPlainLimit()
    {
        $this->assertEqual->equal(' LIMIT 5', (new SqliteDialect())->limitClause(5, null));
        $this->assertEqual->equal(' LIMIT 5', (new MySqlDialect())->limitClause(5, null));
        $this->assertEqual->equal(' LIMIT 5', (new PostgresDialect())->limitClause(5, null));
    }

    public function eachSaysWhatItIsAndWhatItCanDo()
    {
        $this->assertEqual->equal('sqlite', (new SqliteDialect())->name());
        $this->assertEqual->equal('mysql', (new MySqlDialect())->name());
        $this->assertEqual->equal('pgsql', (new PostgresDialect())->name());

        $this->assertBoolean->isFalse((new SqliteDialect())->supportsReturning());
        $this->assertBoolean->isFalse((new MySqlDialect())->supportsReturning());
        $this->assertBoolean->isTrue((new PostgresDialect())->supportsReturning());
    }

    /**
     * A driver nobody has written a dialect for has to say so, rather than quietly writing
     * SQL that database will not read. Asking needs no database to be open, which is why
     * the answer lives apart from the connection.
     */
    public function aDriverWithoutADialectSaysSo()
    {
        $this->assertExceptions->expect(UnknownDialectException::class);

        Dialects::for('odbc');
    }
}
