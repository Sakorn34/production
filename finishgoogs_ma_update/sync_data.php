<?php
/**
 * sync_data.php — AJAX endpoint ซิงก์ข้อมูล stock จากทะเบียนเครื่องผลิต
 */
require __DIR__ . '/config.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if (!settings_admin_unlocked()) {
    echo json_encode(['ok' => false, 'error' => 'ต้องเข้าหน้าหลังบ้านก่อน'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'ใช้ POST เท่านั้น'], JSON_UNESCAPED_UNICODE);
    exit;
}

csrf_check();

try {
    $result = share_sync_all_from_production();
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[sync_data.php] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'ซิงก์ไม่สำเร็จ'], JSON_UNESCAPED_UNICODE);
}
