<?php
/**
 * cron/plesk_line_job_monthly.php — Plesk: สรุปรายเดือน (วันสุดท้ายของเดือน)
 *
 * Plesk Cron: `MM HH 28-31 * *` (เช่น `10 20 28-31 * *`) — รัน 28–31 แต่ส่ง LINE เฉพาะวันสุดท้ายจริง
 */
define('LINE_PLESK_JOB', 'monthly');
require __DIR__ . '/plesk_line_run_job.php';
