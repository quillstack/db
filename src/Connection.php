<?php

declare(strict_types=1);

namespace Quillstack\Db;

use PDO;
use PDOException;
use PDOStatement;
use Quillstack\Db\Dialects\Dialects;
use Quillstack\Db\Exceptions\QueryFailedException;
use Quillstack\Db\Exceptions\TransactionException;
use Quillstack\Db\Query\Query;
use Throwable;

/**
 * One database, opened when something is first asked of it.
 *
 * Nothing here takes SQL with values written into it. Every value travels as a bound
 * parameter, which is what makes an injected value impossible rather than unlikely.
 */
class Connection
{
    private ?PDO $pdo = null;

    private ?Dialect $dialect = null;

    /**
     * How deep the current transaction is. PDO only knows about the outermost one, so the
     * ones inside it are kept here and done with savepoints.
     */
    private int $depth = 0;

    /**
     * How many statements have been run. Cheap enough to count always, and the only honest
     * way to tell whether something is asking the database once or once per row.
     */
    private int $queries = 0;

    /**
     * @var array<int, array{sql: string, bindings: array<string, mixed>}>
     */
    private array $log = [];

    private bool $logging = false;

    /**
     * @param array<int, mixed> $options
     */
    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly array $options = []
    ) {
        //
    }

    /**
     * Opens the connection if it is not open. Building a connection object does not talk to
     * the database, so a request which never asks anything of it pays nothing.
     */
    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        try {
            $this->pdo = new PDO($this->dsn, $this->username, $this->password, $this->options + [
                // Anything going wrong has to say so. Left to itself PDO returns false and
                // carries on, which turns a broken query into a wrong answer.
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Values are bound by the database rather than pasted in by the driver.
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new QueryFailedException(
                "Unable to connect: {$exception->getMessage()}",
                0,
                $exception
            );
        }

        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        if ($this->dialect !== null) {
            return $this->dialect;
        }

        return $this->dialect = Dialects::for($this->driverName());
    }

    /**
     * A query against one table, ready to be built up.
     */
    public function table(string $table): Query
    {
        return (new Query($this))->from($table);
    }

    /**
     * Runs a query and hands back the statement it ran.
     *
     * @param array<string, mixed> $bindings
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        ++$this->queries;

        if ($this->logging) {
            $this->log[] = ['sql' => $sql, 'bindings' => $bindings];
        }

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($bindings as $name => $value) {
                $statement->bindValue($name, $value, self::typeOf($value));
            }

            $statement->execute();

            return $statement;
        } catch (PDOException $exception) {
            throw new QueryFailedException(
                "{$exception->getMessage()} — running: {$sql}",
                0,
                $exception
            );
        }
    }

    /**
     * What PDO should call a value.
     *
     * Handing the whole list to `execute()` binds every one of them as text, and a database
     * comparing a number against text does not always convert it: `COUNT(*) > '1'` is false
     * in SQLite whatever the count. Saying the type is what makes a number compare as one.
     */
    private static function typeOf(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * Every row the query returns.
     *
     * @param array<string, mixed> $bindings
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * The first row, or null where there is none.
     *
     * @param array<string, mixed> $bindings
     *
     * @return ?array<string, mixed>
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->run($sql, $bindings)->fetch();

        /** @var ?array<string, mixed> $row */
        return is_array($row) ? $row : null;
    }

    /**
     * How many rows the statement changed.
     *
     * @param array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * How many statements this connection has run.
     */
    public function queryCount(): int
    {
        return $this->queries;
    }

    /**
     * Keeps what was run, for looking at afterwards. Off unless asked for: remembering every
     * statement of a long-running process is a way to run out of memory.
     */
    public function logQueries(bool $logging = true): void
    {
        $this->logging = $logging;
    }

    /**
     * @return array<int, array{sql: string, bindings: array<string, mixed>}>
     */
    public function queryLog(): array
    {
        return $this->log;
    }

    /**
     * The id given to the row just inserted.
     */
    public function lastInsertId(): string
    {
        return (string) $this->pdo()->lastInsertId();
    }

    /**
     * Runs the callback inside a transaction, committing when it returns and rolling back
     * when it throws.
     *
     * Nesting works: the inner ones become savepoints, so an inner failure undoes its own
     * work without throwing away the outer transaction.
     *
     * @template T
     *
     * @param callable(self): T $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->begin();

        try {
            $result = $work($this);
        } catch (Throwable $throwable) {
            $this->rollBack();

            throw $throwable;
        }

        $this->commit();

        return $result;
    }

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->run('SAVEPOINT ' . $this->savepoint($this->depth));
        }

        ++$this->depth;
    }

    public function commit(): void
    {
        if ($this->depth === 0) {
            throw new TransactionException('Nothing to commit, no transaction has begun');
        }

        --$this->depth;

        if ($this->depth === 0) {
            $this->pdo()->commit();

            return;
        }

        // Releasing a savepoint does not commit anything: the work stays in the transaction
        // it belongs to, which is the outermost one.
        $this->run('RELEASE SAVEPOINT ' . $this->savepoint($this->depth));
    }

    public function rollBack(): void
    {
        if ($this->depth === 0) {
            throw new TransactionException('Nothing to roll back, no transaction has begun');
        }

        --$this->depth;

        if ($this->depth === 0) {
            $this->pdo()->rollBack();

            return;
        }

        $this->run('ROLLBACK TO SAVEPOINT ' . $this->savepoint($this->depth));
    }

    /**
     * How deep the transaction currently is, zero meaning none.
     */
    public function transactionDepth(): int
    {
        return $this->depth;
    }

    private function savepoint(int $depth): string
    {
        return "quillstack_{$depth}";
    }

    private function driverName(): string
    {
        /** @var string $driver */
        $driver = $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $driver;
    }
}
