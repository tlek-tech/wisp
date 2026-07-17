<?php
global $app;

// Register manual service factories here
$app->set('db', function() {
    return Wisp\DB::connect();
});
