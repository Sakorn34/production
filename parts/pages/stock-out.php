<?php
 
$pageTitle = 'เบิกออก';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $docNo = $stock->stockOutBySet(
            (int) $_POST['set_id'],
            (int) $_POST['set_count'],
            validateStockOutNote($_POST['note'] ?? null),
            trim($line_name ?? '') ?: null
        );
        flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-out.php'));
}

$sets = $stock->getAllSetsWithItems();
?>

<div class="page-header">
    <h1>📤 เบิกออก (Set)</h1>
    <p>เบิกอะไหล่ออกเป็น Set ชุด</p>
</div>

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
            <div class="form-group">
                <label>จำนวนชุดที่เบิก</label>
                <input type="number" name="set_count" min="1" value="1" required>
            </div>
            <!-- ผู้เบิก ถูกกำหนดอัตโนมัติตามผู้ใช้งาน (session) -->
            <div class="form-group">
                <label>หมายเหตุ</label>
                <select name="note" required>
                    <option value="">-- เลือกหมายเหตุ --</option>
                    <?php foreach (getStockOutNoteOptions() as $option): ?>
                    <option value="<?= e($option) ?>"><?= e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-danger">ยืนยันเบิกออก</button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
