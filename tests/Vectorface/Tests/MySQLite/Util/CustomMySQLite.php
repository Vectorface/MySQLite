<?php

namespace Vectorface\Tests\MySQLite\Util;

use Vectorface\MySQLite\MySQLite;

/**
 * A subclass that adds its own functions, as the README suggests.
 */
class CustomMySQLite extends MySQLite
{
    /**
     * FOO - A custom scalar function.
     */
    public static function mysql_foo($value)
    {
        return "foo$value";
    }

    /**
     * Not static, so not registered.
     */
    public function mysql_not_static()
    {
        return 1;
    }

    /**
     * Add a custom aggregate to the inherited ones.
     */
    protected static function getAggregateFunctions()
    {
        return parent::getAggregateFunctions() + ['row_total' => ['rowTotalStep', 'rowTotalFinal', 1]];
    }

    /**
     * ROW_TOTAL step - Add one row's value to the total.
     */
    public static function rowTotalStep($total, $rowNumber, $value)
    {
        return ($total ?? 0) + $value;
    }

    /**
     * ROW_TOTAL - Return the total of all values in the group.
     */
    public static function rowTotalFinal($total, $rowNumber)
    {
        return $total ?? 0;
    }
}
