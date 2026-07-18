<?php
/**
 * export_stock_sheet.php — สร้างไฟล์ stock.csv สำหรับนำเข้า Google Sheets (ชีตชื่อ stock)
 *
 * ดึงจาก stock.zip (ไฟล์ .xlsx ฐานข้อมูลเก่า) หรือ staging CSV
 * ใช้เฉพาะชีต Finish Goods / 1203 / 1206 / 1212 — ไม่รวม Update, MA, QC
 *
 * คอลัมน์: timestamp, serialnumber, user
 *   timestamp    ← Time Stamp (หรือ Lot ถ้าไม่มีเวลา)
 *   serialnumber ← Serial Number
 *   user         ← Make By / Make by / Assembly (ชื่อแรกเท่านั้นถ้ามีหลายคน)
 *
 * รัน: php database/tools/export_stock_sheet.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('รันได้จาก command line เท่านั้น');
}
mb_internal_encoding('UTF-8');
set_time_limit(0);

// ── date / user helpers ───────────────────────────────────────────────────────

/**
 * ตรวจรูปแบบวันที่ slash ของคอลัมน์
 *
 * @param array<int, string> $values
 * @return string|array|null
 */
function ess_detect_date_fmt(array $values) {
    $c = ['timed' => ['dmy' => 0, 'mdy' => 0], 'untimed' => ['dmy' => 0, 'mdy' => 0]];
    foreach ($values as $s) {
        $s = trim((string)$s);
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/\d{2,4}#', $s, $m)) {
            continue;
        }
        $a = (int)$m[1];
        $b = (int)$m[2];
        $g = (strpos($s, ':') !== false) ? 'timed' : 'untimed';
        if ($a > 12 && $b <= 12) {
            $c[$g]['dmy']++;
        } elseif ($b > 12 && $a <= 12) {
            $c[$g]['mdy']++;
        }
    }
    $pick = function ($x) {
        if ($x['dmy'] === 0 && $x['mdy'] === 0) {
            return null;
        }
        return $x['dmy'] >= $x['mdy'] ? 'dmy' : 'mdy';
    };
    $t = $pick($c['timed']);
    $u = $pick($c['untimed']);
    if ($t === null && $u === null) {
        return null;
    }
    if ($t === null) {
        $t = $u;
    }
    if ($u === null) {
        $u = $t;
    }
    if ($t === $u) {
        return $t;
    }
    return ['timed' => $t, 'untimed' => $u];
}

/**
 * แปลงวันที่/เวลา → Y-m-d H:i:s
 *
 * @param string           $s
 * @param string|array|null $fmt
 * @return string|null
 */
function ess_parse_dt($s, $fmt = null) {
    $s = trim((string)$s);
    if ($s === '' || $s === '-') {
        return null;
    }
    if (is_array($fmt)) {
        $fmt = (strpos($s, ':') !== false) ? $fmt['timed'] : $fmt['untimed'];
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?(?:\s*(AM|PM|am|pm|น\.))?#iu', $s, $m)) {
        $a = (int)$m[1];
        $b = (int)$m[2];
        $y = (int)$m[3];
        if ($y < 100) {
            $y += 2000;
        }
        if ($y > 2400) {
            $y -= 543;
        }
        if ($a > 12 && $b <= 12) {
            $d = $a;
            $mo = $b;
        } elseif ($b > 12 && $a <= 12) {
            $mo = $a;
            $d = $b;
        } elseif ($fmt === 'dmy') {
            $d = $a;
            $mo = $b;
        } elseif ($fmt === 'mdy') {
            $mo = $a;
            $d = $b;
        } else {
            $mo = $a;
            $d = $b;
        }
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }
        $h = isset($m[4]) ? (int)$m[4] : 0;
        $mi = isset($m[5]) ? (int)$m[5] : 0;
        $sec = (isset($m[6]) && $m[6] !== '') ? (int)$m[6] : 0;
        $ap = isset($m[7]) ? strtoupper($m[7]) : '';
        if ($ap === 'PM' && $h < 12) {
            $h += 12;
        }
        if ($ap === 'AM' && $h === 12) {
            $h = 0;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $mi, $sec);
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?#', $s, $m)) {
        $h = isset($m[4]) ? (int)$m[4] : 0;
        $mi = isset($m[5]) ? (int)$m[5] : 0;
        $sec = isset($m[6]) ? (int)$m[6] : 0;
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $h, $mi, $sec);
    }
    return null;
}

/**
 * แปลง Excel serial date → Y-m-d H:i:s
 *
 * @param float|int|string $serial
 * @return string|null
 */
function ess_excel_serial_to_datetime($serial) {
    $serial = (float)$serial;
    if ($serial < 20000 || $serial > 120000) {
        return null;
    }
    $unix = (int)round(($serial - 25569) * 86400);
    return gmdate('Y-m-d H:i:s', $unix);
}

/**
 * แปลงค่าดิบจากเซลล์ให้เป็นข้อความที่ใช้งานได้
 *
 * @param string $raw
 * @param string $header ชื่อคอลัมน์ (ถ้ารู้)
 * @return string
 */
function ess_normalize_cell($raw, $header = '') {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('/^[\d.]+[eE][\+\-]?\d+$/', $raw)) {
        return sprintf('%.0f', (float)$raw);
    }
    if (is_numeric($raw)) {
        $f = (float)$raw;
        $h = strtolower($header);
        if (strpos($h, 'time') !== false || strpos($h, 'stamp') !== false || $header === 'Lot') {
            $dt = ess_excel_serial_to_datetime($f);
            if ($dt) {
                return $dt;
            }
        }
        if (strpos($h, 'serial') !== false && $f >= 1e10 && $f == floor($f)) {
            return sprintf('%.0f', $f);
        }
    }
    return $raw;
}

/**
 * ดึงชื่อผู้ผลิตคนแรกจาก Make By / Make by / Assembly
 *
 * @param array<string, string> $row
 * @return string
 */
function ess_first_user(array $row) {
    foreach (['Make By', 'Make by', 'Assembly', 'Record By'] as $col) {
        $v = trim($row[$col] ?? '');
        if ($v === '' || $v === '-') {
            continue;
        }
        $parts = preg_split('/[,;，、]+/u', $v);
        $first = trim((string)($parts[0] ?? ''));
        if ($first !== '') {
            return $first;
        }
    }
    return '';
}

/**
 * แปลงแถว CSV ดิบ → associative จากหัวคอลัมน์
 *
 * @param array<int, array<int, string>> $rawRows
 * @return array<int, array<string, string>>
 */
function ess_rows_assoc(array $rawRows) {
    if (!$rawRows) {
        return [];
    }
    $head = array_map('trim', $rawRows[0]);
    $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$head[0]);
    $out = [];
    for ($i = 1, $n = count($rawRows); $i < $n; $i++) {
        $r = $rawRows[$i];
        if (count(array_filter($r, function ($v) { return trim((string)$v) !== ''; })) === 0) {
            continue;
        }
        $row = [];
        foreach ($head as $ci => $h) {
            $row[$h] = ess_normalize_cell(isset($r[$ci]) ? (string)$r[$ci] : '', $h);
        }
        $out[] = $row;
    }
    return $out;
}

/**
 * ประมวลผลแถวผลิต → เก็บใน $bySerial
 *
 * @param array<int, array<string, string>> $rows
 * @param string $src
 * @param array<string, array<string, mixed>> &$bySerial
 * @return int
 */
function ess_ingest_rows(array $rows, $src, array &$bySerial) {
    if (!$rows) {
        return 0;
    }
    $n = 0;
    $tsSamples = array_map(function ($x) {
        return $x['Time Stamp'] ?? '';
    }, $rows);
    $fmt = ess_detect_date_fmt($tsSamples);
    foreach ($rows as $r) {
        $n++;
        $serial = ess_normalize_cell($r['Serial Number'] ?? '', 'Serial Number');
        if ($serial === '' || mb_strlen($serial) < 4) {
            continue;
        }
        $tsRaw = ess_normalize_cell($r['Time Stamp'] ?? '', 'Time Stamp');
        $ts = ess_parse_dt($tsRaw, $fmt);
        if (!$ts && preg_match('#^\d{4}-\d{2}-\d{2}#', $tsRaw)) {
            $ts = $tsRaw;
        }
        if (!$ts && !empty($r['Lot'])) {
            $lotRaw = ess_normalize_cell($r['Lot'], 'Lot');
            $ts = ess_parse_dt($lotRaw, $fmt);
            if (!$ts && preg_match('#^\d{4}-\d{2}-\d{2}#', $lotRaw)) {
                $ts = $lotRaw;
            }
        }
        $user = ess_first_user($r);
        $entry = [
            'timestamp' => $ts ?: '',
            'serialnumber' => $serial,
            'user' => $user,
            '_src' => $src,
            '_ts_sort' => $ts ?: '0000-00-00 00:00:00',
        ];
        if (!isset($bySerial[$serial])) {
            $bySerial[$serial] = $entry;
            continue;
        }
        if ($entry['_ts_sort'] >= $bySerial[$serial]['_ts_sort']) {
            if ($entry['user'] === '' && $bySerial[$serial]['user'] !== '') {
                $entry['user'] = $bySerial[$serial]['user'];
            }
            $bySerial[$serial] = $entry;
        } elseif ($bySerial[$serial]['user'] === '' && $entry['user'] !== '') {
            $bySerial[$serial]['user'] = $entry['user'];
        }
    }
    return $n;
}

// ── XLSX reader ───────────────────────────────────────────────────────────────

/**
 * แปลงอ้างอิงคอลัมน์ Excel (เช่น AB12) เป็น index 0-based
 *
 * @param string $ref
 * @return int
 */
function ess_xlsx_col_index($ref) {
    if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
        return 0;
    }
    $col = $m[1];
    $n = 0;
    for ($i = 0, $len = strlen($col); $i < $len; $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n - 1;
}

/**
 * อ่านค่าเซลล์ xlsx
 *
 * @param SimpleXMLElement $c
 * @param array<int, string> $ss
 * @return string
 */
function ess_xlsx_cell_value($c, array $ss) {
    if (!isset($c->v)) {
        return '';
    }
    $v = (string)$c->v;
    $t = (string)$c['t'];
    if ($t === 's') {
        return isset($ss[(int)$v]) ? trim($ss[(int)$v]) : '';
    }
    if ($t === 'n' || $t === '') {
        $f = (float)$v;
        if ($f >= 20000 && $f < 120000 && $f == floor($f)) {
            return (string)$f;
        }
        if ($f >= 1e10 && $f == floor($f)) {
            return sprintf('%.0f', $f);
        }
    }
    return trim($v);
}

/**
 * อ่านชีตจากไฟล์ xlsx เป็นแถวดิบ
 *
 * @param ZipArchive $zip
 * @param string     $sheetFile path ภายใน zip เช่น xl/worksheets/sheet1.xml
 * @param array<int, string> $ss shared strings
 * @return array<int, array<int, string>>
 */
function ess_xlsx_read_sheet_rows(ZipArchive $zip, $sheetFile, array $ss) {
    $xml = $zip->getFromName($sheetFile);
    if ($xml === false) {
        return [];
    }
    $sx = simplexml_load_string($xml);
    if (!$sx || !isset($sx->sheetData->row)) {
        return [];
    }
    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        $maxCol = 0;
        foreach ($row->c as $c) {
            $ci = ess_xlsx_col_index((string)$c['r']);
            if ($ci > $maxCol) {
                $maxCol = $ci;
            }
            $cells[$ci] = ess_xlsx_cell_value($c, $ss);
        }
        $line = [];
        for ($i = 0; $i <= $maxCol; $i++) {
            $line[] = $cells[$i] ?? '';
        }
        $rows[] = $line;
    }
    return $rows;
}

/**
 * ตรวจว่าชื่อชีตเป็นตารางผลิต (ไม่ใช่ Update/MA/QC)
 *
 * @param string $name
 * @return bool
 */
function ess_is_production_sheet($name) {
    $n = strtolower(trim($name));
    if (in_array($n, ['update', 'ma', 'qc', 'type image'], true)) {
        return false;
    }
    if ($n === 'finish goods' || strpos($n, 'finish goods') === 0) {
        return true;
    }
    if (in_array($n, ['1203', '1206', '1212'], true)) {
        return true;
    }
    return false;
}

/**
 * อ่านทุกชีตผลิตจากไฟล์ xlsx หนึ่งไฟล์
 *
 * @param string $xlsxPath
 * @param array<string, array<string, mixed>> &$bySerial
 * @return array{files:int,rows:int}
 */
function ess_ingest_xlsx($xlsxPath, array &$bySerial) {
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        return ['files' => 0, 'rows' => 0];
    }
    $ss = [];
    if (($x = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($x);
        if ($sx) {
            foreach ($sx->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } else {
                    foreach ($si->r as $rr) {
                        $t .= (string)$rr->t;
                    }
                }
                $ss[] = $t;
            }
        }
    }
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relsXml === false) {
        $zip->close();
        return ['files' => 0, 'rows' => 0];
    }
    $wb = simplexml_load_string($wbXml);
    $rels = simplexml_load_string($relsXml);
    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    }
    $files = 0;
    $rows = 0;
    $base = basename($xlsxPath);
    foreach ($wb->sheets->sheet as $s) {
        $sheetName = (string)$s['name'];
        if (!ess_is_production_sheet($sheetName)) {
            continue;
        }
        $rid = (string)$s->attributes('r', true)['id'];
        if (!isset($relMap[$rid])) {
            continue;
        }
        $target = $relMap[$rid];
        $sheetFile = (strpos($target, 'xl/') === 0) ? $target : 'xl/' . $target;
        $raw = ess_xlsx_read_sheet_rows($zip, $sheetFile, $ss);
        $assoc = ess_rows_assoc($raw);
        $src = $base . ' :: ' . $sheetName;
        $rows += ess_ingest_rows($assoc, $src, $bySerial);
        $files++;
    }
    $zip->close();
    return ['files' => $files, 'rows' => $rows];
}

// ── CSV staging reader ────────────────────────────────────────────────────────

/**
 * ตรวจว่าไฟล์ CSV เป็นตารางผลิต
 *
 * @param string $basename
 * @return bool
 */
function ess_is_production_csv($basename) {
    $bn = strtolower($basename);
    if (!preg_match('/\.csv$/', $bn)) {
        return false;
    }
    if (preg_match('/__(update|ma|qc)(_|\.)/', $bn) || preg_match('/__(update|ma|qc)\.csv$/', $bn)) {
        return false;
    }
    if (strpos($bn, 'finish_goods') !== false) {
        return true;
    }
    if (preg_match('/^bitsupply__(1203|1206|1212)\.csv$/', $bn)) {
        return true;
    }
    return false;
}

/**
 * อ่าน CSV จากโฟลเดอร์ staging
 *
 * @param string $dir
 * @param array<string, array<string, mixed>> &$bySerial
 * @return array{files:int,rows:int}
 */
function ess_ingest_staging_dir($dir, array &$bySerial) {
    $files = 0;
    $rows = 0;
    foreach (scandir($dir) as $fn) {
        if ($fn === '.' || $fn === '..' || !ess_is_production_csv($fn)) {
            continue;
        }
        $fp = fopen($dir . '/' . $fn, 'r');
        if (!$fp) {
            continue;
        }
        $raw = [];
        while (($r = fgetcsv($fp)) !== false) {
            $raw[] = $r;
        }
        fclose($fp);
        $assoc = ess_rows_assoc($raw);
        $rows += ess_ingest_rows($assoc, $fn, $bySerial);
        $files++;
    }
    return ['files' => $files, 'rows' => $rows];
}

/**
 * อ่านทุก xlsx จาก stock.zip
 *
 * @param string $zipPath
 * @param array<string, array<string, mixed>> &$bySerial
 * @return array{files:int,rows:int,xlsx:int}
 */
function ess_ingest_stock_zip($zipPath, array &$bySerial) {
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "ไม่มี ZipArchive\n");
        return ['files' => 0, 'rows' => 0, 'xlsx' => 0];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fwrite(STDERR, "เปิด zip ไม่ได้: $zipPath\n");
        return ['files' => 0, 'rows' => 0, 'xlsx' => 0];
    }
    $tmpdir = sys_get_temp_dir() . '/stock_xlsx_' . uniqid();
    mkdir($tmpdir, 0777, true);
    $xlsxCount = 0;
    $files = 0;
    $rows = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('/\.xlsx$/i', $name) || preg_match('/^stockpart/i', basename($name))) {
            continue;
        }
        $local = $tmpdir . '/' . basename($name);
        copy("zip://$zipPath#$name", $local);
        $stat = ess_ingest_xlsx($local, $bySerial);
        $files += $stat['files'];
        $rows += $stat['rows'];
        $xlsxCount++;
    }
    $zip->close();
    return ['files' => $files, 'rows' => $rows, 'xlsx' => $xlsxCount];
}

// ── main ──────────────────────────────────────────────────────────────────────

$defaultZip = realpath(__DIR__ . '/../../../cluade/production/_archive/ฐานข้อมูลเก่า/stock.zip');
$defaultStaging = realpath(__DIR__ . '/../../../cluade/production/_archive/database/staging/staging');
$input = isset($argv[1]) ? $argv[1] : '';

$bySerial = [];
$sourceLabel = '';
$filesUsed = 0;
$rowsRead = 0;

if ($input !== '') {
    if (is_file($input) && preg_match('/\.zip$/i', $input)) {
        $stat = ess_ingest_stock_zip($input, $bySerial);
        $sourceLabel = $input;
        $filesUsed = $stat['files'];
        $rowsRead = $stat['rows'];
        echo "อ่าน zip: {$stat['xlsx']} ไฟล์ xlsx · {$stat['files']} ชีตผลิต\n";
    } elseif (is_dir($input)) {
        $stat = ess_ingest_staging_dir($input, $bySerial);
        $sourceLabel = $input;
        $filesUsed = $stat['files'];
        $rowsRead = $stat['rows'];
        echo "อ่าน staging: {$stat['files']} ไฟล์ CSV\n";
    }
} elseif ($defaultZip && is_file($defaultZip)) {
    $stat = ess_ingest_stock_zip($defaultZip, $bySerial);
    $sourceLabel = $defaultZip;
    $filesUsed = $stat['files'];
    $rowsRead = $stat['rows'];
    echo "อ่าน stock.zip: {$stat['xlsx']} ไฟล์ xlsx · {$stat['files']} ชีตผลิต\n";
} elseif ($defaultStaging && is_dir($defaultStaging)) {
    $stat = ess_ingest_staging_dir($defaultStaging, $bySerial);
    $sourceLabel = $defaultStaging;
    $filesUsed = $stat['files'];
    $rowsRead = $stat['rows'];
    echo "อ่าน staging: {$stat['files']} ไฟล์ CSV\n";
}

if ($sourceLabel === '') {
    fwrite(STDERR, "ไม่พบ stock.zip หรือโฟลเดอร์ staging\n");
    exit(1);
}

$outDir = realpath(__DIR__ . '/../../exports');
if ($outDir === false) {
    $outDir = dirname(__DIR__) . '/../exports';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0777, true);
    }
}
$outPath = $outDir . DIRECTORY_SEPARATOR . 'stock.csv';

$rows = array_values($bySerial);
usort($rows, function ($a, $b) {
    $c = strcmp($a['_ts_sort'], $b['_ts_sort']);
    return $c !== 0 ? $c : strcmp($a['serialnumber'], $b['serialnumber']);
});

$fp = fopen($outPath, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['timestamp', 'serialnumber', 'user']);
foreach ($rows as $r) {
    $displayTs = $r['timestamp'];
    if ($displayTs !== '' && preg_match('#^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$#', $displayTs)) {
        $displayTs = date('n/j/Y G:i', strtotime($displayTs));
    }
    fputcsv($fp, [$displayTs, $r['serialnumber'], $r['user']]);
}
fclose($fp);

$withTs = 0;
$withUser = 0;
foreach ($rows as $r) {
    if ($r['timestamp'] !== '') {
        $withTs++;
    }
    if ($r['user'] !== '') {
        $withUser++;
    }
}

echo "แหล่ง: $sourceLabel\n";
echo "ประมวลผล: $filesUsed ชีต/ไฟล์ · อ่าน $rowsRead แถวดิบ\n";
echo "ผลลัพธ์: " . count($rows) . " เครื่อง (ไม่ซ้ำ serial)\n";
echo "  มี timestamp: $withTs · มี user: $withUser\n";
echo "บันทึก: $outPath\n";
echo "\nนำเข้า Google Sheets:\n";
echo "  1. เปิด Google Sheets → สร้างสเปรดชีตใหม่\n";
echo "  2. File → Import → Upload → เลือก stock.csv\n";
echo "  3. ตั้งชื่อชีตว่า \"stock\"\n";
