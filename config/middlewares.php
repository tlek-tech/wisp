<?php
global $app;

// Register global middlewares here
$app->use(function($req, $next) {
    $start = microtime(true);
    
    $res = $next($req);
    
    if ($res instanceof Response) {
        $duration = round((microtime(true) - $start) * 1000, 2);
        $res->setHeader('X-Response-Time', $duration . 'ms');
    }
    return $res;
});
