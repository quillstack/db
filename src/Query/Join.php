<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\DbException;

class Join
{
    /**
     * @var string[]
     */
    private const TYPES = ['INNER', 'LEFT', 'RIGHT', 'FULL'];

    public function __construct(
        private readonly string $table,
        private readonly string $first,
        private readonly string $operator,
        private readonly string $second,
        private readonly string $type = 'INNER'
    ) {
        if (!in_array(strtoupper($this->type), self::TYPES, true)) {
            throw new DbException("Unknown join type: {$this->type}");
        }

        // Both sides of the condition are columns, so neither can be bound; refusing an
        // operator that is not one of these is what keeps the string safe.
        if (!in_array($this->operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
            throw new DbException("Unknown operator: {$this->operator}");
        }
    }

    public function toSql(Dialect $dialect): string
    {
        $type = strtoupper($this->type);
        $table = Name::qualify($dialect, $this->table);
        $first = Name::qualify($dialect, $this->first);
        $second = Name::qualify($dialect, $this->second);

        return "{$type} JOIN {$table} ON {$first} {$this->operator} {$second}";
    }
}
