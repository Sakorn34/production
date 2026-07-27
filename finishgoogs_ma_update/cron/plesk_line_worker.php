<?php
/**
 * cron/plesk_line_worker.php — entry สำหรับ Plesk Scheduled Task (Run a PHP script)
 *
 * Worker ส่ง outbox LINE — ใช้เมื่อต้องการ task แยกทุก 2 นาที
 * ตั้ง cron: Run a PHP script → production/finishgoogs_ma_update/cron/plesk_line_worker.php
 * ความถี่ cron ใน Plesk: นาทีทุก 2 (expression: นาที/2, ชั่วโมง-วัน = ทุกค่า)
 */
require __DIR__ . '/line_notify_worker.php';
