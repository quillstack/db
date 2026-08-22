<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use Closure;
use Quillstack\Db\Connection;
use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\DbException;
use Quillstack\Db\Query\Conditions\ColumnComparison;
use Quillstack\Db\Query\Conditions\Comparison;
use Quillstack\Db\Query\Conditions\Exists;
use Quillstack\Db\Query\Conditions\Group;
use Quillstack\Db\Query\Conditions\InList;
use Quillstack\Db\Query\Conditions\NullCheck;

/**
 * Builds one statement and runs it.
 *
 * Every method returns a new query rather than changing this one, so a query can be handed
 * to something else, stored, or branched into two without either side surprising the other.
 */
class Query
{
    /**
     * @var array<int, string|Expression>
     */
    private array $columns = ['*'];

    private string $table = '';

    /**
     * @var Join[]
     */
    private array $joins = [];

    /**
     * @var Condition[]
     */
    private array $wheres = [];

    /**
     * @var string[]
     */
    private array $groups = [];

    /**
     * @var Condition[]
     */
    private array $havings = [];

    /**
     * @var Order[]
     */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $distinct = false;

    public function __construct(private readonly Connection $connection)
    {
        //
    }

    public function from(string $table): self
    {
        $query = clone $this;
        $query->table = $table;

        return $query;
    }

    public function select(string|Expression ...$columns): self
    {
        $query = clone $this;
        // Named arguments would give the list string keys, and the property is a list.
        $query->columns = $columns === [] ? ['*'] : array_values($columns);

        return $query;
    }

    public function distinct(): self
    {
        $query = clone $this;
        $query->distinct = true;

        return $query;
    }

    /**
     * A test on a column. Passing a closure instead opens a bracket, so `a AND (b OR c)` is
     * written as `where('a', ...)->where(fn ($q) => $q->where('b', ...)->orWhere('c', ...))`.
     */
    public function where(string|Closure|Expression $column, string $operator = '=', mixed $value = null, string $boolean = 'AND'): self
    {
        if ($column instanceof Closure) {
            return $this->addWhere(new Group($this->conditionsFrom($column), $boolean));
        }

        return $this->addWhere(new Comparison($column, $operator, $value, $boolean));
    }

    public function orWhere(string|Closure|Expression $column, string $operator = '=', mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'OR');
    }

    /**
     * `WHERE column IN (...)`. Fetching a set of rows this way is one query rather than one
     * per row, which is what the ORM above this builds on.
     *
     * @param array<int, mixed> $values
     */
    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->addWhere(new InList($column, $values, false, $boolean));
    }

    /**
     * @param array<int, mixed> $values
     */
    public function whereNotIn(string $column, array $values, string $boolean = 'AND'): self
    {
        return $this->addWhere(new InList($column, $values, true, $boolean));
    }

    /**
     * A test between two columns rather than against a value. Neither side can be bound, so
     * both are names and the operator is one of a known few.
     */
    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'AND'): self
    {
        return $this->addWhere(new ColumnComparison($first, $operator, $second, $boolean));
    }

    /**
     * Keeps the rows another query finds something for.
     */
    public function whereExists(self $query, string $boolean = 'AND'): self
    {
        return $this->addWhere(new Exists($query, false, $boolean));
    }

    public function whereNotExists(self $query, string $boolean = 'AND'): self
    {
        return $this->addWhere(new Exists($query, true, $boolean));
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        return $this->addWhere(new NullCheck($column, false, $boolean));
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        return $this->addWhere(new NullCheck($column, true, $boolean));
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'INNER'): self
    {
        $query = clone $this;
        $query->joins = [...$this->joins, new Join($table, $first, $operator, $second, $type)];

        return $query;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'LEFT');
    }

    public function groupBy(string ...$columns): self
    {
        $query = clone $this;
        $query->groups = [...$this->groups, ...$columns];

        return $query;
    }

    public function having(string|Expression $column, string $operator = '=', mixed $value = null, string $boolean = 'AND'): self
    {
        $query = clone $this;
        $query->havings = [...$this->havings, new Comparison($column, $operator, $value, $boolean)];

        return $query;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $query = clone $this;
        $query->orders = [...$this->orders, new Order($column, $direction)];

        return $query;
    }

    public function limit(?int $limit): self
    {
        $query = clone $this;
        $query->limit = $limit;

        return $query;
    }

    public function offset(?int $offset): self
    {
        $query = clone $this;
        $query->offset = $offset;

        return $query;
    }

    /**
     * The statement this query stands for, and the values it binds. Nothing runs; this is
     * what makes a query something you can look at, log, or assert on.
     *
     * @return array{sql: string, bindings: array<string, scalar|null>}
     */
    public function toSql(): array
    {
        $bindings = new Bindings();
        $sql = $this->compile($this->connection->dialect(), $bindings);

        return ['sql' => $sql, 'bindings' => $bindings->all()];
    }

    /**
     * Writes this query into an existing set of bindings.
     *
     * A query inside another one has to share them: two of them each numbering their own
     * placeholders from zero would give the same name to different values.
     */
    public function compile(Dialect $dialect, Bindings $bindings): string
    {
        if ($this->table === '') {
            throw new DbException('A query needs a table, call from() first');
        }

        $columns = implode(', ', array_map(
            static fn (string|Expression $column): string => Name::qualify($dialect, $column),
            $this->columns
        ));

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $columns
            . ' FROM ' . Name::qualify($dialect, $this->table);

        foreach ($this->joins as $join) {
            $sql .= ' ' . $join->toSql($dialect);
        }

        $sql .= $this->clause('WHERE', $this->wheres, $dialect, $bindings);

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(
                static fn (string $column): string => Name::qualify($dialect, $column),
                $this->groups
            ));
        }

        $sql .= $this->clause('HAVING', $this->havings, $dialect, $bindings);

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', array_map(
                static fn (Order $order): string => $order->toSql($dialect),
                $this->orders
            ));
        }

        return $sql . $dialect->limitClause($this->limit, $this->offset);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        ['sql' => $sql, 'bindings' => $bindings] = $this->toSql();

        return $this->connection->select($sql, $bindings);
    }

    /**
     * @return ?array<string, mixed>
     */
    public function first(): ?array
    {
        ['sql' => $sql, 'bindings' => $bindings] = $this->limit(1)->toSql();

        return $this->connection->selectOne($sql, $bindings);
    }

    /**
     * One column from every row, which is how a set of ids is read before fetching what
     * hangs off them.
     *
     * @return array<int, mixed>
     */
    public function pluck(string $column): array
    {
        return array_map(
            // One column was asked for, so whatever the database called it, it is the only
            // one in the row.
            static fn (array $row): mixed => reset($row) === false ? null : reset($row),
            $this->select($column)->get()
        );
    }

    public function count(string $column = '*'): int
    {
        $dialect = $this->connection->dialect();
        $name = Name::qualify($dialect, $column);

        // Counting rows does not care what order they are in, and sorting a large table to
        // throw the order away is work nobody asked for.
        $counting = clone $this;
        $counting->orders = [];

        $row = $counting->select(new Expression("COUNT({$name}) AS quillstack_count"))->first();
        $count = $row['quillstack_count'] ?? 0;

        return is_numeric($count) ? (int) $count : 0;
    }

    public function exists(): bool
    {
        return $this->limit(1)->first() !== null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insert(array $values): int
    {
        if ($values === []) {
            throw new DbException('Nothing to insert');
        }

        $dialect = $this->connection->dialect();
        $bindings = new Bindings();

        $columns = implode(', ', array_map(
            static fn (string $column): string => $dialect->quoteIdentifier($column),
            array_keys($values)
        ));
        $placeholders = implode(', ', array_map(
            static fn (mixed $value): string => $bindings->add($value),
            array_values($values)
        ));

        $table = Name::qualify($dialect, $this->table);

        return $this->connection->execute(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            $bindings->all()
        );
    }

    /**
     * Writes many rows in one statement rather than one each.
     *
     * Split into as many statements as the values need: a database will only bind so many
     * per statement, and finding that out at a thousand rows is not the moment.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return int how many rows were written
     */
    public function insertMany(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $dialect = $this->connection->dialect();
        $columns = array_keys($rows[0]);
        $perRow = max(1, count($columns));
        $written = 0;

        foreach (array_chunk($rows, max(1, intdiv($dialect->maximumBindings(), $perRow))) as $chunk) {
            $written += $this->insertChunk($dialect, $columns, $chunk);
        }

        return $written;
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertChunk(Dialect $dialect, array $columns, array $rows): int
    {
        $bindings = new Bindings();
        $names = implode(', ', array_map(
            static fn (string $column): string => $dialect->quoteIdentifier($column),
            $columns
        ));

        $tuples = [];

        foreach ($rows as $row) {
            $tuples[] = '(' . implode(', ', array_map(
                static fn (string $column): string => $bindings->add($row[$column] ?? null),
                $columns
            )) . ')';
        }

        $table = Name::qualify($dialect, $this->table);

        return $this->connection->execute(
            "INSERT INTO {$table} ({$names}) VALUES " . implode(', ', $tuples),
            $bindings->all()
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new DbException('Nothing to update');
        }

        $dialect = $this->connection->dialect();
        $bindings = new Bindings();

        $assignments = implode(', ', array_map(
            static fn (string $column): string =>
                $dialect->quoteIdentifier($column) . ' = ' . $bindings->add($values[$column]),
            array_keys($values)
        ));

        $table = Name::qualify($dialect, $this->table);
        $sql = "UPDATE {$table} SET {$assignments}"
            . $this->clause('WHERE', $this->wheres, $dialect, $bindings);

        return $this->connection->execute($sql, $bindings->all());
    }

    public function delete(): int
    {
        $dialect = $this->connection->dialect();
        $bindings = new Bindings();

        $table = Name::qualify($dialect, $this->table);
        $sql = "DELETE FROM {$table}"
            . $this->clause('WHERE', $this->wheres, $dialect, $bindings);

        return $this->connection->execute($sql, $bindings->all());
    }

    private function addWhere(Condition $condition): self
    {
        $query = clone $this;
        $query->wheres = [...$this->wheres, $condition];

        return $query;
    }

    /**
     * @return Condition[]
     */
    private function conditionsFrom(Closure $callback): array
    {
        $nested = $callback(new self($this->connection));

        if (!$nested instanceof self) {
            throw new DbException('A nested where has to return the query it was given');
        }

        return $nested->wheres;
    }

    /**
     * @param Condition[] $conditions
     */
    private function clause(string $keyword, array $conditions, Dialect $dialect, Bindings $bindings): string
    {
        if ($conditions === []) {
            return '';
        }

        $sql = '';

        foreach ($conditions as $index => $condition) {
            $sql .= $index === 0
                ? $condition->toSql($dialect, $bindings)
                : ' ' . $condition->boolean() . ' ' . $condition->toSql($dialect, $bindings);
        }

        return " {$keyword} {$sql}";
    }
}
