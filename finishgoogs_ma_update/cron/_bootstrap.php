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

$GLOBALS['line_notify_cli'] = true;
define('LINE_NOTIFY_CLI', true);

$root = dirname(__DIR__);
require $root . '/config.php';
require_once dirname($root) . '/shared/line_notify_core.php';
require_once dirname($root) . '/shared/line_flex_templates.php';
line_notify_ensure_schema();
