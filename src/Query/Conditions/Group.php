<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;

/**
 * Conditions in brackets, so `a AND (b OR c)` means what it says rather than what operator
 * precedence makes of it.
 */
class Group implements Condition
{
    /**
     * @param Condition[] $conditions
     */
    public function __construct(
        private readonly array $conditions,
        private readonly string $boolean = 'AND'
    ) {
        //
    }

    /**
     * {@inheritDoc}
     */
    public function toSql(Dialect $dialect, Bindings $bindings): string
    {
        $sql = '';

        foreach ($this->conditions as $index => $condition) {
            $sql .= $index === 0
                ? $condition->toSql($dialect, $bindings)
                : ' ' . $condition->boolean() . ' ' . $condition->toSql($dialect, $bindings);
        }

        return $sql === '' ? '1 = 1' : "({$sql})";
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
