<?php
/**
 * includes/installation_history.php — ประวัติติดตั้ง/ขายจากระบบ installation เดิม (MS Access)
 * ─────────────────────────────────────────────────────────────────────────────
 * ระบบ installation (D:\AppServ\www\installation\installation.mdb บนเครื่อง LAN) บันทึกงานติดตั้ง
 * ปี 2010 – พ.ค. 2023 แล้วเลิกใช้ ข้อมูลไม่เพิ่มอีก — server ของเราเปิดไฟล์ Access บน LAN ไม่ได้
 * จึงนำเข้าครั้งเดียวเป็นตาราง installation_history ของเราเอง (อ่านอย่างเดียว ไม่เขียนกลับระบบเดิม)
 *
 *   1. database/tools/export_installation_history.ps1  อ่าน .mdb → CSV (UTF-8)
 *   2. installation_history.php (หลังบ้าน) หรือ database/tools/import_installation_history.php  นำเข้า CSV
 *
 * ใช้ทำอะไร
 *   • บอก "ขาย/ติดตั้งให้ใคร เมื่อไหร่ invoice อะไร" บนหน้าเครื่อง (ส่วนใหญ่เป็นเครื่องที่ขายไปแล้ว)
 *   • เป็นหลักฐานระดับเดียวกับประวัติส่งมอบ: เปลี่ยนเครื่องที่ยังเป็น "ใหม่" เป็น "ขายแล้ว" ได้
 *     เฉพาะเมื่อไม่มีหลักฐานว่ากลับเข้าคลังหลังวันติดตั้ง (ดู installation_history_status_hint)
 *
 * กับดักของข้อมูลต้นทาง (ช่อง sn_serial พิมพ์อิสระ)
 *   • 5,000+ แถวเป็นค่าเติม "-", "1", "2" · บางแถวเป็นรหัสล็อตแทน serial
 *   • บางช่องมีหลาย serial คั่นด้วย , และมีหมายเหตุติดมา เช่น "SC320051135ซื้อเมื่อ16/6/20",
 *     "(ซื้อเพิ่ม6/9/2021)RF8MA20Q8WJ" → แยก serial ออก และเก็บวันที่ซื้อจากหมายเหตุไว้
 *   • serial เดียวโผล่หลายงาน (ชุด demo ที่หมุนไปหลายที่ / รหัสล็อต) → แสดงทุกงาน แต่ไม่ใช้ตัดสถานะ
 * ─────────────────────────────────────────────────────────────────────────────
 */

/** @var string site_settings: วันเวลาที่นำเข้าล่าสุด + สถิติ (JSON) */
const INSTALLATION_HISTORY_META_KEY = 'installation_history_import_meta';

/**
 * สร้างตาราง
 *
 * @return void
 */
function ensure_installation_history_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    db()->query(
        "CREATE TABLE IF NOT EXISTS installation_history (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            serial VARCHAR(64) NOT NULL,
            serial_raw VARCHAR(255) NOT NULL DEFAULT '',
            sale_date DATE NULL,
            install_id INT NOT NULL DEFAULT 0,
            install_date DATE NULL,
            product VARCHAR(100) NOT NULL DEFAULT '',
            model VARCHAR(100) NOT NULL DEFAULT '',
            invoice VARCHAR(60) NOT NULL DEFAULT '',
            trainer VARCHAR(100) NOT NULL DEFAULT '',
            security_company VARCHAR(255) NOT NULL DEFAULT '',
            employer VARCHAR(255) NOT NULL DEFAULT '',
            end_user VARCHAR(255) NOT NULL DEFAULT '',
            area VARCHAR(255) NOT NULL DEFAULT '',
            job_status VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_serial (serial),
            KEY idx_install (install_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // collation ต้องตรงกับ assets (utf8mb4_unicode_ci) ไม่งั้น join เทียบ serial แล้ว error
    $c = db()->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'installation_history'");
    $row = $c ? $c->fetch_row() : null;
    if ($row && $row[0] !== 'utf8mb4_unicode_ci') {
        db()->query('ALTER TABLE installation_history CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }
}

/**
 * แปลงวันที่ในหมายเหตุ (d/m/y) — ปีสองหลักตั้งแต่ 50 ขึ้นไปเป็น พ.ศ. (62 = 2562) ต่ำกว่านั้นเป็น ค.ศ. (20 = 2020)
 *
 * @param string $d
 * @param string $m
 * @param string $y
 * @return string|null Y-m-d
 */
function installation_history_parse_note_date($d, $m, $y)
{
    $d = (int) $d;
    $m = (int) $m;
    $y = (int) $y;
    if ($y < 100) {
        $y = $y >= 50 ? 2500 + $y - 543 : 2000 + $y;
    } elseif ($y > 2400) {
        $y -= 543;
    }
    if ($y < 2005 || $y > 2030 || !checkdate($m, $d, $y)) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * แยก serial จริงออกจากช่อง sn_serial ที่พิมพ์อิสระ
 *
 * @param string $raw
 * @return array{serials:array<int,string>, note_date:?string}
 */
function installation_history_extract_serials($raw)
{
    $s = trim((string) $raw);
    $out = ['serials' => [], 'note_date' => null];
    if ($s === '') {
        return $out;
    }
    // วันที่ซื้อจากหมายเหตุ: "ซื้อเมื่อ16/6/20" "ซื้อเพิ่ม6/9/2021" "(15/5/21)"
    if (preg_match('~ซื้อ[\x{0E00}-\x{0E7F}]*\s*(\d{1,2})/(\d{1,2})/(\d{2,4})~u', $s, $m)
        || preg_match('~\((\d{1,2})/(\d{1,2})/(\d{2,4})\)~u', $s, $m)) {
        $out['note_date'] = installation_history_parse_note_date($m[1], $m[2], $m[3]);
    }
    $s = preg_replace('~S/N~i', ' ', $s);
    $s = preg_replace('~\([^)]*\)~u', ' ', $s);                 // หมายเหตุในวงเล็บ
    $s = preg_replace('~[\x{0E00}-\x{0E7F}]+[\d/.]*~u', ' ', $s); // ข้อความไทย + วันที่ที่ติดท้าย
    $s = str_replace(['<<', '>>', ':'], ' ', $s);
    foreach (preg_split('~[,;\s]+~', strtoupper($s)) as $t) {
        $t = trim($t, "-_. \t");
        if (strlen($t) < 6 || strlen($t) > 64 || strpos($t, '/') !== false) {
            continue; // สั้นเกิน = ค่าเติม · มี / = เลข IMEI โทรศัพท์หรือวันที่ ไม่ใช่เครื่องเรา
        }
        if (!preg_match('~^[A-Z0-9][A-Z0-9._-]*$~', $t) || !preg_match('~\d~', $t) || preg_match('~^X[X-]*$~', $t)) {
            continue;
        }
        $out['serials'][$t] = true;
    }
    // array_keys แปลง serial ที่เป็นตัวเลขล้วนเป็น int — คืนเป็นข้อความเสมอ
    $out['serials'] = array_map('strval', array_keys($out['serials']));
    return $out;
}

/**
 * นำเข้าจาก CSV ที่ export_installation_history.ps1 สร้าง — แทนที่ข้อมูลเดิมทั้งตาราง
 *
 * @param string $path
 * @return array{ok:bool, error:string, rows_read:int, rows_saved:int, jobs:int, skipped:int}
 */
function installation_history_import_csv($path)
{
    $stat = ['ok' => false, 'error' => '', 'rows_read' => 0, 'rows_saved' => 0, 'jobs' => 0, 'skipped' => 0];
    ensure_installation_history_schema();
    // รับได้ทั้ง .csv และ .csv.gz (ไฟล์บีบอัดจาก export_installation_history.ps1)
    $magic = (string) @file_get_contents($path, false, null, 0, 2);
    $fh = @fopen(($magic === "�" ? 'compress.zlib://' : '') . $path, 'rb');
    if (!$fh) {
        $stat['error'] = 'เปิดไฟล์ไม่ได้';
        return $stat;
    }
    // ข้าม BOM ที่ PowerShell ใส่ไว้ต้นไฟล์ — ถ้าปล่อยไว้ หัวคอลัมน์แรกจะอ่านเครื่องหมายคำพูดไม่ออก
    if (fread($fh, 3) !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $head = fgetcsv($fh);
    if (!$head) {
        $stat['error'] = 'ไฟล์ว่าง';
        return $stat;
    }
    $need = ['install_id', 'install_date', 'serial_raw', 'sn_product', 'sn_invoice', 'in_invoice', 'in_namesend',
        'in_security', 'in_company_sec', 'in_end_user', 'in_area', 'in_status', 'in_product', 'in_model'];
    $idx = array_flip(array_map('trim', $head));
    foreach ($need as $col) {
        if (!isset($idx[$col])) {
            fclose($fh);
            $stat['error'] = 'ไฟล์ไม่ใช่รูปแบบที่ export_installation_history.ps1 สร้าง (ไม่มีคอลัมน์ ' . $col . ')';
            return $stat;
        }
    }
    $cut = function ($v, $n) { return mb_substr(trim((string) $v), 0, $n); };
    $noPlaceholder = function ($v) { $v = trim((string) $v); return preg_match('~^[X\-\s]*$~i', $v) ? '' : $v; };

    $rows = [];
    $jobs = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($need)) {
            continue;
        }
        $stat['rows_read']++;
        $g = function ($k) use ($r, $idx) { return (string) ($r[$idx[$k]] ?? ''); };
        $ex = installation_history_extract_serials($g('serial_raw'));
        if (!$ex['serials']) {
            $stat['skipped']++;
            continue;
        }
        $installDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $g('install_date')) ? $g('install_date') : null;
        $invoice = $noPlaceholder($g('sn_invoice'));
        if ($invoice === '') {
            $invoice = $noPlaceholder($g('in_invoice'));
        }
        $jobs[(int) $g('install_id')] = true;
        foreach ($ex['serials'] as $sn) {
            $rows[] = [
                $sn, $cut($g('serial_raw'), 255), $ex['note_date'], (int) $g('install_id'), $installDate,
                $cut($g('sn_product') !== '' ? $g('sn_product') : $g('in_product'), 100), $cut($g('in_model'), 100),
                $cut($invoice, 60), $cut($g('in_namesend'), 100), $cut($g('in_security'), 255),
                $cut($g('in_company_sec'), 255), $cut($g('in_end_user'), 255), $cut($g('in_area'), 255),
                $cut($g('in_status'), 255),
            ];
        }
    }
    fclose($fh);
    if (!$rows) {
        $stat['error'] = 'ไม่พบ serial ที่ใช้ได้ในไฟล์';
        return $stat;
    }

    db()->begin_transaction();
    try {
        db()->query('DELETE FROM installation_history');
        foreach (array_chunk($rows, 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'));
            $params = [];
            foreach ($chunk as $c) {
                foreach ($c as $v) {
                    $params[] = $v;
                }
            }
            q("INSERT INTO installation_history (serial, serial_raw, sale_date, install_id, install_date, product, model,
                 invoice, trainer, security_company, employer, end_user, area, job_status) VALUES $ph",
              str_repeat('sssissssssssss', count($chunk)), $params);
        }
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollback();
        $stat['error'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        return $stat;
    }
    $stat['ok'] = true;
    $stat['rows_saved'] = count($rows);
    $stat['jobs'] = count($jobs);
    if (function_exists('set_setting')) {
        set_setting(INSTALLATION_HISTORY_META_KEY, json_encode($stat + ['imported_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE));
    }
    return $stat;
}

/**
 * มีข้อมูลนำเข้าแล้วหรือยัง (กันหน้าอื่น query ตารางที่ยังไม่มี)
 *
 * @return bool
 */
function installation_history_available()
{
    static $has = null;
    if ($has === null) {
        try {
            $r = db()->query("SHOW TABLES LIKE 'installation_history'");
            $has = $r && $r->num_rows > 0;
        } catch (\Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/**
 * id เครื่องในทะเบียนที่จับคู่กับประวัติติดตั้งได้
 *
 * แยกเป็นสอง query ที่ใช้ index (asset_code · factory_serial) — join ด้วย OR / UPPER() ทำให้
 * MySQL สแกนไขว้ทั้งสองตาราง (18k × 7k) จนหน้าเว็บหมดเวลา · collation เป็น _ci อยู่แล้วจึงไม่ต้อง UPPER
 *
 * @param string $status กรองสถานะเครื่อง ('' = ทุกสถานะ)
 * @return array<int,bool>
 */
function installation_history_matched_ids($status = '')
{
    $ids = [];
    if (!installation_history_available()) {
        return $ids;
    }
    $cond = $status !== '' ? ' AND a.status = ?' : '';
    foreach (['a.asset_code', 'a.factory_serial'] as $col) {
        $res = qr(
            "SELECT DISTINCT a.id FROM installation_history ih JOIN assets a ON $col = ih.serial
             WHERE $col <> ''" . $cond,
            $status !== '' ? 's' : '',
            $status !== '' ? [$status] : []
        );
        while ($r = $res->fetch_row()) {
            $ids[(int) $r[0]] = true;
        }
    }
    return $ids;
}

/**
 * ประวัติติดตั้งของเครื่องหลายเครื่อง จับคู่ด้วย asset_code และ factory_serial
 *
 * @param array<int,array<string,mixed>> $assets แถว assets (ต้องมี asset_code, factory_serial ถ้ามี)
 * @return array<string,array{jobs:int, date:?string, row:array<string,mixed>, rows:array<int,array<string,mixed>>}> key = asset_code ตัวใหญ่
 */
function installation_history_map(array $assets)
{
    $out = [];
    if (!$assets || !installation_history_available()) {
        return $out;
    }
    $want = [];   // serial → [asset keys]
    foreach ($assets as $a) {
        $key = strtoupper(trim((string) ($a['asset_code'] ?? '')));
        if ($key === '') {
            continue;
        }
        $want[$key][] = $key;
        $fs = strtoupper(trim((string) ($a['factory_serial'] ?? '')));
        if ($fs !== '' && $fs !== $key) {
            $want[$fs][] = $key;
        }
    }
    if (!$want) {
        return $out;
    }
    $serials = array_keys($want);
    foreach (array_chunk($serials, 500) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $res = qr("SELECT * FROM installation_history WHERE serial IN ($ph) ORDER BY COALESCE(sale_date, install_date) DESC, id DESC",
            str_repeat('s', count($chunk)), $chunk);
        while ($r = $res->fetch_assoc()) {
            foreach ($want[(string) $r['serial']] ?? [] as $key) {
                $out[$key]['rows'][] = $r;
            }
        }
    }
    foreach ($out as $key => $e) {
        $ids = [];
        foreach ($e['rows'] as $r) {
            $ids[(int) $r['install_id']] = true;
        }
        $first = $e['rows'][0];
        $out[$key] = [
            'jobs' => count($ids),
            'date' => $first['sale_date'] ?: $first['install_date'],
            'row'  => $first,
            'rows' => $e['rows'],
        ];
    }
    return $out;
}

/**
 * ตรวจว่าใช้ประวัติติดตั้งตัดสถานะเป็น "ขายแล้ว" ได้ไหม พร้อมเหตุผลที่ระบุได้ว่าติดตรงไหน
 *
 * ต้องครบทุกข้อ:
 *   • serial โผล่งานเดียว — หลายงาน = ชุด demo หมุนเวียน หรือรหัสล็อต เชื่อไม่ได้
 *   • รู้วันที่ติดตั้ง/ซื้อ และเครื่องผลิตไม่หลังวันนั้นเกิน 31 วัน — ผลิตทีหลังมาก = serial ซ้ำกับของเก่า
 *   • หลังวันนั้นไม่มีหลักฐานว่ากลับเข้าคลังจริง: สแกนเจอในรอบนับ หรือแถวเข้าคลังที่เป็นเหตุการณ์จริง
 *     (ตั้งเป็นใหม่ด้วยมือ · รับคืนจากระบบเช่า) — ไม่นับแถวที่เครื่องมือเคลียร์ข้อมูลเขียนไว้
 *     (เคลียร์ / ย้อนกลับการเคลียร์ / ซิงก์สถานะทั้งระบบ / นำเข้าจากระบบเดิม / นับสต็อก) เพราะเป็นการแก้ข้อมูล ไม่ใช่ของกลับเข้าคลัง
 *
 * ผู้เรียกต้องเช็คเองว่าเครื่องไม่อยู่ในระบบเช่า และไม่มีใบเบิกขาย (หลักฐานพวกนั้นชนะ)
 *
 * @param array<string,mixed>      $asset แถว assets (id, produced_at)
 * @param array<string,mixed>|null $entry จาก installation_history_map()
 * @return array{ok:bool, why:string, target:string, reason:string}
 */
function installation_history_check(array $asset, $entry)
{
    $no = function ($why) { return ['ok' => false, 'why' => $why, 'target' => '', 'reason' => '']; };
    if (!$entry) {
        return $no('ไม่มีประวัติติดตั้ง');
    }
    if ((int) $entry['jobs'] !== 1) {
        return $no('S/N พบ ' . (int) $entry['jobs'] . ' งาน (ชุด demo / รหัสล็อต) — ไม่เปลี่ยน');
    }
    if (empty($entry['date'])) {
        return $no('ไม่ทราบวันที่ติดตั้ง — ไม่เปลี่ยน');
    }
    $date = (string) $entry['date'];
    $id = (int) ($asset['id'] ?? 0);
    if (!array_key_exists('produced_at', $asset) && $id > 0) {
        $p = qr('SELECT produced_at FROM assets WHERE id = ? LIMIT 1', 'i', [$id])->fetch_row();
        $asset['produced_at'] = $p ? $p[0] : '';
    }
    $produced = substr((string) ($asset['produced_at'] ?? ''), 0, 10);
    // วันผลิตของข้อมูลเก่าเป็นค่าประมาณกลางเดือน (15 · 17) เครื่องที่ติดตั้งต้นเดือนเดียวกันจึงดูเหมือน
    // "ผลิตหลังติดตั้ง" — เผื่อไว้ 31 วัน ถ้าผลิตหลังวันติดตั้งเกินนั้นค่อยถือว่าเป็น serial ซ้ำกับของเก่า
    if ($produced !== '' && $produced > date('Y-m-d', strtotime($date . ' +31 days'))) {
        return $no('ผลิต ' . dthai($produced) . ' หลังวันติดตั้ง ' . dthai($date) . ' เกิน 1 เดือน (S/N อาจซ้ำกับของเก่า) — ไม่เปลี่ยน');
    }
    if ($id > 0) {
        $in = qr(
            "SELECT moved_at, reason FROM stock_movements
             WHERE asset_id = ? AND direction = 'in' AND moved_at > ?
               AND reason NOT LIKE '%นำเข้าจากระบบเดิม%'
               AND reason NOT LIKE 'เคลียร์%'
               AND reason NOT LIKE 'ย้อนกลับการเคลียร์%'
               AND reason NOT LIKE 'ซิงก์สถานะทั้งระบบ%'
               AND reason NOT LIKE '%นับสต็อก (สแกน)%'
             ORDER BY moved_at DESC LIMIT 1",
            'is',
            [$id, $date . ' 23:59:59']
        )->fetch_assoc();
        if ($in) {
            return $no('กลับเข้าคลังหลังวันติดตั้ง: ' . mb_substr((string) $in['reason'], 0, 80) . ' (' . dthai(substr((string) $in['moved_at'], 0, 10)) . ') — ไม่เปลี่ยน');
        }
        try {
            $sc = db()->query("SHOW TABLES LIKE 'stock_scan_items'");
            if ($sc && $sc->num_rows > 0) {
                $scan = qr(
                    "SELECT i.scanned_at, s.id FROM stock_scan_items i JOIN stock_scan_sessions s ON s.id = i.session_id
                     WHERE i.asset_id = ? AND i.result = 'ok' AND s.status IN ('open','applied') AND i.scanned_at > ?
                     ORDER BY i.scanned_at DESC LIMIT 1",
                    'is',
                    [$id, $date . ' 23:59:59']
                )->fetch_row();
                if ($scan) {
                    return $no('สแกนเจอในคลังรอบนับ #' . (int) $scan[1] . ' (' . dthai(substr((string) $scan[0], 0, 10)) . ') หลังวันติดตั้ง — ไม่เปลี่ยน');
                }
            }
        } catch (\Throwable $e) {
            return $no('ตรวจประวัติการนับสต็อกไม่ได้ — ไม่เปลี่ยน');
        }
    }
    $r = $entry['row'];
    $who = trim((string) ($r['end_user'] ?: ($r['employer'] ?: $r['security_company'])));
    $reason = 'ประวัติติดตั้งระบบเดิม ' . $date . ($who !== '' ? ' · ' . mb_substr($who, 0, 60) : '')
        . ((string) $r['invoice'] !== '' ? ' · ' . $r['invoice'] : '');
    return ['ok' => true, 'why' => $reason, 'target' => 'sold', 'reason' => $reason];
}

/**
 * ใช้ประวัติติดตั้งตัดสถานะเป็น "ขายแล้ว" ได้ไหม (ดูเงื่อนไขที่ installation_history_check)
 *
 * @param array<string,mixed>      $asset
 * @param array<string,mixed>|null $entry
 * @return array{target:string, reason:string}|null
 */
function installation_history_status_hint(array $asset, $entry)
{
    $c = installation_history_check($asset, $entry);
    return $c['ok'] ? ['target' => $c['target'], 'reason' => $c['reason']] : null;
}

/**
 * บล็อกประวัติติดตั้งสำหรับการ์ด "การเบิกใช้งานขาย" บนหน้าเครื่อง
 *
 * @param string $assetCode
 * @param string $factorySerial
 * @return string HTML ('' ถ้าไม่มี)
 */
function installation_history_html($assetCode, $factorySerial = '')
{
    $map = installation_history_map([['asset_code' => $assetCode, 'factory_serial' => $factorySerial]]);
    $e = $map[strtoupper(trim((string) $assetCode))] ?? null;
    if (!$e) {
        return '';
    }
    $out = '<div class="asset-sales-block asset-sales-setup-block">';
    $out .= '<div class="asset-sales-block-title">ประวัติติดตั้ง <span class="muted">(ระบบ installation เดิม · 2010–2023)</span></div>';
    if ($e['jobs'] > 1) {
        $out .= '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' '
            . h('S/N นี้พบใน ' . $e['jobs'] . ' งาน — อาจเป็นชุด demo ที่หมุนไปหลายที่ หรือรหัสล็อตที่กรอกแทน serial') . '</p>';
    }
    foreach (array_slice($e['rows'], 0, 10) as $r) {
        $cust = trim((string) $r['end_user']) !== '' ? (string) $r['end_user'] : (string) $r['employer'];
        $date = $r['sale_date'] ?: $r['install_date'];
        $out .= '<div class="asset-sales-setup-row">';
        $out .= '<p class="asset-sales-summary"><span class="asset-sales-badge asset-sales-badge-sale">ติดตั้ง</span>';
        if ($cust !== '') {
            $out .= ' <b class="asset-sales-cust">' . h($cust) . '</b>';
        }
        if ((string) $r['area'] !== '') {
            $out .= ' <span class="asset-sales-dept">' . h((string) $r['area']) . '</span>';
        }
        if ($date) {
            $out .= ' <span class="asset-sales-sep">·</span> <span class="asset-sales-when">' . h(dthai((string) $date)) . '</span>';
        }
        if ((string) $r['invoice'] !== '') {
            $out .= ' <span class="asset-sales-sep">·</span> Invoice <b class="asset-sales-setup-id">' . h((string) $r['invoice']) . '</b>';
        }
        $out .= '</p>';
        $rows = '';
        $add = function ($label, $val) use (&$rows) {
            if (trim((string) $val) !== '') {
                $rows .= stockparts_withdraw_dl_row($label, stockparts_fmt_field((string) $val));
            }
        };
        $add('บริษัท รปภ.', $r['security_company']);
        if (trim((string) $r['end_user']) !== '') {
            $add('ผู้ว่าจ้าง', $r['employer']);
        }
        $add('สินค้า', trim($r['product'] . ' ' . $r['model']));
        $add('ผู้ติดตั้ง', $r['trainer']);
        $add('สถานะงาน', $r['job_status']);
        if ($r['sale_date'] && $r['install_date'] && $r['sale_date'] !== $r['install_date']) {
            $add('วันที่ของงานที่บันทึกไว้', dthai((string) $r['install_date']));
        }
        if (strtoupper(trim((string) $r['serial_raw'])) !== (string) $r['serial']) {
            $add('S/N ที่บันทึกไว้', $r['serial_raw']);
        }
        if ($rows !== '') {
            $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">' . $rows . '</dl>';
        }
        $out .= '</div>';
    }
    return $out . '</div>';
}

/**
 * เครื่องที่ยังเป็น "ใหม่" หรือ "ไม่มีสถานะ" ที่มีประวัติติดตั้ง พร้อมผลว่าจะเปลี่ยนเป็นขายแล้วไหม เพราะอะไร
 *
 * @return array<int,array<string,mixed>> แถว assets + will(bool) · why(string)
 */
function installation_history_candidates()
{
    $out = [];
    $ids = array_keys(installation_history_matched_ids('new') + installation_history_matched_ids('unknown'));
    if (!$ids) {
        return $out;
    }
    $rows = [];
    $res = qr('SELECT a.id, a.asset_code, a.factory_serial, a.status, a.produced_at, p.name AS pname
               FROM assets a JOIN products p ON p.id = a.product_id
               WHERE a.id IN (' . implode(',', $ids) . ') ORDER BY p.name, a.asset_code');
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $map = installation_history_map($rows);
    $sale = function_exists('asset_stockparts_sale_status_by_sn') ? asset_stockparts_sale_status_by_sn(array_column($rows, 'asset_code')) : [];
    $lease = function_exists('asset_leasing_status_by_assets') ? asset_leasing_status_by_assets($rows) : [];
    foreach ($rows as $r) {
        $key = strtoupper((string) $r['asset_code']);
        $e = $map[$key] ?? null;
        $will = false;
        if (!empty($lease[$key]['found'])) {
            $why = 'อยู่ในระบบเช่า — ใช้สถานะตามระบบเช่า';
        } elseif (!empty($sale[$r['asset_code']]['sold'])) {
            $why = 'มีใบเบิก/ประวัติส่งมอบในระบบสต็อก — ใช้หลักฐานนั้น';
        } else {
            $chk = installation_history_check($r, $e);
            $will = $chk['ok'];
            $why = $chk['why'];
        }
        $out[] = $r + ['will' => $will, 'why' => $why];
    }
    // เครื่องที่จะเปลี่ยนขึ้นก่อน
    usort($out, function ($a, $b) {
        return ($a['will'] === $b['will']) ? 0 : ($a['will'] ? -1 : 1);
    });
    return $out;
}

/**
 * เปลี่ยนเป็น "ขายแล้ว" ตามประวัติติดตั้ง — เฉพาะเครื่องที่ installation_history_candidates() ตัดสินว่าเปลี่ยนได้
 *
 * เครื่องใหม่ผ่านตัว sync สถานะ (กฎชุดเดียวกับทุกคืน) · เครื่องไม่มีสถานะบันทึกแบบเดียวกับหน้าติดตามเครื่องไม่มีสถานะ
 * ทุกแถวลง stock_movements รูปแบบ "(จาก → sold)" จึงย้อนกลับได้จากหน้าเคลียร์เครื่องค้าง
 *
 * @return array{new:int, unknown:int}
 */
function installation_history_apply()
{
    $done = ['new' => 0, 'unknown' => 0];
    $newRows = [];
    $actor = function_exists('actor_name') ? (actor_name() ?: 'system') : 'system';
    foreach (installation_history_candidates() as $c) {
        if (!$c['will']) {
            continue;
        }
        if ($c['status'] === 'new') {
            $newRows[] = $c;
            continue;
        }
        $upd = q("UPDATE assets SET status = 'sold' WHERE id = ? AND status = 'unknown'", 'i', [(int) $c['id']]);
        if ($upd->affected_rows < 1) {
            continue;
        }
        q(
            'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
            'isss',
            [(int) $c['id'], 'out', 'ติดตามเครื่องไม่มีสถานะ: ตามหลักฐาน: ' . mb_substr($c['why'], 0, 180) . ' (unknown → sold)', $actor]
        );
        $done['unknown']++;
    }
    if ($newRows && function_exists('asset_status_sync_batch')) {
        $st = asset_status_sync_batch($newRows, true);
        $done['new'] = (int) $st['changed'];
    }
    return $done;
}
