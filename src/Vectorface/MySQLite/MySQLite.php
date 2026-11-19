<?php

namespace Vectorface\MySQLite;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Vectorface\MySQLite\MySQL\Aggregate;
use Vectorface\MySQLite\MySQL\Comparison;
use Vectorface\MySQLite\MySQL\DateTime;
use Vectorface\MySQLite\MySQL\Flow;
use Vectorface\MySQLite\MySQL\Locking;
use Vectorface\MySQLite\MySQL\Numeric;
use Vectorface\MySQLite\MySQL\StringFunctions;

/**
 * Provides some compatibility functions, allowing SQLite to mimic some MySQL functions.
 *
 * All public methods starting with a mysql_ will be registered by the addFunctions method.
 *
 * A method whose first parameter is typed with a class (e.g. UserLocks) receives per-connection state: createFunctions
 * creates one instance of that class per PDO connection and passes it ahead of the SQL arguments.
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
    use Locking;
    use Numeric;
    use StringFunctions;

    /**
     * Get a list of MySQL compatibility functions currently provided by this class.
     *
     * @return string[] An array of function names, normalized to lower case.
     */
    public static function getFunctionList()
    {
        $scalars = array_map(
            function ($f) {
                return substr($f, 6);
            },
            array_keys(static::getPublicMethodData())
        );
        return array_merge($scalars, array_keys(static::getAggregateFunctions()));
    }

    /**
     * Add MySQLite compatibility functions to a PDO object.
     *
     * @param PDO &$pdo A PDO instance to which the MySQLite compatibility functions should be added.
     * @param string[] $fnList A list of functions to create on the SQLite database. (Omit to create all.)
     * @return PDO Returns a reference to the PDO instance passed in to the function.
     */
    public static function &createFunctions(PDO &$pdo, ?array $fnList = null)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException('Expecting a PDO instance using the SQLite driver');
        }

        $state = []; /* Per-connection state objects, shared by all functions registered on this PDO. */
        foreach (static::getPublicMethodData() as $method => $paramCount) {
            static::registerMethod($pdo, $method, $paramCount, $fnList, $state);
        }
        foreach (static::getAggregateFunctions() as $function => [$step, $final, $paramCount]) {
            static::registerAggregate($pdo, $function, $step, $final, $paramCount, $fnList);
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

            /* Per-connection state is supplied by createFunctions, not by SQL, so it isn't counted. */
            $stateParams = static::getStateClass($method) === null ? 0 : 1;
            $data[$method->name] = $method->getNumberOfRequiredParameters() - $stateParams;
        }

        return $data;
    }

    /**
     * Get the class of per-connection state a method takes as its first parameter, if any.
     *
     * @param ReflectionMethod $method The method to inspect.
     * @return string|null The class name, or null if the method takes no per-connection state.
     */
    protected static function getStateClass(ReflectionMethod $method)
    {
        $params = $method->getParameters();
        $type = $params ? $params[0]->getType() : null;
        return $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }

    /**
     * Register a method as an SQLite funtion
     *
     * @param PDO &$pdo A PDO instance to which the MySQLite compatibility functions should be added.
     * @param string $method The internal method name.
     * @param int $paramCount The suggested parameter count.
     * @param string[] $fnList A list of functions to create on the SQLite database, or empty for all.
     * @param object[] &$state Per-connection state objects for this PDO, keyed by class name.
     * @return bool Returns true if the method was registed. False otherwise.
     */
    protected static function registerMethod(PDO &$pdo, $method, $paramCount, ?array $fnList = null, array &$state = [])
    {
        $function = substr($method, 6); /* Strip 'mysql_' prefix to get the function name. */

        /* Skip functions not in the list. */
        if (!empty($fnList) && !in_array($function, $fnList)) {
            return false;
        }

        $createFunction = class_exists(Sqlite::class, false) && $pdo instanceof Sqlite
            ? [$pdo, 'createFunction']
            : [$pdo, 'sqliteCreateFunction'];

        $callback = [__CLASS__, $method];
        $stateClass = static::getStateClass(new ReflectionMethod(__CLASS__, $method));
        if ($stateClass !== null) {
            /* The closure must not capture $pdo: the PDO holds the closure, and the state should die with the PDO. */
            $instance = $state[$stateClass] ??= new $stateClass();
            $target = $callback;
            $callback = fn(...$args) => $target($instance, ...$args);
        }

        if ($paramCount) {
            return $createFunction($function, $callback, $paramCount);
        }
        return $createFunction($function, $callback);
    }

    /**
     * Register an aggregate function with SQLite.
     *
     * @param PDO $pdo A PDO instance to which the aggregate should be added.
     * @param string $function The SQL function name.
     * @param string $step The method called for each row in a group.
     * @param string $final The method called once per group to produce the result.
     * @param int $paramCount The number of arguments the function takes.
     * @param string[] $fnList A list of functions to create on the SQLite database, or empty for all.
     * @return bool Returns true if the aggregate was registered. False otherwise.
     */
    protected static function registerAggregate(PDO $pdo, $function, $step, $final, $paramCount, ?array $fnList = null)
    {
        if (!empty($fnList) && !in_array($function, $fnList)) {
            return false;
        }

        $createAggregate = class_exists(Sqlite::class, false) && $pdo instanceof Sqlite
            ? [$pdo, 'createAggregate']
            : [$pdo, 'sqliteCreateAggregate'];

        return $createAggregate($function, [__CLASS__, $step], [__CLASS__, $final], $paramCount);
    }
}
