<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

/**
 * A piece of SQL which goes into the statement as written, rather than being quoted as a
 * name. Aggregates and anything else the builder has no words for are said this way.
 *
 * What it holds is written by the application, never by a request: it is the one place in
 * this package where a string reaches the database unbound, and it looks like it.
 */
final class Expression
{
    public function __construct(public readonly string $sql)
    {
        //
    }

    public function __toString(): string
    {
        return $this->sql;
    }
}
