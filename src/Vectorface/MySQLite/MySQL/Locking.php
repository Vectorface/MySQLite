<?php

namespace Vectorface\MySQLite\MySQL;

use Vectorface\MySQLite\UserLocks;

/**
 * Provides Locking MySQL compatibility functions for SQLite.
 *
 * Each takes the connection's UserLocks first, supplied by MySQLite::createFunctions.
 *
 * https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html
 */
trait Locking
{
    /**
     * GET_LOCK - Acquire a named lock
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @param float $timeout Seconds to wait, or negative to wait forever.
     * @return int 1 if acquired, 0 if timed out.
     */
    public static function mysql_get_lock(UserLocks $locks, $name, $timeout)
    {
        return (int)$locks->acquire($name, (float)$timeout);
    }

    /**
     * RELEASE_LOCK - Release a named lock
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @return int|null 1 if released, 0 if held by another connection, NULL if not held.
     */
    public static function mysql_release_lock(UserLocks $locks, $name)
    {
        $released = $locks->release($name);
        return $released === null ? null : (int)$released;
    }

    /**
     * RELEASE_ALL_LOCKS - Release all of this connection's named locks
     *
     * @param UserLocks $locks The calling connection's locks.
     * @return int The number of locks released.
     */
    public static function mysql_release_all_locks(UserLocks $locks)
    {
        return $locks->releaseAll();
    }

    /**
     * IS_FREE_LOCK - Check whether a named lock is free
     *
     * @param UserLocks $locks The calling connection's locks.
     * @param string $name The lock name.
     * @return int 1 if free, 0 if held.
     */
    public static function mysql_is_free_lock(UserLocks $locks, $name)
    {
        return (int)$locks->isFree($name);
    }
}
