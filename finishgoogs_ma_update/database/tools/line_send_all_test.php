<?php
/**
 * database/tools/line_send_all_test.php — ทดสอบส่ง LINE ทุกประเภท
 */
$GLOBALS['line_notify_cli'] = true;
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 3) . '/shared/line_notify_jobs.php';

line_notify_ensure_schema();

if (!line_notify_is_enabled()) {
    echo "LINE disabled — เปิดใช้งานใน line_notify_settings.php ก่อน\n";
    exit(1);
}

$catalog = line_notify_type_catalog();
$results = [];

foreach ($catalog as $eventKey => $meta) {
    $label = (string)($meta['label'] ?? $eventKey);
    echo "\n--- {$label} ({$eventKey}) ---\n";
    $r = line_notify_send_now($eventKey);
    $results[] = [
        'event'   => $eventKey,
        'label'   => $label,
        'ok'      => !empty($r['ok']),
        'message' => (string)($r['message'] ?? ''),
        'detail'  => (string)($r['detail'] ?? ''),
    ];
    echo ($r['ok'] ? 'OK' : 'FAIL') . ': ' . ($r['message'] ?? '') . "\n";
    if (!empty($r['detail'])) {
        echo '  ' . $r['detail'] . "\n";
    }
    usleep(300000);
}

echo "\n=== SUMMARY ===\n";
$ok = 0;
$fail = 0;
foreach ($results as $r) {
    $status = $r['ok'] ? 'OK  ' : 'FAIL';
    if ($r['ok']) {
        $ok++;
    } else {
        $fail++;
    }
    echo "{$status}  {$r['label']}: {$r['message']}\n";
}
echo "\nTotal: {$ok} sent, {$fail} failed/skipped\n";
exit($fail > 0 && $ok === 0 ? 1 : 0);
