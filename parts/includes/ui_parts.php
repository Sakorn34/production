<?php
/**
 * parts/includes/ui_parts.php — UI ร่วมสำหรับหน้า Parts
 *
 * องค์ประกอบหลัก: page header แบบเต็มจอ, modal shell, ปุ่ม action
 * Flow: require ผ่าน parts_bootstrap → เรียก parts_page_header() + parts_modal_* ในหน้าแต่ละ module
 */

/**
 * หัวหน้าแบบเต็มความกว้าง พร้อมปุ่ม action ด้านขวา
 *
 * @param string $iconKey   คีย์ไอคอน ui_heading
 * @param string $title     หัวข้อหลัก
 * @param string $subtitle  คำอธิบายย่อย (HTML ไม่ escape — ใส่ number_format เองในหน้า)
 * @param string $actionsHtml ปุ่ม HTML ด้านขวา
 */
function parts_page_header(string $iconKey, string $title, string $subtitle, string $actionsHtml = ''): void
{
    ?>
    <div class="page-header parts-page-header">
        <div>
            <?= ui_heading($iconKey, $title, 'h1') ?>
            <p><?= $subtitle ?></p>
        </div>
        <?php if ($actionsHtml !== ''): ?>
        <div class="parts-page-actions"><?= $actionsHtml ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * เริ่ม modal overlay
 *
 * @param string $id     id ของ overlay
 * @param string $title  หัวข้อ modal
 * @param bool   $hidden ซ่อนเริ่มต้น
 * @param bool   $autoOpen เปิดอัตโนมัติเมื่อโหลดหน้า (แก้ไข)
 */
function parts_modal_begin(string $id, string $title, bool $hidden = true, bool $autoOpen = false): void
{
    $attrs = $hidden ? ' hidden' : '';
    if ($autoOpen) {
        $attrs .= ' data-auto-open="1"';
    }
    ?>
    <div id="<?= e($id) ?>" class="modal-overlay"<?= $attrs ?>>
        <div class="modal-box" role="dialog" aria-labelledby="<?= e($id) ?>-title" aria-modal="true">
            <div class="modal-header">
                <h3 id="<?= e($id) ?>-title"><?= e($title) ?></h3>
                <button type="button" class="modal-close" aria-label="ปิด"><?= ui_icon_html('close', 16, 'btn-svg') ?></button>
            </div>
            <div class="modal-body">
    <?php
}

/**
 * ปิด modal overlay
 */
function parts_modal_end(): void
{
    ?>
            </div>
        </div>
    </div>
    <?php
}

/**
 * ปุ่ม primary เปิด modal
 *
 * @param string $modalId id ของ modal
 * @param string $label   ข้อความปุ่ม
 * @param string $iconKey คีย์ไอคอน
 * @param string $class   class เพิ่มเติม
 */
function parts_btn_open_modal(string $modalId, string $label, string $iconKey = 'plus', string $class = 'btn-primary'): string
{
    return '<button type="button" class="btn ' . e($class) . ' btn-with-icon" data-open-modal="' . e($modalId) . '">'
        . ui_icon_html($iconKey, 16, 'btn-svg') . ' ' . e($label)
        . '</button>';
}

/**
 * รายการ Set แบบยุบได้ (details/summary) — ใช้ร่วมกันในหน้าเบิกออก Set
 *
 * ยุบไว้เป็นค่าเริ่มต้นเพื่อให้เห็นภาพรวมทุก Set ในหน้าจอเดียว กางดูรายละเอียดทีละอันได้
 * โหมดจัดการ ($manage) จะแสดงส่วน "จัดการ Set" แยกกรอบท้ายรายการ เพื่อไม่ให้ปะปนกับปุ่มเบิกที่ใช้ทุกวัน
 *
 * @param array<int,array<string,mixed>> $sets           ผลจาก getAllSetsWithItems()
 * @param array<string,string>           $partIcons      map code => icon path
 * @param array<string,array>            $partProdLabels map จาก production_part_labels_by_stock_codes()
 * @param bool                           $manage         true = แสดงปุ่มแก้ไข/ลบ
 * @return string HTML
 */
function parts_sets_accordion_html(array $sets, array $partIcons, array $partProdLabels, bool $manage = false): string
{
    if (!$sets) {
        return '<p class="empty-state">ยังไม่มี Set — กดปุ่ม <strong>สร้าง Set</strong> เพื่อเริ่มต้น</p>';
    }

    $out = '<div class="set-accordion">';
    foreach ($sets as $s) {
        $setId = (int) $s['id'];
        $items = $s['items'] ?? [];
        $canIssue = !empty($s['can_issue']);
        $itemCount = count($items);

        $badge = $canIssue
            ? '<span class="badge badge-success">พร้อมเบิก</span>'
            : '<span class="badge badge-danger">สต็อกไม่พอ</span>';

        $out .= '<details class="set-row' . ($canIssue ? '' : ' set-row-short') . '">';
        $out .= '<summary class="set-row-summary">'
            . '<span class="set-row-caret" aria-hidden="true"></span>'
            . '<span class="set-row-code">' . e($s['code']) . '</span>'
            . '<span class="set-row-name">' . e($s['name']) . '</span>'
            . '<span class="set-row-count">' . number_format($itemCount) . ' รายการ</span>'
            . '<span class="set-row-badge">' . $badge . '</span>'
            . '</summary>';

        $out .= '<div class="set-row-body">';

        if ($s['description']) {
            $out .= '<p class="text-muted set-row-desc">' . e($s['description']) . '</p>';
        }

        if (!$items) {
            $out .= '<p class="text-muted">ยังไม่มีอะไหล่ใน Set</p>';
        } else {
            $out .= '<div class="table-wrap"><table class="parts-table set-items-table"><thead><tr>'
                . '<th class="col-img">รูป</th><th>อะไหล่</th>'
                . '<th class="text-right">จำนวน/Set</th><th class="text-right">คงเหลือ</th>'
                . ($manage ? '<th class="col-actions"></th>' : '')
                . '</tr></thead><tbody>';

            foreach ($items as $item) {
                $code = (string) ($item['code'] ?? '');
                $name = (string) ($item['name'] ?? '');
                $icon = $partIcons[$code] ?? '';
                $need = (int) $item['quantity'];
                $have = (int) ($item['stock_qty'] ?? 0);
                $short = $have < $need;

                $out .= '<tr' . ($short ? ' class="set-item-short"' : '') . '>';
                $out .= '<td class="col-img">' . parts_img_tag($icon, parts_label_for_code($code, $name, $partProdLabels)) . '</td>';
                $out .= '<td>' . e(parts_format_product_line($code, $name, $partProdLabels)) . '</td>';

                if ($manage) {
                    $out .= '<td class="text-right set-qty-cell">'
                        . '<form method="POST" class="set-qty-form">'
                        . '<input type="hidden" name="action" value="update_item_qty">'
                        . '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '">'
                        . '<input type="number" name="quantity" min="1" value="' . $need . '" class="set-qty-input" aria-label="จำนวนต่อ Set">'
                        . '<button type="submit" class="btn btn-sm btn-icon" title="บันทึกจำนวน">' . ui_icon_html('save', 14, 'btn-svg') . '</button>'
                        . '</form>'
                        . '<span class="text-muted set-qty-unit">' . e($item['unit']) . '</span>'
                        . '</td>';
                } else {
                    $out .= '<td class="text-right">' . formatNumber($need) . ' ' . e($item['unit']) . '</td>';
                }

                $out .= '<td class="text-right">' . formatNumber($have)
                    . ($short ? ' <span class="text-danger">ไม่พอ</span>' : '') . '</td>';

                if ($manage) {
                    $out .= '<td class="col-actions">'
                        . '<form method="POST" onsubmit="return confirm(\'ลบรายการนี้ออกจาก Set?\')">'
                        . '<input type="hidden" name="action" value="remove_item">'
                        . '<input type="hidden" name="item_id" value="' . (int) $item['id'] . '">'
                        . actionIcon('delete', '', 'ลบออกจาก Set')
                        . '</form></td>';
                }

                $out .= '</tr>';
            }
            $out .= '</tbody></table></div>';
        }

        if ($manage) {
            $out .= '<div class="set-manage-zone">'
                . '<b class="set-manage-title">จัดการ Set นี้</b>'
                . '<form method="POST" class="set-manage-form">'
                . '<input type="hidden" name="action" value="update_set">'
                . '<input type="hidden" name="set_id" value="' . $setId . '">'
                . '<label class="sr-only" for="set-name-' . $setId . '">ชื่อ Set</label>'
                . '<input type="text" id="set-name-' . $setId . '" name="name" value="' . e($s['name']) . '" required maxlength="200" placeholder="ชื่อ Set">'
                . '<label class="sr-only" for="set-desc-' . $setId . '">รายละเอียด</label>'
                . '<input type="text" id="set-desc-' . $setId . '" name="description" value="' . e((string) ($s['description'] ?? '')) . '" placeholder="รายละเอียด (ไม่บังคับ)">'
                . '<button type="submit" class="btn btn-sm btn-success btn-with-icon">' . ui_btn_label('save', 'บันทึกชื่อ', 14) . '</button>'
                . '</form>'
                . '<form method="POST" class="set-delete-form" onsubmit="return confirm(\'ลบ Set ' . e($s['code']) . ' ทั้งชุด?\')">'
                . '<input type="hidden" name="action" value="delete_set">'
                . '<input type="hidden" name="set_id" value="' . $setId . '">'
                . '<button type="submit" class="btn btn-sm btn-danger btn-with-icon">' . ui_btn_label('trash', 'ลบ Set นี้', 14) . '</button>'
                . '</form>'
                . '</div>';
        }

        $out .= '</div></details>';
    }
    $out .= '</div>';

    return $out;
}
