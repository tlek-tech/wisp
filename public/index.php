<?php
// Base directory of the application code (everything outside the public/ webroot: vendor/,
// src/, services/, middlewares/, config/, routes/, plugins/, .env). Every path below is
// derived from this one constant, so pointing public/ at an app root that lives somewhere
// other than its parent directory (e.g. public/ split out to its own docroot) only requires
// changing this line.
define('APP_ROOT', __DIR__ . '/..');

// Autoloading, .env parsing and plugin/config/route loading live in Bootstrap so the CLI
// (src/Console.php) boots the app exactly the same way.
require_once APP_ROOT . '/src/Bootstrap.php';
Wisp\Bootstrap::init(APP_ROOT);

// Register class aliases to allow using Response, Request, and DB directly in routes without imports
class_alias('Wisp\Response', 'Response');
class_alias('Wisp\Request', 'Request');
class_alias('Wisp\DB', 'DB');

$app = new Wisp\App();

// Plugins first, then config/services.php, config/middlewares.php and every routes/*.php
Wisp\Bootstrap::load($app, APP_ROOT);

$app->run();
