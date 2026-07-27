<?php
/**
 * database/tools/line_schedule_diagnose.php — ตรวจสอบการตั้งค่า LINE และสถานะ outbox
 *
 * Usage: php line_schedule_diagnose.php
 */
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/cron/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/line_notify_settings.php';

$tz = new DateTimeZone('Asia/Bangkok');
$now = new DateTime('now', $tz);

echo "=== เวลาปัจจุบัน (Asia/Bangkok) ===\n";
echo $now->format('Y-m-d H:i:s') . ' (' . $now->format('l') . ")\n\n";

$diag = line_plesk_task_diagnostics();

echo "=== การตั้งค่า schedule + Plesk task ===\n";
foreach ($diag['tasks'] as $task) {
    echo sprintf(
        "%s | required=%s | status=%s | plesk=%s | last=%s | %s\n",
        $task['event_key'],
        !empty($task['required']) ? 'yes' : 'no',
        $task['status'],
        $task['script_path'] !== '' ? $task['script_path'] : '—',
        $task['last_run_at'] ?? '—',
        $task['status_message']
    );
}

echo "\n=== Outbox (notification_outbox) ===\n";
foreach ($diag['outbox'] as $status => $count) {
    if ($count > 0) {
        echo $status . ': ' . $count . "\n";
    }
}

$db = line_notify_db();
$res2 = $db->query('SELECT id, event_key, status, attempts, last_error, created_at FROM notification_outbox ORDER BY id DESC LIMIT 8');
echo "\n--- ล่าสุด ---\n";
if ($res2) {
    while ($r = $res2->fetch_assoc()) {
        echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "\n=== Worker (process outbox) ===\n";
$worker = line_plesk_test_worker(10);
echo json_encode($worker['stats'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

echo "\n=== Log file ===\n";
echo ($diag['log_exists'] ? 'found: ' : 'missing: ') . $diag['log_path'] . "\n";

echo "\n=== Windows Task (ถ้ารันบน Windows) ===\n";
foreach (['Production_LINE_Notify_Worker'] as $tn) {
    $cmd = 'schtasks /Query /TN ' . escapeshellarg($tn) . ' /FO LIST 2>&1';
    echo "--- $tn ---\n";
    echo shell_exec($cmd) ?: "(ไม่พบ task)\n";
}
