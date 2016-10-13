<?php

namespace Vectorface\MySQLite;

use InvalidArgumentException;
use PDO;
use ReflectionClass;
use ReflectionMethod;

use Vectorface\MySQLite\MySQL\Aggregate;
use Vectorface\MySQLite\MySQL\Comparison;
use Vectorface\MySQLite\MySQL\DateTime;
use Vectorface\MySQLite\MySQL\Flow;
use Vectorface\MySQLite\MySQL\Numeric;
use Vectorface\MySQLite\MySQL\StringFn;

/**
 * Provides some compatibility functions, allowing SQLite to mimic some MySQL functions.
 *
 * All public methods starting with a mysql_ will be registered by the addFunctions method.
 */
class MySQLite
{
    /**
     * Individual traits group functions into MySQL's documented function categories.
     */
    use Aggregate;
    use Comparison;
    use DateTime;
    use Flow;
    use Numeric;
    use StringFn;

    /**
     * Get a list of MySQL compatibility functions currently provided by this class.
     *
     * @return string[] An array of function names, normalized to lower case.
     */
    public static function getFunctionList()
    {
        return array_map(
            function ($f) {
                return substr($f, 6);
            },
            array_keys(static::getPublicMethodData())
        );
    }

    /**
     * Add MySQLite compatibility functions to a PDO object.
     *
     * @param PDO &$pdo A PDO instance to which the MySQLite compatibility functions should be added.
     * @param string[] $fnList A list of functions to create on the SQLite database. (Omit to create all.)
     * @return PDO Returns a reference to the PDO instance passed in to the function.
     */
    public static function &createFunctions(PDO &$pdo, array $fnList = null)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException('Expecting a PDO instance using the SQLite driver');
        }

        foreach (static::getPublicMethodData() as $method => $paramCount) {
            static::registerMethod($pdo, $method, $paramCount, $fnList);
        }

        return $pdo;
    }

    /**
     * Get information about functions that are meant to be exposed by this class.
     *
     * @return int[] An associative array composed of function names mapping to accepted parameter counts.
     */
    protected static function getPublicMethodData()
    {
        $data = [];

        $ref = new ReflectionClass(__CLASS__);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC);
        foreach ($methods as $method) {
            if (strpos($method->name, 'mysql_') !== 0) {
                continue;
            }

            $data[$method->name] = $method->getNumberOfRequiredParameters();
        }

        return $data;
    }

    /**
     * Register a method as an SQLite funtion
     *
     * @param PDO &$pdo A PDO instance to which the MySQLite compatibility functions should be added.
     * @param string $method The internal method name.
     * @param int $paramCount The suggested parameter count.
     * @param string[] $fnList A list of functions to create on the SQLite database, or empty for all.
     * @return bool Returns true if the method was registed. False otherwise.
     */
    protected static function registerMethod(PDO &$pdo, $method, $paramCount, array $fnList = null)
    {
        $function = substr($method, 6); /* Strip 'mysql_' prefix to get the function name. */

        /* Skip functions not in the list. */
        if (!empty($fnList) && !in_array($function, $fnList)) {
            return false;
        }

        if ($paramCount) {
            return $pdo->sqliteCreateFunction($function, [__CLASS__, $method], $paramCount);
        }
        return $pdo->sqliteCreateFunction($function, [__CLASS__, $method]);
    }
}
