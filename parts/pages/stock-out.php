<?php

$pageTitle = 'เบิกออก';
require_once __DIR__ . '/../includes/header.php';

$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
$editRow = $editOut ? $stock->getStockOutDetail($editOut) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['delete_stock_out'])) {
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
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-out.php'));
}

$sets = $stock->getAllSetsWithItems();
$history = $stock->getSetOutHistory(20);
?>

<div class="page-header">
    <?= ui_heading('stock-out-set', 'เบิกออก (Set)', 'h1') ?>
    <p>เบิกอะไหล่ออกเป็น Set ชุด — ระบุ S/N ได้ถ้าเบิกไปใช้กับเครื่อง</p>
</div>

<?php if ($editRow && !empty($editRow['set_id'])): ?>
<div class="card" style="margin-bottom:1.25rem">
    <h2>แก้ไขรายการเบิก Set — <?= e($editRow['doc_no']) ?></h2>
    <form method="POST">
        <input type="hidden" name="update_stock_out_meta" value="1">
        <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
        <div class="form-group">
            <label>ชุดเบิก</label>
            <input type="text" value="[<?= e($editRow['set_code']) ?>] <?= e($editRow['set_name']) ?>" readonly>
        </div>
        <div class="form-group">
            <label>หมายเลขสินค้า (S/N)</label>
            <input type="text" name="asset_code" value="<?= e($editRow['asset_code'] ?? '') ?>" placeholder="เช่น BP26072024">
        </div>
        <div class="form-group">
            <label>หมายเหตุ</label>
            <select name="note" required>
                <?php foreach (getStockOutNoteOptions() as $option): ?>
                <option value="<?= e($option) ?>" <?= ($editRow['note'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">บันทึกการแก้ไข</button>
            <a href="<?= url('/pages/stock-out.php') ?>" class="btn btn-outline">ยกเลิก</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="grid-2">
    <div class="card">
        <h2>เบิกออก Set</h2>
        <form method="POST">
            <div class="form-group">
                <label>เลือก Set</label>
                <select name="set_id" id="setSelect" required>
                    <option value="">-- เลือก Set --</option>
                    <?php foreach ($sets as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= !$s['can_issue'] ? 'disabled' : '' ?>>
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
                    <label>หมายเลขสินค้า (S/N)</label>
                    <input type="text" name="asset_code" placeholder="เช่น BP26072024">
                </div>
            </div>
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
    </div>

    <div class="card">
        <h2>รายละเอียด Set</h2>
        <?php foreach ($sets as $s): ?>
        <div class="set-card <?= !$s['can_issue'] ? 'disabled' : '' ?>">
            <strong>[<?= e($s['code']) ?>] <?= e($s['name']) ?></strong>
            <?php if ($s['description']): ?>
                <p class="text-muted" style="margin: 0.25rem 0;"><?= e($s['description']) ?></p>
            <?php endif; ?>
            <ul class="set-items-list">
                <?php foreach ($s['items'] as $item): ?>
                <li>
                    • <?= e($item['name']) ?> × <?= formatNumber($item['quantity']) ?> <?= e($item['unit']) ?>
                    (คงเหลือ: <?= formatNumber($item['stock_qty']) ?>)
                    <?php if ($item['stock_qty'] < $item['quantity']): ?>
                        <span class="text-danger">ไม่พอ</span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($s['can_issue']): ?>
                <span class="badge badge-success">พร้อมเบิก</span>
            <?php else: ?>
                <span class="badge badge-danger">สต็อกไม่พอ</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:1.25rem">
    <h2>ประวัติเบิก Set ล่าสุด</h2>
    <?php if (empty($history)): ?>
        <p class="empty-state">ยังไม่มีประวัติ</p>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>เลขที่</th>
                <th>Set</th>
                <th>S/N</th>
                <th class="text-right">จำนวนรวม</th>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
