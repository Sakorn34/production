<?php
/**
 * includes/key_parts_order.php — สรุปยอดเบิกอะไหล่สั่งต่างประเทศ แยกใช้ผลิต/ซ่อม/อื่น ตามปี–เดือน
 *
 * รายการอะไหล่อ่านจาก site_settings (ตั้งที่ key_parts_order_config.php) หรือชุดเริ่มต้นในโค้ด
 *
 * ยอดเบิกอ่านจาก biton_inventory (log_stock · log_part เป็นชื่อ) สอดคล้อง stock_report.php
 * คงเหลือ/ขั้นต่ำ/ลิงก์สั่งซื้อ — จากตาราง part + products ช่าง (ถ้ามี map)
 * จำแนก bucket จาก pre_type / log_type ของใบเบิก
 *
 * Flow: resolve 3 รายการ → aggregate log_stock รายเดือน → cache สั้นต่อปี
 */

require_once __DIR__ . '/inventory_db.php';
require_once __DIR__ . '/maintenance_db.php';
require_once __DIR__ . '/production_sync.php';
require_once __DIR__ . '/purchase_check.php';

// ─ ค่าคงที่ ─────────────────────────────────────────────────────────────────

/** @var array<string,string> */
const KEY_PARTS_ORDER_BUCKETS = [
    'prod'   => 'ใช้ผลิต',
    'repair' => 'ซ่อม',
    'other'  => 'อื่นๆ',
];

/** @var string ป้ายยืนยันชุดโค้ดบนหน้า (เปลี่ยนเมื่อ deploy สำคัญ) */
const KEY_PARTS_ORDER_BUILD = '2026-11-02-kpo-fix-h';

/** @var string คีย์ site_settings — JSON รายการอะไหล่ที่สรุปในหน้า parts */
const KEY_PARTS_ORDER_CATALOG_SETTING = 'key_parts_order_catalog';

/** @var int จำนวนรายการสูงสุดในหน้าสรุป */
const KEY_PARTS_ORDER_CATALOG_MAX = 20;

/** @var string[] นามสกุลรูปที่อนุญาตในโฟลเดอร์อัปโหลด */
const KEY_PARTS_ORDER_IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

/** @var string[] */
const KEY_PARTS_ORDER_MONTHS = [
    1  => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
    7  => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
];

/**
 * บังคับปลดล็อกหลังบ้านก่อนเข้าหน้าจัดการรูป KPO (session settings_unlocked เดียวกับ settings.php)
 *
 * @return void
 */
function key_parts_order_require_settings_unlock(): void
{
    $ttl = 1800;
    $since = (int) ($_SESSION['settings_unlocked'] ?? 0);
    if ($since > 0 && (time() - $since) <= $ttl) {
        return;
    }
    if ($since > 0) {
        unset($_SESSION['settings_unlocked']);
    }
    flash('error', 'จัดการรูปได้จากระบบหลังบ้านเท่านั้น — เปิดจากหน้าตั้งค่าแล้วใส่ PIN');
    redirect(ui_settings_admin_url());
}

// ─ Config catalog ─────────────────────────────────────────────────────────────

/**
 * รายการอะไหล่คีย์เริ่มต้น (ใช้เมื่อยังไม่ตั้งค่าใน site_settings)
 *
 * @return array<int,array{key:string,label:string,rev_label:string,inv_log_part:string,image_rel?:string,code_candidates:array<int,string>,name_patterns:array<int,string>}>
 */
function key_parts_order_catalog_builtin(): array
{
    return [
        [
            'key'              => 'pi3',
            'label'            => 'บอร์ด Raspberry Pi3',
            'rev_label'        => 'Lot ใหม่',
            'inv_log_part'     => 'บอร์ด Raspberry Pi3 (Lot ใหม่)',
            'image_rel'        => 'kpo/pi3.jpg',
            'code_candidates'  => [],
            'name_patterns'    => ['%Raspberry%Pi3%', '%Pi3%Lot%', '%บอร์ด%Pi3%', '%Raspberry Pi3%'],
        ],
        [
            'key'              => 'lcd7',
            'label'            => 'จอ 7 Inch',
            'rev_label'        => 'Rev.4.1',
            'inv_log_part'     => 'จอ 7 Inch (Rev.4.1)',
            'image_rel'        => 'kpo/lcd7.jpg',
            'code_candidates'  => [],
            'name_patterns'    => ['%7 Inch%Rev.4%', '%จอ%7%Inch%4.1%', '%7%นิ้ว%4.1%'],
        ],
        [
            'key'              => 'led7',
            'label'            => 'จอ LED 7 นิ้ว',
            'rev_label'        => 'Rev.1.1',
            'inv_log_part'     => 'จอ LED 7 นิ้ว (Rev.1.1)',
            'image_rel'        => 'kpo/led7.jpg',
            'code_candidates'  => [],
            'name_patterns'    => ['%LED%7%Rev.1%', '%จอ LED%7%', '%LED 7%1.1%'],
        ],
    ];
}

/**
 * รายการที่ใช้จริงบนหน้าสรุป — อ่านจาก site_settings หรือ fallback ชุดเริ่มต้น
 *
 * @return array<int,array{key:string,label:string,rev_label:string,inv_log_part:string,image_rel?:string,code_candidates:array<int,string>,name_patterns:array<int,string>}>
 */
function key_parts_order_catalog_def(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $raw = key_parts_order_catalog_setting_json();
    if ($raw !== '') {
        $parsed = key_parts_order_parse_catalog_json($raw);
        if ($parsed !== []) {
            $cached = $parsed;
            return $cached;
        }
    }

    $cached = key_parts_order_catalog_builtin();
    return $cached;
}

/**
 * อ่าน JSON รายการจาก site_settings (biton_production)
 *
 * @return string
 */
function key_parts_order_catalog_setting_json(): string
{
    try {
        $db = production_db();
        $st = $db->prepare('SELECT sval FROM site_settings WHERE skey = ? LIMIT 1');
        $st->execute([KEY_PARTS_ORDER_CATALOG_SETTING]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? trim((string) ($row['sval'] ?? '')) : '';
    } catch (Throwable $e) {
        error_log('[key_parts_order_catalog_setting_json] ' . $e->getMessage());
        return '';
    }
}

/**
 * แปลง JSON เป็นรายการ catalog ที่ normalize แล้ว
 *
 * @param string $json
 * @return array<int,array{key:string,label:string,rev_label:string,inv_log_part:string,image_rel?:string,code_candidates:array<int,string>,name_patterns:array<int,string>}>
 */
function key_parts_order_parse_catalog_json(string $json): array
{
    $json = trim($json);
    if ($json === '') {
        return [];
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = key_parts_order_normalize_catalog_row($row);
        if ($norm !== null) {
            $out[] = $norm;
        }
    }
    return $out;
}

/**
 * ทำให้แถว catalog จากฟอร์ม/JSON มีรูปแบบเดียวกัน
 *
 * @param array<string,mixed> $row
 * @return array{key:string,label:string,rev_label:string,inv_log_part:string,image_rel:string,code_candidates:array<int,string>,name_patterns:array<int,string>}|null
 */
function key_parts_order_normalize_catalog_row(array $row): ?array
{
    $key = strtolower(trim((string) ($row['key'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $key)) {
        return null;
    }

    $label = trim((string) ($row['label'] ?? ''));
    $invLogPart = trim((string) ($row['inv_log_part'] ?? ''));
    if ($label === '' || $invLogPart === '') {
        return null;
    }

    $rev = trim((string) ($row['rev_label'] ?? ''));
    if ($rev === '') {
        $rev = '—';
    }

    $imageRel = trim((string) ($row['image_rel'] ?? ''));
    if ($imageRel === '') {
        $imageRel = 'kpo/' . $key . '.jpg';
    }

    $patterns = [];
    if (isset($row['name_patterns']) && is_array($row['name_patterns'])) {
        foreach ($row['name_patterns'] as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $patterns[] = $p;
            }
        }
    }
    if ($patterns === []) {
        $patterns[] = '%' . str_replace([' ', '(', ')'], '%', $invLogPart) . '%';
    }

    $codes = [];
    if (isset($row['code_candidates']) && is_array($row['code_candidates'])) {
        foreach ($row['code_candidates'] as $c) {
            $c = trim((string) $c);
            if ($c !== '') {
                $codes[] = $c;
            }
        }
    }

    return [
        'key'              => $key,
        'label'            => mb_substr($label, 0, 120),
        'rev_label'        => mb_substr($rev, 0, 80),
        'inv_log_part'     => mb_substr($invLogPart, 0, 200),
        'image_rel'        => $imageRel,
        'code_candidates'  => $codes,
        'name_patterns'    => $patterns,
    ];
}

/**
 * ตรวจรายการก่อนบันทึก site_settings
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{ok:bool,error:string,items:array<int,array<string,mixed>>}
 */
function key_parts_order_validate_catalog(array $rows): array
{
    $items = [];
    $seenKeys = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = key_parts_order_normalize_catalog_row($row);
        if ($norm === null) {
            continue;
        }
        if (isset($seenKeys[$norm['key']])) {
            return ['ok' => false, 'error' => 'รหัส key ซ้ำ: ' . $norm['key'], 'items' => []];
        }
        $seenKeys[$norm['key']] = true;
        $items[] = $norm;
    }

    if ($items === []) {
        return ['ok' => false, 'error' => 'ต้องมีอย่างน้อย 1 รายการที่กรอกครบ (key, ชื่อ, ชื่อ log_part)', 'items' => []];
    }
    if (count($items) > KEY_PARTS_ORDER_CATALOG_MAX) {
        return ['ok' => false, 'error' => 'เกินจำนวนสูงสุด ' . KEY_PARTS_ORDER_CATALOG_MAX . ' รายการ', 'items' => []];
    }

    return ['ok' => true, 'error' => '', 'items' => $items];
}

/**
 * บันทึกรายการ catalog ลง site_settings แล้วล้าง cache สรุปยอด
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{ok:bool,error:string}
 */
function key_parts_order_save_catalog_setting(array $rows): array
{
    $v = key_parts_order_validate_catalog($rows);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => $v['error']];
    }

    $json = json_encode($v['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        return ['ok' => false, 'error' => 'แปลง JSON ไม่สำเร็จ'];
    }

    try {
        $db = production_db();
        $st = $db->prepare(
            'INSERT INTO site_settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)'
        );
        $st->execute([KEY_PARTS_ORDER_CATALOG_SETTING, $json]);
        key_parts_order_purge_caches();
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        error_log('[key_parts_order_save_catalog_setting] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'บันทึกฐานข้อมูลไม่สำเร็จ'];
    }
}

/**
 * ลบการตั้งค่า catalog — กลับไปใช้ชุดเริ่มต้นในโค้ด
 *
 * @return bool
 */
function key_parts_order_reset_catalog_setting(): bool
{
    try {
        $db = production_db();
        $st = $db->prepare('DELETE FROM site_settings WHERE skey = ? LIMIT 1');
        $st->execute([KEY_PARTS_ORDER_CATALOG_SETTING]);
        key_parts_order_purge_caches();
        return true;
    } catch (Throwable $e) {
        error_log('[key_parts_order_reset_catalog_setting] ' . $e->getMessage());
        return false;
    }
}

/**
 * ล้างไฟล์ cache สรุปยอด/ปี (หลังแก้รายการอะไหล่)
 *
 * @return void
 */
function key_parts_order_purge_caches(): void
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'key_parts_order';
    if (is_dir($dir)) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    $yearsFile = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'key_parts_order_years.json';
    if (is_file($yearsFile)) {
        @unlink($yearsFile);
    }
}

/**
 * ลายเซ็น catalog ปัจจุบัน — ผูกกับไฟล์ cache aggregate
 *
 * @return string
 */
function key_parts_order_catalog_cache_sig(): string
{
    return hash('sha256', json_encode(key_parts_order_catalog_def(), JSON_UNESCAPED_UNICODE));
}

/**
 * แยกชื่อแสดง / รุ่น จากชื่อ part ใน inventory (วงเล็บท้ายชื่อ)
 *
 * @param string $partName ชื่อ part_name / log_part
 * @return array{label:string,rev_label:string,inv_log_part:string}
 */
function key_parts_order_split_part_display_name(string $partName): array
{
    $partName = trim($partName);
    if ($partName === '') {
        return ['label' => '', 'rev_label' => '—', 'inv_log_part' => ''];
    }
    if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $partName, $m)) {
        return [
            'label'        => trim($m[1]),
            'rev_label'    => trim($m[2]),
            'inv_log_part' => $partName,
        ];
    }
    return ['label' => $partName, 'rev_label' => '—', 'inv_log_part' => $partName];
}

/**
 * แนะนำ key สำหรับรายการใหม่ (ไม่ชนกับที่มีอยู่)
 *
 * @param string   $logPartName
 * @param string[] $existingKeys
 * @return string
 */
function key_parts_order_suggest_key(string $logPartName, array $existingKeys): string
{
    $ascii = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $logPartName));
    $ascii = trim($ascii, '_');
    if (strlen($ascii) >= 2 && preg_match('/^[a-z]/', $ascii)) {
        $base = substr($ascii, 0, 24);
    } else {
        $base = 'p' . substr(preg_replace('/[^a-f0-9]/', '', md5($logPartName)), 0, 8);
    }
    $key = $base;
    $n = 2;
    while (in_array($key, $existingKeys, true)) {
        $suffix = '_' . $n;
        $key = substr($base, 0, max(2, 32 - strlen($suffix))) . $suffix;
        $n++;
    }
    return $key;
}

/**
 * ค้นหาชื่ออะไหล่ใน inventory สำหรับเพิ่มเข้ารายการสรุป
 *
 * @param string $q     คำค้น (อย่างน้อย 1 ตัวอักษร)
 * @param int    $limit
 * @return array<int,array{inv_log_part:string,label:string,rev_label:string,suggested_key:string,in_catalog:bool,catalog_key:string,source:string,stock:?float,min:?float}>
 */
function key_parts_order_search_inventory_catalog(string $q, int $limit = 25): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $limit = max(5, min(40, $limit));

    $inv = parts_inventory_db();
    if (!$inv) {
        return [];
    }

    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $catalogByLog = [];
    $existingKeys = [];
    foreach (key_parts_order_catalog_def() as $def) {
        $lp = trim((string) ($def['inv_log_part'] ?? ''));
        if ($lp !== '') {
            $catalogByLog[$lp] = (string) $def['key'];
        }
        $existingKeys[] = (string) $def['key'];
    }

    $byName = [];

    $st = $inv->prepare('SELECT part_name FROM part WHERE part_name LIKE ? ORDER BY part_name ASC LIMIT 50');
    if ($st) {
        $st->bind_param('s', $like);
        if ($st->execute()) {
            $res = $st->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $name = trim((string) ($row['part_name'] ?? ''));
                if ($name !== '') {
                    $byName[$name] = 'part';
                }
            }
        }
        $st->close();
    }

    $st2 = $inv->prepare(
        'SELECT DISTINCT log_part FROM log_stock WHERE log_part LIKE ? ORDER BY log_part ASC LIMIT 30'
    );
    if ($st2) {
        $st2->bind_param('s', $like);
        if ($st2->execute()) {
            $res = $st2->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $name = trim((string) ($row['log_part'] ?? ''));
                if ($name !== '' && !isset($byName[$name])) {
                    $byName[$name] = 'log';
                }
            }
        }
        $st2->close();
    }

    $names = array_keys($byName);
    sort($names, SORT_STRING);
    $out = [];
    foreach ($names as $name) {
        if (count($out) >= $limit) {
            break;
        }
        $split = key_parts_order_split_part_display_name($name);
        $inCat = isset($catalogByLog[$name]);
        $stock = null;
        $min = null;
        $invRow = key_parts_order_inv_part_row($inv, $name);
        if ($invRow) {
            $stock = key_parts_order_row_qty($invRow, ['part_amount', 'part_instock', 'part_balance', 'part_qty']);
            $min = key_parts_order_row_qty($invRow, ['part_min_alert', 'part_min', 'part_minimum', 'minimum_stock', 'min_stock']);
        }
        $out[] = [
            'inv_log_part'   => $name,
            'label'          => $split['label'],
            'rev_label'      => $split['rev_label'],
            'suggested_key'  => key_parts_order_suggest_key($name, $existingKeys),
            'in_catalog'     => $inCat,
            'catalog_key'    => $inCat ? $catalogByLog[$name] : '',
            'source'         => $byName[$name],
            'stock'          => $stock,
            'min'            => $min,
        ];
    }

    return $out;
}

/**
 * รายการ catalog สำหรับ editor (JSON-friendly)
 *
 * @return array<int,array{key:string,label:string,rev_label:string,inv_log_part:string}>
 */
function key_parts_order_catalog_editor_rows(): array
{
    $rows = [];
    foreach (key_parts_order_catalog_def() as $def) {
        $rows[] = [
            'key'          => (string) $def['key'],
            'label'        => (string) $def['label'],
            'rev_label'    => (string) $def['rev_label'],
            'inv_log_part' => (string) $def['inv_log_part'],
        ];
    }
    return $rows;
}

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * จำแนก mode เป็น bucket ใช้ผลิต / ซ่อม / อื่นๆ
 *
 * @param string $mode ค่า part_movements.mode
 * @return string prod|repair|other
 */
function key_parts_order_usage_bucket(string $mode): string
{
    if (purchase_check_is_prod_mode($mode)) {
        return 'prod';
    }
    if (strpos($mode, 'ซ่อม') !== false) {
        return 'repair';
    }
    return 'other';
}

/**
 * จำแนกวัตถุประสงค์รายการ drill-down (ละเอียดกว่า bucket สรุปปี)
 *
 * @param array<string,mixed> $mv แถวจาก key_parts_order_movements()
 * @return array{key:string,label:string,sort:int}
 */
function key_parts_order_drill_purpose_meta(array $mv): array
{
    $flow = (string) ($mv['flow'] ?? 'out');
    $mode = trim((string) ($mv['mode'] ?? ''));

    if ($flow === 'in') {
        return ['key' => 'in_import', 'label' => 'นำเข้า', 'sort' => 700];
    }

    if ($mode !== '') {
        if (mb_strpos($mode, 'เสียระหว่างผลิต') !== false
            || (mb_strpos($mode, 'เสีย') !== false && mb_strpos($mode, 'ผลิต') !== false)) {
            return ['key' => 'out_scrap_prod', 'label' => 'เสียระหว่างผลิต', 'sort' => 200];
        }
        if (mb_strpos($mode, 'เสียระหว่างขนส่ง') !== false
            || (mb_strpos($mode, 'เสีย') !== false && mb_strpos($mode, 'ขนส่ง') !== false)) {
            return ['key' => 'out_scrap_ship', 'label' => 'เสียระหว่างขนส่ง', 'sort' => 300];
        }
        if (mb_strpos($mode, 'ตาม PO') !== false || mb_strpos($mode, 'ตามPO') !== false
            || preg_match('/\bPO\b/ui', $mode)) {
            return ['key' => 'out_po', 'label' => 'ตาม PO', 'sort' => 400];
        }
        if (mb_strpos($mode, 'ซ่อม') !== false) {
            return ['key' => 'out_repair', 'label' => 'ซ่อม', 'sort' => 500];
        }
        if (purchase_check_is_prod_mode($mode)) {
            return ['key' => 'out_prod', 'label' => 'ผลิต', 'sort' => 100];
        }
    }

    $bk = (string) ($mv['bucket'] ?? '');
    if ($bk === 'repair') {
        return ['key' => 'out_repair', 'label' => 'ซ่อม', 'sort' => 500];
    }
    if ($bk === 'prod') {
        return ['key' => 'out_prod', 'label' => 'ผลิต', 'sort' => 100];
    }
    if ($bk === 'other') {
        return ['key' => 'out_other', 'label' => 'อื่นๆ', 'sort' => 600];
    }

    return ['key' => 'out_other', 'label' => 'อื่นๆ', 'sort' => 600];
}

/**
 * เรียงรายการ drill-down ตามวัตถุประสงค์ แล้ววันที่ใหม่ → เก่า
 *
 * @param array<int,array<string,mixed>> $movements
 * @return array<int,array<string,mixed>>
 */
function key_parts_order_sort_drill_movements(array $movements): array
{
    foreach ($movements as $i => $mv) {
        $meta = key_parts_order_drill_purpose_meta($mv);
        $movements[$i]['purpose_key'] = $meta['key'];
        $movements[$i]['purpose_label'] = $meta['label'];
        $movements[$i]['purpose_sort'] = $meta['sort'];
    }

    usort($movements, static function (array $a, array $b): int {
        $cmp = ((int) ($a['purpose_sort'] ?? 999)) <=> ((int) ($b['purpose_sort'] ?? 999));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($b['moved_at'] ?? ''), (string) ($a['moved_at'] ?? ''));
    });

    return $movements;
}

/**
 * class ชิปวัตถุประสงค์ drill-down ตาม purpose_key
 *
 * @param string $purposeKey ค่าจาก key_parts_order_drill_purpose_meta()['key']
 * @return string
 */
function key_parts_order_drill_purpose_chip_class(string $purposeKey): string
{
    $map = [
        'out_prod'       => 'kpo-purpose-chip--prod',
        'out_scrap_prod' => 'kpo-purpose-chip--scrap-prod',
        'out_scrap_ship' => 'kpo-purpose-chip--scrap-ship',
        'out_po'         => 'kpo-purpose-chip--po',
        'out_repair'     => 'kpo-purpose-chip--repair',
        'out_other'      => 'kpo-purpose-chip--other',
        'in_import'      => 'kpo-purpose-chip--import',
    ];

    return $map[$purposeKey] ?? 'kpo-purpose-chip--other';
}

/**
 * แสดงชิปข้อความวัตถุประสงค์ (สีแยกประเภท) ในตาราง drill-down
 *
 * @param string $purposeKey
 * @param string $purposeLabel
 * @return void
 */
function key_parts_order_echo_drill_purpose_chip(string $purposeKey, string $purposeLabel): void
{
    $label = trim($purposeLabel);
    if ($label === '') {
        $label = '—';
    }
    $chipCls = key_parts_order_drill_purpose_chip_class($purposeKey);
    ?>
<span class="kpo-purpose-chip <?= e($chipCls) ?>"><?= e($label) ?></span>
    <?php
}

/**
 * แสดงชิปเบิกออก/รับเข้าในตาราง drill-down (สไตล์เดียวกับชิปวัตถุประสงค์)
 *
 * @param string $flow out|in
 * @return void
 */
function key_parts_order_echo_drill_flow_chip(string $flow): void
{
    $flow = $flow === 'in' ? 'in' : 'out';
    $chipCls = $flow === 'in' ? 'kpo-purpose-chip--flow-in' : 'kpo-purpose-chip--flow-out';
    ?>
<span class="kpo-purpose-chip <?= e($chipCls) ?>"><?= e(key_parts_order_flow_label($flow)) ?></span>
    <?php
}

/**
 * ทิศทาง movement จาก log_type / pre_type ของ inventory (เบิก vs นำเข้)
 *
 * @param string $logType
 * @param string $preType
 * @return string out|in
 */
function key_parts_order_inv_flow(string $logType, string $preType = ''): string
{
    $label = trim($logType !== '' ? $logType : $preType);
    if ($label === '') {
        return 'out';
    }
    if (mb_strpos($label, 'นำเข้') !== false || mb_strpos($label, 'รับเข้') !== false) {
        return 'in';
    }
    if (mb_strpos($label, 'เบิก') !== false) {
        return 'out';
    }
    return 'out';
}

/**
 * ป้ายทิศทางสำหรับ UI
 *
 * @param string $flow out|in
 * @return string
 */
function key_parts_order_flow_label(string $flow): string
{
    return $flow === 'in' ? 'รับเข้า' : 'เบิกออก';
}

/**
 * URL ฐานระบบ inventory สาธารณะ (รูป / stock_overview)
 *
 * @return string
 */
function key_parts_order_inventory_public_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = 'https://bit-online.net/inventory';
    try {
        if (!function_exists('app_finishgoogs_secrets_path')) {
            require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
        }
        $c = require app_finishgoogs_secrets_path();
        $cfg = is_array($c) ? ($c['inventory'] ?? null) : null;
        if (is_array($cfg)) {
            $pub = trim((string) ($cfg['public_url'] ?? ''));
            if ($pub !== '') {
                $base = rtrim($pub, '/');
            }
        }
    } catch (Throwable $e) {
        error_log('[key_parts_order_inventory_public_base] ' . $e->getMessage());
    }
    return $base;
}

/**
 * URL ฐานระบบ production (finishgoogs) สำหรับลิงก์ใบเบิก/เครื่อง
 *
 * @return string
 */
function key_parts_order_finishgoogs_public_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $base = 'https://bit-online.net/production/finishgoogs_ma_update';
    try {
        if (!function_exists('app_finishgoogs_secrets_path')) {
            require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
        }
        $c = require app_finishgoogs_secrets_path();
        if (is_array($c)) {
            foreach (['production_public_url', 'finishgoogs_public_url'] as $key) {
                $pub = trim((string) ($c[$key] ?? ''));
                if ($pub !== '') {
                    $base = rtrim($pub, '/');
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[key_parts_order_finishgoogs_public_base] ' . $e->getMessage());
    }

    return $base;
}

/**
 * สร้าง grp กลุ่มรุ่นจาก product id (รูปแบบเดียวกับ inv_pickup_grp)
 *
 * @param string $productIds คั่นด้วย comma หรือขีด
 * @return string
 */
function key_parts_order_inv_pickup_grp_from_product_ids(string $productIds): string
{
    $list = preg_split('/[^0-9]+/', $productIds, -1, PREG_SPLIT_NO_EMPTY);
    $list = array_values(array_unique(array_filter(array_map('intval', $list ?: []), static function ($id) {
        return $id > 0;
    })));
    sort($list);

    return implode('-', $list);
}

/**
 * หา grp คิวผลิตจาก part_id ใน inventory หรือชื่อชุด pre_product
 *
 * @param PDO    $pdo
 * @param int    $partId   part_id ใน inventory
 * @param string $setName  pre_product (ชื่อชุด)
 * @return string
 */
function key_parts_order_resolve_inv_pickup_grp(PDO $pdo, int $partId, string $setName): string
{
    static $partCache = [];
    static $setCache = [];

    if ($partId > 0) {
        if (!array_key_exists($partId, $partCache)) {
            $partCache[$partId] = '';
            try {
                $st = $pdo->prepare('SELECT product_ids FROM inv_part_map WHERE inv_part_id = ? LIMIT 1');
                $st->execute([$partId]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                $ids = trim((string) ($row['product_ids'] ?? ''));
                if ($ids !== '' && $ids !== '0') {
                    $partCache[$partId] = key_parts_order_inv_pickup_grp_from_product_ids($ids);
                }
            } catch (Throwable $e) {
                error_log('[key_parts_order_resolve_inv_pickup_grp part] ' . $e->getMessage());
            }
        }
        if ($partCache[$partId] !== '') {
            return $partCache[$partId];
        }
    }

    $setName = trim($setName);
    if ($setName === '') {
        return '';
    }
    if (!array_key_exists($setName, $setCache)) {
        $setCache[$setName] = '';
        try {
            $st = $pdo->prepare('SELECT mode, product_ids FROM inv_set_map WHERE inv_name = ? LIMIT 1');
            $st->execute([$setName]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $mode = trim((string) ($row['mode'] ?? 'set'));
                $ids = trim((string) ($row['product_ids'] ?? ''));
                if ($mode === 'set' && $ids !== '' && $ids !== '0') {
                    $setCache[$setName] = key_parts_order_inv_pickup_grp_from_product_ids($ids);
                }
            }
        } catch (Throwable $e) {
            error_log('[key_parts_order_resolve_inv_pickup_grp set] ' . $e->getMessage());
        }
    }

    return $setCache[$setName];
}

/**
 * URL หน้ารายละเอียดใบเบิกรอผลิต (inv_pickups)
 *
 * @param string $preId เลขใบ inventory
 * @param string $grp   กลุ่มรุ่น (ว่าง = เปิดหน้ารายการ)
 * @return string
 */
function key_parts_order_inv_pickup_url(string $preId, string $grp = ''): string
{
    $preId = trim($preId);
    if ($preId === '') {
        return '';
    }
    $base = key_parts_order_finishgoogs_public_base();
    $url = $base . '/inv_pickups.php?pre=' . rawurlencode($preId);
    $grp = trim($grp);
    if ($grp !== '') {
        $url .= '&grp=' . rawurlencode($grp);
    }

    return $url;
}

/**
 * เครื่องที่ตัดยอดจากใบเบิกผลิตแล้ว (inv_pickup_alloc)
 *
 * @param PDO    $pdo
 * @param string $preId
 * @param string $grp   ว่าง = ทุกกลุ่มในใบ
 * @return array<int,array{id:int,serial:string,url:string}>
 */
function key_parts_order_inv_pickup_assets(PDO $pdo, string $preId, string $grp = ''): array
{
    $preId = trim($preId);
    if ($preId === '') {
        return [];
    }
    $grp = trim($grp);
    $base = key_parts_order_finishgoogs_public_base();
    try {
        if ($grp !== '') {
            $st = $pdo->prepare(
                'SELECT a.id, a.asset_code, a.factory_serial
                 FROM inv_pickup_alloc x
                 INNER JOIN assets a ON a.id = x.asset_id
                 WHERE x.pre_id = ? AND x.grp = ?
                 ORDER BY x.allocated_at DESC
                 LIMIT 30'
            );
            $st->execute([$preId, $grp]);
        } else {
            $st = $pdo->prepare(
                'SELECT a.id, a.asset_code, a.factory_serial
                 FROM inv_pickup_alloc x
                 INNER JOIN assets a ON a.id = x.asset_id
                 WHERE x.pre_id = ?
                 ORDER BY x.allocated_at DESC
                 LIMIT 30'
            );
            $st->execute([$preId]);
        }
        $out = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $sn = trim((string) ($row['asset_code'] ?? ''));
            if ($sn === '') {
                $sn = trim((string) ($row['factory_serial'] ?? ''));
            }
            if ($sn === '') {
                $sn = '#' . $id;
            }
            $out[] = [
                'id'     => $id,
                'serial' => $sn,
                'url'    => $base . '/asset.php?id=' . $id,
            ];
        }

        return $out;
    } catch (Throwable $e) {
        error_log('[key_parts_order_inv_pickup_assets] ' . $e->getMessage());

        return [];
    }
}

/**
 * ใส่ลิงก์ใบเบิกผลิต + S/N ที่ตัดจากใบ — เฉพาะ bucket ใช้ผลิต
 *
 * @param array<int,array<string,mixed>> $movements
 * @param int $partId part_id ใน inventory ของแถว drill
 * @return array<int,array<string,mixed>>
 */
function key_parts_order_enrich_movements_production(array $movements, int $partId): array
{
    if (!$movements) {
        return $movements;
    }
    try {
        $pdo = production_db();
    } catch (Throwable $e) {
        return $movements;
    }

    foreach ($movements as $i => $row) {
        if (($row['flow'] ?? '') !== 'out') {
            continue;
        }
        if ((string) ($row['bucket'] ?? '') !== 'prod') {
            continue;
        }
        $preId = trim((string) ($row['doc_no'] ?? ''));
        if ($preId === '') {
            continue;
        }
        $setName = trim((string) ($row['pre_product'] ?? ''));
        $grp = key_parts_order_resolve_inv_pickup_grp($pdo, $partId, $setName);
        $pickupUrl = key_parts_order_inv_pickup_url($preId, $grp);
        if ($pickupUrl === '') {
            continue;
        }
        $assets = key_parts_order_inv_pickup_assets($pdo, $preId, $grp);

        if (!isset($movements[$i]['pickup']) || !is_array($movements[$i]['pickup'])) {
            $movements[$i]['pickup'] = [];
        }
        $movements[$i]['pickup']['production_pickup_url'] = $pickupUrl;
        $movements[$i]['pickup']['production_pickup_label'] = 'ใบ ' . $preId
            . ($grp !== '' ? ' · คิวผลิต' : '');
        $movements[$i]['pickup']['production_assets'] = $assets;
    }

    return $movements;
}

/**
 * โฟลเดอร์เก็บรูปที่อัปจากหลังบ้าน (uploads ของระบบหลัก — ไม่ถูกทับตอน deploy)
 *
 * @return string
 */
function key_parts_order_image_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'finishgoogs_ma_update'
        . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'kpo';
}

/**
 * key ใน catalog ที่อนุญาตอัปโหลดรูป
 *
 * @return string[]
 */
function key_parts_order_catalog_keys(): array
{
    $keys = [];
    foreach (key_parts_order_catalog_def() as $def) {
        $k = trim((string) ($def['key'] ?? ''));
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    return $keys;
}

/**
 * ตรวจว่า key อยู่ใน catalog
 *
 * @param string $key
 * @return bool
 */
function key_parts_order_is_catalog_key(string $key): bool
{
    return in_array($key, key_parts_order_catalog_keys(), true);
}

/**
 * path ไฟล์รูปที่อัปไว้แล้วของ key (ไฟล์แรกที่เจอ)
 *
 * @param string $key
 * @return string|null
 */
function key_parts_order_uploaded_image_path(string $key): ?string
{
    if (!key_parts_order_is_catalog_key($key)) {
        return null;
    }
    $dir = key_parts_order_image_dir();
    foreach (KEY_PARTS_ORDER_IMAGE_EXTS as $ext) {
        $path = $dir . DIRECTORY_SEPARATOR . $key . '.' . $ext;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * URL สาธารณะของรูปที่อัปไว้ (?v=mtime กัน cache)
 *
 * @param string $key
 * @return string
 */
function key_parts_order_uploaded_image_url(string $key): string
{
    $path = key_parts_order_uploaded_image_path($key);
    if ($path === null) {
        return '';
    }
    $name = basename($path);
    if (!preg_match('/^[a-z0-9]+\.(jpg|jpeg|png|gif|webp)$/i', $name)) {
        return '';
    }
    $url = parts_upload_img_url('kpo/' . $name);
    if ($url === null || $url === '') {
        return '';
    }
    return $url . '?v=' . (int) filemtime($path);
}

/**
 * ลบรูปที่อัปไว้ของ key — เฉพาะไฟล์ชื่อตรง catalog ในโฟลเดอร์ kpo
 *
 * @param string $key
 * @return int จำนวนไฟล์ที่ลบ
 */
function key_parts_order_delete_uploaded_image(string $key): int
{
    if (!key_parts_order_is_catalog_key($key)) {
        return 0;
    }
    $dir = key_parts_order_image_dir();
    $n = 0;
    foreach (KEY_PARTS_ORDER_IMAGE_EXTS as $ext) {
        $path = $dir . DIRECTORY_SEPARATOR . $key . '.' . $ext;
        if (is_file($path) && @unlink($path)) {
            $n++;
        }
    }
    return $n;
}

/**
 * บันทึกรูปที่อัปโหลดทับของ key (validate MIME + ขนาด · ชื่อไฟล์คงที่)
 *
 * @param string $key   catalog key
 * @param string $field ชื่อ field ใน $_FILES
 * @return string ข้อความ error ว่าง = สำเร็จ
 */
function key_parts_order_save_uploaded_image(string $key, string $field): string
{
    if (!key_parts_order_is_catalog_key($key)) {
        return 'ไม่พบรายการอะไหล่';
    }
    if (empty($_FILES[$field]['tmp_name']) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'ยังไม่ได้เลือกไฟล์รูป';
    }
    if ((int) ($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
        return 'ไฟล์ใหญ่เกิน 5MB';
    }
    $tmp = (string) $_FILES[$field]['tmp_name'];
    if (!is_uploaded_file($tmp)) {
        return 'ไฟล์อัปโหลดไม่ถูกต้อง';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $mimeMap = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($mimeMap[$mime])) {
        return 'รองรับเฉพาะ JPG, PNG, GIF, WEBP';
    }
    if (!@getimagesize($tmp)) {
        return 'ไฟล์นี้ไม่ใช่รูปภาพ';
    }
    $dir = key_parts_order_image_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return 'สร้างโฟลเดอร์เก็บรูปไม่สำเร็จ';
    }
    key_parts_order_delete_uploaded_image($key);
    $dest = $dir . DIRECTORY_SEPARATOR . $key . '.' . $mimeMap[$mime];
    if (!move_uploaded_file($tmp, $dest)) {
        return 'บันทึกไฟล์ไม่สำเร็จ';
    }
    @chmod($dest, 0644);
    return '';
}

/**
 * รวม URL รูป: ไฟล์ที่อัปจากหลังบ้าน → part_image inventory → assets/kpo
 *
 * @param string $partImage ค่า part.part_image
 * @param string $imageRel    path ใต้ parts/assets เช่น kpo/pi3.jpg
 * @param string $key         catalog key สำหรับหาไฟล์ที่อัป
 * @return string
 */
function key_parts_order_resolve_image_url(string $partImage, string $imageRel, string $key = ''): string
{
    if ($key !== '') {
        $uploaded = key_parts_order_uploaded_image_url($key);
        if ($uploaded !== '') {
            return $uploaded;
        }
    }
    $partImage = trim($partImage);
    if ($partImage !== '') {
        if (preg_match('#^https?://#i', $partImage)) {
            return $partImage;
        }
        $base = key_parts_order_inventory_public_base();
        return $base . '/' . ltrim(str_replace('\\', '/', $partImage), '/');
    }
    $imageRel = trim($imageRel);
    if ($imageRel === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $imageRel)) {
        return $imageRel;
    }
    $rel = ltrim(str_replace('\\', '/', $imageRel), '/');
    if (strpos($rel, 'assets/') === 0) {
        $rel = substr($rel, 7);
    }
    $local = dirname(__DIR__) . '/assets/' . $rel;
    if (!is_file($local)) {
        return '';
    }
    return url('/assets/' . $rel);
}

/**
 * รูปการ์ดอะไหล่ — inventory / ไฟล์ใน assets/kpo / รูป production
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function key_parts_order_thumb_html(array $part): string
{
    $name = (string) ($part['name'] ?? $part['label'] ?? '');
    $imgUrl = trim((string) ($part['image_url'] ?? ''));
    if ($imgUrl !== '') {
        return '<span class="pc-thumb kpo-thumb"><img src="' . e($imgUrl) . '" alt="' . e($name)
            . '" loading="lazy" onerror="this.closest(\'.kpo-thumb\').classList.add(\'is-broken\')"></span>';
    }
    return purchase_check_thumb_html($part);
}

/**
 * หมายเหตุ/วัตถุประสงค์จาก log_stock + pre_stock (เบิกไปทำอะไร / รับเข้จากไหน)
 *
 * @param string $flow out|in
 * @param string $logProduct
 * @param string $logRemarks
 * @param string $logVendor
 * @param array{pre_product?:string,pre_remarks?:string}|null $preMeta
 * @return string
 */
function key_parts_order_movement_note(
    string $flow,
    string $logProduct,
    string $logRemarks,
    string $logVendor,
    ?array $preMeta
): string {
    $logProduct = trim($logProduct);
    $logRemarks = trim($logRemarks);
    $logVendor = trim($logVendor);
    $preProduct = trim((string) ($preMeta['pre_product'] ?? ''));
    $preRemarks = trim((string) ($preMeta['pre_remarks'] ?? ''));

    $parts = [];
    $purpose = $logProduct !== '' ? $logProduct : $preProduct;
    if ($purpose !== '') {
        $parts[] = $purpose;
    }

    $extra = $logRemarks !== '' ? $logRemarks : $preRemarks;
    if ($extra !== '' && $extra !== $purpose) {
        $parts[] = $extra;
    }

    if ($flow === 'in' && $logVendor !== '') {
        $parts[] = 'จาก ' . $logVendor;
    }

    return implode(' · ', $parts);
}

/**
 * แปลง log_date ของ inventory เป็น unix timestamp
 *
 * @param string $v รูปแบบ yymmdd-HHMMSS หรือ datetime มาตรฐาน
 * @return int|null
 */
function key_parts_order_log_ts(string $v): ?int
{
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^(\d{2})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
        $ts = strtotime("20{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}");
        return $ts !== false ? $ts : null;
    }
    $ts = strtotime($v);
    return $ts !== false ? $ts : null;
}

/**
 * รูปแบบ LIKE สำหรับกรอง log_date ตามปี–เดือน (yymmdd- และ YYYY-MM)
 *
 * @param int $year ค.ศ.
 * @param int $month 1–12
 * @return array{0:string,1:string}
 */
function key_parts_order_log_month_like_patterns(int $year, int $month): array
{
    $mm = sprintf('%02d', max(1, min(12, $month)));
    $yy = sprintf('%02d', $year % 100);
    return [
        $yy . $mm . '%',
        sprintf('%04d-%02d', $year, (int) $mm) . '%',
    ];
}

/**
 * จำแนก bucket จาก pre_type / log_type ของระบบ inventory
 *
 * @param string $preType
 * @param string $logType
 * @return string prod|repair|other
 */
function key_parts_order_inv_bucket(string $preType, string $logType = ''): string
{
    $logType = trim($logType);
    if ($logType !== '') {
        return key_parts_order_usage_bucket($logType);
    }
    return key_parts_order_usage_bucket(trim($preType));
}

/**
 * โหลด pre_type / pre_by ต่อเลขใบจาก pre_stock (batch) — ผู้เบิกตรง pickup_overview
 *
 * @param mysqli $inv
 * @param string[] $preIds
 * @return array<string,array{pre_type:string,pre_by:string,pre_product:string,pre_remarks:string}>
 */
function key_parts_order_inv_pre_map(mysqli $inv, array $preIds): array
{
    $preIds = array_values(array_unique(array_filter(array_map('strval', $preIds))));
    if (!$preIds) {
        return [];
    }
    $out = [];
    $chunks = array_chunk($preIds, 200);
    foreach ($chunks as $chunk) {
        $esc = array_map(static function ($id) use ($inv) {
            return "'" . $inv->real_escape_string($id) . "'";
        }, $chunk);
        $sql = 'SELECT pre_id, MAX(pre_type) AS pre_type, MAX(pre_by) AS pre_by,'
            . ' MAX(pre_product) AS pre_product, MAX(pre_remarks) AS pre_remarks'
            . ' FROM pre_stock WHERE pre_id IN (' . implode(',', $esc) . ') GROUP BY pre_id';
        $res = $inv->query($sql);
        if (!$res) {
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $id = (string) $row['pre_id'];
            $out[$id] = [
                'pre_type'    => (string) ($row['pre_type'] ?? ''),
                'pre_by'      => trim((string) ($row['pre_by'] ?? '')),
                'pre_product' => trim((string) ($row['pre_product'] ?? '')),
                'pre_remarks' => trim((string) ($row['pre_remarks'] ?? '')),
            ];
        }
        $res->free();
    }
    return $out;
}

/**
 * ป้ายสถานะใบเบิก pre_stock สำหรับ UI
 *
 * @param string $status pre|pickup|cancel|...
 * @return string
 */
function key_parts_order_pre_status_label(string $status): string
{
    $status = strtolower(trim($status));
    if ($status === 'pickup') {
        return 'รับของแล้ว (ยืนยันการจ่าย)';
    }
    if ($status === 'pre') {
        return 'รอรับของ';
    }
    if ($status === 'cancel') {
        return 'ยกเลิก';
    }
    return $status !== '' ? $status : '—';
}

/**
 * URL หน้ารายละเอียดงานซ่อม (maintenance detail_job) ตาม ts_id
 *
 * @param int $tsId รหัส transac.ts_id
 * @return string ค่าว่างถ้า ts_id ไม่ถูกต้อง
 */
function key_parts_order_maintenance_detail_url(int $tsId): string
{
    if ($tsId <= 0) {
        return '';
    }
    static $tpl = null;
    if ($tpl === null) {
        $tpl = 'https://bit-online.net/maintenance/detail_job?ts_id=%d';
        try {
            if (!function_exists('app_finishgoogs_secrets_path')) {
                require_once dirname(__DIR__, 2) . '/shared/app_paths.php';
            }
            $c = require app_finishgoogs_secrets_path();
            if (is_array($c) && !empty($c['maintenance_detail_job_url'])) {
                $tpl = (string) $c['maintenance_detail_job_url'];
            }
        } catch (Throwable $e) {
            error_log('[key_parts_order_maintenance_detail_url] ' . $e->getMessage());
        }
    }
    if (strpos($tpl, '%') === false) {
        return rtrim($tpl, '?&') . (strpos($tpl, '?') !== false ? '&' : '?') . 'ts_id=' . $tsId;
    }

    return sprintf($tpl, $tsId);
}

/**
 * ดึง ts_id จากข้อความหมายเหตุ (เช่น ลิงก์ detail_job หรือ ts_id=4467)
 *
 * @param string $text ข้อความรวมจาก log/pre remarks
 * @return int
 */
function key_parts_order_maintenance_ts_id_from_text(string $text): int
{
    $text = trim($text);
    if ($text === '') {
        return 0;
    }
    if (preg_match('/(?:detail_job[\?&][^#\s]*ts_id=|(?:\bts_id\s*[=:]\s*))(\d{1,9})/i', $text, $m)) {
        return (int) $m[1];
    }

    return 0;
}

/**
 * ข้อความใน log_product เป็นรุ่น/S/N ไม่ใช่ชื่อลูกค้า
 *
 * @param string $text
 * @return bool
 */
function key_parts_order_maintenance_hint_is_device_label(string $text): bool
{
    $text = trim($text);
    if ($text === '') {
        return true;
    }
    if (preg_match('/^(bitVisitor|bitScan|bitCamera|Smart Card|Portable Printer|Card Camera)/iu', $text)) {
        return true;
    }
    if (preg_match('/\b(BP|SC|BS|BV)\d{6,}\b/i', $text)) {
        return true;
    }

    return false;
}

/**
 * ชื่อลูกค้า/บริษัทจากหมายเหตุการเบิก — ใช้จับคู่ transac ใน maintenance
 *
 * @param string $purpose log_product / pre_product
 * @param string $extra   log_remarks / pre_remarks
 * @return string
 */
function key_parts_order_maintenance_customer_hint(string $purpose, string $extra): string
{
    foreach ([$extra, $purpose] as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }
        if (preg_match('/·\s*(.+)$/u', $segment, $m)) {
            $hint = trim((string) $m[1]);
            if ($hint !== '' && !key_parts_order_maintenance_hint_is_device_label($hint)) {
                return $hint;
            }
        }
    }
    $extra = trim($extra);
    if ($extra !== '' && $extra !== trim($purpose) && mb_strlen($extra) <= 120
        && !key_parts_order_maintenance_hint_is_device_label($extra)) {
        return $extra;
    }
    $purpose = trim($purpose);
    if ($purpose !== '' && mb_strlen($purpose) <= 120 && !key_parts_order_maintenance_hint_is_device_label($purpose)) {
        return $purpose;
    }

    return '';
}

/**
 * คำค้นหาหลายแบบจากชื่อในใบเบิก (ให้ตรงกับค้นหา "บริษัท" ใน maintenance)
 *
 * @param string $hint
 * @return string[]
 */
function key_parts_order_maintenance_search_tokens(string $hint): array
{
    $hint = trim(preg_replace('/\s+/u', ' ', $hint) ?? $hint);
    if ($hint === '') {
        return [];
    }
    $tokens = [$hint];
    if (preg_match('#^([^/]+)/#u', $hint, $m)) {
        $tokens[] = trim($m[1]);
    }
    if (preg_match('/^([^(（]+)/u', $hint, $m)) {
        $short = trim($m[1]);
        if ($short !== '') {
            $tokens[] = $short;
        }
    }
    $out = [];
    foreach ($tokens as $t) {
        $t = trim($t);
        if ($t !== '' && mb_strlen($t) >= 2) {
            $out[mb_strtolower($t, 'UTF-8')] = $t;
        }
    }

    return array_values($out);
}

/**
 * escape ตัวอักษรพิเศษใน LIKE
 *
 * @param string $s
 * @return string
 */
function key_parts_order_maintenance_like_pattern(string $s): string
{
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s) . '%';
}

/**
 * ค้นหา ts_id จาก transac ตามชื่อลูกค้าและช่วงวันที่
 *
 * @param mysqli $mdb
 * @param string $likePattern ค่า LIKE ที่มี % แล้ว
 * @param string $from Y-m-d
 * @param string $to   Y-m-d
 * @param string $anchorDay Y-m-d สำหรับเรียงใกล้วันเบิก
 * @return int
 */
function key_parts_order_maintenance_query_ts_id(
    mysqli $mdb,
    string $likePattern,
    string $from,
    string $to,
    string $anchorDay
): int {
    static $cache = [];
    $cacheKey = md5($likePattern . '|' . $from . '|' . $to . '|' . $anchorDay);
    if (isset($cache[$cacheKey])) {
        return (int) $cache[$cacheKey];
    }

    $sql = 'SELECT ts_id FROM transac
            WHERE ts_date >= ? AND ts_date <= ?
              AND (ts_enduser_com LIKE ? OR ts_report_name LIKE ? OR ts_security_com LIKE ?)
            ORDER BY ABS(DATEDIFF(ts_date, ?)) ASC, ts_id DESC
            LIMIT 1';
    $st = $mdb->prepare($sql);
    if (!$st) {
        return 0;
    }
    $st->bind_param('ssssss', $from, $to, $likePattern, $likePattern, $likePattern, $anchorDay);
    $tsId = 0;
    if ($st->execute()) {
        $res = $st->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $tsId = (int) ($row['ts_id'] ?? 0);
        }
    }
    $st->close();
    $cache[$cacheKey] = $tsId;

    return $tsId;
}

/**
 * ค้นหา ts_id จาก transac ตามชื่อลูกค้าและช่วงวันที่ใกล้ pre_date
 *
 * @param mysqli $mdb
 * @param string $hint ชื่อบริษัท/ลูกค้า
 * @param string $anchorDate Y-m-d หรือ datetime
 * @return int
 */
function key_parts_order_maintenance_lookup_ts_id(mysqli $mdb, string $hint, string $anchorDate): int
{
    $tokens = key_parts_order_maintenance_search_tokens($hint);
    if (!$tokens) {
        return 0;
    }
    $anchorTs = strtotime($anchorDate);
    if ($anchorTs === false) {
        $anchorTs = time();
    }
    $anchorDay = date('Y-m-d', $anchorTs);
    // วัน pre_date/log อาจเร็วกว่า ts_date ใน maintenance หลายเดือน (เบิกอะไหล่ก่อนเปิดงาน)
    $windows = [
        [date('Y-m-d', strtotime('-90 days', $anchorTs)), date('Y-m-d', strtotime('+400 days', $anchorTs))],
        [date('Y-m-d', strtotime('-730 days', $anchorTs)), date('Y-m-d', strtotime('+730 days', $anchorTs))],
    ];

    foreach ($windows as [$from, $to]) {
        foreach ($tokens as $token) {
            $like = key_parts_order_maintenance_like_pattern($token);
            $tsId = key_parts_order_maintenance_query_ts_id($mdb, $like, $from, $to, $anchorDay);
            if ($tsId > 0) {
                return $tsId;
            }
        }
    }

    return 0;
}

/**
 * โหลดเลขงาน ts_mtn_number ต่อ ts_id (batch)
 *
 * @param mysqli $mdb
 * @param int[] $tsIds
 * @return array<int,string>
 */
function key_parts_order_maintenance_mtn_labels(mysqli $mdb, array $tsIds): array
{
    $tsIds = array_values(array_unique(array_filter(array_map('intval', $tsIds), static function ($id) {
        return $id > 0;
    })));
    if (!$tsIds) {
        return [];
    }
    $out = [];
    foreach (array_chunk($tsIds, 100) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT ts_id, ts_mtn_number, ts_enduser_com FROM transac WHERE ts_id IN ({$placeholders})";
        $st = $mdb->prepare($sql);
        if (!$st) {
            continue;
        }
        $types = str_repeat('i', count($chunk));
        $st->bind_param($types, ...$chunk);
        if ($st->execute()) {
            $res = $st->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $id = (int) ($row['ts_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $mtn = trim((string) ($row['ts_mtn_number'] ?? ''));
                $com = trim((string) ($row['ts_enduser_com'] ?? ''));
                $out[$id] = $mtn !== '' ? $mtn : ($com !== '' ? $com : ('#' . $id));
            }
        }
        $st->close();
    }

    return $out;
}

/**
 * ใส่ลิงก์งานซ่อม (maintenance) ใน pickup ของแต่ละ movement — เฉพาะเบิกซ่อม
 *
 * @param array<int,array<string,mixed>> $movements
 * @return array<int,array<string,mixed>>
 */
function key_parts_order_enrich_movements_maintenance(array $movements): array
{
    if (!$movements) {
        return $movements;
    }
    $mdb = parts_maintenance_db();
    if (!$mdb) {
        return $movements;
    }

    $needLabels = [];
    foreach ($movements as $i => $row) {
        if (($row['flow'] ?? '') !== 'out') {
            continue;
        }
        $bucket = (string) ($row['bucket'] ?? '');
        $mode = (string) ($row['mode'] ?? '');
        if ($bucket !== 'repair' && mb_strpos($mode, 'ซ่อม') === false) {
            continue;
        }

        $ctx = is_array($row['_maint_ctx'] ?? null) ? $row['_maint_ctx'] : [];

        $blob = trim(implode("\n", [
            (string) ($ctx['log_remarks'] ?? ''),
            (string) ($ctx['pre_remarks_line'] ?? ''),
            (string) ($ctx['pre_remarks_doc'] ?? ''),
            (string) ($ctx['log_product'] ?? ''),
            (string) ($ctx['pre_product'] ?? ''),
            (string) ($row['remark'] ?? ''),
        ]));

        $tsId = key_parts_order_maintenance_ts_id_from_text($blob);
        if ($tsId <= 0) {
            $purpose = trim((string) ($ctx['log_product'] ?? ''));
            if ($purpose === '') {
                $purpose = trim((string) ($ctx['pre_product'] ?? ''));
            }
            $extra = trim((string) ($ctx['pre_remarks_line'] ?? ''));
            if ($extra === '') {
                $extra = trim((string) ($ctx['log_remarks'] ?? ''));
            }
            if ($extra === '') {
                $extra = trim((string) ($ctx['pre_remarks_doc'] ?? ''));
            }
            $hint = key_parts_order_maintenance_customer_hint($purpose, $extra);
            if ($hint === '') {
                $hint = key_parts_order_maintenance_customer_hint('', (string) ($row['remark'] ?? ''));
            }
            $anchor = trim((string) ($ctx['pre_date'] ?? ''));
            if ($anchor === '') {
                $anchor = (string) ($row['moved_at'] ?? '');
            }
            if ($hint !== '') {
                $tsId = key_parts_order_maintenance_lookup_ts_id($mdb, $hint, $anchor);
            }
        }

        if ($tsId <= 0) {
            continue;
        }

        $url = key_parts_order_maintenance_detail_url($tsId);
        if ($url === '') {
            continue;
        }

        if (!isset($movements[$i]['pickup']) || !is_array($movements[$i]['pickup'])) {
            $movements[$i]['pickup'] = [];
        }
        $movements[$i]['pickup']['maintenance_ts_id'] = $tsId;
        $movements[$i]['pickup']['maintenance_job_url'] = $url;
        $needLabels[$tsId] = true;
    }

    if ($needLabels) {
        $labels = key_parts_order_maintenance_mtn_labels($mdb, array_keys($needLabels));
        foreach ($movements as $i => $row) {
            $pk = $row['pickup'] ?? null;
            if (!is_array($pk) || empty($pk['maintenance_ts_id'])) {
                continue;
            }
            $id = (int) $pk['maintenance_ts_id'];
            $movements[$i]['pickup']['maintenance_job_label'] = $labels[$id] ?? ('งาน #' . $id);
        }
    }

    foreach (array_keys($movements) as $idx) {
        unset($movements[$idx]['_maint_ctx']);
    }

    return $movements;
}

/**
 * แถว pre_stock ต่อเลขใบ + part_id (หนึ่งอะไหล่ในใบ)
 *
 * @param mysqli $inv
 * @param string[] $preIds
 * @param int $partId รหัส part ใน inventory (0 = ไม่กรอง part)
 * @return array<string,array{pre_status:string,pre_by:string,pre_date:string,pre_amount:float,pre_type:string}>
 */
function key_parts_order_inv_pre_lines_map(mysqli $inv, array $preIds, int $partId): array
{
    $preIds = array_values(array_unique(array_filter(array_map('strval', $preIds))));
    if (!$preIds) {
        return [];
    }
    $out = [];
    $partId = max(0, $partId);
    $chunks = array_chunk($preIds, 200);
    foreach ($chunks as $chunk) {
        $esc = array_map(static function ($id) use ($inv) {
            return "'" . $inv->real_escape_string($id) . "'";
        }, $chunk);
        $sql = 'SELECT pre_id, pre_status, pre_by, pre_date, pre_amount, pre_type, pre_remarks'
            . ' FROM pre_stock WHERE pre_id IN (' . implode(',', $esc) . ')';
        if ($partId > 0) {
            $sql .= " AND pre_part = '" . $inv->real_escape_string((string) $partId) . "'";
        }
        $res = $inv->query($sql);
        if (!$res) {
            continue;
        }
        while ($row = $res->fetch_assoc()) {
            $id = (string) ($row['pre_id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[$id] = [
                'pre_status'  => trim((string) ($row['pre_status'] ?? '')),
                'pre_by'      => trim((string) ($row['pre_by'] ?? '')),
                'pre_date'    => trim((string) ($row['pre_date'] ?? '')),
                'pre_amount'  => qty_norm($row['pre_amount'] ?? 0),
                'pre_type'    => trim((string) ($row['pre_type'] ?? '')),
                'pre_remarks' => trim((string) ($row['pre_remarks'] ?? '')),
            ];
        }
        $res->free();
    }
    return $out;
}

/**
 * ชื่อผู้เบิกจากใบ pre_stock — fallback log_by ถ้าใบไม่มี pre_by
 *
 * @param array{pre_type?:string,pre_by?:string}|null $preMeta
 * @param string $logBy
 * @return string
 */
function key_parts_order_requester_name(?array $preMeta, string $logBy): string
{
    $by = trim((string) ($preMeta['pre_by'] ?? ''));
    if ($by !== '') {
        return $by;
    }
    return trim($logBy);
}

/**
 * อ่านแถว part จาก inventory ตามชื่อ log_part
 *
 * @param mysqli $inv
 * @param string $logPart
 * @return array<string,mixed>|null
 */
function key_parts_order_inv_part_row(mysqli $inv, string $logPart): ?array
{
    $logPart = trim($logPart);
    if ($logPart === '') {
        return null;
    }
    $st = $inv->prepare('SELECT * FROM part WHERE part_name = ? LIMIT 1');
    if (!$st) {
        return null;
    }
    $st->bind_param('s', $logPart);
    if (!$st->execute()) {
        $st->close();
        return null;
    }
    $res = $st->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $st->close();
    return $row ?: null;
}

/**
 * ดึงค่าตัวเลขจากแถว part ตามชื่อคอลัมน์ที่เป็นไปได้
 *
 * @param array<string,mixed> $row
 * @param string[] $candidates
 * @return float|null
 */
function key_parts_order_row_qty(array $row, array $candidates): ?float
{
    foreach ($candidates as $col) {
        if (!array_key_exists($col, $row)) {
            continue;
        }
        $v = $row[$col];
        if ($v === null || $v === '') {
            continue;
        }
        return qty_norm($v);
    }
    return null;
}

/**
 * รายการคอลัมน์ SELECT จาก log_stock (รองรับ schema ที่ไม่มี log_type)
 *
 * @param mysqli $inv
 * @return string
 */
function key_parts_order_log_stock_columns(mysqli $inv): string
{
    static $cache = [];
    $sig = spl_object_hash($inv);
    if (isset($cache[$sig])) {
        return $cache[$sig];
    }
    $cols = ['log_part', 'log_amount', 'log_date', 'log_by', 'pre_id'];
    foreach (['log_type', 'log_product', 'log_remarks', 'log_vendor'] as $optional) {
        $chk = $inv->query("SHOW COLUMNS FROM log_stock LIKE '{$optional}'");
        if ($chk && $chk->fetch_assoc()) {
            $cols[] = $optional;
        }
        if ($chk) {
            $chk->free();
        }
    }
    return $cache[$sig] = implode(', ', $cols);
}

/**
 * โครงยอด bucket ว่าง
 *
 * @return array{prod:float,repair:float,other:float}
 */
function key_parts_order_empty_buckets(): array
{
    return ['prod' => 0.0, 'repair' => 0.0, 'other' => 0.0];
}

/**
 * บวก qty เข้า bucket
 *
 * @param array{prod:float,repair:float,other:float} $b
 * @param string $bucket
 * @param float|int|string $qty
 * @return array{prod:float,repair:float,other:float}
 */
function key_parts_order_add_bucket(array $b, string $bucket, $qty): array
{
    if (!isset(KEY_PARTS_ORDER_BUCKETS[$bucket])) {
        $bucket = 'other';
    }
    $b[$bucket] = qty_norm($b[$bucket] + qty_norm($qty));
    return $b;
}

/**
 * รวมยอดทั้ง 3 bucket
 *
 * @param array{prod:float,repair:float,other:float} $b
 * @return float
 */
function key_parts_order_bucket_total(array $b): float
{
    return qty_norm(($b['prod'] ?? 0) + ($b['repair'] ?? 0) + ($b['other'] ?? 0));
}

/**
 * ช่วงเวลาปี ค.ศ. สำหรับ SQL
 *
 * @param int $year ค.ศ.
 * @return array{year:int,from:string,to:string}
 */
function key_parts_order_year_bounds(int $year): array
{
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
    return [
        'year' => $year,
        'from' => sprintf('%04d-01-01 00:00:00', $year),
        'to'   => sprintf('%04d-01-01 00:00:00', $year + 1),
    ];
}

/**
 * ค่า filter ที่รองรับจากชิปสรุปปี
 *
 * @return string[]
 */
function key_parts_order_filter_keys(): array
{
    return ['all', 'out', 'in', 'prod', 'repair', 'other'];
}

/**
 * ทำให้ filter จาก GET ปลอดภัย
 *
 * @param string $raw
 * @return string
 */
function key_parts_order_normalize_filter(string $raw): string
{
    $raw = trim($raw);
    return in_array($raw, key_parts_order_filter_keys(), true) ? $raw : 'all';
}

/**
 * ดึงปี ค.ศ. ที่มี log_stock ของ 3 อะไหล่คีย์ (cache สั้น)
 *
 * @return int[] เรียงจากใหม่ไปเก่า
 */
function key_parts_order_inventory_years(): array
{
    $cacheFile = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'key_parts_order_years.json';
    if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 300) {
        $raw = @file_get_contents($cacheFile);
        if (is_string($raw) && $raw !== '') {
            $dec = json_decode($raw, true);
            if (is_array($dec)) {
                return array_values(array_map('intval', $dec));
            }
        }
    }

    $years = [];
    $inv = parts_inventory_db();
    if ($inv) {
        $parts = [];
        foreach (key_parts_order_catalog_def() as $def) {
            $lp = trim((string) ($def['inv_log_part'] ?? ''));
            if ($lp !== '') {
                $parts[] = $lp;
            }
        }
        if ($parts) {
            $ph = implode(',', array_fill(0, count($parts), '?'));
            $types = str_repeat('s', count($parts));
            $sql = "SELECT log_date FROM log_stock WHERE log_part IN ($ph)";
            $st = $inv->prepare($sql);
            if ($st) {
                $st->bind_param($types, ...$parts);
                if ($st->execute()) {
                    $res = $st->get_result();
                    while ($res && ($row = $res->fetch_assoc())) {
                        $ts = key_parts_order_log_ts((string) ($row['log_date'] ?? ''));
                        if ($ts !== null) {
                            $years[(int) date('Y', $ts)] = true;
                        }
                    }
                }
                $st->close();
            }
        }
    }

    $list = array_keys($years);
    rsort($list, SORT_NUMERIC);
    if (!$list) {
        $y = (int) date('Y');
        $list = [$y, $y - 1, $y - 2];
    }
    if (is_dir(dirname($cacheFile))) {
        @file_put_contents($cacheFile, json_encode($list), LOCK_EX);
    }
    return $list;
}

/**
 * ปีที่หน้าให้เลือก (ค.ศ.) — จากข้อมูล inventory จริง
 *
 * @return int[]
 */
function key_parts_order_year_choices(): array
{
    return key_parts_order_inventory_years();
}

/**
 * URL รายงาน stock_report ของ inventory ต่อชื่อ log_part
 *
 * @param string   $logPart
 * @param int[]|null $yearsCe  null = ทุกปีที่มีในระบบ
 * @return string
 */
function key_parts_order_stock_report_url(string $logPart, ?array $yearsCe = null): string
{
    $logPart = trim($logPart);
    if ($logPart === '') {
        return key_parts_order_inventory_public_base() . '/stock_report.php';
    }
    if ($yearsCe === null) {
        $yearsCe = key_parts_order_inventory_years();
    }
    $yearsCe = array_values(array_unique(array_filter(array_map('intval', $yearsCe), static function ($y) {
        return $y >= 2000 && $y <= 2100;
    })));
    rsort($yearsCe, SORT_NUMERIC);

    $qs = 'log_part=' . rawurlencode($logPart);
    foreach ($yearsCe as $y) {
        $qs .= '&years[]=' . $y;
    }
    return key_parts_order_inventory_public_base() . '/stock_report.php?' . $qs;
}

/**
 * แสดงปี พ.ศ. หรือ ค.ศ.
 *
 * @param int    $yearCe ค.ศ.
 * @param string $era    be|ce
 * @return string
 */
function key_parts_order_year_label(int $yearCe, string $era = 'be'): string
{
    if ($era === 'ce') {
        return (string) $yearCe;
    }
    return (string) ($yearCe + 543);
}

/**
 * URL หน้าอะไหล่คีย์สั่งซื้อ
 *
 * @param array<string,string|int|null> $over
 * @return string
 */
function key_parts_order_url(array $over = []): string
{
    $year = (int) ($over['year'] ?? ($_GET['year'] ?? (int) date('Y')));
    $era = (string) ($over['era'] ?? ($_GET['era'] ?? 'be'));
    $part = (string) ($over['part'] ?? ($_GET['part'] ?? ''));
    $month = (int) ($over['month'] ?? ($_GET['month'] ?? 0));

    if (array_key_exists('year', $over) && $over['year'] === null) {
        $year = (int) date('Y');
    }
    if (array_key_exists('part', $over) && $over['part'] === null) {
        $part = '';
    }
    if (array_key_exists('month', $over) && $over['month'] === null) {
        $month = 0;
    }

    $qs = ['year' => max(2000, min(2100, $year))];
    if ($era === 'ce') {
        $qs['era'] = 'ce';
    }
    if ($part !== '') {
        $qs['part'] = $part;
    }
    if ($month >= 1 && $month <= 12) {
        $qs['month'] = $month;
    }
    $filter = (string) ($over['filter'] ?? ($_GET['filter'] ?? ''));
    if (array_key_exists('filter', $over)) {
        $filter = $over['filter'] === null ? '' : (string) $over['filter'];
    }
    $filter = key_parts_order_normalize_filter($filter);
    if ($filter !== 'all') {
        $qs['filter'] = $filter;
    }
    $avgMos = null;
    if (array_key_exists('avg_mos', $over)) {
        $avgMos = $over['avg_mos'] === null ? '' : trim((string) $over['avg_mos']);
    } else {
        $avgMos = trim((string) ($_GET['avg_mos'] ?? ''));
    }
    if ($avgMos !== '') {
        $qs['avg_mos'] = $avgMos;
    }
    return url('/pages/key-parts-order.php?' . http_build_query($qs));
}

/**
 * ปรับ bucket / ยอดเซลล์ตาม filter ชิป
 *
 * @param string $filter
 * @param array{prod:float,repair:float,other:float} $b
 * @param float  $inQty
 * @return array{has:bool,total:float,b:array{prod:float,repair:float,other:float},inQty:float,showIn:bool}
 */
function key_parts_order_cell_for_filter(string $filter, array $b, float $inQty): array
{
    $filter = key_parts_order_normalize_filter($filter);
    $empty = key_parts_order_empty_buckets();
    $outTotal = key_parts_order_bucket_total($b);

    if ($filter === 'in') {
        return [
            'has'    => $inQty > 0,
            'total'  => $inQty,
            'b'      => $empty,
            'inQty'  => $inQty,
            'showIn' => false,
        ];
    }

    if ($filter === 'prod' || $filter === 'repair' || $filter === 'other') {
        $q = qty_norm($b[$filter] ?? 0);
        $slice = $empty;
        $slice[$filter] = $q;
        return [
            'has'    => $q > 0,
            'total'  => $q,
            'b'      => $slice,
            'inQty'  => 0.0,
            'showIn' => false,
        ];
    }

    if ($filter === 'out') {
        return [
            'has'    => $outTotal > 0,
            'total'  => $outTotal,
            'b'      => $b,
            'inQty'  => $inQty,
            'showIn' => false,
        ];
    }

    return [
        'has'    => $outTotal > 0 || $inQty > 0,
        'total'  => $outTotal,
        'b'      => $b,
        'inQty'  => $inQty,
        'showIn' => true,
    ];
}

/**
 * กรองรายการ drill-down ให้สอดคล้องชิป filter บนหน้าสรุป
 *
 * @param array<int,array<string,mixed>> $movements จาก key_parts_order_movements()
 * @param string                         $filter
 * @return array<int,array<string,mixed>>
 */
/**
 * ป้ายสถานะบนการ์ด 3 อะไหล่ — ยอดคงเหลือเทียบขั้นต่ำเป็นหลัก
 *
 * ค่า part_status_purchase จาก inventory (เช่น "ยังไม่ได้สั่งซื้อ") ใช้เฉพาะเมื่อสต็อกยังไม่เข้าเกณฑ์เตือน
 * ไม่งั้นข้อความ workflow จะทับ "ควรสั่งเพิ่ม" ทั้งที่คงเหลือต่ำกว่าขั้นต่ำแล้ว
 *
 * @param array<string,mixed> $item แถวจาก key_parts_order_resolve_items()
 * @return array{label:string, color:string, tone:string}
 */
function key_parts_order_card_status_meta(array $item): array
{
    if (($item['stock'] ?? null) === null) {
        return stock_status_meta('ok');
    }

    $stKey = trim((string) ($item['status'] ?? 'ok'));
    if ($stKey === '') {
        $stKey = 'ok';
    }

    if (stock_status_is_reorder($stKey) || $stKey === 'near') {
        return stock_status_meta($stKey);
    }

    $invSt = trim((string) ($item['inv_purchase_status'] ?? ''));
    if ($invSt === '') {
        return stock_status_meta($stKey);
    }

    $label = key_parts_order_inv_purchase_status_label($invSt);

    return [
        'label' => $label,
        'color' => 'var(--success,#16a34a)',
        'tone'  => 'text-success',
    ];
}

/**
 * ทำให้ป้ายสถานะสั่งซื้อจาก inventory อ่านง่ายบนการ์ด KPO
 *
 * @param string $raw ค่า part_status_purchase
 * @return string
 */
function key_parts_order_inv_purchase_status_label(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    static $map = [
        'ยังไม่ได้สั่งซื้อ' => 'รอดำเนินการสั่ง',
        'ยังไม่สั่งซื้อ'     => 'รอดำเนินการสั่ง',
        'สั่งแล้ว'           => 'สั่งแล้ว',
        'กำลังสั่ง'           => 'กำลังสั่ง',
    ];

    return $map[$raw] ?? $raw;
}

/**
 * โหมด preview layout ตาราง (mockup UX-T1/T2/T3) จาก query kpo_layout — ไม่ใช้ production
 *
 * @return string|null t1|t2|t3 หรือ null
 */
function key_parts_order_layout_preview(): ?string
{
    $v = strtolower(trim((string) ($_GET['kpo_layout'] ?? '')));
    if (in_array($v, ['t1', 't2', 't3'], true)) {
        return $v;
    }

    return null;
}

/**
 * แสดงลิงก์สั่งซื้อบนคอลัมน์อะไหล่หรือไม่ (logic เดียวกับการ์ด)
 *
 * @param array<string,mixed> $item แถวจาก aggregate
 * @return bool
 */
function key_parts_order_show_buy_link(array $item): bool
{
    $plink = trim((string) ($item['purchase_link'] ?? ''));
    if ($plink === '') {
        return false;
    }
    $pst = trim((string) ($item['status'] ?? 'ok'));

    return stock_status_is_reorder($pst) || $pst === 'near';
}

/**
 * ปุ่มลบรายการจาก catalog บนหน้าสรุป
 *
 * @param array<string,mixed> $item
 * @return void
 */
function key_parts_order_echo_remove_button(array $item): void
{
    if (!function_exists('ui_icon_html')) {
        require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';
    }
    $k = (string) ($item['key'] ?? '');
    $label = (string) ($item['label'] ?? '');
    $tip = 'เอารายการนี้ออกจากหน้าสรุป' . ($label !== '' ? ' · ' . $label : '');
    ?>
<button type="button" class="kpo-card-remove kpo-part-remove-icon btn-line btn-sm"
    data-kpo-remove-key="<?= e($k) ?>"
    data-kpo-remove-label="<?= e($label) ?>"
    title="<?= e($tip) ?>"
    aria-label="<?= e($tip) ?>"><?= ui_icon_html('trash', 15, 'btn-svg') ?></button>
    <?php
}

/**
 * บรรทัด Rev / รหัส / คำเตือน map คลัง
 *
 * @param array<string,mixed> $item
 * @return void
 */
function key_parts_order_echo_part_rev_line(array $item): void
{
    ?>
<div class="pc-code kpo-part-rev">
    <?= e((string) ($item['rev_label'] ?? '')) ?>
    <?php if (trim((string) ($item['code'] ?? '')) !== ''): ?>
    · <?= e((string) $item['code']) ?>
    <?php elseif (empty($item['resolved'])): ?>
    · <span class="text-warn">ยังไม่พบในคลัง inventory</span>
    <?php endif; ?>
</div>
    <?php
}

/**
 * ชื่ออะไหล่ — คลิกเปิด stock_report ถ้ามี inv_report_url
 *
 * @param array<string,mixed> $item
 * @param string              $titleClass class หลักของหัวข้อ (kpo-part-title|kpo-card-title)
 * @return void
 */
function key_parts_order_echo_part_title_html(array $item, string $titleClass = 'kpo-part-title'): void
{
    $label = (string) ($item['label'] ?? '');
    $url = trim((string) ($item['inv_report_url'] ?? ''));
    $titleClass = trim($titleClass) !== '' ? trim($titleClass) : 'kpo-part-title';

    if ($url !== '') {
        ?>
<a class="<?= e($titleClass) ?> kpo-part-title-link"
   href="<?= e($url) ?>"
   target="_blank"
   rel="noopener noreferrer"
   title="เปิด stock_report ทุกปีที่มีข้อมูล"><?= e($label) ?></a>
        <?php

        return;
    }
    ?>
<div class="<?= e($titleClass) ?>"><?= e($label) ?></div>
    <?php
}

/**
 * ส่วนคงเหลือ / ขั้นต่ำ / ป้ายสถานะ / ลิงก์สั่งซื้อ
 *
 * @param array<string,mixed> $item
 * @param array{label:string,tone:string} $meta จาก key_parts_order_card_status_meta
 * @return void
 */
function key_parts_order_echo_part_stock_block(array $item, array $meta): void
{
    $unit = (string) ($item['unit'] ?? 'ชิ้น');
    if (($item['stock'] ?? null) === null) {
        echo '<span class="muted">ไม่มีรหัส map กับคลัง</span>';
        echo '<span class="stock-status-tag ' . e((string) ($meta['tone'] ?? '')) . '">' . e((string) ($meta['label'] ?? '')) . '</span>';

        return;
    }
    ?>
<span>คงเหลือ <b class="pc-qty"><?= e(formatNumber($item['stock'])) ?></b> <?= e($unit) ?></span>
<span>ขั้นต่ำ <b class="pc-qty"><?= e(formatNumber($item['min'])) ?></b></span>
<span class="stock-status-tag <?= e((string) ($meta['tone'] ?? '')) ?>"><?= e((string) ($meta['label'] ?? '')) ?></span>
    <?php
    if (key_parts_order_show_buy_link($item)):
        $plink = (string) ($item['purchase_link'] ?? '');
        ?>
<a class="detail-link pc-buy kpo-card-buy" href="<?= e($plink) ?>" target="_blank" rel="noopener noreferrer">สั่งซื้อ ↗</a>
    <?php endif;
}

/**
 * คอลัมน์อะไหล่ในตารางรายเดือน (UX-T1 / T2 หรือโหมด preview)
 *
 * @param array<string,mixed> $item
 * @param string              $layout t1|t2
 * @param array<string,mixed> $flowCtx filter + months + in_by_month + year สำหรับสรุปรวมปี (T2)
 * @return void
 */
function key_parts_order_echo_part_column(array $item, string $layout, array $flowCtx = []): void
{
    $meta = key_parts_order_card_status_meta($item);
    $layout = $layout === 't2' ? 't2' : 't1';

    if ($layout === 't2') {
        ?>
<div class="kpo-part-cell-t2">
    <div class="kpo-part-head">
        <div class="kpo-part-head-text">
            <?php key_parts_order_echo_part_title_html($item); ?>
            <?php key_parts_order_echo_part_rev_line($item); ?>
        </div>
        <?php key_parts_order_echo_remove_button($item); ?>
    </div>
    <div class="kpo-part-meta">
        <div class="kpo-part-row-grid">
            <span class="kpo-part-row-label">สต็อก</span>
            <div class="kpo-part-stock-row">
                <?php key_parts_order_echo_part_stock_block($item, $meta); ?>
            </div>
        </div>
        <?php
        $monthsByMo = is_array($flowCtx['months'] ?? null) ? $flowCtx['months'] : [];
        $inByMo = is_array($flowCtx['in_by_month'] ?? null) ? $flowCtx['in_by_month'] : [];
        $filter = (string) ($flowCtx['filter'] ?? 'all');
        $yearCe = max(2000, (int) ($flowCtx['year'] ?? (int) date('Y')));
        $avgMonths = is_array($flowCtx['avg_months'] ?? null)
            ? $flowCtx['avg_months']
            : key_parts_order_default_avg_months($yearCe);
        if ($monthsByMo !== []):
            key_parts_order_echo_part_year_flow_summary($filter, $monthsByMo, $inByMo, $yearCe, $avgMonths);
        endif;
        ?>
    </div>
</div>
        <?php

        return;
    }
    ?>
<div class="kpo-part-cell">
    <?php key_parts_order_echo_part_title_html($item); ?>
    <?php key_parts_order_echo_part_rev_line($item); ?>
    <div class="kpo-part-stats">
        <?php key_parts_order_echo_part_stock_block($item, $meta); ?>
    </div>
    <div class="kpo-part-actions">
        <?php key_parts_order_echo_remove_button($item); ?>
    </div>
</div>
    <?php
}

/**
 * สองคอลัมน์ชื่อ + สต็อก (UX-T3 preview)
 *
 * @param array<string,mixed> $item
 * @return void
 */
function key_parts_order_echo_part_columns_t3(array $item): void
{
    $meta = key_parts_order_card_status_meta($item);
    ?>
<td class="kpo-part-name">
    <?php key_parts_order_echo_part_title_html($item); ?>
    <?php key_parts_order_echo_part_rev_line($item); ?>
</td>
<td class="kpo-part-stock">
    <div class="kpo-stock-block">
        <div class="kpo-part-stats">
            <?php key_parts_order_echo_part_stock_block($item, $meta); ?>
        </div>
        <div class="kpo-part-actions">
            <?php key_parts_order_echo_remove_button($item); ?>
        </div>
    </div>
</td>
    <?php
}

function key_parts_order_filter_movements(array $movements, string $filter): array
{
    $filter = key_parts_order_normalize_filter($filter);
    if ($filter === 'all') {
        return $movements;
    }

    $out = [];
    foreach ($movements as $mv) {
        $flow = (string) ($mv['flow'] ?? 'out');
        if ($filter === 'in') {
            if ($flow === 'in') {
                $out[] = $mv;
            }
            continue;
        }
        if ($filter === 'out') {
            if ($flow === 'out') {
                $out[] = $mv;
            }
            continue;
        }
        if ($filter === 'prod' || $filter === 'repair' || $filter === 'other') {
            if ($flow === 'out' && (string) ($mv['bucket'] ?? '') === $filter) {
                $out[] = $mv;
            }
        }
    }

    return $out;
}

/**
 * map คอลัมน์ products ใน inventory (รองรับ schema มาตรฐาน code/name และ legacy product_code/product_name)
 *
 * @param PDO $db
 * @return array{select:string,code_col:string,name_col:string}|null
 */
function key_parts_order_products_schema(PDO $db): ?array
{
    static $schema = null;
    static $done = false;
    if ($done) {
        return $schema;
    }
    $done = true;

    try {
        $have = [];
        foreach ($db->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $have[(string) $c['Field']] = true;
        }
        if (isset($have['code'], $have['name'])) {
            $unitExpr = isset($have['unit']) ? 'unit' : "'ชิ้น'";
            $qtyExpr = isset($have['quantity']) ? 'quantity' : '0';
            $minExpr = isset($have['min_stock']) ? 'min_stock' : '0';
            $linkExpr = isset($have['purchase_link']) ? 'purchase_link' : "''";
            $supExpr = isset($have['supplier']) ? 'supplier' : "''";
            return $schema = [
                'select'   => "SELECT id, code, name, {$unitExpr} AS unit, {$qtyExpr} AS quantity,"
                    . " {$minExpr} AS min_stock, {$linkExpr} AS purchase_link, {$supExpr} AS supplier",
                'code_col' => 'code',
                'name_col' => 'name',
            ];
        }
        if (isset($have['product_code'], $have['product_name'])) {
            $unitExpr = isset($have['unit']) ? 'unit' : "'ชิ้น'";
            $qtyExpr = isset($have['quantity']) ? 'quantity' : '0';
            if (isset($have['min_stock'])) {
                $minExpr = 'min_stock';
            } elseif (isset($have['minimum_stock'])) {
                $minExpr = 'minimum_stock';
            } else {
                $minExpr = '0';
            }
            $linkExpr = isset($have['purchase_link']) ? 'purchase_link' : "''";
            $supExpr = isset($have['supplier']) ? 'supplier' : "''";
            return $schema = [
                'select'   => "SELECT id, product_code AS code, product_name AS name, {$unitExpr} AS unit,"
                    . " {$qtyExpr} AS quantity, {$minExpr} AS min_stock, {$linkExpr} AS purchase_link, {$supExpr} AS supplier",
                'code_col' => 'product_code',
                'name_col' => 'product_name',
            ];
        }
    } catch (Throwable $e) {
        error_log('[key_parts_order_products_schema] ' . $e->getMessage());
    }

    return $schema = null;
}

/**
 * ค้นหาแถว products ใน inventory ตาม config หนึ่งรายการ
 *
 * @param array{key:string,label:string,rev_label:string,code_candidates:array<int,string>,name_patterns:array<int,string>} $def
 * @return array<string,mixed>|null
 */
function key_parts_order_resolve_one_product(array $def): ?array
{
    try {
        $db = getDB();
    } catch (Throwable $e) {
        error_log('[key_parts_order_resolve_one_product] ' . $e->getMessage());
        return null;
    }

    $sch = key_parts_order_products_schema($db);
    if ($sch === null) {
        return null;
    }

    $codes = array_values(array_filter(array_map('trim', $def['code_candidates'] ?? [])));
    if ($codes) {
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $codeCol = $sch['code_col'];
        try {
            $st = $db->prepare(
                $sch['select'] . " FROM products WHERE TRIM({$codeCol}) IN ($ph) ORDER BY id DESC LIMIT 1"
            );
            $st->execute($codes);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            error_log('[key_parts_order_resolve_one_product codes] ' . $e->getMessage());
        }
    }

    $nameCol = $sch['name_col'];
    foreach ($def['name_patterns'] ?? [] as $pat) {
        $pat = trim((string) $pat);
        if ($pat === '') {
            continue;
        }
        try {
            $st = $db->prepare(
                $sch['select'] . " FROM products WHERE {$nameCol} LIKE ? ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$pat]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            error_log('[key_parts_order_resolve_one_product name] ' . $e->getMessage());
        }
    }

    return null;
}

/**
 * โหลด mode จาก part_movements เมื่อ stock_out ผูก movement ไว้ (ใช้จำแนก bucket เท่านั้น)
 *
 * @param int[] $movementIds
 * @return array<int,string> movement_id => mode
 */
function key_parts_order_production_modes_by_ids(array $movementIds): array
{
    $movementIds = array_values(array_unique(array_filter(array_map('intval', $movementIds))));
    $movementIds = array_values(array_filter($movementIds, static function ($id) {
        return $id > 0;
    }));
    if (!$movementIds) {
        return [];
    }
    try {
        $prod = production_db();
        $ph = implode(',', array_fill(0, count($movementIds), '?'));
        $st = $prod->prepare("SELECT id, mode FROM part_movements WHERE id IN ($ph)");
        $st->execute($movementIds);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['id']] = (string) ($r['mode'] ?? '');
        }
        return $map;
    } catch (Throwable $e) {
        error_log('[key_parts_order_production_modes_by_ids] ' . $e->getMessage());
        return [];
    }
}

/**
 * ข้อความ mode สำหรับจำแนก bucket — ใช้ mode production ถ้ามี ไม่งั้น note จาก inventory
 *
 * @param string $note
 * @param string|null $prodMode
 * @return string
 */
function key_parts_order_row_mode(string $note, ?string $prodMode): string
{
    $prodMode = trim((string) $prodMode);
    if ($prodMode !== '') {
        return $prodMode;
    }
    return trim($note);
}

/**
 * รวม config + DB + สต็อก inventory เป็นรายการพร้อมแสดง
 *
 * @return array<int,array<string,mixed>>
 */
function key_parts_order_resolve_items(): array
{
    $inv = parts_inventory_db();
    $items = [];
    $codes = [];
    foreach (key_parts_order_catalog_def() as $def) {
        $logPart = trim((string) ($def['inv_log_part'] ?? ''));
        $invRow = ($inv && $logPart !== '') ? key_parts_order_inv_part_row($inv, $logPart) : null;

        $techRow = key_parts_order_resolve_one_product($def);
        $code = $techRow ? trim((string) ($techRow['code'] ?? '')) : '';
        if ($code !== '') {
            $codes[$code] = true;
        }

        $stock = null;
        $min = 0.0;
        $invPurchaseStatus = '';
        $invPartImage = '';
        $invReportUrl = $logPart !== '' ? key_parts_order_stock_report_url($logPart) : '';
        if ($invRow) {
            $stock = key_parts_order_row_qty($invRow, ['part_amount', 'part_instock', 'part_balance', 'part_qty']);
            $minVal = key_parts_order_row_qty($invRow, ['part_min_alert', 'part_min', 'part_minimum', 'minimum_stock', 'min_stock']);
            if ($minVal !== null) {
                $min = $minVal;
            }
            $invPurchaseStatus = trim((string) ($invRow['part_status_purchase'] ?? ''));
            $invPartImage = trim((string) ($invRow['part_image'] ?? ''));
        }
        if ($stock === null && $techRow) {
            $stock = qty_norm($techRow['quantity'] ?? 0);
            $min = qty_norm($techRow['min_stock'] ?? 0);
        }

        $displayName = $invRow ? (string) ($invRow['part_name'] ?? $logPart) : ($techRow ? (string) $techRow['name'] : (string) $def['label']);
        $unit = 'ชิ้น';
        if ($invRow && isset($invRow['part_unit']) && trim((string) $invRow['part_unit']) !== '') {
            $unit = trim((string) $invRow['part_unit']);
        } elseif ($techRow) {
            $unit = (string) ($techRow['unit'] ?: 'ชิ้น');
        }

        $imageRel = trim((string) ($def['image_rel'] ?? ''));
        $purchaseLink = $techRow ? (string) ($techRow['purchase_link'] ?? '') : '';
        if ($purchaseLink === '' && $invRow) {
            $purchaseLink = trim((string) ($invRow['part_link'] ?? ''));
        }

        $items[] = [
            'key'                 => (string) $def['key'],
            'label'               => (string) $def['label'],
            'rev_label'           => (string) $def['rev_label'],
            'inv_log_part'        => $logPart,
            'inv_part_id'         => $invRow ? (int) ($invRow['part_id'] ?? 0) : 0,
            'inv_report_url'      => $invReportUrl,
            'inv_purchase_status' => $invPurchaseStatus,
            'product_id'          => $techRow ? (int) $techRow['id'] : 0,
            'name'                => $displayName,
            'code'                => $code,
            'unit'                => $unit,
            'resolved'            => $logPart !== '' && ($invRow !== null || $techRow !== null),
            'stock'               => $stock,
            'min'                 => $min,
            'purchase_link'       => $purchaseLink,
            'supplier'            => $techRow ? trim((string) ($techRow['supplier'] ?? '')) : '',
            'app_id'              => $techRow ? (int) $techRow['id'] : 0,
            'part_code'           => '',
            'icon_path'           => '',
            'image_url'           => key_parts_order_resolve_image_url($invPartImage, $imageRel, (string) $def['key']),
        ];
    }

    $labels = production_part_labels_by_stock_codes(array_keys($codes));
    foreach ($items as &$it) {
        $code = (string) ($it['code'] ?? '');
        if ($code !== '' && isset($labels[$code])) {
            $it['part_code'] = (string) ($labels[$code]['part_code'] ?? '');
            $it['icon_path'] = (string) ($labels[$code]['icon_path'] ?? '');
        }
        $onHand = $it['stock'];
        $min = qty_norm($it['min'] ?? 0);
        $it['status'] = $onHand === null ? 'ok' : stock_status_key($onHand, $min);
    }
    unset($it);

    return $items;
}

/**
 * ไฟล์ cache ตามปี (อยู่นอก web root)
 *
 * @param int $year ค.ศ.
 * @return string
 */
function key_parts_order_cache_file(int $year): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'key_parts_order';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $sig = key_parts_order_catalog_cache_sig();
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', 'kpo|log_stock|inout|' . $year . '|' . $sig) . '.json';
}

/**
 * โหลด aggregate พร้อม cache 90 วินาที
 *
 * @param int $year ค.ศ.
 * @return array<string,mixed>
 */
function key_parts_order_load_cached(int $year): array
{
    $file = key_parts_order_cache_file($year);
    if (is_file($file) && (time() - (int) filemtime($file)) < 90) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data;
            }
        }
    }
    $data = key_parts_order_load($year);
    if (!empty($data['ok']) && is_dir(dirname($file))) {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $data;
}

/**
 * โหลดยอดเบิก out ทั้งปี แล้ว aggregate ตาม part / เดือน / bucket
 *
 * @param int $year ค.ศ.
 * @return array{
 *   ok:bool,
 *   error:string,
 *   year:int,
 *   items:array<int,array<string,mixed>>,
 *   by_part:array<string,array<int,array{prod:float,repair:float,other:float}>>,
 *   year_part:array<string,array{prod:float,repair:float,other:float}>,
 *   totals:array{prod:float,repair:float,other:float}
 * }
 */
function key_parts_order_load(int $year): array
{
    $bounds = key_parts_order_year_bounds($year);
    $items = key_parts_order_resolve_items();

    $emptyMonths = [];
    for ($m = 1; $m <= 12; $m++) {
        $emptyMonths[$m] = key_parts_order_empty_buckets();
    }

    $byPart = [];
    $inByPart = [];
    $yearPart = [];
    $logPartToKey = [];
    $emptyInMonths = [];
    for ($m = 1; $m <= 12; $m++) {
        $emptyInMonths[$m] = 0.0;
    }
    foreach ($items as $it) {
        $k = (string) $it['key'];
        $byPart[$k] = $emptyMonths;
        $inByPart[$k] = $emptyInMonths;
        $yearPart[$k] = key_parts_order_empty_buckets();
        $lp = trim((string) ($it['inv_log_part'] ?? ''));
        if ($lp !== '') {
            $logPartToKey[$lp] = $k;
        }
    }

    $totals = key_parts_order_empty_buckets();
    $yearInTotal = 0.0;
    $invError = '';

    $base = [
        'ok'            => true,
        'error'         => '',
        'inv_error'     => '',
        'inv_connected' => false,
        'log_rows'      => 0,
        'year'          => $bounds['year'],
        'items'         => $items,
        'by_part'       => $byPart,
        'in_by_part'    => $inByPart,
        'year_part'     => $yearPart,
        'year_in_total' => $yearInTotal,
        'totals'        => $totals,
    ];

    if (!$logPartToKey) {
        return $base;
    }

    $inv = parts_inventory_db();
    if (!$inv) {
        $invError = parts_inventory_db_error() ?: 'เชื่อมต่อระบบ inventory ไม่ได้';
        $base['inv_error'] = $invError;
        return $base;
    }
    $base['inv_connected'] = true;

    $logParts = array_keys($logPartToKey);
    $placeholders = implode(',', array_fill(0, count($logParts), '?'));
    $types = str_repeat('s', count($logParts));
    $colList = key_parts_order_log_stock_columns($inv);
    $sql = "SELECT {$colList}
            FROM log_stock
            WHERE log_part IN ($placeholders)";
    $st = $inv->prepare($sql);
    if (!$st) {
        error_log('[key_parts_order_load] prepare log_stock failed');
        $base['ok'] = false;
        $base['error'] = 'อ่าน log_stock จาก inventory ไม่สำเร็จ';
        return $base;
    }
    $st->bind_param($types, ...$logParts);
    if (!$st->execute()) {
        $st->close();
        $base['ok'] = false;
        $base['error'] = 'อ่าน log_stock จาก inventory ไม่สำเร็จ';
        return $base;
    }
    $res = $st->get_result();
    $rows = [];
    $preIds = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
        $pid = trim((string) ($r['pre_id'] ?? ''));
        if ($pid !== '') {
            $preIds[$pid] = true;
        }
    }
    $st->close();
    $base['log_rows'] = count($rows);

    $preMap = key_parts_order_inv_pre_map($inv, array_keys($preIds));

    foreach ($rows as $r) {
        $lp = trim((string) ($r['log_part'] ?? ''));
        if ($lp === '' || !isset($logPartToKey[$lp])) {
            continue;
        }
        $ts = key_parts_order_log_ts((string) ($r['log_date'] ?? ''));
        if ($ts === null || (int) date('Y', $ts) !== $bounds['year']) {
            continue;
        }
        $mo = (int) date('n', $ts);
        if ($mo < 1 || $mo > 12) {
            continue;
        }
        $key = $logPartToKey[$lp];
        $preId = trim((string) ($r['pre_id'] ?? ''));
        $preMeta = $preId !== '' ? ($preMap[$preId] ?? null) : null;
        $logType = (string) ($r['log_type'] ?? '');
        $preType = is_array($preMeta) ? (string) ($preMeta['pre_type'] ?? '') : '';
        $flow = key_parts_order_inv_flow($logType, $preType);
        $qty = qty_norm($r['log_amount'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        if ($flow === 'in') {
            $inByPart[$key][$mo] = qty_norm(($inByPart[$key][$mo] ?? 0) + $qty);
            $yearInTotal += $qty;
            continue;
        }
        $bucket = key_parts_order_inv_bucket($preType, $logType);
        $byPart[$key][$mo] = key_parts_order_add_bucket($byPart[$key][$mo], $bucket, $qty);
        $yearPart[$key] = key_parts_order_add_bucket($yearPart[$key], $bucket, $qty);
        $totals = key_parts_order_add_bucket($totals, $bucket, $qty);
    }

    $base['by_part'] = $byPart;
    $base['in_by_part'] = $inByPart;
    $base['year_part'] = $yearPart;
    $base['year_in_total'] = $yearInTotal;
    $base['totals'] = $totals;
    return $base;
}

/**
 * รายการเบิกจาก log_stock สำหรับ drill-down หนึ่งอะไหล่หนึ่งเดือน
 *
 * @param string $logPart ชื่อ log_part ใน inventory
 * @param int    $year     ค.ศ.
 * @param int    $month    1–12
 * @param int    $partId   รหัส part ใน inventory (สำหรับ join pre_stock)
 * @return array<int,array<string,mixed>>
 */
function key_parts_order_movements(string $logPart, int $year, int $month, int $partId = 0): array
{
    $logPart = trim($logPart);
    if ($logPart === '' || $month < 1 || $month > 12) {
        return [];
    }

    $inv = parts_inventory_db();
    if (!$inv) {
        return [];
    }

    $colList = key_parts_order_log_stock_columns($inv);
    [$likeA, $likeB] = key_parts_order_log_month_like_patterns($year, $month);
    // กรองเดือนใน SQL — ห้าม LIMIT ทั้ง part แล้วค่อยกรองเดือน (จะหลุดรายการเก่าในเดือนนั้น)
    $sql = "SELECT {$colList}
            FROM log_stock
            WHERE log_part = ?
              AND (log_date LIKE ? OR log_date LIKE ?)
            ORDER BY log_date DESC
            LIMIT 3000";
    $st = $inv->prepare($sql);
    if (!$st) {
        return [];
    }
    $st->bind_param('sss', $logPart, $likeA, $likeB);
    if (!$st->execute()) {
        $st->close();
        return [];
    }
    $res = $st->get_result();
    $rows = [];
    $preIds = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $ts = key_parts_order_log_ts((string) ($r['log_date'] ?? ''));
        if ($ts === null || (int) date('Y', $ts) !== $year || (int) date('n', $ts) !== $month) {
            continue;
        }
        $rows[] = $r;
        $pid = trim((string) ($r['pre_id'] ?? ''));
        if ($pid !== '') {
            $preIds[$pid] = true;
        }
    }
    $st->close();

    $preMap = key_parts_order_inv_pre_map($inv, array_keys($preIds));
    $preLines = key_parts_order_inv_pre_lines_map($inv, array_keys($preIds), $partId);
    $out = [];
    foreach ($rows as $r) {
        $preId = trim((string) ($r['pre_id'] ?? ''));
        $logType = (string) ($r['log_type'] ?? '');
        $preMeta = $preId !== '' ? ($preMap[$preId] ?? null) : null;
        $preLine = $preId !== '' ? ($preLines[$preId] ?? null) : null;
        $preType = is_array($preLine) ? (string) ($preLine['pre_type'] ?? '') : (is_array($preMeta) ? (string) ($preMeta['pre_type'] ?? '') : '');
        $flow = key_parts_order_inv_flow($logType, $preType);
        $modeLabel = $logType !== '' ? $logType : ($preType !== '' ? $preType : '—');
        $ts = key_parts_order_log_ts((string) ($r['log_date'] ?? ''));
        $logBy = trim((string) ($r['log_by'] ?? ''));
        $logDateRaw = trim((string) ($r['log_date'] ?? ''));
        $preStatus = is_array($preLine) ? (string) ($preLine['pre_status'] ?? '') : '';
        $logProduct = trim((string) ($r['log_product'] ?? ''));
        $logRemarks = trim((string) ($r['log_remarks'] ?? ''));
        $out[] = [
            'moved_at'   => $ts !== null ? date('Y-m-d H:i:s', $ts) : $logDateRaw,
            'flow'       => $flow,
            'mode'       => $modeLabel,
            'bucket'     => $flow === 'out' ? key_parts_order_inv_bucket($preType, $logType) : '',
            'qty'        => qty_norm($r['log_amount'] ?? 0),
            'made_by'    => key_parts_order_requester_name(is_array($preMeta) ? $preMeta : null, $logBy),
            'doc_no'     => $preId,
            'pre_product'=> is_array($preMeta) ? trim((string) ($preMeta['pre_product'] ?? '')) : '',
            'remark'     => key_parts_order_movement_note(
                $flow,
                $logProduct,
                $logRemarks,
                (string) ($r['log_vendor'] ?? ''),
                is_array($preMeta) ? $preMeta : null
            ),
            '_maint_ctx' => [
                'log_product'      => $logProduct,
                'log_remarks'      => $logRemarks,
                'pre_product'      => is_array($preMeta) ? trim((string) ($preMeta['pre_product'] ?? '')) : '',
                'pre_remarks_line' => is_array($preLine) ? trim((string) ($preLine['pre_remarks'] ?? '')) : '',
                'pre_remarks_doc'  => is_array($preMeta) ? trim((string) ($preMeta['pre_remarks'] ?? '')) : '',
                'pre_date'         => is_array($preLine) ? trim((string) ($preLine['pre_date'] ?? '')) : '',
            ],
            'pickup'     => [
                'has_pre_line'       => is_array($preLine),
                'pre_status'         => $preStatus,
                'pre_status_label'   => key_parts_order_pre_status_label($preStatus),
                'is_pickup_confirmed'=> $preStatus === 'pickup',
                'pre_date'           => is_array($preLine) ? (string) ($preLine['pre_date'] ?? '') : '',
                'pre_amount'         => is_array($preLine) ? qty_norm($preLine['pre_amount'] ?? 0) : null,
                'requester'          => is_array($preLine) && trim((string) ($preLine['pre_by'] ?? '')) !== ''
                    ? trim((string) $preLine['pre_by'])
                    : (is_array($preMeta) ? trim((string) ($preMeta['pre_by'] ?? '')) : ''),
                'log_by'             => $logBy,
                'log_date_raw'       => $logDateRaw,
            ],
        ];
    }

    $out = key_parts_order_enrich_movements_maintenance($out);

    return key_parts_order_enrich_movements_production($out, $partId);
}

/**
 * ปัดยอดเบิกขึ้นเป็นทวีคูณของ 5 (เช่น 51 → 55)
 *
 * @param float $qty ยอดจริง
 * @return float
 */
function key_parts_order_ceil_to_five(float $qty): float
{
    $qty = qty_norm($qty);
    if ($qty <= 0) {
        return 0.0;
    }

    return (float) (ceil($qty / 5) * 5);
}

/**
 * HTML บรรทัดเบิกจริง ≈ ปัด↑5 (บรรทัดเดียว · ไม่มี ≈ ถ้ายอดเป็นทวีคูณของ 5 แล้ว)
 *
 * @param float $outQty ยอดเบิกจริง
 * @return string
 */
function key_parts_order_out_flow_line_html(float $outQty): string
{
    $outQty = qty_norm($outQty);
    if ($outQty <= 0) {
        return '';
    }
    $rounded = key_parts_order_ceil_to_five($outQty);
    if ($rounded <= $outQty) {
        return '<span class="kpo-cell-flow-out">' . e(formatNumber($outQty)) . '</span>';
    }
    $tip = 'ปัดขึ้นเป็น 5: ' . formatNumber($outQty) . ' → ' . formatNumber($rounded);

    return '<span class="kpo-cell-flow-out-line" title="' . e($tip) . '">'
        . '<span class="kpo-cell-flow-out">' . e(formatNumber($outQty)) . '</span>'
        . '<span class="kpo-cell-flow-approx" aria-hidden="true">≈</span>'
        . '<span class="kpo-cell-flow-round">' . e(formatNumber($rounded)) . '</span>'
        . '</span>';
}

/**
 * รวมยอดเบิก/รับทั้ง 12 เดือนของหนึ่งอะไหล่ ตาม filter ชิป
 *
 * @param string $filter
 * @param array<int,array{prod:float,repair:float,other:float}> $monthsByMo
 * @param array<int,float> $inByMo
 * @return array{out:float,out_rounded:float,in:float,prod:float,repair:float,other:float}
 */
function key_parts_order_sum_year_flow(string $filter, array $monthsByMo, array $inByMo, ?array $onlyMonths = null): array
{
    $filter = key_parts_order_normalize_filter($filter);
    $outSum = 0.0;
    $outRoundedSum = 0.0;
    $inSum = 0.0;
    $prodSum = 0.0;
    $repairSum = 0.0;
    $otherSum = 0.0;

    $monthList = $onlyMonths ?? range(1, 12);
    foreach ($monthList as $mo) {
        $mo = (int) $mo;
        if ($mo < 1 || $mo > 12) {
            continue;
        }
        $b = $monthsByMo[$mo] ?? key_parts_order_empty_buckets();
        $inQty = qty_norm($inByMo[$mo] ?? 0);
        $cell = key_parts_order_cell_for_filter($filter, $b, $inQty);

        if ($filter === 'in') {
            $inSum += qty_norm($cell['total'] ?? 0);
            continue;
        }

        $outMo = qty_norm($cell['total'] ?? 0);
        $outSum += $outMo;
        if ($outMo > 0) {
            $outRoundedSum += key_parts_order_ceil_to_five($outMo);
        }
        if ($filter === 'all' || $filter === 'out') {
            $prodSum += qty_norm($b['prod'] ?? 0);
            $repairSum += qty_norm($b['repair'] ?? 0);
            $otherSum += qty_norm($b['other'] ?? 0);
        } elseif ($filter === 'prod') {
            $prodSum += $outMo;
        } elseif ($filter === 'repair') {
            $repairSum += $outMo;
        } elseif ($filter === 'other') {
            $otherSum += $outMo;
        }
        if ($filter === 'all' && !empty($cell['showIn'])) {
            $inSum += qty_norm($cell['inQty'] ?? 0);
        }
    }

    return [
        'out'         => $outSum,
        'out_rounded' => $outRoundedSum,
        'in'          => $inSum,
        'prod'        => $prodSum,
        'repair'      => $repairSum,
        'other'       => $otherSum,
    ];
}

/**
 * จำนวนเดือนที่ใช้หารเฉลี่ยรายปี — ปีปัจจุบันใช้ถึงเดือนนี้ ปีที่แล้วใช้ 12
 *
 * @param int $yearCe ปี ค.ศ. ที่กำลังดูในตาราง
 * @return int 1–12
 */
function key_parts_order_year_avg_month_divisor(int $yearCe): int
{
    $yearCe = (int) $yearCe;
    $nowYear = (int) date('Y');
    if ($yearCe >= $nowYear) {
        return max(1, min(12, (int) date('n')));
    }

    return 12;
}

/**
 * เดือนเริ่มต้นสำหรับเฉลี่ย — ปีปัจจุบันถึงเดือนนี้ ปีเก่าครบ 12
 *
 * @param int $yearCe
 * @return int[]
 */
function key_parts_order_default_avg_months(int $yearCe): array
{
    $n = key_parts_order_year_avg_month_divisor($yearCe);

    return range(1, $n);
}

/**
 * แปลงพารามิเตอร์ avg_mos จาก URL เป็นรายการเดือน 1–12
 *
 * @param string|null $raw  เช่น "1,2,11" หรือ null = ค่าเริ่มต้น
 * @param int         $yearCe
 * @return int[]
 */
function key_parts_order_parse_avg_months(?string $raw, int $yearCe): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return key_parts_order_default_avg_months($yearCe);
    }
    $mos = [];
    foreach (preg_split('/[^0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $m = (int) $part;
        if ($m >= 1 && $m <= 12) {
            $mos[$m] = $m;
        }
    }
    if (!$mos) {
        return key_parts_order_default_avg_months($yearCe);
    }
    ksort($mos);

    return array_values($mos);
}

/**
 * สตริง avg_mos สำหรับ query string
 *
 * @param int[] $months
 * @return string
 */
function key_parts_order_avg_mos_param(array $months): string
{
    $months = array_values(array_unique(array_filter(array_map('intval', $months), static function ($m) {
        return $m >= 1 && $m <= 12;
    })));
    sort($months);

    return implode(',', $months);
}

/**
 * ค่า avg_mos ที่ใส่ URL (ว่าง = ใช้ค่าเริ่มต้น)
 *
 * @param int[] $months
 * @param int   $yearCe
 * @return string|null
 */
function key_parts_order_avg_mos_url_value(array $months, int $yearCe): ?string
{
    $def = key_parts_order_default_avg_months($yearCe);
    if ($months === $def) {
        return null;
    }
    $s = key_parts_order_avg_mos_param($months);
    return $s !== '' ? $s : null;
}

/**
 * สลับเดือนในรายการเฉลี่ย (อย่างน้อย 1 เดือน)
 *
 * @param int[] $current
 * @param int   $mo
 * @return int[]
 */
function key_parts_order_toggle_avg_month(array $current, int $mo): array
{
    $mo = max(1, min(12, $mo));
    $set = [];
    foreach ($current as $m) {
        $m = (int) $m;
        if ($m >= 1 && $m <= 12) {
            $set[$m] = $m;
        }
    }
    if (isset($set[$mo]) && count($set) <= 1) {
        return array_values($set);
    }
    if (isset($set[$mo])) {
        unset($set[$mo]);
    } else {
        $set[$mo] = $mo;
    }
    ksort($set);

    return array_values($set);
}

/**
 * ป้ายชื่อเดือนที่เลือก (สำหรับ tooltip)
 *
 * @param int[] $months
 * @return string
 */
function key_parts_order_avg_months_label(array $months): string
{
    $labels = [];
    foreach ($months as $m) {
        $labels[] = KEY_PARTS_ORDER_MONTHS[(int) $m] ?? (string) $m;
    }

    return implode(', ', $labels);
}

/**
 * HTML ค่าเฉลี่ยเบิก/เดือน + สัดส่วนใช้ผลิต·ซ่อม (บรรทัดเดียวกับยอดรวมปี)
 *
 * @param string $filter
 * @param array<int,array{prod:float,repair:float,other:float}> $monthsByMo
 * @param array<int,float> $inByMo
 * @param int[]            $avgMonths เดือนที่รวมยอดก่อนหารเฉลี่ย
 * @return string
 */
function key_parts_order_year_out_subline_extras_html(
    string $filter,
    array $monthsByMo,
    array $inByMo,
    array $avgMonths
): string {
    $filter = key_parts_order_normalize_filter($filter);
    $avgMonths = array_values(array_unique(array_filter(array_map('intval', $avgMonths), static function ($m) {
        return $m >= 1 && $m <= 12;
    })));
    sort($avgMonths);
    if (!$avgMonths) {
        return '';
    }
    $totals = key_parts_order_sum_year_flow($filter, $monthsByMo, $inByMo, $avgMonths);
    $out = qty_norm($totals['out'] ?? 0);
    if ($out <= 0 || $filter === 'in') {
        return '';
    }

    $moDiv = count($avgMonths);
    $avgTip = ' title="' . e('เฉลี่ยจาก ' . count($avgMonths) . ' เดือน: '
        . key_parts_order_avg_months_label($avgMonths) . ' (ยอดรวมเฉพาะเดือนที่เลือก)') . '"';

    $parts = [
        '<span class="kpo-year-stat"' . $avgTip . '>เฉลี่ย ' . e(formatNumber((int) round($out / $moDiv))) . '/เดือน</span>',
    ];

    if ($filter === 'all' || $filter === 'out') {
        $prod = qty_norm($totals['prod'] ?? 0);
        $repair = qty_norm($totals['repair'] ?? 0);
        if ($prod > 0) {
            $parts[] = '<span class="kpo-year-stat kpo-bucket-prod"' . $avgTip . '>' . e(KEY_PARTS_ORDER_BUCKETS['prod']) . ' '
                . e(formatNumber((int) round($prod / $moDiv))) . '/เดือน</span>';
        }
        if ($repair > 0) {
            $parts[] = '<span class="kpo-year-stat kpo-bucket-repair"' . $avgTip . '>' . e(KEY_PARTS_ORDER_BUCKETS['repair']) . ' '
                . e(formatNumber((int) round($repair / $moDiv))) . '/เดือน</span>';
        }
    }

    $inner = '';
    foreach ($parts as $i => $part) {
        if ($i > 0) {
            $inner .= '<span class="kpo-year-stat-sep" aria-hidden="true">·</span>';
        }
        $inner .= $part;
    }

    return '<span class="kpo-year-subline-extra">' . $inner . '</span>';
}

/**
 * HTML สรุปเบิกรวมปี — จริง ≈ ผลรวมปัด↑5 รายเดือน (แสดงคู่เสมอเมื่อมียอดเบิก)
 *
 * @param float $actual รวมเบิกจริงทั้งปี
 * @param float $roundedMonthlySum ผลรวมปัด↑5 ของแต่ละเดือน
 * @return string
 */
function key_parts_order_out_year_flow_html(float $actual, float $roundedMonthlySum): string
{
    $actual = qty_norm($actual);
    if ($actual <= 0) {
        return '';
    }
    $rounded = qty_norm($roundedMonthlySum);
    if ($rounded < $actual) {
        $rounded = $actual;
    }
    $tip = 'รวมปี · เบิกจริง ' . formatNumber($actual)
        . ' · รวมปัด↑5 รายเดือน ' . formatNumber($rounded);

    return '<span class="kpo-cell-flow-out-line" title="' . e($tip) . '">'
        . '<span class="kpo-cell-flow-out">' . e(formatNumber($actual)) . '</span>'
        . '<span class="kpo-cell-flow-approx" aria-hidden="true">≈</span>'
        . '<span class="kpo-cell-flow-round">' . e(formatNumber($rounded)) . '</span>'
        . '</span>';
}

/**
 * HTML สรุปรวมปี เบิก (จริง+ปัด) / รับ ใต้ข้อมูลสต็อกในคอลัมน์อะไหล่
 *
 * @param string $filter
 * @param array<int,array{prod:float,repair:float,other:float}> $monthsByMo
 * @param array<int,float> $inByMo
 * @param int    $yearCe    ปี ค.ศ. (รวมปีเต็ม 12 เดือน)
 * @param int[]  $avgMonths เดือนสำหรับคำนวณเฉลี่ย
 * @return void
 */
function key_parts_order_echo_part_year_flow_summary(
    string $filter,
    array $monthsByMo,
    array $inByMo,
    int $yearCe,
    array $avgMonths
): void {
    $totals = key_parts_order_sum_year_flow($filter, $monthsByMo, $inByMo);
    if ($totals['out'] <= 0 && $totals['in'] <= 0) {
        return;
    }
    ?>
<div class="kpo-part-row-grid kpo-part-year-grid">
    <span class="kpo-part-row-label">รวมปี</span>
    <div class="kpo-part-year-body">
        <?php if ($totals['out'] > 0): ?>
        <div class="kpo-year-subline kpo-year-subline-out">
            <span class="kpo-meta-sub">เบิก</span>
            <?= key_parts_order_out_year_flow_html($totals['out'], $totals['out_rounded'] ?? 0.0) ?>
            <?= key_parts_order_year_out_subline_extras_html($filter, $monthsByMo, $inByMo, $avgMonths) ?>
        </div>
        <?php endif; ?>
        <?php if ($totals['in'] > 0): ?>
        <div class="kpo-year-subline">
            <span class="kpo-meta-sub">รับ</span>
            <b class="kpo-cell-flow-in"><?= e(formatNumber($totals['in'])) ?></b>
        </div>
        <?php endif; ?>
    </div>
</div>
    <?php
}

/**
 * HTML สรุปเบิก/รับในเซลล์เดือน (ตัวเลขอย่างเดียว ไม่ใช้แถบสี)
 *
 * @param string $filter ชิป filter ปัจจุบัน
 * @param array{has:bool,total:float,b:array,inQty:float,showIn:bool} $cell จาก key_parts_order_cell_for_filter()
 * @return string
 */
function key_parts_order_cell_flow_html(string $filter, array $cell): string
{
    $filter = key_parts_order_normalize_filter($filter);
    $out = qty_norm($cell['total'] ?? 0);
    $in = qty_norm($cell['inQty'] ?? 0);
    $showIn = !empty($cell['showIn']);
    $parts = [];

    if ($filter === 'in') {
        if ($out > 0) {
            $parts[] = '<span class="kpo-cell-flow-in">' . e(formatNumber($out)) . '</span>';
        }
    } else {
        if ($out > 0) {
            $parts[] = key_parts_order_out_flow_line_html($out);
        }
        if ($showIn && $in > 0) {
            $parts[] = '<span class="kpo-cell-flow-in">' . e(formatNumber($in)) . '</span>';
        }
    }

    if ($parts === []) {
        return '';
    }

    return '<div class="kpo-cell-flow">' . implode('', $parts) . '</div>';
}

/**
 * HTML แถบ stacked 3 ส่วนในเซลล์เดือน
 *
 * @param array{prod:float,repair:float,other:float} $b
 * @return string
 */
function key_parts_order_stack_html(array $b): string
{
    $total = key_parts_order_bucket_total($b);
    if ($total <= 0) {
        return '<div class="kpo-stack kpo-stack-empty" aria-hidden="true"></div>';
    }
    $p = ($b['prod'] / $total) * 100;
    $r = ($b['repair'] / $total) * 100;
    $o = ($b['other'] / $total) * 100;
    return '<div class="kpo-stack" aria-hidden="true">'
        . '<span class="kpo-seg kpo-seg-prod" style="width:' . e(sprintf('%.2F%%', $p)) . '"></span>'
        . '<span class="kpo-seg kpo-seg-repair" style="width:' . e(sprintf('%.2F%%', $r)) . '"></span>'
        . '<span class="kpo-seg kpo-seg-other" style="width:' . e(sprintf('%.2F%%', $o)) . '"></span>'
        . '</div>';
}

/**
 * HTML สรุปยอด 3 บรรทัดจิ๋วในเซลล์
 *
 * @param array{prod:float,repair:float,other:float} $b
 * @return string
 */
function key_parts_order_cell_lines_html(array $b): string
{
    $bits = [];
    foreach (KEY_PARTS_ORDER_BUCKETS as $key => $label) {
        $q = qty_norm($b[$key] ?? 0);
        if ($q <= 0) {
            continue;
        }
        $cls = 'kpo-bucket-' . $key;
        $bits[] = '<span class="' . e($cls) . '">' . e($label) . ' ' . e(formatNumber($q)) . '</span>';
    }
    if (!$bits) {
        return '';
    }
    $lines = '';
    foreach ($bits as $bit) {
        $lines .= '<span class="kpo-cell-line">' . $bit . '</span>';
    }
    return '<div class="kpo-cell-lines kpo-cell-lines-stack">' . $lines . '</div>';
}
