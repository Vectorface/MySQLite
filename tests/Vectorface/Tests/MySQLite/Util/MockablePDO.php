<?php

namespace Vectorface\Tests\MySQLite\Util;

/**
 * Work around mocking issue in PDO.
 *
 * Credit: http://erichogue.ca/2013/02/best-practices/mocking-pdo-in-phpunit/
 */
class MockablePDO extends \PDO {
    public function __construct()
    {
    }
}
