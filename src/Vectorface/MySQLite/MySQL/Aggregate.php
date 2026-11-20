<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides Aggregate (Group By) MySQL compatibility functions for SQLite.
 *
 * Aggregates need step and final callbacks, so they're listed in getAggregateFunctions rather than discovered.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/group-by-functions.html
 */
trait Aggregate
{
    /**
     * @return array[] Function names mapped to [step method, final method, argument count].
     */
    protected static function getAggregateFunctions()
    {
        return [
            'bit_or' => ['bitOrStep', 'bitOrFinal', 1],
        ];
    }

    /**
     * BIT_OR step - OR in one row's value, ignoring NULL.
     *
     * @param int|null $result The result so far, or NULL before the first value.
     * @param int $rowNumber Unused.
     * @param mixed $value This row's value.
     * @return int|null The updated result.
     */
    public static function bitOrStep($result, $rowNumber, $value)
    {
        if ($value === null) {
            return $result;
        }
        return ($result ?? 0) | (is_int($value) ? $value : (int)round((float)$value));
    }

    /**
     * BIT_OR - Return the bitwise OR of the group's values, or 0 if none
     *
     * Values with the top bit set come back negative, as SQLite integers are signed.
     *
     * @param int|null $result The result from the last step.
     * @param int $rowNumber Unused.
     * @return int
     */
    public static function bitOrFinal($result, $rowNumber)
    {
        return $result ?? 0;
    }
}
