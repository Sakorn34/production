<?php

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
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-out-item.php'));
}

$products = $stock->getAllProducts();
$history = $stock->getSingleItemOutHistory(30);
?>

<div class="page-header">
    <?= ui_heading('stock-out-item', 'เบิกรายชิ้น', 'h1') ?>
    <p>เบิกอะไหล่ออกทีละรายการ — ระบุ S/N ได้ถ้าเบิกไปใช้กับเครื่อง</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2><?= $editRow ? 'แก้ไขรายการเบิก' : 'บันทึกเบิกรายชิ้น' ?></h2>
        <?php if ($editRow): ?>
        <?php $ei = $editRow['items'][0] ?? null; ?>
        <?php if (!$ei): ?>
            <p class="empty-state">ไม่พบรายละเอียด</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="update_stock_out" value="1">
            <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
            <div class="form-group">
                <label>อะไหล่</label>
                <input type="text" value="<?= e($ei['name']) ?>" readonly>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>จำนวนเบิก</label>
                    <input type="number" name="quantity" min="1" value="<?= (int) $ei['quantity'] ?>" required>
                </div>
                <div class="form-group">
                    <label>หมายเลขสินค้า (S/N)</label>
                    <input type="text" name="asset_code" value="<?= e($editRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024">
                </div>
            </div>
            <div class="form-group">
                <label>หมายเหตุ</label>
                <select name="note" required>
                    <?php foreach (getStockOutNoteOptions() as $option): ?>
                    <option value="<?= e($option) ?>" <?= ($editRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($editRow['part_movement_id'])): ?>
            <p class="sync-badge">🔗 เชื่อมกับ production (movement #<?= (int) $editRow['part_movement_id'] ?>)</p>
            <?php endif; ?>
            <div class="form-actions">
                <button type="submit" class="btn btn-danger">บันทึกการแก้ไข</button>
                <a href="<?= url('/pages/stock-out-item.php') ?>" class="btn btn-outline">ยกเลิก</a>
            </div>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <form method="POST">
            <div class="form-group">
                <label>ค้นหาอะไหล่</label>
                <input id="product-search" type="text" placeholder="พิมพ์ชื่อหรือรหัสอะไหล่เพื่อค้นหา" autocomplete="off">
            </div>
            <div class="form-group">
                <label>เลือกอะไหล่</label>
                <select id="product-select" name="product_id" required>
                    <option value="">-- เลือกอะไหล่ --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= $p['quantity'] <= 0 ? 'disabled' : '' ?>>
                        <?= e($p['name']) ?>
                        (คงเหลือ: <?= formatNumber($p['quantity']) ?> <?= e($p['unit']) ?>)
                        <?= $p['quantity'] <= 0 ? '(หมด)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>จำนวนเบิก</label>
                    <input type="number" name="quantity" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label>หมายเลขสินค้า (S/N)</label>
                    <input type="text" name="asset_code" placeholder="เช่น BP26072024">
                </div>
            </div>
            <p class="form-hint">ระบุ S/N ถ้าเบิกไปใช้กับเครื่องสินค้า</p>
            <div class="form-group">
                <label>หมายเหตุ</label>
                <select name="note" required>
                    <option value="">-- เลือกหมายเหตุ --</option>
                    <?php foreach (getStockOutNoteOptions() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-danger">ยืนยันเบิกออก</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>ประวัติเบิกรายชิ้นล่าสุด</h2>
        <?php if (empty($history)): ?>
            <p class="empty-state">ยังไม่มีประวัติ</p>
        <?php else: ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>เลขที่</th>
                    <th>อะไหล่</th>
                    <th>S/N</th>
                    <th class="text-right">จำนวน</th>
                    <th>ผู้เบิก</th>
                    <th>หมายเหตุ</th>
                    <th>วันที่</th>
                    <th class="col-actions">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['doc_no']) ?></td>
                    <td>[<?= e($h['code']) ?>] <?= e($h['name']) ?></td>
                    <td><?= e($h['asset_code'] ?: '-') ?></td>
                    <td class="text-right text-danger">-<?= formatNumber($h['quantity']) ?> <?= e($h['unit']) ?></td>
                    <td><?= e($h['issued_by'] ?: '-') ?></td>
                    <td><?= e($h['note'] ?: '-') ?></td>
                    <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                    <td class="col-actions">
                        <div class="table-actions">
                            <?= actionIcon('edit', url('/pages/stock-out-item.php?edit_out=' . (int) $h['stock_out_id']), 'แก้ไข') ?>
                            <form method="POST" onsubmit="return confirm('ลบรายการเบิกนี้? จะ sync กับ production ถ้ามีการเชื่อม')">
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
</div>

<script>
(function() {
    var searchInput = document.getElementById('product-search');
    var productSelect = document.getElementById('product-select');
    if (!searchInput || !productSelect) return;
    var originalOptions = Array.from(productSelect.options).map(function(option) {
        return { value: option.value, text: option.text, disabled: option.disabled };
    });
    searchInput.addEventListener('input', function() {
        var query = searchInput.value.trim().toLowerCase();
        productSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- เลือกอะไหล่ --';
        productSelect.appendChild(placeholder);
        originalOptions.forEach(function(optionData) {
            if (!optionData.value) return;
            if (!query || optionData.text.toLowerCase().includes(query)) {
                var option = document.createElement('option');
                option.value = optionData.value;
                option.textContent = optionData.text;
                if (optionData.disabled) option.disabled = true;
                productSelect.appendChild(option);
            }
        });
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
