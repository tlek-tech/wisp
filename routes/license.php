<?php
global $app;

// 1. Example of a file-level/route-level middleware registered directly in the route file
$app->use(function($req, $next) {
    // This inline middleware runs for all endpoints declared below it
    // We will just let it pass for demonstration purposes
    return $next($req);
});

// 2. A POST route protected specifically by AuthMiddleware (Route-specific Middleware)
// Rate-limited first to blunt brute-force attempts against the secret key.
$app->post("/license", RateLimitMiddleware::class, AuthMiddleware::class, function($req) {
    return Response::json([
        "success" => true,
        "message" => "License checked successfully under secure route."
    ]);
});

// 3 & 4. Demo/debug-only routes — never registered unless APP_DEBUG=true, since they
// expose DB connectivity and internal error details that shouldn't be public.
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true') {
    // 3. Demo route: DB test utilizing direct container database connection
    $app->get("/db-test", function($req, $env) {
        try {
            $pdo = $env->db;
            $stmt = $pdo->prepare("SELECT VERSION() as version");
            $stmt->execute();
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return Response::json([
                "status" => "success",
                "db_version" => $results[0]['version'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            error_log('[db-test] ' . $e->getMessage());
            return Response::json([
                "status" => "error",
                "message" => "Database error"
            ], 500);
        }
    });

    // 4. Demo route: Accessing the auto-wired TranslateService via $env->translateService
    $app->get("/api/translate-test", function($req, $env) {
        $text = $req->query['text'] ?? 'hello world';

        // Auto-wiring: Container parses TranslateService constructor, resolves 'db' automatically!
        $result = $env->translateService->translate($text);

        return Response::json([
            "original" => $text,
            "translated" => $result
        ]);
    });
}
