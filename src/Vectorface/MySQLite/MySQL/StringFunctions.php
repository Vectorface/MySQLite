<?php

namespace Vectorface\MySQLite\MySQL;

/**
 * Provides String Manipulation MySQL compatibility functions for SQLite.
 *
 * http://dev.mysql.com/doc/refman/5.7/en/string-functions.html
 */
trait StringFunctions
{

     /**
     * Concat - Return a concatenated string of all function arguments provided
     * @return string $str concatenated string
     */
    public static function mysql_concat()
    {
        $str = '';
        foreach (func_get_args() as $arg) {
            $str .= $arg;
        }
        return $str;
    }

    /**
     * Concat_ws - Return a concatenated string of all function arguments provided
     * it will use the first argument as the separator
     * @return string $str concatenated string with separator
     */
    public static function mysql_concat_ws()
    {
        $args = func_get_args();
        $seperator = array_shift($args);
        $str = implode($seperator, $args);
        return $str;
    }


    /**
     * Format - Return a formated number string based on the arguments provided
     * Ignoring the functionality of a third argument, locale
     * https://dev.mysql.com/doc/refman/8.0/en/string-functions.html#function_format
     * @return string $str formatted as per arg
     */
    public static function mysql_format()
    {
        $args = func_get_args();
        $number = isset($args[0]) ? $args[0] : 0.0;
        $decimals = isset($args[1]) ? $args[1] : 0;
        return number_format($number, $decimals);
    }

    /**
     * Get the soundex value of a given string
     */
    public static function mysql_soundex(string $str): string
    {
        return soundex($str);
    }
}
