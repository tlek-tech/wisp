<?php
namespace Wisp;

class App {
    private array $routes = [];
    private array $middlewares = [];
    private array $services = [];
    private array $resolvedServices = [];
    private array $callbackResolvers = [];
    private $notFoundHandler;

    public function __construct() {
        $this->notFoundHandler = function(Request $req) {
            return Response::json(['error' => 'Not Found'], 404);
        };
    }

    public function registerCallbackResolver(callable $resolver) {
        $this->callbackResolvers[] = $resolver;
    }

    // --- Service Container Methods (with Auto-wiring) ---

    public function set(string $name, callable $factory) {
        $this->services[$name] = $factory;
    }

    public function resolve(string $name) {
        if (!isset($this->resolvedServices[$name])) {
            if (isset($this->services[$name])) {
                $this->resolvedServices[$name] = $this->services[$name]($this);
            } else {
                // Auto-wiring: Try to resolve class from services folder automatically
                $className = ucfirst($name);
                if (class_exists($className)) {
                    $this->resolvedServices[$name] = $this->autowire($className);
                } else {
                    throw new \Exception("Service '$name' could not be resolved.");
                }
            }
        }
        return $this->resolvedServices[$name];
    }

    public function __get(string $name) {
        return $this->resolve($name);
    }

    private function autowire(string $className) {
        $reflector = new \ReflectionClass($className);
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            try {
                $dependencies[] = $this->resolve($paramName);
            } catch (\Exception $e) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();
                } else {
                    throw new \Exception("Cannot auto-wire parameter '{$paramName}' for class '{$className}': " . $e->getMessage());
                }
            }
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    // --- Middleware Registration ---

    public function use($path, $callback = null) {
        if ($callback === null) {
            $callback = $path;
            $path = '/*';
        }
        $this->middlewares[] = [
            'path' => $path,
            'callback' => $callback
        ];
    }

    // --- HTTP Route Registration ---

    public function get(string $path, ...$handlers) {
        $this->addRoute('GET', $path, $handlers);
    }

    public function post(string $path, ...$handlers) {
        $this->addRoute('POST', $path, $handlers);
    }

    public function put(string $path, ...$handlers) {
        $this->addRoute('PUT', $path, $handlers);
    }

    public function delete(string $path, ...$handlers) {
        $this->addRoute('DELETE', $path, $handlers);
    }

    public function patch(string $path, ...$handlers) {
        $this->addRoute('PATCH', $path, $handlers);
    }

    public function options(string $path, ...$handlers) {
        $this->addRoute('OPTIONS', $path, $handlers);
    }

    public function any(string $path, ...$handlers) {
        $this->addRoute('*', $path, $handlers);
    }

    public function setNotFound(callable $callback) {
        $this->notFoundHandler = $callback;
    }

    private function addRoute(string $method, string $path, array $handlers) {
        // Last item is the controller callback
        $callback = array_pop($handlers);

        // All items before are route-specific middlewares
        $routeMiddlewares = [];
        foreach ($handlers as $h) {
            if (is_array($h)) {
                $routeMiddlewares = array_merge($routeMiddlewares, $h);
            } else {
                $routeMiddlewares[] = $h;
            }
        }

        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'middlewares' => $routeMiddlewares,
            'callback' => $callback
        ];
    }

    // --- Pipeline Execution ---

    // Wisp has no other global error boundary: without this, a plain PHP warning/notice
    // (e.g. an undefined array key) fired mid-request doesn't stop execution — the SAPI
    // just echoes it inline (as raw HTML if display_errors is on, which many hosts default
    // to) *before* the route's own JSON is echoed, silently corrupting the response body.
    // Promoting it to an ErrorException makes it behave like any other error: it interrupts
    // execution and is caught below (or by a route's own try/catch) instead of leaking into
    // the output.
    private function registerErrorHandling() {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false; // respects both @-suppression and the ini error_reporting mask
            }
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });

        // E_ERROR/E_PARSE/E_COMPILE_ERROR can't be caught by set_error_handler or try/catch —
        // this is the last-resort net for those so they still end up as a clean response
        // instead of PHP's default (potentially HTML) fatal-error output.
        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
                return;
            }
            error_log('[Wisp\App] Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            if (ob_get_level() > 0) {
                ob_clean();
            }
            if (headers_sent()) {
                return;
            }
            http_response_code(500);
            if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Internal Server Error']);
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Internal Server Error';
            }
        });
    }

    public function run() {
        $this->registerErrorHandling();
        ob_start();
        $request = new Request();

        // Process CORS.
        // The Origin header is only ever sent by browsers making cross-origin requests;
        // non-browser clients (curl, mobile apps, server-to-server calls) never send it
        // and must not be rejected because of that — Origin is not an access-control
        // mechanism against direct API callers, only a signal for browser requests.
        $allowedOrigin = $_ENV['ALLOWED_ORIGIN'] ?? '*';
        $requestOrigin = $request->header('Origin');
        // Credentials (cookies/HTTP auth) must never be paired with a reflected
        // wildcard origin — that lets any site read credentialed responses. Only
        // send the header when explicitly opted into via env, and never alongside '*'.
        $allowCredentials = ($_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'false') === 'true';

        if ($requestOrigin) {
            if ($allowedOrigin === '*') {
                $corsOrigin = $allowCredentials ? $requestOrigin : '*';
            } else {
                $allowedOrigins = array_map('trim', explode(',', $allowedOrigin));
                if (!in_array($requestOrigin, $allowedOrigins, true)) {
                    Response::json(['error' => 'Forbidden: Invalid Origin'], 403)->send();
                    exit;
                }
                $corsOrigin = $requestOrigin;
            }

            header("Access-Control-Allow-Origin: $corsOrigin");
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
            header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
            if ($allowCredentials) {
                header("Access-Control-Allow-Credentials: true");
            }
        }

        if ($request->method === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        // Baseline security headers for every response.
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: DENY");
        header("Referrer-Policy: no-referrer");
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        $handlers = [];

        // 1. Add global middlewares
        foreach ($this->middlewares as $mw) {
            if ($this->matchPath($mw['path'], $request->path)) {
                $handlers[] = function($req, $next) use ($mw) {
                    $resolved = $this->resolveMiddleware($mw['callback']);
                    return call_user_func($resolved, $req, $next, $this);
                };
            }
        }

        // 2. Match and add route-specific middlewares + final handler
        $routeFound = false;
        foreach ($this->routes as $route) {
            if ($route['method'] !== '*' && $route['method'] !== $request->method) {
                continue;
            }

            $matched = $this->matchRoute($route['path'], $request->path, $params);
            if ($matched) {
                $request->params = $params;

                // Add route-specific middlewares
                foreach ($route['middlewares'] as $mw) {
                    $handlers[] = function($req, $next) use ($mw) {
                        $resolved = $this->resolveMiddleware($mw);
                        return call_user_func($resolved, $req, $next, $this);
                    };
                }

                // Add final route callback
                $handlers[] = function($req, $next) use ($route) {
                    $callback = $this->resolveCallback($route['callback']);
                    return call_user_func($callback, $req, $this);
                };
                $routeFound = true;
                break;
            }
        }

        if (!$routeFound) {
            $handlers[] = function($req, $next) {
                return call_user_func($this->notFoundHandler, $req, $this);
            };
        }

        // 3. Execution pipeline
        $index = 0;
        $next = function($req) use (&$handlers, &$index, &$next) {
            if ($index >= count($handlers)) {
                return null;
            }
            $handler = $handlers[$index++];
            return $handler($req, $next);
        };

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            error_log('[Wisp\App] Unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            // Discard whatever a warning-turned-exception (or the route itself) may have
            // already echoed before throwing, so the client only ever sees the clean error
            // below instead of a mix of stray output + error body.
            ob_clean();
            $response = (strpos($request->path, '/api/') === 0)
                ? Response::json(['error' => 'Internal Server Error'], 500)
                : Response::text('Internal Server Error', 500);
        }

        if ($response instanceof Response) {
            $response->send();
        } else {
            if (is_array($response) || is_object($response)) {
                Response::json($response)->send();
            } else {
                Response::text((string)$response)->send();
            }
        }
        ob_end_flush();
    }

    private function resolveCallback($callback): callable {
        if ($callback instanceof \Closure) {
            return $callback;
        }

        if (is_array($callback) && count($callback) === 2 && is_object($callback[0])) {
            return $callback;
        }

        foreach ($this->callbackResolvers as $resolver) {
            $resolved = $resolver($callback, $this);
            if ($resolved !== null && is_callable($resolved)) {
                return $resolved;
            }
        }

        if (is_callable($callback)) {
            return $callback;
        }

        throw new \Exception("Route callback is not callable: " . print_r($callback, true));
    }

    private function resolveMiddleware($mw): callable {
        if (is_callable($mw)) {
            return $mw;
        }

        if (is_string($mw)) {
            // Check container first
            try {
                $resolved = $this->resolve($mw);
                if (is_callable($resolved)) {
                    return $resolved;
                }
            } catch (\Exception $e) {}

            $className = $mw;
            if (!class_exists($className)) {
                $fallback = ucfirst($mw) . 'Middleware';
                if (class_exists($fallback)) {
                    $className = $fallback;
                }
            }

            if (class_exists($className)) {
                $instance = $this->autowire($className);
                if (method_exists($instance, 'handle')) {
                    return [$instance, 'handle'];
                }
                if (is_callable($instance)) {
                    return $instance;
                }
            }
        }

        throw new \Exception("Middleware '" . print_r($mw, true) . "' could not be resolved.");
    }

    private function matchPath(string $pattern, string $path): bool {
        $pattern = str_replace('\*', '.*', preg_quote($pattern, '#'));
        return preg_match('#^' . $pattern . '$#', $path) === 1;
    }

    private function matchRoute(string $routePath, string $requestPath, &$params): bool {
        $params = [];

        $routePath = ($routePath === '/') ? '/' : rtrim($routePath, '/');
        $requestPath = ($requestPath === '/') ? '/' : rtrim($requestPath, '/');

        $pattern = preg_replace('/:([a-zA-Z0-9_]+)/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = urldecode($value);
                }
            }
            return true;
        }

        return false;
    }
}
