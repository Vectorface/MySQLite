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
 * createFunctions registers public static mysql_* methods. A method whose first parameter has a class type (e.g.
 * UserLocks) gets one instance of it per connection.
 */
class MySQLite
{
    /* One trait per MySQL function category */
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
     * @param PDO $pdo An SQLite PDO instance.
     * @param string[] $fnList Functions to create, in any case. (Omit to create all.)
     * @return PDO The PDO instance passed in.
     */
    public static function createFunctions(PDO $pdo, ?array $fnList = null)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new InvalidArgumentException('Expecting a PDO instance using the SQLite driver');
        }

        $fnList = $fnList === null ? null : array_map('strtolower', $fnList);

        $state = []; /* Per-connection state, keyed by class */
        foreach (static::getPublicMethodData() as $method => $paramCount) {
            static::registerMethod($pdo, $method, $paramCount, $fnList, $state);
        }
        foreach (static::getAggregateFunctions() as $function => [$step, $final, $paramCount]) {
            static::registerAggregate($pdo, $function, $step, $final, $paramCount, $fnList);
        }

        return $pdo;
    }

    /**
     * @return int[] Method names mapped to SQL parameter counts.
     */
    protected static function getPublicMethodData()
    {
        $data = [];

        /* static::class lets subclasses add functions. getMethods() ORs filters, so check isStatic() here. */
        $ref = new ReflectionClass(static::class);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() || strpos($method->name, 'mysql_') !== 0) {
                continue;
            }

            /* State isn't an SQL argument */
            $stateParams = static::getStateClass($method) === null ? 0 : 1;
            $data[$method->name] = $method->getNumberOfRequiredParameters() - $stateParams;
        }

        return $data;
    }

    /**
     * Get the class of a method's per-connection state parameter, if any.
     *
     * @param ReflectionMethod $method The method.
     * @return string|null The class name, or null.
     */
    protected static function getStateClass(ReflectionMethod $method)
    {
        $params = $method->getParameters();
        $type = $params ? $params[0]->getType() : null;
        return $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }

    /**
     * Register a method as an SQLite function
     *
     * @param PDO $pdo An SQLite PDO instance.
     * @param string $method The method name.
     * @param int $paramCount The parameter count.
     * @param string[] $fnList Functions to create, or empty for all.
     * @param object[] &$state Per-connection state, keyed by class.
     * @return bool Whether the method was registered.
     */
    protected static function registerMethod(PDO $pdo, $method, $paramCount, ?array $fnList = null, array &$state = [])
    {
        $function = substr($method, 6); /* Strip 'mysql_' */

        if (!empty($fnList) && !in_array($function, $fnList)) {
            return false;
        }

        $createFunction = class_exists(Sqlite::class, false) && $pdo instanceof Sqlite
            ? [$pdo, 'createFunction']
            : [$pdo, 'sqliteCreateFunction'];

        $callback = [static::class, $method];
        $stateClass = static::getStateClass(new ReflectionMethod(static::class, $method));
        if ($stateClass !== null) {
            /* Don't capture $pdo: the PDO holds this closure, so that would be a cycle. */
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
     * @param PDO $pdo An SQLite PDO instance.
     * @param string $function The SQL function name.
     * @param string $step The method called for each row.
     * @param string $final The method called once per group for the result.
     * @param int $paramCount The parameter count.
     * @param string[] $fnList Functions to create, or empty for all.
     * @return bool Whether the aggregate was registered.
     */
    protected static function registerAggregate(PDO $pdo, $function, $step, $final, $paramCount, ?array $fnList = null)
    {
        if (!empty($fnList) && !in_array($function, $fnList)) {
            return false;
        }

        $createAggregate = class_exists(Sqlite::class, false) && $pdo instanceof Sqlite
            ? [$pdo, 'createAggregate']
            : [$pdo, 'sqliteCreateAggregate'];

        return $createAggregate($function, [static::class, $step], [static::class, $final], $paramCount);
    }
}
