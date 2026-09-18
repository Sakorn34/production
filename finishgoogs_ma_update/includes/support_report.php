<?php
/**
 * includes/support_report.php — ปุ่ม "แจ้งปัญหา" ท้ายทุกหน้า → ส่งเข้า LINE ของผู้ดูแลระบบ
 *
 * ผู้ดูแลคือ Tom (แสดงชื่อ "Sakorn D.") — ใช้ LINE userId ที่ผูกไว้แล้วในสรุปงานรายคน (work_people)
 * ส่งด้วยบอทตัวเดียวกับสรุปงานรายคน เพราะเป็นบอทที่ผู้ดูแลเพิ่มเพื่อนไว้ (บอทตัวจริงใช้ในกลุ่มอย่างเดียว)
 *
 * รูปที่แนบ: LINE รับรูปเป็นลิงก์ https สาธารณะเท่านั้น จึงเก็บไว้ที่ uploads/support/ ก่อน
 * (โฟลเดอร์ uploads ห้ามรันสคริปต์อยู่แล้ว) · เบราว์เซอร์ย่อรูปเป็น JPEG ให้ก่อนอัปโหลด 2 ขนาด:
 * ตัวเต็ม (ด้านยาว ≤ 1600px) กับตัวย่อสำหรับ preview ในแชต (≤ 480px — LINE จำกัด preview ไม่เกิน 1MB)
 */

require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';
require_once dirname(__DIR__, 2) . '/shared/line_flex_templates.php';
require_once __DIR__ . '/work_people.php';
require_once __DIR__ . '/work_summary_send.php';

/** @var string ชื่อผู้รับแจ้งที่แสดงบนหน้าจอ */
const SUPPORT_CONTACT_LABEL = 'Sakorn D.';
/** @var string ชื่อในทะเบียนคนทำงาน (work_people.display_name) ที่ผูก LINE ไว้ */
const SUPPORT_CONTACT_PERSON = 'Tom';
/** @var int แนบรูปได้สูงสุด — LINE ส่งได้ 5 ข้อความต่อครั้ง (ข้อความ 1 + รูป 4) */
const SUPPORT_MAX_IMAGES = 4;
/** @var int ขนาดไฟล์สูงสุดต่อรูป (ไบต์) — รูปผ่านการย่อจากเบราว์เซอร์มาแล้ว เกินนี้ถือว่าผิดปกติ */
const SUPPORT_MAX_BYTES = 4194304;

/** @var array<string,string> สถานะเรื่องที่แจ้ง */
const SUPPORT_STATUSES = ['new' => 'รับเรื่องแล้ว', 'doing' => 'กำลังแก้', 'done' => 'แก้แล้ว'];

/**
 * ตารางเก็บเรื่องที่แจ้ง — เรื่องไม่หายไปกับแชต LINE และตามได้ว่าแก้แล้วหรือยัง
 * collation ตรงกับตารางอื่นของระบบ (utf8mb4_unicode_ci)
 *
 * @return void
 */
function ensure_support_reports_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->query("CREATE TABLE IF NOT EXISTS support_reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        created_at DATETIME NOT NULL,
        reporter VARCHAR(100) NOT NULL DEFAULT '',
        page_title VARCHAR(200) NOT NULL DEFAULT '',
        page_url VARCHAR(500) NOT NULL DEFAULT '',
        message TEXT NULL,
        images_json TEXT NULL,
        app_version VARCHAR(40) NOT NULL DEFAULT '',
        sync_at VARCHAR(20) NOT NULL DEFAULT '',
        status ENUM('new','doing','done') NOT NULL DEFAULT 'new',
        status_by VARCHAR(100) NULL,
        status_at DATETIME NULL,
        admin_note TEXT NULL,
        line_result VARCHAR(255) NULL,
        KEY idx_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * จำนวนเรื่องที่ยังไม่แก้ (ป้ายตัวเลขในเมนูหลังบ้าน)
 *
 * @return int
 */
function support_open_count(): int
{
    ensure_support_reports_schema();
    $r = db()->query("SELECT COUNT(*) FROM support_reports WHERE status <> 'done'");
    return $r ? (int) $r->fetch_row()[0] : 0;
}

/**
 * LINE userId ของผู้รับแจ้ง
 *
 * @return string '' ถ้ายังไม่ได้ผูก LINE
 */
function support_contact_line_id(): string
{
    $r = qr(
        "SELECT line_user_id FROM work_people
         WHERE LOWER(display_name) = LOWER(?) AND line_user_id IS NOT NULL AND line_user_id <> ''
         ORDER BY id LIMIT 1",
        's',
        [SUPPORT_CONTACT_PERSON]
    )->fetch_row();
    return $r ? (string) $r[0] : '';
}

/**
 * เวลาที่ซิงก์สถานะเครื่องทั้งระบบครั้งล่าสุด (Dashboard สั่งทุก 6 ชม.)
 *
 * @return int|null unix time
 */
function support_last_sync_at(): ?int
{
    $flag = dirname(__DIR__) . '/uploads/.asset_status_sync_last';
    return is_file($flag) ? (int) filemtime($flag) : null;
}

/**
 * เก็บรูปที่อัปโหลด 1 รูป
 *
 * @param array<string,mixed> $file รายการเดียวจาก $_FILES
 * @return string|null path ใต้ uploads/ หรือ null ถ้าไม่ใช่รูป JPEG/PNG
 */
function support_save_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return null;
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > SUPPORT_MAX_BYTES) {
        return null;
    }
    $info = @getimagesize((string) $file['tmp_name']);
    // LINE แสดงรูปได้เฉพาะ JPEG และ PNG
    $ext = $info ? ['image/jpeg' => 'jpg', 'image/png' => 'png'][$info['mime']] ?? null : null;
    if ($ext === null) {
        return null;
    }
    $sub = 'support/' . date('Y/m');
    $dir = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    // ชื่อสุ่มยาว — ลิงก์รูปต้องเปิดได้โดยไม่ล็อกอิน (LINE ดึงเอง) จึงต้องเดาไม่ได้
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file((string) $file['tmp_name'], "$dir/$name")) {
        return null;
    }
    return "$sub/$name";
}

/**
 * แปลง $_FILES แบบหลายไฟล์ (name[]) เป็นรายการทีละไฟล์
 *
 * @param string $field
 * @return array<int,array<string,mixed>>
 */
function support_files(string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) {
        return [];
    }
    $out = [];
    foreach ($_FILES[$field]['name'] as $i => $n) {
        $out[] = [
            'name'     => $n,
            'tmp_name' => $_FILES[$field]['tmp_name'][$i] ?? '',
            'size'     => $_FILES[$field]['size'][$i] ?? 0,
            'error'    => $_FILES[$field]['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        ];
    }
    return $out;
}

/**
 * ส่งได้ไหมตอนนี้ — เช็คก่อนเก็บรูป จะได้ไม่มีรูปค้างในเซิร์ฟเวอร์จากเรื่องที่ส่งไม่ออก
 *
 * @return string ข้อความปัญหา หรือ '' ถ้าพร้อมส่ง
 */
function support_report_precheck(): string
{
    if (support_contact_line_id() === '') {
        return SUPPORT_CONTACT_LABEL . ' ยังไม่ได้ผูก LINE ในหน้าสรุปงานรายคน — ส่งไม่ได้';
    }
    if (!line_notify_is_enabled('support.report')) {
        return 'ระบบแจ้งเตือน LINE ปิดอยู่ (ตั้งค่าที่หน้าแจ้งเตือน LINE)';
    }
    return '';
}

/**
 * ส่งเรื่องแจ้งปัญหาเข้า LINE ผู้ดูแล
 *
 * @param string $message   ข้อความที่ผู้ใช้เล่า
 * @param string $pageUrl   หน้าที่เปิดอยู่ตอนกดแจ้ง
 * @param string $pageTitle
 * @param array<int,array{full:string,preview:string}> $images path ใต้ uploads/
 * @return array{ok:bool, message:string}
 */
function support_report_send(string $message, string $pageUrl, string $pageTitle, array $images): array
{
    $message = trim($message);
    if ($message === '' && !$images) {
        return ['ok' => false, 'message' => 'เล่าปัญหาหรือแนบรูปอย่างน้อย 1 อย่าง'];
    }
    ensure_support_reports_schema();
    $base = work_summary_app_base_url();
    $imgUrls = [];
    foreach (array_slice($images, 0, SUPPORT_MAX_IMAGES) as $im) {
        $imgUrls[] = [
            'full'    => $base . '/uploads/' . $im['full'],
            'preview' => $base . '/uploads/' . ($im['preview'] !== '' ? $im['preview'] : $im['full']),
        ];
    }
    $sync = support_last_sync_at();
    $syncText = $sync ? date('d/m/Y H:i', $sync) : '';
    $version = defined('APP_RELEASE_VERSION') ? (string) APP_RELEASE_VERSION : '';
    $from = actor_name();

    // เก็บเรื่องก่อนเสมอ — ส่ง LINE ไม่ได้ก็ยังเปิดดูได้ในหน้าเรื่องที่แจ้งเข้ามา
    $st = db()->prepare('INSERT INTO support_reports (created_at, reporter, page_title, page_url, message, images_json, app_version, sync_at)
                         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)');
    $title = mb_substr(trim($pageTitle), 0, 200);
    $url = mb_substr($pageUrl, 0, 500);
    $msg = mb_substr($message, 0, 3000);
    $imgJson = json_encode($images, JSON_UNESCAPED_UNICODE);
    $st->bind_param('sssssss', $from, $title, $url, $msg, $imgJson, $version, $syncText);
    $st->execute();
    $reportId = (int) $st->insert_id;
    $st->close();

    $err = support_report_precheck();
    if ($err !== '') {
        support_report_set_line_result($reportId, $err);
        return ['ok' => true, 'message' => 'บันทึกเรื่องแล้ว (#' . $reportId . ') แต่ยังส่ง LINE ไม่ได้: ' . $err];
    }
    $payload = [
        'report_id'  => $reportId,
        'report_url' => $base . '/support_reports.php?id=' . $reportId,
        'from'       => $from,
        'at'         => date('d/m/Y H:i'),
        'page_title' => $title,
        'message'    => mb_substr($message, 0, 1500),
        'images'     => $imgUrls,
    ];
    $id = line_notify_dispatch('support.report', $payload, [
        'recipient_id' => support_contact_line_id(),
        'bot'          => WORK_SUMMARY_LINE_BOT,
        'skip_dedup'   => true,
    ]);
    if ($id === null) {
        support_report_set_line_result($reportId, 'เข้าคิวส่ง LINE ไม่ได้');
        return ['ok' => true, 'message' => 'บันทึกเรื่องแล้ว (#' . $reportId . ') แต่เข้าคิวส่ง LINE ไม่ได้'];
    }
    // ส่งทันที ไม่รอ cron รอบถัดไป — คนแจ้งควรรู้ผลตอนนี้เลยว่าถึงแล้ว
    line_notify_process_outbox(10);
    $row = line_notify_db() ? line_notify_db()->query('SELECT status, last_error FROM notification_outbox WHERE id = ' . (int) $id)->fetch_assoc() : null;
    if ($row && $row['status'] === 'sent') {
        support_report_set_line_result($reportId, 'ส่งแล้ว');
        return ['ok' => true, 'message' => 'ส่งถึง ' . SUPPORT_CONTACT_LABEL . ' ทาง LINE แล้ว (เรื่อง #' . $reportId . ')'];
    }
    if ($row && in_array($row['status'], ['failed', 'dead'], true)) {
        support_report_set_line_result($reportId, 'ส่งไม่สำเร็จ: ' . mb_substr((string) $row['last_error'], 0, 200));
        return ['ok' => true, 'message' => 'บันทึกเรื่องแล้ว (#' . $reportId . ') แต่ส่ง LINE ไม่สำเร็จ: ' . mb_substr((string) $row['last_error'], 0, 120)];
    }
    support_report_set_line_result($reportId, 'รอส่ง');
    return ['ok' => true, 'message' => 'รับเรื่องแล้ว (#' . $reportId . ') — ระบบจะส่งเข้า LINE ในไม่กี่นาที'];
}

/**
 * บันทึกผลการส่ง LINE ไว้กับเรื่อง
 *
 * @param int    $id
 * @param string $text
 * @return void
 */
function support_report_set_line_result(int $id, string $text): void
{
    qr('UPDATE support_reports SET line_result = ? WHERE id = ?', 'si', [mb_substr($text, 0, 255), $id]);
}
