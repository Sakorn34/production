<?php

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
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-in.php'));
}

$products = $stock->getAllProducts();
$history = $stock->getStockInHistory(30);
?>

<div class="page-header">
    <?= ui_heading('stock-in', 'รับเข้า', 'h1') ?>
    <p>บันทึกการรับอะไหล่เข้าคลัง</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editRow ? 'แก้ไขรายการรับเข้า' : 'บันทึกรับเข้า' ?></h2>
        <?php if ($editRow): ?>
        <form method="POST">
            <input type="hidden" name="update_stock_in" value="1">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="form-group">
                <label>อะไหล่</label>
                <input type="text" value="<?= e($editRow['name']) ?>" readonly>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>จำนวนรับเข้า</label>
                    <input type="number" name="quantity" min="1" value="<?= (int) $editRow['quantity'] ?>" required>
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="2"><?= e($editRow['note'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success">บันทึกการแก้ไข</button>
                <a href="<?= url('/pages/stock-in.php') ?>" class="btn btn-outline">ยกเลิก</a>
            </div>
        </form>
        <?php else: ?>
        <form method="POST">
            <div class="form-group">
                <label>เลือกอะไหล่</label>
                <select name="product_id" required>
                    <option value="">-- เลือกอะไหล่ --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>">
                       <?= e($p['name']) ?> (คงเหลือ: <?= formatNumber($p['quantity']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>จำนวนรับเข้า</label>
                    <input type="number" name="quantity" min="1" required>
                </div>
                <div class="form-group">
                    <label>หมายเหตุ</label>
                    <textarea name="note" rows="2" placeholder="รายละเอียดเพิ่มเติม (ถ้ามี)"></textarea>
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-success">บันทึกรับเข้า</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>ประวัติรับเข้าล่าสุด</h2>
        <?php if (empty($history)): ?>
            <p class="empty-state">ยังไม่มีประวัติ</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>อะไหล่</th>
                    <th>ผู้รับ</th>
                    <th class="text-right">จำนวน</th>
                    <th>หมายเหตุ</th>
                    <th>วันที่</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['name']) ?></td>
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
