<?php
/**
 * database/tools/migrate_ma_items.php — ย้าย OK/Replace/Repair จาก versions_json → คอลัมน์ ma_records
 *
 * ข้อมูลเก่าจาก AppSheet ถูก import ไว้ใน versions_json แต่ไม่ได้ใส่ ok_items/replace_items/repair_items
 * รัน: php database/tools/migrate_ma_items.php
 * ตัวเลือก: php database/tools/migrate_ma_items.php --from-csv  (อ่าน CSV staging แล้วอัปเดต/เพิ่ม)
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require __DIR__ . '/../../config.php';

/** @param string|null $s */
function ma_csv_items($s) {
    if ($s === null || trim($s) === '') return null;
    $items = array_values(array_filter(array_map('trim', explode(',', $s)), function ($x) {
        return $x !== '' && $x !== '-';
    }));
    return $items ? implode(' , ', $items) : null;
}

/** @param array<string,mixed> $rec */
function items_from_json(array $rec) {
    $vj = !empty($rec['versions_json']) ? (json_decode($rec['versions_json'], true) ?: []) : [];
    return [
        'ok' => ma_csv_items(isset($rec['ok_items']) ? $rec['ok_items'] : (isset($vj['OK']) ? $vj['OK'] : null)),
        'replace' => ma_csv_items(isset($rec['replace_items']) ? $rec['replace_items'] : (isset($vj['Replace']) ? $vj['Replace'] : null)),
        'repair' => ma_csv_items(isset($rec['repair_items']) ? $rec['repair_items'] : (isset($vj['Repair']) ? $vj['Repair'] : null)),
    ];
}

echo "== migrate_ma_items: versions_json → ok/replace/repair columns ==\n";
$res = qr("SELECT id, ok_items, replace_items, repair_items, versions_json FROM ma_records
           WHERE versions_json IS NOT NULL AND TRIM(versions_json) <> '' AND versions_json <> '{}'");
$upd = 0;
while ($r = $res->fetch_assoc()) {
    $need = (trim((string)$r['ok_items']) === '' && trim((string)$r['replace_items']) === '' && trim((string)$r['repair_items']) === '');
    if (!$need) continue;
    $it = items_from_json($r);
    if (!$it['ok'] && !$it['replace'] && !$it['repair']) continue;
    q("UPDATE ma_records SET ok_items=?, replace_items=?, repair_items=? WHERE id=?",
      'sssi', [$it['ok'], $it['replace'], $it['repair'], (int)$r['id']]);
    $upd++;
}
echo "Updated from versions_json: $upd rows\n";

if (!in_array('--from-csv', $argv ?? [], true)) {
    echo "Done. (ใส่ --from-csv เพื่อ sync จาก CSV staging)\n";
    exit(0);
}

$staging = realpath(__DIR__ . '/../../../cluade/production/_archive/database/staging/staging');
if (!$staging || !is_dir($staging)) {
    $staging = realpath(__DIR__ . '/../../../../cluade/production/_archive/database/staging/staging');
}
if (!$staging) {
    echo "ไม่พบโฟลเดอร์ staging CSV\n";
    exit(1);
}

$files = [
    'bitVisitor_S__MA.csv' => 'bitVisitor S',
    'bitVisitor_Plus__MA.csv' => 'bitVisitor Plus',
    'bitVisitor_M__MA.csv' => 'bitVisitor M',
];

function csv_read_ma($path) {
    if (!is_file($path)) return [];
    $fp = fopen($path, 'r');
    $headers = null;
    $rows = [];
    while (($r = fgetcsv($fp)) !== false) {
        if ($headers === null) {
            $r[0] = str_replace("\xEF\xBB\xBF", '', $r[0]);
            $headers = array_map('trim', $r);
            continue;
        }
        if (count(array_filter($r, function ($v) { return trim((string)$v) !== ''; })) === 0) continue;
        $row = [];
        foreach ($headers as $i => $h) $row[$h] = isset($r[$i]) ? trim((string)$r[$i]) : '';
        $rows[] = $row;
    }
    fclose($fp);
    return $rows;
}

function parse_ma_date($s) {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})#', $s, $m)) {
        $a = (int)$m[1]; $b = (int)$m[2]; $y = (int)$m[3];
        if ($y < 100) $y += 2000;
        if ($a > 12 && $b <= 12) { $d = $a; $mo = $b; }
        elseif ($b > 12 && $a <= 12) { $mo = $a; $d = $b; }
        else { $d = $a; $mo = $b; }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return null;
}

$syncUpd = 0;
$syncIns = 0;
foreach ($files as $file => $productName) {
    $path = $staging . DIRECTORY_SEPARATOR . $file;
    $rows = csv_read_ma($path);
    echo "\n-- $file (" . count($rows) . " rows) --\n";
    foreach ($rows as $r) {
        $code = trim($r['Serial Number'] ?? '');
        if ($code === '') continue;
        $a = qr("SELECT a.id FROM assets a JOIN products p ON p.id=a.product_id
                 WHERE a.asset_code=? AND p.name=? LIMIT 1", 'ss', [$code, $productName])->fetch_assoc();
        if (!$a) continue;
        $when = parse_ma_date($r['Time Stamp'] ?? '');
        $round = null;
        if (preg_match('/\d+/', $r['รอบ MA'] ?? '', $m)) $round = (int)$m[0];
        $ok = ma_csv_items($r['OK'] ?? '');
        $rep = ma_csv_items($r['Replace'] ?? '');
        $fix = ma_csv_items($r['Repair'] ?? '');
        $fw = trim($r['Version Firmware'] ?? '') ?: null;
        $remark = trim($r['Remark'] ?? '') ?: null;
        $doneBy = trim($r['Record By'] ?? '') ?: null;

        $exist = qr("SELECT id FROM ma_records WHERE asset_id=? AND ma_round <=> ? AND visited_at <=> ? LIMIT 1",
                    'iis', [(int)$a['id'], $round, $when])->fetch_assoc();
        if ($exist) {
            q("UPDATE ma_records SET ok_items=COALESCE(NULLIF(?,''), ok_items),
                                   replace_items=COALESCE(NULLIF(?,''), replace_items),
                                   repair_items=COALESCE(NULLIF(?,''), repair_items),
                                   fw_version=COALESCE(?, fw_version),
                                   remark=COALESCE(?, remark),
                                   done_by=COALESCE(?, done_by)
                WHERE id=?",
              'ssssssi', [$ok, $rep, $fix, $fw, $remark, $doneBy, (int)$exist['id']]);
            $syncUpd++;
        } else {
            $result = $fix ? 'repair' : ($rep ? 'replace' : ($ok ? 'ok' : null));
            q("INSERT INTO ma_records (asset_id,ma_round,visited_at,result,ok_items,replace_items,repair_items,fw_version,remark,done_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              'iissssssss', [(int)$a['id'], $round, $when, $result, $ok, $rep, $fix, $fw, $remark, $doneBy]);
            $syncIns++;
        }
    }
}
echo "\nCSV sync: updated=$syncUpd inserted=$syncIns\n";
