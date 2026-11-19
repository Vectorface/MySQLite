<?php

/**
 * Hold a MySQLite user-level lock in a separate process, for cross-process lock tests.
 *
 * Usage: php hold_lock.php <lock directory> <lock name>
 * Prints the result of GET_LOCK, then holds the lock until a line is read from stdin (or stdin closes).
 */

require __DIR__ . '/../../../../../vendor/autoload.php';

use Vectorface\MySQLite\MySQLite;
use Vectorface\MySQLite\UserLocks;

UserLocks::setDirectory($argv[1]);

$pdo = class_exists(Pdo\Sqlite::class, false) ? new Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
MySQLite::createFunctions($pdo);

$stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$stmt->execute([$argv[2]]);
echo $stmt->fetchColumn(), "\n";

fgets(STDIN);
