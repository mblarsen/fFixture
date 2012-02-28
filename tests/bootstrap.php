<?php

/**
 * Prepares classes and database for tests.
 */

// Some basics

date_default_timezone_set('Europe/Copenhagen');
define("FIXTURES_ROOT", dirname(__FILE__) . '/fixtures/');

include dirname(__FILE__) . '/flourish/fLoader.php';
fLoader::best();

// Include fFixture

include dirname(__FILE__) . '/../fFixture.php';

// Emtpy database

$db = new fDatabase('sqlite', dirname(__FILE__) . '/test.db');
$db_setup_file = new fFile(dirname(__FILE__) . '/bootstrap.sql');
define("RESET_DATABASE_SQL", $db_setup_file->read());
$db->execute(RESET_DATABASE_SQL);

// Setup ORM

fORMDatabase::attach($db);

// Pretend implementations of fActiveRecord

class Category extends fActiveRecord {};
class Product extends fActiveRecord {};
class Shop extends fActiveRecord {};
class User extends fActiveRecord {};