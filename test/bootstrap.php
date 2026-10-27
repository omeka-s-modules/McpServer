<?php
/**
 * Bootstraps the module's tests, run from Omeka's root:
 *
 *   vendor/bin/phpunit -c modules/McpServer/test/phpunit.xml
 *
 * The unit tests need nothing more. The database tests need core's test
 * database, configured in application/test/config/database.ini, which they
 * empty and install afresh.
 */
require dirname(__DIR__, 3) . '/bootstrap.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');

// The module's classes, which Omeka autoloads only once the module is
// active, and the tests' own.
$loader = require OMEKA_PATH . '/vendor/autoload.php';
$loader->addPsr4('McpServer\\', [dirname(__DIR__) . '/src', dirname(__DIR__)]);
$loader->addPsr4('McpServerTest\\', __DIR__ . '/McpServerTest');

// What a real request always has: the host public addresses are built on,
// and the client's address, which core's key sign-in records.
$_SERVER['HTTP_HOST'] = 'example.com';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
