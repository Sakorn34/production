<?php
/**
 * production_bom_sync.php — reconcile รายการเบิก BOM ของเครื่องผลิตให้ตรง bom_items
 *
 * วัตถุประสงค์: ลบรายการซ้ำ/เกิน (คืนสต็ockเมื่อหักจริง) เพิ่มรายการที่ขาด แล้วผูก stock_out
 * ไม่แตะรายการ MA / เบิกมือ — นับ Set เบิกตาม S/N เป็นส่วนหนึ่งของ BOM fulfillment
 *
 * Flow:
 *   production_bom_reconcile_preview($assetId) → สรุปก่อน confirm
 *   production_reconcile_asset_bom($assetId, $actor) → apply + production_sync_asset_withdrawals()
 */

require_once __DIR__ . '/production_sync.php';

// ─ Constants ───────────────────────────────────────────────────────────────────

/**
 * mode ของ part_movements ที่ถือเป็นเบิก BOM อัตโนมัติจากการผลิต
 *
 * @return array<int,string>
 */
function production_bom_movement_modes(): array
{
    return ['เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)'];
}

// ─ BOM maps ────────────────────────────────────────────────────────────────────

/**
 * โหลด BOM ที่คาดหวังต่อ product_id
 *
 * @param int $productId
 * @return array<int,float> part_id => qty_per_unit
 */
function production_bom_expected_map(int $productId): array
{
    static $cache = [];
    if ($productId <= 0) {
        return [];
    }
    if (isset($cache[$productId])) {
        return $cache[$productId];
    }
    $prod = production_db();
    $st = $prod->prepare(
        'SELECT part_id, qty_per_unit FROM bom_items WHERE product_id = ? ORDER BY part_id'
    );
    $st->execute([$productId]);
    $map = [];
    while ($row = $st->fetch()) {
        $pid = (int) $row['part_id'];
        $map[$pid] = (float) $row['qty_per_unit'];
    }
    $cache[$productId] = $map;
    return $map;
}

/**
 * qty ต่อ part จาก Set stock_out ที่ผูก S/N (ไม่ลบ Set ระหว่าง reconcile)
 *
 * @param string $assetCode S/N
 * @return array<int,float> part_id => qty รวม
 */
function production_bom_set_qty_map(string $assetCode): array
{
    $assetCode = trim($assetCode);
    if ($assetCode === '') {
        return [];
    }
    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);
    $st = $tech->prepare(
        'SELECT soi.product_id, soi.quantity, p.code AS product_code
         FROM stock_out so
         JOIN stock_out_items soi ON soi.stock_out_id = so.id
         JOIN products p ON p.id = soi.product_id
         WHERE so.asset_code = ? AND so.set_id IS NOT NULL'
    );
    $st->execute([$assetCode]);
    $map = [];
    while ($row = $st->fetch()) {
        $partId = production_part_id_by_product_code((string) ($row['product_code'] ?? ''));
        if ($partId === null) {
            continue;
        }
        $map[$partId] = ($map[$partId] ?? 0.0) + (float) $row['quantity'];
    }
    return $map;
}

/**
 * รายการ movement โหมด BOM ที่ reconcile ได้ (ไม่รวม Set header)
 *
 * @param int $assetId
 * @return array<int,array<string,mixed>>
 */
function production_bom_auto_movements(int $assetId): array
{
    $modes = production_bom_movement_modes();
    if ($modes === []) {
        return [];
    }
    $prod = production_db();
    $ph = implode(',', array_fill(0, count($modes), '?'));
    $sql = "SELECT pm.id, pm.part_id, pm.qty, pm.moved_at, pm.mode, pm.tech_stock_out_id, pm.made_by,
                   pt.name AS pname, pt.stock_code
            FROM part_movements pm
            JOIN parts pt ON pt.id = pm.part_id
            WHERE pm.ref_asset_id = ? AND pm.direction = 'out'
              AND pm.mode IN ($ph)
            ORDER BY pm.part_id ASC, pm.id ASC";
    $st = $prod->prepare($sql);
    $st->execute(array_merge([$assetId], $modes));
    $rows = [];
    while ($row = $st->fetch()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * คำนวณ qty จริงต่อ part สำหรับเทียบ BOM (auto movements + Set items)
 *
 * @param int    $assetId
 * @param string $assetCode
 * @param int    $productId
 * @return array{auto:array<int,float>,set:array<int,float>,total:array<int,float>}
 */
function production_bom_actual_qty_breakdown(int $assetId, string $assetCode, int $productId): array
{
    $auto = [];
    foreach (production_bom_auto_movements($assetId) as $m) {
        $pid = (int) $m['part_id'];
        $auto[$pid] = ($auto[$pid] ?? 0.0) + (float) $m['qty'];
    }
    $set = production_bom_set_qty_map($assetCode);
    $total = $auto;
    foreach ($set as $pid => $qty) {
        $total[$pid] = ($total[$pid] ?? 0.0) + $qty;
    }
    return ['auto' => $auto, 'set' => $set, 'total' => $total];
}

/**
 * ประเมินสถานะ BOM ต่อเครื่อง (qty-level)
 *
 * @param int    $assetId
 * @param int    $productId
 * @param string $assetCode
 * @return array{bom_match:string,bom_qty_ok:bool,bom_extra_parts:int,bom_missing_parts:int,bom_actual_total:float,expected:array<int,float>,actual:array<int,float>}
 */
function production_bom_match_status(int $assetId, int $productId, string $assetCode): array
{
    $expected = production_bom_expected_map($productId);
    if ($expected === []) {
        return [
            'bom_match'         => 'none',
            'bom_qty_ok'        => true,
            'bom_extra_parts'   => 0,
            'bom_missing_parts' => 0,
            'bom_actual_total'  => 0.0,
            'expected'          => [],
            'actual'            => [],
        ];
    }
    $actual = production_bom_actual_qty_breakdown($assetId, $assetCode, $productId)['total'];
    $extra = 0;
    $missing = 0;
    $allPartIds = array_unique(array_merge(array_keys($expected), array_keys($actual)));
    foreach ($allPartIds as $pid) {
        $exp = (float) ($expected[$pid] ?? 0.0);
        $act = (float) ($actual[$pid] ?? 0.0);
        if ($act > $exp + 0.0001) {
            $extra++;
        } elseif ($act + 0.0001 < $exp) {
            $missing++;
        }
    }
    $bomMatch = 'ok';
    if ($extra > 0 && $missing > 0) {
        $bomMatch = 'mixed';
    } elseif ($extra > 0) {
        $bomMatch = 'extra';
    } elseif ($missing > 0) {
        $bomMatch = 'missing';
    }
    $actualTotal = array_sum($actual);
    // หมายเหตุ: $actualTotal นับเฉพาะ mode ที่ production_bom_movement_modes() รู้จัก + Set
    // เครื่องที่เบิกผลิตด้วย mode อื่น (เช่น 'เบิกผลิต' ที่กรอกเองจากฟอร์ม) จะไม่ถูกนับที่นี่
    // ผู้เรียกที่เห็นรายการเบิกทั้งหมด (asset_parts_withdraw_summary) เป็นผู้ตัดสินว่าเครื่องนี้
    // "ไม่เคยเบิกผลิตเลยจริง ๆ" หรือแค่เบิกด้วยคนละ mode — ฟังก์ชันนี้ไม่มีข้อมูลพอจะตัดสินเอง
    return [
        'bom_match'         => $bomMatch,
        'bom_qty_ok'        => $bomMatch === 'ok',
        'bom_extra_parts'   => $extra,
        'bom_missing_parts' => $missing,
        'bom_actual_total'  => $actualTotal,
        'expected'          => $expected,
        'actual'            => $actual,
    ];
}

// ─ Preview / reconcile ─────────────────────────────────────────────────────────

/**
 * เรียง movement สำหรับลบ (ไม่มี link → sync-created → id ใหม่สุด)
 *
 * @param array<int,array<string,mixed>> $movements
 * @return array<int,array<string,mixed>>
 */
function production_bom_sort_removable_movements(array $movements): array
{
    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);
    usort($movements, static function ($a, $b) use ($tech) {
        $aLink = (int) ($a['tech_stock_out_id'] ?? 0);
        $bLink = (int) ($b['tech_stock_out_id'] ?? 0);
        if ($aLink <= 0 && $bLink > 0) {
            return -1;
        }
        if ($aLink > 0 && $bLink <= 0) {
            return 1;
        }
        $aDed = 1;
        $bDed = 1;
        if ($aLink > 0) {
            $st = $tech->prepare('SELECT stock_deducted FROM stock_out WHERE id = ?');
            $st->execute([$aLink]);
            $aDed = production_stock_out_was_deducted_row($st->fetch()) ? 1 : 0;
        }
        if ($bLink > 0) {
            $st = $tech->prepare('SELECT stock_deducted FROM stock_out WHERE id = ?');
            $st->execute([$bLink]);
            $bDed = production_stock_out_was_deducted_row($st->fetch()) ? 1 : 0;
        }
        if ($aDed !== $bDed) {
            return $aDed <=> $bDed;
        }
        return (int) $b['id'] <=> (int) $a['id'];
    });
    return $movements;
}

/**
 * สรุป diff ก่อน Sync ตาม BOM (dry-run)
 *
 * @param int $assetId
 * @return array{ok:bool,asset_id:int,asset_code:string,product_id:int,remove:array<int,array<string,mixed>>,add:array<int,array<string,mixed>>,adjust:array<int,array<string,mixed>>,warnings:array<int,string>,bom_match:string}
 */
function production_bom_reconcile_preview(int $assetId): array
{
    $result = [
        'ok'         => false,
        'asset_id'   => $assetId,
        'asset_code' => '',
        'product_id' => 0,
        'remove'     => [],
        'add'        => [],
        'adjust'     => [],
        'warnings'   => [],
        'bom_match'  => 'none',
    ];
    if ($assetId <= 0) {
        $result['warnings'][] = 'รหัสเครื่องไม่ถูกต้อง';
        return $result;
    }
    $prod = production_db();
    $st = $prod->prepare('SELECT id, product_id, asset_code FROM assets WHERE id = ? LIMIT 1');
    $st->execute([$assetId]);
    $asset = $st->fetch();
    if (!$asset) {
        $result['warnings'][] = 'ไม่พบเครื่อง';
        return $result;
    }
    $result['asset_code'] = trim((string) ($asset['asset_code'] ?? ''));
    $result['product_id'] = (int) $asset['product_id'];
    if ($result['asset_code'] === '') {
        $result['warnings'][] = 'เครื่องนี้ไม่มี S/N';
        return $result;
    }
    $status = production_bom_match_status($assetId, $result['product_id'], $result['asset_code']);
    $result['bom_match'] = $status['bom_match'];
    $expected = $status['expected'];
    if ($expected === []) {
        $result['warnings'][] = 'รุ่นนี้ไม่มี BOM';
        $result['ok'] = true;
        return $result;
    }
    $breakdown = production_bom_actual_qty_breakdown($assetId, $result['asset_code'], $result['product_id']);
    $actualTotal = $breakdown['total'];
    $autoMovements = production_bom_auto_movements($assetId);
    $byPart = [];
    foreach ($autoMovements as $m) {
        $byPart[(int) $m['part_id']][] = $m;
    }
    foreach ($byPart as &$list) {
        $list = production_bom_sort_removable_movements($list);
    }
    unset($list);

    $allPartIds = array_unique(array_merge(array_keys($expected), array_keys($actualTotal)));
    foreach ($allPartIds as $partId) {
        $exp = (float) ($expected[$partId] ?? 0.0);
        $autoQty = (float) ($breakdown['auto'][$partId] ?? 0.0);
        $setQty = (float) ($breakdown['set'][$partId] ?? 0.0);
        $act = $autoQty + $setQty;

        if ($act > $exp + 0.0001) {
            $needRemove = $act - $exp;
            $candidates = $byPart[$partId] ?? [];
            foreach ($candidates as $m) {
                if ($needRemove <= 0.0001) {
                    break;
                }
                $mq = (float) $m['qty'];
                if ($mq <= $needRemove + 0.0001) {
                    $result['remove'][] = [
                        'movement_id' => (int) $m['id'],
                        'part_id'     => $partId,
                        'qty'         => $mq,
                        'pname'       => (string) ($m['pname'] ?? ''),
                        'action'      => 'delete',
                    ];
                    $needRemove -= $mq;
                } else {
                    $result['adjust'][] = [
                        'movement_id' => (int) $m['id'],
                        'part_id'     => $partId,
                        'from_qty'    => $mq,
                        'to_qty'      => $mq - $needRemove,
                        'pname'       => (string) ($m['pname'] ?? ''),
                        'action'      => 'reduce',
                    ];
                    $needRemove = 0;
                }
            }
            if ($needRemove > 0.0001 && $setQty > 0) {
                $result['warnings'][] = "part_id {$partId}: เกิน BOM แต่ส่วนเกินอยู่ใน Set — ลบ Set ด้วยมือถ้าต้องการ";
            }
        } elseif ($act + 0.0001 < $exp) {
            $result['add'][] = [
                'part_id' => $partId,
                'qty'     => $exp - $act,
            ];
        }
    }
    $result['ok'] = true;
    return $result;
}

/**
 * Sync ตาม BOM — reconcile qty แล้วผูก stock_out
 *
 * @param int    $assetId
 * @param string $actor
 * @return array{ok:bool,removed:int,adjusted:int,added:int,linked:int,created:int,errors:array<int,string>,message?:string}
 */
function production_reconcile_asset_bom(int $assetId, string $actor): array
{
    $result = [
        'ok'       => true,
        'removed'  => 0,
        'adjusted' => 0,
        'added'    => 0,
        'linked'   => 0,
        'created'  => 0,
        'errors'   => [],
    ];
    $preview = production_bom_reconcile_preview($assetId);
    if (!$preview['ok']) {
        $result['ok'] = false;
        $result['errors'] = $preview['warnings'];
        return $result;
    }
    if ($preview['warnings'] !== [] && $preview['bom_match'] === 'none' && ($preview['remove'] === [] && $preview['add'] === [])) {
        foreach ($preview['warnings'] as $w) {
            if (strpos($w, 'ไม่มี BOM') !== false || strpos($w, 'ไม่มี S/N') !== false) {
                $result['errors'][] = $w;
                $result['ok'] = false;
                return $result;
            }
        }
    }

    $assetCode = $preview['asset_code'];
    $bomMode = production_bom_movement_modes()[0] ?? 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)';

    foreach ($preview['adjust'] as $adj) {
        if (!function_exists('production_edit_out_movement')) {
            $result['errors'][] = 'production_edit_out_movement ไม่พร้อม';
            continue;
        }
        $edit = production_edit_out_movement(
            (int) $adj['movement_id'],
            (float) $adj['to_qty'],
            $assetCode,
            null,
            $actor
        );
        if ($edit['ok'] ?? false) {
            $result['adjusted']++;
        } else {
            $result['errors'][] = $edit['error'] ?? ('ปรับ movement #' . $adj['movement_id'] . ' ไม่สำเร็จ');
        }
    }

    foreach ($preview['remove'] as $rm) {
        if (!function_exists('production_delete_out_movement_with_stock')) {
            $result['errors'][] = 'production_delete_out_movement_with_stock ไม่พร้อม';
            continue;
        }
        $del = production_delete_out_movement_with_stock((int) $rm['movement_id'], $actor);
        if ($del['ok'] ?? false) {
            $result['removed']++;
        } else {
            $result['errors'][] = $del['error'] ?? ('ลบ movement #' . $rm['movement_id'] . ' ไม่สำเร็จ');
        }
    }

    foreach ($preview['add'] as $add) {
        $partId = (int) $add['part_id'];
        $qty = (float) $add['qty'];
        if ($partId <= 0 || $qty <= 0) {
            continue;
        }
        if (!function_exists('tech_parts_stock_out_by_part_id')) {
            $result['errors'][] = 'tech_parts_stock_out_by_part_id ไม่พร้อม';
            continue;
        }
        $out = tech_parts_stock_out_by_part_id($partId, $qty, 'เบิกผลิต (Sync BOM)', $actor, $assetCode);
        if (!$out['ok']) {
            $result['errors'][] = $out['error'] ?? ('เบิก part_id ' . $partId . ' ไม่สำเร็จ');
            continue;
        }
        $prod = production_db();
        $prod->prepare(
            "INSERT INTO part_movements (part_id, moved_at, direction, qty, mode, ref_asset_id, made_by, remark, tech_stock_out_id)
             VALUES (?, NOW(), 'out', ?, ?, ?, ?, 'Sync ตาม BOM', ?)"
        )->execute([
            $partId,
            $qty,
            $bomMode,
            $assetId,
            $actor,
            (int) ($out['stock_out_id'] ?? 0),
        ]);
        $mid = (int) $prod->lastInsertId();
        if (!empty($out['stock_out_id']) && function_exists('production_link_stock_out')) {
            production_link_stock_out(tech_parts_sync_db(), (int) $out['stock_out_id'], $mid, $assetCode);
        }
        $result['added']++;
    }

    if (function_exists('production_sync_asset_withdrawals')) {
        $sync = production_sync_asset_withdrawals($assetId);
        $result['linked'] = (int) ($sync['linked'] ?? 0);
        $result['created'] = (int) ($sync['created'] ?? 0);
        if (!empty($sync['errors'])) {
            foreach ($sync['errors'] as $err) {
                $result['errors'][] = $err;
            }
        }
    }

    $parts = [];
    if ($result['removed'] > 0) {
        $parts[] = 'ลบ ' . $result['removed'];
    }
    if ($result['adjusted'] > 0) {
        $parts[] = 'ปรับ ' . $result['adjusted'];
    }
    if ($result['added'] > 0) {
        $parts[] = 'เพิ่ม ' . $result['added'];
    }
    if ($result['linked'] > 0) {
        $parts[] = 'ผูก ' . $result['linked'];
    }
    if ($result['created'] > 0) {
        $parts[] = 'สร้างใบเบิก ' . $result['created'];
    }
    if ($parts !== []) {
        $result['message'] = 'Sync BOM: ' . implode(' · ', $parts);
    } else {
        $result['message'] = 'BOM ตรงแล้ว — ไม่มีรายการที่ต้อง reconcile';
    }
    if ($result['errors'] !== [] && $result['removed'] === 0 && $result['added'] === 0 && $result['adjusted'] === 0) {
        $result['ok'] = false;
    }
    return $result;
}
