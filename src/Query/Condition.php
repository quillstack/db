<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use Quillstack\Db\Dialect;

/**
 * One test in a WHERE or HAVING clause. It writes itself as SQL and puts whatever values it
 * needs into the bindings rather than into the string.
 */
interface Condition
{
    public function toSql(Dialect $dialect, Bindings $bindings): string;

    /**
     * How this joins to the one before it: `AND` or `OR`.
     */
    public function boolean(): string;
}
