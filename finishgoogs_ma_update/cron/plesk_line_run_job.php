<?php
/**
 * cron/plesk_line_run_job.php — รันงาน LINE 1 ประเภท + ส่ง outbox
 *
 * ใช้ผ่านไฟล์ wrapper `plesk_line_job_*.php` ใน Plesk Scheduled Task (Run a PHP script)
 * ตั้ง **Daily/Cron** ใน Plesk — เวลาไม่ได้ตั้งในหลังบ้านอีก
 *
 * monthly: ตั้ง Cron รัน 28–31 (เช่น `10 20 28-31 * *`) — โค้ดส่งเฉพาะวันสุดท้ายเดือน
 */
if (!defined('LINE_PLESK_JOB')) {
    echo json_encode(['ok' => false, 'error' => 'ใช้ plesk_line_job_*.php'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_jobs.php';

$job = (string)LINE_PLESK_JOB;
$result = line_notify_run_job($job, []);
$worker = line_notify_is_enabled() ? line_notify_process_outbox(15) : ['skipped' => 'disabled'];
line_notify_snapshot_cleanup();

$out = [
    'job'    => $job,
    'result' => $result,
    'worker' => $worker,
];
line_notify_append_cron_log($out);
echo json_encode($out, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
