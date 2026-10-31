<?php
namespace Wisp;

class App {
    private array $routes = [];
    private array $middlewares = [];
    private array $services = [];
    private array $resolvedServices = [];
    private array $callbackResolvers = [];
    private $notFoundHandler;
    private $errorHandler = null;

    public function __construct() {
        $this->notFoundHandler = function(Request $req) {
            $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
            $accept = $req->header('Accept') ?? '';
            $isJson = (strpos($req->path, '/api/') === 0) || (strpos($accept, 'application/json') !== false);

            if ($debug) {
                $availableRoutes = array_map(function($r) {
                    return "{$r['method']} {$r['path']}";
                }, $this->routes);

                if ($isJson) {
                    return Response::json([
                        'error' => 'Not Found',
                        'message' => "Route [{$req->method}] {$req->path} not found.",
                        'available_routes' => $availableRoutes,
                    ], 404);
                }

                return Response::html($this->renderNotFoundHtml($req, $availableRoutes), 404);
            }

            return $isJson
                ? Response::json(['error' => 'Not Found'], 404)
                : Response::text('Not Found', 404);
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

    public function setNotFound(callable $callback): self {
        $this->notFoundHandler = $callback;
        return $this;
    }

    public function onError(callable $handler): self {
        $this->errorHandler = $handler;
        return $this;
    }

    public function getRoutes(): array {
        return $this->routes;
    }

    public function getMiddlewares(): array {
        return $this->middlewares;
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

            if (!headers_sent()) {
                header("Access-Control-Allow-Origin: $corsOrigin");
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
                header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
                if ($allowCredentials) {
                    header("Access-Control-Allow-Credentials: true");
                }
            }
        }

        if ($request->method === 'OPTIONS') {
            if (!headers_sent()) {
                http_response_code(204);
            }
            exit;
        }

        // Baseline security headers for every response.
        if (!headers_sent()) {
            header("X-Content-Type-Options: nosniff");
            header("X-Frame-Options: DENY");
            header("Referrer-Policy: no-referrer");
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
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
            if (ob_get_level() > 0) {
                ob_clean();
            }

            $response = null;

            if ($this->errorHandler !== null) {
                try {
                    $response = call_user_func($this->errorHandler, $e, $request, $this);
                } catch (\Throwable $handlerEx) {
                    error_log('[Wisp\App] Error in custom errorHandler: ' . $handlerEx->getMessage());
                    $e = $handlerEx;
                }
            }

            if ($response === null) {
                $response = $this->handleDefaultError($e, $request);
            }
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
