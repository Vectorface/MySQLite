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
     * @return float|null The square root of the given numeric value, or NULL if it is NULL or negative.
     */
    public static function mysql_sqrt($value)
    {
        return $value === null || $value < 0 ? null : sqrt($value);
    }

    /**
     * RAND - Return a random floating-point value v in the range 0 <= v < 1.0
     *
     * RAND(N) gives MySQL's first value for seed N. Unlike MySQL, a constant N doesn't continue its sequence on later
     * rows.
     *
     * @param int $seed An optional seed.
     * @return float
     */
    public static function mysql_rand($seed = null)
    {
        if (func_num_args() === 0) {
            return mt_rand() / (mt_getrandmax() + 1);
        }

        /* MySQL's randominit() and my_rnd(), in 32-bit unsigned arithmetic */
        $max = 0x3FFFFFFF;
        $seed = (is_int($seed) ? $seed : (int)round((float)$seed)) & 0xFFFFFFFF;
        $seed1 = (($seed * 0x10001 + 55555555) & 0xFFFFFFFF) % $max;
        $seed2 = (($seed * 0x10000001) & 0xFFFFFFFF) % $max;
        return (($seed1 * 3 + $seed2) % $max) / $max;
    }
}
