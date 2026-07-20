<?php
/**
 * database/tools/test_line_notify_uat.php — UAT ระบบ LINE notification (CLI)
 *
 * ทดสอบ schema, dedup, enqueue โดยไม่ต้องส่ง LINE จริง (ถ้า disabled)
 *
 * Usage: php database/tools/test_line_notify_uat.php
 */
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';

echo "=== LINE Notify UAT ===\n";

line_notify_ensure_schema();
echo "[OK] schema ensure\n";

$dedupKey = 'uat.test:' . date('Y-m-d-H-i-s');
$first = line_notify_dedup_take($dedupKey, 60);
$second = line_notify_dedup_take($dedupKey, 60);
echo $first && !$second ? "[OK] dedup blocks duplicate\n" : "[FAIL] dedup\n";

$enabled = line_notify_is_enabled();
echo "LINE enabled: " . ($enabled ? 'yes' : 'no') . "\n";

if ($enabled) {
    $id = line_notify_dispatch('line.test', [
        'message' => 'UAT enqueue ' . date('c'),
    ], ['dedup_key' => 'line.test:uat:' . time()]);
    echo $id ? "[OK] enqueued outbox id={$id}\n" : "[SKIP] dispatch returned null\n";
    $stats = line_notify_process_outbox(3);
    echo "[INFO] worker: " . json_encode($stats) . "\n";
} else {
    echo "[SKIP] LINE disabled — ตั้งค่าที่ line_notify_settings.php ก่อนทดสอบส่งจริง\n";
}

$recent = line_notify_recent_outbox(3);
echo "[INFO] recent outbox: " . count($recent) . " rows\n";
echo "Done.\n";
