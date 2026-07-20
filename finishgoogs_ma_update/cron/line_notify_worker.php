<?php
/**
 * cron/line_notify_worker.php — ประมวลผล outbox ส่ง LINE (รันทุก 2 นาที)
 *
 * Usage: php line_notify_worker.php
 */
require __DIR__ . '/_bootstrap.php';

if (!line_notify_is_enabled()) {
    echo "LINE notify disabled\n";
    exit(0);
}

$stats = line_notify_process_outbox(30);
echo json_encode($stats, JSON_UNESCAPED_UNICODE) . "\n";
exit(($stats['dead'] > 0 && $stats['sent'] === 0) ? 1 : 0);
