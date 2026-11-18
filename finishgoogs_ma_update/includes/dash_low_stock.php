<?php
/**
 * includes/dash_low_stock.php — รายการอะไหล่ที่ควรสั่งเพิ่มบน Dashboard
 *
 * วัตถุประสงค์: render HTML รายการสต็อกต่ำกว่า min_stock ใช้ร่วม index + dashboard_data modal
 * ชื่อแสดงผลดึงจาก production.parts (name, part_code) ผ่าน part_stock_bridge
 * รูปย่อกดเปิด popup ขนาดเต็มได้ (overlay อยู่ใน layout.php)
 * ชื่ออะไหล่เปิดโปรไฟล์ในแท็บใหม่ (parts/pages/product-detail.php)
 */

require_once __DIR__ . '/part_stock_bridge.php';
require_once __DIR__ . '/part_list_ui.php';

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * โหลด metadata อะไหล่จาก production ตามแถว products
 *
 * @param array<int,array<string,mixed>> $rows แถวจาก products (ต้องมี key code)
 * @return array<string,array{name:string,part_code:string,icon_path:string}>
 */
function dash_part_prod_meta_map(array $rows): array
{
    if (!$rows) {
        return [];
    }
    $codes = array_map(function ($r) {
        return (string) ($r['code'] ?? '');
    }, $rows);
    return production_part_labels_by_stock_codes($codes);
}

/**
 * โหลด map รหัสอะไหล่ → icon_path จาก metadata production
 *
 * @param array<int,array<string,mixed>> $rows แถวจาก products (ต้องมี key code)
 * @return array<string,string>
 */
function dash_low_stock_icon_map(array $rows): array
{
    $meta = dash_part_prod_meta_map($rows);
    $icons = [];
    foreach ($meta as $code => $m) {
        if (!empty($m['icon_path'])) {
            $icons[$code] = $m['icon_path'];
        }
    }
    return $icons;
}

/**
 * ปุ่มรูปย่อที่กดแล้วเปิด popup ขนาดเต็ม
 *
 * @param string $iconPath path รูปในระบบ
 * @param string $alt      ชื่ออะไหล่ (หัว popup + alt)
 * @return string HTML
 */
function dash_low_stock_photo_btn(string $iconPath, string $alt): string
{
    $btn = part_list_photo_btn($iconPath, $alt, 'ls-thumb');
    return $btn !== '' ? $btn : '<span class="ls-thumb ls-thumb-ph">' . ui_icon_html('parts', 15) . '</span>';
}

/**
 * แสดงรายการอะไหล่ที่ควรสั่งเพิ่มแบบการ์ดแยกชิ้น
 *
 * @param array<int,array<string,mixed>> $rows     code, name, unit, quantity, min_stock
 * @param array<string,string>           $icons    map code → icon_path (legacy — ถ้าไม่ส่งจะ derive จาก prodMeta)
 * @param array<string,array{name:string,part_code:string,icon_path:string}>|null $prodMeta
 * @return void
 */
function dash_render_low_stock_table(array $rows, array $icons = [], ?array $prodMeta = null): void
{
    if (!$rows) {
        echo '<p class="muted dash-low-stock-empty">ไม่มีรายการที่ต้องสั่งเพิ่ม — สต็อกทุกตัวเพียงพอ</p>';
        return;
    }

    if ($prodMeta === null) {
        $prodMeta = dash_part_prod_meta_map($rows);
    }
    if ($icons === []) {
        foreach ($prodMeta as $code => $m) {
            if (!empty($m['icon_path'])) {
                $icons[$code] = $m['icon_path'];
            }
        }
    }

    echo '<div class="low-stock-cards">';
    foreach ($rows as $p) {
        $qty = qty_norm($p['quantity']);
        $min = qty_norm($p['min_stock']);
        $pctLeft = $min > 0 ? min(100, round($qty / max(1, $min * 2) * 100)) : 50;
        // ระดับมาจาก shared/stock_status.php ที่เดียว — เดิมแผงนี้คำนวณ "วิกฤต" เองทำให้
        // อะไหล่ตัวเดียวกันขึ้นคนละสถานะกับการ์ดด้านบนในหน้าเดียวกัน
        $lsStatus = stock_status_key($qty, $min);
        $lsMeta = stock_status_meta($lsStatus);
        $crit = ($lsStatus === 'out' || $lsStatus === 'critical');
        $icon = isset($icons[$p['code']]) ? $icons[$p['code']] : '';
        $displayName = part_product_display_name($p, $prodMeta);
        $displaySub = part_product_display_sub($p, $prodMeta);
        $nameHtml = part_list_name_link($displayName, (int) ($p['id'] ?? 0));
        echo '<div class="low-stock-card">';
        echo '<div class="ls-img">';
        if ($icon) {
            echo dash_low_stock_photo_btn($icon, $displayName);
        } else {
            echo '<span class="ls-thumb ls-thumb-ph">' . ui_icon_html('parts', 15) . '</span>';
        }
        // ชื่อผู้จำหน่าย — แสดงเป็นชิปต่อท้ายชื่ออะไหล่ (ตัดด้วย … ผ่าน CSS ถ้ายาวเกิน)
        $supplier = trim((string) ($p['supplier'] ?? ''));
        $supChip  = $supplier !== ''
            ? ' <span class="ls-sup-chip" title="ผู้จำหน่าย: ' . h($supplier) . '">' . h($supplier) . '</span>'
            : '';
        echo '</div><div class="ls-info">'
           . '<div class="ls-name">' . $nameHtml . $supChip . '</div>'
           . '<div class="ls-code muted">' . h($displaySub) . '</div>'
           . '<div class="ls-bar"><i style="width:' . (int) $pctLeft . '%;background:' . ($crit ? 'var(--danger)' : 'var(--warning)') . '"></i></div>'
           . '</div>'
           . '<div class="ls-qty"><b>' . qty_fmt($qty) . '</b> <span class="muted">' . h($p['unit'] ?: 'ชิ้น') . '</span></div>'
           . '<div class="ls-badge"><span class="badge-pill ' . ($crit ? 'bp-danger' : 'bp-warning') . '">'
           . h($lsMeta['label']) . '</span></div>';
        // ปุ่มสั่งซื้อ — เปิดลิงก์ที่ตั้งไว้ในข้อมูลอะไหล่ (เฉพาะ http/https) · ไม่มีลิงก์แสดงข้อความจางแทน
        $orderUrl = safe_http_url($p['purchase_link'] ?? '');
        echo '<div class="ls-order">';
        if ($orderUrl !== '') {
            echo '<a href="' . h($orderUrl) . '" class="btn btn-sm btn-line dash-part-order-btn" target="_blank"'
               . ' rel="noopener noreferrer" title="เปิดลิงก์สั่งซื้อ' . ($supplier !== '' ? ' · ' . h($supplier) : '') . '">'
               . ui_btn_label('external-link', 'สั่งซื้อ', 13) . '</a>';
        } else {
            echo '<span class="muted ls-no-link" title="ยังไม่ได้ตั้งลิงก์สั่งซื้อในข้อมูลอะไหล่">ไม่มีลิงก์</span>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}
