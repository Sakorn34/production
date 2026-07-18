<?php
 
$pageTitle = 'เบิกรายชิ้น';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $docNo = $stock->stockOutItem(
            (int) $_POST['product_id'],
            (int) $_POST['quantity'],
            validateStockOutNote($_POST['note'] ?? null),
            trim($line_name ?? '') ?: null
        );
        flash('success', "เบิกออกเรียบร้อย เลขที่: {$docNo}");
    } catch (Exception $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('/pages/stock-out-item.php'));
}

$products = $stock->getAllProducts();
$history = $stock->getSingleItemOutHistory(20);
?>

<div class="page-header">
    <h1>📤 เบิกรายชิ้น</h1>
    <p>เบิกอะไหล่ออกทีละรายการ เช่น ปลั๊กไฟ 3 ขา 1 ชิ้น</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>บันทึกเบิกรายชิ้น</h2>
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
            <div class="form-group">
                <label>จำนวนเบิก</label>
                <input type="number" name="quantity" min="1" value="1" required>
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
        <h2>ประวัติเบิกรายชิ้นล่าสุด</h2>
        <?php if (empty($history)): ?>
            <p class="empty-state">ยังไม่มีประวัติ</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>เลขที่</th>
                    <th>อะไหล่</th>
                    <th class="text-right">จำนวน</th>
                    <th>ผู้เบิก</th>
                    <th>หมายเหตุ</th>
                    <th>วันที่</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e($h['doc_no']) ?></td>
                    <td>[<?= e($h['code']) ?>] <?= e($h['name']) ?></td>
                    <td class="text-right text-danger">-<?= formatNumber($h['quantity']) ?> <?= e($h['unit']) ?></td>
                    <td><?= e($h['issued_by'] ?: '-') ?></td>
                    <td><?= e($h['note'] ?: '-') ?></td>
                    <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

    <script>
        (function() {
            var searchInput = document.getElementById('product-search');
            var productSelect = document.getElementById('product-select');
            if (!searchInput || !productSelect) return;

            var originalOptions = Array.from(productSelect.options).map(function(option) {
                return {
                    value: option.value,
                    text: option.text,
                    disabled: option.disabled
                };
            });

            function filterOptions() {
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
            }

            searchInput.addEventListener('input', filterOptions);
        })();
    </script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
