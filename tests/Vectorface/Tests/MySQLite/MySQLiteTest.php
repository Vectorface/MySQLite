<?php

namespace Vectorface\Tests\MySQLite;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit_Framework_TestCase;
use Vectorface\MySQLite\MySQLite;
use Vectorface\Tests\MySQLite\Util\FakePDO;

/**
 * Test MySQLite; This could be split up into individual function categories later.
 */
class MySQLiteTest extends PHPUnit_Framework_TestCase
{
    /**
     * Test the various compatibility functions.
     */
    public function testCompatibilityFunctions()
    {
        /* Aggregate functions */
        $this->assertEquals(1 | 2 | 4, MySQLite::mysql_bit_or(1, 2, 4));

        /* Comparison functions */
        $this->assertEquals(1, MySQLite::mysql_least(1, 2, 3, 4));
        try {
            MySQLite::mysql_least();
            $this->fail("Least with no arguments is not valid");
        } catch (\InvalidArgumentException $e) {
            /* Expected */
        }

        /* Date/Time functions */
        $this->assertEquals(date("Y-m-d H:i:s"), MySQLite::mysql_now());
        $this->assertEquals(365, MySQLite::mysql_to_days("0000-12-31"));
        $this->assertEquals(718613, MySQLite::mysql_to_days("1967-07-01"));
        $this->assertEquals(735599, MySQLite::mysql_to_days("2014-01-01"));
        $this->assertEquals(time(), MySQLite::mysql_unix_timestamp());
        $time = time();
        $this->assertEquals($time, MySQLite::mysql_unix_timestamp(date("Y-m-d H:i:s")));

        /* Flow control functions */
        $this->assertEquals("foo", MySQLite::mysql_if(true, "foo", "bar"));
        $this->assertEquals("bar", MySQLite::mysql_if(false, "foo", "bar"));

        /* Numeric functions */
        $this->assertEquals(10, MySQLite::mysql_sqrt(100));

        /* String functions */
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

        $pdo = new PDO("sqlite::memory:", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        try {
            $pdo->query("SELECT BIT_OR(1, 2)");
            $this->fail("Attempt to BIT_OR two values is expected to fail before the function is created.");
        } catch (PDOException $e) {
            /* Expected */
        }

        $this->assertTrue($pdo === MySQLite::createFunctions($pdo));

        $this->assertEquals(3, $pdo->query("SELECT BIT_OR(1, 2)")->fetch(PDO::FETCH_COLUMN));
    }

    /**
     * Test that registered functions are listed and available.
     */
    public function testGetFunctionList()
    {
        $this->assertTrue(in_array("bit_or", MySQLite::getFunctionList()));
        $this->assertTrue(in_array("unix_timestamp", MySQLite::getFunctionList()));
    }
}
