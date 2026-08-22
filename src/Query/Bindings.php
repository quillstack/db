<?php

declare(strict_types=1);

namespace Quillstack\Db\Query;

use BackedEnum;
use DateTimeInterface;

/**
 * Collects the values a query binds, giving each a name of its own.
 *
 * Nothing here is ever written into the SQL. The compiler asks for a placeholder and puts
 * that in the string, so a value cannot become part of the statement whatever it holds.
 */
class Bindings
{
    /**
     * @var array<string, scalar|null>
     */
    private array $values = [];

    /**
     * Takes a value and returns the placeholder standing for it.
     */
    public function add(mixed $value): string
    {
        $name = 'p' . count($this->values);
        $this->values[$name] = self::normalise($value);

        return ':' . $name;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * PDO binds what it is given as a string unless told otherwise, and `false` becomes the
     * empty string rather than zero — a comparison against it then quietly matches nothing.
     * Everything is brought to something a database understands before it gets that far.
     */
    public static function normalise(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => (int) $value,
            is_scalar($value), $value === null => $value,
            default => (string) (is_object($value) && method_exists($value, '__toString')
                ? $value
                : json_encode($value)),
        };
    }
}
