<?php

class AuthMiddleware {
    public function handle($req, $next, $env) {
        $secret = $_ENV['API_SECRET_KEY'] ?? '';
        $auth = $req->header('Authorization') ?? '';

        if ($secret === '' || !hash_equals('Bearer ' . $secret, $auth)) {
            return Response::json([
                'error' => 'Unauthorized'
            ], 401);
        }
        return $next($req);
    }
}
