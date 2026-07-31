<?php
/**
 * pages/stock-out-item.php — เบิกรายชิ้น (modal) + ประวัติเต็มจอพร้อมรูป
 */

$pageTitle = 'เบิกรายชิ้น';
require_once __DIR__ . '/../includes/header.php';

$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
$editRow = $editOut ? $stock->getStockOutDetail($editOut) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['delete_stock_out'])) {
            $stock->deleteStockOut((int) $_POST['id']);
            flash('success', 'ลบรายการเบิกแล้ว');
        } elseif (isset($_POST['update_stock_out'])) {
            $stock->updateStockOutSingle(
                (int) $_POST['id'],
                (int) $_POST['quantity'],
                validateStockOutNote($_POST['note'] ?? null),
                trim($_POST['asset_code'] ?? '') ?: null,
                $line_name
            );
            flash('success', 'แก้ไขรายการเบิกแล้ว');
        } else {
            $docNo = $stock->stockOutItem(
                (int) $_POST['product_id'],
                (int) $_POST['quantity'],
                validateStockOutNote($_POST['note'] ?? null),
                trim($line_name ?? '') ?: null,
                trim($_POST['asset_code'] ?? '') ?: null
            );
            flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
        }
    } catch (Exception $e) {
        flash('error', safe_exception_message($e));
    }
    redirect(url('/pages/stock-out-item.php'));
}

$products = parts_enrich_products($stock->getAllProducts());
$partIcons = parts_product_icon_map($products);
$partProdLabels = production_part_labels_by_stock_codes(array_column($products, 'code'));
$history = $stock->getSingleItemOutHistory(50);
$noteOptions = getStockOutNoteOptions();

$actions = parts_btn_open_modal('stock-out-item-add-modal', 'เบิกรายชิ้น', 'stock-out-item', 'btn-danger');
parts_page_header('stock-out-item', 'เบิกรายชิ้น', 'เบิกอะไหล่ออกทีละรายการ · ระบุ S/N ได้ถ้าเบิกไปใช้กับเครื่อง', $actions);
?>

<div class="card parts-list-card">
    <?php if (empty($history)): ?>
        <p class="empty-state">ยังไม่มีประวัติเบิกรายชิ้น — กดปุ่ม <strong>เบิกรายชิ้น</strong> เพื่อเพิ่มรายการ</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="parts-table">
            <thead>
                <tr>
                    <th class="col-img">รูป</th>
                    <th>เลขที่</th>
                    <th>อะไหล่</th>
                    <th>S/N</th>
                    <th class="text-right">จำนวน</th>
                    <th>ผู้เบิก</th>
                    <th>ประเภทการเบิก</th>
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
                    <td><?= e($h['doc_no']) ?></td>
                    <td><?= e(parts_format_product_line((string) $h['code'], (string) $h['name'], $partProdLabels)) ?></td>
                    <td><?= e($h['asset_code'] ?: '-') ?></td>
                    <td class="text-right text-danger">-<?= formatNumber($h['quantity']) ?> <?= e($h['unit']) ?></td>
                    <td><?= e($h['issued_by'] ?: '-') ?></td>
                    <td class="text-muted"><?= e($h['note'] ?: '-') ?></td>
                    <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('edit', url('/pages/stock-out-item.php?edit_out=' . (int) $h['stock_out_id']), 'แก้ไข') ?>
                            <form method="POST" onsubmit="return confirm('ลบรายการเบิกนี้? ข้อมูลในระบบทะเบียนเครื่องที่เกี่ยวข้องจะถูกปรับตามด้วย')">
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

<?php parts_modal_begin('stock-out-item-add-modal', 'บันทึกเบิกรายชิ้น'); ?>
<form method="POST">
    <?= parts_product_picker_html('product_id', $products, $partIcons, [
        'id' => 'stock-out-item-product',
        'autofocus' => true,
    ]) ?>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนเบิก</label>
            <input type="number" name="quantity" min="1" value="1" required>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" placeholder="เช่น BP26072024">
        </div>
    </div>
    <p class="form-hint">ระบุ S/N ถ้าเบิกไปใช้กับเครื่อง</p>
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
        <button type="submit" class="btn btn-danger"><?= ui_icon_html('stock-out-item', 16, 'btn-svg') ?> ยืนยันเบิกออก</button>
    </div>
</form>
<?php parts_modal_end(); ?>

<?php if ($editRow): ?>
<?php $ei = $editRow['items'][0] ?? null; ?>
<?php if ($ei): ?>
<?php parts_modal_begin('stock-out-item-edit-modal', 'แก้ไขรายการเบิก', true, true); ?>
<form method="POST">
    <input type="hidden" name="update_stock_out" value="1">
    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
    <div class="form-group">
        <label>อะไหล่</label>
        <input type="text" value="<?= e(parts_label_for_code((string) ($ei['code'] ?? ''), (string) ($ei['name'] ?? ''), $partProdLabels)) ?>" readonly>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>จำนวนเบิก</label>
            <input type="number" name="quantity" min="1" value="<?= (int) $ei['quantity'] ?>" required data-autofocus>
        </div>
        <div class="form-group">
            <label>หมายเลขเครื่อง (S/N)</label>
            <input type="text" name="asset_code" value="<?= e($editRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024">
        </div>
    </div>
    <div class="form-group">
        <label>ประเภทการเบิก</label>
        <select name="note" required>
            <?php foreach ($noteOptions as $option): ?>
            <option value="<?= e($option) ?>" <?= ($editRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!empty($editRow['part_movement_id'])): ?>
    <p class="sync-badge"><?= ui_icon_html('switch', 12, 'sync-svg') ?> เชื่อมกับระบบทะเบียนเครื่องแล้ว</p>
    <?php endif; ?>
    <div class="form-actions">
        <a href="<?= url('/pages/stock-out-item.php') ?>" class="btn btn-outline">ยกเลิก</a>
        <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
    </div>
</form>
<?php parts_modal_end(); ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
