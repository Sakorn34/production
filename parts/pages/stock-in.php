<?php
 
$pageTitle = 'รับเข้า';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ensure stock_in.received_by column exists
        try {
            ensureStockInColumns($db);
        } catch (Exception $ex) {
            // ignore migration error
        }
        $stock->stockIn(
            (int) $_POST['product_id'],
            (int) $_POST['quantity'],
            trim($_POST['note'] ?? '') ?: null,
            $line_name
        );
        flash('success', 'บันทึกรับเข้าเรียบร้อย');
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-in.php'));
}

$products = $stock->getAllProducts();
$history = $stock->getStockInHistory(20);
?>

<div class="page-header">
    <h1>📥 รับเข้า</h1>
    <p>บันทึกการรับอะไหล่เข้าคลัง</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>บันทึกรับเข้า</h2>
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
            <div class="form-group">
                <label>จำนวนรับเข้า</label>
                <input type="number" name="quantity" min="1" required>
            </div>
            <div class="form-group">
                <label>หมายเหตุ</label>
                <textarea name="note" rows="2" placeholder="รายล่ะเอียด.."></textarea>
            </div>
            <button type="submit" class="btn btn-success">บันทึกรับเข้า</button>
        </form>
    </div>

    <div class="card">
        <h2>ประวัติรับเข้าล่าสุด</h2>
        <?php if (empty($history)): ?>
            <p class="empty-state">ยังไม่มีประวัติ</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>อะไหล่</th>
                    <th>ผู้รับ</th>
                    <th class="text-right">จำนวน</th>
                    <th>หมายเหตุ</th>
                    <th>วันที่</th>
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
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
