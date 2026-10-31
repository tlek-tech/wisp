<?php
namespace Wisp;

class Console {
    private static array $commands = [];
    private static bool $bootstrapped = false;
    private static bool $builtinCommandsRegistered = false;
    private static ?App $app = null;

    public static function register(string $name, callable $handler, string $description = ''): void {
        self::$commands[$name] = [
            'handler' => $handler,
            'description' => $description,
        ];
    }

    public static function getCommands(): array {
        return self::$commands;
    }

    public static function bootstrap(string $appRoot): App {
        if (self::$bootstrapped && self::$app !== null) {
            return self::$app;
        }

        if (!defined('APP_ROOT')) {
            define('APP_ROOT', $appRoot);
        }

        // 1. Autoloader and .env
        if (file_exists(APP_ROOT . '/vendor/autoload.php')) {
            require_once APP_ROOT . '/vendor/autoload.php';
            if (file_exists(APP_ROOT . '/.env') && class_exists('Dotenv\Dotenv')) {
                $dotenv = \Dotenv\Dotenv::createImmutable(APP_ROOT);
                $dotenv->load();
            }
        } else {
            // Fallback autoloader
            spl_autoload_register(function ($class) {
                $prefix = 'Wisp\\';
                $base_dir = APP_ROOT . '/src/';
                $len = strlen($prefix);
                if (strncmp($prefix, $class, $len) === 0) {
                    $relative_class = substr($class, $len);
                    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        return;
                    }
                }

                $dirs = [APP_ROOT . '/services/', APP_ROOT . '/middlewares/'];
                foreach ($dirs as $dir) {
                    $file = $dir . str_replace('\\', '/', $class) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                        return;
                    }
                }
            });

            // Fallback .env parser
            if (file_exists(APP_ROOT . '/.env')) {
                $lines = file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

        // Aliases
        if (!class_exists('Response', false)) {
            class_alias('Wisp\Response', 'Response');
        }
        if (!class_exists('Request', false)) {
            class_alias('Wisp\Request', 'Request');
        }
        if (!class_exists('DB', false)) {
            class_alias('Wisp\DB', 'DB');
        }

        self::$app = new App();
        $GLOBALS['app'] = self::$app;
        global $app;
        $app = self::$app;

        // Load plugins
        $pluginsDir = APP_ROOT . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (scandir($pluginsDir) as $item) {
                if ($item === '.' || $item === '..') continue;
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

        // Load configs
        if (file_exists(APP_ROOT . '/config/services.php')) {
            require_once APP_ROOT . '/config/services.php';
        }
        if (file_exists(APP_ROOT . '/config/middlewares.php')) {
            require_once APP_ROOT . '/config/middlewares.php';
        }

        // Load routes
        $routesDir = APP_ROOT . '/routes';
        if (is_dir($routesDir)) {
            foreach (glob($routesDir . '/*.php') as $routeFile) {
                require_once $routeFile;
            }
        }

        self::$bootstrapped = true;
        return self::$app;
    }

    public static function run(array $argv, string $appRoot): int {
        self::registerBuiltinCommands();

        // Boot app to register routes, plugins and custom commands
        $app = self::bootstrap($appRoot);

        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        if (!isset(self::$commands[$command])) {
            self::error("Unknown command: '{$command}'.");
            self::info("Run 'php wisp help' to view all available commands.");
            return 1;
        }

        try {
            $exitCode = call_user_func(self::$commands[$command]['handler'], $args, $app, $appRoot);
            return is_int($exitCode) ? $exitCode : 0;
        } catch (\Throwable $e) {
            self::error("Command failed: " . $e->getMessage());
            if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
                echo $e->getTraceAsString() . "\n";
            }
            return 1;
        }
    }

    private static function registerBuiltinCommands(): void {
        if (self::$builtinCommandsRegistered) {
            return;
        }
        self::$builtinCommandsRegistered = true;

        // help / list
        self::register('help', function() {
            echo self::color("🌬️ Wisp CLI", 'cyan') . " " . self::color("v1.2.0", 'dim') . "\n";
            echo "A lightweight command-line tool for the Wisp micro-framework.\n\n";
            echo self::color("Usage:", 'yellow') . "\n";
            echo "  php wisp <command> [options] [arguments]\n\n";
            echo self::color("Available Commands:", 'yellow') . "\n";

            $maxLen = 0;
            foreach (self::$commands as $name => $cmd) {
                $maxLen = max($maxLen, strlen($name));
            }

            foreach (self::$commands as $name => $cmd) {
                $padding = str_repeat(' ', $maxLen - strlen($name) + 3);
                echo "  " . self::color($name, 'green') . $padding . $cmd['description'] . "\n";
            }
            echo "\n";
            return 0;
        }, 'Display available commands');

        self::register('list', function($args) {
            return call_user_func(self::$commands['help']['handler'], $args);
        }, 'Alias for help');

        // route:list
        self::register('route:list', function($args, App $app) {
            $routes = $app->getRoutes();
            if (empty($routes)) {
                self::warn("No routes registered in the application.");
                return 0;
            }

            echo self::color("Registered Routes (" . count($routes) . "):", 'cyan') . "\n";

            $headers = ['Method', 'Path', 'Middlewares', 'Handler'];
            $rows = [];

            foreach ($routes as $r) {
                $mwList = [];
                foreach ($r['middlewares'] as $mw) {
                    if (is_string($mw)) {
                        $mwList[] = basename(str_replace('\\', '/', $mw));
                    } elseif (is_array($mw) && count($mw) === 2 && is_object($mw[0])) {
                        $mwList[] = basename(str_replace('\\', '/', get_class($mw[0]))) . '@' . $mw[1];
                    } else {
                        $mwList[] = 'Closure';
                    }
                }
                $mwStr = !empty($mwList) ? implode(', ', $mwList) : '-';

                $cbStr = 'Closure';
                if (is_string($r['callback'])) {
                    $cbStr = $r['callback'];
                } elseif (is_array($r['callback']) && count($r['callback']) === 2) {
                    $cName = is_object($r['callback'][0]) ? get_class($r['callback'][0]) : (string)$r['callback'][0];
                    $cbStr = basename(str_replace('\\', '/', $cName)) . '@' . $r['callback'][1];
                } elseif ($r['callback'] instanceof \Closure) {
                    try {
                        $ref = new \ReflectionFunction($r['callback']);
                        $file = basename($ref->getFileName() ?: 'inline');
                        $line = $ref->getStartLine();
                        $cbStr = "Closure ({$file}:{$line})";
                    } catch (\Throwable $e) {}
                }

                $rows[] = [
                    self::formatMethod($r['method']),
                    $r['path'],
                    $mwStr,
                    $cbStr
                ];
            }

            self::table($headers, $rows);
            return 0;
        }, 'List all registered HTTP routes');

        // serve
        self::register('serve', function(array $args, App $app, string $appRoot) {
            $host = '127.0.0.1';
            $port = '8000';

            foreach ($args as $arg) {
                if (strpos($arg, '--host=') === 0) {
                    $host = substr($arg, 7);
                } elseif (strpos($arg, '--port=') === 0) {
                    $port = substr($arg, 7);
                }
            }

            echo self::color("🌬️ Wisp Development Server", 'cyan') . "\n";
            echo "   URL:           " . self::color("http://{$host}:{$port}", 'green') . "\n";
            echo "   Document Root: " . self::color($appRoot . '/public', 'dim') . "\n";
            echo "   PHP Version:   " . self::color(PHP_VERSION, 'dim') . "\n";
            echo "   Press Ctrl+C to stop the server.\n\n";

            $cmd = sprintf('php -S %s:%s router.php', escapeshellarg($host), escapeshellarg($port));
            passthru($cmd);
            return 0;
        }, 'Start the local PHP development server');

        // make:service
        self::register('make:service', function(array $args, App $app, string $appRoot) {
            $name = $args[0] ?? null;
            if (!$name) {
                self::error("Missing service name. Example: php wisp make:service PaymentService");
                return 1;
            }

            $name = ucfirst(trim($name));
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                self::error("Invalid service class name: '{$name}'");
                return 1;
            }

            $dir = $appRoot . '/services';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filePath = $dir . '/' . $name . '.php';
            if (file_exists($filePath)) {
                self::error("Service '{$name}' already exists at {$filePath}");
                return 1;
            }

            $code = <<<PHP
<?php

class {$name} {
    private PDO \$db;

    public function __construct(PDO \$db) {
        \$this->db = \$db;
    }

    public function handle() {
        // Implement {$name} logic here
    }
}

PHP;
            file_put_contents($filePath, $code);
            self::success("Service created successfully: services/{$name}.php");
            return 0;
        }, 'Create a new auto-wired service class');

        // make:middleware
        self::register('make:middleware', function(array $args, App $app, string $appRoot) {
            $name = $args[0] ?? null;
            if (!$name) {
                self::error("Missing middleware name. Example: php wisp make:middleware CheckRoleMiddleware");
                return 1;
            }

            $name = ucfirst(trim($name));
            if (!preg_match('/Middleware$/', $name)) {
                $name .= 'Middleware';
            }

            $dir = $appRoot . '/middlewares';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filePath = $dir . '/' . $name . '.php';
            if (file_exists($filePath)) {
                self::error("Middleware '{$name}' already exists at {$filePath}");
                return 1;
            }

            $code = <<<PHP
<?php

class {$name} {
    public function handle(\$req, \$next, \$env) {
        // Perform pre-request verification or authorization here

        \$res = \$next(\$req);

        // Perform post-response actions here

        return \$res;
    }
}

PHP;
            file_put_contents($filePath, $code);
            self::success("Middleware created successfully: middlewares/{$name}.php");
            return 0;
        }, 'Create a new middleware class');

        // make:route
        self::register('make:route', function(array $args, App $app, string $appRoot) {
            $name = $args[0] ?? null;
            if (!$name) {
                self::error("Missing route name. Example: php wisp make:route products");
                return 1;
            }

            $name = strtolower(trim($name));
            $name = preg_replace('/\.php$/', '', $name);

            $dir = $appRoot . '/routes';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filePath = $dir . '/' . $name . '.php';
            if (file_exists($filePath)) {
                self::error("Route file '{$name}.php' already exists at {$filePath}");
                return 1;
            }

            $code = <<<PHP
<?php
global \$app;

\$app->group('/{$name}', function(\$app) {
    \$app->get('/', function(\$req) {
        return Response::json([
            'message' => 'Index of {$name}'
        ]);
    });

    \$app->get('/:id', function(\$req) {
        return Response::json([
            'id' => \$req->params['id'] ?? null
        ]);
    });
});

PHP;
            file_put_contents($filePath, $code);
            self::success("Route created successfully: routes/{$name}.php");
            return 0;
        }, 'Create a new route file');

        // env:key
        self::register('env:key', function(array $args, App $app, string $appRoot) {
            $key = bin2hex(random_bytes(32));
            $envPath = $appRoot . '/.env';

            if (!file_exists($envPath)) {
                self::warn(".env file not found. Generated Key:");
                echo "  " . self::color($key, 'green') . "\n";
                return 0;
            }

            $content = file_get_contents($envPath);
            if (preg_match('/^API_SECRET_KEY=.*$/m', $content)) {
                $content = preg_replace('/^API_SECRET_KEY=.*$/m', "API_SECRET_KEY={$key}", $content);
            } else {
                $content .= "\nAPI_SECRET_KEY={$key}\n";
            }

            file_put_contents($envPath, $content);
            self::success("API Secret Key generated and saved to .env:");
            echo "  " . self::color($key, 'green') . "\n";
            return 0;
        }, 'Generate a secure API Secret Key and update .env');
    }

    // --- Formatting & Output Helpers ---

    public static function table(array $headers, array $rows): void {
        $colWidths = [];
        foreach ($headers as $i => $h) {
            $colWidths[$i] = mb_strlen(self::stripAnsi($h));
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $colWidths[$i] = max($colWidths[$i] ?? 0, mb_strlen(self::stripAnsi((string)$cell)));
            }
        }

        // Draw separator
        $drawSep = function() use ($colWidths) {
            echo "+";
            foreach ($colWidths as $w) {
                echo str_repeat('-', $w + 2) . "+";
            }
            echo "\n";
        };

        $drawSep();

        // Draw Header
        echo "|";
        foreach ($headers as $i => $h) {
            $pad = $colWidths[$i] - mb_strlen(self::stripAnsi($h));
            echo " " . self::color($h, 'bold') . str_repeat(' ', $pad) . " |";
        }
        echo "\n";

        $drawSep();

        // Draw Rows
        foreach ($rows as $row) {
            echo "|";
            foreach ($row as $i => $cell) {
                $cellStr = (string)$cell;
                $pad = $colWidths[$i] - mb_strlen(self::stripAnsi($cellStr));
                echo " " . $cellStr . str_repeat(' ', $pad) . " |";
            }
            echo "\n";
        }

        $drawSep();
    }

    public static function color(string $text, string $style): string {
        $styles = [
            'green'  => "\033[32m",
            'cyan'   => "\033[36m",
            'yellow' => "\033[33m",
            'red'    => "\033[31m",
            'blue'   => "\033[34m",
            'bold'   => "\033[1m",
            'dim'    => "\033[2m",
            'reset'  => "\033[0m",
        ];

        return ($styles[$style] ?? '') . $text . $styles['reset'];
    }

    private static function stripAnsi(string $text): string {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    private static function formatMethod(string $method): string {
        $m = strtoupper($method);
        switch ($m) {
            case 'GET': return self::color($m, 'green');
            case 'POST': return self::color($m, 'blue');
            case 'PUT':
            case 'PATCH': return self::color($m, 'yellow');
            case 'DELETE': return self::color($m, 'red');
            default: return self::color($m, 'dim');
        }
    }

    public static function info(string $msg): void {
        echo self::color("INFO: ", 'cyan') . $msg . "\n";
    }

    public static function success(string $msg): void {
        echo self::color("SUCCESS: ", 'green') . $msg . "\n";
    }

    public static function warn(string $msg): void {
        echo self::color("WARN: ", 'yellow') . $msg . "\n";
    }

    public static function error(string $msg): void {
        echo self::color("ERROR: ", 'red') . $msg . "\n";
    }
}
