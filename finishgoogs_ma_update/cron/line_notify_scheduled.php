<?php
/**
 * cron/line_notify_scheduled.php — รันงาน LINE ตาม job (CLI / manual)
 *
 * Usage:
 *   php line_notify_scheduled.php --job=daily|daily_update|weekly|monthly|low_stock_scan|test
 */
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_jobs.php';

$job = 'daily';
foreach ($argv ?? [] as $arg) {
    if (strpos($arg, '--job=') === 0) {
        $job = substr($arg, 6);
    }
}

if (!line_notify_is_enabled() && $job !== 'test') {
    echo "LINE notify disabled\n";
    exit(0);
}

$result = ['job' => $job, 'dispatched' => 0, 'skipped' => ''];

switch ($job) {
    case 'test':
        line_notify_dispatch('line.test', [
            'message' => 'Cron test — ' . date('d/m/Y H:i:s'),
        ], ['dedup_key' => 'line.test:cron:' . time()]);
        $stats = line_notify_process_outbox(5);
        $result['worker'] = $stats;
        $result['dispatched'] = 1;
        break;

    case 'cleanup':
        line_notify_snapshot_cleanup();
        $result['skipped'] = 'cleanup done';
        break;

    default:
        $run = line_notify_run_job($job, []);
        $result = array_merge($result, $run);
}

line_notify_snapshot_cleanup();
echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
