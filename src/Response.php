<?php
namespace Wisp;

class Response {
    private $body;
    private int $status;
    private array $headers;
    private array $cookies = [];

    public function __construct($body, int $status = 200, array $headers = [], array $cookies = []) {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
        $this->cookies = $cookies;
    }

    public function status(int $status): self {
        $this->status = $status;
        return $this;
    }

    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function withHeaders(array $headers): self {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
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

    public function setCookie(string $name, string $value, array $options = []): self {
        $cookie = rawurlencode($name) . '=' . rawurlencode($value);

        if (isset($options['expires'])) {
            $time = is_numeric($options['expires']) ? (int)$options['expires'] : strtotime($options['expires']);
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s T', $time);
        }
        if (isset($options['max_age'])) {
            $cookie .= '; Max-Age=' . (int)$options['max_age'];
        }
        $path = $options['path'] ?? '/';
        $cookie .= '; Path=' . $path;

        if (!empty($options['domain'])) {
            $cookie .= '; Domain=' . $options['domain'];
        }
        if (!empty($options['secure'])) {
            $cookie .= '; Secure';
        }
        if (!isset($options['httponly']) || $options['httponly']) {
            $cookie .= '; HttpOnly';
        }
        if (!empty($options['samesite'])) {
            $cookie .= '; SameSite=' . ucfirst(strtolower($options['samesite']));
        }

        $this->cookies[] = $cookie;
        return $this;
    }

    public function getCookies(): array {
        return $this->cookies;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function getBody() {
        return $this->body;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getJson() {
        return json_decode((string)$this->body, true);
    }

    public function __get(string $name) {
        if ($name === 'status') return $this->status;
        if ($name === 'body') return $this->body;
        if ($name === 'headers') return $this->headers;
        if ($name === 'cookies') return $this->cookies;
        if ($name === 'json') return $this->getJson();
        return null;
    }

    public static function noContent(int $status = 204, array $headers = []): Response {
        return new self('', $status, $headers);
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

    public static function file(string $filePath, array $headers = []): Response {
        if (!file_exists($filePath) || !is_file($filePath)) {
            return new self('File Not Found', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return new self('Unable to read file', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = self::detectMimeType($filePath);
        }
        if (!isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string)filesize($filePath);
        }

        return new self($content, 200, $headers);
    }

    public static function download(string $filePath, ?string $filename = null, array $headers = []): Response {
        $response = self::file($filePath, $headers);
        if ($response->getStatus() !== 200) {
            return $response;
        }

        $name = $filename ?: basename($filePath);
        $nameSanitized = str_replace('"', '\"', $name);
        $response->setHeader('Content-Disposition', 'attachment; filename="' . $nameSanitized . '"');
        return $response;
    }

    private static function detectMimeType(string $filePath): string {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($filePath);
            if ($mime) {
                return $mime;
            }
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $map = [
            'html' => 'text/html; charset=utf-8',
            'htm'  => 'text/html; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'xml'  => 'application/xml; charset=utf-8',
            'txt'  => 'text/plain; charset=utf-8',
            'csv'  => 'text/csv; charset=utf-8',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'svg'  => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico'  => 'image/x-icon',
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'gz'   => 'application/gzip',
            'mp3'  => 'audio/mpeg',
            'mp4'  => 'video/mp4',
            'wasm' => 'application/wasm',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    public function send() {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }

            foreach ($this->cookies as $cookie) {
                header("Set-Cookie: $cookie", false);
            }
        }

        echo $this->body;
    }
}
