<?php
/**
 * pages/year-end-summary.php — สรุปมูลค่าสต็อกสิ้นปี (จำนวนคงเหลือ × ราคา)
 *
 * แสดงอะไหล่ที่ quantity > 0 · ช่องราคาว่างถ้ายังไม่ตั้ง · บันทึกราคา · Export CSV
 *
 * Flow:
 *   bootstrap → POST/CSV (exit ก่อน HTML) → header + ตารางรายการ
 */

$pageTitle = 'สรุปยอดสิ้นปี';
require_once __DIR__ . '/../includes/parts_bootstrap.php';

ensureProductColumns($db);

/**
 * ตรวจว่ามีราคาที่ใช้คำนวณได้หรือไม่
 *
 * @param mixed $price
 * @return bool
 */
function year_end_has_price($price): bool
{
    return $price !== null && $price !== '' && (float) $price > 0;
}

/**
 * คำนวณสรุปจากแถวสินค้า
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{total_items:int,total_qty:int,total_value:float,missing_price:int,valued_items:int}
 */
function year_end_compute_summary(array $rows): array
{
    $summary = [
        'total_items'    => count($rows),
        'total_qty'      => 0,
        'total_value'    => 0.0,
        'missing_price'  => 0,
        'valued_items'   => 0,
    ];
    foreach ($rows as $row) {
        $qty = (int) ($row['quantity'] ?? 0);
        $summary['total_qty'] += $qty;
        if (!year_end_has_price($row['price'] ?? null)) {
            $summary['missing_price']++;
            continue;
        }
        $summary['valued_items']++;
        $summary['total_value'] += $qty * (float) $row['price'];
    }
    return $summary;
}

/**
 * ส่งไฟล์ CSV สรุปมูลค่าสต็อก (UTF-8 BOM) แล้วจบ request
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array{total_items:int,total_qty:int,total_value:float,missing_price:int,valued_items:int} $summary
 * @return void
 */
function year_end_export_csv(array $rows, array $summary): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="year-end-stock-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['รหัส', 'ชื่ออะไหล่', 'จำนวนคงเหลือ', 'หน่วย', 'ราคาต่อหน่วย', 'มูลค่ารวม', 'หมายเหตุ']);
    foreach ($rows as $row) {
        $qty = (int) $row['quantity'];
        $hasPrice = year_end_has_price($row['price'] ?? null);
        $price = $hasPrice ? (float) $row['price'] : null;
        $lineValue = $hasPrice ? $qty * $price : null;
        fputcsv($out, [
            $row['code'],
            parts_display_name($row),
            $qty,
            $row['unit'],
            $hasPrice ? number_format($price, 2, '.', '') : '',
            $lineValue !== null ? number_format($lineValue, 2, '.', '') : '',
            $hasPrice ? '' : 'ยังไม่มีราคา',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['สรุป', '', $summary['total_qty'], '', '', number_format($summary['total_value'], 2, '.', ''), '']);
    fputcsv($out, ['รายการในคลัง', $summary['total_items'], '', '', '', '', '']);
    fputcsv($out, ['ยังไม่มีราคา', $summary['missing_price'], '', '', '', '', '']);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices') {
    $prices = (array) ($_POST['price'] ?? []);
    $saved = 0;
    try {
        $db->beginTransaction();
        foreach ($prices as $pid => $raw) {
            $productId = (int) $pid;
            if ($productId <= 0) {
                continue;
            }
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (!is_numeric($raw)) {
                throw new InvalidArgumentException('ราคาต้องเป็นตัวเลข');
            }
            $stock->updateProductPrice($productId, (float) $raw);
            $saved++;
        }
        $db->commit();
        flash('success', $saved > 0 ? "บันทึกราคา {$saved} รายการแล้ว" : 'ไม่มีราคาใหม่ที่บันทึก');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('error', 'บันทึกราคาไม่สำเร็จ: ' . safe_exception_message($e));
    }
    redirect(url('/pages/year-end-summary.php'));
}

$rows = parts_enrich_products($stock->getStockValuationRows());
$summary = year_end_compute_summary($rows);
$reportYear = (int) date('Y') + 543;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    year_end_export_csv($rows, $summary);
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display:flex; flex-wrap:wrap; align-items:flex-start; justify-content:space-between; gap:12px">
    <div>
        <?= ui_heading('chart', 'สรุปยอดสิ้นปี', 'h1') ?>
        <p>มูลค่าสต็อกคงเหลือ ณ วันนี้ (พ.ศ. <?= e((string) $reportYear) ?>) — จำนวน × ราคาต่อหน่วย</p>
    </div>
    <div style="display:flex; flex-wrap:wrap; gap:8px">
        <a href="<?= url('/pages/year-end-summary.php?export=csv') ?>" class="btn btn-outline"><?= ui_icon_html('download', 16, 'btn-svg') ?> Export CSV</a>
        <a href="<?= e(ui_finishgoogs_app_url()) ?>" class="btn btn-outline">← Dashboard รวม</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="label">รายการในคลัง</div>
        <div class="value"><?= formatNumber($summary['total_items']) ?></div>
    </div>
    <div class="stat-card success">
        <div class="label">จำนวนชิ้นรวม</div>
        <div class="value"><?= formatNumber($summary['total_qty']) ?></div>
    </div>
    <div class="stat-card">
        <div class="label">มูลค่ารวม (มีราคาแล้ว)</div>
        <div class="value"><?= formatCurrency($summary['total_value']) ?></div>
    </div>
    <div class="stat-card <?= $summary['missing_price'] > 0 ? 'warning' : 'success' ?>">
        <div class="label">ยังไม่มีราคา</div>
        <div class="value"><?= formatNumber($summary['missing_price']) ?></div>
    </div>
</div>

<?php if ($summary['missing_price'] > 0): ?>
<div class="card" style="margin-bottom:16px; border-left:4px solid var(--warning)">
    <p style="margin:0; font-size:13px; color:var(--warning)">
        มี <?= formatNumber($summary['missing_price']) ?> รายการที่ยังไม่มีราคา — กรอกในตารางด้านล่างแล้วกด <b>บันทึกราคา</b> มูลค่ารวมจะอัปเดตทันที
    </p>
</div>
<?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="action" value="save_prices">
    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px">
        <?= ui_heading('clipboard', 'รายการอะไหล่คงเหลือ', 'h2') ?>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('save', 16, 'btn-svg') ?> บันทึกราคา</button>
    </div>
    <?php if (empty($rows)): ?>
        <p class="empty-state">ไม่มีอะไหล่คงเหลือในคลัง</p>
    <?php else: ?>
    <div class="table-wrap table-wrap-fold">
        <table>
            <thead>
                <tr>
                    <th>รหัส</th>
                    <th>ชื่ออะไหล่</th>
                    <th class="text-right">คงเหลือ</th>
                    <th class="text-right">ราคา/หน่วย</th>
                    <th class="text-right">มูลค่ารวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $qty = (int) $row['quantity'];
                    $hasPrice = year_end_has_price($row['price'] ?? null);
                    $priceVal = $hasPrice ? (float) $row['price'] : null;
                    $lineTotal = $hasPrice ? $qty * $priceVal : null;
                ?>
                <tr class="<?= $hasPrice ? '' : 'row-missing-price' ?>">
                    <td><?= e($row['code']) ?></td>
                    <td>
                        <a href="<?= url('/pages/product-detail.php?id=' . (int) $row['id']) ?>"><?= e(parts_display_name($row)) ?></a>
                    </td>
                    <td class="text-right"><?= formatNumber($qty) ?> <?= e($row['unit']) ?></td>
                    <td class="text-right" style="min-width:120px">
                        <input type="number"
                               name="price[<?= (int) $row['id'] ?>]"
                               value="<?= $hasPrice ? e(number_format($priceVal, 2, '.', '')) : '' ?>"
                               min="0"
                               step="0.01"
                               placeholder="กรอกราคา"
                               class="price-input"
                               style="width:110px; text-align:right">
                    </td>
                    <td class="text-right">
                        <?php if ($lineTotal !== null): ?>
                            <strong><?= formatCurrency($lineTotal) ?></strong>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--bg); font-weight:600">
                    <td colspan="2">รวม (เฉพาะรายการที่มีราคา)</td>
                    <td class="text-right"><?= formatNumber($summary['total_qty']) ?></td>
                    <td></td>
                    <td class="text-right"><?= formatCurrency($summary['total_value']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</form>

<style>
.row-missing-price { background: var(--warning-soft); }
.row-missing-price .price-input { border-color: var(--warning); }
.price-input {
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 14px;
    font-family: inherit;
}
.price-input:focus { outline: none; border-color: var(--border-focus); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
