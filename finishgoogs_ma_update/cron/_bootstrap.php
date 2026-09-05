<?php
/**
 * cron/_bootstrap.php — bootstrap CLI สำหรับ LINE notification worker/scheduled
 */
// ป้องกันชั้นที่สอง (นอกเหนือจาก .htaccess) — งานเหล่านี้ส่ง LINE ออกจริง
// ถ้าเรียกผ่านเว็บได้ ใครก็สแปมพนักงานได้โดยไม่ต้อง login
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'CLI only'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

// Plesk มี php หลายรุ่นในเครื่องเดียว ถ้า task ไม่ได้ระบุตัวแปลภาษา มันจะหยิบ php
// ของระบบซึ่งมักเก่ากว่าที่เว็บใช้ แล้วตายตั้งแต่ parse config.php ด้วยข้อความ
// "unexpected '?'" (คือ ?string ที่ต้องใช้ 7.1 ขึ้นไป) ซึ่งอ่านแล้วไม่รู้ว่าต้องแก้อะไร
// เช็คตรงนี้ก่อน require เพื่อให้ log บอกสาเหตุจริงและบอกทางแก้
if (PHP_VERSION_ID < 70100) {
    fwrite(STDERR, 'PHP ที่ใช้รันงานนี้เก่าเกินไป: ' . PHP_VERSION . ' (ต้อง 7.1 ขึ้นไป)' . "\n"
        . 'ตั้ง Plesk Scheduled Task ให้ระบุตัวแปลภาษาเอง เช่น' . "\n"
        . '  /opt/plesk/php/8.2/bin/php <path>/cron/<script>.php' . "\n");
    exit(1);
}

$GLOBALS['line_notify_cli'] = true;
define('LINE_NOTIFY_CLI', true);

$root = dirname(__DIR__);
require $root . '/config.php';
require_once dirname($root) . '/shared/line_notify_core.php';
require_once dirname($root) . '/shared/line_flex_templates.php';
line_notify_ensure_schema();
