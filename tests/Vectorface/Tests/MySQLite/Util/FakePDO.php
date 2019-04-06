<?php

namespace Vectorface\Tests\MySQLite\Util;

/**
 * Work around mocking issue in PDO.
 *
 * Credit: http://erichogue.ca/2013/02/best-practices/mocking-pdo-in-phpunit/
 */
class FakePDO extends \PDO
{
    /**
     * Empty constructor.
     */
    public function __construct()
    {
    }

    /**
     * A map of attributes to be passed back from getAttribute.
     *
     * @var mixed[]
     */
    public $attributes = [];

    /**
     * Get the value of an attribute. Uses an element of the Attributes array if available, falling back to parent.
     *
     * @param mixed $attr The attribute whose value is to be fetched.
     * @return mixed The value of the attribute.
     */
    public function getAttribute($attr)
    {
        return isset($this->attributes[$attr]) ? $this->attributes[$attr] : parent::getAttribute($attr);
    }
}
