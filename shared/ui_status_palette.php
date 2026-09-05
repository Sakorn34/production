<?php
/**
 * shared/ui_status_palette.php — สีและชื่อสถานะเครื่อง แหล่งความจริงเดียว
 * ────────────────────────────────────────────────────────────────────────────────
 * เดิมสถานะเดียวมีสองชุดสีในหน้าเดียวกัน: กราฟและแถบสัดส่วนใช้พาสเทลจาก
 * dash_chart_color() ส่วน badge ในตารางใช้คลาส .st-* อีกชุด คนอ่านจึงจำสีของ
 * "เช่า" ได้สองสี และพาสเทลบางตัวคอนทราสต์ต่ำเกินกว่าจะวางตัวอักษรทับได้
 *
 * ทุกคู่ bg/fg ผ่าน WCAG AA (≥ 4.5:1) — เกณฑ์นี้ใช้กับ "สีที่มีตัวอักษรวางทับ" เท่านั้น
 * แก้สีสถานะที่ไฟล์นี้ที่เดียว ทั้ง badge กราฟ และแถบสัดส่วนจะตามไปเอง
 *
 * bar ตั้งใจใช้พาสเทล ไม่ใช่สีทึบเข้ม ๆ แม้จะเป็น hue เดียวกับ fg:
 * แท่งกราฟบนแดชบอร์ดเป็นพื้นที่สีใหญ่และไม่มีตัวอักษรวางทับ (ตัวเลขอยู่เหนือแท่ง
 * ป้าย legend อยู่ข้างสี) ใช้สีทึบแล้วหน้าจอหนักและอ่านยากกว่าเดิม — เคยลองมาแล้ว
 * ความสม่ำเสมอที่ต้องการคือ "สถานะเดียว = โทนสีเดียว" ซึ่งพาสเทลคู่กับ fg เข้มก็ได้อยู่
 *
 * ป้ายชื่อมีสองชุดโดยตั้งใจ:
 *   th   — ชื่อเต็ม ใช้ในตารางและ badge ("เครื่องเช่า")
 *   chip — ชื่อสั้น ใช้ในกราฟที่ที่แคบ ("เช่า")
 * ────────────────────────────────────────────────────────────────────────────────
 */

/**
 * @return array<string,array{th:string,chip:string,bg:string,fg:string,bar:string}>
 */
function status_palette(): array
{
    return [
        'new'     => ['th' => 'เครื่องใหม่',  'chip' => 'ใหม่',       'bg' => '#d1fae5', 'fg' => '#065f46', 'bar' => '#6ee7b7'],
        'rental'  => ['th' => 'เครื่องเช่า',  'chip' => 'เช่า',       'bg' => '#dbeafe', 'fg' => '#1e40af', 'bar' => '#93c5fd'],
        'spare'   => ['th' => 'เครื่องสำรอง', 'chip' => 'สำรอง',      'bg' => '#fef3c7', 'fg' => '#854d0e', 'bar' => '#fde68a'],
        'sold'    => ['th' => 'ขายแล้ว',      'chip' => 'ขายแล้ว',    'bg' => '#ffedd5', 'fg' => '#9a3412', 'bar' => '#fdba74'],
        'retired' => ['th' => 'เสื่อมสภาพ',   'chip' => 'เสื่อมสภาพ', 'bg' => '#e8e9ee', 'fg' => '#414a5c', 'bar' => '#cbd5e1'],
        'lost'    => ['th' => 'สูญหาย',       'chip' => 'สูญหาย',     'bg' => '#fee2e2', 'fg' => '#991b1b', 'bar' => '#fca5a5'],
    ];
}

/**
 * ข้อมูลสถานะหนึ่งตัว — คีย์ที่ไม่รู้จักคืนโทนเทากลาง ๆ ไม่ใช่ค่าว่าง
 * เพื่อให้หน้าเว็บไม่พังเมื่อฐานข้อมูลมีค่าที่โค้ดยังไม่รู้จัก
 *
 * @param  string $st
 * @return array{th:string,chip:string,bg:string,fg:string,bar:string}
 */
function status_palette_entry(string $st): array
{
    $p = status_palette();
    return isset($p[$st])
        ? $p[$st]
        : ['th' => $st, 'chip' => $st, 'bg' => '#e8e9ee', 'fg' => '#414a5c', 'bar' => '#64748b'];
}

/**
 * สีทึบสำหรับกราฟ/แถบสัดส่วนของสถานะหนึ่ง
 *
 * @param  string $st
 * @return string
 */
function status_bar_color(string $st): string
{
    $e = status_palette_entry($st);
    return $e['bar'];
}

/**
 * style ของ badge สถานะ — คืนเป็น inline เพราะสีมาจาก palette ไม่ใช่จากคลาส CSS
 *
 * @param  string $st
 * @return string
 */
function status_badge_style(string $st): string
{
    $e = status_palette_entry($st);
    return 'background:' . $e['bg'] . ';color:' . $e['fg'];
}
