<?php
/**
 * rent_retire_sync.php
 * ────────────────────────────────────────────────────────────────────────────────
 * ตรวจ + ซิงก์สถานะ "เสื่อมสภาพ" ระหว่างทะเบียนเรา (assets) กับระบบเช่า (biton_leasing)
 *
 * ทำไมต้องมี: เครื่องที่บันทึก MA เป็นเสื่อมสภาพในระบบเรา ถ้าตอนนั้นส่งเข้าระบบเช่าไม่สำเร็จ
 * (เช่น เครื่องหลุดจากคิวรอ MA ไปแล้ว หรือระบบเช่าล่มชั่วคราว) สถานะสองฝั่งจะไม่ตรงกัน
 * แล้ว cron sync ก็จะดึงสถานะฝั่งเรากลับเป็นเครื่องใหม่ทุกคืน — หน้านี้ให้กดซิงก์ย้อนหลังได้เอง
 *
 * fail-soft: ต่อระบบเช่าไม่ได้ ต้องคืน ok=false พร้อมข้อความ ไม่ throw
 * ────────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/rent_ma_bridge.php';

/** สถานะในระบบเช่าที่ถือว่าเป็นเสื่อมสภาพแล้ว */
const RENT_RETIRE_LEASE_STATUS = 'Asset Retirement';

/** ข้อความในหมายเหตุ MA ที่ใช้ชี้ว่ารอบนั้นคือการปลดระวาง */
const RENT_RETIRE_MA_KEYWORD = 'เสื่อมสภาพ';

/**
 * ป้ายอธิบายผลการเทียบสถานะสองฝั่ง
 *
 * @return array<string,array{label:string, tone:string, hint:string}>
 */
function rent_retire_sync_states()
{
    return [
        'match'     => ['label' => 'ตรงกัน',              'tone' => 'ok',    'hint' => 'เสื่อมสภาพทั้งสองระบบ'],
        'fix_lease' => ['label' => 'ระบบเช่ายังไม่ตาม',    'tone' => 'warn',  'hint' => 'กดซิงก์เพื่อส่งสถานะเสื่อมสภาพไปให้ระบบเช่า'],
        'fix_ours'  => ['label' => 'ทะเบียนเรายังไม่ตาม',  'tone' => 'warn',  'hint' => 'ระบบเช่าเป็นเสื่อมสภาพแล้ว กดซิงก์เพื่อแก้สถานะฝั่งเรา'],
        'blocked'   => ['label' => 'ยังอยู่กับลูกค้า',      'tone' => 'err',   'hint' => 'ต้องรับเครื่องคืนเข้าคลังในระบบเช่าก่อน จึงจะตั้งเสื่อมสภาพได้'],
        'no_lease'  => ['label' => 'ไม่มีในระบบเช่า',      'tone' => 'muted', 'hint' => 'S/N นี้ไม่ได้อยู่ในระบบเช่า (เครื่องขาย/เครื่องผลิตใหม่) — ไม่ต้องซิงก์'],
    ];
}

/**
 * สรุปผลเทียบสถานะเครื่องหนึ่งเครื่อง
 *
 * @param string $ourStatus   ค่า assets.status
 * @param bool   $leaseFound  เจอ S/N ในระบบเช่าไหม
 * @param string $leaseStatus ค่า tbl_product.pro_status
 * @return array{state:string, label:string, tone:string, hint:string, can_sync:bool}
 */
function rent_retire_sync_classify($ourStatus, $leaseFound, $leaseStatus)
{
    $ourRetired = ((string)$ourStatus === 'retired');
    $leaseStatus = trim((string)$leaseStatus);
    $leaseRetired = ($leaseStatus === RENT_RETIRE_LEASE_STATUS);

    if (!$leaseFound) {
        $state = 'no_lease';
    } elseif ($leaseRetired && $ourRetired) {
        $state = 'match';
    } elseif ($leaseRetired) {
        $state = 'fix_ours';
    } elseif (in_array($leaseStatus, RENT_RETIRE_ALLOWED_FROM, true)) {
        $state = 'fix_lease';
    } else {
        $state = 'blocked';
    }

    $meta = rent_retire_sync_states();
    $m = $meta[$state];
    return [
        'state'    => $state,
        'label'    => $m['label'],
        'tone'     => $m['tone'],
        'hint'     => $m['hint'],
        'can_sync' => in_array($state, ['fix_lease', 'fix_ours'], true),
    ];
}

/**
 * อ่านสถานะจากระบบเช่าทีเดียวหลาย S/N (ไม่ยิงทีละเครื่อง)
 *
 * @param array<int,string> $serials
 * @return array{ok:bool, error:string, map:array<string,string>}
 */
function rent_retire_sync_lease_map(array $serials)
{
    $clean = [];
    foreach ($serials as $sn) {
        $sn = rent_normalize_sn($sn);
        if ($sn !== '') {
            $clean[$sn] = true;
        }
    }
    $clean = array_keys($clean);
    if (!$clean) {
        return ['ok' => true, 'error' => '', 'map' => []];
    }
    if (!dbLeasing()) {
        return ['ok' => false, 'error' => dbLeasingError(), 'map' => []];
    }
    $map = [];
    foreach (array_chunk($clean, 300) as $part) {
        $ph = implode(',', array_fill(0, count($part), '?'));
        $res = rent_q_try(
            'SELECT UPPER(TRIM(pro_sn)) sn, pro_status FROM tbl_product WHERE UPPER(TRIM(pro_sn)) IN (' . $ph . ')',
            str_repeat('s', count($part)),
            $part
        );
        if (!$res['ok']) {
            return ['ok' => false, 'error' => (string)($res['error'] ?? 'อ่านสถานะระบบเช่าไม่สำเร็จ'), 'map' => []];
        }
        if (!empty($res['result'])) {
            while ($row = $res['result']->fetch_assoc()) {
                $map[(string)$row['sn']] = trim((string)$row['pro_status']);
            }
        }
    }
    return ['ok' => true, 'error' => '', 'map' => $map];
}

/**
 * รายชื่อเครื่องที่ "เสื่อมสภาพ" ในระบบเรา พร้อมผลเทียบกับระบบเช่า
 *
 * นับเป็นเสื่อมสภาพเมื่อ สถานะทะเบียน = retired หรือ เคยบันทึก MA ที่มีหมายเหตุเสื่อมสภาพ
 * (เคสที่ 2 คือเคสบั๊กเดิม — บันทึก MA ไปแล้วแต่สถานะไม่ขยับ)
 *
 * @param array{q?:string, product_id?:int, limit?:int} $opts
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>, summary:array<string,int>, lease_ok:bool, lease_error:string}
 */
function rent_retire_sync_rows(array $opts = [])
{
    $q = trim((string)(isset($opts['q']) ? $opts['q'] : ''));
    $productId = (int)(isset($opts['product_id']) ? $opts['product_id'] : 0);
    $limit = (int)(isset($opts['limit']) ? $opts['limit'] : 3000);
    if ($limit <= 0 || $limit > 5000) {
        $limit = 3000;
    }

    $where = ["(a.status = 'retired' OR ma.asset_id IS NOT NULL)"];
    $types = '';
    $params = [];
    if ($productId > 0) {
        $where[] = 'a.product_id = ?';
        $types .= 'i';
        $params[] = $productId;
    }
    if ($q !== '') {
        $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)';
        $types .= 'ss';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $sql = "SELECT a.id, a.asset_code, a.factory_serial, a.status, a.product_id, p.name pname,
                   ma.retire_at, ma.retire_by, ma.retire_remark
            FROM assets a
            LEFT JOIN products p ON p.id = a.product_id
            LEFT JOIN (
                SELECT m.asset_id,
                       MAX(m.visited_at) retire_at,
                       SUBSTRING_INDEX(GROUP_CONCAT(m.done_by ORDER BY m.visited_at DESC SEPARATOR 0x1f), 0x1f, 1) retire_by,
                       SUBSTRING_INDEX(GROUP_CONCAT(m.remark  ORDER BY m.visited_at DESC SEPARATOR 0x1f), 0x1f, 1) retire_remark
                FROM ma_records m
                WHERE m.remark LIKE ?
                GROUP BY m.asset_id
            ) ma ON ma.asset_id = a.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (ma.retire_at IS NULL), ma.retire_at DESC, a.id DESC
            LIMIT " . $limit;
    $types = 's' . $types;
    array_unshift($params, '%' . RENT_RETIRE_MA_KEYWORD . '%');

    $res = q_try($sql, $types, $params);
    if (empty($res['ok'])) {
        return [
            'ok' => false, 'error' => (string)($res['error'] ?? 'อ่านทะเบียนไม่สำเร็จ'),
            'rows' => [], 'summary' => [], 'lease_ok' => false, 'lease_error' => '',
        ];
    }

    $rows = [];
    $serials = [];
    $rs = $res['stmt']->get_result();
    while ($r = $rs->fetch_assoc()) {
        $r['id'] = (int)$r['id'];
        $rows[] = $r;
        $serials[] = $r['asset_code'];
        if ((string)$r['factory_serial'] !== '') {
            $serials[] = $r['factory_serial'];
        }
    }

    $lease = rent_retire_sync_lease_map($serials);
    $summary = ['total' => count($rows), 'match' => 0, 'fix_lease' => 0, 'fix_ours' => 0, 'blocked' => 0, 'no_lease' => 0];

    foreach ($rows as $i => $r) {
        $leaseFound = false;
        $leaseStatus = '';
        $leaseSn = '';
        if ($lease['ok']) {
            foreach ([$r['asset_code'], $r['factory_serial']] as $cand) {
                $sn = rent_normalize_sn($cand);
                if ($sn !== '' && isset($lease['map'][$sn])) {
                    $leaseFound = true;
                    $leaseStatus = $lease['map'][$sn];
                    $leaseSn = $sn;
                    break;
                }
            }
        }
        $cls = $lease['ok']
            ? rent_retire_sync_classify($r['status'], $leaseFound, $leaseStatus)
            : ['state' => 'unknown', 'label' => 'ยังเช็คไม่ได้', 'tone' => 'muted', 'hint' => $lease['error'], 'can_sync' => false];

        $rows[$i]['lease_found']  = $leaseFound;
        $rows[$i]['lease_status'] = $leaseStatus;
        $rows[$i]['lease_sn']     = $leaseSn;
        $rows[$i]['lease_label']  = $leaseFound ? rent_leasing_status_label($leaseStatus, '') : '';
        $rows[$i]['state']        = $cls['state'];
        $rows[$i]['state_label']  = $cls['label'];
        $rows[$i]['state_tone']   = $cls['tone'];
        $rows[$i]['state_hint']   = $cls['hint'];
        $rows[$i]['can_sync']     = $cls['can_sync'];
        if (isset($summary[$cls['state']])) {
            $summary[$cls['state']]++;
        }
    }

    return [
        'ok'          => true,
        'error'       => '',
        'rows'        => $rows,
        'summary'     => $summary,
        'lease_ok'    => (bool)$lease['ok'],
        'lease_error' => (string)$lease['error'],
    ];
}

/**
 * หมายเหตุที่จะส่งไปให้ระบบเช่า — ใช้ของรอบ MA จริงถ้ามี จะได้ตรงกับที่ช่างบันทึกไว้
 *
 * @param int $assetId
 * @return string
 */
function rent_retire_sync_remark_for_asset($assetId)
{
    $row = qr(
        'SELECT remark FROM ma_records WHERE asset_id = ? AND remark LIKE ? ORDER BY visited_at DESC, id DESC LIMIT 1',
        'is',
        [(int)$assetId, '%' . RENT_RETIRE_MA_KEYWORD . '%']
    )->fetch_assoc();
    return rent_ensure_retire_remark($row ? (string)$row['remark'] : '');
}

/**
 * ซิงก์เครื่องเดียว — ส่งเสื่อมสภาพเข้าระบบเช่า แล้วตั้งสถานะฝั่งเราให้ตรง
 *
 * ไม่บันทึก ma_records ใหม่ เพราะรอบ MA บันทึกไปแล้ว หน้านี้แค่ตามสถานะที่ค้าง
 *
 * @param int    $assetId
 * @param string $remark  เว้นว่าง = ใช้หมายเหตุจากรอบ MA เดิม
 * @return array{ok:bool, code:string, message:string, asset_code:string, lease_done:bool, ours_changed:bool}
 */
function rent_retire_sync_one($assetId, $remark = '')
{
    $assetId = (int)$assetId;
    $asset = $assetId > 0
        ? qr('SELECT id, asset_code, factory_serial, status FROM assets WHERE id = ? LIMIT 1', 'i', [$assetId])->fetch_assoc()
        : null;
    if (!$asset) {
        return ['ok' => false, 'code' => 'not_found', 'message' => 'ไม่พบเครื่องในทะเบียน', 'asset_code' => '', 'lease_done' => false, 'ours_changed' => false];
    }
    $code = (string)$asset['asset_code'];
    $remark = trim((string)$remark);
    $remark = $remark !== '' ? rent_ensure_retire_remark($remark) : rent_retire_sync_remark_for_asset($assetId);

    $res = rent_apply_after_ma_save_for_asset($asset, 'retire', null, null, '', $remark);
    $leaseDone = !empty($res['ok']) && in_array((string)(isset($res['code']) ? $res['code'] : ''), ['closed', 'already'], true);
    if (!$leaseDone) {
        return [
            'ok'           => false,
            'code'         => (string)(isset($res['code']) ? $res['code'] : 'fail'),
            'message'      => $code . ' — ' . (string)(isset($res['message']) ? $res['message'] : 'ส่งสถานะไประบบเช่าไม่สำเร็จ'),
            'asset_code'   => $code,
            'lease_done'   => false,
            'ours_changed' => false,
        ];
    }
    $changed = rent_mark_asset_retired($assetId);
    $parts = [((string)$res['code'] === 'already') ? 'ระบบเช่าเป็นเสื่อมสภาพอยู่แล้ว' : 'ส่งเสื่อมสภาพเข้าระบบเช่าแล้ว'];
    $parts[] = $changed ? 'ตั้งสถานะทะเบียนเราเป็นเสื่อมสภาพแล้ว' : 'ทะเบียนเราเป็นเสื่อมสภาพอยู่แล้ว';
    return [
        'ok'           => true,
        'code'         => (string)$res['code'],
        'message'      => $code . ' — ' . implode(' · ', $parts),
        'asset_code'   => $code,
        'lease_done'   => true,
        'ours_changed' => $changed,
    ];
}

/**
 * ซิงก์หลายเครื่องในครั้งเดียว
 *
 * @param array<int,int|string> $assetIds
 * @param string                $remark
 * @return array{ok:bool, success:int, fail:int, messages:array<int,string>, failed:array<int,string>}
 */
function rent_retire_sync_many(array $assetIds, $remark = '')
{
    $success = 0;
    $fail = 0;
    $messages = [];
    $failed = [];
    $seen = [];
    foreach ($assetIds as $id) {
        $id = (int)$id;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $r = rent_retire_sync_one($id, $remark);
        $messages[] = $r['message'];
        if ($r['ok']) {
            $success++;
        } else {
            $fail++;
            $failed[] = $r['message'];
        }
    }
    return ['ok' => $fail === 0 && $success > 0, 'success' => $success, 'fail' => $fail, 'messages' => $messages, 'failed' => $failed];
}

/**
 * ผลเทียบสถานะของเครื่องเดียว สำหรับหน้าโปรไฟล์เครื่อง (asset.php)
 *
 * @param array<string,mixed> $asset     แถว assets
 * @param array<string,mixed> $leaseInfo ผลจาก asset_leasing_info() (ใช้ซ้ำ ไม่ query เพิ่ม)
 * @return array{show:bool, state:string, label:string, tone:string, hint:string, can_sync:bool, retired_ma:bool}
 */
function rent_retire_sync_asset_state(array $asset, array $leaseInfo)
{
    $assetId = (int)(isset($asset['id']) ? $asset['id'] : 0);
    $none = ['show' => false, 'state' => '', 'label' => '', 'tone' => '', 'hint' => '', 'can_sync' => false, 'retired_ma' => false];
    if ($assetId <= 0) {
        return $none;
    }
    $hasRetireMa = (bool)qr(
        'SELECT 1 FROM ma_records WHERE asset_id = ? AND remark LIKE ? LIMIT 1',
        'is',
        [$assetId, '%' . RENT_RETIRE_MA_KEYWORD . '%']
    )->fetch_row();
    $ourRetired = ((string)(isset($asset['status']) ? $asset['status'] : '') === 'retired');
    if (!$hasRetireMa && !$ourRetired) {
        return $none;   // เครื่องนี้ไม่เคยถูกลงเสื่อมสภาพ ไม่ต้องรบกวนหน้าจอ
    }
    $cls = rent_retire_sync_classify(
        isset($asset['status']) ? $asset['status'] : '',
        !empty($leaseInfo['found']),
        (string)(isset($leaseInfo['pro_status']) ? $leaseInfo['pro_status'] : '')
    );
    return [
        'show'       => true,
        'state'      => $cls['state'],
        'label'      => $cls['label'],
        'tone'       => $cls['tone'],
        'hint'       => $cls['hint'],
        'can_sync'   => $cls['can_sync'],
        'retired_ma' => $hasRetireMa,
    ];
}

/**
 * กล่องผลเทียบ + ปุ่มซิงก์ บนหน้าโปรไฟล์เครื่อง
 *
 * @param array<string,mixed> $asset
 * @param array<string,mixed> $leaseInfo
 * @return string HTML (ว่าง = ไม่ต้องแสดง)
 */
function rent_retire_sync_asset_box_html(array $asset, array $leaseInfo)
{
    $st = rent_retire_sync_asset_state($asset, $leaseInfo);
    if (!$st['show'] || $st['state'] === 'no_lease') {
        return '';
    }
    $tone = $st['state'] === 'match' ? 'ok' : ($st['state'] === 'blocked' ? 'err' : 'warn');
    $out = '<div class="retire-sync-box retire-sync-' . h($tone) . '">';
    $out .= '<div class="retire-sync-line"><b>สถานะเสื่อมสภาพ: ' . h($st['label']) . '</b></div>';
    $out .= '<div class="retire-sync-line muted">' . h($st['hint']) . '</div>';
    if ($st['can_sync'] && function_exists('can') && can('ma')) {
        $out .= '<form method="post" style="margin-top:8px" onsubmit="return confirm(\'ซิงก์สถานะเสื่อมสภาพของเครื่องนี้กับระบบเช่า?\');">'
              . csrf_field()
              . '<input type="hidden" name="retire_sync" value="1">'
              . '<button type="submit" class="btn btn-line btn-sm">ซิงก์สถานะกับระบบเช่า</button>'
              . '</form>';
    }
    $out .= '</div>';
    return $out;
}
