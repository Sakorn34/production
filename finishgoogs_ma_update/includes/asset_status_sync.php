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
function asset_status_target_from_external(string $current, ?array $sale, ?array $lease): array
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
        // ลงทะเบียนในระบบเช่าแล้ว = เครื่องเช่า ไม่ว่าจะอยู่กับลูกค้า รอ MA หรือรับคืนรอปล่อยเช่าใหม่
        // (เดิมเครื่องที่รับคืนเข้าคลังเช่าถูกนับเป็น "ใหม่" ทำให้ยอดสต็อกผลิตใหม่ปนเครื่องเช่าวนกลับ)
        return ['target' => 'rental', 'reason' => asset_status_leasing_implies_rental($lease) ? 'สถานะระบบเช่า' : 'ลงทะเบียนในระบบเช่า (คลังเช่า)'];
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

function asset_status_sync_row(array $row, array $saleMap, array $leaseMap, bool $write = true): array
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
        $code !== '' ? ($leaseMap[$code] ?? null) : null
    );
    $target = $resolved['target'];
    $reason = (string) ($resolved['reason'] ?? '');

    if ($target === null || $target === $current || $id <= 0) {
        return ['changed' => false, 'asset_code' => $code, 'from' => $current, 'to' => $current, 'reason' => $reason];
    }

    if ($write) {
        q('UPDATE assets SET status=? WHERE id=?', 'si', [$target, $id]);
        asset_status_sync_log($id, $current, $target, $reason);
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

    foreach ($assetRows as $row) {
        $item = asset_status_sync_row($row, $saleMap, $leaseMap, $write);
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
        'SELECT id, asset_code, factory_serial, status FROM assets WHERE id=? LIMIT 1',
        'i',
        [(int) $assetId]
    )->fetch_assoc();
    if (!$row) {
        return ['changed' => false, 'from' => '', 'to' => '', 'reason' => 'not found'];
    }
    $code = trim((string) ($row['asset_code'] ?? ''));
    $saleMap = $code !== '' ? asset_stockparts_sale_status_by_sn([$code]) : [];
    $leaseMap = asset_leasing_status_by_assets([$row]);
    $item = asset_status_sync_row($row, $saleMap, $leaseMap, $write);
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
            'SELECT id, asset_code, factory_serial, status FROM assets ORDER BY id LIMIT ? OFFSET ?',
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
