<?php
/**
 * includes/inv_pickup.php — ใบเบิกผลิตจากระบบ inventory → คิวผลิต → ตัดยอดเมื่อลงทะเบียนเครื่อง
 *
 * ระบบ inventory เป็นระบบอื่น — **อ่านอย่างเดียว** (dbInventory) ไม่เขียนอะไรกลับ
 * การจับคู่/ตัดยอด/ปิดใบ เก็บในตารางของ biton_production ทั้งหมด
 *
 * ข้อมูลฝั่ง inventory (ดูเพิ่มใน memory inventory-pickup-readonly):
 *   pre_stock  คำขอเบิก แถวละอะไหล่ · pre_id = เลขใบ · pre_product = ชื่อชุด/หมวด · pre_part = part_id (text)
 *   log_stock  ของที่คลังจ่ายจริง · pre_id · log_part = **ชื่อ**อะไหล่ · log_amount
 *   part       part_id · part_name
 *
 * ใบเบิกผลิตมี 2 แบบ ผูกกับรุ่นของเราต่างกัน:
 *   ชุด   (bitVisitor Plus, bitScan …) — ทั้งใบ = เครื่องรุ่นเดียว · จำนวนเครื่อง = จำนวนที่ซ้ำมากสุดของแถว
 *   แยกชิ้น (bitVisitor Accessories …) — แต่ละอะไหล่คือของสำเร็จรูปคนละรุ่น (Mobile Printer → Portable Printer)
 *         ผูกรายอะไหล่ · จำนวน = จำนวนที่เบิกของอะไหล่นั้น
 * หน่วยที่ติดตาม/ตัดยอด = (ใบ, รุ่นของเรา) — ใบแยกชิ้นใบเดียวอาจมีหลายรุ่น
 */

/** @var string ประเภทใบเบิกผลิตในระบบ inventory (สะกดตรงตามที่ระบบนั้นบันทึก) */
const INV_PICKUP_TYPE = 'เบิกผลิต';
/** @var string site_settings: วันที่เริ่มติดตาม (Y-m-d) — ใบก่อนวันนี้ไม่แสดง */
const INV_PICKUP_SINCE_KEY = 'inv_pickup_since';

/**
 * ตารางของเราเอง (biton_production)
 *
 * @return void
 */
function ensure_inv_pickup_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // ชื่อชุด/หมวดใน inventory → วิธีนับ: set = ทั้งใบเป็นรุ่นเดียว · parts = ผูกรายอะไหล่ · ignore = ไม่ติดตาม
    db()->query("CREATE TABLE IF NOT EXISTS inv_set_map (
        inv_name VARCHAR(191) NOT NULL PRIMARY KEY,
        mode ENUM('set','parts','ignore') NOT NULL DEFAULT 'set',
        product_id INT NULL,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // อะไหล่ในใบแบบแยกชิ้น → รุ่นของเรา (product_id NULL = ไม่ใช่เครื่อง ไม่ติดตาม เช่น สายคล้อง)
    db()->query("CREATE TABLE IF NOT EXISTS inv_part_map (
        inv_part_id INT NOT NULL PRIMARY KEY,
        product_id INT NULL,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // เครื่องไหนตัดจากใบไหน — 1 เครื่องตัดได้ใบเดียว
    db()->query("CREATE TABLE IF NOT EXISTS inv_pickup_alloc (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pre_id VARCHAR(30) NOT NULL,
        product_id INT NOT NULL,
        asset_id INT NOT NULL,
        source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
        allocated_by VARCHAR(100) NULL,
        allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_asset (asset_id),
        KEY idx_pre (pre_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // ปิดรายการเอง (ผลิตไม่ครบแต่จบแล้ว · ของเสีย · ยกเลิก)
    db()->query("CREATE TABLE IF NOT EXISTS inv_pickup_close (
        pre_id VARCHAR(30) NOT NULL,
        product_id INT NOT NULL,
        reason VARCHAR(255) NULL,
        closed_by VARCHAR(100) NULL,
        closed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (pre_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * วันที่เริ่มติดตาม — ยังไม่ตั้ง = ต้นเดือนก่อน (ไม่ให้ใบเก่าหลายปีโผล่มาเป็น "รอผลิต")
 *
 * @return string Y-m-d
 */
function inv_pickup_since(): string
{
    $v = trim((string) setting(INV_PICKUP_SINCE_KEY, ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return $v;
    }
    return date('Y-m-01', strtotime('first day of last month'));
}

/**
 * แปลงวันที่ log_stock (ymd-His) เป็น Y-m-d H:i:s
 *
 * @param string $v
 * @return string '' ถ้าอ่านไม่ออก
 */
function inv_pickup_log_dt(string $v): string
{
    if (preg_match('/^(\d{2})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', trim($v), $m)) {
        return "20{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) . ' 00:00:00' : '';
}

/**
 * แผนที่การผูกทั้งหมด
 *
 * @return array{sets:array<string,array{mode:string,product_id:?int}>, parts:array<int,?int>}
 */
function inv_pickup_maps(): array
{
    ensure_inv_pickup_schema();
    $sets = [];
    $r = db()->query('SELECT inv_name, mode, product_id FROM inv_set_map');
    while ($x = $r->fetch_assoc()) {
        $sets[(string) $x['inv_name']] = ['mode' => (string) $x['mode'], 'product_id' => $x['product_id'] !== null ? (int) $x['product_id'] : null];
    }
    $parts = [];
    $r = db()->query('SELECT inv_part_id, product_id FROM inv_part_map');
    while ($x = $r->fetch_assoc()) {
        $parts[(int) $x['inv_part_id']] = $x['product_id'] !== null ? (int) $x['product_id'] : null;
    }
    return ['sets' => $sets, 'parts' => $parts];
}

/**
 * ค่าที่ซ้ำมากสุด (จำนวนชุดของใบแบบชุด) — เท่ากันเอาค่ามากกว่า
 *
 * @param array<int,float> $vals
 * @return int
 */
function inv_pickup_mode(array $vals): int
{
    $cnt = [];
    foreach ($vals as $v) {
        $k = (string) (int) round($v);
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
    }
    if (!$cnt) {
        return 0;
    }
    uksort($cnt, function ($a, $b) use ($cnt) {
        return [$cnt[$b], (int) $b] <=> [$cnt[$a], (int) $a];
    });
    $keys = array_keys($cnt);   // array_key_first ต้อง PHP 7.3 — โค้ดชุดนี้ต้องรันได้บน 7.1
    return (int) $keys[0];
}

/**
 * ใบเบิกผลิตทั้งหมดตั้งแต่วันเริ่มติดตาม แตกเป็นรายการ (ใบ × รุ่นของเรา) พร้อมยอดผลิตแล้ว/คงเหลือ
 *
 * @param array<string,mixed> $opts since (Y-m-d) · with_lines (bool รายละเอียดอะไหล่)
 * @return array{ok:bool, error:string, docs:array<string,array<string,mixed>>, items:array<int,array<string,mixed>>, unmapped:array<string,int>}
 */
function inv_pickup_load(array $opts = []): array
{
    $out = ['ok' => false, 'error' => '', 'docs' => [], 'items' => [], 'unmapped' => []];
    $inv = function_exists('dbInventory') ? dbInventory() : null;
    if (!$inv) {
        $out['error'] = function_exists('dbInventoryError') ? dbInventoryError() : 'เชื่อมต่อระบบ inventory ไม่ได้';
        return $out;
    }
    ensure_inv_pickup_schema();
    $since = (string) ($opts['since'] ?? inv_pickup_since());
    $maps = inv_pickup_maps();

    // ── คำขอเบิก ──
    $st = $inv->prepare("SELECT pre_id, pre_date, pre_product, pre_part, pre_amount, pre_by, pre_status, pre_remarks
                         FROM pre_stock WHERE pre_type = ? AND pre_date >= ? AND pre_status <> 'cancel'
                         ORDER BY pre_id, pre_running");
    if (!$st) {
        $out['error'] = 'อ่านใบเบิกจาก inventory ไม่ได้';
        return $out;
    }
    $type = INV_PICKUP_TYPE;
    $st->bind_param('ss', $type, $since);
    $st->execute();
    $res = $st->get_result();
    $docs = [];
    $partIds = [];
    while ($r = $res->fetch_assoc()) {
        $id = (string) $r['pre_id'];
        if (!isset($docs[$id])) {
            $docs[$id] = [
                'pre_id' => $id, 'date' => (string) $r['pre_date'], 'set' => trim((string) $r['pre_product']),
                'by' => (string) $r['pre_by'], 'status' => (string) $r['pre_status'], 'remark' => trim((string) $r['pre_remarks']),
                'lines' => [], 'issued_at' => '', 'issued_by' => '', 'extra' => false,
            ];
        }
        $pid = (int) $r['pre_part'];
        $partIds[$pid] = true;
        $docs[$id]['lines'][$pid] = [
            'part_id' => $pid, 'name' => '', 'req' => (float) $r['pre_amount'] + (float) ($docs[$id]['lines'][$pid]['req'] ?? 0), 'got' => 0.0,
        ];
    }
    $st->close();
    if (!$docs) {
        $out['ok'] = true;
        return $out;
    }

    // ── ชื่ออะไหล่ (log_stock เก็บเป็นชื่อ ต้องใช้จับคู่) ──
    $names = [];
    $byName = [];
    $r = $inv->query('SELECT part_id, part_name FROM part WHERE part_id IN (' . implode(',', array_map('intval', array_keys($partIds))) . ')');
    while ($r && ($x = $r->fetch_row())) {
        $names[(int) $x[0]] = (string) $x[1];
        $byName[trim((string) $x[1])] = (int) $x[0];
    }

    // ── ของที่คลังจ่ายจริง ──
    $ids = array_keys($docs);
    foreach (array_chunk($ids, 400) as $chunk) {
        $in = implode(',', array_map(function ($v) use ($inv) { return "'" . $inv->real_escape_string($v) . "'"; }, $chunk));
        $r = $inv->query("SELECT pre_id, log_part, SUM(log_amount) amt, MAX(log_date) dt, GROUP_CONCAT(DISTINCT log_by) who
                          FROM log_stock WHERE pre_id IN ($in) GROUP BY pre_id, log_part");
        while ($r && ($x = $r->fetch_assoc())) {
            $id = (string) $x['pre_id'];
            $pid = $byName[trim((string) $x['log_part'])] ?? 0;
            if (!isset($docs[$id])) {
                continue;
            }
            if ($pid && isset($docs[$id]['lines'][$pid])) {
                $docs[$id]['lines'][$pid]['got'] += (float) $x['amt'];
            }
            $dt = inv_pickup_log_dt((string) $x['dt']);
            if ($dt > $docs[$id]['issued_at']) {
                $docs[$id]['issued_at'] = $dt;
            }
            // คนจ่ายซ้ำกันทุกแถวอะไหล่ — เก็บชื่อไม่ซ้ำ
            $who = array_filter(array_map('trim', explode(',', $docs[$id]['issued_by'] . ',' . (string) $x['who'])));
            $docs[$id]['issued_by'] = implode(', ', array_unique($who));
        }
    }

    // ── จำนวนอะไหล่ในสูตรชุด (ใช้แยกใบ "เบิกเสริม" ออกจากใบผลิตทั้งชุด) ──
    $formula = [];
    $r = $inv->query('SELECT pd_name, COUNT(*) FROM production_formula GROUP BY pd_name');
    while ($r && ($x = $r->fetch_row())) {
        $formula[trim((string) $x[0])] = (int) $x[1];
    }

    // ── แตกเป็นรายการ (ใบ × รุ่นของเรา) ──
    $items = [];
    foreach ($docs as $id => &$d) {
        foreach ($d['lines'] as $pid => &$ln) {
            $ln['name'] = $names[$pid] ?? ('#' . $pid);
        }
        unset($ln);
        $d['issued'] = $d['status'] === 'pickup';
        $map = $maps['sets'][$d['set']] ?? null;
        $mode = $map['mode'] ?? '';
        if ($mode === 'ignore') {
            continue;
        }
        if ($mode === 'parts') {
            foreach ($d['lines'] as $pid => $ln) {
                if (!array_key_exists($pid, $maps['parts'])) {
                    $out['unmapped']['part:' . $pid] = ($out['unmapped']['part:' . $pid] ?? 0) + 1;
                    continue;
                }
                $prod = $maps['parts'][$pid];
                if (!$prod) {
                    continue;   // ผูกไว้ว่าไม่ใช่เครื่อง (สายคล้อง ฯลฯ)
                }
                $qty = (int) round($d['issued'] ? $ln['got'] : $ln['req']);
                $key = $id . '|' . $prod;
                if (!isset($items[$key])) {
                    $items[$key] = ['pre_id' => $id, 'product_id' => $prod, 'qty' => 0, 'kind' => 'parts', 'missing' => [], 'parts' => []];
                }
                $items[$key]['qty'] += $qty;
                $items[$key]['parts'][] = $ln['name'];
                if ($d['issued'] && $ln['got'] + 0.001 < $ln['req']) {
                    $items[$key]['missing'][] = $ln['name'];
                }
            }
            continue;
        }
        if (!$map || !$map['product_id']) {
            $out['unmapped']['set:' . $d['set']] = ($out['unmapped']['set:' . $d['set']] ?? 0) + 1;
            continue;
        }
        // เบิกเสริม: ใบชุดที่มีอะไหล่ไม่ถึงครึ่งของสูตร (เช่น bitVisitor S เบิกแค่ Mobile Printer 24 ตัว)
        // ไม่ใช่การผลิตเครื่องใหม่ทั้งชุด — ไม่นับเป็นเครื่อง แสดงแยกไว้ในรายละเอียดเฉย ๆ
        $fsize = $formula[$d['set']] ?? 0;
        if ($fsize >= 3 && count($d['lines']) < (int) ceil($fsize / 2)) {
            $d['extra'] = true;
            continue;
        }
        // จำนวนชุด: ค่าที่ซ้ำมากสุดของที่ขอ — อะไหล่บางตัวต่อชุดไม่ใช่ 1 (หัว USB ×2 · สาย AUX ÷2) ค่าส่วนใหญ่จึงถูกต้องกว่า
        $sets = inv_pickup_mode(array_column($d['lines'], 'req'));
        $missing = [];
        if ($d['issued']) {
            foreach ($d['lines'] as $ln) {
                if ($ln['got'] + 0.001 < $ln['req']) {
                    $missing[] = $ln['name'] . ($ln['got'] > 0 ? ' (จ่าย ' . (0 + $ln['got']) . '/' . (0 + $ln['req']) . ')' : '');
                }
            }
        }
        $items[$id . '|' . $map['product_id']] = ['pre_id' => $id, 'product_id' => (int) $map['product_id'], 'qty' => $sets,
                                                   'kind' => 'set', 'missing' => $missing, 'parts' => []];
    }
    unset($d);

    // ── ยอดผลิตแล้ว (ตัดยอด) + ปิดเอง ──
    $alloc = [];
    $closed = [];
    $in = implode(',', array_map(function ($v) { return "'" . db()->real_escape_string($v) . "'"; }, $ids));
    $r = db()->query("SELECT pre_id, product_id, COUNT(*) n FROM inv_pickup_alloc WHERE pre_id IN ($in) GROUP BY pre_id, product_id");
    while ($x = $r->fetch_assoc()) {
        $alloc[$x['pre_id'] . '|' . $x['product_id']] = (int) $x['n'];
    }
    $r = db()->query("SELECT pre_id, product_id, reason, closed_by, closed_at FROM inv_pickup_close WHERE pre_id IN ($in)");
    while ($x = $r->fetch_assoc()) {
        $closed[$x['pre_id'] . '|' . $x['product_id']] = $x;
    }
    $prodNames = [];
    $r = db()->query('SELECT id, name FROM products');
    while ($x = $r->fetch_row()) {
        $prodNames[(int) $x[0]] = (string) $x[1];
    }
    foreach ($items as $key => &$it) {
        $d = $docs[$it['pre_id']];
        $it['done'] = $alloc[$key] ?? 0;
        $it['left'] = max(0, $it['qty'] - $it['done']);
        $it['closed'] = $closed[$key] ?? null;
        $it['model'] = $prodNames[$it['product_id']] ?? ('#' . $it['product_id']);
        $it['date'] = $d['date'];
        $it['set'] = $d['set'];
        $it['by'] = $d['by'];
        $it['issued_at'] = $d['issued_at'];
        // สถานะ: รอคลังจ่าย → เบิกแล้วรอผลิต → ผลิตครบ (หรือปิดเอง)
        $it['state'] = !$d['issued'] ? 'waiting' : (($it['left'] <= 0 || $it['closed']) ? 'done' : 'ready');
    }
    unset($it);
    uasort($items, function ($a, $b) {
        return [$a['date'], $a['pre_id']] <=> [$b['date'], $b['pre_id']];
    });
    $out['ok'] = true;
    $out['docs'] = $docs;
    $out['items'] = array_values($items);
    return $out;
}

/**
 * ตัดยอดเครื่องที่เพิ่งลงทะเบียน: รุ่นเดียวกัน · ใบที่คลังจ่ายแล้วและยังเหลือ · เก่าสุดก่อน
 *
 * ต่อ inventory ไม่ได้ = ไม่ตัด (ลงทะเบียนเครื่องต้องไม่ล้มเพราะระบบอื่น) · เครื่องที่ไม่มีใบให้ตัด = ข้าม
 *
 * @param int[]  $assetIds
 * @param string $actor
 * @return array{ok:bool, allocated:array<int,string>, error:string} allocated: asset_id => pre_id
 */
function inv_pickup_allocate(array $assetIds, string $actor): array
{
    $res = ['ok' => false, 'allocated' => [], 'error' => ''];
    $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds))));
    if (!$ids) {
        $res['ok'] = true;
        return $res;
    }
    $data = inv_pickup_load();
    if (!$data['ok']) {
        $res['error'] = $data['error'];
        return $res;
    }
    $open = [];
    foreach ($data['items'] as $it) {
        if ($it['state'] === 'ready') {
            $open[$it['product_id']][] = ['pre_id' => $it['pre_id'], 'left' => $it['left']];
        }
    }
    $r = db()->query('SELECT a.id, a.product_id FROM assets a LEFT JOIN inv_pickup_alloc x ON x.asset_id = a.id
                      WHERE x.id IS NULL AND a.id IN (' . implode(',', $ids) . ') ORDER BY a.id');
    while ($a = $r->fetch_assoc()) {
        $pid = (int) $a['product_id'];
        if (empty($open[$pid])) {
            continue;
        }
        $slot = &$open[$pid][0];
        q("INSERT IGNORE INTO inv_pickup_alloc (pre_id, product_id, asset_id, source, allocated_by) VALUES (?, ?, ?, 'auto', ?)",
          'siis', [$slot['pre_id'], $pid, (int) $a['id'], mb_substr($actor, 0, 100)]);
        $res['allocated'][(int) $a['id']] = $slot['pre_id'];
        if (--$slot['left'] <= 0) {
            array_shift($open[$pid]);
        }
        unset($slot);
    }
    $res['ok'] = true;
    return $res;
}

// ─ ตัดยอดเอง / ปิดรายการ ────────────────────────────────────────────────────────

/**
 * ตัดยอดเครื่องเข้าใบเบิกเอง (หรือย้ายจากใบเดิม) ด้วยรหัสเครื่อง
 *
 * @param string $preId
 * @param int    $productId
 * @param string $code  รหัสเครื่อง / S/N
 * @param string $actor
 * @return array{ok:bool, message:string}
 */
function inv_pickup_manual_alloc(string $preId, int $productId, string $code, string $actor): array
{
    ensure_inv_pickup_schema();
    $code = trim($code);
    $a = qr('SELECT a.id, a.asset_code, a.product_id, p.name FROM assets a JOIN products p ON p.id = a.product_id
             WHERE a.asset_code = ? OR a.factory_serial = ? ORDER BY (a.asset_code = ?) DESC LIMIT 1', 'sss', [$code, $code, $code])->fetch_assoc();
    if (!$a) {
        return ['ok' => false, 'message' => 'ไม่พบเครื่อง ' . $code];
    }
    if ((int) $a['product_id'] !== $productId) {
        return ['ok' => false, 'message' => $a['asset_code'] . ' เป็นรุ่น ' . $a['name'] . ' ไม่ตรงกับรายการนี้'];
    }
    $old = qr('SELECT pre_id FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [(int) $a['id']])->fetch_assoc();
    q('DELETE FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [(int) $a['id']]);
    q("INSERT INTO inv_pickup_alloc (pre_id, product_id, asset_id, source, allocated_by) VALUES (?, ?, ?, 'manual', ?)",
      'siis', [$preId, $productId, (int) $a['id'], mb_substr($actor, 0, 100)]);
    return ['ok' => true, 'message' => $a['asset_code'] . ($old && $old['pre_id'] !== $preId ? ' ย้ายมาจากใบ ' . $old['pre_id'] : ' ตัดยอดแล้ว')];
}

/**
 * เอาเครื่องออกจากใบเบิก (ยอดคืนใบ)
 *
 * @param int $assetId
 * @return void
 */
function inv_pickup_unalloc(int $assetId): void
{
    ensure_inv_pickup_schema();
    q('DELETE FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [$assetId]);
}

/**
 * ปิด / เปิดรายการ (ใบ × รุ่น) เอง
 *
 * @param string $preId
 * @param int    $productId
 * @param bool   $close
 * @param string $reason
 * @param string $actor
 * @return void
 */
function inv_pickup_set_closed(string $preId, int $productId, bool $close, string $reason, string $actor): void
{
    ensure_inv_pickup_schema();
    if ($close) {
        q('REPLACE INTO inv_pickup_close (pre_id, product_id, reason, closed_by) VALUES (?, ?, ?, ?)',
          'siss', [$preId, $productId, mb_substr(trim($reason), 0, 255), mb_substr($actor, 0, 100)]);
    } else {
        q('DELETE FROM inv_pickup_close WHERE pre_id = ? AND product_id = ?', 'si', [$preId, $productId]);
    }
}

/**
 * เครื่องที่ตัดยอดจากรายการนี้
 *
 * @param string $preId
 * @param int    $productId
 * @return array<int,array<string,mixed>>
 */
function inv_pickup_alloc_assets(string $preId, int $productId): array
{
    ensure_inv_pickup_schema();
    $out = [];
    $r = qr('SELECT x.asset_id, x.source, x.allocated_by, x.allocated_at, a.asset_code, a.status
             FROM inv_pickup_alloc x JOIN assets a ON a.id = x.asset_id
             WHERE x.pre_id = ? AND x.product_id = ? ORDER BY a.asset_code', 'si', [$preId, $productId]);
    while ($x = $r->fetch_assoc()) {
        $out[] = $x;
    }
    return $out;
}

/**
 * ใบเบิกที่เครื่องนี้ถูกตัดยอด (แสดงบนโปรไฟล์เครื่อง)
 *
 * @param int $assetId
 * @return array<string,mixed>|null
 */
function inv_pickup_for_asset(int $assetId): ?array
{
    ensure_inv_pickup_schema();
    $x = qr('SELECT pre_id, product_id, source, allocated_by, allocated_at FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [$assetId])->fetch_assoc();
    return $x ?: null;
}

// ─ ผูกชุด inventory → รุ่นเรา ──────────────────────────────────────────────────

/**
 * ทำชื่อให้เทียบกันได้ (ตัดช่องว่าง/ขีด/วงเล็บ · ตัวเล็ก)
 *
 * @param string $s
 * @return string
 */
function inv_pickup_norm(string $s): string
{
    return preg_replace('/[\s\-_().\/]+/u', '', mb_strtolower($s)) ?? $s;
}

/**
 * รุ่นของเราที่ชื่อใกล้เคียงที่สุด (ค่าแนะนำตอนผูก — ผู้ดูแลยืนยันเองเสมอ ไม่บันทึกให้อัตโนมัติ)
 *
 * @param string            $name
 * @param array<int,string> $products id => name
 * @return int|null
 */
function inv_pickup_suggest_product(string $name, array $products): ?int
{
    // ชื่อที่ต่างกันเกินกว่าจะเดาจากตัวอักษรได้ — ของสำเร็จรูปที่เบิกแยกชิ้นเป็นส่วนใหญ่
    $hints = [
        'mobile printer' => 'Portable Printer', 'barcode wireless' => 'Scanner Wireless', 'barcode' => 'Scanner',
        'otg smartcard s' => 'Smart Card Reader S', 'smartcard สีดำ' => 'Smart Card Reader', 'printer 80' => 'Printer 80mm.',
        'bitstamp-f (duo)' => 'bitStamp Factory Dual', 'bitstamp-f' => 'bitStamp Factory Single', 'bitstamp-g' => 'bitStamp Guard',
    ];
    $low = mb_strtolower($name);
    foreach ($hints as $k => $target) {
        if (mb_strpos($low, $k) !== false) {
            $id = array_search($target, $products, true);
            return $id !== false ? (int) $id : null;
        }
    }
    $n = inv_pickup_norm($name);
    $best = null;
    $bestScore = 0.0;
    foreach ($products as $id => $pname) {
        similar_text($n, inv_pickup_norm($pname), $pct);
        if ($pct > $bestScore) {
            $bestScore = $pct;
            $best = (int) $id;
        }
    }
    return $bestScore >= 80 ? $best : null;
}

/**
 * ชื่อชุด + อะไหล่ในใบแบบแยกชิ้น ที่ต้องผูก (ย้อนจากวันเริ่มติดตามไป 1 ปี — ครอบรุ่นที่ยังเบิกกันอยู่)
 *
 * @return array{ok:bool, error:string, sets:array<string,array<string,mixed>>, parts:array<int,array<string,mixed>>}
 */
function inv_pickup_mapping_rows(): array
{
    $out = ['ok' => false, 'error' => '', 'sets' => [], 'parts' => []];
    $inv = function_exists('dbInventory') ? dbInventory() : null;
    if (!$inv) {
        $out['error'] = dbInventoryError();
        return $out;
    }
    $maps = inv_pickup_maps();
    $since = date('Y-m-d', strtotime(inv_pickup_since() . ' -1 year'));
    $type = INV_PICKUP_TYPE;
    $st = $inv->prepare("SELECT p.pre_product, p.pre_part, pt.part_name, COUNT(DISTINCT p.pre_id) docs, MAX(p.pre_date) last
                         FROM pre_stock p LEFT JOIN part pt ON pt.part_id = p.pre_part
                         WHERE p.pre_type = ? AND p.pre_date >= ? AND p.pre_status <> 'cancel'
                         GROUP BY p.pre_product, p.pre_part");
    $st->bind_param('ss', $type, $since);
    $st->execute();
    $res = $st->get_result();
    $partsBySet = [];
    while ($r = $res->fetch_assoc()) {
        $set = trim((string) $r['pre_product']);
        if ($set === '' || ctype_digit($set)) {
            continue;   // ค่าแปลก ๆ ที่หลุดมา (เช่น "15")
        }
        if (!isset($out['sets'][$set])) {
            $m = $maps['sets'][$set] ?? null;
            $out['sets'][$set] = ['name' => $set, 'docs' => 0, 'last' => '', 'mode' => $m['mode'] ?? '',
                                  'product_id' => $m['product_id'] ?? null, 'saved' => (bool) $m];
        }
        $out['sets'][$set]['last'] = max($out['sets'][$set]['last'], (string) $r['last']);
        $partsBySet[$set][(int) $r['pre_part']] = ['name' => (string) $r['part_name'], 'docs' => (int) $r['docs']];
    }
    $st->close();
    // จำนวนใบต่อชุด (GROUP BY ข้างบนแยกตามอะไหล่ นับใบซ้ำ)
    $st = $inv->prepare("SELECT pre_product, COUNT(DISTINCT pre_id) FROM pre_stock
                         WHERE pre_type = ? AND pre_date >= ? AND pre_status <> 'cancel' GROUP BY pre_product");
    $st->bind_param('ss', $type, $since);
    $st->execute();
    $res = $st->get_result();
    while ($x = $res->fetch_row()) {
        $k = trim((string) $x[0]);
        if (isset($out['sets'][$k])) {
            $out['sets'][$k]['docs'] = (int) $x[1];
        }
    }
    $st->close();
    // อะไหล่ของชุดที่นับแบบแยกชิ้น (หรือยังไม่ได้ตั้ง แต่ชื่อบอกว่าเป็น Accessories)
    foreach ($out['sets'] as $set => $row) {
        $isParts = $row['mode'] === 'parts' || ($row['mode'] === '' && stripos($set, 'accessor') !== false);
        if (!$isParts) {
            continue;
        }
        foreach ($partsBySet[$set] ?? [] as $pid => $p) {
            if (!isset($out['parts'][$pid])) {
                $out['parts'][$pid] = ['part_id' => $pid, 'name' => $p['name'] !== '' ? $p['name'] : '#' . $pid, 'docs' => 0, 'sets' => [],
                                       'product_id' => $maps['parts'][$pid] ?? null, 'saved' => array_key_exists($pid, $maps['parts'])];
            }
            $out['parts'][$pid]['docs'] += $p['docs'];
            $out['parts'][$pid]['sets'][] = $set;
        }
    }
    uasort($out['sets'], function ($a, $b) { return [$b['last'], $a['name']] <=> [$a['last'], $b['name']]; });
    uasort($out['parts'], function ($a, $b) { return [$b['docs'], $a['name']] <=> [$a['docs'], $b['name']]; });
    $out['ok'] = true;
    return $out;
}

/**
 * จับคู่ย้อนหลัง: เครื่องที่ลงทะเบียนไปแล้วแต่ยังไม่ได้ตัดยอด → ใบเบิกที่ยังค้าง
 *
 * ใช้ตอนเริ่มเปิดฟีเจอร์ (หรือหลังผูกรุ่นใหม่) — ไม่งั้นใบที่เบิกก่อนหน้าแล้วผลิตไปบางส่วน จะขึ้นว่ารอผลิตเต็มจำนวน
 * กติกาเดียวกับตอนลงทะเบียน: รุ่นเดียวกัน · ใบเก่าสุดก่อน · เครื่องต้องลงทะเบียน **หลัง** คลังจ่ายของใบนั้น
 * (ลงทะเบียนก่อนคลังจ่าย = ไม่ได้ผลิตจากของชุดนี้)
 *
 * @param string $actor
 * @return array{ok:bool, allocated:int, error:string}
 */
function inv_pickup_backfill(string $actor): array
{
    $res = ['ok' => false, 'allocated' => 0, 'error' => ''];
    $data = inv_pickup_load();
    if (!$data['ok']) {
        $res['error'] = $data['error'];
        return $res;
    }
    $used = [];
    foreach ($data['items'] as $it) {
        if ($it['state'] !== 'ready' || $it['left'] <= 0) {
            continue;
        }
        $doc = $data['docs'][$it['pre_id']];
        $from = $doc['issued_at'] !== '' ? $doc['issued_at'] : $doc['date'] . ' 00:00:00';
        $r = qr('SELECT a.id FROM assets a LEFT JOIN inv_pickup_alloc x ON x.asset_id = a.id
                 WHERE x.id IS NULL AND a.product_id = ? AND a.created_at >= ?
                 ORDER BY a.created_at, a.id LIMIT ' . ((int) $it['left'] + count($used)), 'is', [(int) $it['product_id'], $from]);
        $left = (int) $it['left'];
        while ($left > 0 && ($a = $r->fetch_assoc())) {
            if (isset($used[(int) $a['id']])) {
                continue;
            }
            q("INSERT IGNORE INTO inv_pickup_alloc (pre_id, product_id, asset_id, source, allocated_by) VALUES (?, ?, ?, 'auto', ?)",
              'siis', [$it['pre_id'], (int) $it['product_id'], (int) $a['id'], mb_substr($actor . ' (ย้อนหลัง)', 0, 100)]);
            $used[(int) $a['id']] = true;
            $left--;
            $res['allocated']++;
        }
    }
    $res['ok'] = true;
    return $res;
}
