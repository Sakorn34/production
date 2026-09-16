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
 *   ขั้นต่ำและ PO ค้างอ่านจาก biton_stockparts ตามกติกาเดียวกับ setupsystem
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
        // 1) จำนวนเครื่องในทะเบียนเรา แยกตามรหัสรุ่น
        $ours = [];
        $res = qr(
            "SELECT UPPER(TRIM(p.product_code)) AS code, p.name,
                    COALESCE(SUM(a.status = 'new'), 0) AS n_new, COUNT(a.id) AS n_all
             FROM products p
             LEFT JOIN assets a ON a.product_id = p.id
             WHERE p.product_code IS NOT NULL AND TRIM(p.product_code) <> ''
             GROUP BY p.id, p.product_code, p.name"
        );
        while ($r = $res->fetch_assoc()) {
            $code = (string) $r['code'];
            if ($want !== null && !isset($want[$code])) {
                continue;
            }
            if (!isset($ours[$code])) {
                $ours[$code] = ['name' => (string) $r['name'], 'new' => 0, 'all' => 0];
            }
            $ours[$code]['new'] += (int) $r['n_new'];
            $ours[$code]['all'] += (int) $r['n_all'];
        }

        // 2) ขั้นต่ำ — ตารางเดียวกับที่ setupsystem ใช้ (biton_stockparts ไม่ใช่ biton_setup ซึ่งมีค่าคนละชุด)
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
        // 4) แยกเครื่องเช่าที่รับคืนแล้วพร้อมปล่อยใหม่ ออกจากเครื่องที่ผลิตใหม่
        //    ทั้งคู่อยู่ในทะเบียนเราเป็นสถานะ "ใหม่" เหมือนกัน (cron sync ตั้งให้ตอนระบบเช่ารับคืน)
        //    แต่คนละเรื่องกัน — เครื่องวนกลับมาไม่ได้แปลว่าเราผลิตเพิ่มได้ ยอดจึงต้องแยกให้เห็น
        $ready = fg_shortage_leasing_ready_serials();
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
        if (!isset($sp[$code])) {
            if ($want !== null) {
                $missing[] = $code;
            }
            continue;
        }
        $poQty = 0;
        foreach ($sp[$code]['ids'] as $id) {
            $poQty += $po[$id] ?? 0;
        }
        $min = $sp[$code]['min'];
        $required = $min + $poQty;

        // ลิงก์ในการ์ดไปหน้ารายการเครื่องที่อยู่ในคลังจริง — หน้า serial ของ setupsystem จะโชว์เครื่องที่ปล่อยเช่าไปแล้วปนมา
        $url = $base !== '' ? $base . '/assets.php?' . http_build_query(['product' => $o['name'], 'status' => 'new']) : '';
        if ($url !== '' && function_exists('line_notify_sanitize_https_uri')) {
            $url = (string) line_notify_sanitize_https_uri($url);
        }

        // ยอดรวมที่ใช้คิด "ขาด" ยังเท่าเดิม แค่บอกได้ว่าในนั้นเป็นเครื่องเช่าวนกลับกี่เครื่อง
        $rentQty = (int) ($rentReady[$code] ?? 0);
        $newQty = max(0, (int) $o['new'] - $rentQty);

        $rows[$code] = [
            'product_id'     => $sp[$code]['ids'][0],
            'product_code'   => $code,
            'product_name'   => $sp[$code]['name'],
            'registry_name'  => $o['name'],
            'registry_total' => $o['all'],
            'stock_qty'      => $newQty,
            'leasing_qty'    => $rentQty,
            'minimum_stock'  => $min,
            'po_qty'         => $poQty,
            'available'      => $o['new'],
            'required'       => $required,
            'need'           => $o['new'] - $required,
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
 * แทนตัวเลขของ setupsystem ด้วยยอดจากทะเบียนเรา สำหรับรุ่นที่เลือกไว้
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
    $codes = fg_shortage_registry_codes();
    if ($codes === []) {
        return ['items' => $apiItems, 'replaced' => [], 'missing' => [], 'error' => ''];
    }

    $calc = fg_shortage_registry_rows($codes);
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
