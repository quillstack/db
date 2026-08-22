<?php

declare(strict_types=1);

namespace Quillstack\Db\Query\Conditions;

use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\DbException;
use Quillstack\Db\Query\Bindings;
use Quillstack\Db\Query\Condition;
use Quillstack\Db\Query\Name;

/**
 * Two columns compared with each other, which is how a query inside another one says which
 * rows it is about.
 */
class ColumnComparison implements Condition
{
    /**
     * Neither side is a value, so neither can be bound — which leaves refusing any operator
     * that is not one of these.
     *
     * @var string[]
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    public function __construct(
        private readonly string $first,
        private readonly string $operator,
        private readonly string $second,
        private readonly string $boolean = 'AND'
    ) {
        if (!in_array($this->operator, self::OPERATORS, true)) {
            throw new DbException("Unknown operator: {$this->operator}");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function toSql(Dialect $dialect, Bindings $bindings): string
    {
        return Name::qualify($dialect, $this->first)
            . " {$this->operator} "
            . Name::qualify($dialect, $this->second);
    }

    /**
     * {@inheritDoc}
     */
    public function boolean(): string
    {
        return $this->boolean;
    }
}
