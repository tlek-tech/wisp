<?php

class RateLimitMiddleware {
    private int $maxAttempts;
    private int $windowSeconds;
    private string $storageDir;

    public function __construct(int $maxAttempts = 10, int $windowSeconds = 60) {
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = __DIR__ . '/../storage/rate_limit';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    public function handle($req, $next, $env) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // ":" (present in every IPv6 address, e.g. "::1" for loopback) is invalid in Windows
        // filenames, so it can't be left in the allowed set here even though it's harmless on
        // Linux/macOS — otherwise every IPv6 client 500s on Windows via a fopen() error instead
        // of getting rate-limited.
        $key = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ip);
        $file = $this->storageDir . '/' . $key . '.json';

        $fp = fopen($file, 'c+');
        if ($fp === false) {
            // Storage unavailable — fail open rather than blocking all traffic.
            return $next($req);
        }

        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode($raw, true);
        $now = time();

        if (!is_array($data) || $now >= ($data['reset'] ?? 0)) {
            $data = ['count' => 0, 'reset' => $now + $this->windowSeconds];
        }

        $data['count']++;

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if ($data['count'] > $this->maxAttempts) {
            $retryAfter = max(1, $data['reset'] - $now);
            return Response::json([
                'error' => 'Too Many Requests',
                'retry_after_seconds' => $retryAfter
            ], 429)->setHeader('Retry-After', (string)$retryAfter);
        }

        return $next($req);
    }
}
