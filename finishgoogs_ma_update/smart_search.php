<?php
/**
 * smart_search.php — AJAX ค้นหาอัจฉริยะ sidebar (S/N, MA, อัปเดต)
 *
 * GET ?ajax=1&q=... → JSON รายการผลลัพธ์
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/smart_search.php';
require_login();

if (!isset($_GET['ajax']) || (string) $_GET['ajax'] !== '1') {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
$items = smart_search_query($q, 3);

echo json_encode($items, JSON_UNESCAPED_UNICODE);
