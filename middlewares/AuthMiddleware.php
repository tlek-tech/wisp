<?php

class AuthMiddleware {
    public function handle($req, $next, $env) {
        $secret = $_ENV['API_SECRET_KEY'] ?? '';
        $token = $req->bearerToken();

        if ($secret === '' || $token === null || !hash_equals($secret, $token)) {
            return Response::json([
                'error' => 'Unauthorized'
            ], 401);
        }
        return $next($req);
    }
}
