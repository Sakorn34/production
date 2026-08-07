<?php
/** cron/plesk_line_job_low_stock.php — Plesk: สแกนอะไหล่ที่ถึงขั้นต่ำแล้ว (ควรสั่งเพิ่ม) */
define('LINE_PLESK_JOB', 'low_stock_scan');
require __DIR__ . '/plesk_line_run_job.php';
