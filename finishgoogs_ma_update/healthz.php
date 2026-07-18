<?php
// Lightweight health check for external uptime monitors (UptimeRobot/healthchecks.io)
require __DIR__ . '/config.php';

header('Content-Type: text/plain');
try {
    db()->query('SELECT 1');
    dbStock()->query('SELECT 1');
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    error_log('[healthz] DB check failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'db_error';
}
