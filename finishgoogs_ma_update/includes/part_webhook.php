<?php
/**
 * includes/part_webhook.php — Webhook เบิกอะไหล่ตาม config รุ่นสินค้า
 *
 * ส่ง POST JSON หลังบันทึก part_movements (ผลิตใหม่ / เบิกใช้ที่ผูกเครื่อง)
 * รองรับ placeholder ใน body template: {{product_code}}, {{quantity}}, {{purpose}},
 * {{device}}, {{serial_number}}, {{User}}
 */

/** รหัสยืนยัน admin สำหรับส่ง webhook ซ้ำ */
define('PART_WEBHOOK_ADMIN_PIN', '9981');

/**
 * ตรวจและเพิ่มคอลัมน์ webhook ใน products / part_movements
 *
 * @return void
 */
function ensure_webhook_schema() {
    static $done = false;
    if ($done) return;
    $done = true;

    $pHave = [];
    $res = db()->query("SHOW COLUMNS FROM products");
    if ($res) {
        while ($c = $res->fetch_assoc()) $pHave[$c['Field']] = true;
    }
    if (!isset($pHave['webhook_url'])) {
        try {
            db()->query("ALTER TABLE products ADD COLUMN webhook_url VARCHAR(500) NULL DEFAULT NULL AFTER icon_path");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_webhook_schema products.url] ' . $e->getMessage());
        }
    }
    if (!isset($pHave['webhook_body'])) {
        try {
            db()->query("ALTER TABLE products ADD COLUMN webhook_body TEXT NULL DEFAULT NULL AFTER webhook_url");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_webhook_schema products.body] ' . $e->getMessage());
        }
    }

    $mHave = [];
    $res2 = db()->query("SHOW COLUMNS FROM part_movements");
    if ($res2) {
        while ($c = $res2->fetch_assoc()) $mHave[$c['Field']] = true;
    }
    if (!isset($mHave['webhook_sent_at'])) {
        try {
            db()->query("ALTER TABLE part_movements ADD COLUMN webhook_sent_at DATETIME NULL DEFAULT NULL");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_webhook_schema pm.sent_at] ' . $e->getMessage());
        }
    }
    if (!isset($mHave['webhook_ok'])) {
        try {
            db()->query("ALTER TABLE part_movements ADD COLUMN webhook_ok TINYINT(1) NOT NULL DEFAULT 0");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_webhook_schema pm.ok] ' . $e->getMessage());
        }
    }
    if (!isset($mHave['webhook_error'])) {
        try {
            db()->query("ALTER TABLE part_movements ADD COLUMN webhook_error VARCHAR(500) NULL DEFAULT NULL");
        } catch (\mysqli_sql_exception $e) {
            error_log('[ensure_webhook_schema pm.error] ' . $e->getMessage());
        }
    }
}

/**
 * Body template เริ่มต้นสำหรับ webhook เบิกอะไหล่
 *
 * @return string
 */
function part_webhook_default_body() {
    return "{\n"
        . '  "product_code": "{{product_code}}",' . "\n"
        . '  "quantity": "{{quantity}}",' . "\n"
        . '  "purpose": "{{purpose}}",' . "\n"
        . '  "device": "{{device}}",' . "\n"
        . '  "serial_number": "{{serial_number}}",' . "\n"
        . '  "User": "{{User}}"' . "\n"
        . '}';
}

/**
 * แทนที่ placeholder ใน template
 *
 * @param string               $tpl
 * @param array<string,string> $vars
 * @return string
 */
function part_webhook_render_body($tpl, array $vars) {
    $out = $tpl;
    foreach ($vars as $k => $v) {
        $out = str_replace('{{' . $k . '}}', $v, $out);
    }
    return $out;
}

/**
 * สร้างค่าตัวอย่าง placeholder สำหรับ preview webhook ตามรุ่นที่กำลังตั้งค่า
 *
 * ดึงจาก BOM ชิ้นแรก, เครื่องล่าสุดของรุ่น, และผู้ใช้ปัจจุบัน
 *
 * @param int $productId รหัสรุ่น products.id
 * @return array<string,string>
 */
function part_webhook_sample_vars($productId) {
    $productId = (int)$productId;
    $p = qr("SELECT name, product_code FROM products WHERE id=?", 'i', [$productId])->fetch_assoc();
    $device = $p ? (string)$p['name'] : 'รุ่นตัวอย่าง';

    $part = qr("SELECT pt.part_code, pt.name, bi.qty_per_unit
                FROM bom_items bi
                JOIN parts pt ON pt.id = bi.part_id
                WHERE bi.product_id=?
                ORDER BY bi.id ASC
                LIMIT 1", 'i', [$productId])->fetch_assoc();
    $partCode = 'PART-001';
    $qty = '1';
    if ($part) {
        $partCode = trim((string)($part['part_code'] ?: $part['name']));
        if ($partCode === '') $partCode = 'PART-001';
        $qty = rtrim(rtrim(number_format((float)$part['qty_per_unit'], 2), '0'), '.');
        if ($qty === '') $qty = '1';
    }

    $asset = qr("SELECT asset_code FROM assets WHERE product_id=? ORDER BY id DESC LIMIT 1", 'i', [$productId])->fetch_assoc();
    $serial = $asset ? (string)$asset['asset_code'] : 'BS00000001';

    $user = function_exists('actor_name') ? trim((string)actor_name()) : '';
    if ($user === '') $user = 'Admin';

    return [
        'product_code'  => $partCode,
        'quantity'      => $qty,
        'purpose'       => 'ผลิต',
        'device'        => $device,
        'serial_number' => $serial,
        'User'          => $user,
    ];
}

/**
 * สร้าง body ตัวอย่างหลังแทน placeholder สำหรับแสดงใน settings
 *
 * @param int         $productId รหัสรุ่น
 * @param string|null $template  template JSON (null = default)
 * @return string
 */
function part_webhook_preview_for_product($productId, $template = null) {
    $tpl = trim((string)$template);
    if ($tpl === '') $tpl = part_webhook_default_body();
    return part_webhook_render_body($tpl, part_webhook_sample_vars($productId));
}

/**
 * กำหนด purpose จาก mode/remark ของการเบิก
 *
 * @param string|null $mode
 * @param string|null $remark
 * @return string
 */
function part_webhook_purpose($mode, $remark) {
    $mode = trim((string)$mode);
    $remark = trim((string)$remark);
    if (mb_stripos($mode, 'อัตโนมัติ') !== false || mb_stripos($mode, 'ผลิต') !== false) {
        return 'ผลิต';
    }
    if (mb_stripos($remark, 'ma') !== false || mb_stripos($remark, 'MA') !== false
        || mb_stripos($mode, 'ma') !== false) {
        return 'MA';
    }
    return 'เบิกใช้';
}

/**
 * ส่ง webhook สำหรับรายการเบิกอะไหล่
 *
 * @param int  $movementId  PK part_movements
 * @param bool $force       true = ส่งซ้ำแม้เคยสำเร็จ
 * @return array{ok:bool,skipped?:bool,error?:string}
 */
function part_webhook_send($movementId, $force = false) {
    ensure_webhook_schema();
    $movementId = (int)$movementId;
    if ($movementId <= 0) {
        return ['ok' => false, 'error' => 'ไม่พบรายการ'];
    }

    $row = qr("SELECT pm.*, pt.part_code, pt.name part_name, pt.unit,
                      a.asset_code, a.product_id,
                      pr.name product_name, pr.product_code prd_code, pr.webhook_url, pr.webhook_body
               FROM part_movements pm
               JOIN parts pt ON pt.id = pm.part_id
               LEFT JOIN assets a ON a.id = pm.ref_asset_id
               LEFT JOIN products pr ON pr.id = a.product_id
               WHERE pm.id=? AND pm.direction='out'", 'i', [$movementId])->fetch_assoc();
    if (!$row) {
        return ['ok' => false, 'error' => 'ไม่พบรายการเบิก'];
    }

    if (!empty($row['webhook_ok']) && !$force) {
        return ['ok' => true, 'skipped' => true];
    }

    $url = trim((string)($row['webhook_url'] ?? ''));
    if ($url === '') {
        q("UPDATE part_movements SET webhook_ok=0, webhook_error='ไม่ได้ตั้ง URL webhook ของรุ่น', webhook_sent_at=NOW() WHERE id=?",
          'i', [$movementId]);
        return ['ok' => false, 'skipped' => true, 'error' => 'ไม่ได้ตั้ง webhook'];
    }

    if (!preg_match('#^https?://#i', $url)) {
        q("UPDATE part_movements SET webhook_ok=0, webhook_error='URL webhook ไม่ถูกต้อง', webhook_sent_at=NOW() WHERE id=?",
          'i', [$movementId]);
        return ['ok' => false, 'error' => 'URL ไม่ถูกต้อง'];
    }

    $qty = rtrim(rtrim(number_format((float)$row['qty'], 2), '0'), '.');
    $vars = [
        'product_code'  => (string)($row['part_code'] ?: $row['part_name']),
        'quantity'      => $qty,
        'purpose'       => part_webhook_purpose($row['mode'], $row['remark']),
        'device'        => (string)($row['product_name'] ?: ''),
        'serial_number' => (string)($row['asset_code'] ?: ''),
        'User'          => (string)($row['made_by'] ?: actor_name()),
    ];

    $tpl = trim((string)($row['webhook_body'] ?? ''));
    if ($tpl === '') $tpl = part_webhook_default_body();
    $body = part_webhook_render_body($tpl, $vars);

    $err = null;
    $httpCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false) {
            $err = curl_error($ch);
        } elseif ($httpCode < 200 || $httpCode >= 300) {
            $err = 'HTTP ' . $httpCode . ': ' . mb_substr((string)$resp, 0, 200);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) {
            $err = 'ส่ง webhook ไม่สำเร็จ (ไม่มี cURL)';
        }
    }

    if ($err) {
        q("UPDATE part_movements SET webhook_ok=0, webhook_error=?, webhook_sent_at=NOW() WHERE id=?",
          'si', [mb_substr($err, 0, 500), $movementId]);
        return ['ok' => false, 'error' => $err];
    }

    q("UPDATE part_movements SET webhook_ok=1, webhook_error=NULL, webhook_sent_at=NOW() WHERE id=?",
      'i', [$movementId]);
    return ['ok' => true];
}

/**
 * แสดง badge สถานะ webhook
 *
 * @param array<string,mixed> $row แถว part_movements + webhook_*
 * @return string HTML
 */
function part_webhook_status_badge(array $row) {
    if (empty($row['webhook_sent_at'])) {
        return '<span class="badge wh-pending" title="ยังไม่ส่ง webhook">⏳ รอส่ง</span>';
    }
    if (!empty($row['webhook_ok'])) {
        return '<span class="badge wh-ok" title="ส่ง webhook แล้ว">✓ ส่งแล้ว</span>';
    }
    $err = isset($row['webhook_error']) ? (string)$row['webhook_error'] : '';
    return '<span class="badge wh-fail" title="' . h($err) . '">✗ ส่งไม่สำเร็จ</span>';
}
