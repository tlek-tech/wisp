<?php
namespace Wisp;

class App {
    private array $routes = [];
    private array $middlewares = [];
    private array $services = [];
    private array $factories = [];
    private array $instances = [];
    private array $resolvedServices = [];
    private array $callbackResolvers = [];
    private $notFoundHandler;
    private $methodNotAllowedHandler;
    private $errorHandler = null;
    private string $currentGroupPrefix = '';
    private array $currentGroupMiddlewares = [];

    public function __construct() {
        $this->notFoundHandler = function(Request $req) {
            $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
            $accept = $req->header('Accept') ?? '';
            $isHtml = (strpos($accept, 'text/html') !== false) && (strpos($accept, 'application/json') === false) && (strpos($req->path, '/api/') !== 0);

            if ($debug) {
                $availableRoutes = array_map(function($r) {
                    return "{$r['method']} {$r['path']}";
                }, $this->routes);

                if (!$isHtml) {
                    return Response::json([
                        'error' => 'Not Found',
                        'message' => "Route [{$req->method}] {$req->path} not found.",
                        'available_routes' => $availableRoutes,
                    ], 404);
                }

                return Response::html($this->renderNotFoundHtml($req, $availableRoutes), 404);
            }

            return !$isHtml
                ? Response::json(['error' => 'Not Found'], 404)
                : Response::text('Not Found', 404);
        };

        $this->methodNotAllowedHandler = function(Request $req, array $allowedMethods) {
            $allowed = array_unique(array_merge($allowedMethods, ['OPTIONS']));
            $methodsStr = implode(', ', $allowed);
            $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
            $accept = $req->header('Accept') ?? '';
            $isHtml = (strpos($accept, 'text/html') !== false) && (strpos($accept, 'application/json') === false) && (strpos($req->path, '/api/') !== 0);
            $headers = ['Allow' => $methodsStr];

            if (!$isHtml) {
                $data = [
                    'error' => 'Method Not Allowed',
                    'allowed_methods' => array_values($allowed),
                ];
                if ($debug) {
                    $data['message'] = "Method [{$req->method}] is not allowed for route {$req->path}.";
                }
                return Response::json($data, 405, $headers);
            }

            return Response::text('Method Not Allowed', 405, $headers);
        };
    }

    public function registerCallbackResolver(callable $resolver) {
        $this->callbackResolvers[] = $resolver;
    }

    // --- Service Container Methods (with Auto-wiring) ---

    public function set(string $name, callable $factory): self {
        $this->services[$name] = $factory;
        return $this;
    }

    public function singleton(string $name, callable $factory): self {
        $this->services[$name] = $factory;
        return $this;
    }

    public function bind(string $name, callable $factory): self {
        $this->factories[$name] = $factory;
        return $this;
    }

    public function instance(string $name, $instance): self {
        $this->instances[$name] = $instance;
        return $this;
    }

    public function has(string $name): bool {
        return isset($this->instances[$name]) ||
               isset($this->resolvedServices[$name]) ||
               isset($this->services[$name]) ||
               isset($this->factories[$name]) ||
               class_exists($name) ||
               class_exists(ucfirst($name));
    }

    public function resolve(string $name) {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->factories[$name])) {
            return $this->factories[$name]($this);
        }

        if (!isset($this->resolvedServices[$name])) {
            if (isset($this->services[$name])) {
                $this->resolvedServices[$name] = $this->services[$name]($this);
            } else {
                // Auto-wiring: Try to resolve class from services folder or full namespace
                $className = class_exists($name) ? $name : ucfirst($name);
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

    public function make(string $className) {
        return $this->autowire($className);
    }

    public function autowire(string $className) {
        $reflector = new \ReflectionClass($className);
        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $className();
        }

        $parameters = $constructor->getParameters();
        $dependencies = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $type = $param->getType();
            $resolved = null;

            // 1. Try resolving via Type-Hint
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeClass = $type->getName();

                if ($typeClass === self::class || $typeClass === 'Wisp\App') {
                    $resolved = $this;
                } elseif (isset($this->instances[$typeClass])) {
                    $resolved = $this->instances[$typeClass];
                } elseif ($typeClass === \PDO::class) {
                    if (isset($this->instances['db'])) {
                        $resolved = $this->instances['db'];
                    } elseif (isset($this->services['db']) || isset($this->resolvedServices['db'])) {
                        $resolved = $this->resolve('db');
                    } else {
                        $resolved = DB::connect();
                    }
                } elseif ($this->has($typeClass)) {
                    try {
                        $resolved = $this->resolve($typeClass);
                    } catch (\Exception $e) {}
                } elseif (class_exists($typeClass)) {
                    try {
                        $resolved = $this->autowire($typeClass);
                    } catch (\Exception $e) {}
                }
            }

            // 2. Fall back to resolving by parameter name
            if ($resolved === null) {
                try {
                    $resolved = $this->resolve($paramName);
                } catch (\Exception $e) {
                    if ($param->isDefaultValueAvailable()) {
                        $resolved = $param->getDefaultValue();
                    } elseif ($type && $type->allowsNull()) {
                        $resolved = null;
                    } else {
                        throw new \Exception("Cannot auto-wire parameter '{$paramName}' for class '{$className}': " . $e->getMessage());
                    }
                }
            }

            $dependencies[] = $resolved;
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

    public function group(string $prefix, ...$args): self {
        $callback = array_pop($args);
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException("Last argument of App::group() must be a callable.");
        }

        $groupMiddlewares = $this->flattenMiddlewares($args);

        $prevPrefix = $this->currentGroupPrefix;
        $prevMiddlewares = $this->currentGroupMiddlewares;

        $normalizedPrefix = '/' . trim($prefix, '/');
        if ($normalizedPrefix === '//' || $normalizedPrefix === '/') {
            $normalizedPrefix = '';
        }

        $this->currentGroupPrefix = $prevPrefix . $normalizedPrefix;
        $this->currentGroupMiddlewares = array_merge($prevMiddlewares, $groupMiddlewares);

        call_user_func($callback, $this);

        $this->currentGroupPrefix = $prevPrefix;
        $this->currentGroupMiddlewares = $prevMiddlewares;

        return $this;
    }

    public function setNotFound(callable $callback): self {
        $this->notFoundHandler = $callback;
        return $this;
    }

    public function setMethodNotAllowed(callable $callback): self {
        $this->methodNotAllowedHandler = $callback;
        return $this;
    }

    public function onError(callable $handler): self {
        $this->errorHandler = $handler;
        return $this;
    }

    public function command(string $name, callable $handler, string $description = ''): self {
        Console::register($name, $handler, $description);
        return $this;
    }

    public function getRoutes(): array {
        return $this->routes;
    }

    public function getMiddlewares(): array {
        return $this->middlewares;
    }

    // Middleware arguments may be given one by one or as a list ([A::class, B::class]). A
    // callable array such as [$instance, 'handle'] is ONE middleware, not a list of two.
    private function flattenMiddlewares(array $items): array {
        $flat = [];
        foreach ($items as $item) {
            if (is_array($item) && !$this->isCallableArray($item)) {
                $flat = array_merge($flat, $this->flattenMiddlewares($item));
            } else {
                $flat[] = $item;
            }
        }
        return $flat;
    }

    private function isCallableArray(array $value): bool {
        return count($value) === 2
            && isset($value[0], $value[1])
            && is_string($value[1])
            && (is_object($value[0]) || is_string($value[0]))
            && is_callable($value);
    }

    private function addRoute(string $method, string $path, array $handlers) {
        // Last item is the controller callback
        $callback = array_pop($handlers);

        // All items before are route-specific middlewares
        $routeMiddlewares = $this->flattenMiddlewares($handlers);

        // Apply group prefix if active
        if ($this->currentGroupPrefix !== '') {
            $subPath = '/' . ltrim($path, '/');
            if ($subPath === '//' || ($subPath === '/' && $this->currentGroupPrefix !== '')) {
                $subPath = '';
            }
            $fullPath = $this->currentGroupPrefix . $subPath;
            if ($fullPath === '') {
                $fullPath = '/';
            }
        } else {
            $fullPath = $path;
        }

        // Apply group middlewares before route-specific middlewares
        $finalMiddlewares = array_merge($this->currentGroupMiddlewares, $routeMiddlewares);

        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'middlewares' => $finalMiddlewares,
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
    // Deprecations are the exception: they flag code that still works (and often come from
    // vendor/ or plugin code the app owner doesn't control, e.g. strlen(null) on PHP 8.1+), so
    // they are logged and execution continues instead of turning the request into a 500.
    private function registerErrorHandling() {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false; // respects both @-suppression and the ini error_reporting mask
            }
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                error_log("[Wisp\\App] Deprecated: $errstr in $errfile:$errline");
                return true;
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
            $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isJson = (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) || (strpos($accept, 'application/json') !== false);

            if ($isJson) {
                header('Content-Type: application/json; charset=utf-8');
                $payload = ['error' => 'Internal Server Error'];
                if ($debug) {
                    $payload['message'] = $error['message'];
                    $payload['file'] = $error['file'];
                    $payload['line'] = $error['line'];
                    $payload['type'] = $error['type'];
                }
                echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } else {
                header('Content-Type: text/html; charset=utf-8');
                if ($debug) {
                    echo $this->renderFatalDebugHtml($error);
                } else {
                    echo 'Internal Server Error';
                }
            }
        });
    }

    public function handle(Request $request): Response {
        // Process CORS.
        $allowedOrigin = $_ENV['ALLOWED_ORIGIN'] ?? '*';
        $requestOrigin = $request->header('Origin');
        $allowCredentials = ($_ENV['CORS_ALLOW_CREDENTIALS'] ?? 'false') === 'true';

        $corsOrigin = null;
        $varyOrigin = false;
        // Browsers also send Origin on same-origin POST/PUT/DELETE (e.g. a form served by this
        // app), so an Origin that names this very host is not a cross-origin request and must
        // not be checked against ALLOWED_ORIGIN.
        if ($requestOrigin && !$this->isSameOrigin($request, $requestOrigin)) {
            if ($allowedOrigin === '*') {
                $corsOrigin = $allowCredentials ? $requestOrigin : '*';
                $varyOrigin = $allowCredentials;
            } else {
                $varyOrigin = true;
                $allowedOrigins = array_map('trim', explode(',', $allowedOrigin));
                if (!in_array($requestOrigin, $allowedOrigins, true)) {
                    $forbidden = Response::json(['error' => 'Forbidden: Invalid Origin'], 403);
                    $this->applySecurityAndCorsHeaders($forbidden, null, false, $varyOrigin);
                    return $forbidden;
                }
                $corsOrigin = $requestOrigin;
            }
        }

        // Preflight/OPTIONS is answered automatically, unless the app defined its own OPTIONS
        // route for this path.
        if ($request->method === 'OPTIONS') {
            $optionsMethods = ['OPTIONS'];
            $hasOwnOptionsRoute = false;
            foreach ($this->routes as $route) {
                if (!$this->matchRoute($route['path'], $request->path, $unused)) {
                    continue;
                }
                if ($route['method'] === 'OPTIONS') {
                    $hasOwnOptionsRoute = true;
                    break;
                }
                $optionsMethods[] = $route['method'] === '*' ? 'GET, POST, PUT, DELETE, PATCH' : $route['method'];
            }

            if (!$hasOwnOptionsRoute) {
                $optionsRes = Response::noContent(204);
                $methods = count($optionsMethods) > 1 ? implode(', ', array_unique($optionsMethods)) : null;
                if ($methods !== null) {
                    $optionsRes->setHeader('Allow', $methods);
                }
                $this->applySecurityAndCorsHeaders($optionsRes, $corsOrigin, $allowCredentials, $varyOrigin, $methods);
                return $optionsRes;
            }
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
        $matchedRoute = null;
        $matchedParams = [];
        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $isPathMatch = $this->matchRoute($route['path'], $request->path, $params);
            if ($isPathMatch) {
                if ($route['method'] === '*' || $route['method'] === $request->method) {
                    $matchedRoute = $route;
                    $matchedParams = $params;
                    break;
                } else {
                    $allowedMethods[] = $route['method'];
                }
            }
        }

        if ($matchedRoute !== null) {
            $request->params = $matchedParams;

            // Add route-specific middlewares
            foreach ($matchedRoute['middlewares'] as $mw) {
                $handlers[] = function($req, $next) use ($mw) {
                    $resolved = $this->resolveMiddleware($mw);
                    return call_user_func($resolved, $req, $next, $this);
                };
            }

            // Add final route callback
            $handlers[] = function($req, $next) use ($matchedRoute) {
                $callback = $this->resolveCallback($matchedRoute['callback']);
                return call_user_func($callback, $req, $this);
            };
        } elseif (!empty($allowedMethods)) {
            // 405 Method Not Allowed
            $handlers[] = function($req, $next) use ($allowedMethods) {
                return call_user_func($this->methodNotAllowedHandler, $req, $allowedMethods, $this);
            };
        } else {
            // 404 Not Found
            $handlers[] = function($req, $next) {
                return call_user_func($this->notFoundHandler, $req, $this);
            };
        }

        // 3. Execution pipeline with automatic response normalization
        $index = 0;
        $next = function($req) use (&$handlers, &$index, &$next): Response {
            if ($index >= count($handlers)) {
                return Response::noContent(204);
            }
            $handler = $handlers[$index++];
            $result = $handler($req, $next);

            if ($result instanceof Response) {
                return $result;
            }
            if (is_array($result) || is_object($result)) {
                return Response::json($result);
            }
            return Response::text((string)$result);
        };

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            error_log('[Wisp\App] Unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            if (ob_get_level() > 0) {
                ob_clean();
            }

            $response = null;

            if ($this->errorHandler !== null) {
                try {
                    $result = call_user_func($this->errorHandler, $e, $request, $this);
                    if ($result instanceof Response) {
                        $response = $result;
                    } elseif (is_array($result) || is_object($result)) {
                        $response = Response::json($result, 500);
                    } elseif ($result !== null) {
                        $response = Response::text((string)$result, 500);
                    }
                } catch (\Throwable $handlerEx) {
                    error_log('[Wisp\App] Error in custom errorHandler: ' . $handlerEx->getMessage());
                    $e = $handlerEx;
                }
            }

            if ($response === null) {
                $response = $this->handleDefaultError($e, $request);
            }
        }

        $this->applySecurityAndCorsHeaders($response, $corsOrigin, $allowCredentials, $varyOrigin);

        return $response;
    }

    private function isSameOrigin(Request $request, string $origin): bool {
        $host = $request->header('Host');
        if ($host === null || $host === '') {
            return false;
        }

        $originParts = parse_url($origin);
        if (!$originParts || empty($originParts['scheme']) || empty($originParts['host'])) {
            return false; // e.g. "null" or malformed
        }
        $hostParts = parse_url('//' . $host);
        if (!$hostParts || empty($hostParts['host'])) {
            return false;
        }

        $defaultPort = strtolower($originParts['scheme']) === 'https' ? 443 : 80;
        return strcasecmp($originParts['host'], $hostParts['host']) === 0
            && ($originParts['port'] ?? $defaultPort) === ($hostParts['port'] ?? $defaultPort);
    }

    public function run(): void {
        $this->registerErrorHandling();
        ob_start();
        $request = new Request();
        $response = $this->handle($request);
        $response->send();
        ob_end_flush();
    }

    private function applySecurityAndCorsHeaders(
        Response $response,
        ?string $corsOrigin,
        bool $allowCredentials,
        bool $varyOrigin = false,
        ?string $allowMethods = null
    ): void {
        // The response differs per Origin whenever it is checked against / reflected from a list,
        // so shared caches must not serve one origin's answer to another.
        if ($varyOrigin) {
            $vary = $response->getHeader('Vary');
            if (!$vary) {
                $response->setHeader('Vary', 'Origin');
            } elseif (stripos($vary, 'Origin') === false) {
                $response->setHeader('Vary', $vary . ', Origin');
            }
        }

        if ($corsOrigin !== null) {
            $response->setHeader('Access-Control-Allow-Origin', $corsOrigin);
            $response->setHeader('Access-Control-Allow-Methods', $allowMethods ?? 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
            $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
            if ($allowCredentials) {
                $response->setHeader('Access-Control-Allow-Credentials', 'true');
            }
        }

        if (!$response->getHeader('X-Content-Type-Options')) {
            $response->setHeader('X-Content-Type-Options', 'nosniff');
        }
        if (!$response->getHeader('X-Frame-Options')) {
            $response->setHeader('X-Frame-Options', 'DENY');
        }
        if (!$response->getHeader('Referrer-Policy')) {
            $response->setHeader('Referrer-Policy', 'no-referrer');
        }
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && !$response->getHeader('Strict-Transport-Security')) {
            $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
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

        if ($routePath === $requestPath) {
            return true;
        }

        // Literal text is preg_quote()d, so "." or "(" in a route path match themselves instead
        // of acting as regex syntax. The only special tokens are:
        //   :param -> (?P<param>[^/]+)   *param -> (?P<param>.*)   * -> .*
        $pattern = '';
        foreach (preg_split('/(:[a-zA-Z_][a-zA-Z0-9_]*|\*[a-zA-Z_][a-zA-Z0-9_]*|\*)/', $routePath, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $part) {
            if ($i % 2 === 0) {
                $pattern .= preg_quote($part, '#');
            } elseif ($part === '*') {
                $pattern .= '.*';
            } elseif ($part[0] === ':') {
                $pattern .= '(?P<' . substr($part, 1) . '>[^/]+)';
            } else {
                $pattern .= '(?P<' . substr($part, 1) . '>.*)';
            }
        }
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

    // --- Error Rendering & Debugging Helpers ---

    private function handleDefaultError(\Throwable $e, Request $request): Response {
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        $accept = $request->header('Accept') ?? '';
        $isJson = (strpos($request->path, '/api/') === 0) || (strpos($accept, 'application/json') !== false);

        $code = (int)$e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;

        if ($debug) {
            if ($isJson) {
                return Response::json([
                    'error' => ($status === 500) ? 'Internal Server Error' : ($e->getMessage() ?: 'Error'),
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ], $status);
            }

            return Response::html($this->renderDebugHtml($e, $request), $status);
        }

        return $isJson
            ? Response::json(['error' => 'Internal Server Error'], $status)
            : Response::text('Internal Server Error', $status);
    }

    private function renderDebugHtml(\Throwable $e, Request $request): string {
        $class = get_class($e);
        $message = $e->getMessage();
        $file = $e->getFile();
        $line = $e->getLine();
        $code = (int)$e->getCode();
        $status = ($code >= 400 && $code <= 599) ? $code : 500;

        $snippetHtml = $this->renderCodeSnippetHtml($file, $line);
        $traceHtml = $this->renderTraceHtml($e);
        $requestHtml = $this->renderRequestContextHtml($request);

        return $this->buildErrorPage(
            $status,
            $class,
            $message,
            $file,
            $line,
            $snippetHtml . $traceHtml . $requestHtml
        );
    }

    private function renderFatalDebugHtml(array $error): string {
        $message = $error['message'] ?? 'Unknown fatal error';
        $file = $error['file'] ?? 'unknown';
        $line = (int)($error['line'] ?? 0);
        $snippetHtml = $this->renderCodeSnippetHtml($file, $line);

        return $this->buildErrorPage(
            500,
            'Fatal Error',
            $message,
            $file,
            $line,
            $snippetHtml
        );
    }

    private function renderNotFoundHtml(Request $req, array $availableRoutes): string {
        $method = htmlspecialchars($req->method, ENT_QUOTES, 'UTF-8');
        $path = htmlspecialchars($req->path, ENT_QUOTES, 'UTF-8');

        $routesList = '';
        if (!empty($availableRoutes)) {
            $routesList .= '<div class="section-card">';
            $routesList .= '<div class="section-header">Available Routes (' . count($availableRoutes) . ')</div>';
            $routesList .= '<div class="routes-grid">';
            foreach ($availableRoutes as $r) {
                $parts = explode(' ', $r, 2);
                $m = htmlspecialchars($parts[0] ?? 'ANY', ENT_QUOTES, 'UTF-8');
                $p = htmlspecialchars($parts[1] ?? '', ENT_QUOTES, 'UTF-8');
                $mClass = strtolower(str_replace('*', 'any', $m));
                $routesList .= "<div class=\"route-item\"><span class=\"method-badge method-{$mClass}\">{$m}</span><span class=\"route-path\">{$p}</span></div>";
            }
            $routesList .= '</div></div>';
        }

        return $this->buildErrorPage(
            404,
            '404 Not Found',
            "No route found matching [{$method}] {$path}",
            'Router',
            0,
            $routesList
        );
    }

    private function buildErrorPage(int $status, string $title, string $message, string $file, int $line, string $body): string {
        $metaHtml = '';
        if ($file !== '' && $file !== 'Router') {
            $metaHtml = '<div class="error-meta">in <strong>' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '</strong> on line <strong>' . $line . '</strong></div>';
        } elseif ($file === 'Router') {
            $metaHtml = '<div class="error-meta">Check your routes configuration in <strong>routes/</strong></div>';
        }

        $titleEscaped = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $messageEscaped = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Wisp Debug - {$titleEscaped}</title>
    <style>
        :root {
            --bg: #090d16;
            --surface: #111827;
            --border: #1f293d;
            --text-primary: #f3f4f6;
            --text-secondary: #9ca3af;
            --text-muted: #64748b;
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.15);
            --danger-border: #dc2626;
            --brand: #38bdf8;
            --brand-bg: rgba(56, 189, 248, 0.12);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            line-height: 1.5;
            padding: 2.5rem 1rem;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
        }
        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.8rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 600;
        }
        .badge-brand { background: var(--brand-bg); color: var(--brand); border: 1px solid rgba(56, 189, 248, 0.3); }
        .badge-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }
        .hero {
            background: var(--surface);
            border: 1px solid var(--border);
            border-left: 4px solid var(--danger);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .exception-title {
            color: var(--danger);
            font-size: 0.875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.4rem;
        }
        .error-message {
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.75rem;
            word-break: break-word;
        }
        .error-meta {
            color: var(--text-secondary);
            font-size: 0.875rem;
            font-family: "JetBrains Mono", Consolas, monospace;
        }
        .error-meta strong { color: var(--text-primary); }
        .section-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .section-header {
            padding: 0.75rem 1.25rem;
            background: rgba(255, 255, 255, 0.02);
            border-bottom: 1px solid var(--border);
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .code-snippet {
            font-family: "JetBrains Mono", "Fira Code", Menlo, Consolas, monospace;
            font-size: 0.875rem;
            line-height: 1.6;
            overflow-x: auto;
            background: #0d1117;
            padding: 0.5rem 0;
        }
        .code-line {
            display: flex;
            padding: 0.1rem 1rem;
        }
        .code-line.highlight {
            background: rgba(239, 68, 68, 0.2);
            border-left: 4px solid var(--danger);
            padding-left: calc(1rem - 4px);
        }
        .line-num {
            width: 3.5rem;
            flex-shrink: 0;
            color: var(--text-muted);
            user-select: none;
            text-align: right;
            padding-right: 1.25rem;
        }
        .line-code {
            color: #e6edf3;
            white-space: pre;
        }
        .trace-item {
            padding: 0.75rem 1.25rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }
        .trace-item:last-child { border-bottom: none; }
        .trace-call {
            font-family: "JetBrains Mono", Consolas, monospace;
            font-weight: 600;
            color: var(--brand);
            margin-bottom: 0.25rem;
        }
        .trace-location {
            color: var(--text-muted);
            font-size: 0.8125rem;
            font-family: "JetBrains Mono", Consolas, monospace;
        }
        .table-data {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .table-data th, .table-data td {
            padding: 0.6rem 1.25rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .table-data th { color: var(--text-muted); font-weight: 500; width: 30%; }
        .table-data td { font-family: "JetBrains Mono", Consolas, monospace; color: var(--text-primary); word-break: break-all; }
        .table-data tr:last-child td { border-bottom: none; }
        .routes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 0.5rem;
            padding: 1rem 1.25rem;
        }
        .route-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: rgba(255, 255, 255, 0.02);
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid var(--border);
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 0.8125rem;
        }
        .method-badge {
            font-weight: 700;
            font-size: 0.75rem;
            padding: 0.15rem 0.45rem;
            border-radius: 0.25rem;
        }
        .method-get { background: #065f46; color: #34d399; }
        .method-post { background: #1e40af; color: #60a5fa; }
        .method-put, .method-patch { background: #854d0e; color: #facc15; }
        .method-delete { background: #991b1b; color: #f87171; }
        .method-any { background: #374151; color: #d1d5db; }
        .route-path { color: var(--text-primary); word-break: break-all; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-bar">
            <span class="badge badge-brand">🌬️ Wisp Debug</span>
            <span class="badge badge-danger">HTTP {$status}</span>
        </div>
        <div class="hero">
            <div class="exception-title">{$titleEscaped}</div>
            <div class="error-message">{$messageEscaped}</div>
            {$metaHtml}
        </div>
        {$body}
    </div>
</body>
</html>
HTML;
    }

    private function renderCodeSnippetHtml(string $file, int $line): string {
        $snippet = $this->getCodeSnippet($file, $line, 5);
        if ($snippet === null || empty($snippet)) {
            return '';
        }

        $html = '<div class="section-card">';
        $html .= '<div class="section-header">Source &mdash; ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ':' . $line . '</div>';
        $html .= '<div class="code-snippet">';
        foreach ($snippet as $num => $code) {
            $isHighlight = ($num === $line);
            $hlClass = $isHighlight ? ' highlight' : '';
            $lineCode = htmlspecialchars(rtrim($code, "\r\n"), ENT_QUOTES, 'UTF-8');
            if ($lineCode === '') {
                $lineCode = '&nbsp;';
            }
            $html .= "<div class=\"code-line{$hlClass}\">";
            $html .= "<span class=\"line-num\">{$num}</span>";
            $html .= "<span class=\"line-code\">{$lineCode}</span>";
            $html .= "</div>";
        }
        $html .= '</div></div>';
        return $html;
    }

    private function getCodeSnippet(string $file, int $line, int $padding = 5): ?array {
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }
        $lines = @file($file);
        if ($lines === false) {
            return null;
        }
        $start = max(1, $line - $padding);
        $end = min(count($lines), $line + $padding);
        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $snippet[$i] = $lines[$i - 1];
        }
        return $snippet;
    }

    private function renderTraceHtml(\Throwable $e): string {
        $trace = $e->getTrace();
        if (empty($trace)) {
            return '';
        }

        $html = '<div class="section-card">';
        $html .= '<div class="section-header">Stack Trace (' . count($trace) . ' frames)</div>';
        foreach ($trace as $i => $frame) {
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            $func = $frame['function'] ?? '';
            $call = htmlspecialchars($class . $type . $func . '()', ENT_QUOTES, 'UTF-8');
            $file = isset($frame['file']) ? htmlspecialchars($frame['file'], ENT_QUOTES, 'UTF-8') : '[internal function]';
            $line = isset($frame['line']) ? ':' . $frame['line'] : '';

            $html .= '<div class="trace-item">';
            $html .= "<div class=\"trace-call\">#{$i} {$call}</div>";
            $html .= "<div class=\"trace-location\">{$file}{$line}</div>";
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderRequestContextHtml(Request $request): string {
        $html = '<div class="section-card">';
        $html .= '<div class="section-header">Request Context</div>';
        $html .= '<table class="table-data">';
        $html .= '<tr><th>Method</th><td>' . htmlspecialchars($request->method, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><th>Path</th><td>' . htmlspecialchars($request->path, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><th>URL</th><td>' . htmlspecialchars($request->url, ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><th>PHP Version</th><td>' . PHP_VERSION . '</td></tr>';
        $html .= '</table></div>';

        if (!empty($request->headers)) {
            $html .= '<div class="section-card">';
            $html .= '<div class="section-header">Request Headers</div>';
            $html .= '<table class="table-data">';
            foreach ($request->headers as $k => $v) {
                $val = (stripos($k, 'authorization') !== false || stripos($k, 'cookie') !== false)
                    ? substr($v, 0, 10) . '... [redacted]'
                    : $v;
                $html .= '<tr><th>' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</th><td>' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            $html .= '</table></div>';
        }

        return $html;
    }
}
