<?php

declare(strict_types=1);

namespace Quillstack\Db;

/**
 * What differs between one database and the next. Everything the query builder does that is
 * not plain SQL goes through here, so adding a database means adding one class rather than
 * finding every place that assumed MySQL.
 */
interface Dialect
{
    /**
     * Wraps a name so it cannot be read as a keyword. A name arrives from the application,
     * never from a request, but quoting it is what makes that guarantee visible.
     */
    public function quoteIdentifier(string $name): string;

    /**
     * The clause limiting how many rows come back, and where to start.
     */
    public function limitClause(?int $limit, ?int $offset): string;

    /**
     * Whether the database can be asked for the id it has just given a row.
     */
    public function supportsReturning(): bool;

    /**
     * Whether the id reported after writing many rows at once is the first of them rather
     * than the last. The ones between are consecutive either way, which is what a database
     * promises for a single statement.
     */
    public function reportsFirstOfBatch(): bool;

    /**
     * How many values one statement may bind. Writing a thousand rows at once is one
     * statement only where the database will take them.
     */
    public function maximumBindings(): int;

    /**
     * The name this dialect answers to, as PDO reports its driver.
     */
    public function name(): string;
}
