<?php
/**
 * includes/asset_status_sync.php
 * ────────────────────────────────────────────────────────────────────────────────
 * sync assets.status จากระบบเช่า (biton_leasing) และการเบิกขาย (biton_stockparts)
 * กติกา: ลงทะเบียนในระบบเช่า = เครื่องเช่า (ยกเว้นปลดระวาง/สูญหาย) > เบิกขาย = ขายแล้ว · ไม่ทับ spare
 * ────────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/stockparts_withdraw.php';
require_once __DIR__ . '/rent_ma_bridge.php';
require_once __DIR__ . '/installation_history.php';

/**
 * ระบบเช่าบ่งชี้ว่าเครื่องอยู่ในสายงานเช่า (ไม่ใช่คลังพร้อมเช่า)
 *
 * @param array<string,mixed> $lease
 * @return bool
 */
function asset_status_leasing_implies_rental(array $lease): bool
{
    if (empty($lease['found'])) {
        return false;
    }
    $p = trim((string) ($lease['p_status'] ?? ''));
    $pro = trim((string) ($lease['pro_status'] ?? ''));
    if ($p === 'active') {
        return true;
    }
    if (in_array($pro, ['MA', 'claim', 'Awaiting Return', 'rent'], true)) {
        return true;
    }
    return in_array($p, ['MA', 'claim'], true);
}

/**
 * ระบบเช่าบ่งชี้ว่าเครื่องปลดระวาง/เสื่อมสภาพแล้ว
 *
 * @param array<string,mixed> $lease
 * @return bool
 */
function asset_status_leasing_implies_retired(array $lease): bool
{
    if (empty($lease['found'])) {
        return false;
    }
    return trim((string) ($lease['pro_status'] ?? '')) === 'Asset Retirement'
        || trim((string) ($lease['p_status'] ?? '')) === 'Asset Retirement';
}

/**
 * ระบบเช่าบ่งชี้ว่าเครื่องสูญหาย
 *
 * @param array<string,mixed> $lease
 * @return bool
 */
function asset_status_leasing_implies_lost(array $lease): bool
{
    if (empty($lease['found'])) {
        return false;
    }
    return trim((string) ($lease['pro_status'] ?? '')) === 'Lost'
        || trim((string) ($lease['p_status'] ?? '')) === 'Lost';
}

/**
 * ระบบเช่าบ่งชี้ว่าเครื่องกลับคลังแล้ว
 *
 * @param array<string,mixed> $lease
 * @return bool
 */
function asset_status_leasing_implies_new(array $lease): bool
{
    if (empty($lease['found'])) {
        return false;
    }
    $p = trim((string) ($lease['p_status'] ?? ''));
    $pro = trim((string) ($lease['pro_status'] ?? ''));
    return $p === 'received' || $pro === 'finished goods';
}

/**
 * คำนวณสถานะเป้าหมายจากแหล่งภายนอก
 *
 * @param string                   $current assets.status ปัจจุบัน
 * @param array<string,mixed>|null $sale    จาก asset_stockparts_sale_status_by_sn
 * @param array<string,mixed>|null $lease   จาก asset_leasing_status_by_assets
 * @return array{target:?string,reason:string}
 */
/**
 * ลงทะเบียนเข้าคลังเช่าไว้นานกี่วัน ถึงเลิกนับว่าเป็น "เครื่องพร้อมส่งให้เช่า"
 *
 * เครื่องที่ลงไว้ไม่นานยังเชื่อว่ารอปล่อยเช่าอยู่จริง แต่ถ้าค้างเกินเกณฑ์นี้
 * โดยไม่เคยมีสัญญาเช่าเลย แปลว่าแถวในทะเบียนเช่าเป็นของค้าง ไม่ได้บอกที่อยู่จริง
 * ของเครื่อง จึงตัดเป็น "ไม่มีสถานะ" ให้ไปตามหลักฐานที่หน้าติดตามเครื่องไม่มีสถานะ
 */
if (!defined('ASSET_LEASE_IDLE_UNKNOWN_DAYS')) {
    define('ASSET_LEASE_IDLE_UNKNOWN_DAYS', 30);
}

/**
 * ค้างในคลังเช่ามากี่วัน
 *
 * @param array<string,mixed>|null $lease
 * @return int|null null = ไม่รู้วันที่ (0000-00-00 หรือว่าง) — ไม่เอามาตัดสิน
 */
function asset_status_lease_idle_days(?array $lease): ?int
{
    $d = trim((string) ($lease['lease_date'] ?? ''));
    if ($d === '' || strpos($d, '0000-00-00') === 0) {
        return null;
    }
    $t = strtotime($d);
    if ($t === false || $t <= 0) {
        return null;
    }
    return (int) floor((time() - $t) / 86400);
}

/**
 * ป้ายอธิบายหลักฐานขาย/ส่งมอบจากระบบ setup
 *
 * @param array<string,mixed> $hit แถวจาก asset_status_setup_sold_map()
 */
function asset_status_setup_reason(array $hit): string
{
    $label = [
        'order' => 'ส่งมอบตามใบสั่งงาน',
        'claim' => 'ส่งออกไปเคลม',
        'sale'  => 'ขายตามระบบ setup',
    ][(string) ($hit['src'] ?? '')] ?? 'ส่งมอบแล้ว';
    $ref = trim((string) ($hit['ref'] ?? ''));
    return 'ระบบ Setup: ' . $label . ($ref !== '' ? ' ' . $ref : '');
}

function asset_status_target_from_external(string $current, ?array $sale, ?array $lease, ?array $setup = null): array
{
    $current = trim($current) !== '' ? trim($current) : 'new';

    // ระบบเช่าชนะใบเบิกขาย — เครื่องเช่าก็ต้องเบิกออกจากคลังเหมือนกัน ใบเบิกจึงบอกได้
    // แค่ว่าเครื่องออกไปแล้ว ไม่ได้บอกว่าออกไปแบบไหน
    // ยกเว้นเครื่องสำรองที่ตั้งไว้เอง ยังคงไม่แตะเหมือนเดิม
    if ($current !== 'spare' && $lease && !empty($lease['found'])) {
        // สัญญาที่ยัง active ชนะทุกอย่าง — เครื่องอยู่กับลูกค้าจริง ต่อให้ทะเบียนเครื่อง
        // เขียนว่าปลดระวาง/สูญหาย ก็ถือว่าข้อมูลสองฝั่งขัดกัน แล้วเชื่อสัญญาที่ยังเดินอยู่
        if (trim((string) ($lease['p_status'] ?? '')) === 'active') {
            return ['target' => 'rental', 'reason' => 'สถานะระบบเช่า'];
        }
        if (asset_status_leasing_implies_lost($lease)) {
            return ['target' => 'lost', 'reason' => 'ระบบเช่าแจ้งสูญหาย'];
        }
        if (asset_status_leasing_implies_retired($lease)) {
            return ['target' => 'retired', 'reason' => 'ระบบเช่าแจ้งปลดระวาง'];
        }
        // อยู่ในสายงานเช่าจริง (อยู่กับลูกค้า · รอ MA · ติดเคลม) = เครื่องเช่าแน่นอน
        if (asset_status_leasing_implies_rental($lease)) {
            return ['target' => 'rental', 'reason' => 'สถานะระบบเช่า'];
        }
        // เหลือกรณี "คลังพร้อมเช่า" (finished goods) — ปกติคือเครื่องพร้อมส่งให้ลูกค้าเช่า
        // หรือเครื่องที่รับคืนมาแล้วรอปล่อยใหม่ ทั้งสองแบบถือเป็นเครื่องเช่าตามเดิม
        //
        // ข้อยกเว้นเดียว: เครื่องที่ "ไม่เคยมีสัญญาเช่าเลย" แต่มีใบเบิกขายจริงจาก stock
        // แปลว่าถูกขายออกไปแล้ว ส่วนแถวในทะเบียนเช่าเป็นของค้างที่ไม่ได้ใช้งาน
        // เดิมกฎนี้บังหลักฐานขายไว้ทั้งหมด เครื่องที่ขายไปแล้วจึงค้างเป็น "เครื่องเช่า" อยู่เป็นปี
        if (empty($lease['had_contract']) && $sale && !empty($sale['sold']) && empty($sale['from_delivery'])) {
            return ['target' => 'sold', 'reason' => 'เบิกขายจาก stock (อยู่ในทะเบียนเช่าแต่ไม่เคยปล่อยเช่า)'];
        }
        if (empty($lease['had_contract'])) {
            // ใบสั่งงาน/ประวัติขายของระบบ setup — บางเครื่องคลังจ่ายออกผ่านใบสั่งงาน
            // โดยไม่ได้ทำใบเบิกขายใน stock หลักฐานชุดนี้เคยถูกแถวทะเบียนเช่าบังไว้ทั้งหมด
            // ใช้ได้เฉพาะเครื่องที่ไม่เคยมีสัญญาเช่าเลย จึงแปลว่าใบสั่งงานนั้นไม่ใช่การส่งเช่า
            if ($setup) {
                return ['target' => 'sold',
                        'reason' => asset_status_setup_reason($setup) . ' (อยู่ในทะเบียนเช่าแต่ไม่เคยปล่อยเช่า)'];
            }
            // เครื่องที่ลงทะเบียนเข้าคลังเช่าไว้เฉย ๆ นานแล้ว ไม่เคยปล่อยเช่าสักครั้ง
            // แถวฝั่งเช่าเป็นของค้าง ไม่ได้บอกว่าเครื่องอยู่ไหน = "ไม่มีสถานะ"
            $idle = asset_status_lease_idle_days($lease);
            if ($idle !== null && $idle >= (int) ASSET_LEASE_IDLE_UNKNOWN_DAYS) {
                return ['target' => 'unknown', 'want_setup' => true,
                        'reason' => 'ลงทะเบียนคลังเช่าไว้ ' . number_format($idle) . ' วัน ไม่เคยปล่อยเช่า'];
            }
            return ['target' => 'rental', 'want_setup' => true,
                    'reason' => 'คลังพร้อมเช่า (ยังไม่เคยปล่อยเช่า)'];
        }
        return ['target' => 'rental', 'reason' => 'รับคืนเข้าคลังเช่า'];
    }

    if ($sale && !empty($sale['sold']) && empty($sale['from_delivery'])) {
        return ['target' => 'sold', 'reason' => 'เบิกขายจาก stock'];
    }

    if ($current === 'spare') {
        return ['target' => null, 'reason' => 'เครื่องสำรอง — ไม่ sync อัตโนมัติ'];
    }


    // ส่งมอบไปไซต์งานแล้วและไม่มีชื่อในระบบเช่าเลย = ขายขาด
    // วางท้ายสุดเพราะหลักฐานเป็นแค่ประวัติการส่งมอบ ไม่มีใบเบิกขายรองรับ
    // จึงต้องแพ้ให้ทุกกฎข้างบน และแตะเฉพาะเครื่องที่ยังเป็น new เท่านั้น
    if ($current === 'new'
        && $sale && !empty($sale['sold']) && !empty($sale['from_delivery'])
        && (!$lease || empty($lease['found']))) {
        return ['target' => 'sold', 'reason' => 'ส่งมอบให้ลูกค้าแล้ว (ไม่มีสัญญาเช่า)'];
    }

    return ['target' => null, 'reason' => ''];
}

/**
 * บันทึก audit เมื่อ sync สถานะ
 *
 * @param int    $assetId
 * @param string $from
 * @param string $to
 * @param string $reason
 * @return void
 */
function asset_status_sync_log(int $assetId, string $from, string $to, string $reason): void
{
    $actor = function_exists('actor_name') ? actor_name() : 'system';
    if ($actor === '') {
        $actor = 'system';
    }
    $dir = $to === 'new' ? 'in' : 'out';
    q(
        'INSERT INTO stock_movements (asset_id,moved_at,direction,reason,made_by) VALUES (?,NOW(),?,?,?)',
        'isss',
        [$assetId, $dir, asset_status_sync_log_prefix() . $from . ' → ' . $to . ($reason !== '' ? ' (' . $reason . ')' : ''), $actor]
    );
}

/**
 * sync แถวเครื่องเดียว (มี sale/lease map แล้ว)
 *
 * @param array<string,mixed>                   $row
 * @param array<string,array<string,mixed>>     $saleMap
 * @param array<string,array<string,mixed>>     $leaseMap
 * @param bool                                  $write
 * @return array{changed:bool,asset_code:string,from:string,to:string,reason:string}
 */
/**
 * asset id ที่สแกนเจอในคลังตอนนับสต็อกรอบล่าสุด (รอบที่ยังเปิดหรือจบแล้ว ไม่นับรอบที่ยกเลิก)
 *
 * @return array<int,bool>
 */
if (!defined('ASSET_STATUS_SCAN_WINDOW_DAYS')) {
    define('ASSET_STATUS_SCAN_WINDOW_DAYS', 30);
}

function asset_status_scanned_ids(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $t = db()->query("SHOW TABLES LIKE 'stock_scan_items'");
        if (!$t || $t->num_rows === 0) {
            return $cache;
        }
        // นับสต็อกมักแบ่งทำหลายรอบ (ทีละรุ่น/ทีละโซน) — ถือว่าเครื่องที่สแกนเจอในรอบไหนก็ได้
        // ภายใน ASSET_STATUS_SCAN_WINDOW_DAYS วันยังอยู่ในคลัง ไม่ใช่ดูแค่รอบล่าสุด
        // (เดิมดูรอบล่าสุดรอบเดียว รอบใหม่ที่สแกนแค่บางรุ่นเลยทำให้เครื่องจากรอบก่อนถูกตัดออก)
        $res = qr(
            "SELECT i.asset_id, MAX(i.scanned_at) AS at, MAX(i.session_id) AS sid
             FROM stock_scan_items i JOIN stock_scan_sessions s ON s.id = i.session_id
             WHERE i.result = 'ok' AND i.asset_id IS NOT NULL
               AND s.status IN ('open','applied')
               AND i.scanned_at >= NOW() - INTERVAL " . (int) ASSET_STATUS_SCAN_WINDOW_DAYS . " DAY
             GROUP BY i.asset_id"
        );
        while ($r = $res->fetch_assoc()) {
            $cache[(int) $r['asset_id']] = ['at' => (string) $r['at'], 'session' => (int) $r['sid']];
        }
        if ($cache) {
            // มีคนเปลี่ยนสถานะเครื่องนั้นเองหลังสแกน (ขาย/ส่งเช่า/ซิงก์) = เชื่อการเปลี่ยนครั้งหลัง
            // ไม่นับการเปลี่ยนที่มาจากหน้านับสต็อกเอง (รวมการย้อนกลับของมัน)
            $res = qr(
                "SELECT asset_id, MAX(moved_at) AS at FROM stock_movements
                 WHERE moved_at >= NOW() - INTERVAL " . (int) ASSET_STATUS_SCAN_WINDOW_DAYS . " DAY
                   AND reason NOT LIKE '%นับสต็อก (สแกน)%'
                 GROUP BY asset_id"
            );
            while ($r = $res->fetch_assoc()) {
                $id = (int) $r['asset_id'];
                if (isset($cache[$id]) && (string) $r['at'] > $cache[$id]['at']) {
                    unset($cache[$id]);
                }
            }
        }
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * เครื่องที่ระบบ setup บันทึกว่าขาย/ส่งมอบไปแล้ว (อ่านทีเดียวทั้งชุด)
 *
 * บางเครื่องคลังจ่ายออกผ่านใบสั่งงานของระบบ setup โดยไม่ได้ทำใบเบิกขายใน stock
 * ทะเบียน stock จึงยังขึ้นว่าอยู่ในคลัง และสถานะฝั่งเราค้างเป็น "เครื่องใหม่"
 * ทั้งที่ของส่งถึงลูกค้าแล้ว — หลักฐานชุดนี้จึงใช้ปิดช่องว่างนั้น
 *
 * @param string[] $codes
 * @return array<string,array{ref:string,date:string,customer:string,src:string}> รหัสเครื่อง (ตัวใหญ่) => ข้อมูล
 */
function asset_status_setup_sold_map(array $codes): array
{
    $out = [];
    $codes = array_values(array_unique(array_filter(array_map('trim', $codes))));
    if (!$codes || !function_exists('dbSetup')) {
        return $out;
    }
    $db = dbSetup();
    if (!$db) {
        return $out;
    }
    $in = implode(',', array_map(function ($v) use ($db) { return "'" . $db->real_escape_string($v) . "'"; }, $codes));
    try {
        // ใบส่งมอบ Order — แหล่งหลัก มีทั้งเลขใบและชื่อหน่วยงาน
        $res = $db->query(
            "SELECT ps.serial_number, ps.issue_ref, ps.issue_date, so.company_name, so.customer_name
             FROM po_order_part_serials ps LEFT JOIN setup_orders so ON so.id = ps.order_id
             WHERE ps.serial_number IN ($in) ORDER BY ps.issue_date ASC, ps.id ASC"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $out[strtoupper(trim((string) $r['serial_number']))] = [
                'ref'      => trim((string) ($r['issue_ref'] ?? '')),
                'date'     => trim((string) ($r['issue_date'] ?? '')),
                'customer' => trim((string) ($r['company_name'] ?? '')) !== ''
                    ? trim((string) $r['company_name']) : trim((string) ($r['customer_name'] ?? '')),
                'src'      => 'order',
            ];
        }
        // ประวัติขาย/เคลม — เอาเฉพาะแถวที่เครื่องนี้เป็น "ตัวที่ส่งออกไป" (new_serial_number)
        $res = $db->query(
            "SELECT new_serial_number, claim_number, claim_date, customer_name, issue_type
             FROM equipment_claim_history
             WHERE new_serial_number IN ($in) ORDER BY claim_date ASC, id ASC"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $sn = strtoupper(trim((string) $r['new_serial_number']));
            if (isset($out[$sn])) {
                continue;   // ใบส่งมอบละเอียดกว่า ใช้ตัวนั้นก่อน
            }
            $out[$sn] = [
                'ref'      => trim((string) ($r['claim_number'] ?? '')),
                'date'     => trim((string) ($r['claim_date'] ?? '')),
                'customer' => trim((string) ($r['customer_name'] ?? '')),
                'src'      => trim((string) ($r['issue_type'] ?? '')) === 'claim' ? 'claim' : 'sale',
            ];
        }
    } catch (\Throwable $e) {
        error_log('[asset_status_setup_sold_map] ' . $e->getMessage());
    }
    return $out;
}

function asset_status_sync_row(array $row, array $saleMap, array $leaseMap, bool $write = true, array $installMap = [], array $setupMap = []): array
{
    $id = (int) ($row['id'] ?? 0);
    $code = trim((string) ($row['asset_code'] ?? ''));
    $current = trim((string) ($row['status'] ?? 'new'));
    if ($current === '') {
        $current = 'new';
    }


    $resolved = asset_status_target_from_external(
        $current,
        $code !== '' ? ($saleMap[$code] ?? null) : null,
        $code !== '' ? ($leaseMap[$code] ?? null) : null,
        $code !== '' ? ($setupMap[strtoupper($code)] ?? null) : null
    );
    $target = $resolved['target'];
    $reason = (string) ($resolved['reason'] ?? '');

    // ขาย/ส่งมอบตามระบบ setup — คลังจ่ายออกผ่านใบสั่งงานโดยไม่ได้ทำใบเบิกขายใน stock
    // แตะเฉพาะเครื่องที่ยังเป็น "ใหม่" และไม่มีชื่อในระบบเช่า (สัญญาเช่าชนะเสมอ)
    // เครื่องที่อยู่ในทะเบียนเช่าใช้หลักฐานชุดนี้ในกฎข้างบนแล้ว
    if ($target === null && $current === 'new' && $code !== '') {
        $lease = $leaseMap[strtoupper($code)] ?? ($leaseMap[$code] ?? null);
        $hit = $setupMap[strtoupper($code)] ?? null;
        if ($hit && empty($lease['found'])) {
            $target = 'sold';
            $reason = asset_status_setup_reason($hit);
        }
    }

    // ประวัติติดตั้งระบบเดิม (installation) — หลักฐานอ่อนสุด ใช้กับเครื่องที่ยังเป็น "ใหม่" เท่านั้น
    // เมื่อไม่มีหลักฐานอื่นเลย (ไม่อยู่ในระบบเช่า ไม่มีใบเบิก) เงื่อนไขละเอียดดู installation_history_status_hint
    if ($target === null && $current === 'new' && $code !== '') {
        $lease = $leaseMap[strtoupper($code)] ?? ($leaseMap[$code] ?? null);
        $sale = $saleMap[$code] ?? null;
        if (empty($lease['found']) && empty($sale['sold'])) {
            $hint = installation_history_status_hint($row, $installMap[strtoupper($code)] ?? null);
            if ($hint) {
                $target = $hint['target'];
                $reason = $hint['reason'];
            }
        }
    }

    if ($target === null || $target === $current || $id <= 0) {
        return ['changed' => false, 'asset_code' => $code, 'from' => $current, 'to' => $current, 'reason' => $reason];
    }

    if ($write) {
        q('UPDATE assets SET status=? WHERE id=?', 'si', [$target, $id]);
        asset_status_sync_log($id, $current, $target, $reason);
        // ทะเบียนสินค้า (stock) นับเฉพาะเครื่องใหม่ — สถานะเปลี่ยนแล้วต้องตาม active ไปด้วย
        if (function_exists('stock_active_sync_codes') && $code !== '') {
            stock_active_sync_codes([$code]);
        }
    }

    return ['changed' => true, 'asset_code' => $code, 'from' => $current, 'to' => $target, 'reason' => $reason];
}

/**
 * sync ชุดเครื่อง (batch)
 *
 * @param array<int,array<string,mixed>> $assetRows
 * @param bool                           $write
 * @return array{changed:int,total:int,items:array<int,array<string,mixed>>}
 */
function asset_status_sync_batch(array $assetRows, bool $write = true): array
{
    $stats = ['changed' => 0, 'total' => count($assetRows), 'items' => []];
    if (!$assetRows) {
        return $stats;
    }

    $codes = [];
    foreach ($assetRows as $row) {
        $c = trim((string) ($row['asset_code'] ?? ''));
        if ($c !== '') {
            $codes[] = $c;
        }
    }

    $saleMap = $codes ? asset_stockparts_sale_status_by_sn($codes) : [];
    $leaseMap = asset_leasing_status_by_assets($assetRows);
    // โหลดประวัติติดตั้งเฉพาะเครื่องที่ยังเป็น "ใหม่" — กฎนี้ไม่แตะสถานะอื่น
    $newRows = array_values(array_filter($assetRows, function ($r) { return trim((string) ($r['status'] ?? '')) === 'new'; }));
    $installMap = $newRows ? installation_history_map($newRows) : [];
    // หลักฐานฝั่ง setup ใช้กับเครื่องที่ยังเป็น "ใหม่" เหมือนกัน จึงถามเฉพาะชุดนั้น
    $setupMap = $newRows ? asset_status_setup_sold_map(array_column($newRows, 'asset_code')) : [];

    // รอบตรวจก่อน: เครื่องที่กฎตอบว่า "อยู่ในทะเบียนเช่าแต่ไม่เคยปล่อยเช่า" ต้องดูหลักฐาน
    // ฝั่ง setup ด้วย ไม่งั้นเครื่องที่ขายไปแล้วผ่านใบสั่งงานจะถูกแถวทะเบียนเช่าบังไว้
    // ถามเฉพาะชุดนี้ เพราะเป็นการอ่านฐานของระบบอื่น ไม่อยากยิงทั้งทะเบียน
    $wantSetup = [];
    foreach ($assetRows as $row) {
        $c = trim((string) ($row['asset_code'] ?? ''));
        if ($c === '' || isset($setupMap[strtoupper($c)])) {
            continue;
        }
        $peek = asset_status_target_from_external(
            trim((string) ($row['status'] ?? 'new')),
            $saleMap[$c] ?? null,
            $leaseMap[$c] ?? null
        );
        if (!empty($peek['want_setup'])) {
            $wantSetup[] = $c;
        }
    }
    if ($wantSetup) {
        foreach (asset_status_setup_sold_map($wantSetup) as $k => $v) {
            $setupMap[$k] = $v;
        }
    }

    foreach ($assetRows as $row) {
        $item = asset_status_sync_row($row, $saleMap, $leaseMap, $write, $installMap, $setupMap);
        if (!empty($item['changed'])) {
            $stats['changed']++;
            $stats['items'][] = $item;
        }
    }

    return $stats;
}

/**
 * sync เครื่องเดียวตาม asset id
 *
 * @param int  $assetId
 * @param bool $write
 * @return array{changed:bool,from:string,to:string,reason:string}
 */
function asset_status_sync_by_id(int $assetId, bool $write = true): array
{
    $row = qr(
        'SELECT id, asset_code, factory_serial, status, produced_at FROM assets WHERE id=? LIMIT 1',
        'i',
        [(int) $assetId]
    )->fetch_assoc();
    if (!$row) {
        return ['changed' => false, 'from' => '', 'to' => '', 'reason' => 'not found'];
    }
    $code = trim((string) ($row['asset_code'] ?? ''));
    $saleMap = $code !== '' ? asset_stockparts_sale_status_by_sn([$code]) : [];
    $leaseMap = asset_leasing_status_by_assets([$row]);
    $isNew = trim((string) $row['status']) === 'new';
    $installMap = $isNew ? installation_history_map([$row]) : [];
    // ถามหลักฐานฝั่ง setup ทุกครั้ง — เครื่องเดียวไม่หนัก และกฎทะเบียนเช่าก็ใช้หลักฐานชุดนี้
    $setupMap = $code !== '' ? asset_status_setup_sold_map([$code]) : [];
    $item = asset_status_sync_row($row, $saleMap, $leaseMap, $write, $installMap, $setupMap);
    return [
        'changed' => !empty($item['changed']),
        'from' => (string) ($item['from'] ?? ''),
        'to' => (string) ($item['to'] ?? ''),
        'reason' => (string) ($item['reason'] ?? ''),
    ];
}

/**
 * sync ทะเบียนเครื่องทั้งหมด (chunk)
 *
 * @param bool $write
 * @param int  $chunkSize
 * @return array{changed:int,total:int,items:array<int,array<string,mixed>>}
 */
function asset_status_sync_all(bool $write = true, int $chunkSize = 200): array
{
    ensure_asset_status_sold_schema();
    $chunkSize = max(50, min(500, (int) $chunkSize));
    $offset = 0;
    $stats = ['changed' => 0, 'total' => 0, 'items' => []];

    while (true) {
        $res = qr(
            'SELECT id, asset_code, factory_serial, status, produced_at FROM assets ORDER BY id LIMIT ? OFFSET ?',
            'ii',
            [$chunkSize, $offset]
        );
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        if (!$rows) {
            break;
        }
        $batch = asset_status_sync_batch($rows, $write);
        $stats['changed'] += (int) ($batch['changed'] ?? 0);
        $stats['total'] += (int) ($batch['total'] ?? 0);
        if (!empty($batch['items'])) {
            foreach ($batch['items'] as $it) {
                $stats['items'][] = $it;
            }
        }
        $offset += $chunkSize;
    }

    return $stats;
}

/**
 * sync อัตโนมัติเมื่อครบ interval (ใช้บน dashboard)
 *
 * @param int $intervalSec ค่าเริ่มต้น 6 ชม.
 * @return array{ran:bool,changed:int,total:int}|null
 */
function asset_status_sync_if_stale(int $intervalSec = 21600): ?array
{
    $intervalSec = max(300, $intervalSec);
    $flag = dirname(__DIR__) . '/uploads/.asset_status_sync_last';
    if (is_file($flag) && (time() - filemtime($flag)) < $intervalSec) {
        return null;
    }
    $stats = asset_status_sync_all(true, 200);
    @touch($flag);
    return ['ran' => true, 'changed' => (int) ($stats['changed'] ?? 0), 'total' => (int) ($stats['total'] ?? 0)];
}
