<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides String Manipulation MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/string-functions.html
 */
trait String
{

     /**
     * Concat - Return a concatinated string of all function arguments provided
     * @return string $str concatinated string
     */
    public static function mysql_concat()
    {
        $str = '';
        foreach(func_get_args() as $arg) {
            $str .= $arg;
        }
        return $str;
    }
}
