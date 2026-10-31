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
    private ?array $parsedJson = null;
    private array $simulatedCookies = [];
    private array $simulatedFiles = [];
    private array $attributes = [];
    private static array $macros = [];

    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->url = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = parse_url($requestUri, PHP_URL_PATH);

        // When the app is hosted under a subpath (e.g. https://host/rag/), BASE_PATH lets
        // route definitions in routes/*.php stay written as if mounted at "/" — the prefix
        // is stripped here before route matching happens in App::run().
        $basePath = rtrim($_ENV['BASE_PATH'] ?? '', '/');
        if ($basePath !== '' && strpos($this->path, $basePath) === 0) {
            $this->path = substr($this->path, strlen($basePath));
            if ($this->path === '') {
                $this->path = '/';
            }
        }

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

    public static function create(
        string $method,
        string $uri,
        array $query = [],
        array $headers = [],
        ?string $body = null,
        array $cookies = [],
        array $files = []
    ): self {
        $req = new self();
        $req->method = strtoupper($method);
        $req->url = $uri;
        $req->path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $req->query = $query;
        $req->headers = $headers;
        $req->rawBody = $body ?? '';
        $req->simulatedCookies = $cookies;
        $req->simulatedFiles = $files;
        return $req;
    }

    public function body(): string {
        if ($this->rawBody === null) {
            $this->rawBody = file_get_contents('php://input') ?: '';
        }
        return $this->rawBody;
    }

    public function json() {
        if ($this->parsedJson === null) {
            $raw = $this->body();
            if ($raw === '' || $raw === null) {
                $this->parsedJson = [];
            } else {
                $decoded = json_decode($raw, true);
                $this->parsedJson = is_array($decoded) ? $decoded : [];
            }
        }
        return $this->parsedJson;
    }

    public function all(): array {
        $json = $this->json();
        $post = $_POST ?? [];
        return array_merge($this->query, $post, is_array($json) ? $json : []);
    }

    public function input(?string $key = null, $default = null) {
        if ($key === null) {
            return $this->all();
        }
        $all = $this->all();
        return $all[$key] ?? $default;
    }

    public function has(string $key): bool {
        $all = $this->all();
        return array_key_exists($key, $all);
    }

    public function only(array $keys): array {
        $all = $this->all();
        $result = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                $result[$k] = $all[$k];
            }
        }
        return $result;
    }

    public function except(array $keys): array {
        $all = $this->all();
        foreach ($keys as $k) {
            unset($all[$k]);
        }
        return $all;
    }

    public function bearerToken(): ?string {
        $header = $this->header('Authorization');
        if ($header === null) {
            return null;
        }
        if (preg_match('/^\s*Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    // Client IP. CF-Connecting-IP / X-Forwarded-For / X-Real-IP are plain request headers that
    // any client can set, so they are only believed when the TCP peer (REMOTE_ADDR) is a proxy
    // listed in TRUSTED_PROXIES (comma-separated IPs/CIDRs, or "*" to trust every peer — only
    // safe when the origin is reachable exclusively through the proxy). Otherwise REMOTE_ADDR
    // is returned, so rate limiting and logging can't be dodged by spoofing a header.
    public function ip(): string {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $rules = array_filter(array_map('trim', explode(',', $_ENV['TRUSTED_PROXIES'] ?? '')), 'strlen');
        if (!$rules || !self::ipInList($remote, $rules)) {
            return $remote;
        }
        $trustAll = in_array('*', $rules, true);

        $cfIp = $this->header('CF-Connecting-IP');
        if ($cfIp !== null && filter_var(trim($cfIp), FILTER_VALIDATE_IP)) {
            return trim($cfIp);
        }

        $xff = $this->header('X-Forwarded-For');
        if ($xff !== null) {
            $chain = array_map('trim', explode(',', $xff));
            // Each proxy appends the address it saw, so only the right-hand entries were added
            // by proxies we trust; the left-hand ones are client-supplied. Walk from the right
            // and stop at the first address that isn't one of our proxies. With "*" there is no
            // way to tell proxies apart, so fall back to the left-most entry.
            if ($trustAll) {
                $chain = array_slice($chain, 0, 1);
            } else {
                $chain = array_reverse($chain);
            }
            foreach ($chain as $candidate) {
                if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                    break;
                }
                if ($trustAll || !self::ipInList($candidate, $rules)) {
                    return $candidate;
                }
            }
        }

        $realIp = $this->header('X-Real-IP');
        if ($realIp !== null && filter_var(trim($realIp), FILTER_VALIDATE_IP)) {
            return trim($realIp);
        }

        return $remote;
    }

    private static function ipInList(string $ip, array $rules): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $ipBin = inet_pton($ip);

        foreach ($rules as $rule) {
            if ($rule === '*') {
                return true;
            }
            $parts = explode('/', $rule, 2);
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
                continue;
            }
            $subnetBin = inet_pton($parts[0]);
            if (strlen($subnetBin) !== strlen($ipBin)) {
                continue; // IPv4 rule vs IPv6 address (or the reverse)
            }
            $maxBits = strlen($ipBin) * 8;
            if (isset($parts[1])) {
                if (!ctype_digit($parts[1]) || (int)$parts[1] > $maxBits) {
                    continue;
                }
                $bits = (int)$parts[1];
            } else {
                $bits = $maxBits;
            }

            $bytes = intdiv($bits, 8);
            $rest = $bits % 8;
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
                continue;
            }
            if ($rest > 0) {
                $mask = (0xFF << (8 - $rest)) & 0xFF;
                if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }

    public function cookie(string $name, $default = null): ?string {
        if (isset($this->simulatedCookies[$name])) {
            return (string)$this->simulatedCookies[$name];
        }

        if (isset($_COOKIE[$name])) {
            return (string)$_COOKIE[$name];
        }

        $cookieHeader = $this->header('Cookie');
        if ($cookieHeader !== null) {
            $parts = explode(';', $cookieHeader);
            foreach ($parts as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) === 2 && trim($pair[0]) === $name) {
                    return urldecode(trim($pair[1]));
                }
            }
        }

        return $default;
    }

    public function file(string $name) {
        if (isset($this->simulatedFiles[$name])) {
            return $this->simulatedFiles[$name];
        }
        return $_FILES[$name] ?? null;
    }

    public function files(): array {
        return !empty($this->simulatedFiles) ? $this->simulatedFiles : ($_FILES ?? []);
    }

    public function hasFile(string $name): bool {
        $file = $this->file($name);
        return is_array($file) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE;
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
