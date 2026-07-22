<?php
require_once dirname(__DIR__, 2) . '/includes/xlsx_reader.php';
require_once dirname(__DIR__, 2) . '/includes/vendor_import.php';

$file = 'D:/AppServ/www/_ฐานข้อมูลเก่า/stockpartDB.xlsx';
$rows = xlsx_read_rows($file, 0);
$withDealer = 0;
$withLink = 0;
foreach ($rows as $r) {
    if (trim($r['Dealer'] ?? '') !== '') {
        $withDealer++;
    }
    if (trim($r['Link'] ?? '') !== '') {
        $withLink++;
    }
}
echo "Excel with Dealer: $withDealer\n";
echo "Excel with Link: $withLink\n";

require_once dirname(__DIR__, 2) . '/config/database.php';
$db = getDB();
$preview = vendor_import_build_updates($rows, $db);
echo 'updates to apply: ' . count($preview['updates']) . "\n";
echo 'skip no data: ' . $preview['skip_empty'] . "\n";
echo 'skip bad code: ' . $preview['skip_bad_code'] . "\n";
echo 'not in DB: ' . count($preview['missing']) . "\n";
echo "sample:\n";
foreach (array_slice($preview['updates'], 0, 5) as $u) {
    echo json_encode($u, JSON_UNESCAPED_UNICODE) . "\n";
}
