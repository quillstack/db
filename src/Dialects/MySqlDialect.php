<?php

declare(strict_types=1);

namespace Quillstack\Db\Dialects;

use Quillstack\Db\Dialect;

class MySqlDialect implements Dialect
{
    /**
     * {@inheritDoc}
     *
     * MySQL reports the first of the batch.
     */
    public function reportsFirstOfBatch(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * {@inheritDoc}
     */
    public function limitClause(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        // MySQL has no way to offset without a limit either, and takes the largest count it
        // can rather than a negative one.
        $limit ??= PHP_INT_MAX;
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
     * MySQL's limit is the size of the packet rather than a count, and 65535
     * placeholders is where the protocol itself stops.
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
        return 'mysql';
    }
}
