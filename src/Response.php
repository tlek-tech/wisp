<?php
namespace Wisp;

class Response {
    private $body;
    private int $status;
    private array $headers;

    public function __construct($body, int $status = 200, array $headers = []) {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeader(string $name): ?string {
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return $value;
            }
        }
        return null;
    }

    public static function json($data, int $status = 200, array $headers = []): Response {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return new self(json_encode(['error' => 'Internal Server Error']), 500, $headers);
        }
        return new self($encoded, $status, $headers);
    }

    public static function text(string $text, int $status = 200, array $headers = []): Response {
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/plain; charset=utf-8';
        }
        return new self($text, $status, $headers);
    }

    public static function html(string $html, int $status = 200, array $headers = []): Response {
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'text/html; charset=utf-8';
        }
        return new self($html, $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): Response {
        return new self('', $status, ['Location' => $url]);
    }

    public function send() {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }

        echo $this->body;
    }
}
