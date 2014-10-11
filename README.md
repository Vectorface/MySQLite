MySQLite
========
[![Build Status](https://travis-ci.org/Vectorface/MySQLite.svg?branch=master)](https://travis-ci.org/Vectorface/MySQLite)
[![Code Coverage](https://scrutinizer-ci.com/g/Vectorface/MySQLite/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/Vectorface/MySQLite/?branch=master)

MySQLite is an easy way to add MySQL functions to SQLite accessed through PDO. This can be useful for testing and development where an SQLite database may be more practical than a real MySQL database.

Usage
-----

Using MySQLite is meant to be a one-step affair, with no configuration required:

```php
use Vectorface\MySQLite\MySQLite;

// Create a PDO instance on an SQLite database
$pdo = new PDO('sqlite::memory:');

// Create compatibility functions for use within that database connection.
MySQLite::createFunctions($pdo);

// Use it.
$three = $pdo->query("SELECT BIT_OR(1, 2)")->fetch(PDO::FETCH_COLUMN);
// Wait... That works now?!? What the what?!?
```

It is also possible to use it as a one-liner:

```php
$pdo = MySQLite::createFunctions(new PDO('sqlite::memory:'));

```

You can get a list of supported functions with ```MySQLite::getFunctionList()```.


Limitations
-----------

MySQLite only provides a limited subset of MySQL functions. There are a lot of MySQL functions, so there's both a developer cost and performance penalty to adding them all.

If you want to add more, it's easy. You only need to add a function called mysql_[function name] into one of the traits in src/Vectorface/MySQLite/MySQL.

For example, to create a function ```FOO()``` with silly behavior in String.php:

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

With the above definition present, String::mysql_foo will automatically be registered with ```MySQLite::createFunctions($pdo)``` is used.
