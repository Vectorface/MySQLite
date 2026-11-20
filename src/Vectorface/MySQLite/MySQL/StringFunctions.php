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
     * @return string|null $str concatenated string, or NULL if any argument is NULL
     */
    public static function mysql_concat()
    {
        $args = func_get_args();
        return in_array(null, $args, true) ? null : implode('', $args);
    }

    /**
     * Concat_ws - Return a concatenated string of all function arguments provided
     * it will use the first argument as the separator. NULL arguments after the separator are skipped.
     * @return string|null $str concatenated string with separator, or NULL if the separator is NULL
     */
    public static function mysql_concat_ws()
    {
        $args = func_get_args();
        $separator = array_shift($args);
        if ($separator === null) {
            return null;
        }
        return implode($separator, array_filter($args, function ($arg) {
            return $arg !== null;
        }));
    }


    /**
     * Format - Return a formated number string based on the arguments provided
     * Ignoring the functionality of a third argument, locale
     * https://dev.mysql.com/doc/refman/8.0/en/string-functions.html#function_format
     * @return string|null $str formatted as per arg, or NULL if the number or decimals is NULL
     */
    public static function mysql_format()
    {
        $args = func_get_args();
        if (in_array(null, array_slice($args, 0, 2), true)) {
            return null;
        }
        $number = isset($args[0]) ? $args[0] : 0.0;
        $decimals = isset($args[1]) ? $args[1] : 0;
        return number_format($number, $decimals);
    }

    /**
     * Get the soundex value of a given string, or NULL if the string is NULL
     */
    public static function mysql_soundex(?string $str): ?string
    {
        return $str === null ? null : soundex($str);
    }

    /**
     * RIGHT - Return the rightmost $len characters of a string
     *
     * @param string $str The string.
     * @param int $len The number of characters to return.
     * @return string|null The rightmost characters, '' if $len <= 0, or NULL if either argument is NULL.
     */
    public static function mysql_right($str, $len)
    {
        if ($str === null || $len === null) {
            return null;
        }

        $str = (string)$str;
        $len = (int)round((float)$len);
        if ($len <= 0) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($str, -$len, null, 'UTF-8') : substr($str, -$len);
    }
}
