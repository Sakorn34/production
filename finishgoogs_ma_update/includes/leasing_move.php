<?php
/**
 * includes/leasing_move.php — ย้ายเครื่องผลิตใหม่ไปลงทะเบียนในระบบเช่า (biton_leasing.tbl_product)
 *
 * เขียนลงฐานระบบเช่าแบบเดียวกับฟอร์มเพิ่มสินค้าของระบบเช่าเอง (rent/save_product.php) ทุกคอลัมน์:
 *   pro_date = วันนี้ · pro_name = ชื่อรุ่นในระบบเช่า · pro_sn = รหัสเครื่อง · pro_status = 'finished goods' (คลังพร้อมเช่า)
 *   pro_user_add = คนที่กดย้าย · pro_remarks = '' · pro_asset_dstart/dend = '0000-00-00' · pro_bundle = ''
 * ไม่แก้โค้ดระบบเช่า · pro_sn เป็น unique ในฐานเขาอยู่แล้ว กันลงซ้ำได้อีกชั้น
 *
 * ชื่อรุ่นในระบบเช่าต้องตั้งเองที่ระบบหลังบ้าน (products.leasing_name) — ไม่เดาเอง เพราะเขียนชื่อผิดลงฐานคนอื่น
 * แก้ยากกว่าไม่เขียน · หน้าตั้งค่ามีค่าแนะนำจากเครื่องของรุ่นนั้นที่อยู่ในระบบเช่าแล้ว
 * products.leasing_auto = ลงทะเบียนผลิตใหม่แล้วเสนอให้ย้ายด้วย (ปิดเป็นค่าเริ่มต้น · ถามยืนยันทุกครั้ง)
 */

/** @var string[] รุ่นที่ระบบเช่าบังคับข้อมูลที่เรายังไม่มี (IMEI) — ย้ายจากระบบเราไม่ได้ */
const LEASING_MOVE_BLOCKED = ['Portable Client'];
/** @var int ความยาวสูงสุดของ pro_sn ในฐานระบบเช่า */
const LEASING_SN_MAX = 20;

/**
 * คอลัมน์ตั้งค่าการย้ายในตาราง products
 *
 * @return void
 */
function ensure_leasing_move_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $cols = [];
    $r = db()->query('SHOW COLUMNS FROM products');
    while ($c = $r->fetch_assoc()) {
        $cols[$c['Field']] = true;
    }
    if (!isset($cols['leasing_name'])) {
        db()->query("ALTER TABLE products ADD COLUMN leasing_name VARCHAR(50) NULL DEFAULT NULL");
    }
    if (!isset($cols['leasing_auto'])) {
        db()->query("ALTER TABLE products ADD COLUMN leasing_auto TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/**
 * ชื่อรุ่นทั้งหมดในระบบเช่า (tbl_product_name)
 *
 * @return array<string,string> pron_name => คำอธิบาย
 */
function leasing_model_names(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        return $cache;
    }
    $r = @$l->query('SELECT pron_name, pron_description FROM tbl_product_name ORDER BY pron_name');
    while ($r && ($x = $r->fetch_row())) {
        $cache[(string) $x[0]] = (string) $x[1];
    }
    return $cache;
}

/**
 * ชื่อรุ่นในระบบเช่าที่แนะนำ — ชื่อที่เครื่องรุ่นนี้ของเราใช้มากที่สุดในระบบเช่า
 *
 * @param int $productId
 * @return array{name:string, n:int}|null
 */
function leasing_suggest_name(int $productId): ?array
{
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        return null;
    }
    $codes = [];
    $res = qr("SELECT asset_code FROM assets WHERE product_id = ? ORDER BY id DESC LIMIT 400", 'i', [$productId]);
    while ($r = $res->fetch_row()) {
        $codes[] = "'" . $l->real_escape_string((string) $r[0]) . "'";
    }
    if (!$codes) {
        return null;
    }
    $r = @$l->query('SELECT pro_name, COUNT(*) n FROM tbl_product WHERE pro_sn IN (' . implode(',', $codes) . ') GROUP BY pro_name ORDER BY n DESC LIMIT 1');
    $x = $r ? $r->fetch_row() : null;
    return $x ? ['name' => (string) $x[0], 'n' => (int) $x[1]] : null;
}

/**
 * S/N ที่มีในระบบเช่าแล้ว (จากรายการที่ถาม)
 *
 * @param string[] $codes
 * @return array<string,string> รหัส(ตัวใหญ่) => สถานะในระบบเช่า
 */
function leasing_existing_sns(array $codes): array
{
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    $out = [];
    if (!$l || !$codes) {
        return $out;
    }
    foreach (array_chunk(array_values(array_unique($codes)), 500) as $chunk) {
        $in = implode(',', array_map(function ($c) use ($l) { return "'" . $l->real_escape_string((string) $c) . "'"; }, $chunk));
        $r = @$l->query("SELECT pro_sn, pro_status FROM tbl_product WHERE pro_sn IN ($in)");
        while ($r && ($x = $r->fetch_row())) {
            $out[strtoupper(trim((string) $x[0]))] = (string) $x[1];
        }
    }
    return $out;
}

/**
 * เหตุผลที่รุ่นนี้ย้ายไม่ได้ ('' = ย้ายได้)
 *
 * @param array<string,mixed> $product แถว products (ต้องมี leasing_name)
 * @return string
 */
function leasing_product_block_reason(array $product): string
{
    $ln = trim((string) ($product['leasing_name'] ?? ''));
    if ($ln === '') {
        return 'ยังไม่ได้ตั้งชื่อรุ่นในระบบเช่า (ระบบหลังบ้าน → ตั้งค่ารุ่น)';
    }
    if (in_array($ln, LEASING_MOVE_BLOCKED, true)) {
        return 'ระบบเช่าต้องใช้ IMEI ซึ่งระบบเรายังไม่เก็บ — ลงในระบบเช่าเอง';
    }
    $names = leasing_model_names();
    if ($names && !isset($names[$ln])) {
        return 'ชื่อรุ่น "' . $ln . '" ไม่มีในระบบเช่าแล้ว — ตั้งใหม่ที่ระบบหลังบ้าน';
    }
    return '';
}

/**
 * ย้ายเครื่องไประบบเช่า
 *
 * @param int[]  $assetIds
 * @param string $actor ชื่อผู้บันทึก (pro_user_add)
 * @return array{ok:bool, moved:string[], skipped:array<string,string>, error:string}
 */
function leasing_move_assets(array $assetIds, string $actor): array
{
    ensure_leasing_move_schema();
    $out = ['ok' => false, 'moved' => [], 'skipped' => [], 'error' => ''];
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้' . (function_exists('dbLeasingError') ? ': ' . dbLeasingError() : '');
        return $out;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds))));
    if (!$ids) {
        $out['error'] = 'ยังไม่ได้เลือกเครื่อง';
        return $out;
    }
    $rows = [];
    $res = db()->query('SELECT a.id, a.asset_code, a.status, a.product_id, p.name pname, p.leasing_name
                        FROM assets a JOIN products p ON p.id = a.product_id WHERE a.id IN (' . implode(',', $ids) . ')');
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $existing = leasing_existing_sns(array_column($rows, 'asset_code'));
    $user = mb_substr(trim($actor) !== '' ? trim($actor) : 'production', 0, 20);
    $today = date('Y-m-d');
    $ins = $l->prepare("INSERT INTO tbl_product (pro_date, pro_name, pro_sn, pro_status, pro_user_add, pro_remarks, pro_asset_dstart, pro_asset_dend, pro_bundle)
                        VALUES (?, ?, ?, 'finished goods', ?, '', '0000-00-00', '0000-00-00', '')");
    if (!$ins) {
        $out['error'] = 'เตรียมคำสั่งบันทึกในระบบเช่าไม่ได้: ' . $l->error;
        return $out;
    }
    foreach ($rows as $r) {
        $code = trim((string) $r['asset_code']);
        $why = leasing_product_block_reason($r);
        if ($why === '' && $r['status'] !== 'new') {
            $why = 'สถานะไม่ใช่เครื่องใหม่ (' . status_th((string) $r['status']) . ')';
        }
        if ($why === '' && isset($existing[strtoupper($code)])) {
            $why = 'อยู่ในระบบเช่าแล้ว (' . $existing[strtoupper($code)] . ')';
        }
        if ($why === '' && mb_strlen($code) > LEASING_SN_MAX) {
            $why = 'รหัสยาวเกิน ' . LEASING_SN_MAX . ' ตัวที่ระบบเช่ารับได้';
        }
        if ($why !== '') {
            $out['skipped'][$code] = $why;
            continue;
        }
        $name = (string) $r['leasing_name'];
        $ins->bind_param('ssss', $today, $name, $code, $user);
        if (!$ins->execute()) {
            $out['skipped'][$code] = $l->errno === 1062 ? 'อยู่ในระบบเช่าแล้ว' : 'บันทึกในระบบเช่าไม่สำเร็จ: ' . $l->error;
            continue;
        }
        // ระบบเราเปลี่ยนเป็นเครื่องเช่าทันที (ตรงกับกติกาซิงก์: ลงทะเบียนในระบบเช่า = เครื่องเช่า) + ประวัติเข้า-ออกคลัง
        q("UPDATE assets SET status = 'rental' WHERE id = ?", 'i', [(int) $r['id']]);
        q("INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), 'out', ?, ?)",
          'iss', [(int) $r['id'], 'ย้ายไประบบเช่า (คลังพร้อมเช่า) (new → rental)', $actor]);
        $out['moved'][] = $code;
    }
    $ins->close();
    $out['ok'] = true;
    return $out;
}
