<?php
/**
 * production_asset_withdraw_sync.php — Sync Stock ตามรายการเบิกของเครื่อง (part_movements)
 *
 * ใช้ตาราง「อะไหล่ที่เบิกใช้กับเครื่องนี้」เป็นหลัก — ไม่เทียบ bom_items
 * ผูก/สร้าง stock_out, ลบใบเบิกซ้ำ/เกินใน Parts (คืนสต็ockเมื่อ stock_deducted=1)
 *
 * Flow:
 *   production_asset_withdraw_reconcile_preview($assetId) → dry-run
 *   production_reconcile_asset_withdraw_list($assetId, $actor) → apply
 */

require_once __DIR__ . '/production_sync.php';

// ─ Load movements ──────────────────────────────────────────────────────────────

/**
 * โหลด movement เบิกออกทั้งหมดของเครื่อง (ทุก mode — ตรงตาราง asset.php)
 *
 * @param int $assetId
 * @return array<int,array<string,mixed>>
 */
function production_asset_withdraw_movements(int $assetId): array
{
    if ($assetId <= 0) {
        return [];
    }
    $prod = production_db();
    $st = $prod->prepare(
        "SELECT pm.id, pm.part_id, pm.qty, pm.moved_at, pm.mode, pm.made_by, pm.remark,
                pm.tech_stock_out_id, pt.name AS pname, pt.stock_code
         FROM part_movements pm
         JOIN parts pt ON pt.id = pm.part_id
         WHERE pm.ref_asset_id = ? AND pm.direction = 'out'
         ORDER BY pm.moved_at ASC, pm.id ASC"
    );
    $st->execute([$assetId]);
    $rows = [];
    while ($row = $st->fetch()) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * รวบ stock_out.id ที่ควรเก็บไว้ (ผูก movement ในตาราง)
 *
 * @param string              $assetCode
 * @param array<int,int|true> $movementIdSet  key = movement id
 * @param array<int,array<string,mixed>> $movements
 * @return array<int,true> stock_out.id => true
 */
function production_asset_withdraw_keep_stock_out_ids(string $assetCode, array $movementIdSet, array $movements): array
{
    $keep = [];
    if ($assetCode === '' || $movementIdSet === []) {
        return $keep;
    }

    foreach ($movements as $m) {
        $oid = (int) ($m['tech_stock_out_id'] ?? 0);
        if ($oid > 0) {
            $keep[$oid] = true;
        }
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    $st = $tech->prepare(
        'SELECT id, part_movement_id FROM stock_out WHERE TRIM(COALESCE(asset_code, "")) = ?'
    );
    $st->execute([$assetCode]);
    while ($row = $st->fetch()) {
        $oid = (int) $row['id'];
        $pmid = (int) ($row['part_movement_id'] ?? 0);
        if ($pmid > 0 && isset($movementIdSet[$pmid])) {
            $keep[$oid] = true;
        }
    }

    $st2 = $tech->prepare(
        'SELECT DISTINCT soi.stock_out_id, soi.part_movement_id
         FROM stock_out_items soi
         INNER JOIN stock_out so ON so.id = soi.stock_out_id
         WHERE TRIM(COALESCE(so.asset_code, "")) = ?'
    );
    $st2->execute([$assetCode]);
    while ($row = $st2->fetch()) {
        $pmid = (int) ($row['part_movement_id'] ?? 0);
        if ($pmid > 0 && isset($movementIdSet[$pmid])) {
            $keep[(int) $row['stock_out_id']] = true;
        }
    }

    return $keep;
}

/**
 * รายการ stock_out.id ของ S/N ที่ไม่อยู่ใน keep set
 *
 * @param string           $assetCode
 * @param array<int,true>  $keepIds
 * @return array<int,int>
 */
function production_asset_withdraw_orphan_stock_out_ids(string $assetCode, array $keepIds): array
{
    if ($assetCode === '') {
        return [];
    }
    $tech = tech_parts_sync_db();
    $st = $tech->prepare('SELECT id FROM stock_out WHERE TRIM(COALESCE(asset_code, "")) = ?');
    $st->execute([$assetCode]);
    $orphans = [];
    while ($row = $st->fetch()) {
        $oid = (int) $row['id'];
        if (!isset($keepIds[$oid])) {
            $orphans[] = $oid;
        }
    }
    return $orphans;
}

/**
 * หา stock_out ซ้ำที่อ้าง movement เดียวกัน (เก็บใบที่ movement.tech_stock_out_id ชี้)
 *
 * @param array<int,array<string,mixed>> $movements
 * @return array<int,int> stock_out.id ที่ควรลบ
 */
function production_asset_withdraw_duplicate_stock_out_ids(array $movements): array
{
    if ($movements === []) {
        return [];
    }
    $movementIdSet = [];
    $preferredOut = [];
    foreach ($movements as $m) {
        $mid = (int) $m['id'];
        $movementIdSet[$mid] = true;
        $oid = (int) ($m['tech_stock_out_id'] ?? 0);
        if ($oid > 0) {
            $preferredOut[$mid] = $oid;
        }
    }

    $tech = tech_parts_sync_db();
    $mids = array_keys($movementIdSet);
    $ph = implode(',', array_fill(0, count($mids), '?'));
    $st = $tech->prepare(
        "SELECT id, part_movement_id FROM stock_out WHERE part_movement_id IN ($ph)"
    );
    $st->execute($mids);
    $byMovement = [];
    while ($row = $st->fetch()) {
        $pmid = (int) $row['part_movement_id'];
        $byMovement[$pmid][] = (int) $row['id'];
    }

    $remove = [];
    foreach ($byMovement as $pmid => $outIds) {
        if (count($outIds) <= 1) {
            continue;
        }
        $keepId = (int) ($preferredOut[$pmid] ?? min($outIds));
        foreach ($outIds as $oid) {
            if ($oid !== $keepId) {
                $remove[$oid] = $oid;
            }
        }
    }
    return array_values($remove);
}

/**
 * หา part_movements ซ้ำใน production (อะไหล่เดียวกันหลายแถว — มักจาก import เก่า)
 *
 * เก็บแถว id น้อยสุดต่อ part_id ลบที่เหลือ
 *
 * @param int $assetId
 * @return array<int,int> movement.id ที่ควรลบ
 */
function production_asset_withdraw_duplicate_movement_ids(int $assetId): array
{
    if ($assetId <= 0) {
        return [];
    }
    $prod = production_db();
    $st = $prod->prepare(
        "SELECT part_id, GROUP_CONCAT(id ORDER BY id ASC) AS ids
         FROM part_movements
         WHERE ref_asset_id = ? AND direction = 'out'
         GROUP BY part_id
         HAVING COUNT(*) > 1"
    );
    $st->execute([$assetId]);
    $remove = [];
    while ($row = $st->fetch()) {
        $ids = array_map('intval', explode(',', (string) $row['ids']));
        array_shift($ids);
        foreach ($ids as $id) {
            if ($id > 0) {
                $remove[] = $id;
            }
        }
    }
    return $remove;
}

/**
 * ลบ part_movements ซ้ำ + stock_out_items ที่ผูก (คืนสต็ockถ้าหักแล้ว)
 *
 * @param int  $assetId
 * @param bool $dryRun
 * @return array{ok:bool,removed:int,kept:int,errors:array<int,string>}
 */
function production_remove_duplicate_movements_for_asset(int $assetId, bool $dryRun = false): array
{
    $result = ['ok' => true, 'removed' => 0, 'kept' => 0, 'errors' => []];
    $dupIds = production_asset_withdraw_duplicate_movement_ids($assetId);
    if ($dupIds === []) {
        return $result;
    }

    $prod = production_db();

    foreach ($dupIds as $mid) {
        $mid = (int) $mid;
        if ($mid <= 0) {
            continue;
        }
        if ($dryRun) {
            $result['removed']++;
            continue;
        }
        if (!function_exists('production_sync_delete_stock_out_from_movement')) {
            $result['errors'][] = "movement #{$mid}: ระบบ sync ไม่พร้อม";
            $result['ok'] = false;
            continue;
        }
        if (!production_sync_delete_stock_out_from_movement($mid)) {
            if (function_exists('production_delete_out_movement')) {
                production_delete_out_movement($mid);
                $result['removed']++;
            } else {
                $result['errors'][] = "movement #{$mid}: ลบไม่สำเร็จ";
                $result['ok'] = false;
            }
            continue;
        }
        $result['removed']++;
    }

    return $result;
}

// ─ Preview / reconcile ─────────────────────────────────────────────────────────

/**
 * dry-run — สรุปการ sync ตามรายการเบิก
 *
 * @param int $assetId
 * @return array{ok:bool,asset_id:int,asset_code:string,movement_count:int,link:array<int,array<string,mixed>>,create:array<int,array<string,mixed>>,remove_stock_out:array<int,array<string,mixed>>,warnings:array<int,string>}
 */
function production_asset_withdraw_reconcile_preview(int $assetId): array
{
    $result = [
        'ok'               => false,
        'asset_id'         => $assetId,
        'asset_code'       => '',
        'movement_count'   => 0,
        'link'             => [],
        'create'           => [],
        'remove_stock_out' => [],
        'warnings'         => [],
    ];

    if ($assetId <= 0) {
        $result['warnings'][] = 'รหัสเครื่องไม่ถูกต้อง';
        return $result;
    }

    $prod = production_db();
    $st = $prod->prepare('SELECT asset_code FROM assets WHERE id = ? LIMIT 1');
    $st->execute([$assetId]);
    $asset = $st->fetch();
    if (!$asset) {
        $result['warnings'][] = 'ไม่พบเครื่อง';
        return $result;
    }

    $assetCode = trim((string) ($asset['asset_code'] ?? ''));
    $result['asset_code'] = $assetCode;
    if ($assetCode === '') {
        $result['warnings'][] = 'เครื่องนี้ไม่มี S/N';
        return $result;
    }

    $movements = production_asset_withdraw_movements($assetId);
    $result['movement_count'] = count($movements);
    if ($movements === []) {
        $result['ok'] = true;
        $orphans = production_asset_withdraw_orphan_stock_out_ids(
            $assetCode,
            production_asset_withdraw_keep_stock_out_ids($assetCode, [], [])
        );
        foreach ($orphans as $oid) {
            $result['remove_stock_out'][] = ['stock_out_id' => $oid, 'reason' => 'orphan_no_movements'];
        }
        return $result;
    }

    $movementIdSet = [];
    foreach ($movements as $m) {
        $movementIdSet[(int) $m['id']] = true;
    }

    $keepIds = production_asset_withdraw_keep_stock_out_ids($assetCode, $movementIdSet, $movements);
    $orphanIds = production_asset_withdraw_orphan_stock_out_ids($assetCode, $keepIds);
    $dupIds = production_asset_withdraw_duplicate_stock_out_ids($movements);
    $removeIds = [];
    foreach (array_merge($orphanIds, $dupIds) as $oid) {
        $removeIds[$oid] = $oid;
    }
    foreach ($removeIds as $oid) {
        $reason = in_array($oid, $dupIds, true) ? 'duplicate_doc' : 'orphan';
        $result['remove_stock_out'][] = ['stock_out_id' => $oid, 'reason' => $reason];
    }

    $tech = tech_parts_sync_db();
    ensure_stock_production_sync_schema($tech);

    foreach ($movements as $m) {
        $mid = (int) $m['id'];
        $linked = (int) ($m['tech_stock_out_id'] ?? 0);
        if ($linked > 0 && isset($keepIds[$linked])) {
            continue;
        }

        $product = production_resolve_tech_product((int) $m['part_id']);
        if (!$product) {
            $result['warnings'][] = "movement #{$mid}: ไม่ map รหัสอะไหล่";
            continue;
        }

        $qty = (int) round((float) $m['qty']);
        if ($qty <= 0) {
            continue;
        }

        $mDate = substr((string) $m['moved_at'], 0, 10);
        $outId = production_sync_find_unlinked_stock_out(
            $tech,
            $assetCode,
            (int) $product['id'],
            $qty,
            $mDate,
            $mid
        );

        $entry = [
            'movement_id' => $mid,
            'part_id'     => (int) $m['part_id'],
            'qty'         => $qty,
            'pname'       => (string) ($m['pname'] ?? ''),
            'mode'        => (string) ($m['mode'] ?? ''),
        ];

        if ($outId > 0) {
            $entry['stock_out_id'] = $outId;
            $result['link'][] = $entry;
        } else {
            $existSt = $tech->prepare('SELECT id FROM stock_out WHERE part_movement_id = ? LIMIT 1');
            $existSt->execute([$mid]);
            $existId = (int) ($existSt->fetchColumn() ?: 0);
            if ($existId > 0) {
                $entry['stock_out_id'] = $existId;
                $result['link'][] = $entry;
            } else {
                $result['create'][] = $entry;
            }
        }
    }

    $result['ok'] = true;
    return $result;
}

/**
 * Sync ตามรายการเบิกในตาราง — ลบ stock_out เกิน แล้วผูก/สร้างให้ครบ
 *
 * @param int    $assetId
 * @param string $actor
 * @return array{ok:bool,removed:int,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>,message?:string}
 */
function production_reconcile_asset_withdraw_list(int $assetId, string $actor): array
{
    $result = [
        'ok'       => true,
        'removed'  => 0,
        'linked'   => 0,
        'created'  => 0,
        'repaired' => 0,
        'skipped'  => 0,
        'failed'   => 0,
        'errors'   => [],
    ];

    $dedupe = production_remove_duplicate_movements_for_asset($assetId, false);
    if ($dedupe['removed'] > 0) {
        $result['removed'] += (int) $dedupe['removed'];
    }
    if (!empty($dedupe['errors'])) {
        foreach ($dedupe['errors'] as $err) {
            $result['errors'][] = $err;
        }
    }

    $preview = production_asset_withdraw_reconcile_preview($assetId);
    if (!$preview['ok']) {
        $result['ok'] = false;
        $result['errors'] = array_merge($result['errors'], $preview['warnings']);
        return $result;
    }

    if ($preview['asset_code'] === '' && $preview['movement_count'] === 0 && $preview['remove_stock_out'] === []) {
        $result['errors'][] = 'ไม่พบ S/N หรือรายการเบิก';
        $result['ok'] = false;
        return $result;
    }

    foreach ($preview['remove_stock_out'] as $rm) {
        $oid = (int) ($rm['stock_out_id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }
        if (($rm['reason'] ?? '') === 'duplicate_doc') {
            if (production_sync_delete_stock_out_by_id($oid)) {
                $result['removed']++;
            } else {
                $result['errors'][] = "ลบ stock_out #{$oid} ไม่สำเร็จ";
            }
        }
    }

    if (!function_exists('production_sync_asset_withdrawals')) {
        $result['errors'][] = 'production_sync_asset_withdrawals ไม่พร้อม';
        $result['ok'] = false;
        return $result;
    }

    $sync = production_sync_asset_withdrawals($assetId);
    $result['linked'] = (int) ($sync['linked'] ?? 0);
    $result['created'] = (int) ($sync['created'] ?? 0);
    $result['repaired'] = (int) ($sync['repaired'] ?? 0);
    $result['skipped'] = (int) ($sync['skipped'] ?? 0);
    $result['failed'] = (int) ($sync['failed'] ?? 0);
    if (!empty($sync['errors'])) {
        foreach ($sync['errors'] as $err) {
            $result['errors'][] = $err;
        }
    }

    if ($result['failed'] > 0 && $result['linked'] === 0 && $result['created'] === 0 && $result['removed'] === 0) {
        $result['ok'] = false;
    }

    $parts = [];
    if ($result['removed'] > 0) {
        $parts[] = 'ลบใบเบิกซ้ำ ' . $result['removed'];
    }
    if ($result['linked'] > 0) {
        $parts[] = 'ผูก ' . $result['linked'];
    }
    if ($result['created'] > 0) {
        $parts[] = 'สร้างใบเบิก ' . $result['created'];
    }
    if ($result['repaired'] > 0) {
        $parts[] = 'ซ่อมลิงก์ ' . $result['repaired'];
    }
    if ($parts !== []) {
        $result['message'] = 'Sync รายการเบิก: ' . implode(' · ', $parts);
    } else {
        $result['message'] = 'Stock ตรงรายการเบิกแล้ว — ไม่มีรายการที่ต้อง sync';
    }

    return $result;
}

/**
 * รีเซ็ต Parts stock_out ตาม production part_movements — ลบใบเบิกเดิมแล้ว sync ใหม่
 *
 * @param int    $assetId
 * @param string $actor
 * @return array{ok:bool,purged:int,removed:int,linked:int,created:int,repaired:int,skipped:int,failed:int,errors:array<int,string>,message?:string}
 */
function production_resync_asset_stock_from_movements(int $assetId, string $actor = 'resync-cli'): array
{
    $result = [
        'ok'      => true,
        'purged'  => 0,
        'removed' => 0,
        'linked'  => 0,
        'created' => 0,
        'repaired'=> 0,
        'skipped' => 0,
        'failed'  => 0,
        'errors'  => [],
    ];

    if ($assetId <= 0) {
        $result['ok'] = false;
        $result['errors'][] = 'รหัสเครื่องไม่ถูกต้อง';
        return $result;
    }

    $prod = production_db();
    $stAsset = $prod->prepare('SELECT asset_code FROM assets WHERE id = ? LIMIT 1');
    $stAsset->execute([$assetId]);
    $assetRow = $stAsset->fetch();
    if (!$assetRow) {
        $result['ok'] = false;
        $result['errors'][] = "ไม่พบ asset #{$assetId}";
        return $result;
    }
    $assetCode = trim((string) ($assetRow['asset_code'] ?? ''));
    if ($assetCode === '') {
        $result['ok'] = false;
        $result['errors'][] = 'เครื่องนี้ไม่มี S/N';
        return $result;
    }

    $dedupe = production_remove_duplicate_movements_for_asset($assetId, false);
    if ($dedupe['removed'] > 0) {
        $result['removed'] += (int) $dedupe['removed'];
    }
    if (!empty($dedupe['errors'])) {
        $result['errors'] = array_merge($result['errors'], $dedupe['errors']);
    }

    if (!function_exists('production_purge_stock_out_for_sn')) {
        $result['ok'] = false;
        $result['errors'][] = 'production_purge_stock_out_for_sn ไม่พร้อม';
        return $result;
    }

    $result['purged'] = production_purge_stock_out_for_sn($assetCode, true);

    if (!function_exists('production_sync_asset_withdrawals')) {
        $result['ok'] = false;
        $result['errors'][] = 'production_sync_asset_withdrawals ไม่พร้อม';
        return $result;
    }

    $sync = production_sync_asset_withdrawals($assetId);
    $result['linked']   = (int) ($sync['linked'] ?? 0);
    $result['created']  = (int) ($sync['created'] ?? 0);
    $result['repaired'] = (int) ($sync['repaired'] ?? 0);
    $result['skipped']  = (int) ($sync['skipped'] ?? 0);
    $result['failed']   = (int) ($sync['failed'] ?? 0);
    if (!empty($sync['errors'])) {
        $result['errors'] = array_merge($result['errors'], $sync['errors']);
    }

    if (function_exists('production_consolidate_stock_outs_for_sn')) {
        $cons = production_consolidate_stock_outs_for_sn($assetCode, false);
        if (!$cons['ok'] && !empty($cons['errors'])) {
            $result['errors'] = array_merge($result['errors'], $cons['errors']);
        }
    }

    if ($result['failed'] > 0 && $result['linked'] === 0 && $result['created'] === 0) {
        $result['ok'] = false;
    }

    $parts = [];
    if ($result['purged'] > 0) {
        $parts[] = 'ลบใบเบิกเดิม ' . $result['purged'];
    }
    if ($result['removed'] > 0) {
        $parts[] = 'ลบ movement ซ้ำ ' . $result['removed'];
    }
    if ($result['created'] > 0) {
        $parts[] = 'สร้าง ' . $result['created'];
    }
    if ($result['linked'] > 0) {
        $parts[] = 'ผูก ' . $result['linked'];
    }
    $result['message'] = $parts !== []
        ? 'Resync: ' . implode(' · ', $parts)
        : 'Resync สำเร็จ — ข้อมูลตรง production แล้ว';

    return $result;
}
