<?php
/**
 * includes/part_list_ui.php — ปุ่มดูรูป + ลิงก์โปรไฟล์อะไหล่ ใช้ร่วมทุกรายการ
 *
 * กติกาเดียวกันทั้งระบบทะเบียนเครื่อง:
 *   กดรูป = popup ขนาดเต็ม (#img-preview-overlay ใน layout.php จับ .ls-photo-btn)
 *   กดชื่อ = แท็บใหม่ไป parts/pages/product-detail.php
 *
 * ตัวอย่าง: echo part_list_photo_btn($path, $name, 'thumb') . part_list_name_link($name, $appId);
 */

require_once __DIR__ . '/part_stock_bridge.php';

// ────────────────────────────────────────────────────────────────────────────────
// helpers
// ────────────────────────────────────────────────────────────────────────────────

/**
 * URL โปรไฟล์อะไหล่ในแอป Parts จาก products.id
 *
 * @param int $appId products.id ฝั่ง biton_tech_parts
 * @return string ว่างถ้าไม่มี id
 */
function part_list_profile_url(int $appId): string
{
    if ($appId <= 0) {
        return '';
    }
    $base = function_exists('ui_parts_base_url') ? ui_parts_base_url() : parts_app_base_url();
    return rtrim((string) $base, '/') . '/pages/product-detail.php?id=' . $appId;
}

/**
 * ปุ่มรูปย่อที่กดแล้วเปิด popup ขนาดเต็ม
 *
 * @param string $iconPath path รูปในระบบ
 * @param string $alt      ชื่ออะไหล่ (หัว popup + alt)
 * @param string $imgClass คลาสของ <img>
 * @return string HTML ว่างถ้าไม่มีรูป
 */
function part_list_photo_btn(string $iconPath, string $alt, string $imgClass = 'thumb'): string
{
    $url = img_url($iconPath);
    if (!$url) {
        return '';
    }
    return '<button type="button" class="ls-photo-btn" title="กดดูรูปขนาดเต็ม"'
        . ' data-photo-src="' . h($url) . '" data-photo-alt="' . h($alt) . '">'
        . img_tag($iconPath, $alt, $imgClass)
        . '</button>';
}

/**
 * ชื่ออะไหล่ที่กดแล้วเปิดโปรไฟล์ในแท็บใหม่
 *
 * @param string $name  ชื่อที่แสดง
 * @param int    $appId products.id ฝั่งแอป Parts
 * @param string $cls   คลาสของ <a>
 * @return string HTML ที่ escape แล้ว
 */
function part_list_name_link(string $name, int $appId, string $cls = 'ls-name-link'): string
{
    $name = trim($name);
    if ($name === '') {
        return '—';
    }
    $esc = h($name);
    $url = part_list_profile_url($appId);
    if ($url === '') {
        return $esc;
    }
    return '<a class="' . h($cls) . '" href="' . h($url) . '" target="_blank" rel="noopener noreferrer"'
        . ' title="เปิดโปรไฟล์อะไหล่ในแท็บใหม่" onclick="event.stopPropagation()">' . $esc . '</a>';
}
