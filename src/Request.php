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
}
