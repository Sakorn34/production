<?php
// Lightweight health check for external uptime monitors (UptimeRobot/healthchecks.io)
require __DIR__ . '/config/database.php';

header('Content-Type: text/plain');
try {
    getDB()->query('SELECT 1');
    http_response_code(200);
    echo 'ok';
} catch (Throwable $e) {
    error_log('[healthz] DB check failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'db_error';
}
