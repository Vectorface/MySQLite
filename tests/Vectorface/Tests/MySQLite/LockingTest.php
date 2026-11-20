<?php

namespace Vectorface\Tests\MySQLite;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Vectorface\MySQLite\MySQLite;
use Vectorface\MySQLite\UserLocks;

/**
 * Test the user-level locking functions: GET_LOCK, RELEASE_LOCK, RELEASE_ALL_LOCKS and IS_FREE_LOCK.
 */
class LockingTest extends TestCase
{
    /** @var string A private lock directory, isolating these tests. */
    private $lockDir;

    protected function setUp(): void
    {
        $this->lockDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mysqlite-locktest-' . bin2hex(random_bytes(8));
        mkdir($this->lockDir);
        UserLocks::setDirectory($this->lockDir);
    }

    protected function tearDown(): void
    {
        UserLocks::setDirectory(null);
        array_map('unlink', glob($this->lockDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($this->lockDir);
    }

    private function getPdo(): PDO
    {
        $args = ["sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]];
        $pdo = class_exists(\Pdo\Sqlite::class, false) ? new \Pdo\Sqlite(...$args) : new PDO(...$args);
        return MySQLite::createFunctions($pdo);
    }

    private function query(PDO $pdo, string $sql, array $params = [])
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * @dataProvider functionNameProvider
     */
    public function testFunctionsAreListed(string $function)
    {
        $this->assertContains($function, MySQLite::getFunctionList());
    }

    public static function functionNameProvider(): array
    {
        return [
            'get_lock' => ['get_lock'],
            'release_lock' => ['release_lock'],
            'release_all_locks' => ['release_all_locks'],
            'is_free_lock' => ['is_free_lock'],
        ];
    }

    public function testLockLifecycle()
    {
        $pdo = $this->getPdo();

        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"));
        $this->assertNull($this->query($pdo, "SELECT RELEASE_LOCK('a')"), "Releasing an unheld lock gives NULL");

        $this->assertEquals(1, $this->query($pdo, "SELECT GET_LOCK('a', 0)"));
        $this->assertEquals(0, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"), "The holder also sees it in use");
        $this->assertEquals(0, $this->query($pdo, "SELECT IS_FREE_LOCK('A')"), "Lock names are case-insensitive");

        $this->assertEquals(1, $this->query($pdo, "SELECT RELEASE_LOCK('a')"));
        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"));
    }

    public function testLocksAreReentrant()
    {
        $pdo = $this->getPdo();

        $this->assertEquals(1, $this->query($pdo, "SELECT GET_LOCK('a', 0)"));
        $this->assertEquals(1, $this->query($pdo, "SELECT GET_LOCK('a', 0)"));
        $this->assertEquals(1, $this->query($pdo, "SELECT RELEASE_LOCK('a')"));
        $this->assertEquals(0, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"), "Still held once more");
        $this->assertEquals(1, $this->query($pdo, "SELECT RELEASE_LOCK('a')"));
        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"));
    }

    public function testReleaseAllLocks()
    {
        $pdo = $this->getPdo();

        $this->assertEquals(0, $this->query($pdo, "SELECT RELEASE_ALL_LOCKS()"));
        $this->query($pdo, "SELECT GET_LOCK('a', 0)");
        $this->query($pdo, "SELECT GET_LOCK('a', 0)");
        $this->query($pdo, "SELECT GET_LOCK('b', 0)");
        $this->assertEquals(3, $this->query($pdo, "SELECT RELEASE_ALL_LOCKS()"));
        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('a')"));
        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('b')"));
    }

    public function testLocksAreOwnedByConnection()
    {
        $one = $this->getPdo();
        $two = $this->getPdo();

        $this->assertEquals(1, $this->query($one, "SELECT GET_LOCK('a', 0)"));
        $this->assertEquals(0, $this->query($two, "SELECT IS_FREE_LOCK('a')"));
        $this->assertEquals(0, $this->query($two, "SELECT GET_LOCK('a', 0)"));
        $this->assertEquals(0, $this->query($two, "SELECT RELEASE_LOCK('a')"), "Only the holder may release");
        $this->assertEquals(0, $this->query($two, "SELECT RELEASE_ALL_LOCKS()"));
        $this->assertEquals(0, $this->query($one, "SELECT IS_FREE_LOCK('a')"));

        $start = microtime(true);
        $this->assertEquals(0, $this->query($two, "SELECT GET_LOCK('a', 0.2)"));
        $this->assertGreaterThanOrEqual(0.2, microtime(true) - $start, "GET_LOCK waits for the timeout");
    }

    public function testLocksAreReleasedWithConnection()
    {
        $one = $this->getPdo();
        $two = $this->getPdo();

        $this->assertEquals(1, $this->query($one, "SELECT GET_LOCK('a', 0)"));
        $one = null;
        $this->assertEquals(1, $this->query($two, "SELECT IS_FREE_LOCK('a')"));
        $this->assertEquals(1, $this->query($two, "SELECT GET_LOCK('a', 0)"));
    }

    public function testLocksAreSharedAcrossProcesses()
    {
        $pdo = $this->getPdo();
        $script = __DIR__ . DIRECTORY_SEPARATOR . 'Util' . DIRECTORY_SEPARATOR . 'hold_lock.php';
        $proc = proc_open(
            [PHP_BINARY, $script, $this->lockDir, 'shared'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc);

        try {
            $this->assertEquals("1", trim(fgets($pipes[1])), "The child process acquires the lock");
            $this->assertEquals(0, $this->query($pdo, "SELECT IS_FREE_LOCK('shared')"));
            $this->assertEquals(0, $this->query($pdo, "SELECT GET_LOCK('shared', 0)"));
            $this->assertEquals(0, $this->query($pdo, "SELECT RELEASE_LOCK('shared')"));
        } finally {
            fwrite($pipes[0], "\n");
            fclose($pipes[0]);
            fclose($pipes[1]);
            proc_close($proc);
        }

        $this->assertEquals(1, $this->query($pdo, "SELECT IS_FREE_LOCK('shared')"), "Released when the process exits");
    }

    public function testDirectCalls()
    {
        $locks = new UserLocks();

        $this->assertEquals(1, MySQLite::mysql_is_free_lock($locks, 'a'));
        $this->assertEquals(1, MySQLite::mysql_get_lock($locks, 'a', 0));
        $this->assertEquals(0, MySQLite::mysql_is_free_lock(new UserLocks(), 'a'));
        $this->assertEquals(1, MySQLite::mysql_release_all_locks($locks));
    }

    /**
     * @dataProvider invalidNameProvider
     */
    public function testInvalidLockNames($name)
    {
        $pdo = $this->getPdo();

        $this->expectException(InvalidArgumentException::class);
        $this->query($pdo, "SELECT IS_FREE_LOCK(?)", [$name]);
    }

    public static function invalidNameProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'too long' => [str_repeat('x', UserLocks::MAX_NAME_LENGTH + 1)],
        ];
    }
}
