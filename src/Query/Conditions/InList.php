<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;
use Quillstack\Db\Query\Name;

/**
 * `WHERE id IN (...)`, which is how a set of rows is fetched in one query rather than one
 * query each.
 */
class InList implements Condition
{
    /**
     * @param array<int, mixed> $values
     */
    public function __construct(
        private readonly string $column,
        private readonly array $values,
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
        $column = Name::qualify($dialect, $this->column);

        if ($this->values === []) {
            // `IN ()` is not valid SQL, and an empty set matches nothing — which is what a
            // condition that is always false says, without a special case downstream.
            return $this->negated ? '1 = 1' : '1 = 0';
        }

        $placeholders = implode(', ', array_map(
            static fn (mixed $value): string => $bindings->add($value),
            array_values($this->values)
        ));

        $in = $this->negated ? 'NOT IN' : 'IN';

        return "{$column} {$in} ({$placeholders})";
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
