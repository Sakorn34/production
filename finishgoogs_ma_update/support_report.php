<?php
/**
 * support_report.php — รับเรื่องจากหน้าต่าง "แจ้งปัญหา" ท้ายทุกหน้า (ทั้งแอปผลิตและแอปอะไหล่) แล้วส่งเข้า LINE ผู้ดูแล
 *
 * POST multipart: csrf · message · page_url · page_title · img_full[] · img_preview[]
 * ตอบ JSON {ok, message}
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/support_report.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['csrf']) || !hash_equals(csrf(), (string) $_POST['csrf'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'คำขอไม่ถูกต้อง — รีเฟรชหน้าแล้วลองใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ลิงก์หน้าที่แจ้งต้องเป็นหน้าในระบบเราเท่านั้น — กันใช้ปุ่มนี้ส่งลิงก์อะไรก็ได้เข้า LINE ผู้ดูแล
$pageUrl = trim((string) ($_POST['page_url'] ?? ''));
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
$u = parse_url($pageUrl);
if (!$u || empty($u['host']) || strcasecmp((string) $u['host'], preg_replace('/:\d+$/', '', $host)) !== 0
    || strpos((string) ($u['path'] ?? ''), '/production/') !== 0) {
    $pageUrl = '';
}


$full = support_files('img_full');
$prev = support_files('img_preview');
$images = [];
foreach (array_slice($full, 0, SUPPORT_MAX_IMAGES) as $i => $f) {
    $p = support_save_image($f);
    if ($p === null) {
        continue;
    }
    $pv = isset($prev[$i]) ? support_save_image($prev[$i]) : null;
    $images[] = ['full' => $p, 'preview' => (string) $pv];
}
if (count($full) > 0 && !$images) {
    echo json_encode(['ok' => false, 'message' => 'อ่านรูปที่แนบไม่ได้ — ใช้รูป JPG หรือ PNG'], JSON_UNESCAPED_UNICODE);
    exit;
}

$r = support_report_send(
    (string) ($_POST['message'] ?? ''),
    $pageUrl,
    (string) ($_POST['page_title'] ?? ''),
    $images
);
echo json_encode($r, JSON_UNESCAPED_UNICODE);
