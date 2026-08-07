<?php
/**
 * shared/stock_status.php — สถานะสต็อกอะไหล่ (แหล่งความจริงเดียวของทั้งสองแอป)
 *
 * เดิมตรรกะนี้เขียนซ้ำอยู่ 5 ที่ (การ์ดแดชบอร์ด, แผงรายการล่าง, modal โปรไฟล์อะไหล่,
 * หน้าโปรไฟล์อะไหล่ฝั่ง parts, ตารางอะไหล่) และเพี้ยนกันไปแล้วจริง — แผงรายการล่าง
 * มีระดับ "วิกฤต" แต่การ์ดไม่มี อะไหล่ตัวเดียวกันจึงขึ้นคนละสถานะในหน้าเดียวกัน
 *
 * ห้ามให้ไฟล์นี้แตะฐานข้อมูลหรือ session — รับแค่ตัวเลขสองตัว เพื่อให้เรียกใน loop
 * ได้โดยไม่มีต้นทุน และเทสต์ได้ตรง ๆ
 */

/**
 * ระดับสถานะจากจำนวนคงเหลือเทียบขั้นต่ำ
 *
 * ตรวจจากรุนแรงไปหาปกติ ตัวแรกที่เข้าเงื่อนไขชนะ
 *
 *   out      คงเหลือ 0
 *   critical ไม่เกินครึ่งของขั้นต่ำ — ใช้สูตรเดิมของแผงรายการล่างเป๊ะ ๆ
 *   low      ถึง/ต่ำกว่าขั้นต่ำ (จุดที่ต้องสั่งซื้อ)
 *   near     เพิ่งพ้นขั้นต่ำมาไม่เกิน 25% — เตือนล่วงหน้า ไม่นับเป็นของขาด
 *   ok       นอกเหนือจากนั้น
 *
 * near ใช้ ceil() เพราะถ้าไม่ปัดขึ้น อะไหล่ที่ขั้นต่ำน้อย ๆ จะไม่มีจำนวนเต็มไหน
 * เข้าช่วงเลย (ขั้นต่ำ 1 → ช่วง (1, 1.25] ว่างเปล่า) ซึ่งกระทบอะไหล่ 14 รายการ
 *
 * @param int $qty คงเหลือ
 * @param int $min ขั้นต่ำ (0 = ไม่ได้ตั้ง)
 * @return string out|critical|low|near|ok
 */
function stock_status_key(int $qty, int $min): string
{
    if ($qty <= 0) {
        return 'out';
    }
    if ($min <= 0) {
        return 'ok'; // ไม่ได้ตั้งขั้นต่ำ = ไม่มีเกณฑ์ให้เทียบ อย่าเดาว่าขาด
    }
    if ($qty <= max(1, intdiv($min, 2))) {
        return 'critical';
    }
    if ($qty <= $min) {
        return 'low';
    }
    if ($qty <= (int) ceil($min * 1.25)) {
        return 'near';
    }
    return 'ok';
}

/**
 * ป้ายกำกับ + สี + คลาสข้อความของแต่ละระดับ
 *
 * color เป็น CSS custom property พร้อม fallback (ใช้กับ style="background:...") ได้ตรง
 * tone เป็นชื่อคลาสสำหรับข้อความ/badge
 *
 * @param string $key ผลจาก stock_status_key()
 * @return array{label:string, color:string, tone:string}
 */
function stock_status_meta(string $key): array
{
    static $map = [
        'out'      => ['label' => 'หมดแล้ว',        'color' => 'var(--danger,#dc2626)',  'tone' => 'text-danger'],
        'critical' => ['label' => 'วิกฤต',           'color' => 'var(--danger,#dc2626)',  'tone' => 'text-danger'],
        'low'      => ['label' => 'ควรสั่งเพิ่ม',    'color' => 'var(--warning,#f59e0b)', 'tone' => 'text-warn'],
        'near'     => ['label' => 'ใกล้ถึงขั้นต่ำ',  'color' => 'var(--near,#ca8a04)',    'tone' => 'text-near'],
        'ok'       => ['label' => 'ปกติ',            'color' => 'var(--success,#16a34a)', 'tone' => 'text-success'],
    ];
    return $map[$key] ?? $map['ok'];
}

/**
 * ระดับนี้นับเป็น "ต้องสั่งซื้อ" หรือไม่ (ตรงกับเงื่อนไข quantity <= min_stock เดิม)
 *
 * near ไม่นับ — เป็นสัญญาณเตือนบนหน้าจอ ไม่ใช่ของขาด ถ้านับรวมจะทำให้ตัวเลข KPI
 * และการแจ้งเตือน LINE เพิ่มขึ้นทั้งที่ไม่มีอะไหล่ตัวไหนต่ำกว่าขั้นต่ำเพิ่มเลย
 *
 * @param string $key
 * @return bool
 */
function stock_status_is_reorder(string $key): bool
{
    return $key === 'out' || $key === 'critical' || $key === 'low';
}

/**
 * ป้ายสถานะพร้อมใช้ — ใช้ htmlspecialchars ตรง ๆ ไม่ผูกกับ h()/e() ของแอปใดแอปหนึ่ง
 *
 * @param int    $qty
 * @param int    $min
 * @param string $class คลาสเพิ่มเติมของ <span> ชั้นนอก
 * @return string HTML
 */
function stock_status_badge_html(int $qty, int $min, string $class = ''): string
{
    $meta = stock_status_meta(stock_status_key($qty, $min));
    $cls = trim($class . ' ' . $meta['tone']);
    return '<span class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') . '</span>';
}
