<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides Numeric MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/numeric-functions.html
 */
trait Numeric
{
    /**
     * SQRT - Return the square root of the argument
     *
     * @param mixed $value A numeric value for which a square root is to be calculated.
     * @return float The square root of the given numeric value.
     */
    public static function mysql_sqrt($value)
    {
        return sqrt($value);
    }

    /**
     * RAND - Returns a random floating-point
     * @return float
     */
    public static function mysql_rand()
    {
        return mt_rand() / mt_getrandmax();
    }
}
