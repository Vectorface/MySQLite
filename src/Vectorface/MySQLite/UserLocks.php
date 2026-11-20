<?php

namespace Vectorface\MySQLite;

use InvalidArgumentException;
use RuntimeException;

/**
 * One connection's MySQL user-level locks (GET_LOCK() etc.)
 *
 * Locks are flock()s on files in a shared directory, so all processes on the machine see them. They're released when
 * this object is destroyed or the process exits.
 */
class UserLocks
{
    /** MySQL's maximum lock name length, in characters. */
    public const MAX_NAME_LENGTH = 64;

    /** Time to sleep between attempts while waiting for a lock, in microseconds. */
    public const POLL_INTERVAL = 10000;

    /** @var string|null Lock file directory, or null for the system temp directory. */
    private static $directory = null;

    /** @var int[] Holder object IDs by lock name, as in-process flock() is unreliable. */
    private static $processLocks = [];

    /** @var array<string, array{handle: resource, count: int}> Locks held by this connection. */
    private $held = [];

    /**
     * Set the lock file directory. Only connections using the same directory share locks.
     *
     * @param string|null $directory A writable directory, or null for the system temp directory.
     */
    public static function setDirectory(?string $directory)
    {
        self::$directory = $directory;
    }

    /**
     * Acquire a lock, re-entrantly.
     *
     * @param mixed $name The lock name.
     * @param float $timeout Seconds to wait, or negative to wait forever.
     * @return bool True if acquired, false if timed out.
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
     * Release one acquisition of a lock.
     *
     * @param mixed $name The lock name.
     * @return bool|null True if released, false if held by another connection, null if not held.
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
     * Release all of this connection's locks.
     *
     * @return int The number released, counting re-entrant acquisitions.
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
     * Check whether no connection holds a lock.
     *
     * @param mixed $name The lock name.
     * @return bool
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
     * Release all locks when the connection goes away, like MySQL.
     */
    public function __destruct()
    {
        $this->releaseAll();
    }

    /**
     * Fully release a held lock.
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
     * Validate a lock name and lowercase it, as MySQL lock names are case-insensitive.
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
     * Open a lock's file, creating it if needed. Lock files are never deleted.
     *
     * @param string $name The normalized lock name.
     * @return resource
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
