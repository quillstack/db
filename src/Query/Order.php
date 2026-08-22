<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\DbException;

class Order
{
    public function __construct(
        private readonly string $column,
        private readonly string $direction = 'ASC'
    ) {
        if (!in_array(strtoupper($this->direction), ['ASC', 'DESC'], true)) {
            throw new DbException("Unknown direction: {$this->direction}");
        }
    }

    public function toSql(Dialect $dialect): string
    {
        return Name::qualify($dialect, $this->column) . ' ' . strtoupper($this->direction);
    }
}
