<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;
use Quillstack\Db\Query\Name;

class NullCheck implements Condition
{
    public function __construct(
        private readonly string $column,
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
        $is = $this->negated ? 'IS NOT NULL' : 'IS NULL';

        return Name::qualify($dialect, $this->column) . " {$is}";
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
