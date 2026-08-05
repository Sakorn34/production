<?php
/**
 * pages/stock-out.php — เบิกออก Set + จัดการ Set (รวมหน้าเดียว)
 *
 * เดิมแยกเป็น stock-out.php (เบิก) กับ sets.php (จัดการ) ซึ่ง render รายการ Set ซ้ำกันทั้งสองหน้า
 * รวมเป็นหน้าเดียวแล้ว — sets.php redirect มาที่นี่
 *
 * การจัดวาง: ปุ่ม "เบิกออก Set" (งานประจำวัน) อยู่หัวหน้า ส่วนปุ่มแก้ไข/ลบ Set (แก้ข้อมูลต้นแบบ)
 * ซ่อนอยู่ในกรอบ "จัดการ Set นี้" ท้ายรายการที่กางออกมา เพื่อลดโอกาสกดพลาดระหว่างทำงานประจำวัน
 */

$pageTitle = 'เบิกออก Set';
require_once __DIR__ . '/../includes/header.php';

$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
$editRow = $editOut ? $stock->getStockOutDetail($editOut) : null;

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
        } elseif (isset($_POST['delete_stock_out'])) {
            $stock->deleteStockOut((int) $_POST['id']);
            flash('success', 'ลบรายการเบิก Set แล้ว');
        } elseif (isset($_POST['update_stock_out_meta'])) {
            $stock->updateStockOutMeta(
                (int) $_POST['id'],
                validateStockOutNote($_POST['note'] ?? null),
                trim($_POST['asset_code'] ?? '') ?: null,
                $line_name
            );
            flash('success', 'แก้ไขรายการเบิกแล้ว');
        } else {
            $docNo = $stock->stockOutBySet(
                (int) $_POST['set_id'],
                (int) $_POST['set_count'],
                validateStockOutNote($_POST['note'] ?? null),
                trim($line_name ?? '') ?: null,
                trim($_POST['asset_code'] ?? '') ?: null
            );
            flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
        }
    } catch (Exception $e) {
        flash('error', safe_exception_message($e));
    }
    redirect(url('/pages/stock-out.php'));
}

$sets = $stock->getAllSetsWithItems();
$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
$partProdLabels = production_part_labels_by_stock_codes(array_column($products, 'code'));
$history = $stock->getSetOutHistory(50);
$noteOptions = getStockOutNoteOptions();

$actions = parts_btn_open_modal('stock-out-set-modal', 'เบิกออก Set', 'stock-out-set', 'btn-danger')
    . parts_btn_open_modal('set-add-modal', 'สร้าง Set', 'plus', 'btn-outline')
    . parts_btn_open_modal('set-item-add-modal', 'เพิ่มอะไหล่ใน Set', 'stock-in', 'btn-outline');
parts_page_header(
    'stock-out-set',
    'เบิกออก Set',
    'เบิกอะไหล่ออกเป็นชุด และจัดการ Set · ' . number_format(count($sets)) . ' Set · ระบุ S/N ได้ถ้าเบิกไปใช้กับเครื่อง',
    $actions
);
?>

<div class="card parts-list-card">
    <h2 style="margin-bottom:1rem;font-size:1.05rem">Set ทั้งหมด <span class="text-muted" style="font-weight:400;font-size:0.85rem">— กดที่แถวเพื่อดูรายการอะไหล่และจัดการ</span></h2>
    <?= parts_sets_accordion_html($sets, $partIcons, $partProdLabels, true) ?>
</div>

<div class="card parts-list-card">
    <h2 style="margin-bottom:1rem;font-size:1.05rem">ประวัติเบิก Set ล่าสุด</h2>
    <?php if (empty($history)): ?>
        <p class="empty-state">ยังไม่มีประวัติ</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="parts-table">
            <thead>
                <tr>
                    <th>เลขที่</th>
                    <th>Set</th>
                    <th>S/N</th>
                    <th class="text-right">จำนวนรวม</th>
                    <th>ผู้เบิก</th>
                    <th>ประเภทการเบิก</th>
                    <th>วันเวลา</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['doc_no']) ?></td>
                    <td>[<?= e($h['set_code']) ?>] <?= e($h['set_name']) ?></td>
                    <td><?= e($h['asset_code'] ?: '-') ?></td>
                    <td class="text-right"><?= formatNumber($h['total_qty']) ?></td>
                    <td><?= e($h['issued_by'] ?: '-') ?></td>
                    <td class="text-muted"><?= e($h['note'] ?: '-') ?></td>
                    <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('edit', url('/pages/stock-out.php?edit_out=' . (int) $h['stock_out_id']), 'แก้ไข') ?>
                            <form method="POST" onsubmit="return confirm('ลบรายการเบิก Set นี้?')">
                                <input type="hidden" name="delete_stock_out" value="1">
                                <input type="hidden" name="id" value="<?= (int) $h['stock_out_id'] ?>">
                                <?= actionIcon('delete', '', 'ลบ') ?>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php parts_modal_begin('stock-out-set-modal', 'เบิกออก Set'); ?>
<form method="POST">
    <div class="form-group">
        <label>เลือก Set</label>
        <select name="set_id" required data-autofocus>
            <option value="">-- เลือก Set --</option>
            <?php foreach ($sets as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= !$s['can_issue'] ? 'disabled' : '' ?>>
                [<?= e($s['code']) ?>] <?= e($s['name']) ?>
                <?= !$s['can_issue'] ? '(สต็อกไม่พอ)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนชุดที่เบิก</label>
            <input type="number" name="set_count" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" placeholder="เช่น BP26072024">
        </div>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <option value="">-- เลือกประเภทการเบิก --</option>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>"><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-danger"><?= ui_icon_html('stock-out-set', 16, 'btn-svg') ?> ยืนยันเบิกออก</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php if ($editRow && !empty($editRow['set_id'])): ?>
<?php parts_modal_begin('stock-out-set-edit-modal', 'แก้ไขรายการเบิก Set — ' . $editRow['doc_no'], true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_out_meta" value="1">
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <div class="form-group">
        <label>ชุดเบิก</label>
        <input type="text" value="[<?= e($editRow['set_code']) ?>] <?= e($editRow['set_name']) ?>" readonly>
    </div>
    <div class="form-group">
        <label>หมายเลขเครื่อง (S/N)</label>
        <input type="text" name="asset_code" value="<?= e($editRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024" data-autofocus>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>" <?= ($editRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-actions">
        <a href="<?= url('/pages/stock-out.php') ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

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
