<?php
/**
 * pages/stock-in.php — รับเข้าอะไหล่ (modal) + ประวัติเต็มจอพร้อมรูป
 */

$pageTitle = 'รับเข้า';
require_once __DIR__ . '/../includes/header.php';

$editIn = isset($_GET['edit_in']) ? (int) $_GET['edit_in'] : 0;
$editRow = $editIn ? $stock->getStockInRow($editIn) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['delete_stock_in'])) {
            $stock->deleteStockIn((int) $_POST['id']);
            flash('success', 'ลบรายการรับเข้าแล้ว');
        } elseif (isset($_POST['update_stock_in'])) {
            $stock->updateStockIn(
                (int) $_POST['id'],
                (int) $_POST['quantity'],
                trim($_POST['note'] ?? '') ?: null
            );
            flash('success', 'แก้ไขรายการรับเข้าแล้ว');
        } else {
            ensureStockInColumns($db);
            $stock->stockIn(
                (int) $_POST['product_id'],
                (int) $_POST['quantity'],
                trim($_POST['note'] ?? '') ?: null,
                $line_name
            );
            flash('success', 'บันทึกรับเข้าเรียบร้อย');
        }
    } catch (Exception $e) {
        flash('error', safe_exception_message($e));
    }
    redirect(url('/pages/stock-in.php'));
}

$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
$history = $stock->getStockInHistory(50);
$partProdLabels = production_part_labels_by_stock_codes(array_column($products, 'code'));

$actions = parts_btn_open_modal('stock-in-add-modal', 'บันทึกรับเข้า', 'stock-in', 'btn-success');
parts_page_header('stock-in', 'รับเข้า', 'บันทึกการรับอะไหล่เข้าคลัง · ' . number_format(count($history)) . ' รายการล่าสุด', $actions);
?>

<div class="card parts-list-card">
    <?php if (empty($history)): ?>
        <p class="empty-state">ยังไม่มีประวัติรับเข้า — กดปุ่ม <strong>บันทึกรับเข้า</strong> เพื่อเพิ่มรายการ</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="parts-table">
            <thead>
                <tr>
                    <th class="col-img">รูป</th>
                    <th>รหัส</th>
                    <th>อะไหล่</th>
                    <th>ผู้รับ</th>
                    <th class="text-right">จำนวน</th>
                    <th>หมายเหตุ</th>
                    <th>วันเวลา</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h):
                    $icon = $partIcons[$h['code']] ?? '';
                ?>
                <tr>
                    <td class="col-img"><?= parts_img_tag($icon, parts_label_for_code((string) $h['code'], (string) $h['name'], $partProdLabels)) ?></td>
                    <td><?= e($h['code']) ?></td>
                    <td><?= e(parts_label_for_code((string) $h['code'], (string) $h['name'], $partProdLabels)) ?></td>
                    <td><?= e($h['received_by'] ?: '-') ?></td>
                    <td class="text-right text-success">+<?= formatNumber($h['quantity']) ?> <?= e($h['unit']) ?></td>
                    <td class="text-muted"><?= e($h['note'] ?: '-') ?></td>
                    <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('edit', url('/pages/stock-in.php?edit_in=' . (int) $h['id']), 'แก้ไข') ?>
                            <form method="POST" onsubmit="return confirm('ลบรายการรับเข้านี้?')">
                                <input type="hidden" name="delete_stock_in" value="1">
                                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
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

<?php parts_modal_begin('stock-in-add-modal', 'บันทึกรับเข้า'); ?>
<form method="POST">
    <?= parts_product_picker_html('product_id', $products, $partIcons, [
        'id'             => 'stock-in-product',
        'autofocus'      => true,
        'disableZeroQty' => false,
    ]) ?>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนรับเข้า</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเหตุ</label>
            <textarea name="note" rows="2" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea>
        </div>
    </div>
    <div class="form-actions">
        <button type="button" class="btn btn-outline modal-close-btn">ยกเลิก</button>
        <button type="submit" class="btn btn-success"><?= ui_icon_html('stock-in', 16, 'btn-svg') ?> บันทึกรับเข้า</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php if ($editRow): ?>
<?php parts_modal_begin('stock-in-edit-modal', 'แก้ไขรายการรับเข้า', true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_in" value="1">
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <div class="form-group">
        <label>อะไหล่</label>
        <input type="text" value="<?= e(parts_label_for_code((string) ($editRow['code'] ?? ''), (string) ($editRow['name'] ?? ''), $partProdLabels)) ?>" readonly>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนรับเข้า</label>
            <input type="number" name="quantity" min="1" value="<?= (int) $editRow['quantity'] ?>" required data-autofocus>
        </div>
        <div class="form-group">
            <label>หมายเหตุ</label>
            <textarea name="note" rows="2"><?= e($editRow['note'] ?? '') ?></textarea>
        </div>
    </div>
    <div class="form-actions">
        <a href="<?= url('/pages/stock-in.php') ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
