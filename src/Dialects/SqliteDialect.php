<?php

declare(strict_types=1);

namespace Quillstack\Db\Dialects;

use Quillstack\Db\Dialect;

class SqliteDialect implements Dialect
{
    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        // A name holding a quote would close the quoting and let the rest be read as SQL.
        // Doubling it is what SQL says to do; a name still has to come from the application.
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * {@inheritDoc}
     */
    public function limitClause(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        // SQLite will not take an offset without a limit, so an absent one is said as -1.
        $limit ??= -1;
        $clause = " LIMIT {$limit}";

        return $offset === null ? $clause : $clause . " OFFSET {$offset}";
    }

    /**
     * {@inheritDoc}
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     *
     * SQLite takes 999 by default, and older builds less; this leaves room.
     */
    public function maximumBindings(): int
    {
        return 900;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'sqlite';
    }
}
