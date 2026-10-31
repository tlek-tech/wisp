<?php
// Router script for PHP's built-in server (php -S ... router.php / `php wisp serve`).
// Serves real files from public/ and sends everything else to the app.
$uri = urldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicDir = realpath(__DIR__ . '/public');

if ($uri !== '/' && $publicDir !== false && strpos($uri, "\0") === false) {
    // realpath() resolves "../" (and Windows "..\\") first, so the prefix check below is what
    // actually keeps requests like /../.env or /../src/App.php inside public/.
    $file = realpath($publicDir . $uri);

    if ($file !== false && is_file($file) && strpos($file, $publicDir . DIRECTORY_SEPARATOR) === 0) {
        $relative = substr($file, strlen($publicDir) + 1);
        $hidden = false;
        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            // Dotfiles (.env, .htaccess, .git/...) are never served; .well-known/ is the exception.
            if ($segment !== '' && $segment[0] === '.' && $segment !== '.well-known') {
                $hidden = true;
                break;
            }
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        // PHP files go through the app (public/index.php) instead of being dumped as source.
        if (!$hidden && $ext !== 'php' && $ext !== 'phtml') {
            $mimes = [
                'css'  => 'text/css',
                'js'   => 'application/javascript',
                'json' => 'application/json',
                'html' => 'text/html',
                'txt'  => 'text/plain',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'ico'  => 'image/x-icon',
                'svg'  => 'image/svg+xml',
                'woff' => 'font/woff',
                'woff2'=> 'font/woff2',
            ];
            $mime = $mimes[$ext] ?? (function_exists('mime_content_type') ? mime_content_type($file) : '') ?: 'application/octet-stream';
            header('Content-Type: ' . $mime);
            header('X-Content-Type-Options: nosniff');
            readfile($file);
            exit;
        }
    }
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once __DIR__ . '/public/index.php';
