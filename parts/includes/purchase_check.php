<?php
/**
 * includes/purchase_check.php — เช็คสั่งซื้อ: อะไหล่ตามสูตรของแต่ละรุ่นที่ผลิต เทียบคงเหลือวันนี้
 *
 * รายการมาจากสูตรผลิต (biton_production.bom_items) ของรุ่นที่ยังเปิดใช้งาน
 * คงเหลือ / ขั้นต่ำ มาจากคลังช่าง (biton_tech_parts.products) จับคู่ด้วย parts.stock_code
 *
 * ไม่นับยอดเบิกหรือช่วงเวลาแล้ว — ประวัติการเบิกดูได้ที่หน้าโปรไฟล์อะไหล่แต่ละตัว
 * (เดิมหน้านี้แสดงเฉพาะอะไหล่ที่ถูกเบิกผลิตในช่วงที่เลือก อะไหล่ที่หมดแต่ไม่มีใครเบิกจึงไม่โผล่)
 */

/** @var array<string,string> คีย์กรองสถานะสต็อก */
const PURCHASE_CHECK_STATUS = [
    'all'   => 'ทั้งหมด',
    'order' => 'ควรสั่ง',
    'near'  => 'ใกล้ขั้นต่ำ',
];

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ตรวจว่า mode เป็นเบิกผลิตหรือไม่ (ใช้ในหน้าอะไหล่สั่งต่างประเทศ)
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
 * URL หน้านี้ — เหลือแค่ตัวกรองสถานะ
 *
 * @param string $st
 * @return string
 */
function purchase_check_url(string $st = 'all'): string
{
    if (!isset(PURCHASE_CHECK_STATUS[$st]) || $st === 'all') {
        return url('/pages/purchase-check.php');
    }
    return url('/pages/purchase-check.php?' . http_build_query(['st' => $st]));
}

/**
 * อันดับความเร่งด่วนของสถานะ — น้อย = ต้องดูก่อน
 *
 * @param string $key
 * @return int
 */
function purchase_check_status_rank(string $key): int
{
    static $rank = ['out' => 0, 'critical' => 1, 'low' => 2, 'near' => 3, 'ok' => 4, 'unmapped' => 5];
    return $rank[$key] ?? 9;
}

/**
 * คงเหลือวันนี้จากคลังช่าง ตามรหัสอะไหล่
 *
 * แอปทะเบียนเครื่องเรียกผ่านป้ายตัวเลขบนเมนู ซึ่งไม่มี getDB() ของแอปอะไหล่
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
    $db = function_exists('getDB') ? getDB() : tech_parts_sync_db();
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
}

// ─ Load ───────────────────────────────────────────────────────────────────────

/**
 * รุ่นที่ผลิต + อะไหล่ตามสูตรของแต่ละรุ่น พร้อมสถานะคลังวันนี้
 *
 * @return array{
 *   ok:bool,
 *   error:string,
 *   models:array<int,array{product_id:int,name:string,parts:array<int,array<string,mixed>>,order:int,near:int}>,
 *   counts:array{models:int,parts:int,order:int,near:int}
 * }
 */
function purchase_check_load(): array
{
    $out = [
        'ok' => true, 'error' => '', 'models' => [],
        'counts' => ['models' => 0, 'parts' => 0, 'order' => 0, 'near' => 0],
    ];
    try {
        $st = production_db()->query(
            "SELECT pr.id AS product_id, pr.name AS model_name,
                    pt.id AS part_id, pt.name AS part_name, TRIM(pt.stock_code) AS stock_code,
                    COALESCE(NULLIF(TRIM(b.unit), ''), pt.unit) AS unit, pt.icon_path, b.qty_per_unit
             FROM bom_items b
             JOIN products pr ON pr.id = b.product_id AND pr.is_active = 1
             JOIN parts pt ON pt.id = b.part_id"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $stock = purchase_check_stock_by_codes(array_column($rows, 'stock_code'));
    } catch (Throwable $e) {
        error_log('[purchase_check_load] ' . $e->getMessage());
        $out['ok'] = false;
        $out['error'] = 'โหลดสูตรผลิตหรือคลังอะไหล่ไม่สำเร็จ';
        return $out;
    }

    $models = [];
    $partStatus = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        $code = (string) $r['stock_code'];
        $tech = $code !== '' ? ($stock[$code] ?? null) : null;
        // ไม่ได้ผูกรหัสสต็อก = ไม่รู้คงเหลือ ห้ามขึ้น "ปกติ" ให้เข้าใจผิด
        $status = $tech === null ? 'unmapped' : stock_status_key($tech['quantity'], $tech['min_stock']);
        $part = [
            'part_id'       => (int) $r['part_id'],
            'code'          => $code,
            'name'          => (string) $r['part_name'],
            'unit'          => (string) ($r['unit'] ?: 'ชิ้น'),
            'icon_path'     => (string) ($r['icon_path'] ?? ''),
            'app_id'        => (int) ($tech['id'] ?? 0),
            'stock'         => $tech !== null ? qty_norm($tech['quantity']) : null,
            'min'           => $tech !== null ? qty_norm($tech['min_stock']) : null,
            'purchase_link' => (string) ($tech['purchase_link'] ?? ''),
            'supplier'      => trim((string) ($tech['supplier'] ?? '')),
            'status'        => $status,
            'bom'           => qty_norm($r['qty_per_unit']),
        ];
        if (!isset($models[$pid])) {
            $models[$pid] = ['product_id' => $pid, 'name' => (string) $r['model_name'], 'parts' => [], 'order' => 0, 'near' => 0];
        }
        $models[$pid]['parts'][] = $part;
        if (stock_status_is_reorder($status)) {
            $models[$pid]['order']++;
        } elseif ($status === 'near') {
            $models[$pid]['near']++;
        }
        // อะไหล่ตัวเดียวอยู่ในหลายรุ่น — การ์ดสรุปนับครั้งเดียว
        $partStatus[(int) $r['part_id']] = $status;
    }

    foreach ($models as &$m) {
        usort($m['parts'], static function ($a, $b) {
            return [purchase_check_status_rank($a['status']), $a['name']]
                <=> [purchase_check_status_rank($b['status']), $b['name']];
        });
    }
    unset($m);
    $models = array_values($models);
    // รุ่นที่มีอะไหล่ต้องสั่งมากสุดขึ้นก่อน
    usort($models, static function ($a, $b) {
        return [-$a['order'], -$a['near'], $a['name']] <=> [-$b['order'], -$b['near'], $b['name']];
    });

    $out['models'] = $models;
    $out['counts']['models'] = count($models);
    $out['counts']['parts'] = count($partStatus);
    foreach ($partStatus as $s) {
        if (stock_status_is_reorder($s)) {
            $out['counts']['order']++;
        } elseif ($s === 'near') {
            $out['counts']['near']++;
        }
    }
    return $out;
}

/**
 * อะไหล่ของรุ่นที่ตรงกับตัวกรองสถานะ
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

// ─ Render ─────────────────────────────────────────────────────────────────────

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
 * หัวตาราง + colgroup
 *
 * @return string HTML
 */
function purchase_check_thead_html(): string
{
    return '<colgroup><col class="pc-c-name"><col class="pc-c-bom"><col class="pc-c-stock"><col class="pc-c-min"><col class="pc-c-st"></colgroup>'
        . '<thead><tr>'
        . '<th>อะไหล่</th>'
        . '<th class="text-right">สูตรต่อเครื่อง</th>'
        . '<th class="text-right">คงเหลือ</th>'
        . '<th class="text-right">ขั้นต่ำ</th>'
        . '<th>สถานะ</th>'
        . '</tr></thead>';
}

/**
 * แถวหัวรุ่น
 *
 * @param array<string,mixed> $model
 * @param int                 $shown จำนวนอะไหล่ที่แสดง (หลังกรอง)
 * @return string HTML
 */
function purchase_check_group_row_html(array $model, int $shown): string
{
    $bits = [formatNumber($shown) . ' อะไหล่'];
    if ((int) $model['order'] > 0) {
        $bits[] = formatNumber($model['order']) . ' ควรสั่ง';
    }
    if ((int) $model['near'] > 0) {
        $bits[] = formatNumber($model['near']) . ' ใกล้ขั้นต่ำ';
    }
    return '<tr class="pc-group"><td colspan="5"><span>' . e((string) $model['name']) . '</span><em>'
        . e(implode(' · ', $bits)) . '</em></td></tr>';
}

/**
 * ช่องชื่ออะไหล่ — รูปอยู่หน้าชื่อ
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function purchase_check_name_cell_html(array $part): string
{
    $code = (string) ($part['code'] ?? '');
    $name = (string) ($part['name'] ?? '');
    $bits = array_filter([$code, (string) ($part['supplier'] ?? '')]);
    $html = '<div class="pc-partcell">' . purchase_check_thumb_html($part) . '<div class="pc-partname">';
    $html .= parts_name_profile_link($code, $name, 'detail-link', (int) ($part['app_id'] ?? 0));
    if ($bits) {
        $html .= '<div class="pc-code">' . e(implode(' · ', $bits)) . '</div>';
    }
    return $html . '</div></div>';
}

/**
 * ช่องสถานะ + ลิงก์สั่งซื้อเมื่อควรสั่งหรือใกล้ขั้นต่ำ
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function purchase_check_status_cell_html(array $part): string
{
    $st = (string) ($part['status'] ?? 'ok');
    if ($st === 'unmapped') {
        return '<span class="text-muted pc-unmapped" title="อะไหล่ในสูตรนี้ยังไม่ได้ผูกรหัสสต็อกกับคลังช่าง">ไม่ได้ผูกรหัสสต็อก</span>';
    }
    $meta = stock_status_meta($st);
    $html = '<span class="stock-status-tag ' . e((string) $meta['tone']) . '">' . e((string) $meta['label']) . '</span>';
    $link = (string) ($part['purchase_link'] ?? '');
    if ($link !== '' && (stock_status_is_reorder($st) || $st === 'near')) {
        $html .= '<a class="detail-link pc-buy" href="' . e($link) . '" target="_blank" rel="noopener noreferrer">สั่งซื้อ</a>';
    }
    return $html;
}

/**
 * แถวอะไหล่ 5 คอลัมน์
 *
 * @param array<string,mixed> $part
 * @return string HTML
 */
function purchase_check_part_row_html(array $part): string
{
    $mapped = $part['stock'] !== null;
    $low = $mapped && stock_status_is_reorder((string) $part['status']) ? ' qty-low' : '';
    return '<tr>'
        . '<td>' . purchase_check_name_cell_html($part) . '</td>'
        . '<td class="text-right pc-qty">' . formatNumber($part['bom']) . ' <span class="text-muted">' . e((string) $part['unit']) . '</span></td>'
        . '<td class="text-right pc-qty' . $low . '">' . ($mapped ? formatNumber($part['stock']) : '—') . '</td>'
        . '<td class="text-right pc-qty">' . ($mapped ? formatNumber($part['min']) : '—') . '</td>'
        . '<td>' . purchase_check_status_cell_html($part) . '</td>'
        . '</tr>';
}
