<?php
/**
 * includes/dash_low_stock.php — รายการอะไหล่ที่ควรสั่งเพิ่มบน Dashboard
 *
 * วัตถุประสงค์: render HTML รายการสต็อกต่ำกว่า min_stock ใช้ร่วม index + dashboard_data modal
 * ชื่อแสดงผลดึงจาก production.parts (name, part_code) ผ่าน part_stock_bridge
 */

require_once __DIR__ . '/part_stock_bridge.php';

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
        $qty = (int) $p['quantity'];
        $min = (int) $p['min_stock'];
        $pctLeft = $min > 0 ? min(100, round($qty / max(1, $min * 2) * 100)) : 50;
        // ระดับมาจาก shared/stock_status.php ที่เดียว — เดิมแผงนี้คำนวณ "วิกฤต" เองทำให้
        // อะไหล่ตัวเดียวกันขึ้นคนละสถานะกับการ์ดด้านบนในหน้าเดียวกัน
        $lsStatus = stock_status_key($qty, $min);
        $lsMeta = stock_status_meta($lsStatus);
        $crit = ($lsStatus === 'out' || $lsStatus === 'critical');
        $icon = isset($icons[$p['code']]) ? $icons[$p['code']] : '';
        $displayName = part_product_display_name($p, $prodMeta);
        $displaySub = part_product_display_sub($p, $prodMeta);
        echo '<div class="low-stock-card">';
        echo '<div class="ls-img">';
        if ($icon) {
            echo img_tag($icon, $displayName, 'ls-thumb');
        } else {
            echo '<span class="ls-thumb ls-thumb-ph">' . ui_icon_html('parts', 15) . '</span>';
        }
        echo '</div><div class="ls-info">'
           . '<div class="ls-name" title="' . h($displayName) . '">' . h($displayName) . '</div>'
           . '<div class="ls-code muted">' . h($displaySub) . '</div>'
           . '<div class="ls-bar"><i style="width:' . (int) $pctLeft . '%;background:' . ($crit ? 'var(--danger)' : 'var(--warning)') . '"></i></div>'
           . '</div>'
           . '<div class="ls-qty"><b>' . number_format($qty) . '</b> <span class="muted">' . h($p['unit'] ?: 'ชิ้น') . '</span></div>'
           . '<div class="ls-badge"><span class="badge-pill ' . ($crit ? 'bp-danger' : 'bp-warning') . '">'
           . h($lsMeta['label']) . '</span></div>'
           . '</div>';
    }
    echo '</div>';
}
