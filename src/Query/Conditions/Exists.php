<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;
use Quillstack\Db\Query\Query;

/**
 * Whether another query finds anything.
 *
 * It writes itself into the same bindings as the query around it: two of them each numbering
 * their own placeholders from zero would give the same name to different values.
 */
class Exists implements Condition
{
    public function __construct(
        private readonly Query $query,
        private readonly bool $negated = false,
        private readonly string $boolean = 'AND'
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function toSql(Dialect $dialect, Bindings $bindings): string
    {
        $exists = $this->negated ? 'NOT EXISTS' : 'EXISTS';

        return "{$exists} (" . $this->query->compile($dialect, $bindings) . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
