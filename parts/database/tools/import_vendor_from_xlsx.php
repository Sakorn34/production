<?php
/**
 * database/tools/import_vendor_from_xlsx.php — CLI นำเข้า Dealer/Link จาก stockpartDB.xlsx
 *
 * Usage:
 *   php parts/database/tools/import_vendor_from_xlsx.php [--dry-run] [--file=path] [--only-empty]
 *
 * ค่าเริ่มต้นไฟล์: D:/AppServ/www/_ฐานข้อมูลเก่า/stockpartDB.xlsx
 */

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/xlsx_reader.php';
require_once dirname(__DIR__, 2) . '/includes/vendor_import.php';

$defaultFile = 'D:/AppServ/www/_ฐานข้อมูลเก่า/stockpartDB.xlsx';
$dryRun = in_array('--dry-run', $argv, true);
$onlyEmpty = in_array('--only-empty', $argv, true);
$file = $defaultFile;

foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $file = substr($arg, 7);
    }
}

if (!is_readable($file)) {
    fwrite(STDERR, "ไม่พบไฟล์: {$file}\n");
    exit(1);
}

echo ($dryRun ? '[DRY RUN] ' : '') . "อ่าน: {$file}\n";
$rows = xlsx_read_rows($file, 0);
echo 'แถวในไฟล์: ' . count($rows) . "\n";

$db = getDB();
$plan = vendor_import_build_updates($rows, $db);
echo 'พร้อมอัปเดต: ' . count($plan['updates']) . "\n";
echo 'ข้าม (ไม่มี Dealer/Link): ' . $plan['skip_empty'] . "\n";
echo 'ข้าม (รหัสไม่ถูกต้อง): ' . $plan['skip_bad_code'] . "\n";
echo 'ไม่พบใน DB: ' . count($plan['missing']) . "\n";

if ($plan['missing'] !== []) {
    echo '  ตัวอย่าง missing: ' . implode(', ', array_slice($plan['missing'], 0, 10)) . "\n";
}

if ($dryRun) {
    foreach (array_slice($plan['updates'], 0, 8) as $u) {
        echo json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}

$result = vendor_import_apply($plan['updates'], $db, $onlyEmpty);
echo "อัปเดตแล้ว: {$result['updated']}\n";
echo "ข้าม (มีค่าอยู่แล้ว): {$result['skipped_existing']}\n";
if ($result['errors'] !== []) {
    echo "ข้อผิดพลาด:\n";
    foreach ($result['errors'] as $err) {
        echo "  - {$err}\n";
    }
}
