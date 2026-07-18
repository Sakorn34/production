<?php
/**
 * database/tools/import_stock_code_from_csv.php — นำเข้า stock_code จาก CSV (คอลัมน์ ID → P00001)
 *
 * จับคู่ production.parts กับแถว CSV โดย:
 * 1) parts.part_code = CSV Code (ถ้า Code ไม่ว่าง)
 * 2) parts.name = CSV Name (ถ้า Code ว่าง)
 *
 * รัน: php database/tools/import_stock_code_from_csv.php [path/to/file.csv]
 */
require dirname(__DIR__, 2) . '/config.php';

$csvPath = isset($argv[1]) ? $argv[1] : 'C:/Users/werto/Downloads/stock part DB - Parts.csv';
if (!is_file($csvPath)) {
    fwrite(STDERR, "ไม่พบไฟล์: $csvPath\n");
    exit(1);
}

ensure_parts_stock_code_schema();

/**
 * หา parts.id จากแถว CSV
 *
 * @param string $csvCode
 * @param string $csvName
 * @return array{id:int,name:string,stock_code:?string}|null
 */
function import_find_part_row($csvCode, $csvName) {
    if ($csvCode !== '') {
        $part = qr('SELECT id, name, stock_code FROM parts WHERE part_code=? LIMIT 1', 's', [$csvCode])->fetch_assoc();
        if ($part) {
            return $part;
        }
    }
    if ($csvName === '') {
        return null;
    }
    $part = qr('SELECT id, name, stock_code FROM parts WHERE name=? LIMIT 1', 's', [$csvName])->fetch_assoc();
    if ($part) {
        return $part;
    }
    // ชื่อใน DB มักยาวกว่า CSV เช่น "C 10pF" → "C 10pF 50V"
    $part = qr('SELECT id, name, stock_code FROM parts WHERE name LIKE ? LIMIT 2', 's', [$csvName . '%'])->fetch_assoc();
    if ($part) {
        $dup = qr('SELECT id FROM parts WHERE name LIKE ? AND id<>?', 'si', [$csvName . '%', (int)$part['id']])->fetch_assoc();
        if (!$dup) {
            return $part;
        }
    }
    // normalize: "C10000 35v" ↔ "C 10000μF 35V"
    $normCsv = import_normalize_part_name($csvName);
    if ($normCsv !== '') {
        $res = db()->query('SELECT id, name, stock_code FROM parts WHERE stock_code IS NULL OR TRIM(stock_code)=""');
        while ($cand = $res->fetch_assoc()) {
            if (import_normalize_part_name($cand['name']) === $normCsv) {
                return $cand;
            }
            $normDb = import_normalize_part_name($cand['name']);
            if ($normDb !== '' && (strpos($normDb, $normCsv) === 0 || strpos($normCsv, $normDb) === 0)) {
                return $cand;
            }
        }
    }
    return null;
}

/**
 * ทำ normalize ชื่ออะไหล่เพื่อจับคู่ CSV ↔ DB
 *
 * @param string $s
 * @return string
 */
function import_normalize_part_name($s) {
    $s = mb_strtolower(trim((string)$s));
    if (preg_match_all('/[\d.]+/', $s, $m) && !empty($m[0])) {
        return implode('-', $m[0]);
    }
    $s = str_replace(['μ', 'µ', ' '], ['u', 'u', ''], $s);
    return $s;
}

$fh = fopen($csvPath, 'r');
if (!$fh) {
    fwrite(STDERR, "เปิดไฟล์ไม่ได้\n");
    exit(1);
}

$header = fgetcsv($fh);
if (!$header) {
    fwrite(STDERR, "CSV ว่าง\n");
    exit(1);
}

/** @var array<string,int> */
$col = [];
foreach ($header as $i => $h) {
    $col[trim((string)$h)] = (int)$i;
}
foreach (['Code', 'Name', 'ID'] as $need) {
    if (!isset($col[$need])) {
        fwrite(STDERR, "CSV ต้องมีคอลัมน์: $need\n");
        exit(1);
    }
}

$matched = 0;
$skipped = 0;
$notFound = [];
$conflict = [];

while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 3) {
        continue;
    }
    $csvCode = trim((string)($row[$col['Code']] ?? ''));
    $csvName = trim((string)($row[$col['Name']] ?? ''));
    $stockCode = trim((string)($row[$col['ID']] ?? ''));
    if ($stockCode === '' || $csvName === '') {
        $skipped++;
        continue;
    }

    $part = import_find_part_row($csvCode, $csvName);

    if (!$part) {
        $notFound[] = $stockCode . ' | ' . ($csvCode ?: $csvName);
        continue;
    }

    $dup = qr('SELECT id FROM parts WHERE id<>? AND stock_code=? LIMIT 1', 'is', [(int)$part['id'], $stockCode])->fetch_assoc();
    if ($dup) {
        $conflict[] = "$stockCode already on part #{$dup['id']}";
    }

    q('UPDATE parts SET stock_code=? WHERE id=?', 'si', [$stockCode, (int)$part['id']]);
    $matched++;
}

fclose($fh);

echo "=== import stock_code ===\n";
echo "CSV: $csvPath\n";
echo "อัปเดต stock_code: $matched\n";
echo "ข้าม (ไม่มี ID/Name): $skipped\n";
echo "ไม่พบใน production.parts: " . count($notFound) . "\n";
if ($notFound) {
    echo "--- not found (สูงสุด 20) ---\n";
    foreach (array_slice($notFound, 0, 20) as $line) {
        echo "  $line\n";
    }
}
if ($conflict) {
    echo "conflict: " . count($conflict) . "\n";
}

$res = db()->query('SELECT COUNT(*) c FROM parts WHERE stock_code IS NOT NULL AND TRIM(stock_code)<>""');
$row = $res ? $res->fetch_assoc() : ['c' => 0];
echo "parts ที่มี stock_code ตอนนี้: " . (int)$row['c'] . "\n";

exit(count($notFound) > 0 ? 2 : 0);
