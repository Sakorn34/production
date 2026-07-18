<?php
/**
 * healthz.php — ตรวจ DB ทั้ง 3 ตัว (production, stockparts, tech_parts)
 */
require __DIR__ . '/config.php';

header('Content-Type: text/plain');
$errors = [];

try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    $errors[] = 'production';
    error_log('[healthz] production DB failed: ' . $e->getMessage());
}

try {
    dbStock()->query('SELECT 1');
} catch (Throwable $e) {
    $errors[] = 'stockparts';
    error_log('[healthz] stockparts DB failed: ' . $e->getMessage());
}

try {
    dbParts()->query('SELECT 1');
} catch (Throwable $e) {
    $errors[] = 'techparts';
    error_log('[healthz] techparts DB failed: ' . $e->getMessage());
}

if ($errors) {
    http_response_code(500);
    echo 'db_error:' . implode(',', $errors);
    exit;
}

http_response_code(200);
echo 'ok';
