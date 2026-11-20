MySQLite
========
[![Build Status](https://github.com/Vectorface/MySQLite/actions/workflows/ci.yml/badge.svg)](https://github.com/Vectorface/MySQLite/actions)
[![Code Coverage](https://scrutinizer-ci.com/g/Vectorface/MySQLite/badges/coverage.png?b=main)](https://scrutinizer-ci.com/g/Vectorface/MySQLite/?branch=main)

MySQLite is an easy way to add MySQL functions to SQLite accessed through PDO. This can be useful for testing and development where an SQLite database may be more practical than a real MySQL database.

Usage
-----

Using MySQLite is meant to be a one-step affair, with no configuration required:

```php
use Vectorface\MySQLite\MySQLite;

// Create a PDO instance on an SQLite database. (On PHP 8.0 to 8.3, use new PDO('sqlite::memory:') instead.)
$pdo = PDO::connect('sqlite::memory:');

// Create compatibility functions for use within that database connection.
MySQLite::createFunctions($pdo);

// Use it.
$days = $pdo->query("SELECT DATEDIFF('2025-12-25', '2025-11-19')")->fetchColumn(); // 36
// Wait... That works now?!? What the what?!?
```

On PHP 8.4 and later, `PDO::connect()` returns a `Pdo\Sqlite` instance, as does `new Pdo\Sqlite(...)`. A plain `new PDO('sqlite:...')` still works, but MySQLite must then use PDO methods that are deprecated as of PHP 8.5, so each function it adds raises a deprecation notice.

It is also possible to use it as a one-liner:

```php
$pdo = MySQLite::createFunctions(PDO::connect('sqlite::memory:'));
```

You can get a list of supported functions with ```MySQLite::getFunctionList()```.


Limitations
-----------

MySQLite only provides a limited subset of MySQL functions. There are a lot of MySQL functions, so there's both a developer cost and performance penalty to adding them all.

If you want to add more, it's easy. You only need to add a public static method called mysql_[function name] into one of the traits in src/Vectorface/MySQLite/MySQL.

For example, to create a function ```FOO()``` with silly behavior in StringFunctions.php:

```php
    ...
    public static function mysql_foo($foo)
    {
        if ($foo == "foo") {
            return "bar";
        }
        return $foo;
    }
    ...
```

With the above definition present, `FOO()` will automatically be registered when ```MySQLite::createFunctions($pdo)``` is used.

To add functions in your own project instead, extend MySQLite and call `createFunctions` on your class:

```php
class MyFunctions extends MySQLite
{
    public static function mysql_foo($foo)
    {
        return $foo == "foo" ? "bar" : $foo;
    }
}

MyFunctions::createFunctions($pdo); // Adds FOO() along with all of MySQLite's functions.
```
