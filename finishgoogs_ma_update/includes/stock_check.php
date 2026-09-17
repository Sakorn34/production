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

// โหลดตัวนับยอดแบบเดียวกับ setupsystem (fg_shortage_setup_stock_all) + ตัวเช็คเครื่องเช่า
require_once dirname(dirname(__DIR__)) . "/shared/finishgood_shortage_dashboard.php";

/** @var array<int,string> สถานะที่ปิดเครื่องออกจากคลังได้จากหน้านี้ */
const STOCK_CHECK_CLOSE_STATUSES = ['sold', 'retired', 'lost'];

/** @var array<int,string> สถานะปลายทางที่ย้อนกลับได้ — รวมของที่ปุ่มซิงก์ตั้งให้ด้วย ไม่ใช่แค่การปิดเครื่อง */
const STOCK_CHECK_UNDOABLE_STATUSES = ["sold", "retired", "lost", "rental", "new", "unknown"];

/** @var int เครื่องที่ผลิตภายในกี่เดือนล่าสุด จะไม่ถูกปิดอัตโนมัติด้วยเกณฑ์อายุ (ต้องมีหลักฐานเท่านั้น) */
const STOCK_SYNC_MIN_AGE_MONTHS = 6;

/** @var string เหตุผลของการปิดจากหลักฐานระบบ Setup — มีเลข PO รองรับ จึงไม่ต้องสงสัยว่าปิดเกิน */
const STOCK_CHECK_EVIDENCE_REASON = 'เบิกออกจากคลังแล้วตามระบบ Setup';

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
    $out = [];
    foreach (stock_check_setup_stock() as $code => $row) {
        if ($row['date'] !== '') {
            $out[$code] = $row['date'];
        }
    }
    return $out;
}

/**
 * ยอดนับสต็อกล่าสุดของระบบ Setup รายรุ่น (จำนวน + วันที่นับ)
 *
 * @return array<string,array{qty:int, date:string}>
 */
function stock_check_setup_stock()
{
    // เลิกอ้างอิงยอดนับสต็อกของระบบ Setup แล้ว (17 ก.ย. 2026) — ใช้รอบนับสต็อกด้วยการสแกนของเราแทน
    // คืนค่าว่าง: เส้นแบ่ง "เก่ากว่าวันนับ" ใช้วันนับของเราอย่างเดียว และไม่มีตัวเช็คปิดเกินเทียบกับ Setup
    return [];
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

// ─ ซิงก์สถานะทั้งหมดในรอบเดียว ───────────────────────────────────────────────

/**
 * ตัดสินสถานะที่ควรเป็นของเครื่องหนึ่งเครื่อง จากแหล่งข้อมูลทั้งหมดที่มี
 *
 * ลำดับความน่าเชื่อถือ — ใช้ข้อที่เจอก่อนเสมอ:
 *   1-4 ระบบเช่า  ถ้าเครื่องอยู่ในทะเบียนเช่า ระบบเช่าคือคนที่รู้ดีที่สุดว่าเครื่องอยู่ไหน
 *   5-6 หลักฐานการเบิกออก/ขาย (เลข PO หรือใบเบิก) = ออกจากคลังไปแล้วแน่นอน
 *   7   นับเจอกับตาในรอบนับล่าสุดของเรา = อยู่ในคลังจริง
 *   8   ไม่มีหลักฐานอะไรเลย แต่เก่ากว่าวันนับสต็อก = ขายออกไปนานแล้ว
 *   9   ที่เหลือ = ผลิตหลังวันนับ ยังอยู่ในคลัง
 *
 * ข้อ 7-9 แตะเฉพาะเครื่องที่ยังเป็น "ใหม่" — เครื่องที่ปิดไปแล้วจะไม่ถูกเปิดกลับ
 * ถ้าไม่มีหลักฐานใหม่มายืนยัน และเครื่องสำรองไม่แตะเลย (ตั้งด้วยมือ)
 *
 * @param array<string,mixed>      $asset
 * @param array<string,mixed>|null $sale
 * @param array<string,mixed>|null $lease
 * @param array<string,mixed>      $ctx  issued / counted / cutoff
 * @return array{target:?string, reason:string}
 */
function stock_sync_target(array $asset, $sale, $lease, array $ctx)
{
    $current = trim((string) ($asset['status'] ?? 'new')) ?: 'new';
    if ($current === 'spare') {
        return ['target' => null, 'reason' => ''];
    }

    // ลงทะเบียนในระบบเช่า = เครื่องเช่าเสมอ (ยกเว้นปลดระวาง/สูญหาย) ต่อให้สแกนเจอในคลัง
    if ($lease && !empty($lease['found'])) {
        $pro = trim((string) ($lease['pro_status'] ?? ''));
        $p = trim((string) ($lease['p_status'] ?? ''));
        if ($p === 'active' || $pro === 'rent') {
            return ['target' => 'rental', 'reason' => 'ระบบเช่า: อยู่กับลูกค้า'];
        }
        if ($pro === 'Asset Retirement') {
            return ['target' => 'retired', 'reason' => 'ระบบเช่า: ปลดระวาง'];
        }
        if ($pro === 'Lost' || $p === 'Lost') {
            return ['target' => 'lost', 'reason' => 'ระบบเช่า: สูญหาย'];
        }
        return ['target' => 'rental', 'reason' => 'ระบบเช่า: ลงทะเบียนเป็นเครื่องเช่า'];
    }

    // สแกนเจอวางอยู่ในคลังเราจริง (และไม่ใช่เครื่องเช่า) = เครื่องใหม่ ชนะหลักฐานการขาย
    if (isset($ctx['scanned'][(int) $asset['id']])) {
        return ['target' => 'new', 'reason' => 'สแกนเจอในคลัง (รอบนับภายใน 30 วัน)'];
    }

    $sn = strtoupper(trim((string) ($asset['asset_code'] ?? '')));
    $fs = strtoupper(trim((string) ($asset['factory_serial'] ?? '')));
    if (isset($ctx['issued'][$sn]) || ($fs !== '' && isset($ctx['issued'][$fs]))) {
        $hit = $ctx['issued'][$sn] ?? $ctx['issued'][$fs];
        return ['target' => 'sold', 'reason' => 'ระบบ Setup: เบิกออกแล้ว (' . stock_check_issue_label($hit['type']) . ')'];
    }
    if ($sale && !empty($sale['sold'])) {
        return ['target' => 'sold', 'reason' => 'มีใบเบิกขายในระบบสต็อก'];
    }

    if (isset($ctx['counted'][(int) $asset['id']])) {
        return ['target' => 'new', 'reason' => 'นับเจอในคลังรอบล่าสุด'];
    }

    // "เก่า" ต้องเข้าสองเงื่อนไขพร้อมกัน: เก่ากว่าวันนับสต็อกของรุ่นนั้น และผลิตมาแล้วเกิน
    // STOCK_SYNC_MIN_AGE_MONTHS เดือน — กันกรณีเพิ่งนับสต็อกไปเมื่อเดือนที่แล้ว แล้วของที่ผลิต
    // ก่อนหน้านั้นไม่กี่สัปดาห์โดนปิดยกแผงทั้งที่ยังวางอยู่ในคลัง
    $produced = substr((string) $asset['produced_at'], 0, 10);
    $cutoff = $ctx['cutoff'][(int) $asset['product_id']] ?? '';
    $old = ($cutoff !== '' && $produced !== '' && $produced < $cutoff && $produced < $ctx['age_line']);

    if ($current === 'new') {
        return $old
            ? ['target' => 'sold', 'reason' => 'เครื่องเก่ากว่าวันนับสต็อก ไม่มีหลักฐานว่ายังอยู่']
            : ['target' => 'new', 'reason' => ''];
    }

    // เครื่องที่ปิดเป็น "ขายแล้ว" ไว้ แต่ทะเบียนสต็อกยืนยันว่ายังไม่มีใบเบิกและยังไม่ส่งมอบ
    // = ของยังอยู่ในคลังจริง เปิดกลับให้ (เฉพาะเครื่องที่ผลิตหลังวันนับสต็อก ไม่ไปแตะของเก่า)
    // ไม่แตะเสื่อมสภาพ/สูญหาย เพราะสองอันนั้นคนตั้งใจบันทึกเอง ไม่ได้ดูจากใบเบิก
    if ($current === 'sold' && !$old && $sale && array_key_exists('sold', $sale) && $sale['sold'] === false) {
        return ['target' => 'new', 'reason' => 'ระบบสต็อก: ยังอยู่ในคลัง ไม่มีใบเบิกและไม่ได้ส่งมอบ'];
    }

    return ['target' => null, 'reason' => ''];
}

/**
 * ไล่ตัดสินสถานะของเครื่องทั้งทะเบียน — โหมดดูก่อน (preview) หรือบันทึกจริง
 *
 * @param bool $apply false = ดูอย่างเดียว ไม่เขียนอะไร
 * @return array{ok:bool, error:string, total:int, changed:int, groups:array<string,array<string,mixed>>}
 */
function stock_sync_plan($apply = false)
{
    @set_time_limit(600);
    ensure_stock_check_schema();

    // เส้นแบ่ง "เก่ากว่าวันนับ" รายรุ่น — ใช้วันนับของเราก่อน ถ้าไม่มีค่อยใช้ของระบบ Setup
    $setupDates = stock_check_setup_count_dates();
    $ourCounts = stock_count_latest_all();
    $cutoff = [];
    $res = qr("SELECT id, UPPER(TRIM(COALESCE(product_code, ''))) AS code FROM products");
    while ($r = $res->fetch_assoc()) {
        $pid = (int) $r['id'];
        if (isset($ourCounts[$pid])) {
            $cutoff[$pid] = substr((string) $ourCounts[$pid]['counted_at'], 0, 10);
        } elseif (isset($setupDates[(string) $r['code']])) {
            $cutoff[$pid] = $setupDates[(string) $r['code']];
        }
    }

    $counted = [];
    foreach (stock_count_found_ids_all() as $ids) {
        foreach ($ids as $assetId => $_) {
            $counted[$assetId] = true;
        }
    }
    $issued = stock_check_issued_map();
    // เครื่องที่สแกนเจอในรอบนับไหนก็ได้ภายใน 30 วัน (ไม่นับรอบที่ยกเลิก) — ดู asset_status_scanned_ids()
    if (!function_exists('asset_status_scanned_ids')) {
        require_once __DIR__ . '/asset_status_sync.php';
    }
    $scanned = asset_status_scanned_ids();

    $ctx = [
        'issued'   => $issued['map'],
        'scanned'  => $scanned,
        'counted'  => $counted,
        'cutoff'   => $cutoff,
        'age_line' => date('Y-m-d', strtotime('-' . STOCK_SYNC_MIN_AGE_MONTHS . ' months')),
    ];

    $groups = [];
    $total = 0;
    $changed = 0;
    $lastId = 0;
    while (true) {
        $rows = [];
        $res = qr(
            'SELECT id, asset_code, factory_serial, status, product_id, produced_at
             FROM assets WHERE id > ? ORDER BY id LIMIT 800',
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
            $c = trim((string) $r['asset_code']);
            if ($c !== '') {
                $codes[] = $c;
            }
        }
        $saleMap = ($codes && function_exists('asset_stockparts_sale_status_by_sn'))
            ? asset_stockparts_sale_status_by_sn($codes)
            : [];
        $leaseMap = function_exists('asset_leasing_status_by_assets') ? asset_leasing_status_by_assets($rows) : [];

        foreach ($rows as $r) {
            $total++;
            $key = strtoupper(trim((string) $r['asset_code']));
            $decision = stock_sync_target(
                $r,
                $saleMap[$key] ?? null,
                $leaseMap[$key] ?? null,
                $ctx
            );
            $to = $decision['target'];
            $from = trim((string) $r['status']) ?: 'new';
            if ($to === null || $to === $from) {
                continue;
            }
            $changed++;
            $gk = $from . '→' . $to . '|' . $decision['reason'];
            if (!isset($groups[$gk])) {
                $groups[$gk] = ['from' => $from, 'to' => $to, 'reason' => $decision['reason'], 'n' => 0, 'sample' => []];
            }
            $groups[$gk]['n']++;
            if (count($groups[$gk]['sample']) < 5) {
                $groups[$gk]['sample'][] = (string) $r['asset_code'];
            }
            if ($apply) {
                q('UPDATE assets SET status = ? WHERE id = ?', 'si', [$to, (int) $r['id']]);
                q(
                    'INSERT INTO stock_movements (asset_id, moved_at, direction, reason, made_by) VALUES (?, NOW(), ?, ?, ?)',
                    'isss',
                    [
                        (int) $r['id'],
                        $to === 'new' ? 'in' : 'out',
                        'ซิงก์สถานะทั้งระบบ: ' . ($decision['reason'] !== '' ? $decision['reason'] . ' ' : '')
                            . '(' . $from . ' → ' . $to . ')',
                        function_exists('actor_name') ? (actor_name() ?: 'system') : 'system',
                    ]
                );
            }
        }
    }
    uasort($groups, static function ($a, $b) {
        return $b['n'] - $a['n'];
    });
    return [
        'ok'      => true,
        'error'   => $issued['ok'] ? '' : (string) $issued['error'],
        'total'   => $total,
        'changed' => $changed,
        'groups'  => $groups,
    ];
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
        if ($parsed === null || !in_array($parsed['to'], STOCK_CHECK_UNDOABLE_STATUSES, true)) {
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

        // เช็คว่าปิดเกินไปไหม — ถ้าระบบ Setup นับเจอมากกว่าที่เราเหลือ แปลว่าของยังอยู่ในคลังจริง
        // แต่ทะเบียนเราปิดไปแล้ว ส่วนต่างคือจำนวนที่ควรย้อนกลับ
        $setup = stock_check_setup_stock();
        $code = strtoupper(trim((string) qr(
            'SELECT COALESCE(product_code, "") FROM products WHERE id = ?',
            'i',
            [(int) $r['product_id']]
        )->fetch_row()[0]));
        $nowNew = (int) qr(
            "SELECT COUNT(*) FROM assets WHERE product_id = ? AND status = 'new'",
            'i',
            [(int) $r['product_id']]
        )->fetch_row()[0];
        $r['now_new'] = $nowNew;
        $r['setup_qty'] = isset($setup[$code]) ? (int) $setup[$code]['qty'] : -1;
        $r['setup_date'] = isset($setup[$code]) ? (string) $setup[$code]['date'] : '';
        // ปิดจากหลักฐาน (มีเลข PO) ไม่นับว่าน่าสงสัย ต่อให้ยอดสองฝั่งไม่ตรงกัน
        $r['suspect'] = ($r['setup_qty'] > $nowNew && $r['to'] !== 'new' && $r['label'] !== STOCK_CHECK_EVIDENCE_REASON)
            ? min((int) $r['undoable'], $r['setup_qty'] - $nowNew)
            : 0;
        // ทางกลับกัน: ทะเบียนเราเหลือมากกว่าที่ Setup นับเจอ = ยังมีเครื่องค้างที่ต้องเคลียร์ (หรือย้อนกลับมาเกิน)
        $r['excess'] = ($r['setup_qty'] >= 0 && $nowNew > $r['setup_qty']) ? $nowNew - $r['setup_qty'] : 0;
        $r['suspect_dup'] = false;
        $rows[] = $r;
    }

    // ส่วนต่างเป็นของ "รุ่น" ไม่ใช่ของแต่ละกลุ่ม — ถ้ารุ่นเดียวถูกปิดหลายรอบ ให้แสดงที่กลุ่มใหญ่สุด
    // กลุ่มเดียว ไม่งั้นบวกซ้ำแล้วย้อนเกินจำนวนที่ควรย้อน
    $owner = [];
    foreach ($rows as $i => $r) {
        if ((int) $r['suspect'] <= 0) {
            continue;
        }
        $pid = (int) $r['product_id'];
        if (!isset($owner[$pid]) || (int) $rows[$owner[$pid]]['undoable'] < (int) $r['undoable']) {
            $owner[$pid] = $i;
        }
    }
    foreach ($rows as $i => $r) {
        if ((int) $r['suspect'] > 0 && ($owner[(int) $r['product_id']] ?? -1) !== $i) {
            $rows[$i]['suspect'] = 0;
            $rows[$i]['suspect_dup'] = true;
        }
    }

    // กลุ่มที่น่าจะปิดเกินขึ้นก่อน จะได้ไม่ต้องไล่หาเอง
    usort($rows, static function ($a, $b) {
        return ((int) $b['suspect'] - (int) $a['suspect']) ?: ((int) $b['max_id'] - (int) $a['max_id']);
    });
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
function stock_check_undo_apply($minId, $maxId, $reason, $productId, $limit = 0)
{
    $parsed = stock_check_parse_reason($reason);
    if ($parsed === null || !in_array($parsed['to'], STOCK_CHECK_UNDOABLE_STATUSES, true)) {
        return ['ok' => false, 'restored' => 0, 'skipped' => 0, 'message' => 'ย้อนกลับรายการนี้ไม่ได้'];
    }
    $limit = max(0, (int) $limit);
    $actor = function_exists('actor_name') ? (actor_name() ?: 'system') : 'system';
    $restored = 0;
    $skipped = 0;
    // เรียงเครื่องใหม่สุดขึ้นก่อน — ย้อนแค่บางส่วนก็ได้ของที่น่าจะยังอยู่ในคลังจริงกลับมาก่อน
    $res = qr(
        'SELECT DISTINCT m.asset_id, a.produced_at FROM stock_movements m JOIN assets a ON a.id = m.asset_id
         WHERE m.id BETWEEN ? AND ? AND m.reason = ? AND a.product_id = ?
         ORDER BY a.produced_at DESC, m.asset_id DESC',
        'iisi',
        [(int) $minId, (int) $maxId, (string) $reason, (int) $productId]
    );
    $ids = [];
    while ($r = $res->fetch_row()) {
        $ids[] = (int) $r[0];
    }
    foreach ($ids as $id) {
        if ($limit > 0 && $restored >= $limit) {
            break;
        }
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
    if ($limit > 0) {
        $msg .= ' (เครื่องที่ผลิตล่าสุดก่อน)';
    }
    if ($skipped > 0) {
        $msg .= ' · ข้าม ' . number_format($skipped) . ' เครื่อง (มีการแก้สถานะต่อไปแล้ว)';
    }
    return ['ok' => $restored > 0, 'restored' => $restored, 'skipped' => $skipped, 'message' => $msg];
}

/**
 * ปิดส่วนที่เหลือเกินยอดนับของระบบ Setup — เอาเครื่องเก่าสุดออกก่อน
 *
 * ใช้คู่กับปุ่มย้อนกลับ: ย้อนทั้งกลุ่มมาแล้วเยอะเกิน ก็ตัดส่วนเกินออกให้ยอดตรงกันได้
 * โดยไม่ต้องไปไล่ติ๊กทีละเครื่องในแท็บค้างเก่า
 *
 * เครื่องเช่าที่รับคืนแล้วรอปล่อยใหม่ไม่ถูกแตะ — พวกนั้นอยู่ในคลังเช่าจริง ไม่ใช่ของค้าง
 *
 * @param int    $productId
 * @param int    $limit  จำนวนที่จะปิด
 * @param string $status ปลายทาง (sold/retired/lost)
 * @param string $reason
 * @return array{ok:bool, changed:int, skipped:int, message:string}
 */
function stock_check_close_excess($productId, $limit, $status = 'sold', $reason = '')
{
    $productId = (int) $productId;
    $limit = (int) $limit;
    if ($productId <= 0 || $limit <= 0) {
        return ['ok' => false, 'changed' => 0, 'skipped' => 0, 'message' => 'ไม่มีจำนวนที่จะปิด'];
    }
    $ready = function_exists('fg_shortage_leasing_ready_serials') ? fg_shortage_leasing_ready_serials() : [];
    $ids = [];
    $res = qr(
        "SELECT id, UPPER(TRIM(asset_code)) AS sn, UPPER(TRIM(COALESCE(factory_serial, ''))) AS fs
         FROM assets WHERE product_id = ? AND status = 'new'
         ORDER BY produced_at ASC, id ASC",
        'i',
        [$productId]
    );
    while (($r = $res->fetch_assoc()) && count($ids) < $limit) {
        if (isset($ready[(string) $r['sn']]) || ((string) $r['fs'] !== '' && isset($ready[(string) $r['fs']]))) {
            continue;   // เครื่องเช่าพร้อมปล่อยใหม่ ไม่ใช่ของค้าง
        }
        $ids[] = (int) $r['id'];
    }
    if (!$ids) {
        return ['ok' => false, 'changed' => 0, 'skipped' => 0, 'message' => 'ไม่มีเครื่องที่ปิดได้ (เหลือแต่เครื่องเช่าพร้อมปล่อยใหม่)'];
    }
    return stock_check_apply_status($ids, $status, $reason !== '' ? $reason : 'เคลียร์ส่วนเกินให้ตรงยอดนับของระบบ Setup');
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
