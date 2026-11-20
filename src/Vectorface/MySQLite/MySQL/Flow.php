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
     * Like MySQL, the condition is true if it is not NULL and not 0. Strings are read as numbers, so 'abc' and '0.0'
     * are false, while '1abc' and '0.5' are true.
     *
     * @param mixed $condition The condition which determines which result should be returned.
     * @param mixed $onTrue The result to be returned if the condition is true.
     * @param mixed $onFalse The result to be returned if the condition is false.
     * @return mixed Either the onTrue or onFalse result is returned, depending on the value of the condition.
     */
    public static function mysql_if($condition, $onTrue, $onFalse)
    {
        /* Casting a string to float reads its leading number, if any, as MySQL does. */
        return $condition !== null && (float)$condition != 0 ? $onTrue : $onFalse;
    }
}
