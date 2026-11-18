<?php
/**
 * key_parts_order_config.php — ตั้งรายการอะไหล่สั่งต่างประเทศ (หน้า parts/key-parts-order)
 *
 * เพิ่ม/ลด/แก้รายการที่สรุปยอดจาก inventory log_stock · เก็บ JSON ใน site_settings
 * ต้องปลดล็อกหลังบ้าน (PIN) · ชื่อ log_part ต้องตรงกับ inventory
 *
 * Flow: settings_gate → POST บันทึก/รีเซ็ต → แสดงฟอร์มตารางรายการ
 */

require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require __DIR__ . '/includes/layout.php';
require_once dirname(__DIR__) . '/parts/includes/key_parts_order.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? 'save'));

    if ($action === 'reset') {
        if (key_parts_order_reset_catalog_setting()) {
            flash_set('รีเซ็ตเป็นชุดเริ่มต้น 3 รายการแล้ว');
        } else {
            flash_set('รีเซ็ตไม่สำเร็จ', 'err');
        }
        header('Location: ' . BASE_URL . '/key_parts_order_config.php');
        exit;
    }

    $keys = (array) ($_POST['item_key'] ?? []);
    $labels = (array) ($_POST['item_label'] ?? []);
    $revs = (array) ($_POST['item_rev'] ?? []);
    $logParts = (array) ($_POST['item_log_part'] ?? []);
    $rows = [];
    $n = max(count($keys), count($labels), count($logParts));
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'key'          => isset($keys[$i]) ? (string) $keys[$i] : '',
            'label'        => isset($labels[$i]) ? (string) $labels[$i] : '',
            'rev_label'    => isset($revs[$i]) ? (string) $revs[$i] : '',
            'inv_log_part' => isset($logParts[$i]) ? (string) $logParts[$i] : '',
        ];
    }

    $result = key_parts_order_save_catalog_setting($rows);
    if ($result['ok']) {
        flash_set('บันทึกรายการอะไหล่แล้ว (' . count(key_parts_order_catalog_def()) . ' รายการ)');
    } else {
        flash_set($result['error'] !== '' ? $result['error'] : 'บันทึกไม่สำเร็จ', 'err');
    }
    header('Location: ' . BASE_URL . '/key_parts_order_config.php');
    exit;
}

$usingCustom = key_parts_order_catalog_setting_json() !== '';
$catalog = key_parts_order_catalog_def();
$kpoPageUrl = ui_parts_base_url() . '/pages/key-parts-order.php';

page_header('รายการอะไหล่สั่งต่างประเทศ', true);
?>

<p class="muted" style="margin:-4px 0 14px">
  กำหนดว่าหน้า <a href="<?= h($kpoPageUrl) ?>" target="_blank" rel="noopener noreferrer">อะไหล่สั่งต่างประเทศ</a>
  จะสรุปอะไหล่ใดบ้าง · ชื่อ <b>log_part</b> ต้องตรงกับ inventory (ใช้ใน log_stock)
  · รหัส <b>key</b> ใช้ระบุรายการ (a-z, 0-9, _ - · ขึ้นต้นตัวอักษร)
  <?php if ($usingCustom): ?>
  · <span class="text-success">ใช้ชุดที่ตั้งเอง</span>
  <?php else: ?>
  · <span class="muted">ใช้ชุดเริ่มต้นในโค้ด</span>
  <?php endif; ?>
</p>

<form method="post" id="kpo-catalog-form" class="card" style="padding:16px;margin-bottom:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">

    <div class="table-wrap">
        <table class="pc-table" id="kpo-catalog-table">
            <thead>
                <tr>
                    <th style="width:120px">key</th>
                    <th>ชื่อบนการ์ด</th>
                    <th style="width:140px">รุ่น/Rev</th>
                    <th>ชื่อ log_part (inventory)</th>
                    <th style="width:72px"></th>
                </tr>
            </thead>
            <tbody id="kpo-catalog-body">
                <?php foreach ($catalog as $row): ?>
                <tr>
                    <td><input type="text" name="item_key[]" value="<?= h((string) $row['key']) ?>" maxlength="32" pattern="[a-z][a-z0-9_-]*" required style="width:100%"></td>
                    <td><input type="text" name="item_label[]" value="<?= h((string) $row['label']) ?>" maxlength="120" required style="width:100%"></td>
                    <td><input type="text" name="item_rev[]" value="<?= h((string) $row['rev_label']) ?>" maxlength="80" style="width:100%"></td>
                    <td><input type="text" name="item_log_part[]" value="<?= h((string) $row['inv_log_part']) ?>" maxlength="200" required style="width:100%"></td>
                    <td><button type="button" class="btn btn-line btn-sm kpo-row-remove" aria-label="ลบแถว">ลบ</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="margin:12px 0 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <button type="button" class="btn btn-line btn-sm btn-with-icon" id="kpo-add-row">
            <?= ui_btn_label('plus', 'เพิ่มรายการ') ?>
        </button>
        <button type="submit" class="btn btn-primary btn-with-icon">
            <?= ui_btn_label('check', 'บันทึกรายการ') ?>
        </button>
        <span class="muted">สูงสุด <?= (int) KEY_PARTS_ORDER_CATALOG_MAX ?> รายการ</span>
    </p>
</form>

<form method="post" onsubmit="return confirm('รีเซ็ตเป็นชุดเริ่มต้น 3 รายการในโค้ด? การตั้งค่าที่บันทึกจะถูกลบ');">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset">
    <button type="submit" class="btn btn-line btn-sm">รีเซ็ตเป็นชุดเริ่มต้น</button>
</form>

<template id="kpo-catalog-row-tpl">
    <tr>
        <td><input type="text" name="item_key[]" value="" maxlength="32" pattern="[a-z][a-z0-9_-]*" required placeholder="เช่น pi4" style="width:100%"></td>
        <td><input type="text" name="item_label[]" value="" maxlength="120" required placeholder="ชื่อแสดง" style="width:100%"></td>
        <td><input type="text" name="item_rev[]" value="" maxlength="80" placeholder="Rev / Lot" style="width:100%"></td>
        <td><input type="text" name="item_log_part[]" value="" maxlength="200" required placeholder="ชื่อใน log_stock" style="width:100%"></td>
        <td><button type="button" class="btn btn-line btn-sm kpo-row-remove" aria-label="ลบแถว">ลบ</button></td>
    </tr>
</template>

<script>
(function () {
    var body = document.getElementById('kpo-catalog-body');
    var tpl = document.getElementById('kpo-catalog-row-tpl');
    var maxRows = <?= (int) KEY_PARTS_ORDER_CATALOG_MAX ?>;

    document.getElementById('kpo-add-row').addEventListener('click', function () {
        if (!body || !tpl) return;
        if (body.querySelectorAll('tr').length >= maxRows) {
            alert('เกินจำนวนสูงสุด ' + maxRows + ' รายการ');
            return;
        }
        body.appendChild(tpl.content.cloneNode(true));
    });

    document.getElementById('kpo-catalog-form').addEventListener('click', function (ev) {
        var btn = ev.target.closest('.kpo-row-remove');
        if (!btn || !body) return;
        var rows = body.querySelectorAll('tr');
        if (rows.length <= 1) {
            alert('ต้องเหลืออย่างน้อย 1 รายการ');
            return;
        }
        btn.closest('tr').remove();
    });
})();
</script>

<?php page_footer(); ?>
