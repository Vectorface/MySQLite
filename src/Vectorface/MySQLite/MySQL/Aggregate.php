<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides Aggregate (Group By) MySQL compatibility functions for SQLite.
 *
 * Aggregates are listed in getAggregateFunctions rather than discovered by their mysql_ prefix, because SQLite needs
 * two callbacks for each: a step called for every row in the group, and a final called once to produce the result.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/group-by-functions.html
 */
trait Aggregate
{
    /**
     * Get the aggregate functions provided by this trait.
     *
     * @return array[] Function names mapped to [step method, final method, argument count].
     */
    protected static function getAggregateFunctions()
    {
        return [
            'bit_or' => ['bitOrStep', 'bitOrFinal', 1],
        ];
    }

    /**
     * BIT_OR step - OR one row's value into the running result. NULL values are ignored.
     *
     * @param int|null $result The result so far, or NULL before the first non-NULL value.
     * @param int $rowNumber The row number within the group (unused).
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
     * BIT_OR - Return the bitwise OR of all values in the group, or 0 if there are none.
     *
     * MySQL returns an unsigned 64-bit integer; values with the top bit set come back negative here, because SQLite
     * integers are signed.
     *
     * @param int|null $result The result from the last step.
     * @param int $rowNumber The number of rows in the group (unused).
     * @return int The bitwise OR of all non-NULL values.
     */
    public static function bitOrFinal($result, $rowNumber)
    {
        return $result ?? 0;
    }
}
