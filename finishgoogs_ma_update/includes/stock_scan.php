<?php
/**
 * stock_scan.php
 * ────────────────────────────────────────────────────────────────────────────────
 * นับสต็อกด้วยการสแกน QR บนมือถือ แล้วตัดสถานะเครื่องที่ไม่เจอ
 *
 * ขั้นตอน:
 *   1. เปิดรอบนับ — เลือกว่านับรุ่นไหนบ้าง (หรือทุกรุ่น)
 *   2. สแกน — ทุกครั้งที่สแกนบันทึกขึ้นเซิร์ฟเวอร์ทันที มือถือค้าง/รีเฟรช/สลับเครื่องก็ไม่หาย
 *      หลายคนสแกนเข้ารอบเดียวกันพร้อมกันได้
 *   3. ตรวจก่อนตัด — สรุปว่าแต่ละเครื่องจะเป็นสถานะอะไรเพราะอะไร เอากลุ่มที่ไม่แน่ใจออกได้
 *   4. ตัดสถานะ — เครื่องที่สแกนเจอ = อยู่ในคลัง · ไม่เจอแต่มีหลักฐาน = ตามหลักฐาน
 *      · ไม่เจอและไม่มีหลักฐานอะไรเลย = "ไม่มีสถานะ" (unknown)
 *
 * ทุกการเปลี่ยนสถานะเขียน stock_movements ในรูป "... (จาก → เป็น)" จึงย้อนกลับได้
 * จากหน้าเคลียร์เครื่องค้างสถานะ แท็บประวัติ
 * ────────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/stock_check.php';

/**
 * สร้างตารางรอบนับ + รายการที่สแกน (ครั้งแรกที่เปิดหน้า)
 *
 * @return void
 */
function ensure_stock_scan_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_stock_check_schema();
    try {
        db()->query(
            "CREATE TABLE IF NOT EXISTS stock_scan_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                started_at DATETIME NOT NULL,
                started_by VARCHAR(100) NOT NULL DEFAULT '',
                status ENUM('open','applied','cancelled') NOT NULL DEFAULT 'open',
                scope_all TINYINT(1) NOT NULL DEFAULT 0,
                scope_products TEXT NULL,
                note VARCHAR(255) NULL,
                applied_at DATETIME NULL,
                applied_by VARCHAR(100) NULL,
                result_json TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        db()->query(
            "CREATE TABLE IF NOT EXISTS stock_scan_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id BIGINT UNSIGNED NOT NULL,
                raw_code VARCHAR(255) NOT NULL,
                asset_id BIGINT UNSIGNED NULL,
                result ENUM('ok','not_found','out_of_scope') NOT NULL,
                scanned_at DATETIME NOT NULL,
                scanned_by VARCHAR(100) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                UNIQUE KEY uq_session_asset (session_id, asset_id),
                KEY idx_session (session_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // ตารางรุ่นแรกยังไม่มี prev_status — เพิ่มทีหลังเมื่อเปลี่ยนมาตั้งสถานะทันทีที่สแกน
        $col = db()->query("SHOW COLUMNS FROM stock_scan_items LIKE 'prev_status'");
        if ($col && $col->num_rows === 0) {
            db()->query("ALTER TABLE stock_scan_items ADD COLUMN prev_status VARCHAR(16) NULL AFTER asset_id");
        }
    } catch (\Throwable $e) {
        error_log('[ensure_stock_scan_schema] ' . $e->getMessage());
    }
}

/**
 * ชื่อคนที่ทำรายการ
 *
 * @return string
 */
function stock_scan_actor()
{
    return function_exists('actor_name') ? (actor_name() ?: 'system') : 'system';
}

/**
 * รอบนับที่ยังเปิดอยู่ (มีได้ทีละรอบ ทั้งทีมสแกนเข้ารอบเดียวกัน)
 *
 * @return array<string,mixed>|null
 */
function stock_scan_open_session()
{
    ensure_stock_scan_schema();
    $row = qr("SELECT * FROM stock_scan_sessions WHERE status = 'open' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    return $row ?: null;
}

/**
 * รอบนับตาม id
 *
 * @param int $id
 * @return array<string,mixed>|null
 */
function stock_scan_session($id)
{
    ensure_stock_scan_schema();
    $row = qr('SELECT * FROM stock_scan_sessions WHERE id = ? LIMIT 1', 'i', [(int) $id])->fetch_assoc();
    return $row ?: null;
}

/**
 * product id ที่อยู่ในขอบเขตของรอบนับ (ว่าง = ทุกรุ่น)
 *
 * @param array<string,mixed> $session
 * @return array<int,int>
 */
function stock_scan_scope_ids(array $session)
{
    if (!empty($session['scope_all'])) {
        return [];
    }
    $ids = [];
    foreach (explode(',', (string) $session['scope_products']) as $v) {
        $v = (int) $v;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    return array_values($ids);
}

/**
 * เปิดรอบนับใหม่
 *
 * @param array<int,int|string> $productIds ว่าง = ทุกรุ่น
 * @param string                $note
 * @return array{ok:bool, message:string, id:int}
 */
function stock_scan_start(array $productIds, $note = '')
{
    ensure_stock_scan_schema();
    if (stock_scan_open_session()) {
        return ['ok' => false, 'message' => 'มีรอบนับที่เปิดอยู่แล้ว — ปิดหรือยกเลิกรอบเดิมก่อน', 'id' => 0];
    }
    $ids = [];
    foreach ($productIds as $v) {
        $v = (int) $v;
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }
    $all = !$ids;
    $ins = q_try(
        "INSERT INTO stock_scan_sessions (started_at, started_by, status, scope_all, scope_products, note)
         VALUES (NOW(), ?, 'open', ?, ?, ?)",
        'siss',
        [
            stock_scan_actor(),
            $all ? 1 : 0,
            $all ? null : implode(',', $ids),
            trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 255) : null,
        ]
    );
    if (empty($ins['ok'])) {
        return ['ok' => false, 'message' => (string) ($ins['error'] ?? 'เปิดรอบนับไม่สำเร็จ'), 'id' => 0];
    }
    return ['ok' => true, 'message' => 'เปิดรอบนับแล้ว เริ่มสแกนได้เลย', 'id' => (int) $ins['insert_id']];
}

/**
 * แปลงข้อความที่สแกนได้เป็นรหัสเครื่อง — QR บางแผ่นเป็นลิงก์ asset.php?code=XXX
 *
 * @param string $raw
 * @return string
 */
function stock_scan_normalize($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    if (preg_match('~[?&]code=([^&#\s]+)~i', $raw, $m)) {
        $raw = rawurldecode($m[1]);
    }
    return strtoupper(trim($raw));
}

/**
 * สถานะที่ควรเป็นเมื่อสแกนเจอเครื่องในคลัง
 *
 * ไม่อยู่ในระบบเช่า = เครื่องใหม่ · ลงทะเบียนในระบบเช่าแล้ว = ตามกติกา sync (เช่า/เสื่อมสภาพ/สูญหาย)
 * เครื่องสำรองที่ตั้งเองคงไว้
 *
 * @param array<string,mixed> $asset  ต้องมี id, asset_code
 * @param string              $current
 * @return array{target:string, why:string, lease:string}
 */
function stock_scan_status_for(array $asset, $current)
{
    $current = trim((string) $current) ?: 'new';
    $lease = null;
    if (function_exists('asset_leasing_status_by_assets')) {
        $map = asset_leasing_status_by_assets([['id' => (int) $asset['id'], 'asset_code' => (string) $asset['asset_code'], 'factory_serial' => '']]);
        $lk = strtoupper(trim((string) $asset['asset_code']));
        if (!empty($map[$lk]['found'])) {
            $lease = $map[$lk];
        }
    }
    if ($current === 'spare') {
        return ['target' => 'spare', 'why' => 'เครื่องสำรอง', 'lease' => $lease ? trim((string) ($lease['pro_status'] ?? '')) : ''];
    }
    if ($lease && function_exists('asset_status_target_from_external')) {
        $t = asset_status_target_from_external($current, null, $lease);
        if (!empty($t['target'])) {
            return ['target' => (string) $t['target'], 'why' => 'สแกนเจอ · ' . $t['reason'], 'lease' => trim((string) ($lease['pro_status'] ?? ''))];
        }
    }
    return ['target' => 'new', 'why' => 'สแกนเจอในคลัง', 'lease' => ''];
}

/**
 * ตรวจรหัสที่สแกนได้โดยยังไม่บันทึกอะไร — ใช้แสดงการ์ดให้กดยืนยัน/ยกเลิกก่อนนับ
 *
 * @param int    $sessionId
 * @param string $raw
 * @return array{ok:bool, result:string, message:string, code:string, model:string, status:string, status_th:string, will_change:bool, lease:string}
 */
function stock_scan_check($sessionId, $raw)
{
    $session = stock_scan_session($sessionId);
    $out = [
        'ok' => false, 'result' => 'error', 'message' => '', 'code' => '', 'model' => '',
        'status' => '', 'status_th' => '', 'will_change' => false, 'lease' => '',
    ];
    if (!$session || $session['status'] !== 'open') {
        $out['message'] = 'รอบนับนี้ปิดไปแล้ว';
        return $out;
    }
    $code = stock_scan_normalize($raw);
    $out['code'] = $code;
    if ($code === '') {
        $out['message'] = 'อ่านรหัสไม่ได้';
        return $out;
    }
    $out['ok'] = true;

    $asset = qr(
        'SELECT a.id, a.asset_code, a.product_id, a.status, p.name AS pname
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE a.asset_code = ? OR a.factory_serial = ? LIMIT 1',
        'ss',
        [$code, $code]
    )->fetch_assoc();
    if (!$asset) {
        $out['result'] = 'not_found';
        $out['message'] = 'ไม่พบรหัสนี้ในทะเบียน';
        return $out;
    }

    $out['code'] = (string) $asset['asset_code'];
    $out['model'] = (string) $asset['pname'];
    $out['status'] = (string) $asset['status'];
    $out['status_th'] = status_th((string) $asset['status']);

    $scope = stock_scan_scope_ids($session);
    if ($scope && !in_array((int) $asset['product_id'], $scope, true)) {
        $out['result'] = 'out_of_scope';
        $out['message'] = 'รุ่น ' . $asset['pname'] . ' ไม่ได้อยู่ในรอบนับนี้';
        return $out;
    }

    $dup = qr(
        'SELECT scanned_at, scanned_by FROM stock_scan_items WHERE session_id = ? AND asset_id = ? LIMIT 1',
        'ii',
        [(int) $sessionId, (int) $asset['id']]
    )->fetch_assoc();
    if ($dup) {
        $out['result'] = 'dup';
        $out['message'] = 'สแกนไปแล้ว (' . substr((string) $dup['scanned_at'], 11, 5) . ' โดย ' . $dup['scanned_by'] . ')';
        return $out;
    }

    $out['result'] = 'ok';
    $plan = stock_scan_status_for($asset, (string) $asset['status']);
    $out['lease'] = $plan['lease'];
    $out['target'] = $plan['target'];
    $out['will_change'] = $plan['target'] !== (string) $asset['status'];
    $tail = $plan['lease'] !== '' ? ' (ลงทะเบียนในระบบเช่า ไม่นับเป็นสต็อกใหม่)' : '';
    $out['message'] = $out['will_change']
        ? 'ตอนนี้เป็น ' . $out['status_th'] . ' → จะเปลี่ยนเป็น' . status_th($plan['target']) . $tail
        : 'เป็น' . status_th($plan['target']) . 'อยู่แล้ว' . $tail;
    return $out;
}

/**
 * บันทึกการสแกนหนึ่งครั้ง
 *
 * @param int    $sessionId
 * @param string $raw
 * @return array{ok:bool, result:string, message:string, code:string, model:string, count:int}
 */
function stock_scan_add($sessionId, $raw)
{
    $session = stock_scan_session($sessionId);
    $out = ['ok' => false, 'result' => 'error', 'message' => '', 'code' => '', 'model' => '', 'count' => 0];
    if (!$session || $session['status'] !== 'open') {
        $out['message'] = 'รอบนับนี้ปิดไปแล้ว';
        return $out;
    }
    $code = stock_scan_normalize($raw);
    $out['code'] = $code;
    if ($code === '') {
        $out['message'] = 'อ่านรหัสไม่ได้';
        return $out;
    }

    $asset = qr(
        'SELECT a.id, a.asset_code, a.product_id, p.name AS pname
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE a.asset_code = ? OR a.factory_serial = ? LIMIT 1',
        'ss',
        [$code, $code]
    )->fetch_assoc();

    $scope = stock_scan_scope_ids($session);
    if (!$asset) {
        $result = 'not_found';
        $out['message'] = 'ไม่พบรหัสนี้ในทะเบียน';
    } elseif ($scope && !in_array((int) $asset['product_id'], $scope, true)) {
        $result = 'out_of_scope';
        $out['model'] = (string) $asset['pname'];
        $out['message'] = 'รุ่น ' . $asset['pname'] . ' ไม่ได้อยู่ในรอบนับนี้';
    } else {
        $result = 'ok';
        $out['model'] = (string) $asset['pname'];
        $dup = qr(
            'SELECT scanned_at, scanned_by FROM stock_scan_items WHERE session_id = ? AND asset_id = ? LIMIT 1',
            'ii',
            [(int) $sessionId, (int) $asset['id']]
        )->fetch_assoc();
        if ($dup) {
            $out['ok'] = true;
            $out['result'] = 'dup';
            $out['code'] = (string) $asset['asset_code'];
            $out['message'] = 'สแกนไปแล้ว (' . substr((string) $dup['scanned_at'], 11, 5) . ' โดย ' . $dup['scanned_by'] . ')';
            $out['count'] = stock_scan_count($sessionId);
            return $out;
        }
        $out['code'] = (string) $asset['asset_code'];
        $out['message'] = 'นับแล้ว';
    }

    // สแกนเจอในคลัง = ตั้งสถานะให้ทันที เฉพาะเครื่องนี้ ไม่แตะเครื่องอื่น
    //   ไม่อยู่ในระบบเช่า → เครื่องใหม่
    //   ลงทะเบียนในระบบเช่าแล้ว → เครื่องเช่า (หรือเสื่อมสภาพ/สูญหายตามระบบเช่า) ไม่นับเป็นสต็อกใหม่
    $prev = null;
    if ($asset && $result === 'ok') {
        $cur = qr('SELECT status FROM assets WHERE id = ? LIMIT 1', 'i', [(int) $asset['id']])->fetch_assoc();
        $prev = $cur ? (string) $cur['status'] : null;
        $plan = stock_scan_status_for($asset, (string) $prev);
        if ($plan['lease'] !== '') {
            $out['lease'] = $plan['lease'];
        }
        $to = $plan['target'];
        if ($prev !== null && $to !== $prev) {
            q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$to, (int) $asset['id']]);
            q(
                'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
                'isss',
                [(int) $asset['id'], $to === 'new' ? 'in' : 'out', 'นับสต็อก (สแกน) รอบ #' . (int) $sessionId . ': ' . $plan['why'] . ' (' . $prev . ' → ' . $to . ')', stock_scan_actor()]
            );
            $out['changed_from'] = $prev;
            $out['changed_to'] = $to;
            $out['message'] = 'เปลี่ยนจาก ' . status_th($prev) . ' → ' . status_th($to) . ($plan['lease'] !== '' ? ' (ลงทะเบียนในระบบเช่า)' : '');
        } else {
            $prev = null;   // สถานะถูกอยู่แล้ว ไม่มีอะไรต้องย้อน
            if ($plan['lease'] !== '') {
                $out['message'] = 'เป็น' . status_th((string) $to) . 'อยู่แล้ว (ลงทะเบียนในระบบเช่า)';
            }
        }
    }

    // ไม่พบ/นอกขอบเขต ก็เก็บไว้ จะได้ตามต่อทีหลังว่าเป็นเครื่องอะไร
    q_try(
        'INSERT INTO stock_scan_items (session_id, raw_code, asset_id, prev_status, result, scanned_at, scanned_by)
         VALUES (?, ?, ?, ?, ?, NOW(), ?)',
        'isisss',
        [(int) $sessionId, mb_substr($code, 0, 255), $asset && $result === 'ok' ? (int) $asset['id'] : null, $prev, $result, stock_scan_actor()]
    );
    $out['ok'] = true;
    $out['result'] = $result;
    $out['count'] = stock_scan_count($sessionId);
    return $out;
}

/**
 * ลบรายการสแกนที่ยิงผิด
 *
 * @param int $sessionId
 * @param int $itemId
 * @return bool
 */
function stock_scan_remove($sessionId, $itemId)
{
    $session = stock_scan_session($sessionId);
    if (!$session || $session['status'] !== 'open') {
        return false;
    }
    $item = qr(
        'SELECT asset_id, prev_status FROM stock_scan_items WHERE id = ? AND session_id = ? LIMIT 1',
        'ii',
        [(int) $itemId, (int) $sessionId]
    )->fetch_assoc();
    if ($item && $item['asset_id'] && (string) $item['prev_status'] !== '') {
        stock_scan_revert_one((int) $sessionId, (int) $item['asset_id'], (string) $item['prev_status'], 'ลบรายการที่สแกนผิด');
    }
    q('DELETE FROM stock_scan_items WHERE id = ? AND session_id = ?', 'ii', [(int) $itemId, (int) $sessionId]);
    return true;
}

/**
 * คืนสถานะเดิมของเครื่องที่ถูกตั้งเป็นเครื่องใหม่ตอนสแกน
 *
 * คืนเฉพาะเมื่อยังเป็นเครื่องใหม่อยู่ — ถ้ามีคนแก้ต่อไปแล้วไม่ทับของเขา
 *
 * @param int    $sessionId
 * @param int    $assetId
 * @param string $prev
 * @param string $why
 * @return bool
 */
function stock_scan_revert_one($sessionId, $assetId, $prev, $why)
{
    $cur = qr('SELECT status FROM assets WHERE id = ? LIMIT 1', 'i', [(int) $assetId])->fetch_assoc();
    if (!$cur || $prev === '' || (string) $cur['status'] === $prev) {
        return false;
    }
    // คืนเฉพาะเมื่อการเปลี่ยนล่าสุดของเครื่องนี้มาจากการสแกนรอบนี้ — มีคนแก้ต่อไปแล้วไม่ทับของเขา
    $last = qr('SELECT reason FROM stock_movements WHERE asset_id = ? ORDER BY id DESC LIMIT 1', 'i', [(int) $assetId])->fetch_row();
    if (!$last || strpos((string) $last[0], 'นับสต็อก (สแกน) รอบ #' . (int) $sessionId . ':') !== 0) {
        return false;
    }
    $now = (string) $cur['status'];
    q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$prev, (int) $assetId]);
    q(
        'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
        'isss',
        [(int) $assetId, $prev === 'new' ? 'in' : 'out', 'นับสต็อก (สแกน) รอบ #' . (int) $sessionId . ': ' . $why . ' (' . $now . ' → ' . $prev . ')', stock_scan_actor()]
    );
    return true;
}

/**
 * รายการที่สแกนทั้งหมดของรอบ พร้อมสถานะเดิมและสถานะตอนนี้
 *
 * @param int $sessionId
 * @return array<int,array<string,mixed>>
 */
function stock_scan_items_all($sessionId)
{
    $rows = [];
    $res = qr(
        "SELECT i.id, i.raw_code, i.result, i.prev_status, i.scanned_at, i.scanned_by,
                a.id AS asset_id, a.asset_code, a.status AS cur_status, p.name AS pname
         FROM stock_scan_items i
         LEFT JOIN assets a ON a.id = i.asset_id
         LEFT JOIN products p ON p.id = a.product_id
         WHERE i.session_id = ?
         ORDER BY (i.result = 'ok') DESC, p.name, i.id",
        'i',
        [(int) $sessionId]
    );
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * รอบนับย้อนหลัง
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function stock_scan_sessions_list($limit = 10)
{
    $rows = [];
    $res = qr(
        "SELECT s.*, (SELECT COUNT(*) FROM stock_scan_items i WHERE i.session_id = s.id AND i.result = 'ok') AS scanned
         FROM stock_scan_sessions s ORDER BY s.id DESC LIMIT " . max(1, min(50, (int) $limit))
    );
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * ข้อมูลผลลัพธ์ที่บันทึกไว้ของรอบนับ
 *
 * @param array<string,mixed> $session
 * @return array<string,mixed>
 */
function stock_scan_result(array $session)
{
    $r = json_decode((string) ($session['result_json'] ?? ''), true);
    return is_array($r) ? $r : [];
}

/**
 * รอบนับนี้ยังตัดสถานะเครื่องที่ไม่เจอได้ไหม
 * (รอบที่เปิดอยู่ หรือรอบที่จบแบบ "เก็บเฉพาะที่สแกน" และยังไม่เคยตัด)
 *
 * @param array<string,mixed> $session
 * @return bool
 */
function stock_scan_can_cut(array $session)
{
    if ($session['status'] === 'open') {
        return true;
    }
    if ($session['status'] !== 'applied') {
        return false;
    }
    $r = stock_scan_result($session);
    return ($r['mode'] ?? '') === 'scanned_only';
}

/**
 * สรุปรอบนับ สำหรับหน้าหลังจบรอบ
 *
 * @param int $sessionId
 * @return array{scanned:int, changed:int, from:array<string,int>, models:array<int,array{name:string,n:int}>, not_found:array<int,string>, out_of_scope:array<int,string>}
 */
function stock_scan_summary($sessionId)
{
    $sid = (int) $sessionId;
    $out = ['scanned' => 0, 'changed' => 0, 'from' => [], 'models' => [], 'not_found' => [], 'out_of_scope' => []];
    $res = qr(
        "SELECT i.result, i.raw_code, i.prev_status, p.name AS pname
         FROM stock_scan_items i
         LEFT JOIN assets a ON a.id = i.asset_id
         LEFT JOIN products p ON p.id = a.product_id
         WHERE i.session_id = ? ORDER BY i.id",
        'i',
        [$sid]
    );
    $models = [];
    while ($r = $res->fetch_assoc()) {
        if ($r['result'] === 'ok') {
            $out['scanned']++;
            $name = (string) $r['pname'];
            $models[$name] = ($models[$name] ?? 0) + 1;
            $prev = (string) $r['prev_status'];
            if ($prev !== '') {
                $out['changed']++;
                $out['from'][$prev] = ($out['from'][$prev] ?? 0) + 1;
            }
        } elseif ($r['result'] === 'not_found') {
            $out['not_found'][] = (string) $r['raw_code'];
        } else {
            $out['out_of_scope'][] = (string) $r['raw_code'];
        }
    }
    arsort($models);
    foreach ($models as $name => $n) {
        $out['models'][] = ['name' => $name, 'n' => $n];
    }
    return $out;
}

/**
 * จบรอบนับ — เก็บสถานะที่สแกนไว้ ไม่เปลี่ยนเครื่องอื่น
 *
 * @param int $sessionId
 * @return void
 */
function stock_scan_finish($sessionId)
{
    q(
        "UPDATE stock_scan_sessions SET status = 'applied', applied_at = NOW(), applied_by = ?, result_json = ? WHERE id = ? AND status = 'open'",
        'ssi',
        [stock_scan_actor(), json_encode(['mode' => 'scanned_only'], JSON_UNESCAPED_UNICODE), (int) $sessionId]
    );
}

/**
 * จำนวนเครื่องที่นับได้ในรอบ (เฉพาะที่อยู่ในขอบเขต)
 *
 * @param int $sessionId
 * @return int
 */
function stock_scan_count($sessionId)
{
    return (int) qr(
        "SELECT COUNT(*) FROM stock_scan_items WHERE session_id = ? AND result = 'ok'",
        'i',
        [(int) $sessionId]
    )->fetch_row()[0];
}

/**
 * รายการที่สแกนล่าสุด
 *
 * @param int $sessionId
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function stock_scan_recent($sessionId, $limit = 30)
{
    $rows = [];
    $res = qr(
        'SELECT i.id, i.raw_code, i.result, i.scanned_at, i.scanned_by, a.asset_code, p.name AS pname
         FROM stock_scan_items i
         LEFT JOIN assets a ON a.id = i.asset_id
         LEFT JOIN products p ON p.id = a.product_id
         WHERE i.session_id = ?
         ORDER BY i.id DESC LIMIT ' . max(1, min(200, (int) $limit)),
        'i',
        [(int) $sessionId]
    );
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * นับได้กี่เครื่องต่อรุ่น เทียบกับที่ทะเบียนบอกว่าอยู่ในคลัง
 *
 * @param array<string,mixed> $session
 * @return array<int,array{product_id:int, name:string, scanned:int, in_stock:int}>
 */
function stock_scan_progress(array $session)
{
    $scope = stock_scan_scope_ids($session);
    $where = $scope ? 'WHERE p.id IN (' . implode(',', array_map('intval', $scope)) . ')' : 'WHERE p.is_active = 1';
    $rows = [];
    $res = qr(
        "SELECT p.id, p.name,
                SUM(a.status = 'new') AS in_stock,
                COUNT(a.id) AS total
         FROM products p LEFT JOIN assets a ON a.product_id = p.id
         {$where}
         GROUP BY p.id
         HAVING total > 0
         ORDER BY p.name"
    );
    while ($r = $res->fetch_assoc()) {
        $rows[(int) $r['id']] = [
            'product_id' => (int) $r['id'],
            'name'       => (string) $r['name'],
            'scanned'    => 0,
            'in_stock'   => (int) $r['in_stock'],
        ];
    }
    $res = qr(
        "SELECT a.product_id, COUNT(*) n FROM stock_scan_items i JOIN assets a ON a.id = i.asset_id
         WHERE i.session_id = ? AND i.result = 'ok' GROUP BY a.product_id",
        'i',
        [(int) $session['id']]
    );
    while ($r = $res->fetch_assoc()) {
        if (isset($rows[(int) $r['product_id']])) {
            $rows[(int) $r['product_id']]['scanned'] = (int) $r['n'];
        }
    }
    return array_values($rows);
}

/**
 * ตัดสินสถานะของเครื่องหนึ่งเครื่องหลังนับ
 *
 * @param array<string,mixed>      $asset
 * @param bool                     $scanned
 * @param array<string,mixed>|null $sale
 * @param array<string,mixed>|null $lease
 * @param array<string,mixed>      $issued
 * @return array{target:?string, key:string, reason:string}
 */
function stock_scan_target(array $asset, $scanned, $sale, $lease, array $issued, $install = null)
{
    $current = trim((string) ($asset['status'] ?? '')) ?: 'new';
    $pro = $lease && !empty($lease['found']) ? trim((string) ($lease['pro_status'] ?? '')) : '';
    $p = $lease && !empty($lease['found']) ? trim((string) ($lease['p_status'] ?? '')) : '';
    $withCustomer = ($p === 'active' || $pro === 'rent');
    $pool = ($pro === 'finished goods' || $p === 'received');

    // วางอยู่ในคลังจริง = เครื่องใหม่ — ยกเว้นเครื่องที่ลงทะเบียนในระบบเช่า ซึ่งนับเป็นเครื่องเช่าเสมอ
    $inLease = $lease && !empty($lease['found']);
    if ($scanned && !$inLease) {
        return ['target' => 'new', 'key' => 'scan_found', 'reason' => 'สแกนเจอในคลัง'];
    }

    // เครื่องสำรองตั้งด้วยมือเสมอ และ cron sync ก็ไม่แตะ — ไม่ได้สแกนก็คงไว้ ไม่เอาหลักฐานอื่นมาทับ
    if ($current === 'spare') {
        return ['target' => null, 'key' => 'keep_manual', 'reason' => 'คงไว้ตามที่บันทึก (เครื่องสำรอง)'];
    }

    // ไม่เจอตอนนับ — ใช้หลักฐานจากระบบอื่นก่อน
    if ($withCustomer) {
        return ['target' => 'rental', 'key' => 'lease_rent', 'reason' => 'ระบบเช่า: อยู่กับลูกค้า'];
    }
    if ($pro === 'Asset Retirement') {
        return ['target' => 'retired', 'key' => 'lease_retired', 'reason' => 'ระบบเช่า: ปลดระวาง'];
    }
    if ($pro === 'Lost' || $p === 'Lost') {
        return ['target' => 'lost', 'key' => 'lease_lost', 'reason' => 'ระบบเช่า: สูญหาย'];
    }
    if ($pool) {
        return ['target' => 'rental', 'key' => 'lease_pool', 'reason' => 'ระบบเช่า: อยู่ในคลังเช่า'];
    }
    if ($inLease) {
        return ['target' => 'rental', 'key' => 'lease_pool', 'reason' => 'ระบบเช่า: อยู่ในคลังเช่า'];
    }
    $sn = strtoupper(trim((string) ($asset['asset_code'] ?? '')));
    $fs = strtoupper(trim((string) ($asset['factory_serial'] ?? '')));
    if (isset($issued[$sn]) || ($fs !== '' && isset($issued[$fs]))) {
        return ['target' => 'sold', 'key' => 'setup_issued', 'reason' => 'ระบบ Setup: เบิกออกแล้ว มีเลข PO'];
    }
    if ($sale && !empty($sale['sold'])) {
        return ['target' => 'sold', 'key' => 'stock_sold', 'reason' => 'ระบบสต็อก: มีใบเบิกหรือประวัติส่งมอบ'];
    }
    if (in_array($current, ['new', 'unknown'], true) && function_exists('installation_history_status_hint')
        && installation_history_status_hint($asset, $install)) {
        return ['target' => 'sold', 'key' => 'install_history', 'reason' => 'ระบบ installation เดิม: มีประวัติติดตั้งให้ลูกค้า'];
    }

    // คนบันทึกเองว่าเป็นเครื่องสำรอง/เสื่อมสภาพ/สูญหาย — เชื่อตามนั้น ไม่ต้องล้าง
    if (in_array($current, ['spare', 'retired', 'lost'], true)) {
        return ['target' => null, 'key' => 'keep_manual', 'reason' => 'คงไว้ตามที่บันทึก (' . status_th($current) . ')'];
    }

    // ไม่เจอ และไม่มีหลักฐานอะไรเลย
    if ($current === 'sold') {
        return ['target' => 'unknown', 'key' => 'unknown_sold', 'reason' => 'เคยเป็นขายแล้ว แต่ไม่มีหลักฐานการขาย'];
    }
    if ($current === 'rental') {
        return ['target' => 'unknown', 'key' => 'unknown_rental', 'reason' => 'เคยเป็นเครื่องเช่า แต่ไม่มีในระบบเช่า'];
    }
    return ['target' => 'unknown', 'key' => 'unknown_missing', 'reason' => 'ไม่เจอตอนนับ และไม่มีหลักฐานอื่น'];
}

/**
 * สรุปสิ่งที่จะเปลี่ยน (ดูก่อน) หรือบันทึกจริง
 *
 * @param int               $sessionId
 * @param bool              $apply
 * @param array<int,string> $skipKeys กลุ่มที่ผู้ใช้ติ๊กออก ไม่ต้องเปลี่ยน
 * @return array{ok:bool, message:string, total:int, changed:int, groups:array<string,array<string,mixed>>, not_found:array<int,string>}
 */
function stock_scan_plan($sessionId, $apply = false, array $skipKeys = [])
{
    @set_time_limit(600);
    $session = stock_scan_session($sessionId);
    $empty = ['ok' => false, 'message' => '', 'total' => 0, 'changed' => 0, 'groups' => [], 'not_found' => []];
    if (!$session) {
        $empty['message'] = 'ไม่พบรอบนับ';
        return $empty;
    }
    if ($apply && !stock_scan_can_cut($session)) {
        $empty['message'] = 'รอบนับนี้ตัดสถานะเครื่องอื่นไปแล้ว หรือถูกยกเลิก';
        return $empty;
    }
    $skip = array_flip($skipKeys);

    $scanned = [];
    $res = qr("SELECT asset_id FROM stock_scan_items WHERE session_id = ? AND result = 'ok'", 'i', [(int) $sessionId]);
    while ($r = $res->fetch_row()) {
        $scanned[(int) $r[0]] = true;
    }
    $notFound = [];
    $res = qr(
        "SELECT raw_code FROM stock_scan_items WHERE session_id = ? AND result <> 'ok' ORDER BY id",
        'i',
        [(int) $sessionId]
    );
    while ($r = $res->fetch_row()) {
        $notFound[] = (string) $r[0];
    }

    // รอบที่ไม่ได้สแกนอะไรเลย ห้ามตัด — ไม่งั้นทุกเครื่องในขอบเขตถูกมองว่า "ไม่เจอ" ทั้งหมด
    if ($apply && !$scanned) {
        $empty['message'] = 'รอบนี้ยังไม่ได้สแกนเครื่องไหนเลย ตัดสถานะเครื่องอื่นไม่ได้';
        return $empty;
    }

    // เครื่องที่สแกนเจอในรอบอื่นภายใน 30 วันก็นับว่าอยู่ในคลัง — นับสต็อกแบ่งทำหลายรอบได้
    $prevScanned = function_exists('asset_status_scanned_ids') ? asset_status_scanned_ids() : [];

    $scope = stock_scan_scope_ids($session);
    $scopeSql = $scope ? ' AND product_id IN (' . implode(',', array_map('intval', $scope)) . ')' : '';
    $issued = stock_check_issued_map();
    $issuedMap = $issued['map'];
    $actor = stock_scan_actor();
    $tag = 'นับสต็อก (สแกน) รอบ #' . (int) $sessionId . ': ';

    // บันทึกผลนับรายรุ่นลงตารางนับสต็อกเดิมก่อนเปลี่ยนสถานะ — แท็บค้างเก่า/ปุ่มซิงก์จะรู้ว่าเครื่องไหน "นับเจอ"
    if ($apply) {
        $prodRes = qr('SELECT DISTINCT product_id FROM assets WHERE 1=1' . $scopeSql);
        while ($pr = $prodRes->fetch_row()) {
            $pid = (int) $pr[0];
            $found = [];
            $missing = [];
            $ar = qr("SELECT id FROM assets WHERE product_id = ? AND status IN ('new','unknown')", 'i', [$pid]);
            while ($a = $ar->fetch_row()) {
                if (isset($scanned[(int) $a[0]]) || isset($prevScanned[(int) $a[0]])) {
                    $found[] = (int) $a[0];
                } else {
                    $missing[] = (int) $a[0];
                }
            }
            // เครื่องที่สแกนเจอแต่สถานะเดิมไม่ใช่ "ใหม่" ก็นับว่าเจอด้วย
            $sr = qr(
                "SELECT i.asset_id FROM stock_scan_items i JOIN assets a ON a.id = i.asset_id
                 WHERE i.session_id = ? AND i.result = 'ok' AND a.product_id = ? AND a.status NOT IN ('new','unknown')",
                'ii',
                [(int) $sessionId, $pid]
            );
            while ($a = $sr->fetch_row()) {
                $found[] = (int) $a[0];
            }
            if (!$found && !$missing) {
                continue;
            }
            $ins = q_try(
                'INSERT INTO stock_counts (product_id, counted_at, counted_by, found_qty, missing_qty, note) VALUES (?, NOW(), ?, ?, ?, ?)',
                'isiis',
                [$pid, $actor, count($found), count($missing), 'สแกน QR รอบ #' . (int) $sessionId]
            );
            if (!empty($ins['ok'])) {
                $cid = (int) $ins['insert_id'];
                foreach ($found as $aid) {
                    q('INSERT IGNORE INTO stock_count_items (count_id, asset_id, found) VALUES (?, ?, 1)', 'ii', [$cid, $aid]);
                }
                foreach ($missing as $aid) {
                    q('INSERT IGNORE INTO stock_count_items (count_id, asset_id, found) VALUES (?, ?, 0)', 'ii', [$cid, $aid]);
                }
            }
        }
    }

    $groups = [];
    $total = 0;
    $changed = 0;
    $lastId = 0;
    while (true) {
        $rows = [];
        $res = qr(
            'SELECT id, asset_code, factory_serial, status, product_id, produced_at
             FROM assets WHERE id > ?' . $scopeSql . ' ORDER BY id LIMIT 800',
            'i',
            [$lastId]
        );
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
            $lastId = (int) $r['id'];
        }
        if (!$rows) {
            break;
        }
        $codes = [];
        foreach ($rows as $r) {
            if (trim((string) $r['asset_code']) !== '') {
                $codes[] = trim((string) $r['asset_code']);
            }
        }
        $saleMap = ($codes && function_exists('asset_stockparts_sale_status_by_sn')) ? asset_stockparts_sale_status_by_sn($codes) : [];
        $leaseMap = function_exists('asset_leasing_status_by_assets') ? asset_leasing_status_by_assets($rows) : [];
        $installMap = function_exists('installation_history_map') ? installation_history_map($rows) : [];

        foreach ($rows as $r) {
            $total++;
            $key = strtoupper(trim((string) $r['asset_code']));
            $aid = (int) $r['id'];
            if (!isset($scanned[$aid]) && isset($prevScanned[$aid]) && trim((string) $r['status']) !== 'spare'
                && empty($leaseMap[$key]['found'])) {
                $d = ['target' => 'new', 'key' => 'scan_prev', 'reason' => 'สแกนเจอในรอบนับก่อนหน้า (ภายใน 30 วัน)'];
            } else {
                $d = stock_scan_target($r, isset($scanned[$aid]), $saleMap[$key] ?? null, $leaseMap[$key] ?? null, $issuedMap, $installMap[$key] ?? null);
            }
            $from = trim((string) $r['status']) ?: 'new';
            $to = $d['target'];
            $gk = $d['key'];
            if (!isset($groups[$gk])) {
                $groups[$gk] = ['key' => $gk, 'reason' => $d['reason'], 'to' => $to, 'n' => 0, 'change' => 0, 'from' => [], 'sample' => []];
            }
            $groups[$gk]['n']++;
            if ($to === null || $to === $from) {
                continue;
            }
            $groups[$gk]['change']++;
            $groups[$gk]['from'][$from] = ($groups[$gk]['from'][$from] ?? 0) + 1;
            if (count($groups[$gk]['sample']) < 5) {
                $groups[$gk]['sample'][] = (string) $r['asset_code'];
            }
            if (isset($skip[$gk])) {
                continue;
            }
            $changed++;
            if ($apply) {
                q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$to, (int) $r['id']]);
                q(
                    'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
                    'isss',
                    [(int) $r['id'], $to === 'new' ? 'in' : 'out', $tag . $d['reason'] . ' (' . $from . ' → ' . $to . ')', $actor]
                );
            }
        }
    }

    if ($apply) {
        q(
            "UPDATE stock_scan_sessions SET status = 'applied', applied_at = COALESCE(applied_at, NOW()), applied_by = COALESCE(applied_by, ?), result_json = ? WHERE id = ?",
            'ssi',
            [$actor, json_encode(['mode' => 'cut', 'changed' => $changed, 'skipped' => array_keys($skip), 'cut_at' => date('Y-m-d H:i:s'), 'cut_by' => $actor], JSON_UNESCAPED_UNICODE), (int) $sessionId]
        );
    }

    // กลุ่มที่เปลี่ยนเยอะขึ้นก่อน
    uasort($groups, static function ($a, $b) {
        return $b['change'] - $a['change'] ?: $b['n'] - $a['n'];
    });
    return [
        'ok'        => true,
        'message'   => $apply ? 'ตัดสถานะแล้ว ' . number_format($changed) . ' เครื่อง' : '',
        'total'     => $total,
        'changed'   => $changed,
        'groups'    => $groups,
        'not_found' => $notFound,
    ];
}

/**
 * ยกเลิกรอบนับ — คืนสถานะเดิมของเครื่องที่ถูกตั้งเป็นเครื่องใหม่ตอนสแกน
 *
 * @param int $sessionId
 * @return int จำนวนเครื่องที่คืนสถานะ
 */
function stock_scan_cancel($sessionId)
{
    $res = qr(
        "SELECT asset_id, prev_status FROM stock_scan_items
         WHERE session_id = ? AND result = 'ok' AND prev_status IS NOT NULL AND prev_status <> ''",
        'i',
        [(int) $sessionId]
    );
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        if (stock_scan_revert_one((int) $sessionId, (int) $r['asset_id'], (string) $r['prev_status'], 'ยกเลิกรอบนับ')) {
            $n++;
        }
    }
    q("UPDATE stock_scan_sessions SET status = 'cancelled' WHERE id = ? AND status = 'open'", 'i', [(int) $sessionId]);
    return $n;
}

// ─ รอบนับสต็อก ───────────────────────────────────────────────────────────────

/** @var string คีย์ใน site_settings — นับสต็อกแต่ละรุ่นอย่างน้อยทุกกี่วัน */
const STOCK_COUNT_INTERVAL_KEY = 'stock_count_interval_days';

/** @var int ค่าเริ่มต้นของรอบนับ (วัน) */
const STOCK_COUNT_INTERVAL_DEFAULT = 90;

/**
 * รอบนับสต็อก (วัน)
 *
 * @return int
 */
function stock_count_interval_days()
{
    $v = function_exists('setting') ? (int) setting(STOCK_COUNT_INTERVAL_KEY, STOCK_COUNT_INTERVAL_DEFAULT) : STOCK_COUNT_INTERVAL_DEFAULT;
    return max(7, min(365, $v > 0 ? $v : STOCK_COUNT_INTERVAL_DEFAULT));
}

/**
 * วันที่นับล่าสุดของแต่ละรุ่น
 *
 * นับว่า "นับแล้ว" ถ้า: มีผลนับในตาราง stock_counts (ตัดสถานะรอบสแกน / เครื่องมือนับเดิม)
 * หรือมีเครื่องของรุ่นนั้นถูกสแกนเจอในรอบนับที่ยังไม่ยกเลิก — แบ่งนับทีละรุ่นหลายรอบก็นับ
 *
 * @return array<int,string> product_id => วันเวลา (Y-m-d H:i:s)
 */
function stock_count_last_by_product()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $has = function ($table) {
        $r = db()->query("SHOW TABLES LIKE '" . db()->real_escape_string($table) . "'");
        return $r && $r->num_rows > 0;
    };
    try {
        if ($has('stock_counts')) {
            $res = qr('SELECT product_id, MAX(counted_at) AS at FROM stock_counts GROUP BY product_id');
            while ($r = $res->fetch_assoc()) {
                $cache[(int) $r['product_id']] = (string) $r['at'];
            }
        }
        if ($has('stock_scan_items')) {
            $res = qr(
                "SELECT a.product_id, MAX(i.scanned_at) AS at
                 FROM stock_scan_items i
                 JOIN stock_scan_sessions s ON s.id = i.session_id
                 JOIN assets a ON a.id = i.asset_id
                 WHERE i.result = 'ok' AND s.status IN ('open', 'applied')
                 GROUP BY a.product_id"
            );
            while ($r = $res->fetch_assoc()) {
                $pid = (int) $r['product_id'];
                if (!isset($cache[$pid]) || (string) $r['at'] > $cache[$pid]) {
                    $cache[$pid] = (string) $r['at'];
                }
            }
        }
    } catch (\Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * สถานะรอบนับของรุ่นหนึ่ง
 *
 * @param int $productId
 * @return array{last:string, days:?int, due:bool, label:string}
 */
function stock_count_status($productId)
{
    $all = stock_count_last_by_product();
    $interval = stock_count_interval_days();
    $last = (string) ($all[(int) $productId] ?? '');
    if ($last === '') {
        return ['last' => '', 'days' => null, 'due' => true, 'label' => 'ยังไม่เคยนับ'];
    }
    $days = (int) floor((time() - strtotime($last)) / 86400);
    return [
        'last'  => $last,
        'days'  => $days,
        'due'   => $days > $interval,
        'label' => $days > $interval ? 'ไม่ได้นับ ' . number_format($days) . ' วัน' : 'นับล่าสุด ' . dthai(substr($last, 0, 10)),
    ];
}
