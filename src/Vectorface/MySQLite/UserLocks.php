<?php

namespace Vectorface\MySQLite;

use InvalidArgumentException;
use RuntimeException;

/**
 * MySQL user-level (named) locks, as used by GET_LOCK() and friends, for a single connection.
 *
 * Each lock is an advisory flock() on a file in a shared directory, so locks are visible to every process on the
 * machine, much like a MySQL server's locks are visible to all of its connections. Locks are released when the
 * owning PDO object is destroyed (via this object's destructor) or when the process exits.
 */
class UserLocks
{
    /** MySQL's maximum lock name length, in characters. */
    public const MAX_NAME_LENGTH = 64;

    /** Time to sleep between attempts while waiting for a lock, in microseconds. */
    public const POLL_INTERVAL = 10000;

    /**
     * The directory that holds lock files, or null to use the system temp directory.
     *
     * @var string|null
     */
    private static $directory = null;

    /**
     * Owners of every lock held in this process, mapping lock names to spl_object_id() of the holding instance.
     *
     * Whether two flock() handles in the same process conflict is platform-dependent, so this tracks it directly.
     *
     * @var int[]
     */
    private static $processLocks = [];

    /**
     * Locks held by this connection, mapping lock names to their open lock file handle and acquisition count.
     *
     * @var array<string, array{handle: resource, count: int}>
     */
    private $held = [];

    /**
     * Set the directory that holds lock files. Connections share locks only if they use the same directory.
     *
     * @param string|null $directory An existing, writable directory, or null for the system temp directory.
     */
    public static function setDirectory(?string $directory)
    {
        self::$directory = $directory;
    }

    /**
     * Try to acquire a lock, waiting up to $timeout seconds. Locks are re-entrant within a connection.
     *
     * @param mixed $name The lock name.
     * @param float $timeout Seconds to wait for the lock. Negative values wait forever.
     * @return bool True if the lock was acquired, false if the attempt timed out.
     */
    public function acquire($name, float $timeout)
    {
        $name = static::normalizeName($name);
        if (isset($this->held[$name])) {
            $this->held[$name]['count']++;
            return true;
        }

        $deadline = $timeout < 0 ? null : microtime(true) + $timeout;
        $handle = static::openLockFile($name);
        while (isset(self::$processLocks[$name]) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                fclose($handle);
                return false;
            }
            usleep(self::POLL_INTERVAL);
        }

        $this->held[$name] = ['handle' => $handle, 'count' => 1];
        self::$processLocks[$name] = spl_object_id($this);
        return true;
    }

    /**
     * Release one acquisition of a lock held by this connection.
     *
     * @param mixed $name The lock name.
     * @return bool|null True if released, false if another connection holds the lock, null if nobody holds it.
     */
    public function release($name)
    {
        $name = static::normalizeName($name);
        if (!isset($this->held[$name])) {
            return $this->isFree($name) ? null : false;
        }

        if (--$this->held[$name]['count'] === 0) {
            $this->unlock($name);
        }
        return true;
    }

    /**
     * Release every lock held by this connection.
     *
     * @return int The number of locks released, counting each acquisition of a re-entrant lock.
     */
    public function releaseAll()
    {
        $released = 0;
        foreach (array_keys($this->held) as $name) {
            $released += $this->held[$name]['count'];
            $this->unlock($name);
        }
        return $released;
    }

    /**
     * Check whether a lock is free, meaning no connection holds it.
     *
     * @param mixed $name The lock name.
     * @return bool True if the lock is free.
     */
    public function isFree($name)
    {
        $name = static::normalizeName($name);
        if (isset(self::$processLocks[$name])) {
            return false;
        }

        $handle = static::openLockFile($name);
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        return $free;
    }

    /**
     * Release all locks when the connection goes away, as MySQL does when a session ends.
     */
    public function __destruct()
    {
        $this->releaseAll();
    }

    /**
     * Fully release a lock held by this connection.
     *
     * @param string $name The normalized lock name.
     */
    private function unlock($name)
    {
        flock($this->held[$name]['handle'], LOCK_UN);
        fclose($this->held[$name]['handle']);
        unset($this->held[$name], self::$processLocks[$name]);
    }

    /**
     * Validate a lock name and normalize it. MySQL lock names are case-insensitive.
     *
     * @param mixed $name The lock name.
     * @return string The normalized lock name.
     */
    protected static function normalizeName($name)
    {
        if ($name === null) {
            throw new InvalidArgumentException("Incorrect user-level lock name 'NULL'");
        }
        $name = (string)$name;
        $mb = function_exists('mb_strlen');
        $length = $mb ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($length === 0 || $length > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException("Incorrect user-level lock name '$name'");
        }
        return $mb ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    }

    /**
     * Open the file backing a lock, creating it if needed. Lock files are left in place for reuse.
     *
     * @param string $name The normalized lock name.
     * @return resource The open file handle.
     */
    protected static function openLockFile($name)
    {
        $directory = self::$directory ?? sys_get_temp_dir();
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'mysqlite-lock-' . sha1($name) . '.lock';
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Unable to open lock file '$path'");
        }
        return $handle;
    }
}
