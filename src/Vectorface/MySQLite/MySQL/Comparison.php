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
     * Strings are compared case-insensitively, ignoring trailing spaces. Mixed types are compared as numbers, like
     * MariaDB (MySQL 8 compares them as strings).
     *
     * @param mixed ...$args Two or more values.
     * @return mixed The smallest argument, or NULL if any is NULL.
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
     * Read a value as a number like MySQL: a string's leading number, or 0.
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
     * Sort key for comparing strings case-insensitively, ignoring trailing spaces.
     */
    private static function stringSortKey($value)
    {
        $value = rtrim($value, ' ');
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
