<?php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    }
} else {
    // Fallback PSR-4 and directory folder autoloader
    spl_autoload_register(function ($class) {
        // Wisp namespace
        $prefix = 'Wisp\\';
        $base_dir = __DIR__ . '/../src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative_class = substr($class, $len);
            $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }

        // Global namespace auto-load folders (services & middlewares)
        $dirs = [__DIR__ . '/../services/', __DIR__ . '/../middlewares/'];
        foreach ($dirs as $dir) {
            $file = $dir . str_replace('\\', '/', $class) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    });

    // Fallback simple .env parser
    if (file_exists(__DIR__ . '/../.env')) {
        $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
        }
    }
}

// Register class aliases to allow using Response, Request, and DB directly in routes without imports
class_alias('Wisp\Response', 'Response');
class_alias('Wisp\Request', 'Request');
class_alias('Wisp\DB', 'DB');

$app = new Wisp\App();

// Automatically load all plugins from the plugins/ folder
$pluginsDir = __DIR__ . '/../plugins';
if (is_dir($pluginsDir)) {
    foreach (scandir($pluginsDir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $fullPath = $pluginsDir . '/' . $item;
        if (is_dir($fullPath)) {
            $pluginFile = $fullPath . '/' . $item . '.php';
            if (file_exists($pluginFile)) {
                require_once $pluginFile;
            }
        } elseif (is_file($fullPath) && pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
            require_once $fullPath;
        }
    }
}

// Load Service registrations
if (file_exists(__DIR__ . '/../config/services.php')) {
    require_once __DIR__ . '/../config/services.php';
}

// Load Global Middleware registrations
if (file_exists(__DIR__ . '/../config/middlewares.php')) {
    require_once __DIR__ . '/../config/middlewares.php';
}

// Automatically load all route files from the routes/ folder
$routesDir = __DIR__ . '/../routes';
if (is_dir($routesDir)) {
    foreach (glob($routesDir . '/*.php') as $routeFile) {
        require_once $routeFile;
    }
}

$app->run();
