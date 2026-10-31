<?php
namespace Wisp;

// Startup steps shared by public/index.php (HTTP) and Console::bootstrap (CLI), so the two
// entry points can't drift apart again (autoloader, .env parsing and plugin loading used to
// be copy-pasted in both).
class Bootstrap {
    public static function init(string $root): void {
        self::registerAutoloader($root);
        self::loadEnv($root);
    }

    public static function registerAutoloader(string $root): void {
        $vendorAutoload = $root . '/vendor/autoload.php';
        $hasVendor = is_file($vendorAutoload);
        if ($hasVendor) {
            require_once $vendorAutoload;
        }

        // composer.json only maps Wisp\ => src/. services/ and middlewares/ hold classes in the
        // global namespace, which composer never sees, so this loader is registered whether or
        // not vendor/ exists. Wisp\ itself is only handled here when composer isn't around.
        spl_autoload_register(function ($class) use ($root, $hasVendor) {
            if (!preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*$/', $class)) {
                return;
            }
            $path = str_replace('\\', '/', $class) . '.php';

            if (!$hasVendor && strncmp($class, 'Wisp\\', 5) === 0) {
                $file = $root . '/src/' . substr($path, 5);
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }

            foreach (['services', 'middlewares'] as $dir) {
                $file = $root . '/' . $dir . '/' . $path;
                if (is_file($file)) {
                    require_once $file;
                    return;
                }
            }
        });
    }

    public static function loadEnv(string $root): void {
        $file = $root . '/.env';
        if (!is_file($file)) {
            return;
        }

        if (class_exists('Dotenv\Dotenv')) {
            \Dotenv\Dotenv::createImmutable($root)->load();
            return;
        }

        foreach (self::parseEnv((string)file_get_contents($file)) as $name => $value) {
            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    // Minimal .env parser used when phpdotenv isn't installed. Follows phpdotenv for the
    // cases that matter: "export " prefix, quoted values (with a trailing comment allowed
    // after the closing quote) and whitespace-introduced "# comments" on unquoted values.
    public static function parseEnv(string $contents): array {
        $vars = [];
        foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = ltrim(substr($line, 7));
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name)) {
                continue;
            }
            $vars[$name] = self::parseEnvValue(ltrim(substr($line, $pos + 1)));
        }
        return $vars;
    }

    private static function parseEnvValue(string $value): string {
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/s', $value, $m)) {
            return strtr($m[1], ['\\"' => '"', '\\\\' => '\\', '\\n' => "\n", '\\r' => "\r", '\\t' => "\t"]);
        }
        if (preg_match("/^'([^']*)'/", $value, $m)) {
            return $m[1];
        }
        return trim(preg_replace('/(^|\s)#.*$/', '', $value));
    }

    // Loads plugins, config/, then routes/. All of it runs in this function's scope with $app
    // declared global, so those files can use either `global $app;` or a bare $app.
    public static function load(App $appInstance, string $root): void {
        global $app;
        $app = $appInstance;

        $pluginsDir = $root . '/plugins';
        if (is_dir($pluginsDir)) {
            foreach (scandir($pluginsDir) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $fullPath = $pluginsDir . '/' . $item;
                $pluginFile = null;
                if (is_dir($fullPath)) {
                    if (is_file($fullPath . '/' . $item . '.php')) {
                        $pluginFile = $fullPath . '/' . $item . '.php';
                    }
                } elseif (is_file($fullPath) && pathinfo($fullPath, PATHINFO_EXTENSION) === 'php') {
                    $pluginFile = $fullPath;
                }
                if ($pluginFile === null) {
                    continue;
                }

                // One plugin that can't load (e.g. syntax newer than this PHP version) must not
                // take the whole app down with it; its routes/middlewares simply won't exist.
                try {
                    require_once $pluginFile;
                } catch (\Throwable $e) {
                    error_log('[Wisp\Bootstrap] Plugin "' . $item . '" failed to load and was skipped: '
                        . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                }
            }
        }

        foreach (['config/services.php', 'config/middlewares.php'] as $config) {
            if (is_file($root . '/' . $config)) {
                require_once $root . '/' . $config;
            }
        }

        $routesDir = $root . '/routes';
        if (is_dir($routesDir)) {
            foreach (glob($routesDir . '/*.php') as $routeFile) {
                require_once $routeFile;
            }
        }
    }
}
