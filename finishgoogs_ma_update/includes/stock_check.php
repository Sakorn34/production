<?php
/**
 * stock_check.php
 * ────────────────────────────────────────────────────────────────────────────────
 * เคลียร์เครื่องที่ยังค้างสถานะ "เครื่องใหม่" ทั้งที่ออกจากคลังไปแล้ว
 *
 * ทะเบียนเราเปลี่ยนสถานะเมื่อมีคนบันทึกเท่านั้น เครื่องรุ่นเก่าที่ขายออกไปตั้งแต่ก่อนมีระบบ
 * จึงค้างเป็น "ใหม่" อยู่หลายพันเครื่อง ทำให้ยอดคงคลังสูงเกินจริง ไฟล์นี้ให้ 3 ทาง:
 *
 *   1. หลักฐาน  — S/N ที่ระบบ Setup บันทึกว่าเบิกออกไปแล้ว (ขาย/ซื้อ/เคลม) ปิดได้เลย
 *   2. ค้างเก่า — เครื่องที่ยังใหม่แต่เก่ากว่าวันนับสต็อกของรุ่นนั้น ให้คนเลือกปิดเอง
 *   3. นับสต็อก — นับของจริงในคลัง เครื่องที่ไม่เจอตอนนับจะถูกยกมาให้ปิดในข้อ 2
 *
 * ทุกการเปลี่ยนสถานะเขียน stock_movements ไว้เสมอ ย้อนดูได้ว่าใครเปลี่ยนเมื่อไหร่ เพราะอะไร
 * ────────────────────────────────────────────────────────────────────────────────
 */

/** @var array<int,string> สถานะที่ปิดเครื่องออกจากคลังได้จากหน้านี้ */
const STOCK_CHECK_CLOSE_STATUSES = ['sold', 'retired', 'lost'];

/**
 * สร้างตารางเก็บประวัติการนับสต็อก (ครั้งแรกที่เปิดหน้า)
 *
 * @return void
 */
function ensure_stock_check_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->query(
            "CREATE TABLE IF NOT EXISTS stock_counts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                counted_at DATETIME NOT NULL,
                counted_by VARCHAR(100) NOT NULL DEFAULT '',
                found_qty INT UNSIGNED NOT NULL DEFAULT 0,
                missing_qty INT UNSIGNED NOT NULL DEFAULT 0,
                note VARCHAR(255) NULL,
                PRIMARY KEY (id),
                KEY idx_product (product_id, counted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        db()->query(
            "CREATE TABLE IF NOT EXISTS stock_count_items (
                count_id BIGINT UNSIGNED NOT NULL,
                asset_id BIGINT UNSIGNED NOT NULL,
                found TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (count_id, asset_id),
                KEY idx_asset (asset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\Throwable $e) {
        error_log('[ensure_stock_check_schema] ' . $e->getMessage());
    }
}

// ─ 1) หลักฐานจากระบบ Setup ───────────────────────────────────────────────────

/**
 * S/N ที่ระบบ Setup บันทึกว่าเบิกออกจากคลังไปแล้ว
 *
 * @return array{ok:bool, error:string, map:array<string,array<string,string>>}
 */
function stock_check_issued_map()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $setup = dbSetup();
    if (!$setup) {
        $cache = ['ok' => false, 'error' => 'ต่อฐาน setup ไม่ได้', 'map' => []];
        return $cache;
    }
    $map = [];
    $res = $setup->query(
        "SELECT UPPER(TRIM(serial_number)) AS sn, issue_type, issue_date, po_number, company_name
         FROM po_order_part_serials
         WHERE serial_number IS NOT NULL AND TRIM(serial_number) <> ''
           AND issue_date IS NOT NULL AND issue_date <> '0000-00-00'
         ORDER BY issue_date"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        // แถวล่าสุดของ S/N เดิมชนะ (เครื่องเคลมกลับมาแล้วออกไปใหม่)
        $map[(string) $r['sn']] = [
            'type'    => (string) $r['issue_type'],
            'date'    => (string) $r['issue_date'],
            'po'      => trim((string) $r['po_number']),
            'company' => trim((string) $r['company_name']),
        ];
    }
    $cache = ['ok' => true, 'error' => '', 'map' => $map];
    return $cache;
}

/**
 * ป้ายไทยของประเภทการเบิกออก
 *
 * @param string $type
 * @return string
 */
function stock_check_issue_label($type)
{
    $map = ['sale' => 'ขาย', 'purchase' => 'สั่งซื้อ', 'claim' => 'เคลม'];
    return $map[(string) $type] ?? (string) $type;
}

/**
 * เครื่องที่ทะเบียนเรายังเป็น "ใหม่" แต่ระบบ Setup บันทึกว่าเบิกออกไปแล้ว
 *
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>}
 */
function stock_check_evidence_rows()
{
    $issued = stock_check_issued_map();
    if (!$issued['ok']) {
        return ['ok' => false, 'error' => $issued['error'], 'rows' => []];
    }
    if (!$issued['map']) {
        return ['ok' => true, 'error' => '', 'rows' => []];
    }
    $rows = [];
    $res = qr(
        "SELECT a.id, a.asset_code, a.factory_serial, a.produced_at, p.name AS pname, p.id AS product_id
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE a.status = 'new'
         ORDER BY a.produced_at DESC, a.id DESC"
    );
    while ($r = $res->fetch_assoc()) {
        $code = strtoupper(trim((string) $r['asset_code']));
        $fs = strtoupper(trim((string) $r['factory_serial']));
        $hit = $issued['map'][$code] ?? (($fs !== '' && isset($issued['map'][$fs])) ? $issued['map'][$fs] : null);
        if ($hit === null) {
            continue;
        }
        $r['issue_type']  = $hit['type'];
        $r['issue_label'] = stock_check_issue_label($hit['type']);
        $r['issue_date']  = $hit['date'];
        $r['issue_po']    = $hit['po'];
        $r['issue_to']    = $hit['company'];
        $rows[] = $r;
    }
    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

// ─ 2) เครื่องค้างเก่า ────────────────────────────────────────────────────────

/**
 * วันนับสต็อกล่าสุดของแต่ละรุ่นจากระบบ Setup (ใช้เป็นเส้นแบ่งว่าเครื่องไหน "เก่ากว่าการนับ")
 *
 * @return array<string,string> product_code (ตัวใหญ่) => วันที่นับ
 */
function stock_check_setup_count_dates()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $stock = dbStock();
    if (!$stock) {
        return $cache;
    }
    $res = $stock->query(
        "SELECT UPPER(TRIM(p.product_code)) AS code, f.last_check_at
         FROM products p JOIN finishgood_stock_balance f ON f.product_id = p.id
         WHERE p.is_active = 1 AND f.last_check_at IS NOT NULL AND f.last_check_at <> '0000-00-00'"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $code = (string) $r['code'];
        $date = substr((string) $r['last_check_at'], 0, 10);
        if ($code !== '' && (!isset($cache[$code]) || $date > $cache[$code])) {
            $cache[$code] = $date;
        }
    }
    return $cache;
}

/**
 * เครื่องที่ยังเป็น "ใหม่" แต่เก่ากว่าเส้นแบ่ง — ของจริงน่าจะออกจากคลังไปนานแล้ว
 *
 * เส้นแบ่งของแต่ละรุ่น เรียงตามความน่าเชื่อถือ:
 *   1. วันนับสต็อกของเราเอง (ถ้าเคยนับ)
 *   2. วันนับสต็อกของระบบ Setup
 *   3. วันที่ผู้ใช้กรอกเอง (ช่อง "เก่ากว่าวันที่")
 *
 * @param array{product_id?:int, before?:string, q?:string, limit?:int} $opts
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>, total:int}
 */
function stock_check_stale_rows(array $opts = [])
{
    $productId = (int) (isset($opts['product_id']) ? $opts['product_id'] : 0);
    $manualBefore = trim((string) (isset($opts['before']) ? $opts['before'] : ''));
    $q = trim((string) (isset($opts['q']) ? $opts['q'] : ''));
    $limit = (int) (isset($opts['limit']) ? $opts['limit'] : 500);
    if ($limit <= 0 || $limit > 2000) {
        $limit = 500;
    }

    $setupDates = stock_check_setup_count_dates();
    $ourCounts = stock_count_latest_all();
    $counted = stock_count_found_ids_all();

    $where = ["a.status = 'new'"];
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

    $res = q_try(
        "SELECT a.id, a.asset_code, a.factory_serial, a.produced_at, a.product_id,
                p.name AS pname, UPPER(TRIM(COALESCE(p.product_code, ''))) AS code
         FROM assets a JOIN products p ON p.id = a.product_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY a.produced_at ASC, a.id ASC",
        $types,
        $params
    );
    if (empty($res['ok'])) {
        return ['ok' => false, 'error' => (string) ($res['error'] ?? 'อ่านทะเบียนไม่สำเร็จ'), 'rows' => [], 'total' => 0];
    }

    $rows = [];
    $total = 0;
    $byProduct = [];
    $rs = $res['stmt']->get_result();
    while ($r = $rs->fetch_assoc()) {
        $pid = (int) $r['product_id'];
        // นับเจอกับตาตอนนับครั้งล่าสุด = อยู่ในคลังจริง ไม่ใช่เครื่องค้าง ต่อให้ผลิตมานานแล้ว
        if (isset($counted[$pid][(int) $r['id']])) {
            continue;
        }
        $cutoff = '';
        $source = '';
        if (isset($ourCounts[$pid])) {
            $cutoff = substr((string) $ourCounts[$pid]['counted_at'], 0, 10);
            $source = 'นับสต็อกของเรา';
        } elseif (isset($setupDates[(string) $r['code']])) {
            $cutoff = $setupDates[(string) $r['code']];
            $source = 'นับสต็อกระบบ Setup';
        }
        if ($manualBefore !== '') {
            $cutoff = $manualBefore;
            $source = 'วันที่เลือกเอง';
        }
        if ($cutoff === '') {
            continue;   // ไม่มีเส้นแบ่ง = ไม่มีเหตุให้สงสัยเครื่องนี้
        }
        if (substr((string) $r['produced_at'], 0, 10) >= $cutoff) {
            continue;
        }
        $total++;
        if (!isset($byProduct[$pid])) {
            $byProduct[$pid] = ['product_id' => $pid, 'name' => (string) $r['pname'], 'n' => 0];
        }
        $byProduct[$pid]['n']++;
        if (count($rows) >= $limit) {
            continue;
        }
        $r['cutoff'] = $cutoff;
        $r['cutoff_source'] = $source;
        $rows[] = $r;
    }
    usort($byProduct, static function ($a, $b) {
        return $b['n'] - $a['n'];
    });
    return ['ok' => true, 'error' => '', 'rows' => $rows, 'total' => $total, 'by_product' => $byProduct];
}

/**
 * รุ่นที่มีเครื่องค้างเก่า พร้อมจำนวน — ใช้ทำตัวกรองรายรุ่น
 *
 * @return array<int,array{product_id:int, name:string, n:int}>
 */
function stock_check_stale_by_product()
{
    $all = stock_check_stale_rows(['limit' => 1]);
    return isset($all['by_product']) ? $all['by_product'] : [];
}

// ─ เปลี่ยนสถานะหลายเครื่อง ───────────────────────────────────────────────────

/**
 * เปลี่ยนสถานะเครื่องหลายตัวพร้อมกัน + เขียนประวัติทุกครั้ง
 *
 * @param array<int,int|string> $assetIds
 * @param string                $status   sold|retired|lost
 * @param string                $reason   เหตุผลที่จะเขียนลงประวัติ
 * @return array{ok:bool, changed:int, skipped:int, message:string}
 */
function stock_check_apply_status(array $assetIds, $status, $reason)
{
    $status = (string) $status;
    if (!in_array($status, STOCK_CHECK_CLOSE_STATUSES, true)) {
        return ['ok' => false, 'changed' => 0, 'skipped' => 0, 'message' => 'สถานะปลายทางไม่ถูกต้อง'];
    }
    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'เคลียร์เครื่องค้างสถานะ';
    }
    $actor = function_exists('actor_name') ? (actor_name() ?: 'system') : 'system';
    $changed = 0;
    $skipped = 0;
    $seen = [];
    foreach ($assetIds as $id) {
        $id = (int) $id;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $cur = qr('SELECT status FROM assets WHERE id = ? LIMIT 1', 'i', [$id])->fetch_assoc();
        if (!$cur || (string) $cur['status'] === $status) {
            $skipped++;
            continue;
        }
        q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$status, $id]);
        q(
            'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
            'isss',
            [$id, 'out', $reason . ' (' . (string) $cur['status'] . ' → ' . $status . ')', $actor]
        );
        $changed++;
    }
    $msg = 'เปลี่ยนสถานะแล้ว ' . number_format($changed) . ' เครื่อง';
    if ($skipped > 0) {
        $msg .= ' · ข้าม ' . number_format($skipped) . ' เครื่อง (สถานะเป็นแบบนั้นอยู่แล้ว)';
    }
    return ['ok' => $changed > 0, 'changed' => $changed, 'skipped' => $skipped, 'message' => $msg];
}

// ─ ย้อนกลับการเคลียร์ ────────────────────────────────────────────────────────

/** @var string คำขึ้นต้นเหตุผลของการย้อนกลับ ใช้กันไม่ให้ย้อนซ้ำซ้อน */
const STOCK_CHECK_UNDO_PREFIX = 'ย้อนกลับการเคลียร์';

/**
 * รายการการเคลียร์ที่ผ่านมา จัดกลุ่มตาม วัน + เหตุผล + รุ่น
 *
 * อ่านจาก stock_movements ที่หน้านี้เขียนไว้ (รูปแบบ "เหตุผล (จาก → เป็น)") จึงย้อนกลับ
 * สิ่งที่เคลียร์ไปแล้วได้ แม้จะทำไปตั้งแต่ก่อนมีปุ่มนี้
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function stock_check_undo_batches($limit = 40)
{
    $limit = (int) $limit;
    if ($limit <= 0 || $limit > 200) {
        $limit = 40;
    }
    $rows = [];
    $res = q_try(
        "SELECT DATE(m.moved_at) AS day, m.reason, m.made_by, a.product_id, p.name AS pname,
                COUNT(*) AS n, MIN(m.id) AS min_id, MAX(m.id) AS max_id
         FROM stock_movements m
         JOIN assets a ON a.id = m.asset_id
         JOIN products p ON p.id = a.product_id
         WHERE m.reason LIKE '%(% → %)' AND m.reason NOT LIKE ?
         GROUP BY day, m.reason, m.made_by, a.product_id
         ORDER BY max_id DESC
         LIMIT " . $limit,
        's',
        [STOCK_CHECK_UNDO_PREFIX . '%']
    );
    if (empty($res['ok'])) {
        return $rows;
    }
    $rs = $res['stmt']->get_result();
    while ($r = $rs->fetch_assoc()) {
        $parsed = stock_check_parse_reason((string) $r['reason']);
        if ($parsed === null || !in_array($parsed['to'], STOCK_CHECK_CLOSE_STATUSES, true)) {
            continue;
        }
        $r['from'] = $parsed['from'];
        $r['to'] = $parsed['to'];
        $r['label'] = $parsed['text'];
        // นับเฉพาะเครื่องที่ยังอยู่ในสถานะปลายทาง — ที่ถูกแก้ต่อไปแล้วย้อนไม่ได้
        $cnt = qr(
            'SELECT COUNT(*) FROM stock_movements m JOIN assets a ON a.id = m.asset_id
             WHERE m.id BETWEEN ? AND ? AND m.reason = ? AND a.product_id = ? AND a.status = ?',
            'iisis',
            [(int) $r['min_id'], (int) $r['max_id'], (string) $r['reason'], (int) $r['product_id'], $parsed['to']]
        )->fetch_row();
        $r['undoable'] = (int) $cnt[0];
        $rows[] = $r;
    }
    return $rows;
}

/**
 * แยกเหตุผลออกเป็นข้อความ + สถานะต้นทาง/ปลายทาง
 *
 * @param string $reason
 * @return array{text:string, from:string, to:string}|null
 */
function stock_check_parse_reason($reason)
{
    if (!preg_match('/^(.*)\s*\(([a-z_]+)\s*→\s*([a-z_]+)\)\s*$/u', (string) $reason, $m)) {
        return null;
    }
    return ['text' => trim($m[1]), 'from' => $m[2], 'to' => $m[3]];
}

/**
 * ย้อนสถานะกลับเป็นแบบก่อนเคลียร์ สำหรับหนึ่งกลุ่ม
 *
 * @param int    $minId  ช่วง id ของ stock_movements ที่จะย้อน
 * @param int    $maxId
 * @param string $reason เหตุผลเดิม (ต้องตรงทั้งบรรทัด)
 * @param int    $productId
 * @return array{ok:bool, restored:int, skipped:int, message:string}
 */
function stock_check_undo_apply($minId, $maxId, $reason, $productId)
{
    $parsed = stock_check_parse_reason($reason);
    if ($parsed === null || !in_array($parsed['to'], STOCK_CHECK_CLOSE_STATUSES, true)) {
        return ['ok' => false, 'restored' => 0, 'skipped' => 0, 'message' => 'ย้อนกลับรายการนี้ไม่ได้'];
    }
    $actor = function_exists('actor_name') ? (actor_name() ?: 'system') : 'system';
    $restored = 0;
    $skipped = 0;
    $res = qr(
        'SELECT DISTINCT m.asset_id FROM stock_movements m JOIN assets a ON a.id = m.asset_id
         WHERE m.id BETWEEN ? AND ? AND m.reason = ? AND a.product_id = ?',
        'iisi',
        [(int) $minId, (int) $maxId, (string) $reason, (int) $productId]
    );
    $ids = [];
    while ($r = $res->fetch_row()) {
        $ids[] = (int) $r[0];
    }
    foreach ($ids as $id) {
        $cur = qr('SELECT status FROM assets WHERE id = ? LIMIT 1', 'i', [$id])->fetch_assoc();
        if (!$cur || (string) $cur['status'] !== $parsed['to']) {
            $skipped++;   // มีคนแก้สถานะต่อไปแล้ว ไม่ทับของเขา
            continue;
        }
        q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$parsed['from'], $id]);
        q(
            'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
            'isss',
            [
                $id,
                $parsed['from'] === 'new' ? 'in' : 'out',
                STOCK_CHECK_UNDO_PREFIX . ': ' . $parsed['text'] . ' (' . $parsed['to'] . ' → ' . $parsed['from'] . ')',
                $actor,
            ]
        );
        $restored++;
    }
    $msg = 'ย้อนสถานะกลับแล้ว ' . number_format($restored) . ' เครื่อง';
    if ($skipped > 0) {
        $msg .= ' · ข้าม ' . number_format($skipped) . ' เครื่อง (มีการแก้สถานะต่อไปแล้ว)';
    }
    return ['ok' => $restored > 0, 'restored' => $restored, 'skipped' => $skipped, 'message' => $msg];
}

// ─ 3) นับสต็อกของเราเอง ──────────────────────────────────────────────────────

/**
 * การนับครั้งล่าสุดของทุกรุ่น
 *
 * @return array<int,array<string,mixed>> product_id => แถว stock_counts
 */
function stock_count_latest_all()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    ensure_stock_check_schema();
    $cache = [];
    $res = q_try(
        'SELECT c.* FROM stock_counts c
         JOIN (SELECT product_id, MAX(id) AS mx FROM stock_counts GROUP BY product_id) t
           ON t.mx = c.id'
    );
    if (empty($res['ok'])) {
        return $cache;
    }
    $rs = $res['stmt']->get_result();
    while ($r = $rs->fetch_assoc()) {
        $cache[(int) $r['product_id']] = $r;
    }
    return $cache;
}

/**
 * การนับครั้งล่าสุดของรุ่นเดียว
 *
 * @param int $productId
 * @return array<string,mixed>|null
 */
function stock_count_latest($productId)
{
    $all = stock_count_latest_all();
    return $all[(int) $productId] ?? null;
}

/**
 * เครื่องที่ต้องเอาไปนับ — ทุกเครื่องที่ทะเบียนบอกว่าอยู่ในคลัง
 *
 * @param int $productId
 * @return array<int,array<string,mixed>>
 */
function stock_count_assets($productId)
{
    $productId = (int) $productId;
    if ($productId <= 0) {
        return [];
    }
    $rows = [];
    $res = qr(
        "SELECT id, asset_code, factory_serial, produced_at
         FROM assets WHERE product_id = ? AND status = 'new'
         ORDER BY produced_at DESC, asset_code DESC",
        'i',
        [$productId]
    );
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    return $rows;
}

/**
 * บันทึกผลการนับ — เก็บว่าเจอเครื่องไหนบ้าง ไม่เปลี่ยนสถานะเครื่องเอง
 *
 * เครื่องที่ไม่เจอจะไปโผล่ในรายการ "ค้างเก่า" ให้คนตัดสินใจอีกที ระบบไม่เดาแทน
 * ว่าหายไปไหน — ขายออกไป เสื่อมสภาพ หรือแค่ยังหาไม่เจอในวันที่นับ
 *
 * @param int                  $productId
 * @param array<int,int>       $foundIds  asset id ที่เจอของจริง
 * @param string               $note
 * @return array{ok:bool, message:string, count_id:int, found:int, missing:int}
 */
function stock_count_save($productId, array $foundIds, $note = '')
{
    ensure_stock_check_schema();
    $productId = (int) $productId;
    if ($productId <= 0) {
        return ['ok' => false, 'message' => 'ไม่ได้เลือกรุ่นสินค้า', 'count_id' => 0, 'found' => 0, 'missing' => 0];
    }
    $all = stock_count_assets($productId);
    if (!$all) {
        return ['ok' => false, 'message' => 'รุ่นนี้ไม่มีเครื่องสถานะ "ใหม่" ให้นับ', 'count_id' => 0, 'found' => 0, 'missing' => 0];
    }
    $found = [];
    foreach ($foundIds as $id) {
        $found[(int) $id] = true;
    }
    $foundCount = 0;
    $missingCount = 0;
    foreach ($all as $a) {
        if (isset($found[(int) $a['id']])) {
            $foundCount++;
        } else {
            $missingCount++;
        }
    }

    $ins = q_try(
        'INSERT INTO stock_counts (product_id, counted_at, counted_by, found_qty, missing_qty, note)
         VALUES (?, NOW(), ?, ?, ?, ?)',
        'isiis',
        [
            $productId,
            function_exists('actor_name') ? (actor_name() ?: 'system') : 'system',
            $foundCount,
            $missingCount,
            $note !== '' ? mb_substr(trim((string) $note), 0, 255) : null,
        ]
    );
    if (empty($ins['ok'])) {
        return ['ok' => false, 'message' => (string) ($ins['error'] ?? 'บันทึกการนับไม่สำเร็จ'), 'count_id' => 0, 'found' => 0, 'missing' => 0];
    }
    $countId = (int) $ins['insert_id'];
    foreach ($all as $a) {
        q(
            'INSERT INTO stock_count_items (count_id, asset_id, found) VALUES (?, ?, ?)',
            'iii',
            [$countId, (int) $a['id'], isset($found[(int) $a['id']]) ? 1 : 0]
        );
    }
    return [
        'ok'       => true,
        'count_id' => $countId,
        'found'    => $foundCount,
        'missing'  => $missingCount,
        'message'  => 'บันทึกการนับแล้ว — เจอ ' . number_format($foundCount) . ' เครื่อง · ไม่เจอ ' . number_format($missingCount) . ' เครื่อง',
    ];
}

/**
 * asset id ที่ "เจอของจริง" ในการนับครั้งล่าสุดของแต่ละรุ่น
 *
 * ใช้กันไม่ให้เครื่องที่เพิ่งยืนยันด้วยตาว่าอยู่ในคลัง ไปโผล่ในรายการเครื่องค้าง
 * เพียงเพราะวันที่ผลิตเก่ากว่าวันนับ
 *
 * @return array<int,array<int,bool>> product_id => [asset_id => true]
 */
function stock_count_found_ids_all()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $latest = stock_count_latest_all();
    if (!$latest) {
        return $cache;
    }
    $ids = [];
    foreach ($latest as $row) {
        $ids[] = (int) $row['id'];
    }
    $res = q_try(
        'SELECT c.product_id, i.asset_id FROM stock_count_items i
         JOIN stock_counts c ON c.id = i.count_id
         WHERE i.found = 1 AND i.count_id IN (' . implode(',', $ids) . ')'
    );
    if (empty($res['ok'])) {
        return $cache;
    }
    $rs = $res['stmt']->get_result();
    while ($r = $rs->fetch_row()) {
        $cache[(int) $r[0]][(int) $r[1]] = true;
    }
    return $cache;
}

/**
 * asset id ที่ "ไม่เจอ" ในการนับครั้งล่าสุดของรุ่นนั้น และยังเป็นเครื่องใหม่อยู่
 *
 * @param int $productId
 * @return array<int,int>
 */
function stock_count_missing_ids($productId)
{
    $latest = stock_count_latest($productId);
    if (!$latest) {
        return [];
    }
    $ids = [];
    $res = qr(
        "SELECT i.asset_id FROM stock_count_items i JOIN assets a ON a.id = i.asset_id
         WHERE i.count_id = ? AND i.found = 0 AND a.status = 'new'",
        'i',
        [(int) $latest['id']]
    );
    while ($r = $res->fetch_row()) {
        $ids[] = (int) $r[0];
    }
    return $ids;
}
