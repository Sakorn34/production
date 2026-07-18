<?php
/**
 * parse_production_history.php — แปลงประวัติการผลิตระบบเก่า → CSV สำหรับ import update_logs
 *
 * แหล่งข้อมูล (เลือกอย่างใดอย่างหนึ่ง):
 *   --url   ดึงจาก historyFG.php ของระบบเก่า (แนะนำ)
 *   --html  อ่านไฟล์ HTML ที่บันทึกไว้
 *   --pdf   อ่าน PDF (รองรับจำกัด)
 *
 * กรองเฉพาะแถวที่หมายเหตุไม่ว่างและไม่ใช่ "-"
 * ขยายช่วง S/N และดึงรหัสเครื่องเพิ่มจากหมายเหตุ
 * made_by = Admin, วันที่ตามรายการ
 *
 * รัน CLI:
 *   php database/tools/parse_production_history.php --url
 *   php database/tools/parse_production_history.php --html database/import/historyFG.html
 *
 * ผลลัพธ์: database/import/update_logs_from_history.csv
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

require dirname(__DIR__, 2) . '/config.php';

// ─ constants ─────────────────────────────────────────────────────────────────

$DEFAULT_URL = 'http://192.168.2.199/stockParts_tech/historyFG.php';
$DEFAULT_PDF = 'C:/Users/werto/Downloads/ประวัติการผลิต.pdf';
$OUT_CSV = dirname(__DIR__) . '/import/update_logs_from_history.csv';
$MADE_BY = 'Admin';

// ─ serial helpers ────────────────────────────────────────────────────────────

/** @return string[] */
function serial_patterns()
{
    return [
        '/\b[A-Z]{2,3}\d{5,10}\b/',
        '/\bSC\d{9,12}\b/',
        '/\bD\d{5,8}\b/',
        '/\bAA\d{10,14}\b/',
    ];
}

/**
 * ดึงรหัสเครื่องทั้งหมดจากข้อความ
 *
 * @param string $text
 * @return string[]
 */
function extract_serial_codes($text)
{
    $found = [];
    $text = strtoupper($text);
    foreach (serial_patterns() as $pat) {
        if (preg_match_all($pat, $text, $m)) {
            foreach ($m[0] as $code) {
                $found[$code] = true;
            }
        }
    }
    return array_keys($found);
}

/**
 * ขยายช่วงเลขเมื่อ prefix เหมือนกัน
 *
 * @param string $from
 * @param string $to
 * @return string[]
 */
function expand_serial_range($from, $to)
{
    if ($from === $to) {
        return [$from];
    }
    if (!preg_match('/^([A-Z]{1,3})(\d+)$/', $from, $fm) || !preg_match('/^([A-Z]{1,3})(\d+)$/', $to, $tm)) {
        return array_values(array_unique([$from, $to]));
    }
    if ($fm[1] !== $tm[1] || strlen($fm[2]) !== strlen($tm[2])) {
        return array_values(array_unique([$from, $to]));
    }
    $start = (int)$fm[2];
    $end = (int)$tm[2];
    if ($end < $start || ($end - $start) > 500) {
        return array_values(array_unique([$from, $to]));
    }
    $out = [];
    $pad = strlen($fm[2]);
    for ($i = $start; $i <= $end; $i++) {
        $out[] = $fm[1] . str_pad((string)$i, $pad, '0', STR_PAD_LEFT);
    }
    return $out;
}

/**
 * ขยายช่วง S/N เช่น BS25080486 - BS25080515
 *
 * @param string $snField
 * @return string[]
 */
function expand_sn_field($snField)
{
    $snField = trim($snField);
    if ($snField === '' || $snField === '-') {
        return [];
    }
    $codes = [];
    foreach (preg_split('/\s*,\s*/', $snField) as $part) {
        $part = trim($part);
        if ($part === '' || $part === '-') {
            continue;
        }
        if (preg_match('/^(.+?)\s*-\s*(.+)$/', $part, $m)) {
            $from = strtoupper(trim($m[1]));
            $to = strtoupper(trim($m[2]));
            if (preg_match('/^[A-Z]{1,3}\d+$/', $from) && preg_match('/^[A-Z]{1,3}\d+$/', $to)) {
                foreach (expand_serial_range($from, $to) as $c) {
                    $codes[$c] = true;
                }
                continue;
            }
            if (preg_match('/^D\d+$/', $from) && preg_match('/^D\d+$/', $to)) {
                foreach (expand_serial_range($from, $to) as $c) {
                    $codes[$c] = true;
                }
                continue;
            }
            if (preg_match('/^SC\d+$/', $from) && preg_match('/^SC\d+$/', $to)) {
                foreach (expand_serial_range($from, $to) as $c) {
                    $codes[$c] = true;
                }
                continue;
            }
        }
        foreach (extract_serial_codes($part) as $c) {
            $codes[$c] = true;
        }
    }
    return array_keys($codes);
}

/**
 * ดึงและขยายช่วงรหัสจากข้อความ (ใช้กับคอลัมน์ S/N หรือหมายเหตุ)
 *
 * @param string $text
 * @return string[]
 */
function extract_codes_from_text($text)
{
    $codes = [];
    $text = strtoupper($text);
    if (preg_match_all('/([A-Z]{1,3}\d{5,12}|SC\d{9,12}|D\d{5,8}|AA\d{10,14})\s*-\s*([A-Z]{1,3}\d{5,12}|SC\d{9,12}|D\d{5,8}|AA\d{10,14})/', $text, $ranges, PREG_SET_ORDER)) {
        foreach ($ranges as $rg) {
            foreach (expand_serial_range($rg[1], $rg[2]) as $c) {
                $codes[$c] = true;
            }
        }
    }
    foreach (extract_serial_codes($text) as $c) {
        $codes[$c] = true;
    }
    return array_keys($codes);
}

/**
 * รวม S/N จากคอลัมน์ + หมายเหตุ
 *
 * @param string $snField
 * @param string $remark
 * @return string[]
 */
function collect_asset_codes($snField, $remark)
{
    $codes = [];
    foreach (expand_sn_field($snField) as $c) {
        $codes[$c] = true;
    }
    foreach (extract_codes_from_text($remark) as $c) {
        $codes[$c] = true;
    }
    $list = array_keys($codes);
    sort($list);
    return $list;
}

// ─ update classification ─────────────────────────────────────────────────────

/**
 * จำแนกประเภทอัปเดตจากหมายเหตุ
 *
 * @param string $remark
 * @return string
 */
function classify_update_type($remark)
{
    if (preg_match('/\bfw\b|firmware|exp\.?|exp ver|image up|new image|ver\d|v\d+\.\d/i', $remark)) {
        return 'firmware';
    }
    if (preg_match('/เปลี่ยน|ใช้|บอร์ด|สาย|sw\b|chip|กล้อง|จอ|กาว|connector|ถ่าน|transistor|ic |boost|display|board|pi |hub|lot\.|register|printer|feelttek|battery|ground|uv |สกรีน|โมดูล|relay|reset|on-off|acrylic|logitech|ide |hdmi|magnetic|idc|สวิตช์|รุ่นใหม่/i', $remark)) {
        return 'hardware';
    }
    if (preg_match('/fw|image|ver/i', $remark)) {
        return 'firmware';
    }
    return 'other';
}

/**
 * แยก component_name / new_value จากหมายเหตุ
 *
 * @param string $remark
 * @param string $type
 * @return array{0:?string,1:?string,2:?string}
 */
function parse_remark_fields($remark, $type)
{
    $comp = null;
    $old = null;
    $new = null;

    if ($type === 'firmware') {
        if (preg_match('/FW\.?\s*([\d.]+[a-z]?)/i', $remark, $m)) {
            $new = $m[1];
        } elseif (preg_match('/EXP\.?\s*v?([\d.]+)/i', $remark, $m)) {
            $new = $m[1];
        } elseif (preg_match('/([\d.]+[a-z])\s*$/i', $remark, $m)) {
            $new = $m[1];
        } elseif (preg_match('/Ver\s*([\d.]+)/i', $remark, $m)) {
            $new = $m[1];
        } elseif (preg_match('/v([\d.]+)/i', $remark, $m)) {
            $new = $m[1];
        }
    }

    if ($type === 'hardware') {
        if (preg_match('/เปลี่ยน\s+(.+?)\s*:\s*(\S+)/u', $remark, $m)) {
            $comp = trim($m[1]);
            $new = trim($m[2]);
        } elseif (preg_match('/เปลี่ยน\s+(.+)/u', $remark, $m)) {
            $comp = trim($m[1]);
        } elseif (preg_match('/ใช้\s+(.+)/u', $remark, $m)) {
            $comp = trim($m[1]);
        } elseif (preg_match('/(IC\s+\w+|SDB\d+|Pi\s*\d?|Main\s*Board|Display|Lot\.\S+)/i', $remark, $m)) {
            $comp = trim($m[1]);
        }
    }

    return [$comp, $old, $new];
}

/**
 * แปลง datetime จากระบบเก่าเป็น Y-m-d H:i:s
 *
 * @param string $dt
 * @return string
 */
function normalize_datetime($dt)
{
    if (preg_match('/^(\d{2}):(\d{2}):(\d{2})\s*\/\s*(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
        return sprintf('%s-%s-%s %s:%s:%s', $m[4], $m[5], $m[6], $m[1], $m[2], $m[3]);
    }
    return $dt;
}

// ─ HTML / text parsers ───────────────────────────────────────────────────────

/**
 * แยกแถวจาก HTML ของ historyFG.php
 *
 * @param string $html
 * @return array<int,array<string,string>>
 */
function parse_history_html($html)
{
    $rows = [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    $trs = $xpath->query("//table[contains(@class,'tebleHistoryFG')]//tr");
    if (!$trs) {
        return $rows;
    }
    foreach ($trs as $tr) {
        $tds = $tr->getElementsByTagName('td');
        if ($tds->length < 5) {
            continue;
        }
        $dt = trim($tds->item(0)->textContent);
        if (!preg_match('/\d{2}:\d{2}:\d{2}\s*\/\s*\d{4}-\d{2}-\d{2}/', $dt)) {
            continue;
        }
        $product = trim($tds->item(1)->textContent);
        $sn = trim($tds->item(3)->textContent);
        $remark = trim($tds->item(4)->textContent);
        $input = $tds->item(4)->getElementsByTagName('input');
        if ($input->length > 0 && $input->item(0)->hasAttribute('value')) {
            $remark = trim($input->item(0)->getAttribute('value'));
        }
        $remark = preg_replace('/\s+/u', ' ', $remark);
        if ($remark === '' || $remark === '-') {
            continue;
        }
        $rows[] = [
            'datetime' => $dt,
            'product' => $product,
            'sn' => $sn,
            'remark' => $remark,
        ];
    }
    return $rows;
}

/**
 * แยกแถวจากข้อความ plain (export PDF / txt)
 *
 * @param string $text
 * @return array<int,array<string,string>>
 */
function parse_production_text($text)
{
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, 'เวลา-วันที่') !== false) {
            continue;
        }
        if (!preg_match('/^(\d{2}:\d{2}:\d{2}\s*\/\s*\d{4}-\d{2}-\d{2})/', $line, $dm)) {
            continue;
        }
        $cols = preg_split("/\t+/", $line);
        if (count($cols) >= 5) {
            $remark = trim($cols[4]);
            $sn = trim($cols[3]);
            $product = trim($cols[1]);
            $dt = trim($cols[0]);
        } else {
            continue;
        }
        $remark = preg_replace('/\s+/u', ' ', $remark);
        if ($remark === '' || $remark === '-' || $remark === 'แก้ไข') {
            continue;
        }
        $rows[] = [
            'datetime' => $dt,
            'product' => $product,
            'sn' => $sn,
            'remark' => $remark,
        ];
    }
    return $rows;
}

// ─ CLI args ──────────────────────────────────────────────────────────────────

$source = 'url';
$sourcePath = $DEFAULT_URL;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--url') {
        $source = 'url';
        $sourcePath = isset($argv[$i + 1]) && strpos($argv[$i + 1], '--') !== 0 ? $argv[++$i] : $DEFAULT_URL;
    } elseif ($argv[$i] === '--html') {
        $source = 'html';
        $sourcePath = $argv[++$i] ?? '';
    } elseif ($argv[$i] === '--pdf') {
        $source = 'pdf';
        $sourcePath = $argv[++$i] ?? $DEFAULT_PDF;
    } elseif ($argv[$i] === '--out') {
        $OUT_CSV = $argv[++$i] ?? $OUT_CSV;
    }
}

// ─ load source ───────────────────────────────────────────────────────────────

$srcRows = [];
if ($source === 'url') {
    $html = @file_get_contents($sourcePath);
    if ($html === false) {
        fwrite(STDERR, "ดึง URL ไม่ได้: $sourcePath\n");
        exit(1);
    }
    $srcRows = parse_history_html($html);
} elseif ($source === 'html') {
    if (!is_readable($sourcePath)) {
        fwrite(STDERR, "ไม่พบไฟล์ HTML: $sourcePath\n");
        exit(1);
    }
    $srcRows = parse_history_html(file_get_contents($sourcePath));
} else {
    if (!is_readable($sourcePath)) {
        fwrite(STDERR, "ไม่พบไฟล์ PDF: $sourcePath\n");
        exit(1);
    }
    $txtPath = preg_replace('/\.pdf$/i', '.txt', $sourcePath);
    $text = is_readable($txtPath) ? file_get_contents($txtPath) : '';
    if ($text === '') {
        fwrite(STDERR, "PDF ต้องมีไฟล์ .txt คู่กัน หรือใช้ --url / --html แทน\n");
        exit(1);
    }
    $srcRows = parse_production_text($text);
}

if (!$srcRows) {
    fwrite(STDERR, "ไม่พบแถวที่มีหมายเหตุ\n");
    exit(1);
}

// ─ map รุ่นจากฐานข้อมูล production (S/N → products.name) ───────────────────

/**
 * ดึงชื่อรุ่นจาก assets + products ตามรหัสเครื่อง
 *
 * @param string[] $codes รายการ asset_code
 * @return array<string,string> asset_code => model name
 */
function lookup_models_by_codes(array $codes)
{
    $map = [];
    $codes = array_values(array_unique(array_filter($codes)));
    if (!$codes) {
        return $map;
    }
    $chunkSize = 400;
    for ($i = 0; $i < count($codes); $i += $chunkSize) {
        $chunk = array_slice($codes, $i, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));
        $res = qr("SELECT a.asset_code, p.name
                   FROM assets a JOIN products p ON p.id=a.product_id
                   WHERE a.asset_code IN ($ph)", $types, $chunk);
        while ($r = $res->fetch_assoc()) {
            $map[strtoupper($r['asset_code'])] = $r['name'];
        }
    }
    return $map;
}

$allCodes = [];
foreach ($srcRows as $row) {
    foreach (collect_asset_codes($row['sn'], $row['remark']) as $c) {
        $allCodes[] = $c;
    }
}
$modelMap = lookup_models_by_codes($allCodes);
echo 'พบรุ่นในระบบ: ' . count($modelMap) . ' / ' . count(array_unique($allCodes)) . " รหัส\n";

// ─ write CSV ─────────────────────────────────────────────────────────────────

$importDir = dirname($OUT_CSV);
if (!is_dir($importDir)) {
    mkdir($importDir, 0755, true);
}

$fh = fopen($OUT_CSV, 'w');
if (!$fh) {
    fwrite(STDERR, "เขียนไฟล์ไม่ได้: $OUT_CSV\n");
    exit(1);
}

fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['asset_code', 'updated_at', 'update_type', 'component_name', 'old_value', 'new_value', 'detail', 'made_by', 'model', 'source_sn']);

$totalRows = 0;
$totalAssets = 0;
$skippedNoSn = 0;
$skipLog = [];

foreach ($srcRows as $row) {
    $codes = collect_asset_codes($row['sn'], $row['remark']);
    if (!$codes) {
        $skippedNoSn++;
        $skipLog[] = $row;
        continue;
    }
    $type = classify_update_type($row['remark']);
    list($comp, $old, $new) = parse_remark_fields($row['remark'], $type);
    $updatedAt = normalize_datetime($row['datetime']);

    foreach ($codes as $code) {
        $model = isset($modelMap[strtoupper($code)]) ? $modelMap[strtoupper($code)] : '';
        fputcsv($fh, [
            $code,
            $updatedAt,
            $type,
            $comp,
            $old,
            $new,
            $row['remark'],
            $MADE_BY,
            $model,
            $row['sn'],
        ]);
        $totalAssets++;
    }
    $totalRows++;
}

fclose($fh);

$logPath = $importDir . '/update_logs_skipped_no_sn.csv';
if ($skipLog) {
    $lf = fopen($logPath, 'w');
    fwrite($lf, "\xEF\xBB\xBF");
    fputcsv($lf, ['datetime', 'product', 'sn', 'remark']);
    foreach ($skipLog as $s) {
        fputcsv($lf, [$s['datetime'], $s['product'], $s['sn'], $s['remark']]);
    }
    fclose($lf);
}

echo "แหล่ง: $source ($sourcePath)\n";
echo "แถวหมายเหตุ: $totalRows\n";
echo "รายการ import (ต่อเครื่อง): $totalAssets\n";
echo "ข้าม (ไม่มี S/N): $skippedNoSn\n";
echo "CSV: $OUT_CSV\n";
if ($skipLog) {
    echo "Log ข้าม: $logPath\n";
}
