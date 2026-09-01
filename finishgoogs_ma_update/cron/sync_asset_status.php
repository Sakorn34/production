<?php
/**
 * cron/sync_asset_status.php — sync assets.status จากระบบเช่า + stockparts (CLI)
 *
 * ตั้ง schedule รายวัน เช่น 06:00:
 *   php D:\AppServ\www\production\finishgoogs_ma_update\cron\sync_asset_status.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CLI only'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/config.php';
require $root . '/includes/asset_status_sync.php';

$stats = asset_status_sync_all(true, 200);
echo json_encode([
    'ok' => true,
    'changed' => (int) ($stats['changed'] ?? 0),
    'total' => (int) ($stats['total'] ?? 0),
], JSON_UNESCAPED_UNICODE) . "\n";
