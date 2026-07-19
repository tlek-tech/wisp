<?php
namespace Wisp;

class Request {
    public string $method;
    public string $url;
    public string $path;
    public array $headers = [];
    public array $query = [];
    public array $params = [];
    private ?string $rawBody = null;
    private array $attributes = [];
    private static array $macros = [];

    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->url = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = parse_url($requestUri, PHP_URL_PATH);
        
        $this->query = $_GET;

        // Parse headers
        if (function_exists('getallheaders')) {
            $this->headers = getallheaders();
        } else {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) == 'HTTP_') {
                    $this->headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
                }
            }
        }
    }

    public function body(): string {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input');
        }
        return $this->rawBody;
    }

    public function json() {
        return json_decode($this->body(), true);
    }

    public function header(string $name): ?string {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    // --- Plugin injection: per-request attribute bag ---
    // Lets middleware (e.g. a JWT plugin) attach data that later middleware/route handlers can read.

    public function setAttribute(string $key, $value): void {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, $default = null) {
        return $this->attributes[$key] ?? $default;
    }

    // --- Plugin injection: macro registry ---
    // Lets plugins add new callable methods to Request without modifying this class.

    public static function macro(string $name, callable $fn): void {
        self::$macros[$name] = $fn;
    }

    public function __call(string $name, array $args) {
        if (!isset(self::$macros[$name])) {
            throw new \BadMethodCallException("Method {$name} does not exist on Request.");
        }
        $macro = self::$macros[$name];
        if ($macro instanceof \Closure) {
            $macro = \Closure::bind($macro, $this, static::class);
        }
        return call_user_func_array($macro, $args);
    }
}
