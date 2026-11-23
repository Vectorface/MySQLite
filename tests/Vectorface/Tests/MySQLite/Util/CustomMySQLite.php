<?php

namespace Vectorface\Tests\MySQLite\Util;

use Vectorface\MySQLite\MySQLite;

/**
 * A subclass adding its own functions.
 */
class CustomMySQLite extends MySQLite
{
    /**
     * FOO - A scalar function.
     */
    public static function mysql_foo($value)
    {
        return "foo$value";
    }

    /**
     * OPT_ARGS - Takes an optional second argument.
     */
    public static function mysql_opt_args($a, $b = 'd')
    {
        return "$a-$b";
    }

    /**
     * Not static, so not registered.
     */
    public function mysql_not_static()
    {
        return 1;
    }

    /**
     * Add an aggregate to the inherited ones.
     */
    protected static function getAggregateFunctions()
    {
        return parent::getAggregateFunctions() + ['row_total' => ['rowTotalStep', 'rowTotalFinal', 1]];
    }

    /**
     * ROW_TOTAL step
     */
    public static function rowTotalStep($total, $rowNumber, $value)
    {
        return ($total ?? 0) + $value;
    }

    /**
     * ROW_TOTAL - Sum the group's values.
     */
    public static function rowTotalFinal($total, $rowNumber)
    {
        return $total ?? 0;
    }
}
