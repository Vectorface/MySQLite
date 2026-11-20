<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides Flow Control MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/control-flow-functions.html
 */
trait Flow
{
    /**
     * IF - If/else construct
     *
     * Like MySQL, the condition is true if non-zero and not NULL, reading strings as numbers ('abc' is false).
     *
     * @param mixed $condition The condition.
     * @param mixed $onTrue The result if the condition is true.
     * @param mixed $onFalse The result if the condition is false.
     * @return mixed $onTrue or $onFalse.
     */
    public static function mysql_if($condition, $onTrue, $onFalse)
    {
        return $condition !== null && (float)$condition != 0 ? $onTrue : $onFalse;
    }
}
