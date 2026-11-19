<?php

namespace Vectorface\Tests\MySQLite;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\TestCase;
use Vectorface\MySQLite\MySQLite;
use Vectorface\Tests\MySQLite\Util\FakePDO;

/**
 * Test MySQLite; This could be split up into individual function categories later.
 */
class MySQLiteTest extends TestCase
{
    private function getPdo(mixed ...$args): PDO
    {
        return class_exists(Pdo\Sqlite::class, false)
            ? new Pdo\Sqlite(...$args)
            : new PDO(...$args);
    }

    /**
     * Test miscellaneous compatibility functions.
     */
    public function testCompatibilityFunctions()
    {
        /* Comparison functions */
        $this->assertEquals(1, MySQLite::mysql_least(1, 2, 3, 4));
        try {
            MySQLite::mysql_least();
            $this->fail("Least with no arguments is not valid");
        } catch (\InvalidArgumentException $e) {
            /* Expected */
        }

        /* Flow control functions */
        $this->assertEquals("foo", MySQLite::mysql_if(true, "foo", "bar"));
        $this->assertEquals("bar", MySQLite::mysql_if(false, "foo", "bar"));

        /* Numeric functions */
        $this->assertEquals(10, MySQLite::mysql_sqrt(100));
    }

    public function testDateTimeFunctions()
    {
        $this->assertEquals(date("Y-m-d H:i:s"), MySQLite::mysql_now());
        $this->assertEquals(date("Y-m-d"), MySQLite::mysql_curdate());
        $this->assertEquals(365, MySQLite::mysql_to_days("0000-12-31"));
        $this->assertEquals(718613, MySQLite::mysql_to_days("1967-07-01"));
        $this->assertEquals(735599, MySQLite::mysql_to_days("2014-01-01"));
        $this->assertEquals(time(), MySQLite::mysql_unix_timestamp());
        $this->assertEquals(0, MySQLite::mysql_unix_timestamp("0000-00-00 00:00:00"));
        $this->assertEquals(0, MySQLite::mysql_unix_timestamp("0000-00-00"));
        $time = time();
        $this->assertEquals($time, MySQLite::mysql_unix_timestamp(date("Y-m-d H:i:s")));
    }

    /**
     * TO_DAYS must not depend on the time zone, including zones east of UTC and dates in daylight saving time.
     */
    public function testToDaysInTimeZones()
    {
        $timezone = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Toronto', 'Europe/Berlin', 'Australia/Sydney', 'Pacific/Kiritimati'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertEquals(365, MySQLite::mysql_to_days("0000-12-31"), $zone);
                $this->assertEquals(718613, MySQLite::mysql_to_days("1967-07-01"), $zone);
                $this->assertEquals(735599, MySQLite::mysql_to_days("2014-01-01"), $zone);
                $this->assertEquals(735780, MySQLite::mysql_to_days("2014-07-01"), $zone);
                $this->assertEquals(735780, MySQLite::mysql_to_days("2014-07-01 23:59:59"), $zone);
            }
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * Like MySQL, TO_DAYS and UNIX_TIMESTAMP give NULL for NULL and invalid dates, and UNIX_TIMESTAMP gives 0 when out
     * of range.
     */
    public function testToDaysAndUnixTimestampEdgeCases()
    {
        $this->assertSame(1, MySQLite::mysql_to_days("0000-01-01"));
        $this->assertSame(733321, MySQLite::mysql_to_days("2007-10-07"));
        $this->assertNull(MySQLite::mysql_to_days(null));
        $this->assertNull(MySQLite::mysql_to_days("not a date"));
        $this->assertNull(MySQLite::mysql_to_days("0000-00-00"));
        $this->assertNull(MySQLite::mysql_to_days("2010-02-30"));

        $this->assertNull(MySQLite::mysql_unix_timestamp(null));
        $this->assertNull(MySQLite::mysql_unix_timestamp("not a date"));
        $this->assertSame(0, MySQLite::mysql_unix_timestamp("1960-01-01 00:00:00"));
        $this->assertSame(0, MySQLite::mysql_unix_timestamp("3100-01-01 00:00:00"));

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $this->assertNull($pdo->query("SELECT TO_DAYS(NULL)")->fetch(PDO::FETCH_COLUMN));
        $this->assertNull($pdo->query("SELECT UNIX_TIMESTAMP(NULL)")->fetch(PDO::FETCH_COLUMN));
        $this->assertEqualsWithDelta(time(), $pdo->query("SELECT UNIX_TIMESTAMP()")->fetch(PDO::FETCH_COLUMN), 1);
    }

    /**
     * Test that createFunctions hooks the functions into a PDO object.
     */
    public function testCreateFunctions()
    {
        $fakepdo = new FakePDO();
        $fakepdo->attributes[PDO::ATTR_DRIVER_NAME] = 'mysql';

        try {
            MySQLite::createFunctions($fakepdo);
            $this->fail("Attempt to create functions with a driver other than SQLite should fail.");
        } catch (InvalidArgumentException $e) {
            /* Expected */
        }

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->query("SELECT BIT_OR(1)");
            $this->fail("Attempt to BIT_OR is expected to fail before the function is created.");
        } catch (PDOException $e) {
            /* Expected */
        }

        $this->assertSame($pdo, MySQLite::createFunctions($pdo));
        $this->assertEquals(3, $pdo->query("SELECT BIT_OR(3)")->fetch(PDO::FETCH_COLUMN));
    }

    /**
     * Test that createFunctions is able to create only a limited subset of supported functions.
     */
    public function testSelectiveCreateFunctions()
    {
        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->assertSame($pdo, MySQLite::createFunctions($pdo, ['bit_or']));
        $this->assertEquals(3, $pdo->query("SELECT BIT_OR(3)")->fetch(PDO::FETCH_COLUMN));
        try {
            $pdo->query("SELECT UNIX_TIMESTAMP()");
            $this->fail("UNIX_TIMESTAMP function is expected not to have been created.");
        } catch (PDOException $e) {
            /* Expected */
        }
    }

    /**
     * BIT_OR is an aggregate: it combines a column's values across rows, ignoring NULLs.
     *
     * @dataProvider bitOrProvider
     */
    public function testBitOr(int $expected, array $values)
    {
        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $pdo->exec("CREATE TABLE t (v)");
        $insert = $pdo->prepare("INSERT INTO t (v) VALUES (?)");
        foreach ($values as $value) {
            $insert->execute([$value]);
        }

        $rows = $pdo->query("SELECT BIT_OR(v) FROM t")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(1, $rows, "An aggregate returns one row");
        $this->assertEquals($expected, $rows[0]);
    }

    public static function bitOrProvider(): array
    {
        return [
            'several rows' => [7, [1, 2, 4]],
            'overlapping bits' => [7, [3, 6, 5]],
            'NULLs are ignored' => [5, [null, 1, null, 4]],
            'only NULLs' => [0, [null, null]],
            'no rows' => [0, []],
            'numeric strings' => [6, ['2', '4']],
        ];
    }

    public function testBitOrGroupBy()
    {
        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $pdo->exec("CREATE TABLE t (k, v); INSERT INTO t VALUES ('a', 1), ('a', 2), ('b', 8), ('b', 16)");

        $this->assertEquals(
            ['a' => 3, 'b' => 24],
            $pdo->query("SELECT k, BIT_OR(v) FROM t GROUP BY k ORDER BY k")->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        $this->expectException(PDOException::class);
        $pdo->query("SELECT BIT_OR(1, 2)"); /* MySQL's BIT_OR takes exactly one argument */
    }

    /**
     * Test that registered functions are listed and available.
     */
    public function testGetFunctionList()
    {
        $this->assertContains("bit_or", MySQLite::getFunctionList());
        $this->assertContains("unix_timestamp", MySQLite::getFunctionList());
    }

    /**
     * Test the concat function
     */
    public function testConcat()
    {
        $expected = 'test1 test2 test4';
        $test = MySQLite::mysql_concat("test1", " ", "test2", " ", "test4");
        $this->assertEquals($expected, $test);

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $result = $pdo->query('SELECT CONCAT("test1"," ","test2"," " ,"test4")')->fetch(PDO::FETCH_COLUMN);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test the concat_ws function
     */
    public function testConcatWS()
    {
        $expected = 'test1|test2|test4';
        $test = MySQLite::mysql_concat_ws("|", "test1", "test2", "test4");
        $this->assertEquals($expected, $test);

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $result = $pdo->query('SELECT CONCAT_WS("|","test1","test2","test4")')->fetch(PDO::FETCH_COLUMN);
        $this->assertEquals($expected, $result);
    }

     /**
     * Test the rand function
     */
    public function testRand()
    {
        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);

        $pdo->exec("CREATE TABLE testing(id INT PRIMARY KEY NOT NULL)");
        $stmt = $pdo->prepare("INSERT INTO testing (id) VALUES (?)");
        for ($x = 0; $x <= 10; $x++) {
            $stmt->execute([$x]);
        }

        $results = [];
        for ($x = 0; $x < 20; $x++) {
            $results[] = $pdo->query('SELECT id FROM testing ORDER BY RAND() LIMIT 1')->fetch(PDO::FETCH_COLUMN);
        }

        $this->assertNotEquals(
            array_slice($results, 0, 10),
            array_slice($results, 10, 10)
        );
    }

    /**
     * @dataProvider seededRandProvider
     */
    public function testSeededRand(float $expected, $seed)
    {
        $this->assertEqualsWithDelta($expected, MySQLite::mysql_rand($seed), 1e-14);

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $stmt = $pdo->prepare("SELECT RAND(?)");
        $stmt->execute([$seed]);
        $this->assertEqualsWithDelta($expected, $stmt->fetch(PDO::FETCH_COLUMN), 1e-14);
    }

    public static function seededRandProvider(): array
    {
        /* MySQL's first value for seed 3, from the RAND() example in the MySQL manual. */
        return [
            'integer seed' => [0.90576975597606, 3],
            'numeric string seed' => [0.90576975597606, '3'],
            'decimal seed is rounded' => [0.90576975597606, 2.6],
        ];
    }

    public function testFormat()
    {
        $expected = '12,312,312';
        $test = MySQLite::mysql_format("12312312.232", 0);
        $this->assertEquals($expected, $test);

        $expected = '12.2';
        $test = MySQLite::mysql_format("12.232", 1);
        $this->assertEquals($expected, $test);
    }

    public function testSoundex()
    {
        $this->assertEquals("F000", MySQLite::mysql_soundex("foo"));
    }

    /**
     * Test NULL handling, which follows MySQL: mostly NULL in, NULL out.
     *
     * @dataProvider nullArgumentProvider
     */
    public function testNullArguments($expected, string $function, array $args)
    {
        $this->assertFunctionResult($expected, $function, $args);
    }

    public static function nullArgumentProvider(): array
    {
        return [
            'CONCAT with a NULL' => [null, 'concat', ['a', null, 'b']],
            'CONCAT_WS skips NULLs' => ['a|b', 'concat_ws', ['|', 'a', null, 'b']],
            'CONCAT_WS keeps empty strings' => ['a||b', 'concat_ws', ['|', 'a', '', 'b']],
            'CONCAT_WS with NULL separator' => [null, 'concat_ws', [null, 'a', 'b']],
            'FORMAT with NULL number' => [null, 'format', [null, 2]],
            'FORMAT with NULL decimals' => [null, 'format', [1.5, null]],
            'SOUNDEX of NULL' => [null, 'soundex', [null]],
            'SQRT of NULL' => [null, 'sqrt', [null]],
            'SQRT of a negative number' => [null, 'sqrt', [-16]],
        ];
    }

    /**
     * Assert that a function gives the expected result both when called directly and when called through SQLite.
     *
     * @param mixed $expected The expected result. Compared loosely through SQLite, as PHP 8.0 returns strings.
     * @param string $function The function name, e.g. 'concat'.
     * @param array $args The function arguments.
     */
    private function assertFunctionResult($expected, string $function, array $args)
    {
        $this->assertSame($expected, MySQLite::{"mysql_$function"}(...$args), "Direct call to $function");

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $stmt = $pdo->prepare(sprintf("SELECT %s(%s)", $function, implode(", ", array_fill(0, count($args), "?"))));
        $stmt->execute($args);
        $actual = $stmt->fetch(PDO::FETCH_COLUMN);
        if ($expected === null) {
            $this->assertNull($actual, "SQL call to $function");
        } else {
            $this->assertEquals($expected, $actual, "SQL call to $function");
        }
    }

    public function testYear()
    {
        $this->assertSame(1987, MySQLite::mysql_year('1987-01-01'));
        $this->assertSame(2010, MySQLite::mysql_year('2010-11-30 12:34:56'));
        $this->assertSame(2010, MySQLite::mysql_year('2010-00-00'), "Incomplete dates are allowed");
        $this->assertSame(0, MySQLite::mysql_year('0000-00-00'));
        $this->assertNull(MySQLite::mysql_year('2010-02-30'));
        $this->assertNull(MySQLite::mysql_year('not a date'));
        $this->assertNull(MySQLite::mysql_year(null));

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $this->assertEquals(2014, $pdo->query("SELECT YEAR('2014-06-15')")->fetch(PDO::FETCH_COLUMN));
    }

    public function testDatediff()
    {
        /* Examples from the MySQL manual */
        $this->assertSame(1, MySQLite::mysql_datediff('2007-12-31 23:59:59', '2007-12-30'));
        $this->assertSame(-31, MySQLite::mysql_datediff('2010-11-30 23:59:59', '2010-12-31'));

        $this->assertSame(2, MySQLite::mysql_datediff('2024-03-01', '2024-02-28'), "Leap day is counted");
        $this->assertSame(365242, MySQLite::mysql_datediff('2000-01-01', '1000-01-01'));
        $this->assertSame(0, MySQLite::mysql_datediff('2010-01-01 00:00:00', '2010-01-01 23:59:59'));
        $this->assertNull(MySQLite::mysql_datediff('2010-02-30', '2010-01-01'));
        $this->assertNull(MySQLite::mysql_datediff('0000-00-00', '2010-01-01'));
        $this->assertNull(MySQLite::mysql_datediff(null, '2010-01-01'));
        $this->assertNull(MySQLite::mysql_datediff('2010-01-01', 'not a date'));

        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $this->assertSame(1, MySQLite::mysql_datediff('2024-03-11', '2024-03-10'), "DST doesn't shift days");
        } finally {
            date_default_timezone_set($timezone);
        }

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $this->assertEquals(-31, $pdo->query("SELECT DATEDIFF('2010-11-30', '2010-12-31')")->fetch(PDO::FETCH_COLUMN));
    }

    public function testTimediff()
    {
        /* Examples from the MySQL manual */
        $this->assertSame(
            '-00:00:00.000001',
            MySQLite::mysql_timediff('2000:01:01 00:00:00', '2000:01:01 00:00:00.000001')
        );
        $this->assertSame(
            '46:58:57.999999',
            MySQLite::mysql_timediff('2008-12-31 23:59:59.000001', '2008-12-30 01:01:01.000002')
        );

        /* Times */
        $this->assertSame('00:29:45', MySQLite::mysql_timediff('10:00:00', '09:30:15'));
        $this->assertSame('-26:00:00', MySQLite::mysql_timediff('-1 01:00:00', '01:00:00'));
        $this->assertSame('25:00:00', MySQLite::mysql_timediff('1 00:00:00', '-1:00'));
        $this->assertSame('01:00:00.500000', MySQLite::mysql_timediff('10:00:00.5', '09:00:00'));

        /* Results are clamped to MySQL's TIME range */
        $this->assertSame('838:59:59', MySQLite::mysql_timediff('2010-01-01 00:00:00', '2000-01-01 00:00:00'));
        $this->assertSame('-838:59:59', MySQLite::mysql_timediff('2000-01-01', '2010-01-01'));

        /* Invalid or mismatched arguments */
        $this->assertNull(MySQLite::mysql_timediff('2010-01-01 00:00:00', '10:00:00'));
        $this->assertNull(MySQLite::mysql_timediff('10:00:00', '2010-01-01 00:00:00'));
        $this->assertNull(MySQLite::mysql_timediff('10:61:00', '10:00:00'));
        $this->assertNull(MySQLite::mysql_timediff('2010-02-30 00:00:00', '2010-01-01 00:00:00'));
        $this->assertNull(MySQLite::mysql_timediff(null, '10:00:00'));

        /* SQLite 3.43+ has its own timediff() with a different format; ours must replace it. */
        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $this->assertEquals(
            '24:00:01',
            $pdo->query("SELECT TIMEDIFF('2010-01-02 00:00:01', '2010-01-01')")->fetch(PDO::FETCH_COLUMN)
        );
    }

    public function testRight()
    {
        $this->assertSame('rbar', MySQLite::mysql_right('foobarbar', 4));
        $this->assertSame('abc', MySQLite::mysql_right('abc', 10));
        $this->assertSame('', MySQLite::mysql_right('abc', 0));
        $this->assertSame('', MySQLite::mysql_right('abc', -1));
        $this->assertSame('ïve', MySQLite::mysql_right('naïve', 3), "Counts characters, not bytes");
        $this->assertSame('23', MySQLite::mysql_right(123, 2));
        $this->assertNull(MySQLite::mysql_right(null, 1));
        $this->assertNull(MySQLite::mysql_right('abc', null));

        $pdo = $this->getPdo("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        MySQLite::createFunctions($pdo);
        $this->assertEquals('rbar', $pdo->query("SELECT RIGHT('foobarbar', 4)")->fetch(PDO::FETCH_COLUMN));
    }
}
