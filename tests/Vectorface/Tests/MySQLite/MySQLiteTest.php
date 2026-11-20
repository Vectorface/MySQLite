<?php

namespace Vectorface\Tests\MySQLite;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;
use PDOException;
use PHPUnit\Framework\TestCase;
use Vectorface\MySQLite\MySQLite;
use Vectorface\Tests\MySQLite\Util\CustomMySQLite;
use Vectorface\Tests\MySQLite\Util\FakePDO;

/**
 * Test MySQLite. Function results are grouped by MySQL's function categories, matching the traits in src.
 */
class MySQLiteTest extends TestCase
{
    private function getPdo(): PDO
    {
        $args = ["sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]];
        return class_exists(Sqlite::class, false) ? new Sqlite(...$args) : new PDO(...$args);
    }

    private function getPdoWithFunctions(): PDO
    {
        $pdo = $this->getPdo();
        MySQLite::createFunctions($pdo);
        return $pdo;
    }

    /**
     * Assert that a function gives the expected result both when called directly and when called through SQLite.
     *
     * Arguments are passed to SQLite as literals rather than bound parameters, which PDO would turn into strings, so
     * the function receives the same types either way.
     *
     * @param mixed $expected The expected result. Compared loosely through SQLite, as PHP 8.0 returns strings.
     * @param string $function The function name, e.g. 'concat'.
     * @param array $args The function arguments.
     */
    private function assertFunctionResult($expected, string $function, array $args)
    {
        $this->assertSame($expected, MySQLite::{"mysql_$function"}(...$args), "Direct call to $function");

        $pdo = $this->getPdoWithFunctions();
        $literals = array_map(function ($arg) use ($pdo) {
            if ($arg === null) {
                return 'NULL';
            }
            return is_string($arg) ? $pdo->quote($arg) : var_export(is_bool($arg) ? (int)$arg : $arg, true);
        }, $args);
        $actual = $pdo->query(sprintf("SELECT %s(%s)", $function, implode(", ", $literals)))->fetch(PDO::FETCH_COLUMN);
        if ($expected === null) {
            $this->assertNull($actual, "SQL call to $function");
        } else {
            $this->assertEquals($expected, $actual, "SQL call to $function");
        }
    }

    /*
     * Registration
     */

    public function testCreateFunctionsRequiresSqlite()
    {
        $fakepdo = new FakePDO();
        $fakepdo->attributes[PDO::ATTR_DRIVER_NAME] = 'mysql';

        $this->expectException(InvalidArgumentException::class);
        MySQLite::createFunctions($fakepdo);
    }

    /**
     * Test that createFunctions hooks the functions into a PDO object.
     */
    public function testCreateFunctions()
    {
        $pdo = $this->getPdo();
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
     * The PDO doesn't need to be a variable, so the README's one-liner works without a notice.
     */
    public function testCreateFunctionsOneLiner()
    {
        $pdo = MySQLite::createFunctions($this->getPdo());
        $this->assertEquals(3, $pdo->query("SELECT BIT_OR(3)")->fetch(PDO::FETCH_COLUMN));
    }

    /**
     * Test that createFunctions is able to create only a limited subset of supported functions, named in any case.
     *
     * @dataProvider selectiveFunctionListProvider
     */
    public function testSelectiveCreateFunctions(array $fnList)
    {
        $pdo = $this->getPdo();
        $this->assertSame($pdo, MySQLite::createFunctions($pdo, $fnList));
        $this->assertEquals(3, $pdo->query("SELECT BIT_OR(3)")->fetch(PDO::FETCH_COLUMN));
        $this->assertEquals(2, $pdo->query("SELECT LEAST(3, 2)")->fetch(PDO::FETCH_COLUMN));

        $this->expectException(PDOException::class);
        $pdo->query("SELECT UNIX_TIMESTAMP()");
    }

    public static function selectiveFunctionListProvider(): array
    {
        return [
            'lower case' => [['bit_or', 'least']],
            'upper case' => [['BIT_OR', 'LEAST']],
            'mixed case' => [['Bit_Or', 'Least']],
        ];
    }

    /**
     * @dataProvider functionNameProvider
     */
    public function testGetFunctionList(string $function)
    {
        $this->assertContains($function, MySQLite::getFunctionList());
    }

    /**
     * Subclasses can add scalar and aggregate functions, and keep the inherited ones.
     */
    public function testSubclassFunctions()
    {
        $pdo = CustomMySQLite::createFunctions($this->getPdo());
        $pdo->exec("CREATE TABLE t (v); INSERT INTO t VALUES (1), (2), (4)");

        $this->assertEquals('foobar', $pdo->query("SELECT FOO('bar')")->fetch(PDO::FETCH_COLUMN));
        $this->assertEquals(7, $pdo->query("SELECT ROW_TOTAL(v) FROM t")->fetch(PDO::FETCH_COLUMN));
        $this->assertEquals(7, $pdo->query("SELECT BIT_OR(v) FROM t")->fetch(PDO::FETCH_COLUMN));

        $functions = CustomMySQLite::getFunctionList();
        $this->assertContains('foo', $functions);
        $this->assertContains('row_total', $functions);
        $this->assertContains('bit_or', $functions);
        $this->assertNotContains('not_static', $functions);
        $this->assertNotContains('foo', MySQLite::getFunctionList());

        $this->expectException(PDOException::class);
        $pdo->query("SELECT NOT_STATIC()");
    }

    public static function functionNameProvider(): array
    {
        $functions = [
            'bit_or', 'least', 'if', 'sqrt', 'rand', 'now', 'curdate', 'to_days', 'unix_timestamp', 'year',
            'datediff', 'timediff', 'concat', 'concat_ws', 'format', 'soundex', 'right',
        ];
        return array_combine($functions, array_map(function ($function) {
            return [$function];
        }, $functions));
    }

    /*
     * Aggregate functions
     */

    /**
     * BIT_OR is an aggregate: it combines a column's values across rows, ignoring NULLs.
     *
     * @dataProvider bitOrProvider
     */
    public function testBitOr(int $expected, array $values)
    {
        $pdo = $this->getPdoWithFunctions();
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
        $pdo = $this->getPdoWithFunctions();
        $pdo->exec("CREATE TABLE t (k, v); INSERT INTO t VALUES ('a', 1), ('a', 2), ('b', 8), ('b', 16)");

        $this->assertEquals(
            ['a' => 3, 'b' => 24],
            $pdo->query("SELECT k, BIT_OR(v) FROM t GROUP BY k ORDER BY k")->fetchAll(PDO::FETCH_KEY_PAIR)
        );

        $this->expectException(PDOException::class);
        $pdo->query("SELECT BIT_OR(1, 2)"); /* MySQL's BIT_OR takes exactly one argument */
    }

    /*
     * Comparison, flow control and numeric functions
     */

    /**
     * @dataProvider comparisonFlowAndNumericProvider
     */
    public function testComparisonFlowAndNumericFunctions($expected, string $function, array $args)
    {
        $this->assertFunctionResult($expected, $function, $args);
    }

    public static function comparisonFlowAndNumericProvider(): array
    {
        return [
            /* LEAST; results match MariaDB 10.11 and 11.4 */
            'LEAST of integers' => [1, 'least', [3, 1, 4, 2]],
            'LEAST (MySQL manual)' => [0, 'least', [2, 0]],
            'LEAST of floats (MySQL manual)' => [3.0, 'least', [34.0, 3.0, 5.0, 767.0]],
            'LEAST of strings (MySQL manual)' => ['A', 'least', ['B', 'A', 'C']],
            'LEAST of integer and float' => [2.5, 'least', [2.5, 10]],
            'LEAST of NULL' => [null, 'least', [1, null, 3]],
            'LEAST compares numeric strings as strings' => ['10', 'least', ['10', '9']],
            'LEAST ignores case' => ['a', 'least', ['B', 'a', 'C']],
            'LEAST ignores trailing spaces' => ['a ', 'least', ['a ', 'a']],
            'LEAST returns the first of equal strings' => ['A', 'least', ['A', 'a']],
            'LEAST compares numbers and strings as numbers' => [9, 'least', [10, '9']],
            'LEAST reads leading numbers from strings' => [9.5, 'least', [10, '9.5abc']],
            'LEAST reads other strings as zero' => [0, 'least', ['abc', 5]],
            'IF true' => ['foo', 'if', [true, 'foo', 'bar']],
            'IF false' => ['bar', 'if', [false, 'foo', 'bar']],
            'IF of NULL' => ['bar', 'if', [null, 'foo', 'bar']],
            'IF of a negative number (MySQL manual STRCMP example)' => ['no', 'if', [-1, 'no', 'yes']],
            'IF of a fraction' => ['foo', 'if', [0.5, 'foo', 'bar']],
            'IF of float zero' => ['bar', 'if', [0.0, 'foo', 'bar']],
            'IF of a non-numeric string' => ['bar', 'if', ['abc', 'foo', 'bar']],
            'IF of an empty string' => ['bar', 'if', ['', 'foo', 'bar']],
            'IF of a zero decimal string' => ['bar', 'if', ['0.0', 'foo', 'bar']],
            'IF of a negative zero string' => ['bar', 'if', ['-0', 'foo', 'bar']],
            'IF of a hex string' => ['bar', 'if', ['0x10', 'foo', 'bar']],
            'IF of a fraction string' => ['foo', 'if', ['.5', 'foo', 'bar']],
            'IF of a string with a leading number' => ['foo', 'if', ['1abc', 'foo', 'bar']],
            'IF of a string with leading space' => ['foo', 'if', [' 1', 'foo', 'bar']],
            'SQRT' => [10.0, 'sqrt', [100]],
            'SQRT of NULL' => [null, 'sqrt', [null]],
            'SQRT of a negative number' => [null, 'sqrt', [-16]],
        ];
    }

    /**
     * Like MySQL, LEAST needs at least two arguments.
     *
     * @dataProvider leastArgumentCountProvider
     */
    public function testLeastRequiresTwoArguments(array $args)
    {
        $this->expectException(InvalidArgumentException::class);
        MySQLite::mysql_least(...$args);
    }

    public static function leastArgumentCountProvider(): array
    {
        return [
            'no arguments' => [[]],
            'one argument' => [[1]],
        ];
    }

    public function testRand()
    {
        $pdo = $this->getPdoWithFunctions();
        $pdo->exec("CREATE TABLE testing(id INT PRIMARY KEY NOT NULL)");
        $stmt = $pdo->prepare("INSERT INTO testing (id) VALUES (?)");
        for ($x = 0; $x <= 10; $x++) {
            $stmt->execute([$x]);
        }

        $results = [];
        for ($x = 0; $x < 20; $x++) {
            $results[] = $pdo->query('SELECT id FROM testing ORDER BY RAND() LIMIT 1')->fetch(PDO::FETCH_COLUMN);
        }

        $this->assertNotEquals(array_slice($results, 0, 10), array_slice($results, 10, 10));
    }

    /**
     * @dataProvider seededRandProvider
     */
    public function testSeededRand(float $expected, $seed)
    {
        $this->assertEqualsWithDelta($expected, MySQLite::mysql_rand($seed), 1e-14);

        $stmt = $this->getPdoWithFunctions()->prepare("SELECT RAND(?)");
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

    /*
     * Date and time functions
     */

    /**
     * @dataProvider dateTimeProvider
     */
    public function testDateTimeFunctions($expected, string $function, array $args)
    {
        $this->assertFunctionResult($expected, $function, $args);
    }

    public static function dateTimeProvider(): array
    {
        return [
            /* TO_DAYS */
            'TO_DAYS of year 0' => [365, 'to_days', ['0000-12-31']],
            'TO_DAYS of first day of year 0' => [1, 'to_days', ['0000-01-01']],
            'TO_DAYS before 1970' => [718613, 'to_days', ['1967-07-01']],
            'TO_DAYS (MySQL manual)' => [733321, 'to_days', ['2007-10-07']],
            'TO_DAYS of compact date' => [733321, 'to_days', ['20071007']],
            'TO_DAYS of compact number' => [733321, 'to_days', [20071007]],
            'TO_DAYS of NULL' => [null, 'to_days', [null]],
            'TO_DAYS of garbage' => [null, 'to_days', ['not a date']],
            'TO_DAYS of zero date' => [null, 'to_days', ['0000-00-00']],
            'TO_DAYS of invalid date' => [null, 'to_days', ['2010-02-30']],

            /* UNIX_TIMESTAMP (see also testCurrentTimeFunctions) */
            'UNIX_TIMESTAMP of zero datetime' => [0, 'unix_timestamp', ['0000-00-00 00:00:00']],
            'UNIX_TIMESTAMP of zero date' => [0, 'unix_timestamp', ['0000-00-00']],
            'UNIX_TIMESTAMP before 1970' => [0, 'unix_timestamp', ['1960-01-01 00:00:00']],
            'UNIX_TIMESTAMP after 3001' => [0, 'unix_timestamp', ['3100-01-01 00:00:00']],
            'UNIX_TIMESTAMP of NULL' => [null, 'unix_timestamp', [null]],
            'UNIX_TIMESTAMP of garbage' => [null, 'unix_timestamp', ['not a date']],

            /* YEAR */
            'YEAR of date' => [1987, 'year', ['1987-01-01']],
            'YEAR of datetime' => [2010, 'year', ['2010-11-30 12:34:56']],
            'YEAR of compact datetime' => [2010, 'year', ['20101130123456']],
            'YEAR of incomplete date' => [2010, 'year', ['2010-00-00']],
            'YEAR of zero date' => [0, 'year', ['0000-00-00']],
            'YEAR of invalid date' => [null, 'year', ['2010-02-30']],
            'YEAR of garbage' => [null, 'year', ['not a date']],
            'YEAR of NULL' => [null, 'year', [null]],

            /* DATEDIFF */
            'DATEDIFF ignores time (MySQL manual)' => [1, 'datediff', ['2007-12-31 23:59:59', '2007-12-30']],
            'DATEDIFF negative (MySQL manual)' => [-31, 'datediff', ['2010-11-30 23:59:59', '2010-12-31']],
            'DATEDIFF across leap day' => [2, 'datediff', ['2024-03-01', '2024-02-28']],
            'DATEDIFF of compact dates' => [2, 'datediff', ['20240301', '20240228']],
            'DATEDIFF across centuries' => [365242, 'datediff', ['2000-01-01', '1000-01-01']],
            'DATEDIFF same day' => [0, 'datediff', ['2010-01-01 00:00:00', '2010-01-01 23:59:59']],
            'DATEDIFF of invalid date' => [null, 'datediff', ['2010-02-30', '2010-01-01']],
            'DATEDIFF of zero date' => [null, 'datediff', ['0000-00-00', '2010-01-01']],
            'DATEDIFF of NULL' => [null, 'datediff', [null, '2010-01-01']],
            'DATEDIFF of garbage' => [null, 'datediff', ['2010-01-01', 'not a date']],

            /* TIMEDIFF; the SQL calls also check that ours replaces SQLite 3.43+'s own timediff() */
            'TIMEDIFF fractional (MySQL manual)' => [
                '-00:00:00.000001', 'timediff', ['2000:01:01 00:00:00', '2000:01:01 00:00:00.000001'],
            ],
            'TIMEDIFF over a day (MySQL manual)' => [
                '46:58:57.999999', 'timediff', ['2008-12-31 23:59:59.000001', '2008-12-30 01:01:01.000002'],
            ],
            'TIMEDIFF of date and datetime' => ['24:00:01', 'timediff', ['2010-01-02 00:00:01', '2010-01-01']],
            'TIMEDIFF of times' => ['00:29:45', 'timediff', ['10:00:00', '09:30:15']],
            'TIMEDIFF of time with days' => ['-26:00:00', 'timediff', ['-1 01:00:00', '01:00:00']],
            'TIMEDIFF of negative time' => ['25:00:00', 'timediff', ['1 00:00:00', '-1:00']],
            'TIMEDIFF fraction on one side' => ['01:00:00.500000', 'timediff', ['10:00:00.5', '09:00:00']],
            'TIMEDIFF clamped high' => ['838:59:59', 'timediff', ['2010-01-01 00:00:00', '2000-01-01 00:00:00']],
            'TIMEDIFF clamped low' => ['-838:59:59', 'timediff', ['2000-01-01', '2010-01-01']],
            'TIMEDIFF datetime and time' => [null, 'timediff', ['2010-01-01 00:00:00', '10:00:00']],
            'TIMEDIFF time and datetime' => [null, 'timediff', ['10:00:00', '2010-01-01 00:00:00']],
            'TIMEDIFF of invalid time' => [null, 'timediff', ['10:61:00', '10:00:00']],
            'TIMEDIFF of invalid date' => [null, 'timediff', ['2010-02-30 00:00:00', '2010-01-01 00:00:00']],
            'TIMEDIFF of NULL' => [null, 'timediff', [null, '10:00:00']],
        ];
    }

    /**
     * Date functions must not depend on PHP's time zone, including zones east of UTC and across daylight saving time.
     *
     * @dataProvider timeZoneProvider
     */
    public function testDateFunctionsIgnoreTimeZone(string $zone, $expected, string $function, array $args)
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set($zone);
        try {
            $this->assertFunctionResult($expected, $function, $args);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public static function timeZoneProvider(): iterable
    {
        $cases = [
            [365, 'to_days', ['0000-12-31']],
            [718613, 'to_days', ['1967-07-01']],
            [735599, 'to_days', ['2014-01-01']],
            [735780, 'to_days', ['2014-07-01']],
            [735780, 'to_days', ['2014-07-01 23:59:59']],
            [1, 'datediff', ['2024-03-11', '2024-03-10']], /* North American daylight saving starts 2024-03-10 */
        ];
        foreach (['UTC', 'America/Toronto', 'Europe/Berlin', 'Australia/Sydney', 'Pacific/Kiritimati'] as $zone) {
            foreach ($cases as [$expected, $function, $args]) {
                $name = sprintf("%s: %s('%s')", $zone, $function, implode("', '", $args));
                yield $name => [$zone, $expected, $function, $args];
            }
        }
    }

    /**
     * Functions of the current time. Each result must match the time just before or just after the call, so a clock
     * tick mid-test can't cause a failure.
     */
    public function testCurrentTimeFunctions()
    {
        $before = date("Y-m-d H:i:s");
        $now = MySQLite::mysql_now();
        $this->assertContains($now, [$before, date("Y-m-d H:i:s")]);

        $before = date("Y-m-d");
        $curdate = MySQLite::mysql_curdate();
        $this->assertContains($curdate, [$before, date("Y-m-d")]);

        $before = time();
        $timestamp = MySQLite::mysql_unix_timestamp();
        $this->assertContains($timestamp, [$before, time()]);

        $before = time();
        $timestamp = $this->getPdoWithFunctions()->query("SELECT UNIX_TIMESTAMP()")->fetch(PDO::FETCH_COLUMN);
        $this->assertContains((int)$timestamp, [$before, time()]);

        $time = time();
        $this->assertSame($time, MySQLite::mysql_unix_timestamp(date("Y-m-d H:i:s", $time)));
    }

    /*
     * String functions
     */

    /**
     * @dataProvider stringProvider
     */
    public function testStringFunctions($expected, string $function, array $args)
    {
        $this->assertFunctionResult($expected, $function, $args);
    }

    public static function stringProvider(): array
    {
        return [
            /* CONCAT */
            'CONCAT' => ['test1 test2 test4', 'concat', ['test1', ' ', 'test2', ' ', 'test4']],
            'CONCAT with a NULL' => [null, 'concat', ['a', null, 'b']],

            /* CONCAT_WS */
            'CONCAT_WS' => ['test1|test2|test4', 'concat_ws', ['|', 'test1', 'test2', 'test4']],
            'CONCAT_WS skips NULLs' => ['a|b', 'concat_ws', ['|', 'a', null, 'b']],
            'CONCAT_WS keeps empty strings' => ['a||b', 'concat_ws', ['|', 'a', '', 'b']],
            'CONCAT_WS with NULL separator' => [null, 'concat_ws', [null, 'a', 'b']],

            /* FORMAT */
            'FORMAT with no decimals' => ['12,312,312', 'format', ['12312312.232', 0]],
            'FORMAT with decimals' => ['12.2', 'format', ['12.232', 1]],
            'FORMAT with NULL number' => [null, 'format', [null, 2]],
            'FORMAT with NULL decimals' => [null, 'format', [1.5, null]],

            /* SOUNDEX */
            'SOUNDEX' => ['F000', 'soundex', ['foo']],
            'SOUNDEX of NULL' => [null, 'soundex', [null]],

            /* RIGHT */
            'RIGHT (MySQL manual)' => ['rbar', 'right', ['foobarbar', 4]],
            'RIGHT longer than string' => ['abc', 'right', ['abc', 10]],
            'RIGHT of zero characters' => ['', 'right', ['abc', 0]],
            'RIGHT of negative length' => ['', 'right', ['abc', -1]],
            'RIGHT counts characters, not bytes' => ['ïve', 'right', ['naïve', 3]],
            'RIGHT of a number' => ['23', 'right', [123, 2]],
            'RIGHT of NULL string' => [null, 'right', [null, 1]],
            'RIGHT of NULL length' => [null, 'right', ['abc', null]],
        ];
    }
}
