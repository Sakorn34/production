<?php
/**
 * shared/finishgood_shortage_registry.php — นับยอดคงเหลือจากทะเบียนเครื่องของ production
 * แทนตัวเลขของ setupsystem สำหรับรุ่นที่เลือกไว้
 *
 * ทำไมต้องมี: setupsystem นับ serial ในตาราง stock ว่า "อยู่ในคลัง" จนกว่าจะถูกเบิกผ่าน PO
 *   แต่ order แบบเช่าไม่ให้ผูก serial จึงไม่มีการตัด stock ตอนปิดงาน — เครื่องที่ปล่อยเช่า
 *   ไปแล้ว (เช่น Portable Printer ที่ส่งไปกับ bitVisitor S) จึงยังถูกนับอยู่ในคลังตลอด
 *   ทะเบียนเครื่องของเรา sync สถานะจากระบบเช่าและการเบิกขายทุกวัน จึงรู้ว่าเครื่องไหนอยู่ในคลังจริง
 *
 * ทำไมไม่ใช้กับทุกรุ่น: รุ่นที่นำเข้าจาก AppSheet มีเครื่องเก่าจำนวนมากที่ไม่เคยถูกปิดสถานะ
 *   (bitScan ขึ้นว่า "ใหม่" หลายร้อยเครื่อง) และรุ่นหมวด STK ไม่มีในทะเบียนเราเลย
 *   จึงให้เลือกเป็นรายรุ่น เฉพาะรุ่นที่สถานะในทะเบียนเชื่อถือได้
 *
 * สูตรเหมือน setupsystem ทุกช่อง ยกเว้นยอดคงเหลือ:
 *   ขาด = เครื่องสถานะ new ในทะเบียนเรา − (ขั้นต่ำ + PO ค้าง)
 *   ขั้นต่ำตั้งที่ระบบเรา (products.min_stock) · PO ค้างอ่านจาก biton_stockparts/biton_setup — ส่วนเดียวที่ยังพึ่ง Setup
 *   ไม่บวกเครื่องเช่าพร้อมเช่าอีก — sync ทำให้เครื่องเหล่านั้นเป็น new อยู่แล้ว บวกซ้ำจะนับสองรอบ
 */

/** @var string คีย์ใน site_settings — product_code ที่นับยอดคงเหลือจากทะเบียนเรา */
const FG_SHORTAGE_REGISTRY_KEY = 'fg_shortage_registry_codes';

/** @var string ค่าก่อนมีใครตั้ง — Portable Printer คือรุ่นที่ยืนยันแล้วว่า setupsystem นับเครื่องที่ปล่อยเช่าเป็นของในคลัง */
const FG_SHORTAGE_REGISTRY_DEFAULT = 'ACC027';

/**
 * @var string ค่าที่เก็บเมื่อผู้ใช้ตั้งใจไม่เลือกรุ่นไหนเลย
 *
 * setting() คืนค่าเริ่มต้นเมื่อค่าว่าง — ถ้าเก็บค่าว่างไว้ ACC027 จะกลับมาเองโดยไม่มีใครเลือก
 */
const FG_SHORTAGE_REGISTRY_NONE = '-';

/** @var string สถานะ order ยกเลิกใน biton_setup — ค่าเดียวกับ PO_CANCELLED_ORDER_STATUS ของ setupsystem */
const FG_SHORTAGE_PO_CANCELLED_STATUS = 'ยกเลิก PO';

/**
 * ทำความสะอาดรายการรหัสรุ่น
 *
 * @param  array<int,mixed> $codes
 * @return array<int,string> ตัวพิมพ์ใหญ่ ไม่ซ้ำ เรียงตามตัวอักษร
 */
function fg_shortage_registry_clean_codes(array $codes): array
{
    $clean = [];
    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code !== '' && preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $code)) {
            $clean[$code] = true;
        }
    }
    ksort($clean);
    return array_keys($clean);
}

/**
 * รหัสรุ่นที่ให้นับยอดคงเหลือจากทะเบียนเรา
 *
 * @return array<int,string>
 */
function fg_shortage_registry_codes(): array
{
    if (!function_exists('setting')) {
        return [];
    }
    $raw = trim((string) setting(FG_SHORTAGE_REGISTRY_KEY, FG_SHORTAGE_REGISTRY_DEFAULT));
    if ($raw === FG_SHORTAGE_REGISTRY_NONE) {
        return [];
    }
    return fg_shortage_registry_clean_codes(explode(',', $raw));
}

/**
 * บันทึกรหัสรุ่นที่ให้นับยอดคงเหลือจากทะเบียนเรา
 *
 * @param  array<int,mixed> $codes
 * @return void
 */
function fg_shortage_save_registry_codes(array $codes): void
{
    if (!function_exists('set_setting')) {
        return;
    }
    $clean = fg_shortage_registry_clean_codes($codes);
    set_setting(FG_SHORTAGE_REGISTRY_KEY, $clean !== [] ? implode(',', $clean) : FG_SHORTAGE_REGISTRY_NONE);
}

/**
 * คอลัมน์ขั้นต่ำของเราเอง (products.min_stock) — เดิมอ่านจาก biton_stockparts ของระบบ Setup
 *
 * ครั้งแรกที่สร้างคอลัมน์ คัดลอกค่าเดิมจาก stockparts มาให้ ตัวเลขบน Dashboard/LINE จะได้ไม่เปลี่ยน
 * หลังจากนั้นแก้ที่ระบบเราอย่างเดียว (หน้าตั้งค่ารุ่น / หน้าตั้งค่าการนับและการแจ้งเตือน)
 * NULL = รุ่นที่ยังไม่เคยตั้ง (ไม่อยู่ในรายการต้องผลิต)
 *
 * @return void
 */
function fg_shortage_ensure_min_schema(): void
{
    static $done = false;
    if ($done || !function_exists('db')) {
        return;
    }
    $done = true;
    try {
        $has = db()->query("SHOW COLUMNS FROM products LIKE 'min_stock'");
        if ($has && $has->num_rows > 0) {
            return;
        }
        db()->query("ALTER TABLE products ADD COLUMN min_stock INT UNSIGNED NULL DEFAULT NULL AFTER category");
        $stock = function_exists('dbStock') ? dbStock() : null;
        if (!$stock) {
            return;
        }
        // ค่าแรกของแต่ละรหัส (เรียงตาม id) — ชุดเดียวกับที่เคยใช้คำนวณ
        $seed = [];
        $res = $stock->query(
            "SELECT UPPER(TRIM(product_code)) AS code, COALESCE(minimum_stock, 0) AS mn
             FROM products WHERE is_active = 1 AND product_code IS NOT NULL AND TRIM(product_code) <> '' ORDER BY id"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            if (!isset($seed[(string) $r['code']])) {
                $seed[(string) $r['code']] = (int) $r['mn'];
            }
        }
        foreach ($seed as $code => $mn) {
            q('UPDATE products SET min_stock = ? WHERE UPPER(TRIM(product_code)) = ? AND min_stock IS NULL', 'is', [max(0, $mn), $code]);
        }
    } catch (\Throwable $e) {
        error_log('[fg_shortage_ensure_min_schema] ' . $e->getMessage());
    }
}

/**
 * บันทึกขั้นต่ำหลายรุ่นพร้อมกัน
 *
 * @param array<string,mixed> $map product_code => จำนวน ('' = ไม่ตั้ง / ไม่ติดตาม)
 * @return int จำนวนรุ่นที่บันทึก
 */
function fg_shortage_save_min_stock(array $map): int
{
    fg_shortage_ensure_min_schema();
    $n = 0;
    foreach ($map as $code => $val) {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $code)) {
            continue;
        }
        $val = trim((string) $val);
        if ($val === '') {
            q('UPDATE products SET min_stock = NULL WHERE UPPER(TRIM(product_code)) = ?', 's', [$code]);
        } else {
            q('UPDATE products SET min_stock = ? WHERE UPPER(TRIM(product_code)) = ?', 'is', [max(0, (int) $val), $code]);
        }
        $n++;
    }
    return $n;
}

/**
 * คำนวณยอดขาดจากทะเบียนเครื่องของเรา
 *
 * จับคู่รุ่นระหว่างสองระบบด้วย product_code · รุ่นที่ไม่มีทั้งสองฝั่งจะไม่ถูกคืนมา
 *
 * @param  array<int,string>|null $codes null = ทุกรุ่นที่จับคู่ได้ (ใช้แสดงตารางเทียบ)
 * @return array{ok:bool,error:string,rows:array<string,array<string,mixed>>,missing:array<int,string>}
 */
function fg_shortage_registry_rows(?array $codes = null): array
{
    $fail = static function (string $error): array {
        return ['ok' => false, 'error' => $error, 'rows' => [], 'missing' => []];
    };

    if (!function_exists('db') || !function_exists('dbStock') || !function_exists('dbSetup') || !function_exists('qr')) {
        return $fail('ไม่ได้โหลด config ของ production');
    }

    $want = $codes === null ? null : array_flip(fg_shortage_registry_clean_codes($codes));
    if ($want === []) {
        return ['ok' => true, 'error' => '', 'rows' => [], 'missing' => []];
    }

    try {
        $stock = dbStock();
        $setup = dbSetup();
    } catch (\Throwable $e) {
        return $fail('ต่อฐาน stockparts ไม่ได้: ' . $e->getMessage());
    }
    if (!$stock) {
        return $fail('ต่อฐาน stockparts ไม่ได้');
    }
    // ไม่รู้ว่า PO ไหนถูกยกเลิก = นับ PO เกินจริงแล้วเตือนผิด — ให้กลับไปใช้ตัวเลขของ setupsystem ดีกว่า
    if (!$setup) {
        return $fail('ต่อฐาน setup ไม่ได้ (ต้องใช้ตัด PO ที่ยกเลิกออก)');
    }

    try {
        // 1) จำนวนเครื่องในทะเบียนเรา + ขั้นต่ำที่ตั้งไว้ในระบบเรา แยกตามรหัสรุ่น
        fg_shortage_ensure_min_schema();
        $ours = [];
        $res = qr(
            "SELECT UPPER(TRIM(p.product_code)) AS code, p.name, p.min_stock,
                    COALESCE(SUM(a.status = 'new'), 0) AS n_new, COUNT(a.id) AS n_all
             FROM products p
             LEFT JOIN assets a ON a.product_id = p.id
             WHERE p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''
             GROUP BY p.id, p.product_code, p.name, p.min_stock"
        );
        while ($r = $res->fetch_assoc()) {
            $code = (string) $r['code'];
            if ($want !== null && !isset($want[$code])) {
                continue;
            }
            if (!isset($ours[$code])) {
                $ours[$code] = ['name' => (string) $r['name'], 'new' => 0, 'all' => 0, 'min' => null];
            }
            $ours[$code]['new'] += (int) $r['n_new'];
            $ours[$code]['all'] += (int) $r['n_all'];
            if ($r['min_stock'] !== null) {
                $ours[$code]['min'] = max((int) $ours[$code]['min'], (int) $r['min_stock']);
            }
        }

        // 2) รหัสสินค้าฝั่ง stockparts — ใช้จับคู่กับรายการใน PO เท่านั้น (ขั้นต่ำไม่อ่านจากที่นี่แล้ว)
        $sp = [];
        $res = $stock->query(
            "SELECT id, UPPER(TRIM(product_code)) AS code, product_name, COALESCE(minimum_stock, 0) AS mn
             FROM products
             WHERE is_active = 1 AND product_code IS NOT NULL AND TRIM(product_code) <> ''
             ORDER BY id"
        );
        if (!$res) {
            return $fail('อ่านรายการสินค้าใน stockparts ไม่ได้');
        }
        while ($r = $res->fetch_assoc()) {
            $code = (string) $r['code'];
            if (!isset($ours[$code])) {
                continue;
            }
            if (!isset($sp[$code])) {
                $sp[$code] = ['ids' => [], 'name' => (string) $r['product_name'], 'min' => (int) $r['mn']];
            }
            $sp[$code]['ids'][] = (int) $r['id'];
        }

        // 3) PO ค้าง — กติกาเดียวกับ setupsystem: status_product ยังว่าง (ปิดงานแล้วระบบจะเขียนประเภทการขายลงไป)
        //    และ order ไม่ได้ถูกยกเลิก
        //
        //    ต่างกันข้อเดียว: ใบสั่งงานที่ถูกลบไปแล้วเราไม่นับ — api/delete_order.php ลบเฉพาะหัวใบใน
        //    biton_setup ส่วนรายการสินค้าอยู่ biton_stockparts จึง cascade ตามไม่ได้ แถวลูกเลยค้างอยู่
        //    ตลอดไปและถูกนับเป็นความต้องการของใบที่ไม่มีอยู่จริง ทำให้ยอด "ต้องผลิตเพิ่ม" บวมเกิน
        $cancelled = [];
        $live = [];
        $res = $setup->query('SELECT id, status FROM setup_orders');
        if (!$res) {
            return $fail('อ่านรายการใบสั่งงานไม่ได้');
        }
        while ($r = $res->fetch_assoc()) {
            $live[] = (int) $r['id'];
            if (trim((string) $r['status']) === FG_SHORTAGE_PO_CANCELLED_STATUS) {
                $cancelled[] = (int) $r['id'];
            }
        }

        $po = [];
        $ids = [];
        foreach ($sp as $row) {
            foreach ($row['ids'] as $id) {
                $ids[] = $id;
            }
        }
        if ($ids !== []) {
            $sql = "SELECT part_id, COALESCE(SUM(quantity), 0) AS q
                    FROM po_order_parts
                    WHERE part_id IN (" . implode(',', $ids) . ")
                      AND order_product_type IS NOT NULL
                      AND (status_product IS NULL OR status_product = '')";
            if ($cancelled !== []) {
                $sql .= " AND order_id NOT IN (" . implode(',', $cancelled) . ")";
            }
            if ($live !== []) {
                $sql .= " AND order_id IN (" . implode(',', $live) . ")";
            }
            $sql .= " GROUP BY part_id";
            $res = $stock->query($sql);
            if (!$res) {
                return $fail('อ่าน PO ค้างไม่ได้');
            }
            while ($r = $res->fetch_assoc()) {
                $po[(int) $r['part_id']] = (int) round((float) $r['q']);
            }
        }
        // 4) เครื่องที่ลงทะเบียนในระบบเช่าแล้วเป็นเครื่องเช่า ไม่ใช่สต็อกเครื่องใหม่
        //    ปกติ sync ตั้งเป็น "เช่า" ให้อยู่แล้ว แต่ระหว่างรอ cron อาจยังค้างเป็น "ใหม่" — ตัดออกตรงนี้กันนับเกิน
        $ready = fg_shortage_leasing_all_serials();
        $rentReady = [];
        if ($ready) {
            $res = qr(
                "SELECT UPPER(TRIM(p.product_code)) AS code, UPPER(TRIM(a.asset_code)) AS sn,
                        UPPER(TRIM(COALESCE(a.factory_serial, ''))) AS fs
                 FROM products p JOIN assets a ON a.product_id = p.id
                 WHERE a.status = 'new' AND p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''"
            );
            while ($r = $res->fetch_assoc()) {
                $code = (string) $r['code'];
                if (!isset($ours[$code])) {
                    continue;
                }
                if (isset($ready[(string) $r['sn']]) || ((string) $r['fs'] !== '' && isset($ready[(string) $r['fs']]))) {
                    $rentReady[$code] = ($rentReady[$code] ?? 0) + 1;
                }
            }
        }
        // 5) คลังพร้อมเช่า (ระบบเช่า finished goods) — นับรวมในยอดที่มี แต่แยกตัวเลขไว้ให้เห็น
        //    ชุดเดียวกับคอลัมน์ "คลังพร้อมเช่า" บนการ์ด Dashboard
        $poolReady = fg_shortage_leasing_ready_serials();
        $poolQty = [];
        if ($poolReady) {
            $res = qr(
                "SELECT UPPER(TRIM(p.product_code)) AS code, UPPER(TRIM(a.asset_code)) AS sn,
                        UPPER(TRIM(COALESCE(a.factory_serial, ''))) AS fs
                 FROM products p JOIN assets a ON a.product_id = p.id
                 WHERE a.status IN ('new', 'rental') AND p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''"
            );
            while ($r = $res->fetch_assoc()) {
                $code = (string) $r['code'];
                if (isset($ours[$code]) && (isset($poolReady[(string) $r['sn']]) || ((string) $r['fs'] !== '' && isset($poolReady[(string) $r['fs']])))) {
                    $poolQty[$code] = ($poolQty[$code] ?? 0) + 1;
                }
            }
        }
    } catch (\Throwable $e) {
        return $fail('คำนวณจากทะเบียนเครื่องไม่สำเร็จ: ' . $e->getMessage());
    }

    $base = function_exists('line_notify_production_base_url') ? rtrim(line_notify_production_base_url(), '/') : '';
    // public_production_url บางที่ตั้งไว้แค่ /production — เติมโฟลเดอร์แอปแบบเดียวกับ work_summary_app_base_url()
    if ($base !== '' && substr($base, -strlen('/finishgoogs_ma_update')) !== '/finishgoogs_ma_update') {
        $base .= '/finishgoogs_ma_update';
    }
    $rows = [];
    $missing = [];
    foreach ($ours as $code => $o) {
        // ติดตามเฉพาะรุ่นที่ตั้งขั้นต่ำไว้ในระบบเรา หรือมีรายการสินค้าฝั่ง PO ให้จับคู่
        if ($o['min'] === null && !isset($sp[$code])) {
            if ($want !== null) {
                $missing[] = $code;
            }
            continue;
        }
        $poQty = 0;
        foreach ((isset($sp[$code]) ? $sp[$code]['ids'] : []) as $id) {
            $poQty += $po[$id] ?? 0;
        }
        $min = (int) $o['min'];
        $required = $min + $poQty;

        // ลิงก์ในการ์ดไปหน้ารายการเครื่องที่อยู่ในคลังจริง — หน้า serial ของ setupsystem จะโชว์เครื่องที่ปล่อยเช่าไปแล้วปนมา
        $url = $base !== '' ? $base . '/assets.php?' . http_build_query(['product' => $o['name'], 'status' => 'new']) : '';
        if ($url !== '' && function_exists('line_notify_sanitize_https_uri')) {
            $url = (string) line_notify_sanitize_https_uri($url);
        }

        // สต็อก = เครื่องใหม่ในทะเบียนเรา ที่ไม่ได้ลงทะเบียนในระบบเช่า
        $leaseStuck = (int) ($rentReady[$code] ?? 0);
        $newQty = max(0, (int) $o['new'] - $leaseStuck);
        // คลังพร้อมเช่าใช้ส่งงานแทนเครื่องผลิตใหม่ได้ — นับรวมในยอดที่มี (ตกลงกันเมื่อ 17 ก.ย. 2026)
        $rentQty = (int) ($poolQty[$code] ?? 0);

        $rows[$code] = [
            'product_id'     => isset($sp[$code]) ? $sp[$code]['ids'][0] : 0,
            'product_code'   => $code,
            'product_name'   => isset($sp[$code]) ? $sp[$code]['name'] : $o['name'],
            'min_set'        => $o['min'] !== null,
            'registry_name'  => $o['name'],
            'registry_total' => $o['all'],
            'stock_qty'      => $newQty,
            'leasing_qty'    => $rentQty,
            'pool_qty'       => (int) ($poolQty[$code] ?? 0),
            'minimum_stock'  => $min,
            'po_qty'         => $poQty,
            'available'      => $newQty + $rentQty,
            'required'       => $required,
            'need'           => $newQty + $rentQty - $required,
            'detail_url'     => $url,
            'stock_source'   => 'production_registry',
        ];
    }
    if ($want !== null) {
        foreach (array_keys($want) as $code) {
            if (!isset($ours[$code]) && !in_array($code, $missing, true)) {
                $missing[] = $code;
            }
        }
    }
    ksort($rows);

    return ['ok' => true, 'error' => '', 'rows' => $rows, 'missing' => $missing];
}

/**
 * S/N ของเครื่องเช่าที่รับคืนเข้าคลังแล้ว รอปล่อยเช่ารอบใหม่
 *
 * เครื่องพวกนี้ผ่าน MA แล้ววนกลับมาใช้ใหม่ ไม่ใช่ของที่เพิ่งผลิต — ทะเบียนเราตั้งเป็น
 * สถานะ "ใหม่" เหมือนกันหมดเพราะ cron sync อ่านจากระบบเช่า จึงต้องมีตัวแยกไว้ตรงนี้
 *
 * ต่อระบบเช่าไม่ได้ = คืนค่าว่าง แล้วยอดจะรวมกันเหมือนเดิม ไม่ทำให้ทั้งหน้าล้ม
 *
 * @return array<string,bool> S/N ตัวใหญ่ => true
 */
function fg_shortage_leasing_ready_serials(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('dbLeasing')) {
        return $cache;
    }
    try {
        $lease = dbLeasing();
    } catch (\Throwable $e) {
        return $cache;
    }
    if (!$lease) {
        return $cache;
    }
    $res = $lease->query(
        "SELECT UPPER(TRIM(pro_sn)) AS sn FROM tbl_product
         WHERE TRIM(pro_status) = 'finished goods' AND pro_sn IS NOT NULL AND TRIM(pro_sn) <> ''"
    );
    while ($res && ($r = $res->fetch_row())) {
        $cache[(string) $r[0]] = true;
    }
    return $cache;
}

/**
 * S/N ทุกเครื่องที่ลงทะเบียนในระบบเช่า (ทุกสถานะ)
 *
 * @return array<string,bool>
 */
function fg_shortage_leasing_all_serials(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    if (!function_exists('dbLeasing')) {
        return $cache;
    }
    try {
        $lease = dbLeasing();
    } catch (\Throwable $e) {
        return $cache;
    }
    if (!$lease) {
        return $cache;
    }
    $res = $lease->query("SELECT UPPER(TRIM(pro_sn)) AS sn FROM tbl_product WHERE pro_sn IS NOT NULL AND TRIM(pro_sn) <> ''");
    while ($res && ($r = $res->fetch_row())) {
        $cache[(string) $r[0]] = true;
    }
    return $cache;
}

/**
 * แทนตัวเลขของ setupsystem ด้วยยอดจากทะเบียนเรา — ทุกรุ่นที่มีในทะเบียน
 *
 * API ของ setupsystem คืนมาเฉพาะรุ่นที่ขาด — รุ่นที่เลือกไว้จึงต้องคำนวณเองทุกครั้ง
 * ไม่ใช่แค่แก้แถวที่ API ส่งมา (Portable Printer ไม่อยู่ในรายการของ API เลยเพราะนับว่าเหลือเยอะ)
 *
 * คำนวณไม่ได้ = คืนรายการของ setupsystem กลับไปตามเดิม พร้อม error ให้แสดง
 *
 * @param  array<int,array<string,mixed>> $apiItems รายการรุ่นที่ขาดจาก setupsystem
 * @return array{items:array<int,array<string,mixed>>,replaced:array<string,array{api:?array,ours:array}>,missing:array<int,string>,error:string}
 */
function fg_shortage_apply_registry(array $apiItems): array
{
    // ใช้ยอดทะเบียนเราทั้งหมด — ไม่เรียก API สต็อกของ setupsystem แล้ว ($apiItems ส่งมาว่าง)
    // รายการที่ส่งมา (ถ้ามี) และไม่มีในทะเบียนเรายังผ่านไปตามเดิม
    $calc = fg_shortage_registry_rows(null);
    if (!$calc['ok']) {
        return ['items' => $apiItems, 'replaced' => [], 'missing' => [], 'error' => $calc['error']];
    }

    $rows = $calc['rows'];
    $out = [];
    $replaced = [];
    foreach ($apiItems as $item) {
        $code = strtoupper(trim((string) ($item['product_code'] ?? '')));
        if (isset($rows[$code])) {
            $replaced[$code] = ['api' => $item, 'ours' => $rows[$code]];
            continue;
        }
        $out[] = $item;
    }
    foreach ($rows as $code => $row) {
        if (!isset($replaced[$code])) {
            $replaced[$code] = ['api' => null, 'ours' => $row];
        }
        if ((int) $row['need'] < 0) {
            $out[] = $row;
        }
    }

    // เรียงจากขาดมากไปน้อยแบบเดียวกับ setupsystem — รุ่นวิกฤตที่สุดอยู่การ์ดแรก
    usort($out, static function ($a, $b) {
        return (int) $a['need'] - (int) $b['need'];
    });

    return ['items' => $out, 'replaced' => $replaced, 'missing' => $calc['missing'], 'error' => ''];
}
