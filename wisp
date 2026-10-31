#!/usr/bin/env php
<?php
define('APP_ROOT', __DIR__);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/Console.php';
    require_once __DIR__ . '/src/App.php';
    require_once __DIR__ . '/src/Request.php';
    require_once __DIR__ . '/src/Response.php';
    require_once __DIR__ . '/src/DB.php';
}

$status = Wisp\Console::run($argv, __DIR__);
exit($status);
