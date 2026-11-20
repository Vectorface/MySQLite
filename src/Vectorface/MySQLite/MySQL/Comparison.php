<?php

namespace Vectorface\MySQLite\MySQL;

use InvalidArgumentException;

/**
 * Provides Comparison MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/comparison-operators.html
 */
trait Comparison
{
    /**
     * LEAST - Return the smallest argument
     *
     * Arguments are compared as MariaDB does:
     * - If any argument is NULL, the result is NULL.
     * - If every argument is a string, they are compared as strings, ignoring case and trailing spaces like MariaDB's
     *   default collations (but not accents). E.g. LEAST('10', '9') is '10', and LEAST('b', 'A') is 'A'.
     * - Otherwise they are compared as numbers, and the result is a number. Strings are read as numbers the way MySQL
     *   reads them, so LEAST(10, '9') is 9, and LEAST(5, 'abc') is 0.
     * When arguments are equal, the first is returned.
     *
     * MySQL 8 differs from MariaDB only when numbers and strings are mixed, which it compares as strings.
     *
     * @param mixed ...$args Two or more values.
     * @return mixed The smallest argument, or NULL if any argument is NULL.
     */
    public static function mysql_least(...$args)
    {
        if (count($args) < 2) {
            throw new InvalidArgumentException("Incorrect parameter count in the call to native function 'LEAST'");
        }
        if (in_array(null, $args, true)) {
            return null;
        }

        $strings = count(array_filter($args, 'is_string')) === count($args);
        $keys = array_map(fn($arg) => $strings ? static::stringSortKey($arg) : static::toNumber($arg), $args);

        $least = 0;
        foreach ($keys as $i => $key) {
            if ($strings ? strcmp($key, $keys[$least]) < 0 : $key < $keys[$least]) {
                $least = $i;
            }
        }
        return $strings ? $args[$least] : $keys[$least];
    }

    /**
     * Convert a value to a number the way MySQL does, reading a string's leading number and ignoring the rest.
     *
     * @param mixed $value The value to convert.
     * @return int|float The number, or 0 if a string doesn't start with a number.
     */
    private static function toNumber($value)
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $pattern = '/^\s*[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/';
        return preg_match($pattern, (string)$value, $m) ? trim($m[0]) + 0 : 0;
    }

    /**
     * Get a string's sort key for a case-insensitive comparison that ignores trailing spaces, like MariaDB's
     * default collations.
     *
     * @param string $value The string.
     * @return string The sort key, to be compared with strcmp.
     */
    private static function stringSortKey($value)
    {
        $value = rtrim($value, ' ');
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
