<?php

declare(strict_types=1);

namespace Quillstack\Db\Dialects;

use Quillstack\Db\Dialect;

class PostgresDialect implements Dialect
{
    /**
     * {@inheritDoc}
     *
     * Postgres reports the last, where a sequence is being read at all.
     */
    public function reportsFirstOfBatch(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * {@inheritDoc}
     */
    public function limitClause(?int $limit, ?int $offset): string
    {
        $clause = $limit === null ? '' : " LIMIT {$limit}";

        return $offset === null ? $clause : $clause . " OFFSET {$offset}";
    }

    /**
     * {@inheritDoc}
     */
    public function supportsReturning(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Postgres binds at most 65535 per statement.
     */
    public function maximumBindings(): int
    {
        return 60000;
    }

    /**
     * {@inheritDoc}
     */
    public function name(): string
    {
        return 'pgsql';
    }
}
