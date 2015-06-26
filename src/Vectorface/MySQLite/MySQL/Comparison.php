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
     * @param mixed ... One or more numeric arguments.
     * @return mixed The argument whose value is considered lowest.
     */
    public static function mysql_least()
    {
        $args = func_get_args();
        if (!count($args)) {
            throw new InvalidArgumentException('No arguments provided to SQLite LEAST');
        }

        return min($args);
    }
}
