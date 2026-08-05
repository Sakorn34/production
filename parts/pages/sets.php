<?php
/**
 * pages/sets.php — จัดการ Set (นิยามชุดอะไหล่)
 *
 * ประวัติของไฟล์นี้: 2026-07-31 เคยรวมเข้ากับ stock-out.php เพราะสองหน้า render
 * รายการ Set ซ้ำกัน — 2026-08-05 การเบิกออก Set ย้ายไปเป็น modal ที่ products.php
 * และประวัติเบิกไปที่ history.php?tab=set หน้านี้จึงกลับมาทำหน้าที่เดิมของตัวเอง
 * คือแก้ไข "นิยาม" ของ Set อย่างเดียว ส่วน stock-out.php กลายเป็น redirect มาที่นี่
 *
 * แยกงานตั้งค่า (หน้านี้) ออกจากงานประจำวัน (products.php) เพื่อลดโอกาสกดพลาด
 * ปุ่มแก้ไข/ลบ Set ยังซ่อนอยู่ในกรอบ "จัดการ Set นี้" ท้ายรายการที่กางออกมา
 */

$pageTitle = 'จัดการ Set';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'add_set') {
            $code = generateSetCode($db);
            $stmt = $db->prepare('INSERT INTO sets (code, name, description) VALUES (?, ?, ?)');
            $stmt->execute([
                $code,
                trim($_POST['name']),
                trim($_POST['description'] ?? '') ?: null,
            ]);
            flash('success', "สร้าง Set เรียบร้อย (รหัส: {$code})");
        } elseif ($action === 'update_set') {
            $stock->updateSet((int) $_POST['set_id'], (string) $_POST['name'], $_POST['description'] ?? null);
            flash('success', 'บันทึกชื่อ Set แล้ว');
        } elseif ($action === 'delete_set') {
            $stock->deleteSet((int) $_POST['set_id']);
            flash('success', 'ลบ Set แล้ว');
        } elseif ($action === 'add_item') {
            $stmt = $db->prepare(
                'INSERT INTO set_items (set_id, product_id, quantity) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
            );
            $stmt->execute([
                (int) $_POST['set_id'],
                (int) $_POST['product_id'],
                (int) $_POST['quantity'],
            ]);
            flash('success', 'เพิ่มอะไหล่ใน Set เรียบร้อย');
        } elseif ($action === 'update_item_qty') {
            $stock->updateSetItemQty((int) $_POST['item_id'], (int) $_POST['quantity']);
            flash('success', 'แก้จำนวนต่อ Set แล้ว');
        } elseif ($action === 'remove_item') {
            $stmt = $db->prepare('DELETE FROM set_items WHERE id = ?');
            $stmt->execute([(int) $_POST['item_id']]);
            flash('success', 'ลบรายการออกจาก Set แล้ว');
        } else {
            // ไม่มี else ที่แก้ข้อมูล — คำสั่งที่ไม่รู้จักต้องไม่ทำอะไรทั้งสิ้น
            flash('error', 'คำสั่งไม่ถูกต้อง');
        }
    } catch (Exception $e) {
        flash('error', safe_exception_message($e));
    }
    redirect(url('/pages/sets.php'));
}

$sets = $stock->getAllSetsWithItems();
$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
$partProdLabels = production_part_labels_by_stock_codes(array_column($products, 'code'));

$actions = parts_btn_open_modal('set-add-modal', 'สร้าง Set', 'plus', 'btn-primary')
    . parts_btn_open_modal('set-item-add-modal', 'เพิ่มอะไหล่ใน Set', 'stock-in', 'btn-outline');
parts_page_header(
    'sets',
    'จัดการ Set',
    'กำหนดว่าแต่ละ Set ประกอบด้วยอะไหล่อะไรบ้าง · ' . number_format(count($sets)) . ' Set · เบิกออกจริงทำที่หน้าอะไหล่',
    $actions
);
?>

<div class="card parts-list-card">
    <h2 style="margin-bottom:1rem;font-size:1.05rem">Set ทั้งหมด <span class="text-muted" style="font-weight:400;font-size:0.85rem">— กดที่แถวเพื่อดูรายการอะไหล่และจัดการ</span></h2>
    <?= parts_sets_accordion_html($sets, $partIcons, $partProdLabels, true) ?>
</div>

<?php parts_modal_begin('set-add-modal', 'สร้าง Set ใหม่'); ?>
<form method="POST">
    <input type="hidden" name="action" value="add_set">
    <div class="form-group">
        <label for="set-add-name">ชื่อ Set</label>
        <input type="text" id="set-add-name" name="name" required data-autofocus maxlength="200">
    </div>
    <div class="form-group">
        <label for="set-add-desc">รายละเอียด</label>
        <textarea id="set-add-desc" name="description" rows="2"></textarea>
    </div>
    <p class="muted" style="font-size:12px;margin:0 0 12px">รหัส Set สร้างอัตโนมัติ</p>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('plus', 16, 'btn-svg') ?> สร้าง Set</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php parts_modal_begin('set-item-add-modal', 'เพิ่มอะไหล่ใน Set'); ?>
<form method="POST">
    <input type="hidden" name="action" value="add_item">
    <div class="form-group">
        <label for="set-item-set">เลือก Set</label>
        <select id="set-item-set" name="set_id" required data-autofocus>
            <option value="">-- เลือก Set --</option>
            <?php foreach ($sets as $s): ?>
            <option value="<?= (int) $s['id'] ?>">[<?= e($s['code']) ?>] <?= e($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="set-item-search">ค้นหาอะไหล่</label>
        <input type="text" id="set-item-search" data-product-search="set-item-product" placeholder="พิมพ์ชื่อหรือรหัสอะไหล่" autocomplete="off">
    </div>
    <div class="form-group">
        <label for="set-item-product">เลือกอะไหล่</label>
        <select name="product_id" id="set-item-product" required>
            <option value="">-- เลือกอะไหล่ --</option>
            <?php foreach ($products as $p): ?>
            <option value="<?= (int) $p['id'] ?>"><?= e(parts_format_product_option($p)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="set-item-qty">จำนวนต่อ 1 Set</label>
        <input type="number" id="set-item-qty" name="quantity" min="1" value="1" required>
    </div>
    <p class="muted" style="font-size:12px;margin:0 0 12px">ถ้าอะไหล่นี้มีใน Set อยู่แล้ว ระบบจะแทนที่จำนวนเดิม</p>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> เพิ่มใน Set</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
