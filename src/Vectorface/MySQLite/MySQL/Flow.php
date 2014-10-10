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
     * @param bool $condition The boolean condition which determines which result should be returned.
     * @param mixed $onTrue The result to be returned if the condition is true.
     * @param mixed $onFalse The condition to be returned if the condition is false.
     * @return mixed Either the onTrue or onFalse result is returned, depending on the value of the condition.
     */
    public static function mysql_if($condition, $onTrue, $onFalse)
    {
        return $condition ? $onTrue : $onFalse;
    }
}
