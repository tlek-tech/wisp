<?php
global $app;

$app->get("/version", function($req) {
    return Response::json([
        "version" => "1.2.0"
    ]);
});
