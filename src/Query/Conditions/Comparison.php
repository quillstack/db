<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\DbException;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;
use Quillstack\Db\Query\Expression;
use Quillstack\Db\Query\Name;

class Comparison implements Condition
{
    /**
     * Operators are not values, so they cannot be bound — which means the only safe thing to
     * do with one is to refuse any that is not on this list.
     *
     * @var string[]
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE'];

    public function __construct(
        private readonly string|Expression $column,
        private readonly string $operator,
        private readonly mixed $value,
        private readonly string $boolean = 'AND'
    ) {
        if (!in_array(strtoupper($this->operator), self::OPERATORS, true)) {
            throw new DbException("Unknown operator: {$this->operator}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function toSql(Dialect $dialect, Bindings $bindings): string
    {
        $operator = strtoupper($this->operator);
        $placeholder = $bindings->add($this->value);

        return Name::qualify($dialect, $this->column) . " {$operator} {$placeholder}";
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
