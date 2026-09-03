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
    if ($skipped === []) {
        return ['items' => $items, 'skipped' => 0];
    }
    $keep = [];
    $n = 0;
    foreach ($items as $it) {
        if (fg_shortage_is_skipped($it, $skipped)) {
            $n++;
            continue;
        }
        $keep[] = $it;
    }
    return ['items' => array_values($keep), 'skipped' => $n];
}
