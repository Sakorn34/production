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
 * หน่วยที่ติดตาม/ตัดยอด = (ใบ, กลุ่มรุ่น) — ใบแยกชิ้นใบเดียวอาจมีหลายกลุ่ม
 *
 * ของสำเร็จรูปที่ปนในใบชุด ตั้งค่าได้ที่ inv_pickup_map.php ตาราง inv_set_line_map (รายชุด × อะไหล่ → รุ่น)
 * ค่าเริ่มต้น seed จาก INV_PICKUP_SET_COMPANIONS / INV_PICKUP_BUNDLE_COMPANIONS ถ้าตารางยังว่าง
 *   แยกตามชื่อชุด เช่น Mobile Printer ในใบ bitVisitor S → Portable Printer
 *   หรือต้องครบในใบเดียวกัน (need_all) เช่น กล่อง Card Cam + Logitech Brio → Card Camera
 *   ยกเว้นชุดใน INV_PICKUP_COMPANION_SKIP_SETS (เช่น bitVisitor Plus — Brio อยู่ในสูตรชุด ไม่แยกคิว Card Camera)
 * ใบชุดยังนับรุ่นหลักตามเดิม · ใบเบิกเสริมที่เหลือแค่อะไหล่พวกนี้จะไม่นับเป็นเครื่องรุ่นหลัก แต่คิวที่แยกยังขึ้น
 *
 * อะไหล่/ชุดเดียวใช้ได้หลายรุ่น (เช่น หัวอ่านตัวเดียวกันใส่ได้ทั้ง Smart Card Reader และรุ่น S)
 * → ผูกได้หลายรุ่น เป็น "กลุ่มรุ่น" (grp = product id เรียงกัน คั่นด้วย -) ลงทะเบียนรุ่นไหนในกลุ่มก็ตัดยอดใบนั้น
 * อะไหล่บางตัวใช้มากกว่า 1 ชิ้นต่อเครื่อง → ตั้ง "ชิ้นต่อเครื่อง" จำนวนเครื่อง = ชิ้นที่เบิก ÷ ชิ้นต่อเครื่อง
 * (ใบชุดไม่ต้องตั้ง — นับจำนวนชุดจากค่าที่ซ้ำมากสุด อะไหล่ ×2 ในชุดจึงไม่ทำให้นับผิด)
 */

/** @var string ประเภทใบเบิกผลิตในระบบ inventory (สะกดตรงตามที่ระบบนั้นบันทึก) */
const INV_PICKUP_TYPE = 'เบิกผลิต';
/** @var string site_settings: วันที่เริ่มติดตาม (Y-m-d) — ใบก่อนวันนี้ไม่แสดง */
const INV_PICKUP_SINCE_KEY = 'inv_pickup_since';
/**
 * ของสำเร็จรูปที่ปนในใบชุด — ชื่อชุด => (ชื่ออะไหล่ inventory => ชื่อรุ่นของเรา)
 *
 * @var array<string,array<string,string>>
 */
const INV_PICKUP_SET_COMPANIONS = [
    'bitVisitor S' => [
        'Mobile Printer TPM-5822' => 'Portable Printer',
    ],
];
/**
 * ต้องมีอะไหล่ครบทุกตัวในใบเดียวกันถึงแยกเป็นเครื่องอีกรุ่น
 * กล่อง Card Cam + Logitech Brio พร้อมกัน → Card Camera · มีแค่ตัวใดตัวหนึ่ง = อะไหล่ของรุ่นในใบนั้น
 *
 * @var array<int,array{product:string,parts:string[]}>
 */
const INV_PICKUP_BUNDLE_COMPANIONS = [
    [
        'product' => 'Card Camera',
        'parts' => ['กล่อง Card Cam', 'Logitech Brio100-Graphite'],
    ],
];
/**
 * ชุดผลิตที่ไม่แยกของสำเร็จรูปอื่นเป็นคิว (อะไหล่ในใบ = ประกอบรุ่นหลักอย่างเดียว)
 * bitVisitor Plus มี Brio/กล่อง Card Cam ในสูตร แต่ไม่ผลิต Card Camera แยกจากใบชุด Plus
 *
 * @var string[]
 */
const INV_PICKUP_COMPANION_SKIP_SETS = [
    'bitVisitor Plus',
];

/**
 * ชุดที่ไม่ใช้กฎ companion (แยกคิว Card Camera / Portable Printer ฯลฯ)
 *
 * @param string $setName ชื่อชุด pre_product ใน inventory
 * @return bool true = ใช้ companion ได้ตาม inv_set_line_map
 */
function inv_pickup_set_allows_companion_machine(string $setName): bool
{
    $setName = trim($setName);

    return $setName !== '' && !in_array($setName, INV_PICKUP_COMPANION_SKIP_SETS, true);
}

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
        product_ids VARCHAR(255) NOT NULL DEFAULT '',
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // อะไหล่ในใบแบบแยกชิ้น → รุ่นของเรา (product_ids ว่าง = ไม่ใช่เครื่อง ไม่ติดตาม เช่น สายคล้อง)
    db()->query("CREATE TABLE IF NOT EXISTS inv_part_map (
        inv_part_id INT NOT NULL PRIMARY KEY,
        product_ids VARCHAR(255) NOT NULL DEFAULT '',
        per_unit DECIMAL(8,2) NOT NULL DEFAULT 1,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // เครื่องไหนตัดจากใบไหน — 1 เครื่องตัดได้ใบเดียว
    db()->query("CREATE TABLE IF NOT EXISTS inv_pickup_alloc (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pre_id VARCHAR(30) NOT NULL,
        grp VARCHAR(100) NOT NULL,
        asset_id INT NOT NULL,
        source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
        allocated_by VARCHAR(100) NULL,
        allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_asset (asset_id),
        KEY idx_pre (pre_id, grp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // ปิดรายการเอง (ผลิตไม่ครบแต่จบแล้ว · ของเสีย · ยกเลิก)
    db()->query("CREATE TABLE IF NOT EXISTS inv_pickup_close (
        pre_id VARCHAR(30) NOT NULL,
        grp VARCHAR(100) NOT NULL,
        reason VARCHAR(255) NULL,
        closed_by VARCHAR(100) NULL,
        closed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (pre_id, grp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // เครื่องที่ไม่ต้องนับเข้าใบเบิก — นับเข้าคลังใหม่เฉย ๆ หรือเป็นของค้างจากใบเบิกเก่าก่อนเริ่มติดตาม
    // เก็บแยกจากการตัดยอด เพื่อให้เอากลับมานับได้ทีหลังและรู้ว่าใครเคลียร์เมื่อไหร่
    db()->query("CREATE TABLE IF NOT EXISTS inv_pickup_skip (
        asset_id INT NOT NULL PRIMARY KEY,
        reason VARCHAR(255) NULL,
        skipped_by VARCHAR(100) NULL,
        skipped_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensure_inv_pickup_set_companions();
}

/**
 * ตารางตั้งค่าอะไหล่ในใบชุดต่อรุ่น + seed ค่าเริ่มต้นถ้ายังว่าง + ถอน seed เก่าใน inv_part_map
 *
 * @return void
 */
function ensure_inv_pickup_set_companions(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q("DELETE FROM inv_part_map WHERE updated_by = ?", 's', ['system']);
    db()->query("CREATE TABLE IF NOT EXISTS inv_set_line_map (
        inv_name VARCHAR(191) NOT NULL,
        inv_part_id INT NOT NULL,
        part_name VARCHAR(191) NOT NULL DEFAULT '',
        product_ids VARCHAR(255) NOT NULL DEFAULT '',
        need_all TINYINT(1) NOT NULL DEFAULT 0,
        updated_by VARCHAR(100) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (inv_name, inv_part_id),
        KEY idx_set_name (inv_name, part_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $n = 0;
    $cnt = db()->query('SELECT COUNT(*) c FROM inv_set_line_map');
    if ($cnt && ($row = $cnt->fetch_assoc())) {
        $n = (int) $row['c'];
    }
    inv_pickup_scrub_companion_skip_sets();

    if ($n > 0) {
        return;
    }
    $inv = function_exists('dbInventory') ? dbInventory() : null;
    if (!$inv) {
        return;
    }
    foreach (INV_PICKUP_SET_COMPANIONS as $set => $parts) {
        foreach ($parts as $partName => $prodName) {
            inv_pickup_line_map_seed($inv, $set, $partName, $prodName, 0);
        }
    }
    foreach (INV_PICKUP_BUNDLE_COMPANIONS as $b) {
        $sets = inv_pickup_bundle_sets($inv, $b['parts']);
        foreach ($sets as $set) {
            if (!inv_pickup_set_allows_companion_machine($set)) {
                continue;
            }
            foreach ($b['parts'] as $partName) {
                inv_pickup_line_map_seed($inv, $set, $partName, $b['product'], 1);
            }
        }
    }
}

/**
 * ลบแถว companion ของชุดที่ไม่แยกคิว (เช่น bitVisitor Plus × Card Camera)
 *
 * @return void
 */
function inv_pickup_scrub_companion_skip_sets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_inv_pickup_schema();
    foreach (INV_PICKUP_COMPANION_SKIP_SETS as $setName) {
        q('DELETE FROM inv_set_line_map WHERE inv_name = ?', 's', [$setName]);
    }
}

/**
 * part_id จากชื่ออะไหล่ใน inventory
 *
 * @param \mysqli $inv
 * @param string  $partName
 * @return int
 */
function inv_pickup_inventory_part_id($inv, string $partName): int
{
    $st = $inv->prepare('SELECT part_id FROM part WHERE part_name = ? LIMIT 1');
    if (!$st) {
        return 0;
    }
    $st->bind_param('s', $partName);
    $st->execute();
    $res = $st->get_result();
    $row = $res ? $res->fetch_row() : null;
    $st->close();
    return $row ? (int) $row[0] : 0;
}

/**
 * ชุด inventory ที่เคยมีอะไหล่ครบทุกตัวของ bundle ในใบเดียวกัน
 *
 * @param \mysqli  $inv
 * @param string[] $partNames
 * @return string[]
 */
function inv_pickup_bundle_sets($inv, array $partNames): array
{
    $partNames = array_values(array_filter(array_map('strval', $partNames)));
    if (count($partNames) < 2) {
        return [];
    }
    $sql = 'SELECT p0.pre_product FROM pre_stock p0';
    $binds = [];
    $types = '';
    foreach ($partNames as $i => $nm) {
        $talias = 't' . $i;
        if ($i === 0) {
            $sql .= " INNER JOIN part $talias ON $talias.part_id = p0.pre_part AND $talias.part_name = ?";
        } else {
            $alias = 'p' . $i;
            $sql .= " INNER JOIN pre_stock $alias ON $alias.pre_id = p0.pre_id AND $alias.pre_type = p0.pre_type"
                . " INNER JOIN part $talias ON $talias.part_id = $alias.pre_part AND $talias.part_name = ?";
        }
        $binds[] = $nm;
        $types .= 's';
    }
    $sql .= ' WHERE p0.pre_type = ? GROUP BY p0.pre_product';
    $binds[] = INV_PICKUP_TYPE;
    $types .= 's';
    $st = $inv->prepare($sql);
    if (!$st) {
        return [];
    }
    $st->bind_param($types, ...$binds);
    $st->execute();
    $res = $st->get_result();
    $out = [];
    while ($res && ($x = $res->fetch_row())) {
        $name = trim((string) $x[0]);
        if ($name !== '') {
            $out[] = $name;
        }
    }
    $st->close();
    return $out;
}

/**
 * ใส่แถวตั้งค่าเริ่มต้น (ไม่ทับของที่มีแล้ว)
 *
 * @param \mysqli $inv
 * @param string  $set
 * @param string  $partName
 * @param string  $prodName
 * @param int     $needAll
 * @return void
 */
function inv_pickup_line_map_seed($inv, string $set, string $partName, string $prodName, int $needAll): void
{
    $partId = inv_pickup_inventory_part_id($inv, $partName);
    $gid = inv_pickup_products_by_name()[$prodName] ?? 0;
    $set = mb_substr(trim($set), 0, 191);
    if ($partId <= 0 || $gid <= 0 || $set === '') {
        return;
    }
    q('INSERT IGNORE INTO inv_set_line_map (inv_name, inv_part_id, part_name, product_ids, need_all, updated_by) VALUES (?, ?, ?, ?, ?, ?)',
      'sissis', [$set, $partId, mb_substr($partName, 0, 191), (string) $gid, $needAll ? 1 : 0, 'system']);
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
 * @return array{sets:array<string,array{mode:string,grp:string}>, parts:array<int,array{grp:string,per_unit:float}>}
 */
function inv_pickup_maps(): array
{
    ensure_inv_pickup_schema();
    $sets = [];
    $r = db()->query('SELECT inv_name, mode, product_ids FROM inv_set_map');
    while ($x = $r->fetch_assoc()) {
        $sets[(string) $x['inv_name']] = ['mode' => (string) $x['mode'], 'grp' => inv_pickup_grp((string) $x['product_ids'])];
    }
    $parts = [];
    $r = db()->query('SELECT inv_part_id, product_ids, per_unit FROM inv_part_map');
    while ($x = $r->fetch_assoc()) {
        $parts[(int) $x['inv_part_id']] = ['grp' => inv_pickup_grp((string) $x['product_ids']), 'per_unit' => max(0.01, (float) $x['per_unit'])];
    }
    return ['sets' => $sets, 'parts' => $parts];
}

/**
 * กลุ่มรุ่น — product id ไม่ซ้ำ เรียงจากน้อยไปมาก คั่นด้วย "-" ('' = ไม่มีรุ่น)
 *
 * @param string|array<int,int|string> $ids "3,1" หรือ [3,1]
 * @return string "1-3"
 */
function inv_pickup_grp($ids): string
{
    $list = is_array($ids) ? $ids : preg_split('/[^0-9]+/', (string) $ids, -1, PREG_SPLIT_NO_EMPTY);
    $list = array_values(array_unique(array_filter(array_map('intval', $list))));
    sort($list);
    return implode('-', $list);
}

/**
 * product id ในกลุ่มรุ่น
 *
 * @param string $grp
 * @return int[]
 */
function inv_pickup_grp_ids(string $grp): array
{
    return $grp === '' ? [] : array_map('intval', explode('-', $grp));
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
 * ใส่รายการคิวจากอะไหล่ที่เป็นเครื่อง (ใบแยกชิ้น หรือของสำเร็จรูปที่ปนในใบชุด)
 *
 * อะไหล่หลายตัวในใบเดียวที่ผูกกลุ่มรุ่นเดียวกัน = ชิ้นส่วนของเครื่องชุดเดียวกัน — ใช้ค่ามากสุด ไม่บวกกัน
 *
 * @param array<string,array<string,mixed>> $items
 * @param string                            $preId
 * @param array{name:string,req:float,got:float} $ln
 * @param array{grp:string,per_unit:float}  $pm
 * @param bool                              $issued คลังจ่ายแล้ว
 * @return void
 */
function inv_pickup_push_part_item(array &$items, string $preId, array $ln, array $pm, bool $issued): void
{
    if ($pm['grp'] === '') {
        return;
    }
    $qty = (int) floor((($issued ? $ln['got'] : $ln['req']) + 0.001) / $pm['per_unit']);
    $key = $preId . '|' . $pm['grp'];
    if (!isset($items[$key])) {
        $items[$key] = ['pre_id' => $preId, 'grp' => $pm['grp'], 'qty' => 0, 'kind' => 'parts', 'missing' => [], 'parts' => []];
    }
    $items[$key]['qty'] = max($items[$key]['qty'], $qty);
    $items[$key]['parts'][] = $ln['name'] . ($pm['per_unit'] != 1 ? ' (' . (0 + $pm['per_unit']) . ' ชิ้น/เครื่อง)' : '');
    if ($issued && $ln['got'] + 0.001 < $ln['req']) {
        $items[$key]['missing'][] = $ln['name'];
    }
}

/**
 * ชื่อรุ่นของเรา → product id (cache ใน request)
 *
 * @return array<string,int>
 */
function inv_pickup_products_by_name(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $r = db()->query('SELECT id, name FROM products');
        while ($r && ($x = $r->fetch_row())) {
            $map[(string) $x[1]] = (int) $x[0];
        }
    }
    return $map;
}

/**
 * ชื่ออะไหล่ในใบ (เรียงตามที่เบิก)
 *
 * @param array<int,array{name?:string}> $lines
 * @return string[]
 */
function inv_pickup_line_names(array $lines): array
{
    $out = [];
    foreach ($lines as $ln) {
        if (!empty($ln['name'])) {
            $out[] = (string) $ln['name'];
        }
    }
    return $out;
}

/**
 * ตั้งค่าอะไหล่ในใบชุด → รุ่นของเรา (cache ใน request)
 *
 * @return array<string,array<int,array{name:string,grp:string,need_all:int}>>
 */
function inv_pickup_line_maps(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    ensure_inv_pickup_schema();
    $cache = [];
    $r = db()->query('SELECT inv_name, inv_part_id, part_name, product_ids, need_all FROM inv_set_line_map');
    while ($r && ($x = $r->fetch_assoc())) {
        $set = (string) $x['inv_name'];
        $grp = inv_pickup_grp((string) $x['product_ids']);
        if ($grp === '') {
            continue;
        }
        $cache[$set][(int) $x['inv_part_id']] = [
            'name' => (string) $x['part_name'],
            'grp' => $grp,
            'need_all' => (int) $x['need_all'] ? 1 : 0,
        ];
    }
    return $cache;
}

/**
 * กลุ่มรุ่นของของสำเร็จรูปที่แยกจากใบนี้ ('' = อะไหล่ของรุ่นในใบนั้น)
 *
 * อ่าน inv_set_line_map ก่อน ถ้ายังไม่มีแถวใช้ค่าคงที่ตั้งต้น
 *
 * @param string   $setName       ชื่อชุดใน inventory (pre_product)
 * @param string   $partName      ชื่ออะไหล่
 * @param string[] $docLineNames  ชื่ออะไหล่ทั้งหมดในใบนี้
 * @return string grp เช่น "24"
 */
function inv_pickup_set_companion_grp(string $setName, string $partName, array $docLineNames = []): string
{
    if (!inv_pickup_set_allows_companion_machine($setName)) {
        return '';
    }
    $rules = inv_pickup_line_maps()[$setName] ?? [];
    $rule = null;
    foreach ($rules as $r) {
        if ($r['name'] === $partName) {
            $rule = $r;
            break;
        }
    }
    if ($rule) {
        if ($rule['need_all']) {
            foreach ($rules as $r) {
                if ($r['need_all'] && $r['grp'] === $rule['grp'] && !in_array($r['name'], $docLineNames, true)) {
                    return '';
                }
            }
        }
        return $rule['grp'];
    }
    $target = INV_PICKUP_SET_COMPANIONS[$setName][$partName] ?? '';
    if ($target === '') {
        foreach (INV_PICKUP_BUNDLE_COMPANIONS as $b) {
            if (!in_array($partName, $b['parts'], true)) {
                continue;
            }
            $ok = true;
            foreach ($b['parts'] as $need) {
                if (!in_array($need, $docLineNames, true)) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $target = $b['product'];
                break;
            }
        }
    }
    if ($target === '') {
        return '';
    }
    $id = inv_pickup_products_by_name()[$target] ?? 0;
    return $id > 0 ? inv_pickup_grp($id) : '';
}

/**
 * อะไหล่ในใบชุดที่แยกเป็นเครื่องคนละรุ่นกับรายการนี้ — ไม่โชว์เป็นชิ้นส่วนของรุ่นหลัก
 *
 * @param int      $partId
 * @param string   $itemGrp
 * @param array{parts:array<int,array{grp:string,per_unit:float}>} $maps
 * @param string   $setName
 * @param string   $partName
 * @param string[] $docLineNames
 * @return bool
 */
function inv_pickup_line_other_machine(int $partId, string $itemGrp, array $maps, string $setName = '', string $partName = '', array $docLineNames = []): bool
{
    $setName = trim($setName);
    if ($setName !== '' && !inv_pickup_set_allows_companion_machine($setName)) {
        return false;
    }
    if ($setName !== '' && $partName !== '') {
        $g = inv_pickup_set_companion_grp($setName, $partName, $docLineNames);
        if ($g !== '') {
            return $g !== $itemGrp;
        }
    }
    $g = $maps['parts'][$partId]['grp'] ?? '';
    return $g !== '' && $g !== $itemGrp;
}

/**
 * เดือนที่มีใบเบิกผลิต — ไว้ทำตัวเลือกดูย้อนหลัง
 *
 * ถามตาราง pre_stock ตรง ๆ ไม่ผ่าน inv_pickup_load() เพราะแค่ต้องการรายชื่อเดือน
 * ไม่ต้องคำนวณยอดผลิต/คงเหลือ ซึ่งหนักกว่ามาก
 *
 * @param int $limit จำนวนเดือนล่าสุดที่คืน
 * @return array<int,array{m:string,n:int}> ใหม่สุดก่อน
 */
function inv_pickup_months(int $limit = 24): array
{
    $inv = function_exists('dbInventory') ? dbInventory() : null;
    if (!$inv) {
        return [];
    }
    $limit = max(1, min(60, $limit));
    $st = $inv->prepare("SELECT DATE_FORMAT(pre_date, '%Y-%m') m, COUNT(DISTINCT pre_id) n
                         FROM pre_stock
                         WHERE pre_type = ? AND pre_status <> 'cancel' AND pre_date IS NOT NULL
                         GROUP BY m ORDER BY m DESC LIMIT $limit");
    if (!$st) {
        return [];
    }
    $type = INV_PICKUP_TYPE;
    $st->bind_param('s', $type);
    if (!$st->execute()) {
        return [];
    }
    $res = $st->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $m = (string) $r['m'];
        if (preg_match('/^\d{4}-\d{2}$/', $m)) {
            $out[] = ['m' => $m, 'n' => (int) $r['n']];
        }
    }
    return $out;
}

/**
 * ชื่อเดือนภาษาไทยจาก YYYY-MM
 *
 * @param string $m
 * @return string
 */
function inv_pickup_month_label(string $m): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $x)) {
        return $m;
    }
    $th = ['01' => 'มกราคม', '02' => 'กุมภาพันธ์', '03' => 'มีนาคม', '04' => 'เมษายน',
           '05' => 'พฤษภาคม', '06' => 'มิถุนายน', '07' => 'กรกฎาคม', '08' => 'สิงหาคม',
           '09' => 'กันยายน', '10' => 'ตุลาคม', '11' => 'พฤศจิกายน', '12' => 'ธันวาคม'];
    return ($th[$x[2]] ?? $x[2]) . ' ' . ((int) $x[1] + 543);
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
                inv_pickup_push_part_item($items, $id, $ln, $maps['parts'][$pid], $d['issued']);
            }
            continue;
        }
        // ของสำเร็จรูปที่ปนในใบนี้: ตามชื่อชุด หรือครบชุดในใบเดียวกัน (กล่อง Card Cam + Brio)
        $kitReq = [];
        $kitN = 0;
        $setGrp = $map['grp'] ?? '';
        $docNames = inv_pickup_line_names($d['lines']);
        foreach ($d['lines'] as $pid => $ln) {
            $cgrp = inv_pickup_set_companion_grp($d['set'], $ln['name'], $docNames);
            if ($cgrp !== '' && $cgrp !== $setGrp) {
                inv_pickup_push_part_item($items, $id, $ln, ['grp' => $cgrp, 'per_unit' => 1], $d['issued']);
                continue;
            }
            $kitN++;
            $kitReq[] = $ln['req'];
        }
        if (!$map || $setGrp === '') {
            $out['unmapped']['set:' . $d['set']] = ($out['unmapped']['set:' . $d['set']] ?? 0) + 1;
            continue;
        }
        // เบิกเสริม: อะไหล่ชุดที่เหลือไม่ถึงครึ่งสูตร (เช่น เบิกแค่ Mobile Printer) — ไม่นับเป็นเครื่องรุ่นหลัก
        // ของสำเร็จรูปที่แยกคิวไปแล้วไม่ถูกทิ้ง เพราะดันเข้า $items ก่อนหน้านี้
        $fsize = $formula[$d['set']] ?? 0;
        if ($fsize >= 3 && $kitN < (int) ceil($fsize / 2)) {
            $d['extra'] = true;
            continue;
        }
        // จำนวนชุด: ค่าที่ซ้ำมากสุดของแถวที่เป็นชิ้นส่วนชุด — ไม่นับของสำเร็จรูปที่แยกคิว ไม่งั้นจำนวนคนละตัวจะเพี้ยน
        $sets = inv_pickup_mode($kitReq !== [] ? $kitReq : array_column($d['lines'], 'req'));
        $missing = [];
        if ($d['issued']) {
            foreach ($d['lines'] as $pid => $ln) {
                if (inv_pickup_line_other_machine($pid, $setGrp, $maps, $d['set'], $ln['name'], $docNames)) {
                    continue;
                }
                if ($ln['got'] + 0.001 < $ln['req']) {
                    $missing[] = $ln['name'] . ($ln['got'] > 0 ? ' (จ่าย ' . (0 + $ln['got']) . '/' . (0 + $ln['req']) . ')' : '');
                }
            }
        }
        $items[$id . '|' . $setGrp] = ['pre_id' => $id, 'grp' => $setGrp, 'qty' => $sets,
                                       'kind' => 'set', 'missing' => $missing, 'parts' => []];
    }
    unset($d);

    // ── ยอดผลิตแล้ว (ตัดยอด) + ปิดเอง ──
    $alloc = [];
    $closed = [];
    $in = implode(',', array_map(function ($v) { return "'" . db()->real_escape_string($v) . "'"; }, $ids));
    $r = db()->query("SELECT pre_id, grp, COUNT(*) n FROM inv_pickup_alloc WHERE pre_id IN ($in) GROUP BY pre_id, grp");
    while ($x = $r->fetch_assoc()) {
        $alloc[$x['pre_id'] . '|' . $x['grp']] = (int) $x['n'];
    }
    $r = db()->query("SELECT pre_id, grp, reason, closed_by, closed_at FROM inv_pickup_close WHERE pre_id IN ($in)");
    while ($x = $r->fetch_assoc()) {
        $closed[$x['pre_id'] . '|' . $x['grp']] = $x;
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
        $it['products'] = inv_pickup_grp_ids($it['grp']);
        $it['model'] = implode(' / ', array_map(function ($p) use ($prodNames) { return $prodNames[$p] ?? ('#' . $p); }, $it['products']));
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
    // รายการที่ยังเหลือ เรียงเก่าสุดก่อน (items เรียงตามวันที่มาแล้ว) — รายการหนึ่งรับได้หลายรุ่น
    $open = [];
    foreach ($data['items'] as $it) {
        if ($it['state'] === 'ready') {
            $open[] = ['pre_id' => $it['pre_id'], 'grp' => $it['grp'], 'products' => $it['products'], 'left' => $it['left']];
        }
    }
    $r = db()->query('SELECT a.id, a.product_id FROM assets a LEFT JOIN inv_pickup_alloc x ON x.asset_id = a.id
                      WHERE x.id IS NULL AND a.id IN (' . implode(',', $ids) . ') ORDER BY a.id');
    while ($a = $r->fetch_assoc()) {
        $pid = (int) $a['product_id'];
        foreach ($open as &$slot) {
            if ($slot['left'] <= 0 || !in_array($pid, $slot['products'], true)) {
                continue;
            }
            q("INSERT IGNORE INTO inv_pickup_alloc (pre_id, grp, asset_id, source, allocated_by) VALUES (?, ?, ?, 'auto', ?)",
              'ssis', [$slot['pre_id'], $slot['grp'], (int) $a['id'], mb_substr($actor, 0, 100)]);
            $res['allocated'][(int) $a['id']] = $slot['pre_id'];
            $slot['left']--;
            break;
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
 * @param string $grp   กลุ่มรุ่นของรายการ ("1-3")
 * @param string $code  รหัสเครื่อง / S/N
 * @param string $actor
 * @return array{ok:bool, message:string}
 */
function inv_pickup_manual_alloc(string $preId, string $grp, string $code, string $actor): array
{
    ensure_inv_pickup_schema();
    $code = trim($code);
    $a = qr('SELECT a.id, a.asset_code, a.product_id, p.name FROM assets a JOIN products p ON p.id = a.product_id
             WHERE a.asset_code = ? OR a.factory_serial = ? ORDER BY (a.asset_code = ?) DESC LIMIT 1', 'sss', [$code, $code, $code])->fetch_assoc();
    if (!$a) {
        return ['ok' => false, 'message' => 'ไม่พบเครื่อง ' . $code];
    }
    if (!in_array((int) $a['product_id'], inv_pickup_grp_ids($grp), true)) {
        return ['ok' => false, 'message' => $a['asset_code'] . ' เป็นรุ่น ' . $a['name'] . ' ไม่ตรงกับรายการนี้'];
    }
    $old = qr('SELECT pre_id FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [(int) $a['id']])->fetch_assoc();
    q('DELETE FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [(int) $a['id']]);
    q("INSERT INTO inv_pickup_alloc (pre_id, grp, asset_id, source, allocated_by) VALUES (?, ?, ?, 'manual', ?)",
      'ssis', [$preId, $grp, (int) $a['id'], mb_substr($actor, 0, 100)]);
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
 * @param string $grp
 * @param bool   $close
 * @param string $reason
 * @param string $actor
 * @return void
 */
function inv_pickup_set_closed(string $preId, string $grp, bool $close, string $reason, string $actor): void
{
    ensure_inv_pickup_schema();
    if ($close) {
        q('REPLACE INTO inv_pickup_close (pre_id, grp, reason, closed_by) VALUES (?, ?, ?, ?)',
          'ssss', [$preId, $grp, mb_substr(trim($reason), 0, 255), mb_substr($actor, 0, 100)]);
    } else {
        q('DELETE FROM inv_pickup_close WHERE pre_id = ? AND grp = ?', 'ss', [$preId, $grp]);
    }
}

/**
 * เครื่องที่ตัดยอดจากรายการนี้
 *
 * @param string $preId
 * @param string $grp
 * @return array<int,array<string,mixed>>
 */
function inv_pickup_alloc_assets(string $preId, string $grp): array
{
    ensure_inv_pickup_schema();
    $out = [];
    $r = qr('SELECT x.asset_id, x.source, x.allocated_by, x.allocated_at, a.asset_code, a.status, p.name pname
             FROM inv_pickup_alloc x JOIN assets a ON a.id = x.asset_id JOIN products p ON p.id = a.product_id
             WHERE x.pre_id = ? AND x.grp = ? ORDER BY a.asset_code', 'ss', [$preId, $grp]);
    while ($x = $r->fetch_assoc()) {
        $out[] = $x;
    }
    return $out;
}

/**
 * S/N ที่ตัดยอดแล้วของหลายใบในกลุ่มรุ่นเดียวกัน — query เดียว (ใช้ที่ popup บน Dashboard)
 *
 * @param string   $grp
 * @param string[] $preIds
 * @return array<string,array<int,array<string,mixed>>> pre_id => รายการเครื่อง
 */
function inv_pickup_alloc_assets_by_doc(string $grp, array $preIds): array
{
    ensure_inv_pickup_schema();
    $out = [];
    $ids = array_values(array_unique(array_filter($preIds)));
    if (!$ids) {
        return $out;
    }
    $in = implode(',', array_map(function ($v) { return "'" . db()->real_escape_string($v) . "'"; }, $ids));
    $r = qr('SELECT x.pre_id, x.asset_id, a.asset_code, p.name pname
             FROM inv_pickup_alloc x JOIN assets a ON a.id = x.asset_id JOIN products p ON p.id = a.product_id
             WHERE x.grp = ? AND x.pre_id IN (' . $in . ') ORDER BY a.asset_code', 's', [$grp]);
    while ($x = $r->fetch_assoc()) {
        $out[$x['pre_id']][] = $x;
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
    $x = qr('SELECT pre_id, grp, source, allocated_by, allocated_at FROM inv_pickup_alloc WHERE asset_id = ?', 'i', [$assetId])->fetch_assoc();
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
        'กล่อง card cam' => 'Card Camera',
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
 * ชื่อชุด + อะไหล่ที่ต้องผูก (ย้อนจากวันเริ่มติดตามไป 1 ปี — ครอบรุ่นที่ยังเบิกกันอยู่)
 *
 * รวมอะไหล่ของใบแยกชิ้น และของสำเร็จรูปที่ปนในใบชุด (ชื่อใกล้เคียงรุ่นของเรา เช่น Mobile Printer)
 *
 * @return array{ok:bool, error:string, sets:array<string,array<string,mixed>>, parts:array<int,array<string,mixed>>, set_parts:array<string,array<int,array<string,mixed>>>}
 */
function inv_pickup_mapping_rows(): array
{
    $out = ['ok' => false, 'error' => '', 'sets' => [], 'parts' => [], 'set_parts' => []];
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
                                  'grp' => $m['grp'] ?? '', 'saved' => (bool) $m];
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
    // อะไหล่ของชุดแยกชิ้น → ตาราง Accessories · ใบชุด → แผงตั้งค่ารายชุด (set_parts)
    foreach ($out['sets'] as $set => $row) {
        $isParts = $row['mode'] === 'parts' || ($row['mode'] === '' && stripos($set, 'accessor') !== false);
        foreach ($partsBySet[$set] ?? [] as $pid => $p) {
            $name = $p['name'] !== '' ? $p['name'] : '#' . $pid;
            // ใบชุดตั้งค่าในแผงรายชุด — ไม่ปนเข้าตารางแยกรายชิ้น (กันผูก inv_part_map ทั้งระบบซ้ำ)
            if (!$isParts) {
                $out['set_parts'][$set][$pid] = ['part_id' => $pid, 'name' => $name, 'docs' => (int) $p['docs']];
                continue;
            }
            $savedGrp = $maps['parts'][$pid]['grp'] ?? '';
            if (!isset($out['parts'][$pid])) {
                $out['parts'][$pid] = ['part_id' => $pid, 'name' => $name, 'docs' => 0, 'sets' => [],
                                       'grp' => $savedGrp, 'per_unit' => $maps['parts'][$pid]['per_unit'] ?? 1.0,
                                       'saved' => array_key_exists($pid, $maps['parts'])];
            }
            $out['parts'][$pid]['docs'] += $p['docs'];
            $out['parts'][$pid]['sets'][] = $set;
        }
        if (isset($out['set_parts'][$set])) {
            uasort($out['set_parts'][$set], function ($a, $b) {
                return [$b['docs'], $a['name']] <=> [$a['docs'], $b['name']];
            });
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
                 WHERE x.id IS NULL AND a.product_id IN (' . implode(',', $it['products']) . ') AND a.created_at >= ?
                 ORDER BY a.created_at, a.id LIMIT ' . ((int) $it['left'] + count($used)), 's', [$from]);
        $left = (int) $it['left'];
        while ($left > 0 && ($a = $r->fetch_assoc())) {
            if (isset($used[(int) $a['id']])) {
                continue;
            }
            q("INSERT IGNORE INTO inv_pickup_alloc (pre_id, grp, asset_id, source, allocated_by) VALUES (?, ?, ?, 'auto', ?)",
              'ssis', [$it['pre_id'], $it['grp'], (int) $a['id'], mb_substr($actor . ' (ย้อนหลัง)', 0, 100)]);
            $used[(int) $a['id']] = true;
            $left--;
            $res['allocated']++;
        }
    }
    $res['ok'] = true;
    return $res;
}

// ─ เครื่องที่ไม่ผ่านใบเบิก ────────────────────────────────────────────────────────

/**
 * รุ่นที่ติดตามกับใบเบิก inventory — ผูกไว้ในชุดแบบ "ทั้งใบ" หรือในอะไหล่ของใบแยกชิ้น
 *
 * รุ่นที่ไม่เคยเบิกจากคลัง inventory (ผลิตจากสต็อกช่าง ฯลฯ) ไม่อยู่ในนี้
 * จึงไม่ถูกนับว่า "ไม่ผ่านใบเบิก" — ไม่งั้นเครื่องทุกเครื่องของรุ่นพวกนั้นขึ้นเตือนหมด
 *
 * @return array<int,true> product_id => true
 */
function inv_pickup_tracked_products(): array
{
    $maps = inv_pickup_maps();
    $out = [];
    foreach ($maps['sets'] as $m) {
        if ($m['mode'] === 'set') {
            foreach (inv_pickup_grp_ids($m['grp']) as $p) {
                $out[$p] = true;
            }
        }
    }
    foreach ($maps['parts'] as $m) {
        foreach (inv_pickup_grp_ids($m['grp']) as $p) {
            $out[$p] = true;
        }
    }
    foreach (INV_PICKUP_SET_COMPANIONS as $parts) {
        foreach ($parts as $prodName) {
            $id = inv_pickup_products_by_name()[$prodName] ?? 0;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
    }
    foreach (INV_PICKUP_BUNDLE_COMPANIONS as $b) {
        $id = inv_pickup_products_by_name()[$b['product']] ?? 0;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    foreach (inv_pickup_line_maps() as $rules) {
        foreach ($rules as $r) {
            foreach (inv_pickup_grp_ids($r['grp']) as $p) {
                $out[$p] = true;
            }
        }
    }
    return $out;
}

/**
 * รุ่นนี้ติดตามกับใบเบิกไหม
 *
 * @param int $productId
 * @return bool
 */
function inv_pickup_is_tracked(int $productId): bool
{
    static $tracked = null;
    if ($tracked === null) {
        $tracked = inv_pickup_tracked_products();
    }
    return isset($tracked[$productId]);
}

/**
 * เครื่องที่ลงทะเบียนตั้งแต่วันเริ่มติดตาม แต่ไม่ได้ตัดยอดใบเบิกใด (เฉพาะรุ่นที่ติดตาม)
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function inv_pickup_unmatched_assets(int $limit = 500): array
{
    $tracked = array_keys(inv_pickup_tracked_products());
    if (!$tracked) {
        return [];
    }
    $out = [];
    $r = qr('SELECT a.id, a.asset_code, a.product_id, a.created_at, a.created_by, p.name pname
             FROM assets a JOIN products p ON p.id = a.product_id
             LEFT JOIN inv_pickup_alloc x ON x.asset_id = a.id
             LEFT JOIN inv_pickup_skip s ON s.asset_id = a.id
             WHERE x.id IS NULL AND s.asset_id IS NULL AND a.product_id IN (' . implode(',', array_map('intval', $tracked)) . ') AND a.created_at >= ?
             ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int) $limit, 's', [inv_pickup_since() . ' 00:00:00']);
    while ($x = $r->fetch_assoc()) {
        $out[] = $x;
    }
    return $out;
}

/**
 * เคลียร์เครื่องออกจากรายการ "ลงทะเบียนโดยไม่มีใบเบิก" — ไม่นับเข้าชุดใบเบิกใด
 * (นับเข้าคลังใหม่เฉย ๆ · ของค้างจากใบเบิกเก่า) · ทะเบียนเครื่องไม่ถูกแตะ เอากลับมานับได้ทุกเมื่อ
 *
 * @param int[]  $assetIds
 * @param string $reason
 * @param string $actor
 * @return int จำนวนที่เคลียร์
 */
function inv_pickup_skip_assets(array $assetIds, string $reason, string $actor): int
{
    ensure_inv_pickup_schema();
    $n = 0;
    foreach (array_unique(array_filter(array_map('intval', $assetIds))) as $id) {
        q('REPLACE INTO inv_pickup_skip (asset_id, reason, skipped_by) VALUES (?, ?, ?)',
          'iss', [$id, mb_substr(trim($reason), 0, 255), mb_substr($actor, 0, 100)]);
        $n++;
    }
    return $n;
}

/**
 * เอาเครื่องกลับมานับในรายการอีกครั้ง
 *
 * @param int $assetId
 * @return void
 */
function inv_pickup_unskip(int $assetId): void
{
    ensure_inv_pickup_schema();
    q('DELETE FROM inv_pickup_skip WHERE asset_id = ?', 'i', [$assetId]);
}

/**
 * เครื่องที่เคลียร์ไว้ (ไม่นับเข้าใบเบิก)
 *
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function inv_pickup_skipped_assets(int $limit = 300): array
{
    ensure_inv_pickup_schema();
    $out = [];
    $r = qr('SELECT a.id, a.asset_code, a.created_at, p.name pname, s.reason, s.skipped_by, s.skipped_at
             FROM inv_pickup_skip s JOIN assets a ON a.id = s.asset_id JOIN products p ON p.id = a.product_id
             ORDER BY s.skipped_at DESC LIMIT ' . (int) $limit);
    while ($x = $r->fetch_assoc()) {
        $out[] = $x;
    }
    return $out;
}

/**
 * เครื่องนี้ "ไม่ผ่านใบเบิก" ไหม (รุ่นที่ติดตาม · ลงทะเบียนหลังวันเริ่มติดตาม · ไม่มีการตัดยอด · ยังไม่ถูกเคลียร์)
 *
 * @param array<string,mixed> $asset แถว assets (id, product_id, created_at)
 * @return bool
 */
function inv_pickup_asset_unmatched(array $asset): bool
{
    ensure_inv_pickup_schema();
    if (!inv_pickup_is_tracked((int) $asset['product_id'])) {
        return false;
    }
    if ((string) ($asset['created_at'] ?? '') < inv_pickup_since() . ' 00:00:00') {
        return false;
    }
    if (qr('SELECT asset_id FROM inv_pickup_skip WHERE asset_id = ?', 'i', [(int) $asset['id']])->fetch_assoc()) {
        return false;   // เคลียร์ไว้แล้ว ไม่ต้องเตือนบนโปรไฟล์เครื่อง
    }
    return inv_pickup_for_asset((int) $asset['id']) === null;
}
