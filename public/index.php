<?php
/**
* PHPCI - Continuous Integration for PHP
*
* @copyright    Copyright 2014, Block 8 Limited.
* @license      https://github.com/Block8/PHPCI/blob/master/LICENSE.md
* @link         https://www.phptesting.org/
*/

// Let the cli-server serve non-PHP, static files.
if (php_sapi_name() === 'cli-server' &&
    is_file($_SERVER["SCRIPT_FILENAME"]) &&
    pathinfo($_SERVER["SCRIPT_FILENAME"], PATHINFO_EXTENSION) !== 'php'
) {
    return false;
}

session_set_cookie_params(43200); // Set session cookie to last 12 hours.
session_start();

require_once(__DIR__ . '/../bootstrap.php');

$fc = new PHPCI\Application($config, new b8\Http\Request());
print $fc->handleRequest();
