<?php
/**
 * shared/finishgood_shortage_filter.php — เลือกว่าจะส่งรุ่นไหนในแจ้งเตือน
 * "สินค้าที่ต้องผลิตเพิ่ม"
 *
 * เดิมส่งทุกรุ่นที่ setupsystem บอกว่าขาด ซึ่งบางรุ่นไม่ได้อยากให้เตือน
 * (ของที่สั่งซื้อเอาไม่ได้ผลิตเอง หรือรุ่นที่กำลังเลิกทำ)
 *
 * เก็บเป็นรายการ product_code ที่ "ไม่ส่ง" ไม่ใช่รายการที่ "ส่ง" — รุ่นใหม่ที่
 * setupsystem เพิ่มมาทีหลังจะได้ถูกเตือนเองโดยไม่ต้องมาติ๊กเพิ่ม ซึ่งปลอดภัยกว่า
 * แบบที่ลืมติ๊กแล้วของขาดเงียบ ๆ
 */

/** @var string คีย์ใน site_settings */
const FG_SHORTAGE_SKIP_KEY = 'fg_shortage_skip_codes';
/**
 * @var string รุ่นที่ "ซ่อน" — ของสำเร็จรูปที่แผนกอื่นดูแลสต็อกเอง เราไม่นับ ไม่ติดตาม
 * หายจากตารางรุ่นบน Dashboard · ยอดขาดรวม · การ์ดเช็คสต็อกในไลน์ · แจ้งเตือนสินค้าที่ต้องผลิตเพิ่ม
 * · หน้านับสต็อก — ทะเบียนเครื่อง/ประวัติไม่ถูกแตะ (ต่างจาก SKIP ที่แค่ไม่แจ้งเตือนแต่ยังแสดง)
 */
const FG_SHORTAGE_HIDE_KEY = 'fg_shortage_hide_codes';

/**
 * อ่านรายการรหัสรุ่นจาก site_settings (คั่นด้วย comma)
 *
 * @param string $key
 * @return array<int,string> product_code ตัวพิมพ์ใหญ่
 */
function fg_shortage_code_list(string $key): array
{
    if (!function_exists('setting')) {
        return [];
    }
    $out = [];
    foreach (explode(',', (string) setting($key, '')) as $code) {
        $code = strtoupper(trim($code));
        if ($code !== '') {
            $out[$code] = true;
        }
    }
    return array_keys($out);
}

/**
 * บันทึกรายการรหัสรุ่นลง site_settings
 *
 * @param string            $key
 * @param array<int,string> $codes
 * @return void
 */
function fg_shortage_save_code_list(string $key, array $codes): void
{
    if (!function_exists('set_setting')) {
        return;
    }
    $clean = [];
    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code !== '' && preg_match('/^[A-Z0-9._-]{1,32}$/', $code)) {
            $clean[$code] = true;
        }
    }
    ksort($clean);
    set_setting($key, implode(',', array_keys($clean)));
}

/**
 * รหัสรุ่นที่ซ่อนจากรายการสต็อกทั้งระบบ
 *
 * @return array<int,string>
 */
function fg_shortage_hidden_codes(): array
{
    return fg_shortage_code_list(FG_SHORTAGE_HIDE_KEY);
}

/**
 * บันทึกรหัสรุ่นที่ซ่อน
 *
 * @param array<int,string> $codes
 * @return void
 */
function fg_shortage_save_hidden_codes(array $codes): void
{
    fg_shortage_save_code_list(FG_SHORTAGE_HIDE_KEY, $codes);
}

/**
 * รหัสรุ่นที่ถูกปิดการแจ้งเตือนไว้
 *
 * @return array<int,string> product_code ตัวพิมพ์ใหญ่
 */
function fg_shortage_skipped_codes(): array
{
    if (!function_exists('setting')) {
        return [];
    }
    $raw = trim((string) setting(FG_SHORTAGE_SKIP_KEY, ''));
    if ($raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $code) {
        $code = strtoupper(trim($code));
        if ($code !== '') {
            $out[$code] = true;
        }
    }
    return array_keys($out);
}

/**
 * บันทึกรหัสรุ่นที่ปิดการแจ้งเตือน
 *
 * @param  array<int,string> $codes
 * @return void
 */
function fg_shortage_save_skipped_codes(array $codes): void
{
    if (!function_exists('set_setting')) {
        return;
    }
    $clean = [];
    foreach ($codes as $code) {
        $code = strtoupper(trim((string) $code));
        // กันค่าแปลกปลอมที่ไม่ใช่รูปแบบรหัสสินค้า
        if ($code !== '' && preg_match('/^[A-Z0-9._-]{1,32}$/', $code)) {
            $clean[$code] = true;
        }
    }
    ksort($clean);
    set_setting(FG_SHORTAGE_SKIP_KEY, implode(',', array_keys($clean)));
}

/**
 * รุ่นนี้ถูกปิดแจ้งเตือนไว้ไหม
 *
 * @param  array<string,mixed>  $item
 * @param  array<int,string>    $skipped
 * @return bool
 */
function fg_shortage_is_skipped(array $item, array $skipped): bool
{
    if ($skipped === []) {
        return false;
    }
    $code = strtoupper(trim((string) ($item['product_code'] ?? '')));
    return $code !== '' && in_array($code, $skipped, true);
}

/**
 * กรองรายการก่อนส่งแจ้งเตือน
 *
 * @param  array<int,array<string,mixed>> $items
 * @return array{items:array<int,array<string,mixed>>,skipped:int}
 */
function fg_shortage_filter_items(array $items): array
{
    $skipped = fg_shortage_skipped_codes();
    $hidden = fg_shortage_hidden_codes();
    if ($skipped === [] && $hidden === []) {
        return ['items' => $items, 'skipped' => 0, 'hidden' => 0];
    }
    $keep = [];
    $n = 0;
    $h = 0;
    foreach ($items as $it) {
        // รุ่นที่ซ่อนไม่นับเป็น "ปิดแจ้งเตือน" — ไม่ให้ขึ้นข้อความ "ทุกรุ่นที่ขาดถูกปิดแจ้งเตือน"
        if (fg_shortage_is_skipped($it, $hidden)) {
            $h++;
            continue;
        }
        if (fg_shortage_is_skipped($it, $skipped)) {
            $n++;
            continue;
        }
        $keep[] = $it;
    }
    return ['items' => array_values($keep), 'skipped' => $n, 'hidden' => $h];
}

/**
 * รุ่นที่ "ไม่นับ" สต็อกวนกลับ MA ในยอดขาด (คิดขาดจากเช่าใหม่เท่านั้น)
 * การแสดง New / MA บนตาราง Dashboard ใช้กับทุกรุ่นที่มีข้อมูลแยกยอด
 *
 * @return string[] product_code ตัวพิมพ์ใหญ่
 */
function fg_ma_reuse_display_codes(): array
{
    return ['ACC027'];
}

/**
 * รุ่นนี้ "ไม่นับ" ยอด MA ในสูตรขาดหรือไม่ (เฉพาะรายการใน fg_ma_reuse_display_codes)
 * การแสดง New / MA บนตาราง Dashboard ไม่ได้ถูกจำกัดด้วยฟังก์ชันนี้แล้ว
 *
 * @param string $productCode
 * @return bool
 */
function fg_model_show_ma_reuse_ui(string $productCode): bool
{
    $code = strtoupper(trim($productCode));

    return $code !== '' && in_array($code, fg_ma_reuse_display_codes(), true);
}

/**
 * ยอดที่มีสำหรับคิดขาด/ความเร่งด่วน — รุ่นที่แยก MA ไม่นับสต็อกวนกลับ MA ในคลังเช่า
 *
 * @param int $newQty เครื่องใหม่
 * @param int $rentQty คลังพร้อมเช่ารวม (ใหม่ + MA)
 * @param string $productCode
 * @param int $maReuseQty ยอดวนกลับ MA ในคลัง (รุ่นที่แยก UI)
 * @return array{available:int,gap:int,over:int,state:string}
 */
function fg_model_shortage_from_stock(int $newQty, int $rentQty, int $required, string $productCode, int $maReuseQty = 0): array
{
    if (fg_model_show_ma_reuse_ui($productCode)) {
        $rentForNeed = max(0, max(0, $rentQty) - max(0, $maReuseQty));
    } else {
        $rentForNeed = max(0, $rentQty);
    }
    $available = max(0, $newQty) + $rentForNeed;
    $need = $available - max(0, $required);
    if ($need >= 0) {
        return [
            'available' => $available,
            'gap'       => 0,
            'over'      => $need,
            'state'     => 'over',
        ];
    }

    return [
        'available' => $available,
        'gap'       => abs($need),
        'over'      => 0,
        'state'     => 'short',
    ];
}
