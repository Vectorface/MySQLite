<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides Aggregate (Group By) MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/group-by-functions.html
 */
trait Aggregate
{
    /**
     * BIT_OR - Return bitwise or
     *
     * @param int ... Values to which bitwise OR should be applied.
     * @return int The value of all input arguments bitwise OR'ed together.
     */
    public static function mysql_bit_or()
    {
        $result = 0;
        foreach (func_get_args() as $arg) {
            $result |= $arg;
        }
        return $result;
    }
}
