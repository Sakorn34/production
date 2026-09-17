<?php
/**
 * import_installation_history.php — นำเข้า CSV ประวัติติดตั้งระบบเดิม (CLI)
 *
 *   php database/tools/import_installation_history.php path/to/installation_history.csv
 *
 * CSV สร้างจาก export_installation_history.ps1 · แทนที่ข้อมูลเดิมในตาราง installation_history ทั้งหมด
 * บน server ใช้หน้าหลังบ้าน installation_history.php แทน (อัปโหลดไฟล์)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/installation_history.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "ใช้: php import_installation_history.php <ไฟล์ CSV>\n");
    exit(1);
}
$r = installation_history_import_csv($path);
if (!$r['ok']) {
    fwrite(STDERR, 'นำเข้าไม่สำเร็จ: ' . $r['error'] . "\n");
    exit(1);
}
printf("อ่าน %s แถว · บันทึก %s serial จาก %s งาน · ข้าม %s แถว (ไม่มี serial ที่ใช้ได้)\n",
    number_format($r['rows_read']), number_format($r['rows_saved']), number_format($r['jobs']), number_format($r['skipped']));
