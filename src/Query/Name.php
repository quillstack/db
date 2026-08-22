<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use Quillstack\Db\Dialect;

/**
 * Turns the names an application writes into names a database reads.
 */
class Name
{
    /**
     * Quotes a column, keeping `table.column` in two pieces and leaving `*` alone.
     */
    public static function qualify(Dialect $dialect, string|Expression $name): string
    {
        // An expression is SQL already, so quoting it would turn it into a column name.
        if ($name instanceof Expression) {
            return $name->sql;
        }

        if ($name === '*') {
            return '*';
        }

        $parts = explode('.', $name);

        return implode('.', array_map(
            static fn (string $part): string => $part === '*' ? '*' : $dialect->quoteIdentifier($part),
            $parts
        ));
    }
}
