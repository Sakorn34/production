<?php
/**
 * includes/purchase_check.php — สรุปยอดเบิกผลิตเทียบสต็อกก่อนสั่งซื้อ
 *
 * นับจาก part_movements ฝั่ง production ที่เป็นเบิกผลิตจริง (ผลิต / BOM / ชุดอะไหล่)
 * แล้ว map ไปคงเหลือวันนี้ใน biton_tech_parts ผ่าน stock_code
 *
 * ไม่รวม MA และเบิกซ่อม — คนละคำถามกับงานสั่งของผลิต
 */

// ─ ค่าคงที่ ─────────────────────────────────────────────────────────────────

/** @var array<string,string> คีย์ช่วงเวลาที่หน้าอนุญาต — อย่าใช้เลขล้วนเป็น key (PHP จะแปลงเป็น int) */
const PURCHASE_CHECK_PERIODS = [
    'month' => 'เดือนนี้',
    '30d'   => '30 วัน',
    '90d'   => '90 วัน',
];

/** @var array<string,string> คีย์มุมมอง */
const PURCHASE_CHECK_TABS = [
    'models'    => 'แยกตามรุ่นเครื่อง',
    'suppliers' => 'แยกตามผู้จำหน่าย',
];

/** @var array<string,string> คีย์กรองสถานะสต็อก */
const PURCHASE_CHECK_STATUS = [
    'all'   => 'ทั้งหมด',
    'order' => 'ควรสั่ง',
    'near'  => 'ใกล้ขั้นต่ำ',
];

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ตรวจว่า mode เป็นเบิกผลิตหรือไม่
 *
 * @param string $mode
 * @return bool
 */
function purchase_check_is_prod_mode(string $mode): bool
{
    $mode = trim($mode);
    if ($mode === '' || $mode === 'MA' || strpos($mode, 'ซ่อม') !== false) {
        return false;
    }
    if (preg_match('/: Out\s*$/i', $mode)) {
        return true;
    }
    return strpos($mode, 'ผลิต') !== false
        || strpos($mode, 'BOM') !== false
        || strpos($mode, 'ชุดอะไหล่') !== false;
}

/**
 * วันเริ่มต้นของช่วงที่เลือก (00:00 ของวันนั้น)
 *
 * @param string $period 30d|90d|month
 * @return array{key:string,from:string,label:string,from_label:string}
 */
function purchase_check_period_bounds(string $period): array
{
    if (!isset(PURCHASE_CHECK_PERIODS[$period])) {
        $period = '90d';
    }
    if ($period === 'month') {
        $fromTs = strtotime(date('Y-m-01 00:00:00'));
    } elseif ($period === '30d') {
        $fromTs = strtotime('today -29 days');
    } else {
        $fromTs = strtotime('today -89 days');
    }
    if ($fromTs === false) {
        $fromTs = time();
    }
    return [
        'key'        => $period,
        'from'       => date('Y-m-d 00:00:00', $fromTs),
        'label'      => PURCHASE_CHECK_PERIODS[$period],
        'from_label' => purchase_check_thai_date(date('Y-m-d', $fromTs)),
    ];
}

/**
 * วันที่แบบสั้นภาษาไทย (พ.ศ.)
 *
 * @param string $ymd
 * @return string
 */
function purchase_check_thai_date(string $ymd): string
{
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    return ((int) date('j', $ts)) . ' ' . $months[(int) date('n', $ts)] . ' ' . ((int) date('Y', $ts) + 543);
}

/**
 * URL หน้านี้คงพารามิเตอร์ที่ยังใช้
 *
 * @param array<string,string|int|null> $over
 * @return string
 */
function purchase_check_url(array $over = []): string
{
    $period = (string) ($over['period'] ?? ($_GET['period'] ?? '90d'));
    if ($period === '90') {
        $period = '90d';
    } elseif ($period === '30') {
        $period = '30d';
    }
    $tab = (string) ($over['tab'] ?? ($_GET['tab'] ?? 'models'));
    $st = (string) ($over['st'] ?? ($_GET['st'] ?? 'all'));
    if (array_key_exists('period', $over) && $over['period'] === null) {
        $period = '90d';
    }
    if (!isset(PURCHASE_CHECK_PERIODS[$period])) {
        $period = '90d';
    }
    if (!isset(PURCHASE_CHECK_TABS[$tab])) {
        $tab = 'models';
    }
    if (!isset(PURCHASE_CHECK_STATUS[$st])) {
        $st = 'all';
    }
    $w = (string) ($over['w'] ?? ($_GET['w'] ?? ''));
    $qs = ['period' => $period];
    if ($tab !== 'models') {
        $qs['tab'] = $tab;
    }
    if ($st !== 'all') {
        $qs['st'] = $st;
    }
    // ตัวกรองระดับของส่วน "คลังวันนี้" — คีย์ที่ไม่รู้จักถูกทิ้ง
    if ($w !== '' && isset(PURCHASE_CHECK_WATCH[$w])) {
        $qs['w'] = $w;
    }
    return url('/pages/purchase-check.php?' . http_build_query($qs));
}

/**
 * อันดับความเร่งด่วนของสถานะ — น้อย = ต้องดูก่อน
 *
 * @param string $key
 * @return int
 */
function purchase_check_status_rank(string $key): int
{
    static $rank = ['out' => 0, 'critical' => 1, 'low' => 2, 'near' => 3, 'ok' => 4];
    return $rank[$key] ?? 5;
}

/**
 * เงื่อนไข mode เบิกผลิตใน SQL — ใช้ร่วมกันทั้งยอดต่ออะไหล่และนับเครื่องต่อรุ่น
 *
 * @return string
 */
function purchase_check_mode_sql(): string
{
    return "(
            pm.mode LIKE '%ผลิต%'
            OR pm.mode LIKE '%BOM%'
            OR pm.mode LIKE '%ชุดอะไหล่%'
            OR pm.mode LIKE '%: Out%'
          )
          AND pm.mode NOT LIKE '%ซ่อม%'
          AND pm.mode <> 'MA'";
}

/**
 * ตรวจว่ามี index ตามวันที่เบิกหรือยัง — ห้าม ALTER ใน request (บนเซิร์ฟเวอร์จริงจะล็อกตาราง)
 *
 * @param PDO $prod
 * @return bool
 */
function purchase_check_has_moved_index(PDO $prod): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    require_once dirname(__DIR__, 2) . '/shared/schema_once.php';
    if (schema_once_skip('pmove_dir_moved_idx')) {
        return $has = true;
    }
    $has = false;
    try {
        $st = $prod->query("SHOW INDEX FROM part_movements WHERE Key_name = 'idx_pmove_dir_moved'");
        $has = $st && (bool) $st->fetch();
        if ($has) {
            schema_once_mark('pmove_dir_moved_idx');
        }
    } catch (Throwable $e) {
        error_log('[purchase_check_has_moved_index] ' . $e->getMessage());
    }
    return $has;
}

/**
 * แยก id จาก GROUP_CONCAT เป็นชุดไม่ซ้ำ
 *
 * @param string $csv
 * @return array<int,true>
 */
function purchase_check_parse_ids(string $csv): array
{
    $out = [];
    if ($csv === '') {
        return $out;
    }
    foreach (explode(',', $csv) as $bit) {
        $id = (int) $bit;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return $out;
}

/**
 * ไฟล์ cache สรุปหน้าเช็คสั่งซื้อ (อยู่นอก web root)
 *
 * @param string $from
 * @return string
 */
function purchase_check_cache_file(string $from): string
{
    $dir = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'purchase_check';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', 'pc|' . $from) . '.json';
}

/**
 * โหลดสรุปพร้อม cache สั้น — สลับแท็บไม่ต้องยิง SQL ซ้ำ
 *
 * @param string $from
 * @return array<string,mixed>
 */
function purchase_check_load_cached(string $from): array
{
    $file = purchase_check_cache_file($from);
    if (is_file($file) && (time() - (int) filemtime($file)) < 90) {
        $raw = @file_get_contents($file);
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['ok'])) {
                return $data;
            }
        }
    }
    $data = purchase_check_load($from);
    if (!empty($data['ok']) && is_dir(dirname($file))) {
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $data;
}

/**
 * โหลดยอดเบิกผลิตในช่วง แล้วจัดกลุ่มตามอะไหล่และรุ่น
 *
 * รวมยอดใน SQL ต่ออะไหล่+รุ่น — query เดียว ไม่ ALTER ตารางตอนเปิดหน้า
 *
 * @param string $from วันเริ่มต้น Y-m-d H:i:s
 * @return array{
 *   ok:bool,
 *   error:string,
 *   parts:array<int,array<string,mixed>>,
 *   models:array<int,array<string,mixed>>,
 *   suppliers:array<int,array<string,mixed>>,
 *   counts:array{models:int,parts:int,suppliers:int,order:int,near:int}
 * }
 */
function purchase_check_load(string $from): array
{
    $empty = [
        'ok' => true, 'error' => '',
        'parts' => [], 'models' => [], 'suppliers' => [],
        'counts' => ['models' => 0, 'parts' => 0, 'suppliers' => 0, 'order' => 0, 'near' => 0],
    ];
    try {
        $prod = production_db();
        $prod->exec('SET SESSION group_concat_max_len = 65535');
        $hint = purchase_check_has_moved_index($prod) ? ' FORCE INDEX (idx_pmove_dir_moved)' : '';
        $sql = 'SELECT
                    pm.part_id,
                    COALESCE(a.product_id, 0) AS product_id,
                    SUM(pm.qty) AS qty,
                    COUNT(DISTINCT CASE WHEN pm.ref_asset_id > 0 THEN pm.ref_asset_id END) AS machines,
                    GROUP_CONCAT(DISTINCT IF(pm.ref_asset_id > 0, pm.ref_asset_id, NULL)) AS asset_ids,
                    MAX(pt.name) AS part_name,
                    MAX(pt.part_code) AS part_code,
                    MAX(pt.stock_code) AS stock_code,
                    MAX(pt.unit) AS unit,
                    MAX(pt.icon_path) AS icon_path,
                    MAX(COALESCE(NULLIF(TRIM(pr.name), \'\'),
                        IF(COALESCE(a.product_id, 0) > 0, \'ไม่ระบุชื่อรุ่น\', \'\'))) AS model_name
                FROM part_movements pm' . $hint . '
                INNER JOIN parts pt ON pt.id = pm.part_id
                LEFT JOIN assets a ON a.id = pm.ref_asset_id
                LEFT JOIN products pr ON pr.id = a.product_id
                WHERE pm.direction = \'out\'
                  AND pm.moved_at >= ?
                  AND ' . purchase_check_mode_sql() . '
                GROUP BY pm.part_id, COALESCE(a.product_id, 0)';
        $st = $prod->prepare($sql);
        $st->execute([$from]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[purchase_check_load] ' . $e->getMessage());
        $empty['ok'] = false;
        $empty['error'] = 'โหลดยอดเบิกผลิตไม่สำเร็จ';
        return $empty;
    }

    $codes = [];
    $productIds = [];
    foreach ($rows as $r) {
        $code = trim((string) ($r['stock_code'] ?? ''));
        if ($code !== '') {
            $codes[$code] = true;
        }
        $pid = (int) ($r['product_id'] ?? 0);
        if ($pid > 0) {
            $productIds[$pid] = true;
        }
    }

    $stock = purchase_check_stock_by_codes(array_keys($codes));
    $bom = purchase_check_bom_map(array_keys($productIds));

    $parts = [];
    $models = [];
    foreach ($rows as $r) {
        $partId = (int) $r['part_id'];
        $code = trim((string) ($r['stock_code'] ?? ''));
        $qty = qty_norm($r['qty'] ?? 0);
        $modelName = (string) $r['model_name'];
        $productId = (int) $r['product_id'];
        $rowMachines = (int) ($r['machines'] ?? 0);

        if (!isset($parts[$partId])) {
            $tech = $code !== '' ? ($stock[$code] ?? null) : null;
            $onHand = $tech !== null ? qty_norm($tech['quantity'] ?? 0) : null;
            $min = $tech !== null ? qty_norm($tech['min_stock'] ?? 0) : 0;
            $stKey = $onHand === null ? 'ok' : stock_status_key($onHand, $min);
            $parts[$partId] = [
                'part_id'       => $partId,
                'code'          => $code,
                'name'          => (string) $r['part_name'],
                'part_code'     => (string) ($r['part_code'] ?? ''),
                'unit'          => (string) ($r['unit'] ?: 'ชิ้น'),
                'icon_path'     => (string) ($r['icon_path'] ?? ''),
                'app_id'        => (int) ($tech['id'] ?? 0),
                'stock'         => $onHand,
                'min'           => $min,
                'purchase_link' => (string) ($tech['purchase_link'] ?? ''),
                'supplier'      => trim((string) ($tech['supplier'] ?? '')),
                'status'        => $stKey,
                'used'          => 0.0,
                'models'        => [],
            ];
        }
        $parts[$partId]['used'] = qty_norm($parts[$partId]['used'] + $qty);
        // เบิกไม่ผูกเครื่องนับในยอดอะไหล่ แต่ไม่โชว์เป็นรุ่น — ไม่ใช่รุ่นสินค้า
        if ($productId <= 0) {
            continue;
        }
        $parts[$partId]['models'][$productId] = [
            'product_id' => $productId,
            'name'       => $modelName,
            'qty'        => $qty,
            'machines'   => $rowMachines,
        ];

        if (!isset($models[$productId])) {
            $models[$productId] = [
                'product_id' => $productId,
                'name'       => $modelName,
                'assets'     => [],
                'parts'      => [],
            ];
        }
        foreach (purchase_check_parse_ids((string) ($r['asset_ids'] ?? '')) as $aid => $_) {
            $models[$productId]['assets'][$aid] = true;
        }
        $models[$productId]['parts'][$partId] = [
            'part_id' => $partId,
            'code'    => $code,
            'name'    => (string) $r['part_name'],
            'unit'    => (string) ($r['unit'] ?: 'ชิ้น'),
            'qty'     => $qty,
            'bom'     => $bom[$productId][$partId] ?? null,
        ];
    }

    foreach ($parts as &$p) {
        $list = [];
        foreach ($p['models'] as $m) {
            $pid = (int) $m['product_id'];
            $list[] = [
                'product_id' => $pid,
                'name'       => $m['name'],
                'qty'        => $m['qty'],
                'machines'   => (int) ($m['machines'] ?? 0),
                'bom'        => $pid > 0 ? ($bom[$pid][$p['part_id']] ?? null) : null,
            ];
        }
        usort($list, static function ($a, $b) {
            return $b['qty'] <=> $a['qty'];
        });
        $p['models'] = $list;
    }
    unset($p);

    $partList = array_values($parts);
    usort($partList, static function ($a, $b) {
        $ra = purchase_check_status_rank((string) $a['status']);
        $rb = purchase_check_status_rank((string) $b['status']);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return $b['used'] <=> $a['used'];
    });

    $modelList = [];
    foreach ($models as $m) {
        $plist = array_values($m['parts']);
        usort($plist, static function ($a, $b) {
            return $b['qty'] <=> $a['qty'];
        });
        $modelList[] = [
            'product_id' => $m['product_id'],
            'name'       => $m['name'],
            'machines'   => count($m['assets'] ?? []),
            'parts'      => $plist,
        ];
    }
    usort($modelList, static function ($a, $b) {
        if ($a['machines'] !== $b['machines']) {
            return $b['machines'] <=> $a['machines'];
        }
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    $order = 0;
    $near = 0;
    foreach ($partList as $p) {
        if (stock_status_is_reorder((string) $p['status'])) {
            $order++;
        } elseif ($p['status'] === 'near') {
            $near++;
        }
    }

    $supplierList = purchase_check_group_suppliers($partList);

    return [
        'ok'        => true,
        'error'     => '',
        'parts'     => $partList,
        'models'    => $modelList,
        'suppliers' => $supplierList,
        'counts'    => [
            'models'    => count($modelList),
            'parts'     => count($partList),
            'suppliers' => count($supplierList),
            'order'     => $order,
            'near'      => $near,
        ],
    ];
}

/**
 * จัดกลุ่มอะไหล่ตามผู้จำหน่าย — ของที่ควรสั่งอยู่บนในแต่ละร้าน
 *
 * @param array<int,array<string,mixed>> $parts
 * @return array<int,array<string,mixed>>
 */
function purchase_check_group_suppliers(array $parts): array
{
    $groups = [];
    foreach ($parts as $p) {
        $name = trim((string) ($p['supplier'] ?? ''));
        $key = $name !== '' ? mb_strtolower($name, 'UTF-8') : '';
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'name'  => $name !== '' ? $name : 'ไม่ระบุผู้จำหน่าย',
                'empty' => $name === '',
                'order' => 0,
                'near'  => 0,
                'parts' => [],
            ];
        }
        $groups[$key]['parts'][] = $p;
        if (stock_status_is_reorder((string) $p['status'])) {
            $groups[$key]['order']++;
        } elseif ($p['status'] === 'near') {
            $groups[$key]['near']++;
        }
    }

    $list = array_values($groups);
    foreach ($list as &$g) {
        usort($g['parts'], static function ($a, $b) {
            $ra = purchase_check_status_rank((string) $a['status']);
            $rb = purchase_check_status_rank((string) $b['status']);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $b['used'] <=> $a['used'];
        });
    }
    unset($g);

    usort($list, static function ($a, $b) {
        if ($a['empty'] !== $b['empty']) {
            return $a['empty'] <=> $b['empty'];
        }
        if ($a['order'] !== $b['order']) {
            return $b['order'] <=> $a['order'];
        }
        if (count($a['parts']) !== count($b['parts'])) {
            return count($b['parts']) <=> count($a['parts']);
        }
        return strcasecmp((string) $a['name'], (string) $b['name']);
    });
    return $list;
}

/**
 * คงเหลือวันนี้จากคลังช่าง ตามรหัสอะไหล่
 *
 * @param string[] $codes
 * @return array<string,array<string,mixed>>
 */
function purchase_check_stock_by_codes(array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
    if (!$codes) {
        return [];
    }
    try {
        $db = getDB();
        $ph = implode(',', array_fill(0, count($codes), '?'));
        $st = $db->prepare(
            "SELECT id, code, name, quantity, min_stock, unit, purchase_link, supplier
             FROM products WHERE code IN ($ph)"
        );
        $st->execute($codes);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[trim((string) $r['code'])] = $r;
        }
        return $map;
    } catch (Throwable $e) {
        error_log('[purchase_check_stock_by_codes] ' . $e->getMessage());
        return [];
    }
}

/**
 * ชุดเบิกมาตรฐานต่อเครื่อง (qty_per_unit) ของรุ่นที่เจอในช่วง
 *
 * @param int[] $productIds
 * @return array<int,array<int,float>> product_id => part_id => qty
 */
function purchase_check_bom_map(array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if (!$ids) {
        return [];
    }
    try {
        $prod = production_db();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $prod->prepare(
            "SELECT product_id, part_id, qty_per_unit FROM bom_items WHERE product_id IN ($ph)"
        );
        $st->execute($ids);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int) $r['product_id'];
            $map[$pid][(int) $r['part_id']] = qty_norm($r['qty_per_unit'] ?? 0);
        }
        return $map;
    } catch (Throwable $e) {
        error_log('[purchase_check_bom_map] ' . $e->getMessage());
        return [];
    }
}

/**
 * สรุปสูตรต่อเครื่องจากรายการรุ่น — ค่าเดียวหรือหลายค่าถ้าแต่ละรุ่นไม่เท่ากัน
 *
 * @param array<int,array<string,mixed>> $models
 * @return string|null
 */
function purchase_check_bom_display(array $models): ?string
{
    $uniq = [];
    foreach ($models as $m) {
        if (!array_key_exists('bom', $m) || $m['bom'] === null) {
            continue;
        }
        $q = qty_norm($m['bom']);
        $uniq[sprintf('%.4F', $q)] = $q;
    }
    if (!$uniq) {
        return null;
    }
    $vals = array_values($uniq);
    sort($vals, SORT_NUMERIC);
    return implode(' · ', array_map('formatNumber', $vals));
}

/** @var array<string,string> สถานะที่ส่วน "คลังวันนี้" แสดง (เรียงจากหนักไปเบา) */
const PURCHASE_CHECK_WATCH = [
    'out'      => 'หมดแล้ว',
    'critical' => 'วิกฤต',
    'low'      => 'ควรสั่งเพิ่ม',
    'near'     => 'ใกล้ขั้นต่ำ',
];

/**
 * อะไหล่ทั้งคลังที่หมด / ต่ำกว่า / ใกล้ขั้นต่ำ ณ วันนี้ — ไม่ขึ้นกับว่าถูกเบิกผลิตในช่วงไหน
 *
 * ตารางเบิกผลิตด้านล่างแสดงเฉพาะอะไหล่ที่ถูกเบิกผลิตในช่วงที่เลือก อะไหล่ที่หมดแต่ไม่มีใครเบิก
 * (หรือเบิกไปใช้ MA / ซ่อม) จึงไม่เคยโผล่ — ส่วนนี้ตอบคำถาม "ตอนนี้อะไรหมด/ใกล้หมด" ตรง ๆ
 * ยอดใช้นับจากใบเบิกทุกประเภทในคลังช่าง (stock_out) เพื่อคิดว่าของที่เหลือพอใช้อีกกี่วัน
 *
 * @param string $from วันเริ่มช่วงที่คิดยอดใช้ (Y-m-d H:i:s)
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,counts:array<string,int>,days:float}
 */
function purchase_check_watch(string $from): array
{
    $counts = array_fill_keys(array_keys(PURCHASE_CHECK_WATCH), 0);
    $days = max(1.0, (time() - (int) strtotime($from)) / 86400);
    $out = ['ok' => true, 'error' => '', 'rows' => [], 'counts' => $counts, 'days' => $days];
    try {
        // แอปทะเบียนเครื่องเรียกฟังก์ชันนี้ด้วย (ป้ายตัวเลขบนเมนู) ซึ่งไม่มี getDB() ของแอปอะไหล่
        $db = function_exists('getDB') ? getDB() : tech_parts_sync_db();
        $rows = [];
        $st = $db->query(
            'SELECT id, code, name, unit, quantity, min_stock, supplier, purchase_link
             FROM products WHERE is_active = 1'
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = stock_status_key($r['quantity'], $r['min_stock']);
            if (!isset(PURCHASE_CHECK_WATCH[$key])) {
                continue;
            }
            $rows[(int) $r['id']] = [
                'app_id'        => (int) $r['id'],
                'code'          => trim((string) $r['code']),
                'name'          => (string) $r['name'],
                'unit'          => (string) ($r['unit'] ?: 'ชิ้น'),
                'stock'         => qty_norm($r['quantity']),
                'min'           => qty_norm($r['min_stock']),
                'supplier'      => trim((string) $r['supplier']),
                'purchase_link' => (string) $r['purchase_link'],
                'status'        => $key,
                'used'          => 0.0,
                'days_left'     => null,
                'icon_path'     => '',
            ];
        }
        if ($rows) {
            $ph = implode(',', array_fill(0, count($rows), '?'));
            $use = $db->prepare(
                "SELECT soi.product_id, SUM(soi.quantity) AS q
                 FROM stock_out_items soi JOIN stock_out so ON so.id = soi.stock_out_id
                 WHERE so.created_at >= ? AND soi.product_id IN ($ph)
                 GROUP BY soi.product_id"
            );
            $use->execute(array_merge([$from], array_keys($rows)));
            foreach ($use->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $rows[(int) $u['product_id']]['used'] = qty_norm($u['q']);
            }
            // รูปอะไหล่อยู่ฝั่งทะเบียนผลิต (parts.icon_path) จับคู่ด้วยรหัสสต็อก
            $codes = array_values(array_filter(array_unique(array_column($rows, 'code'))));
            if ($codes && function_exists('production_db')) {
                $cph = implode(',', array_fill(0, count($codes), '?'));
                $ic = production_db()->prepare(
                    "SELECT TRIM(stock_code) AS sc, MAX(icon_path) AS icon_path FROM parts
                     WHERE TRIM(stock_code) IN ($cph) AND icon_path IS NOT NULL AND icon_path <> ''
                     GROUP BY TRIM(stock_code)"
                );
                $ic->execute($codes);
                $icons = [];
                foreach ($ic->fetchAll(PDO::FETCH_ASSOC) as $x) {
                    $icons[(string) $x['sc']] = (string) $x['icon_path'];
                }
                foreach ($rows as &$r) {
                    $r['icon_path'] = $icons[$r['code']] ?? '';
                }
                unset($r);
            }
        }
        foreach ($rows as &$r) {
            $perDay = $r['used'] / $days;
            if ($r['stock'] > 0 && $perDay > 0) {
                $r['days_left'] = (int) round($r['stock'] / $perDay);
            }
            $out['counts'][$r['status']]++;
        }
        unset($r);
        $rank = array_flip(array_keys(PURCHASE_CHECK_WATCH));
        usort($rows, static function ($a, $b) use ($rank) {
            // ระดับเดียวกัน: ของที่ใช้หมดเร็วกว่าขึ้นก่อน แล้วค่อยเรียงตามคงเหลือเทียบขั้นต่ำ
            $da = $a['days_left'] ?? PHP_INT_MAX;
            $db = $b['days_left'] ?? PHP_INT_MAX;
            $ra = $a['min'] > 0 ? $a['stock'] / $a['min'] : 0;
            $rb = $b['min'] > 0 ? $b['stock'] / $b['min'] : 0;
            return [$rank[$a['status']], $da, $ra] <=> [$rank[$b['status']], $db, $rb];
        });
        $out['rows'] = $rows;
    } catch (Throwable $e) {
        error_log('[purchase_check_watch] ' . $e->getMessage());
        $out['ok'] = false;
        $out['error'] = 'อ่านคลังอะไหล่ไม่ได้';
    }
    return $out;
}

/**
 * ส่วน "คลังวันนี้" — การ์ดสรุป 4 ระดับ (กดกรอง) + ตารางเรียงจากหนักไปเบา
 *
 * @param array<string,mixed> $watch    ผลจาก purchase_check_watch()
 * @param string              $wIn      ระดับที่กรองอยู่ ('' = ทั้งหมด)
 * @param string              $periodIn ช่วงที่คิดยอดใช้
 * @return string HTML
 */
function purchase_check_watch_html(array $watch, string $wIn, string $periodIn): string
{
    $tones = [
        'out'      => ['var(--danger,#dc2626)', 'pc-w-danger'],
        'critical' => ['var(--danger,#dc2626)', 'pc-w-danger'],
        'low'      => ['var(--warning,#f59e0b)', 'pc-w-warn'],
        'near'     => ['var(--near,#ca8a04)', 'pc-w-near'],
    ];
    $total = array_sum($watch['counts']);
    $h = '<section class="pc-watch" id="stock-today">';
    $h .= '<div class="pc-watch-stats" role="group" aria-label="สถานะคลังวันนี้">';
    foreach (PURCHASE_CHECK_WATCH as $k => $label) {
        $on = $wIn === $k;
        $h .= '<a class="pc-stat pc-wstat' . ($on ? ' is-on' : '') . '" style="--pc-w:' . $tones[$k][0] . '"'
            . ' href="' . e(purchase_check_url(['w' => $on ? '' : $k])) . '#stock-today">'
            . '<span class="pc-lbl">' . e($label) . '</span>'
            . '<span class="pc-num">' . formatNumber($watch['counts'][$k]) . '</span></a>';
    }
    $h .= '</div>';

    $rows = $wIn !== '' ? array_values(array_filter($watch['rows'], static function ($r) use ($wIn) {
        return $r['status'] === $wIn;
    })) : $watch['rows'];

    $h .= '<div class="card pc-watch-card">';
    $h .= '<div class="pc-watch-head"><b>' . ($wIn !== ''
            ? e(PURCHASE_CHECK_WATCH[$wIn]) . ' ' . formatNumber(count($rows)) . ' รายการ'
              . ' <a href="' . e(purchase_check_url(['w' => ''])) . '#stock-today">ดูทั้งหมด</a>'
            : 'อะไหล่ที่ต้องดู ' . formatNumber($total) . ' รายการ')
        . '</b><span class="pc-watch-period">ยอดใช้คิดจาก <nav class="pc-seg pc-seg-sm" aria-label="ช่วงที่คิดยอดใช้">';
    foreach (PURCHASE_CHECK_PERIODS as $pk => $pl) {
        $pk = (string) $pk;
        $h .= '<a class="pc-seg-btn' . ($periodIn === $pk ? ' is-active' : '') . '" href="'
            . e(purchase_check_url(['period' => $pk])) . '#stock-today">' . e($pl) . '</a>';
    }
    $h .= '</nav></span></div>';

    if (!$watch['ok']) {
        $h .= '<p class="text-muted" style="color:var(--danger,#dc2626);padding:10px 14px;margin:0">' . e($watch['error']) . '</p>';
    } elseif (!$rows) {
        $h .= '<p class="text-muted" style="padding:10px 14px;margin:0">ไม่มีอะไหล่ที่หมดหรือใกล้ขั้นต่ำ</p>';
    } else {
        $h .= '<div class="table-wrap"><table class="pc-table pc-watch-table"><thead><tr>'
            . '<th>อะไหล่</th><th>คงเหลือ / ขั้นต่ำ</th><th class="text-right">ใช้ไป</th><th class="text-right">พอใช้อีก</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $k = $r['status'];
            $pct = $r['min'] > 0 ? min(100, round($r['stock'] / $r['min'] * 100)) : 0;
            $chip = '<span class="pc-wchip ' . $tones[$k][1] . '">' . e(PURCHASE_CHECK_WATCH[$k]) . '</span>';
            if ($r['stock'] <= 0) {
                $left = '<span class="pc-left is-out">หมดแล้ว</span>';
            } elseif ($r['days_left'] === null) {
                $left = '<span class="pc-left is-idle">ไม่มีการใช้ในช่วงนี้</span>';
            } else {
                $d = (int) $r['days_left'];
                $cls = $d <= 30 ? 'is-soon' : ($d <= 60 ? 'is-mid' : 'is-ok');
                $left = '<span class="pc-left ' . $cls . '">' . ($d > 365 ? 'เกิน 1 ปี' : '~' . formatNumber($d) . ' วัน') . '</span>';
            }
            $h .= '<tr>'
                . '<td>' . purchase_check_name_cell_html($r, true, $chip) . '</td>'
                . '<td><div class="pc-qty"><b>' . formatNumber($r['stock']) . '</b> <span class="text-muted">/ ' . formatNumber($r['min']) . ' ' . e($r['unit']) . '</span></div>'
                . '<div class="pc-wbar"><i style="width:' . $pct . '%;background:' . $tones[$k][0] . '"></i></div>'
                . '<div class="pc-left-m">' . $chip . ' ' . $left . '</div></td>'
                . '<td class="text-right pc-qty">' . ($r['used'] > 0 ? formatNumber($r['used']) : '<span class="text-muted">—</span>') . '</td>'
                . '<td class="text-right">' . $left . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '</div></section>';
    return $h;
}

/**
 * กรองรายการอะไหล่ตามสถานะที่เลือก
 *
 * @param array<int,array<string,mixed>> $parts
 * @param string                         $st
 * @return array<int,array<string,mixed>>
 */
function purchase_check_filter_parts(array $parts, string $st): array
{
    if ($st === 'order') {
        return array_values(array_filter($parts, static function ($p) {
            return stock_status_is_reorder((string) $p['status']);
        }));
    }
    if ($st === 'near') {
        return array_values(array_filter($parts, static function ($p) {
            return $p['status'] === 'near';
        }));
    }
    return $parts;
}

/**
 * thumb รูป หรือตัวอักษรย่อถ้าไม่มีไฟล์
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function purchase_check_thumb_html(array $part): string
{
    $name = (string) ($part['name'] ?? '');
    $code = (string) ($part['code'] ?? '');
    $path = (string) ($part['icon_path'] ?? '');
    if ($path !== '' && $code !== '') {
        $img = parts_img_link_tag($path, $name, $code);
        if ($img !== '') {
            return $img;
        }
    }
    $ini = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '?';
    return '<span class="pc-thumb" aria-hidden="true">' . e($ini) . '</span>';
}

/**
 * colgroup ร่วมของทุกตารางในหน้าเช็คสั่งซื้อ
 *
 * @return string HTML
 */
function purchase_check_colgroup_html(): string
{
    return '<colgroup>'
        . '<col class="pc-c-name">'
        . '<col class="pc-c-used">'
        . '<col class="pc-c-bom">'
        . '<col class="pc-c-stock">'
        . '<col class="pc-c-min">'
        . '<col class="pc-c-st">'
        . '</colgroup>';
}

/**
 * หัวตารางรูปแบบร่วม — อะไหล่มีรูปอยู่หน้าชื่อ
 *
 * @return string HTML
 */
function purchase_check_thead_html(): string
{
    return '<thead><tr>'
        . '<th>อะไหล่</th>'
        . '<th class="text-right">เบิกผลิต</th>'
        . '<th class="text-right">สูตรต่อเครื่อง</th>'
        . '<th class="text-right">คงเหลือ</th>'
        . '<th class="text-right">ขั้นต่ำ</th>'
        . '<th>สถานะ</th>'
        . '</tr></thead>';
}

/**
 * แถวหัวกลุ่มในตารางรวม (รุ่นเครื่อง / ผู้จำหน่าย)
 *
 * @param string $title ชื่อกลุ่ม
 * @param string $meta ข้อความขวา เช่น 17 เครื่อง · 1 อะไหล่
 * @param string $search ค่า data-pc-search
 * @param string $group คีย์ data-pc-group
 * @return string HTML
 */
function purchase_check_group_row_html(string $title, string $meta, string $search, string $group): string
{
    return '<tr class="pc-group" data-pc-group="' . e($group) . '" data-pc-search="' . e($search) . '">'
        . '<td colspan="6"><span>' . e($title) . '</span><em>' . e($meta) . '</em></td>'
        . '</tr>';
}

/**
 * ช่องชื่ออะไหล่ — รูปอยู่หน้าชื่อทุกตาราง
 *
 * @param array<string,mixed> $part
 * @param bool $withThumb
 * @param string $extra HTML ต่อท้ายในแถบชื่อ เช่น ปุ่มซ่อน-ขยายรุ่น
 * @return string HTML
 */
function purchase_check_name_cell_html(array $part, bool $withThumb = true, string $extra = ''): string
{
    $code = (string) ($part['code'] ?? '');
    $name = (string) ($part['name'] ?? '');
    $bits = array_filter([$code, (string) ($part['supplier'] ?? '')]);
    $html = '<div class="pc-partcell">';
    if ($withThumb) {
        $html .= purchase_check_thumb_html($part);
    }
    $html .= '<div class="pc-partname">';
    $html .= parts_name_profile_link($code, $name, 'detail-link', (int) ($part['app_id'] ?? 0));
    if ($bits) {
        $html .= '<div class="pc-code">' . e(implode(' · ', $bits)) . '</div>';
    }
    $html .= '</div>' . $extra . '</div>';
    return $html;
}

/**
 * ปุ่มซ่อน-ขยายรายการรุ่นเครื่องใต้แต่ละอะไหล่
 *
 * @param string $id คีย์กลุ่มรุ่น
 * @param int $count จำนวนรุ่น
 * @param bool $open เปิดรายการรุ่นไว้ตั้งแต่ต้น
 * @return string HTML
 */
function purchase_check_fold_btn_html(string $id, int $count, bool $open = false): string
{
    $label = formatNumber($count) . ' รุ่น';
    $verb = $open ? 'ซ่อนรุ่นเครื่อง ' : 'แสดงรุ่นเครื่อง ';
    return '<button type="button" class="pc-fold" data-pc-fold="' . e($id) . '"'
        . ' aria-expanded="' . ($open ? 'true' : 'false') . '"'
        . ' aria-label="' . e($verb . $label) . '">'
        . ui_icon_html('chevron-right', 14, 'pc-fold-ico')
        . '<span>' . e($label) . '</span>'
        . '</button>';
}

/**
 * ช่องสถานะและลิงก์สั่งซื้อเมื่อควรสั่งหรือใกล้ขั้นต่ำ
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function purchase_check_status_cell_html(array $part): string
{
    $st = (string) ($part['status'] ?? 'ok');
    $meta = stock_status_meta($st);
    $html = '<span class="stock-status-tag ' . e((string) $meta['tone']) . '">' . e((string) $meta['label']) . '</span>';
    $link = (string) ($part['purchase_link'] ?? '');
    if ($link !== '' && (stock_status_is_reorder($st) || $st === 'near')) {
        $html .= '<a class="detail-link pc-buy" href="' . e($link) . '" target="_blank" rel="noopener noreferrer">สั่งซื้อ</a>';
    }
    return $html;
}

/**
 * แถวข้อมูลรูปแบบร่วม 6 คอลัมน์
 *
 * @param array<string,mixed> $part
 * @param float|int|null $qty ยอดเบิกของแถวนี้
 * @param float|int|string|null $bom สูตรต่อเครื่อง หรือข้อความสรุปหลายรุ่น
 * @param array{search?:string,class?:string,group?:string,thumb?:bool,stock?:bool,model_name?:string,machines?:int,fold_id?:string,fold_count?:int,fold_open?:bool,models_of?:string,hidden?:bool} $opt
 * @return string HTML
 */
function purchase_check_part_row_html(array $part, $qty, $bom, array $opt = []): string
{
    $search = (string) ($opt['search'] ?? '');
    $class = trim((string) ($opt['class'] ?? ''));
    $group = (string) ($opt['group'] ?? '');
    $thumb = ($opt['thumb'] ?? true) === true;
    $showStock = ($opt['stock'] ?? true) === true;
    $modelName = trim((string) ($opt['model_name'] ?? ''));
    $foldId = (string) ($opt['fold_id'] ?? '');
    $foldCount = (int) ($opt['fold_count'] ?? 0);
    $foldOpen = !empty($opt['fold_open']);
    $modelsOf = (string) ($opt['models_of'] ?? '');
    $st = (string) ($part['status'] ?? 'ok');
    $stock = $part['stock'] ?? null;
    $min = $part['min'] ?? null;
    $unit = (string) ($part['unit'] ?? 'ชิ้น');

    $attrs = '';
    if ($search !== '') {
        $attrs .= ' data-pc-search="' . e($search) . '"';
    }
    if ($group !== '') {
        $attrs .= ' data-pc-group="' . e($group) . '"';
    }
    if ($foldId !== '' && $modelName === '') {
        $attrs .= ' data-pc-models="' . e($foldId) . '" data-pc-open="' . ($foldOpen ? '1' : '0') . '"';
    }
    if ($modelsOf !== '') {
        $attrs .= ' data-pc-models-of="' . e($modelsOf) . '"';
    }
    if (!empty($opt['hidden'])) {
        $attrs .= ' hidden';
    }
    if ($class !== '') {
        $attrs .= ' class="' . e($class) . '"';
    }

    if ($modelName !== '') {
        $mach = (int) ($opt['machines'] ?? 0);
        $nameHtml = '<div class="pc-partcell pc-partcell-more"><div class="pc-partname">'
            . e($modelName)
            . ($mach > 0 ? '<div class="pc-code">' . e(formatNumber($mach) . ' เครื่อง') . '</div>' : '')
            . '</div></div>';
    } else {
        $foldBtn = ($foldId !== '' && $foldCount > 0) ? purchase_check_fold_btn_html($foldId, $foldCount, $foldOpen) : '';
        $nameHtml = purchase_check_name_cell_html($part, $thumb, $foldBtn);
    }

    $qtyHtml = $qty === null
        ? '—'
        : '<b>' . formatNumber($qty) . '</b> <span class="text-muted">' . e($unit) . '</span>';
    if ($bom === null || $bom === '') {
        $bomHtml = '—';
    } elseif (is_string($bom)) {
        $bomHtml = e($bom);
    } else {
        $bomHtml = formatNumber($bom);
    }
    $stockHtml = '';
    $minHtml = '';
    $statusHtml = '';
    if ($showStock) {
        $stockHtml = $stock === null ? '—' : formatNumber($stock);
        $minHtml = $stock === null ? '—' : formatNumber($min);
        $statusHtml = purchase_check_status_cell_html($part);
    }
    $lowClass = $showStock && stock_status_is_reorder($st) ? ' qty-low' : '';

    return '<tr' . $attrs . '>'
        . '<td>' . $nameHtml . '</td>'
        . '<td class="text-right pc-qty">' . $qtyHtml . '</td>'
        . '<td class="text-right pc-qty">' . $bomHtml . '</td>'
        . '<td class="text-right pc-qty' . $lowClass . '">' . $stockHtml . '</td>'
        . '<td class="text-right pc-qty">' . $minHtml . '</td>'
        . '<td>' . $statusHtml . '</td>'
        . '</tr>';
}
