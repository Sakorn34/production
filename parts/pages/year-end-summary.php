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
 * @return array{total_items:int,total_qty:float,total_value:float,missing_price:int,valued_items:int}
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
        $qty = qty_norm($row['quantity'] ?? 0);
        $summary['total_qty'] = qty_norm($summary['total_qty'] + $qty);
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
        $qty = qty_norm($row['quantity']);
        $hasPrice = year_end_has_price($row['price'] ?? null);
        $price = $hasPrice ? (float) $row['price'] : null;
        $lineValue = $hasPrice ? $qty * $price : null;
        fputcsv($out, [
            $row['code'],
            parts_display_name($row),
            qty_input_value($qty),
            $row['unit'],
            $hasPrice ? number_format($price, 2, '.', '') : '',
            $lineValue !== null ? number_format($lineValue, 2, '.', '') : '',
            $hasPrice ? '' : 'ยังไม่มีราคา',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['สรุป', '', qty_input_value($summary['total_qty']), '', '', number_format($summary['total_value'], 2, '.', ''), '']);
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
// รูปอะไหล่ — อ่านทีเดียวทั้งชุด ไม่ถามทีละแถว
$rowIcons = parts_product_icon_map($rows);
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


<form method="post" class="card">
    <input type="hidden" name="action" value="save_prices">
    <?php
        $nMissing = 0;
        foreach ($rows as $r) { if (!year_end_has_price($r['price'] ?? null)) { $nMissing++; } }
        $nHas = count($rows) - $nMissing;
    ?>
    <?php // หัวการ์ด: ชื่อเรื่อง + ชิปกรอง + ปุ่มบันทึก อยู่บรรทัดเดียวกัน (ตกบรรทัดเองเมื่อจอแคบ)
          // กรองด้วย JS ไม่โหลดหน้าใหม่ — แถวที่ซ่อนยังอยู่ในฟอร์ม ราคาที่พิมพ์ค้างไว้จึงไม่หาย ?>
    <div class="ye-head">
        <?= ui_heading('clipboard', 'รายการอะไหล่คงเหลือ', 'h2') ?>
        <?php if (!empty($rows)): ?>
        <div class="ye-chip-row" role="group" aria-label="กรองรายการ">
            <button type="button" class="ye-chip is-active" data-ye-filter="all">ทั้งหมด <b><?= formatNumber(count($rows)) ?></b></button>
            <button type="button" class="ye-chip" data-ye-filter="none">ยังไม่มีราคา <b><?= formatNumber($nMissing) ?></b></button>
            <button type="button" class="ye-chip" data-ye-filter="has">ใส่ราคาแล้ว <b><?= formatNumber($nHas) ?></b></button>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary"><?= ui_icon_html('save', 16, 'btn-svg') ?> บันทึกราคา</button>
    </div>
    <?php if (empty($rows)): ?>
        <p class="empty-state">ไม่มีอะไหล่คงเหลือในคลัง</p>
    <?php else: ?>
    <div class="ye-filter-bar">
        <label class="ye-vendor-label" for="ye-vendor-select">ผู้จำหน่าย</label>
        <select id="ye-vendor-select" class="ye-vendor-select">
            <option value="">ทั้งหมด</option>
            <!-- JS เติม option ผู้จำหน่ายจริงให้เอง -->
        </select>
        <p class="ye-chip-note muted" id="ye-filter-note" hidden></p>
    </div>
    <div class="table-wrap table-wrap-fold">
        <table class="ye-table">
            <colgroup>
                <col style="width:60px"><!-- รูป -->
                <col><!-- ชื่ออะไหล่ — ยืด/หดตามที่เหลือ -->
                <col style="width:14%"><!-- ผู้จำหน่าย -->
                <col style="width:11%"><!-- คงเหลือ -->
                <col style="width:16%"><!-- ราคา/หน่วย -->
                <col style="width:11%"><!-- มูลค่ารวม -->
            </colgroup>
            <thead>
                <tr>
                    <th class="col-img">รูป</th>
                    <th>ชื่ออะไหล่</th>
                    <th class="ye-sort-th" data-ye-sort="supplier">
                        ผู้จำหน่าย <span class="ye-sort-icon" aria-hidden="true">⇅</span>
                    </th>
                    <th class="text-right">คงเหลือ</th>
                    <th class="text-right">ราคา/หน่วย</th>
                    <th class="text-right">มูลค่ารวม</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row):
                    $qty = qty_norm($row['quantity']);
                    $hasPrice = year_end_has_price($row['price'] ?? null);
                    $priceVal = $hasPrice ? (float) $row['price'] : null;
                    $lineTotal = $hasPrice ? $qty * $priceVal : null;
                ?>
                <tr class="<?= $hasPrice ? '' : 'row-missing-price' ?>" data-ye-price="<?= $hasPrice ? 'has' : 'none' ?>">
                    <?php // รูปแทนคอลัมน์รหัส — กวาดตาหาอะไหล่จากรูปเร็วกว่าอ่านรหัสทีละบรรทัด
                          // รหัสยังอยู่ใน title ของรูป ชี้เมาส์ดูได้ และยังอยู่ในไฟล์ CSV ที่ export
                          $rowIcon = $rowIcons[$row['code']] ?? ''; ?>
                    <td class="col-img" title="<?= e($row['code']) ?>">
                        <?php if ($rowIcon !== ''): ?>
                            <?= parts_photo_button_html(parts_img_tag($rowIcon, parts_display_name($row)), $rowIcon, $row, 'parts-photo-btn-cell', false) ?>
                        <?php else: ?>
                            <span class="ye-noimg" aria-hidden="true"></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= url('/pages/product-detail.php?id=' . (int) $row['id']) ?>" target="_blank" rel="noopener noreferrer" title="เปิดโปรไฟล์อะไหล่ในแท็บใหม่"><?= e(parts_display_name($row)) ?></a>
                    </td>
                    <td class="ye-supplier" data-supplier="<?= e($row['supplier'] ?? '') ?>"><?= e($row['supplier'] ?? '—') ?></td>
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
                <tr class="ye-foot-row" style="background:var(--bg); font-weight:600">
                    <td colspan="3">รวม (เฉพาะรายการที่มีราคา)</td>
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
/* ชิปกรองรายการ — หน้าตาเดียวกับชิปในหน้าสั่งซื้ออะไหล่หลัก */
.ye-filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
.ye-vendor-label { font-size: calc(12px * var(--font-scale, 1)); color: var(--muted-text, #6b7280); white-space: nowrap; }
.ye-vendor-select {
    font: inherit; font-size: calc(12px * var(--font-scale, 1));
    border: 1px solid var(--border, #e8e4f2); border-radius: 8px;
    padding: 4px 28px 4px 10px; background: var(--card, #fff);
    color: inherit; cursor: pointer;
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23888' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    min-width: 150px; max-width: 220px;
}
.ye-vendor-select:focus { outline: none; border-color: var(--primary, #e11d74); }
.ye-vendor-select.is-active { border-color: var(--primary, #e11d74); box-shadow: inset 0 0 0 1px var(--primary, #e11d74); }
#ye-filter-note { margin: 0; }
.ye-table { table-layout: fixed; width: 100%; }
.ye-sort-th { cursor: pointer; user-select: none; white-space: nowrap; }
.ye-sort-th:hover { color: var(--primary, #e11d74); }
.ye-sort-th[data-sort-asc] .ye-sort-icon::after { content: " ▲"; font-size: 10px; }
.ye-sort-th[data-sort-desc] .ye-sort-icon::after { content: " ▼"; font-size: 10px; }
.ye-sort-icon { font-size: 11px; color: var(--muted, #9ca3af); margin-left: 2px; }
.ye-sort-th[data-sort-asc] .ye-sort-icon,
.ye-sort-th[data-sort-desc] .ye-sort-icon { color: var(--primary, #e11d74); }
.ye-supplier { max-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ye-table td, .ye-table th { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ye-table td.col-img { text-overflow: clip; overflow: visible; white-space: normal; }
.ye-table td:nth-child(2), .ye-table th:nth-child(2) { white-space: normal; } /* ชื่อตัดบรรทัดได้ */
.ye-noimg {
    display: inline-block; width: 40px; height: 40px; border-radius: 8px;
    border: 1px dashed var(--border, #e5e7eb); background: var(--bg, #f8fafc);
}
#ye-filter-note { margin: -2px 0 10px; }
.ye-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; }
.ye-head > h2 { margin: 0; }
/* ชิปยุบลงบรรทัดใหม่ในตัวเองเมื่อที่ไม่พอ ปุ่มบันทึกจึงอยู่ท้ายบรรทัดเดิมเสมอ */
.ye-head > .ye-chip-row { flex: 0 1 auto; min-width: 0; }
.ye-head > .btn { margin-left: auto; flex: 0 0 auto; }
.ye-chip-row { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.ye-chip {
    cursor: pointer; font: inherit; background: var(--card, #fff);
    border: 1px solid var(--border, #e8e4f2); border-radius: 999px;
    padding: 5px 8px; font-size: calc(12px * var(--font-scale, 1)); flex: 0 0 auto;
    font-variant-numeric: tabular-nums; color: inherit;
}
.ye-chip:hover { border-color: var(--primary, #e11d74); background: var(--primary-soft, #fce7f3); }
.ye-chip.is-active { border-color: var(--primary, #e11d74); box-shadow: inset 0 0 0 1px var(--primary, #e11d74); background: var(--primary-soft, #fce7f3); }
.ye-chip b { color: var(--primary, #e11d74); margin-left: 2px; }
.ye-chip-note { font-size: calc(12px * var(--font-scale, 1)); }
tr[data-ye-price].is-hidden { display: none; }
.ye-foot-row.is-dim td { opacity: .6; }
</style>

<script>
(function () {
    var chips   = [].slice.call(document.querySelectorAll('[data-ye-filter]'));
    var rows    = [].slice.call(document.querySelectorAll('tr[data-ye-price]'));
    var note    = document.getElementById('ye-filter-note');
    var foot    = document.querySelector('.ye-foot-row');
    var selEl   = document.getElementById('ye-vendor-select');
    var PKEY    = 'parts_year_end_filter';
    var VKEY    = 'parts_ye_vendor';

    // ── สร้าง option จากผู้จำหน่ายที่มีจริงในข้อมูล ──
    if (selEl) {
        var uniqVendors = [];
        rows.forEach(function (tr) {
            var v = (tr.querySelector('[data-supplier]') || {}).getAttribute ? tr.querySelector('[data-supplier]').getAttribute('data-supplier') : '';
            if (v && v !== '—' && uniqVendors.indexOf(v) < 0) { uniqVendors.push(v); }
        });
        uniqVendors.sort(function (a, b) { return a.localeCompare(b, 'th'); });
        uniqVendors.forEach(function (v) {
            var opt = document.createElement('option'); opt.value = v; opt.textContent = v;
            selEl.appendChild(opt);
        });
        // คืนค่าที่บันทึกไว้
        try { var sv = localStorage.getItem(VKEY); if (sv) { selEl.value = sv; } } catch (e) {}
    }

    var currentPrice  = 'all';
    var currentVendor = selEl ? selEl.value : '';

    function applyFilters() {
        var shown = 0;
        rows.forEach(function (tr) {
            var priceOk  = currentPrice === 'all' || tr.getAttribute('data-ye-price') === currentPrice;
            var vendorOk = !currentVendor;
            if (!vendorOk) {
                var cell = tr.querySelector('[data-supplier]');
                vendorOk = cell && cell.getAttribute('data-supplier') === currentVendor;
            }
            var hit = priceOk && vendorOk;
            tr.classList.toggle('is-hidden', !hit);
            if (hit) { shown++; }
        });
        chips.forEach(function (c) {
            c.classList.toggle('is-active', c.getAttribute('data-ye-filter') === currentPrice);
        });
        if (selEl) { selEl.classList.toggle('is-active', !!currentVendor); }
        var isFiltered = currentPrice !== 'all' || !!currentVendor;
        if (note) {
            note.hidden = !isFiltered;
            if (isFiltered) {
                var parts = [];
                if (currentPrice !== 'all') { parts.push(currentPrice === 'none' ? 'ยังไม่มีราคา' : 'ใส่ราคาแล้ว'); }
                if (currentVendor) { parts.push(currentVendor); }
                note.textContent = 'แสดง ' + shown + ' รายการ' + (parts.length ? ' (' + parts.join(', ') + ')' : '') + ' · ยอดรวมท้ายตารางยังเป็นของทั้งคลัง';
            }
        }
        if (foot) { foot.classList.toggle('is-dim', isFiltered); }
        try { localStorage.setItem(PKEY, currentPrice); } catch (e) {}
        try { if (currentVendor) { localStorage.setItem(VKEY, currentVendor); } else { localStorage.removeItem(VKEY); } } catch (e) {}
    }

    chips.forEach(function (c) {
        c.addEventListener('click', function () { currentPrice = c.getAttribute('data-ye-filter'); applyFilters(); });
    });
    if (selEl) {
        selEl.addEventListener('change', function () { currentVendor = selEl.value; applyFilters(); });
    }

    // คืนสถานะจาก localStorage
    try { var sp = localStorage.getItem(PKEY) || 'all'; currentPrice = ['all','none','has'].indexOf(sp) >= 0 ? sp : 'all'; } catch (e) {}
    applyFilters();
})();

// ── sort ตามผู้จำหน่าย ──
(function () {
    var SORT_KEY = 'parts_ye_sort';
    var sortThs = [].slice.call(document.querySelectorAll('[data-ye-sort]'));
    if (!sortThs.length) { return; }
    var tbody = document.querySelector('.ye-table tbody');
    if (!tbody) { return; }

    var sortDir = { supplier: 0 }; // 0=ไม่ sort, 1=asc, -1=desc
    try {
        var saved = JSON.parse(localStorage.getItem(SORT_KEY) || 'null');
        if (saved && saved.col && saved.dir) { sortDir[saved.col] = saved.dir; }
    } catch (e) {}

    function getVal(tr, col) {
        var cell = tr.querySelector('[data-' + col + ']');
        return cell ? cell.getAttribute('data-' + col).toLowerCase() : '';
    }

    function doSort(col) {
        var rows = [].slice.call(tbody.querySelectorAll('tr[data-ye-price]'));
        // สลับ asc → desc → ไม่ sort
        sortDir[col] = sortDir[col] === 1 ? -1 : sortDir[col] === -1 ? 0 : 1;
        sortThs.forEach(function (th) {
            var c = th.getAttribute('data-ye-sort');
            th.removeAttribute('data-sort-asc');
            th.removeAttribute('data-sort-desc');
            if (c === col && sortDir[col] !== 0) {
                th.setAttribute(sortDir[col] === 1 ? 'data-sort-asc' : 'data-sort-desc', '');
            }
        });
        if (sortDir[col] === 0) {
            // กลับลำดับ a-z ตามชื่อ (ลำดับต้นฉบับ PHP)
            rows.sort(function (a, b) {
                var na = (a.querySelector('td:nth-child(2) a') || {}).textContent || '';
                var nb = (b.querySelector('td:nth-child(2) a') || {}).textContent || '';
                return na.localeCompare(nb, 'th');
            });
        } else {
            rows.sort(function (a, b) {
                var va = getVal(a, col), vb = getVal(b, col);
                // "—" (ไม่มีผู้จำหน่าย) ไปท้ายเสมอ
                if (!va || va === '—') { return 1; }
                if (!vb || vb === '—') { return -1; }
                return va.localeCompare(vb, 'th') * sortDir[col];
            });
        }
        rows.forEach(function (tr) { tbody.appendChild(tr); });
        try { localStorage.setItem(SORT_KEY, JSON.stringify({ col: col, dir: sortDir[col] })); } catch (e) {}
    }

    sortThs.forEach(function (th) {
        th.addEventListener('click', function () { doSort(th.getAttribute('data-ye-sort')); });
    });

    // คืนสถานะ sort ที่บันทึกไว้
    Object.keys(sortDir).forEach(function (col) {
        if (sortDir[col] !== 0) {
            // reset ก่อน sort จริง ให้ doSort เลือก dir เอง
            var want = sortDir[col];
            sortDir[col] = want === 1 ? 0 : 1; // ก่อนสลับ ให้ผล want
            doSort(col);
        }
    });
})();
</script>

<?php parts_photo_modal_html(); ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
