<?php

namespace Vectorface\MySQLite\MySQL;

use Vectorface\MySQLite\UserLocks;

/**
 * Provides Locking MySQL compatibility functions for SQLite.
 *
 * These functions act on behalf of a connection, so each takes that connection's UserLocks as its first parameter.
 * MySQLite::createFunctions supplies it; SQL callers pass only the MySQL arguments.
 *
 * https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html
 */
trait Locking
{
    /**
     * GET_LOCK - Acquire a named lock, waiting up to $timeout seconds.
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @param float $timeout Seconds to wait for the lock. Negative values wait forever.
     * @return int 1 if the lock was acquired, 0 if the attempt timed out.
     */
    public static function mysql_get_lock(UserLocks $locks, $name, $timeout)
    {
        return (int)$locks->acquire($name, (float)$timeout);
    }

    /**
     * RELEASE_LOCK - Release a named lock held by this connection.
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @return int|null 1 if released, 0 if another connection holds the lock, NULL if nobody holds it.
     */
    public static function mysql_release_lock(UserLocks $locks, $name)
    {
        $released = $locks->release($name);
        return $released === null ? null : (int)$released;
    }

    /**
     * RELEASE_ALL_LOCKS - Release all named locks held by this connection.
     *
     * @param UserLocks $locks The calling connection's locks.
     * @return int The number of locks released.
     */
    public static function mysql_release_all_locks(UserLocks $locks)
    {
        return $locks->releaseAll();
    }

    /**
     * IS_FREE_LOCK - Check whether a named lock is free.
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @return int 1 if the lock is free, 0 if any connection holds it.
     */
    public static function mysql_is_free_lock(UserLocks $locks, $name)
    {
        return (int)$locks->isFree($name);
    }
}
