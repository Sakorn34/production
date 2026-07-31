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
