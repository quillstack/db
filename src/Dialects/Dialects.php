<?php

declare(strict_types=1);

namespace Quillstack\Db\Dialects;

use Quillstack\Db\Dialect;
use Quillstack\Db\Exceptions\UnknownDialectException;

/**
 * Which dialect answers for which driver. Kept apart from the connection so that adding a
 * database, and finding out one is missing, needs no database to be open.
 */
class Dialects
{
    /**
     * @var array<string, class-string<Dialect>>
     */
    private const DIALECTS = [
        'sqlite' => SqliteDialect::class,
        'mysql' => MySqlDialect::class,
        'pgsql' => PostgresDialect::class,
    ];

    public static function for(string $driver): Dialect
    {
        if (!isset(self::DIALECTS[$driver])) {
            throw new UnknownDialectException("No dialect for the `{$driver}` driver");
        }

        $class = self::DIALECTS[$driver];

        return new $class();
    }

    /**
     * @return string[]
     */
    public static function drivers(): array
    {
        return array_keys(self::DIALECTS);
    }
}
