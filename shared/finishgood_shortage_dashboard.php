<?php
/**
 * shared/finishgood_shortage_dashboard.php — รายการสินค้าที่ต้องผลิตเพิ่ม สำหรับหน้า Dashboard
 *
 * ใช้ตัวเลขชุดเดียวกับการแจ้งเตือน LINE ทุกขั้น (API setupsystem → แทนรุ่นที่นับจากทะเบียนเรา
 * → ตัดรุ่นที่ปิดแจ้งเตือน) คนที่เห็นการ์ดใน LINE กับบน Dashboard จะได้เห็นเลขเดียวกัน
 *
 * ทำไมต้อง cache: API ของ setupsystem คำนวณ stock ทั้งระบบทุกครั้ง ใช้เวลาราว 5 วินาที
 *   Dashboard เปิดกันทั้งวัน ถ้ายิงทุกครั้งจะช้าทั้งหน้าและกดภาระให้ setupsystem
 *   จึงเก็บผลไว้ FG_SHORTAGE_DASH_CACHE_TTL วินาที และหน้า Dashboard โหลดแยกทีหลัง (ไม่บล็อกหน้า)
 *
 * ถ้ารอบนี้ดึงไม่ได้ แต่มีผลเก่าอยู่ → คืนผลเก่าพร้อมบอกว่าเป็นข้อมูลเมื่อไหร่ ดีกว่าการ์ดว่าง
 */

require_once __DIR__ . '/finishgood_shortage_client.php';
require_once __DIR__ . '/finishgood_shortage_filter.php';
require_once __DIR__ . '/finishgood_shortage_registry.php';

/** @var int อายุ cache (วินาที) */
const FG_SHORTAGE_DASH_CACHE_TTL = 600;

/**
 * ไฟล์ cache — อยู่โฟลเดอร์เดียวกับ error log ซึ่งอยู่นอก web root และเขียนได้แน่นอน
 *
 * @return string '' = หาที่เก็บไม่ได้ (ทำงานต่อได้โดยไม่มี cache)
 */
function fg_shortage_dash_cache_file(): string
{
    if (!function_exists('app_error_log_path')) {
        return '';
    }
    $dir = dirname(app_error_log_path());
    return is_dir($dir) && is_writable($dir) ? $dir . '/fg-shortage-dashboard.json' : '';
}

/**
 * อ่าน cache
 *
 * @return array<string,mixed>|null
 */
function fg_shortage_dash_cache_read(): ?array
{
    $file = fg_shortage_dash_cache_file();
    if ($file === '' || !is_file($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    // cache รุ่นเก่าเก็บผลที่คิดยอดทะเบียนไปแล้ว (ไม่มี api_items) — ทิ้งไป ไม่งั้นการ์ดค้างตัวเลขเก่า
    return is_array($data) && isset($data['saved_at'], $data['api_items']) ? $data : null;
}

/**
 * บันทึก cache — การตั้งค่ารายรุ่นเปลี่ยนเมื่อไหร่ ต้องล้างด้วย fg_shortage_dash_cache_clear()
 *
 * @param array<string,mixed> $data
 * @return void
 */
function fg_shortage_dash_cache_write(array $data): void
{
    $file = fg_shortage_dash_cache_file();
    if ($file === '') {
        return;
    }
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * ล้าง cache — เรียกหลังเปลี่ยนรุ่นที่นับจากทะเบียน/รุ่นที่ปิดแจ้งเตือน ให้ Dashboard เห็นผลทันที
 *
 * @return void
 */
function fg_shortage_dash_cache_clear(): void
{
    $file = fg_shortage_dash_cache_file();
    if ($file !== '' && is_file($file)) {
        @unlink($file);
    }
}

/**
 * รายการสินค้าที่ต้องผลิตเพิ่ม
 *
 * cache เฉพาะผลจาก API ของ setupsystem (ช้า ~5 วินาที) — ยอดจากทะเบียนเราคิดใหม่ทุกครั้ง
 * เพราะเป็น query ในฐานเราเองที่เร็ว และสถานะเครื่องเปลี่ยนตลอด (สแกนนับ/ซิงก์/ตัดสถานะ)
 * ถ้า cache ทั้งก้อน การ์ดจะค้างตัวเลขเก่าไม่ตรงกับรายการ S/N ที่นับสด
 *
 * @param bool $force true = ดึง API ใหม่ ไม่ใช้ cache
 * @return array{ok:bool,error:string,items:array<int,array<string,mixed>>,total_shortage:int,skipped:int,registry_error:string,saved_at:int,timestamp_text:string,stale:bool}
 */
function fg_shortage_dashboard_data(bool $force = false): array
{
    static $memo = null;
    if (!$force && $memo !== null) {
        return $memo;
    }

    // ไม่เรียก API สต็อกของ setupsystem แล้ว — ยอดทุกรุ่นมาจากทะเบียนเรา (ขั้นต่ำก็ตั้งที่ระบบเรา)
    // ส่วนที่ยังอ่านจากฝั่ง Setup มีแค่ใบ PO ค้าง ซึ่งเป็น query ตรงในฐานข้อมูล ไม่ผ่าน API
    $registry = fg_shortage_apply_registry([]);
    $filtered = fg_shortage_filter_items($registry['items']);

    $total = 0;
    foreach ($filtered['items'] as $it) {
        $total += (int) ($it['need'] ?? 0);
    }

    $memo = [
        'ok'             => $registry['error'] === '',
        'error'          => (string) $registry['error'],
        'items'          => $filtered['items'],
        'total_shortage' => $total,
        'skipped'        => (int) $filtered['skipped'],
        'registry_error' => (string) $registry['error'],
        'saved_at'       => time(),
        'timestamp_text' => date('d/m/Y H:i'),
        'stale'          => false,
    ];
    return $memo;
}

/**
 * ตัวเลขคลังรายรหัสรุ่น สำหรับแปะบนการ์ด "จำนวนเครื่องแยกตามรุ่น" บน Dashboard
 *
 * รุ่นที่ขาด ใช้ตัวเลขจาก API ของระบบ Setup ตรง ๆ (ชุดเดียวกับที่ส่งเข้าไลน์ ตัวเลขจะได้ไม่ขัดกัน)
 * ส่วนรุ่นที่ไม่ขาด API ไม่ส่งมาเลย (ตัดทิ้งตั้งแต่ต้นทาง) จึงนับเองจากทะเบียนเครื่องของเรา
 * ด้วยสูตรเดียวกัน แล้วบอกที่มาไว้ในแต่ละแถวว่า setup หรือ registry
 *
 * @return array{ok:bool,error:string,updated:string,stale:bool,codes:array<string,array<string,mixed>>}
 */
function fg_model_stock_map(): array
{
    $fg = fg_shortage_dashboard_data();
    // ไม่คำนวณยอดนับของระบบ Setup แล้ว (เคยใช้โชว์เทียบบนการ์ด เอาคอลัมน์ออกไปแล้ว)
    $setupStock = [];
    $ourNew = [];
    $ourPool = [];
    if (function_exists('qr')) {
        $ready = fg_shortage_leasing_ready_serials();
        $res = qr(
            "SELECT UPPER(TRIM(p.product_code)) AS code, UPPER(TRIM(a.asset_code)) AS sn,
                    UPPER(TRIM(COALESCE(a.factory_serial, ''))) AS fs, a.status
             FROM products p JOIN assets a ON a.product_id = p.id
             WHERE p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''
               AND a.status IN ('new', 'rental')"
        );
        while ($r = $res->fetch_assoc()) {
            $code = (string) $r['code'];
            $pool = isset($ready[(string) $r['sn']]) || ((string) $r['fs'] !== '' && isset($ready[(string) $r['fs']]));
            if ($pool) {
                $ourPool[$code] = ($ourPool[$code] ?? 0) + 1;
            } elseif ((string) $r['status'] === 'new') {
                $ourNew[$code] = ($ourNew[$code] ?? 0) + 1;
            }
        }
    }
    $sources = static function (string $code) use ($setupStock, $ourNew, $ourPool): array {
        return [
            'ours'  => (int) ($ourNew[$code] ?? 0),
            'setup' => isset($setupStock[$code]) ? (int) $setupStock[$code]['qty'] : -1,
            'pool'  => (int) ($ourPool[$code] ?? 0),
            'mode'  => isset($setupStock[$code]) ? (string) $setupStock[$code]['mode'] : '',
        ];
    };
    $out = [
        'ok'      => (bool) $fg['ok'],
        'error'   => (string) $fg['error'],
        'updated' => $fg['saved_at'] > 0 ? date('d/m/Y H:i', (int) $fg['saved_at']) . ' น.' : '',
        'stale'   => (bool) $fg['stale'],
        'codes'   => [],
    ];
    foreach ($fg['items'] as $it) {
        $code = strtoupper(trim((string) $it['product_code']));
        if ($code === '') {
            continue;
        }
        $out['codes'][$code] = [
            'state' => 'short',
            'gap'   => abs((int) $it['need']),
            'have'  => (int) $it['available'],
            'req'   => (int) $it['required'],
            'min'   => (int) $it['minimum_stock'],
            'po'    => (int) $it['po_qty'],
            // แยกของที่ผลิตใหม่ ออกจากเครื่องเช่าที่รับคืนแล้ววนกลับมาปล่อยใหม่
            'new'   => (int) $it['stock_qty'],
            'rent'  => (int) $it['leasing_qty'],
            'src'   => (($it['stock_source'] ?? '') === 'production_registry') ? 'registry' : 'setup',
        ] + $sources($code);
    }

    $all = fg_shortage_registry_rows(null);
    if (!empty($all['ok'])) {
        foreach ($all['rows'] as $code => $row) {
            $code = strtoupper(trim((string) $code));
            if ($code === '' || isset($out['codes'][$code])) {
                continue;
            }
            $need = (int) $row['need'];
            $out['codes'][$code] = [
                // ไม่อยู่ในรายการขาดแต่นับแล้วติดลบ = รุ่นที่ตั้งปิดแจ้งเตือนไว้
                'state' => $need >= 0 ? 'over' : 'muted',
                'over'  => max(0, $need),
                'gap'   => max(0, -$need),
                'have'  => (int) $row['available'],
                'req'   => (int) $row['required'],
                'min'   => (int) $row['minimum_stock'],
                'po'    => (int) $row['po_qty'],
                'new'   => (int) $row['stock_qty'],
                'rent'  => (int) $row['leasing_qty'],
                'src'   => 'registry',
            ] + $sources($code);
        }
    } elseif ($out['error'] === '') {
        $out['error'] = (string) $all['error'];
    }

    return $out;
}

/**
 * ไฟล์จำว่าเราเห็นใบสั่งงานใบไหนหายไปจากระบบ Setup ครั้งแรกเมื่อไหร่
 *
 * setupsystem ลบใบสั่งงานโดยไม่เก็บ log และลบข้ามฐานไม่ได้ (รายการสินค้าอยู่ biton_stockparts)
 * จึงไม่มีทางรู้เวลาลบจริง — เก็บเวลาที่ "ตรวจพบว่าใบหาย" ไว้เองแทน จะได้มีข้อมูลอ้างอิง
 *
 * @return string เส้นทางไฟล์ (ว่าง = ยังหาที่เก็บไม่ได้)
 */
function fg_shortage_orphan_seen_file(): string
{
    if (!function_exists('app_error_log_path')) {
        return '';
    }
    return dirname(app_error_log_path()) . '/fg-po-orphans.json';
}

/**
 * บันทึก/อ่านเวลาที่พบว่าใบสั่งงานหายไป
 *
 * ใบที่หายไปตั้งแต่ก่อนเราเริ่มเฝ้า (รุ่นแรกที่เขียนไฟล์นี้) บอกวันที่ลบไม่ได้เลย — first_seen
 * ของใบพวกนั้นคือวันที่เราเริ่มเฝ้า ไม่ใช่วันที่ลบ จึงต้องแยกด้วย reliable ไม่งั้นจะรายงานวันผิด
 *
 * @param  array<int,array<string,mixed>> $orders order_id => ข้อมูลใบที่หาไม่เจอแล้ว
 * @return array<int,array{first_seen:int,last_seen:int,reliable:bool}>
 */
function fg_shortage_orphan_seen_touch(array $orders): array
{
    $file = fg_shortage_orphan_seen_file();
    if ($file === '' || $orders === []) {
        return [];
    }
    $now = time();
    $book = [];
    if (is_file($file)) {
        $raw = json_decode((string) @file_get_contents($file), true);
        if (is_array($raw)) {
            $book = $raw;
        }
    }
    $dirty = false;

    // เวลาที่เริ่มเฝ้า — ไฟล์รุ่นก่อนยังไม่มี meta จึงถอยไปใช้ค่า first_seen ที่เก่าที่สุดที่มีอยู่
    if (!isset($book['__meta']['watch_since'])) {
        $oldest = $now;
        foreach ($book as $k => $v) {
            if ($k !== '__meta' && is_array($v) && (int) ($v['first_seen'] ?? 0) > 0) {
                $oldest = min($oldest, (int) $v['first_seen']);
            }
        }
        $book['__meta'] = ['watch_since' => $oldest];
        $dirty = true;
    }
    $watchSince = (int) $book['__meta']['watch_since'];

    foreach ($orders as $id => $info) {
        $key = (string) (int) $id;
        if (!isset($book[$key]) || !is_array($book[$key])) {
            $book[$key] = ['first_seen' => $now, 'last_seen' => $now];
            $dirty = true;
            continue;
        }
        // เขียนทับ last_seen ไม่บ่อยเกินวันละครั้ง — ไฟล์นี้ถูกอ่านทุกครั้งที่เปิดโมดัล
        if ($now - (int) ($book[$key]['last_seen'] ?? 0) > 86400) {
            $book[$key]['last_seen'] = $now;
            $dirty = true;
        }
    }
    if ($dirty) {
        @file_put_contents($file, json_encode($book, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    $out = [];
    foreach ($orders as $id => $info) {
        $key = (string) (int) $id;
        if (isset($book[$key])) {
            $first = (int) ($book[$key]['first_seen'] ?? 0);
            $out[(int) $id] = [
                'first_seen' => $first,
                'last_seen'  => (int) ($book[$key]['last_seen'] ?? 0),
                // ใบที่หายไประหว่างที่เราเฝ้าอยู่แล้วเท่านั้น ถึงจะบอกช่วงเวลาที่ถูกลบได้
                'reliable'   => $first > $watchSince + 60,
            ];
        }
    }
    return $out;
}

/**
 * รายการสินค้าทั้งใบของใบสั่งงานที่ถูกลบ — ใช้บอกว่าใบนั้นมีอะไรอยู่บ้าง
 *
 * @param  mysqli            $stock
 * @param  array<int,int>    $orderIds
 * @return array<int,array<int,array{name:string,qty:int}>>
 */
function fg_shortage_order_items(\mysqli $stock, array $orderIds): array
{
    $ids = [];
    foreach ($orderIds as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === []) {
        return [];
    }
    $res = $stock->query(
        "SELECT order_id, part_code, part_name, COALESCE(SUM(quantity), 0) AS qty
         FROM po_order_parts
         WHERE order_id IN (" . implode(',', $ids) . ")
         GROUP BY order_id, part_code, part_name
         ORDER BY order_id, part_name"
    );
    $out = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $out[(int) $r['order_id']][] = [
            'name' => trim((string) $r['part_name']) !== ''
                ? trim((string) $r['part_name'])
                : trim((string) $r['part_code']),
            'qty'  => (int) round((float) $r['qty']),
        ];
    }
    return $out;
}

/**
 * ใบสั่งงานที่ทำให้เกิดยอด "PO ค้าง" ของรุ่นหนึ่ง
 *
 * กติกาเดียวกับตอนนับ (ดู finishgood_shortage_registry.php): รายการที่ช่องประเภทการขายยังว่าง
 * = ยังไม่ได้ส่งของ และใบสั่งงานไม่ได้ถูกยกเลิก
 *
 * ใบที่ถูกลบไปแล้วแต่รายการสินค้ายังค้าง (setupsystem ลบเฉพาะหัวใบ คนละฐานกันจึง cascade ไม่ได้)
 * ยังแสดงอยู่ในรายการพร้อมรายละเอียดอ้างอิง แต่ไม่รวมใน total — ของเรานับเฉพาะใบที่ยังมีอยู่จริง
 *
 * @param  string $code product_code
 * @return array{ok:bool,error:string,rows:array<int,array<string,mixed>>,total:int,orphan_qty:int,orphan_count:int}
 */
function fg_shortage_open_pos(string $code): array
{
    $out = ['ok' => true, 'error' => '', 'rows' => [], 'total' => 0, 'orphan_qty' => 0, 'orphan_count' => 0];
    $fail = function (string $msg) {
        return ['ok' => false, 'error' => $msg, 'rows' => [], 'total' => 0, 'orphan_qty' => 0, 'orphan_count' => 0];
    };
    $code = strtoupper(trim($code));
    if ($code === '') {
        return $fail('ไม่มีรหัสรุ่น');
    }

    try {
        $stock = dbStock();
        $setup = dbSetup();
        if (!$stock) {
            return $fail('ต่อฐาน stockparts ไม่ได้');
        }
        if (!$setup) {
            return $fail('ต่อฐาน setup ไม่ได้');
        }

        $ids = [];
        $st = $stock->prepare(
            "SELECT id FROM products WHERE is_active = 1 AND UPPER(TRIM(product_code)) = ? ORDER BY id"
        );
        $st->bind_param('s', $code);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_row()) {
            $ids[] = (int) $r[0];
        }
        if ($ids === []) {
            return $out;
        }

        $cancelled = [];
        $res = $setup->query(
            "SELECT id FROM setup_orders WHERE status = '" . $setup->real_escape_string(FG_SHORTAGE_PO_CANCELLED_STATUS) . "'"
        );
        while ($res && ($r = $res->fetch_row())) {
            $cancelled[] = (int) $r[0];
        }

        $sql = "SELECT order_id, COALESCE(SUM(quantity), 0) AS qty, MAX(order_product_type) AS ptype,
                       MAX(created_at) AS created_at, MAX(updated_at) AS updated_at,
                       MAX(order_customer_name) AS order_customer_name
                FROM po_order_parts
                WHERE part_id IN (" . implode(',', $ids) . ")
                  AND order_product_type IS NOT NULL
                  AND (status_product IS NULL OR status_product = '')";
        if ($cancelled !== []) {
            $sql .= " AND order_id NOT IN (" . implode(',', $cancelled) . ")";
        }
        $sql .= " GROUP BY order_id ORDER BY created_at DESC, order_id DESC";
        $res = $stock->query($sql);
        if (!$res) {
            return $fail('อ่านรายการ PO ค้างไม่ได้');
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[(int) $r['order_id']] = [
                'order_id'   => (int) $r['order_id'],
                'qty'        => (int) round((float) $r['qty']),
                'ptype'      => (string) $r['ptype'],
                'created_at' => (string) $r['created_at'],
                'updated_at' => (string) $r['updated_at'],
                'po_number'  => '',
                'customer'   => trim((string) $r['order_customer_name']),
                'sale_type'  => '',
                'status'     => '',
                'po_date'    => '',
                'found'      => false,
                'orphan'      => true,
                'items'       => [],
                'seen_first'  => 0,
                'seen_last'   => 0,
                'seen_usable' => false,
            ];
        }
        if ($rows !== []) {
            $res = $setup->query(
                "SELECT id, po_number, customer_name, company_name, sale_type, status, po_date
                 FROM setup_orders WHERE id IN (" . implode(',', array_keys($rows)) . ")"
            );
            while ($res && ($r = $res->fetch_assoc())) {
                $id = (int) $r['id'];
                $rows[$id]['po_number'] = trim((string) $r['po_number']);
                $rows[$id]['customer']  = trim((string) $r['customer_name']) !== ''
                    ? trim((string) $r['customer_name'])
                    : trim((string) $r['company_name']);
                $rows[$id]['sale_type'] = trim((string) $r['sale_type']);
                $rows[$id]['status']    = trim((string) $r['status']);
                $rows[$id]['po_date']   = trim((string) $r['po_date']);
                $rows[$id]['found']     = true;
                $rows[$id]['orphan']    = false;
            }
        }

        // ใบที่หัวใบหายไปแล้ว: ไม่รวมในยอด แต่เก็บรายละเอียดไว้ให้ดูว่าใบนั้นมีอะไร ลูกค้าใคร เห็นว่าหายตั้งแต่เมื่อไหร่
        $orphans = [];
        foreach ($rows as $id => $row) {
            if (!empty($row['orphan'])) {
                $orphans[$id] = $row;
            }
        }
        if ($orphans !== []) {
            $items = fg_shortage_order_items($stock, array_keys($orphans));
            $seen = fg_shortage_orphan_seen_touch($orphans);
            foreach (array_keys($orphans) as $id) {
                $rows[$id]['items']       = $items[$id] ?? [];
                $rows[$id]['seen_first']  = (int) ($seen[$id]['first_seen'] ?? 0);
                $rows[$id]['seen_last']   = (int) ($seen[$id]['last_seen'] ?? 0);
                $rows[$id]['seen_usable'] = !empty($seen[$id]['reliable']);
            }
        }
        foreach ($rows as $row) {
            if (!empty($row['orphan'])) {
                $out['orphan_qty']   += (int) $row['qty'];
                $out['orphan_count']++;
            } else {
                $out['total'] += (int) $row['qty'];
            }
        }
        $out['rows'] = array_values($rows);
    } catch (\Throwable $e) {
        return $fail('ดึงรายการ PO ไม่สำเร็จ: ' . $e->getMessage());
    }

    return $out;
}

/**
 * ยอดคงคลังที่ระบบ Setup นับได้จริง ของทุกรุ่นในรอบเดียว
 *
 * สำคัญ: หมวด "อุปกรณ์ผลิตใหม่" (เกือบทุกรุ่น) setupsystem <b>ไม่ได้ใช้</b>เลข quantity ใน
 * finishgood_stock_balance เป็นยอดคงคลัง — เลขนั้นคือผลนับด้วยมือครั้งล่าสุดเฉย ๆ บางรุ่นเป็น 0
 * ยอดจริงเขานับจาก serial ในตาราง stock ที่บันทึกหลังวันนับและยังไม่ถูกเบิกออก
 * เอาเลข quantity ไปเทียบตรง ๆ จึงเพี้ยนทั้งกระดาน — ฟังก์ชันนี้คิดแบบเดียวกับเขาให้ครบทุกรุ่น
 *
 * @return array<string,array{qty:int, mode:string, date:string}> product_code => ยอด
 */
function fg_shortage_setup_stock_all(): array
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

    $products = [];
    $index = [];
    $res = $stock->query(
        "SELECT UPPER(TRIM(p.product_code)) AS code, p.product_name, COALESCE(c.category_name, '-') AS cat,
                COALESCE(f.quantity, 0) AS qty, f.last_check_at
         FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id
         LEFT JOIN finishgood_stock_balance f ON f.product_id = p.id
         WHERE p.is_active = 1 AND p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''"
    );
    while ($res && ($r = $res->fetch_assoc())) {
        $code = (string) $r['code'];
        $raw = (string) $r['last_check_at'];
        $raw = ($raw !== '' && substr($raw, 0, 10) !== '0000-00-00') ? $raw : '';
        $date = $raw !== '' ? substr($raw, 0, 10) : '';
        $serialMode = ((string) $r['cat'] === FG_SHORTAGE_SERIAL_CATEGORY);
        $products[$code] = [
            'name'  => (string) $r['product_name'],
            'date'  => $date,
            'ts'    => $raw !== '' ? strtotime($raw) : null,
            'qty'   => $serialMode ? 0 : (int) round((float) $r['qty']),
            'mode'  => $serialMode ? 'serial' : 'manual',
        ];
        foreach ([(string) $r['product_name'], $code] as $k) {
            $k = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $k)), 'UTF-8');
            if ($k !== '' && !isset($index[$k])) {
                $index[$k] = $code;
            }
        }
    }
    if (!$products) {
        return $cache;
    }

    // serial ที่ถูกเบิกออกไปแล้ว — ตัดออกเหมือนที่ setupsystem ทำ
    $issued = [];
    $setup = dbSetup();
    if ($setup) {
        $res = $setup->query(
            "SELECT DISTINCT UPPER(TRIM(serial_number)) AS sn FROM po_order_part_serials
             WHERE serial_number IS NOT NULL AND TRIM(serial_number) <> ''
               AND issue_date IS NOT NULL AND issue_date <> '0000-00-00'"
        );
        while ($res && ($r = $res->fetch_row())) {
            $issued[(string) $r[0]] = true;
        }
    }

    $res = $stock->query('SELECT model, serial_number, timestamp, setup_id, active FROM stock');
    while ($res && ($r = $res->fetch_assoc())) {
        $sn = strtoupper(trim((string) $r['serial_number']));
        if ($sn === '' || isset($issued[$sn])) {
            continue;
        }
        $setupId = $r['setup_id'] === null ? '' : trim((string) $r['setup_id']);
        if ($setupId !== '' && $setupId !== '0') {
            continue;
        }
        if ($r['active'] !== null && (int) $r['active'] !== 1) {
            continue;
        }
        $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $r['model'])), 'UTF-8');
        $code = $index[$key] ?? '';
        if ($code === '') {
            foreach ($products as $c => $_) {
                if (stripos((string) $r['model'], $c) === 0) {
                    $code = $c;
                    break;
                }
            }
        }
        if ($code === '' || $products[$code]['mode'] !== 'serial') {
            continue;
        }
        $ts = (string) ($r['timestamp'] ?? '');
        $tsEmpty = ($ts === '' || $ts === '0000-00-00 00:00:00');
        if (!$tsEmpty && (int) date('Y', strtotime($ts)) < FG_SHORTAGE_SERIAL_YEAR_FROM) {
            continue;
        }
        // เทียบเวลาเต็ม ไม่ใช่แค่วันที่ — ให้ตรงกับที่ setupsystem ตัด (นับสต็อกตอนบ่าย ของที่ลงตอนเช้าไม่นับ)
        $lastTs = $products[$code]['ts'];
        if ($lastTs !== null && ($tsEmpty || strtotime($ts) < $lastTs)) {
            continue;
        }
        $products[$code]['qty']++;
    }

    foreach ($products as $code => $p) {
        $cache[$code] = ['qty' => (int) $p['qty'], 'mode' => (string) $p['mode'], 'date' => (string) $p['date']];
    }
    return $cache;
}

// ─ หมายเลขสินค้าที่อยู่ในสต็อก (กดการ์ดรายรุ่นบน Dashboard) ─────────────────────

/** @var string หมวดสินค้าที่ setupsystem นับยอดจาก serial ในตาราง stock (หมวดอื่นใช้ยอดนับมือ) */
const FG_SHORTAGE_SERIAL_CATEGORY = 'อุปกรณ์ผลิตใหม่';

/**
 * @var int ปีเริ่มนับ serial — ค่าที่ API ของ setupsystem ใช้จริง
 *
 * api/finishgood_shortage.php โหลด finishgood_stock.php (ไม่ใช่ api/finishgood_stock.php ที่ตั้งไว้ 2026)
 * ถ้า setupsystem เปลี่ยนค่านี้ รายการหมายเลขจะนับไม่ตรงกับตัวเลข "มี" — หน้ารายการจะแจ้งเมื่อจำนวนไม่ตรง
 */
const FG_SHORTAGE_SERIAL_YEAR_FROM = 2025;

/**
 * ชื่อรุ่นใน stockparts → ชื่อใน biton_leasing.tbl_product (alias ชุดเดียวกับของ setupsystem)
 *
 * @param string $name
 * @return string
 */
function fg_shortage_leasing_name(string $name): string
{
    $aliases = [
        'portble printer'     => 'Portable Printer',
        'smart card reader s' => 'Smart Card S',
    ];
    $key = mb_strtolower(trim($name), 'UTF-8');
    return $aliases[$key] ?? trim($name);
}

/**
 * serial ในตาราง stock ตรงกับรุ่นนี้ไหม — พอร์ตจาก isStockModelMatchProduct() ของ setupsystem
 *
 * @param string $model
 * @param string $productName
 * @param string $productCode
 * @return bool
 */
function fg_shortage_model_matches(string $model, string $productName, string $productCode): bool
{
    $norm = static function (string $v): string {
        $v = trim($v);
        return $v === '' ? '' : (string) preg_replace('/\s+/u', ' ', $v);
    };
    $model = $norm($model);
    if ($model === '') {
        return false;
    }
    $candidates = [];
    foreach ([$productName, $productCode] as $v) {
        $v = $norm($v);
        if ($v !== '') {
            $candidates[] = $v;
            $candidates[] = strtolower($v);
        }
    }
    if (in_array($model, $candidates, true) || in_array(strtolower($model), $candidates, true)) {
        return true;
    }
    $code = $norm($productCode);
    return $code !== '' && stripos($model, $code) === 0;
}

/**
 * หมายเลขสินค้าที่ประกอบเป็นตัวเลข "มี" ของรุ่นหนึ่ง
 *
 * ต้องนับด้วยกติกาเดียวกับที่มาของตัวเลข ไม่งั้นจำนวนในรายการจะไม่ตรงกับการ์ด:
 *   - รุ่นที่นับจากทะเบียนเรา → เครื่องสถานะ new
 *   - รุ่นอื่น → serial ในตาราง stock ตามกติกา setupsystem (ยังไม่ถูกเบิก · ไม่ผูกงานติดตั้ง · active
 *     · บันทึกตั้งแต่ปีเริ่มนับ · หลังวันนับมือล่าสุด) + เครื่องเช่าพร้อมเช่าจากระบบเช่า
 *   - หมวดที่ setupsystem ใช้ยอดนับมือ → ไม่มีหมายเลขให้แสดง
 *
 * @param array<string,mixed> $item แถวจาก fg_shortage_dashboard_data()
 * @return array{ok:bool,error:string,mode:string,stock:array<int,array<string,mixed>>,leasing:array<int,array<string,mixed>>,manual_qty:int,last_check_at:string}
 */
function fg_shortage_serials(array $item): array
{
    $out = ['ok' => true, 'error' => '', 'mode' => 'serial', 'stock' => [], 'leasing' => [], 'manual_qty' => 0, 'last_check_at' => ''];
    $fail = static function (string $error) use ($out): array {
        $out['ok'] = false;
        $out['error'] = $error;
        return $out;
    };
    $code = strtoupper(trim((string) ($item['product_code'] ?? '')));
    if ($code === '') {
        return $fail('ไม่มีรหัสรุ่น');
    }

    try {
        if (($item['stock_source'] ?? '') === 'production_registry') {
            $out['mode'] = 'registry';
            // stock   = เครื่องใหม่ที่ไม่ได้ลงทะเบียนในระบบเช่า (ยอดที่ใช้คิดขาด)
            // leasing = คลังพร้อมเช่า (ระบบเช่าเป็น finished goods) — แสดงแยก ไม่นับรวมในยอดขาด
            //           ชุดเดียวกับคอลัมน์ "คลังพร้อมเช่า" บนการ์ด (fg_model_stock_map → pool)
            $ready = fg_shortage_leasing_ready_serials();
            $inLease = fg_shortage_leasing_all_serials();
            $res = qr(
                "SELECT a.id, a.asset_code, a.factory_serial, a.produced_at, a.status
                 FROM assets a JOIN products p ON p.id = a.product_id
                 WHERE UPPER(TRIM(p.product_code)) = ? AND a.status IN ('new', 'rental')
                 ORDER BY a.produced_at DESC, a.asset_code DESC",
                's',
                [$code]
            );
            while ($r = $res->fetch_assoc()) {
                $sn = strtoupper(trim((string) $r['asset_code']));
                $fs = strtoupper(trim((string) $r['factory_serial']));
                $row = ['sn' => (string) $r['asset_code'], 'date' => (string) $r['produced_at'], 'asset_id' => (int) $r['id']];
                if (isset($ready[$sn]) || ($fs !== '' && isset($ready[$fs]))) {
                    $out['leasing'][] = $row;
                } elseif ((string) $r['status'] === 'new' && !isset($inLease[$sn]) && ($fs === '' || !isset($inLease[$fs]))) {
                    $out['stock'][] = $row;
                }
            }
            return $out;
        }

        $stock = dbStock();
        if (!$stock) {
            return $fail('ต่อฐาน stockparts ไม่ได้');
        }
        $st = $stock->prepare(
            "SELECT p.product_code, p.product_name, c.category_name, f.last_check_at, COALESCE(f.quantity, 0) AS qty
             FROM products p
             LEFT JOIN product_categories c ON c.id = p.category_id
             LEFT JOIN finishgood_stock_balance f ON f.product_id = p.id
             WHERE p.is_active = 1 AND UPPER(TRIM(p.product_code)) = ?
             ORDER BY p.id LIMIT 1"
        );
        $st->bind_param('s', $code);
        $st->execute();
        $product = $st->get_result()->fetch_assoc();
        if (!$product) {
            return $fail('ไม่พบรุ่นนี้ในระบบ Setup');
        }
        $name = (string) $product['product_name'];
        $lastCheck = trim((string) ($product['last_check_at'] ?? ''));
        $out['last_check_at'] = $lastCheck;

        if ((string) $product['category_name'] === FG_SHORTAGE_SERIAL_CATEGORY) {
            $setup = dbSetup();
            if (!$setup) {
                return $fail('ต่อฐาน setup ไม่ได้ (ต้องใช้ตัด serial ที่เบิกออกไปแล้ว)');
            }
            $issued = [];
            $res = $setup->query(
                "SELECT DISTINCT TRIM(serial_number) FROM po_order_part_serials
                 WHERE serial_number IS NOT NULL AND serial_number <> ''
                   AND issue_date IS NOT NULL AND issue_date <> '0000-00-00'"
            );
            while ($res && ($r = $res->fetch_row())) {
                $issued[(string) $r[0]] = true;
            }

            $lastCheckTs = $lastCheck !== '' ? strtotime($lastCheck) : null;
            $res = $stock->query(
                "SELECT model, serial_number, timestamp, setup_id, active FROM stock
                 WHERE serial_number IS NOT NULL AND serial_number <> ''"
            );
            while ($res && ($r = $res->fetch_assoc())) {
                $sn = trim((string) $r['serial_number']);
                if ($sn === '' || isset($issued[$sn])) {
                    continue;
                }
                $setupId = $r['setup_id'] === null ? '' : trim((string) $r['setup_id']);
                if ($setupId !== '' && $setupId !== '0') {
                    continue;
                }
                $ts = (string) ($r['timestamp'] ?? '');
                $tsEmpty = $ts === '' || $ts === '0000-00-00 00:00:00';
                if (!$tsEmpty && (int) date('Y', strtotime($ts)) < FG_SHORTAGE_SERIAL_YEAR_FROM) {
                    continue;
                }
                if ($r['active'] !== null && (int) $r['active'] !== 1) {
                    continue;
                }
                if (!fg_shortage_model_matches((string) $r['model'], $name, (string) $product['product_code'])) {
                    continue;
                }
                if ($lastCheckTs !== null && ($tsEmpty || strtotime($ts) < $lastCheckTs)) {
                    continue;
                }
                $out['stock'][] = ['sn' => $sn, 'date' => $tsEmpty ? '' : $ts, 'asset_id' => 0];
            }
            usort($out['stock'], static function ($a, $b) {
                return strcmp($b['date'], $a['date']) ?: strcmp($b['sn'], $a['sn']);
            });
        } else {
            $out['mode'] = 'manual';
            $out['manual_qty'] = (int) round((float) $product['qty']);
        }

        // เครื่องเช่าพร้อมเช่า — setupsystem บวกยอดนี้เข้า "มี" ของทุกรุ่นที่ชื่อตรงกับระบบเช่า
        $lease = dbLeasing();
        if ($lease) {
            $st = $lease->prepare(
                "SELECT TRIM(pro_sn) AS sn, pro_date FROM tbl_product
                 WHERE TRIM(pro_status) = 'finished goods' AND TRIM(pro_name) <> ''
                   AND LOWER(TRIM(pro_name)) = LOWER(?)
                 ORDER BY pro_date DESC, pro_sn DESC"
            );
            $leaseName = fg_shortage_leasing_name($name);
            $st->bind_param('s', $leaseName);
            $st->execute();
            $res = $st->get_result();
            while ($r = $res->fetch_assoc()) {
                $out['leasing'][] = ['sn' => (string) $r['sn'], 'date' => (string) $r['pro_date'], 'asset_id' => 0];
            }
        } elseif ((int) ($item['leasing_qty'] ?? 0) > 0) {
            $out['error'] = 'ต่อระบบเช่าไม่ได้ — รายการเครื่องเช่าพร้อมเช่าไม่ครบ';
        }

        // จับคู่กับทะเบียนเครื่องของเรา เพื่อให้กดเปิดหน้าประวัติเครื่องได้
        $sns = [];
        foreach (['stock', 'leasing'] as $k) {
            foreach ($out[$k] as $row) {
                $sns[$row['sn']] = true;
            }
        }
        if ($sns !== []) {
            $map = [];
            foreach (array_chunk(array_keys($sns), 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $res = qr(
                    "SELECT id, asset_code, factory_serial FROM assets WHERE asset_code IN ($ph) OR factory_serial IN ($ph)",
                    str_repeat('s', count($chunk) * 2),
                    array_merge($chunk, $chunk)
                );
                while ($r = $res->fetch_assoc()) {
                    foreach ([(string) $r['asset_code'], (string) $r['factory_serial']] as $key) {
                        if ($key !== '' && isset($sns[$key])) {
                            $map[$key] = (int) $r['id'];
                        }
                    }
                }
            }
            foreach (['stock', 'leasing'] as $k) {
                foreach ($out[$k] as $i => $row) {
                    $out[$k][$i]['asset_id'] = $map[$row['sn']] ?? 0;
                }
            }
        }
    } catch (\Throwable $e) {
        return $fail('ดึงหมายเลขสินค้าไม่สำเร็จ: ' . $e->getMessage());
    }

    return $out;
}
