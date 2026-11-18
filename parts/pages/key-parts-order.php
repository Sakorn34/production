<?php
/**
 * pages/key-parts-order.php — อะไหล่สั่งต่างประเทศ (UX-T2)
 *
 * 3 อะไหล่คีย์ · ยอดเบิกแยกใช้ผลิต/ซ่อม/อื่น · มุมมองรายเดือนตามปีปฏิทิน
 * ยอดเบิกจาก stock_out ใน inventory · คงเหลือ/ขั้นต่ำจาก products · drill-down รายเดือน
 *
 * Flow: bootstrap → aggregate (cache) → ตาราง → drill-down
 */

$pageTitle = 'อะไหล่สั่งต่างประเทศ';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/key_parts_order.php';

$yearIn = (int) ($_GET['year'] ?? (int) date('Y'));
$eraIn = (string) ($_GET['era'] ?? 'be');
$partKeyIn = trim((string) ($_GET['part'] ?? ''));
$monthIn = (int) ($_GET['month'] ?? 0);
$filterIn = key_parts_order_normalize_filter((string) ($_GET['filter'] ?? 'all'));

if ($yearIn < 2000 || $yearIn > 2100) {
    $yearIn = (int) date('Y');
}
$yearChoices = key_parts_order_year_choices();
if (!in_array($yearIn, $yearChoices, true)) {
    $yearChoices[] = $yearIn;
    rsort($yearChoices, SORT_NUMERIC);
}
if ($eraIn !== 'ce') {
    $eraIn = 'be';
}
if ($monthIn < 1 || $monthIn > 12) {
    $monthIn = 0;
}

$avgMosRaw = trim((string) ($_GET['avg_mos'] ?? ''));
$avgMonthsIn = key_parts_order_parse_avg_months($avgMosRaw !== '' ? $avgMosRaw : null, $yearIn);

$data = key_parts_order_load_cached($yearIn);
$items = $data['items'] ?? [];
$byPart = $data['by_part'] ?? [];
$inByPart = $data['in_by_part'] ?? [];
$yearInTotal = qty_norm($data['year_in_total'] ?? 0);
$totals = $data['totals'] ?? key_parts_order_empty_buckets();
$yearTotal = key_parts_order_bucket_total($totals);

$itemByKey = [];
foreach ($items as $it) {
    $itemByKey[(string) $it['key']] = $it;
}

$drillItem = ($partKeyIn !== '' && isset($itemByKey[$partKeyIn])) ? $itemByKey[$partKeyIn] : null;
$drillMovements = [];
$drillMovementsRaw = [];
$kpoDrillRowPayload = [];
if ($drillItem && $monthIn > 0 && trim((string) ($drillItem['inv_log_part'] ?? '')) !== '') {
    $drillMovementsRaw = key_parts_order_movements(
        (string) $drillItem['inv_log_part'],
        $yearIn,
        $monthIn,
        (int) ($drillItem['inv_part_id'] ?? 0)
    );
    $drillMovements = key_parts_order_filter_movements($drillMovementsRaw, $filterIn);
}

$kpoFilterLabels = [
    'out'    => 'เบิกออก',
    'in'     => 'รับเข้า',
    'prod'   => KEY_PARTS_ORDER_BUCKETS['prod'],
    'repair' => KEY_PARTS_ORDER_BUCKETS['repair'],
    'other'  => KEY_PARTS_ORDER_BUCKETS['other'],
];

require_once __DIR__ . '/../includes/header.php';
parts_page_header(
    'chart',
    'อะไหล่สั่งต่างประเทศ',
    'สรุปเบิกออก/รับเข้าจาก inventory · คงเหลือและขั้นต่ำจาก stock overview'
);
?>

<div class="pc-page kpo-page kpo-layout-t2">
    <?php if (empty($data['ok'])): ?>
    <div class="alert alert-danger"><?= e((string) ($data['error'] ?? 'โหลดข้อมูลไม่สำเร็จ')) ?></div>
    <?php endif; ?>
    <?php if (!empty($data['inv_error'])): ?>
    <div class="alert alert-danger"><?= e((string) $data['inv_error']) ?> — ตรวจค่า <code>inventory</code> ใน finishgoogs.secrets.php บนเซิร์ฟเวอร์</div>
    <?php endif; ?>

    <div class="pc-tools kpo-tools kpo-tools-compact">
        <div class="kpo-tools-block kpo-tools-years">
            <div class="kpo-tools-head">
                <span class="pc-tool-label">ปีที่ดูยอด</span>
                <?php if ($eraIn === 'be'): ?>
                <span class="kpo-tools-current"><?= e(key_parts_order_year_label($yearIn, 'be')) ?> (<?= e((string) $yearIn) ?>)</span>
                <?php else: ?>
                <span class="kpo-tools-current"><?= e((string) $yearIn) ?> (<?= e(key_parts_order_year_label($yearIn, 'be')) ?>)</span>
                <?php endif; ?>
            </div>
            <div class="kpo-tools-year-row">
                <nav class="history-kind-legend history-tabs kpo-year-tabs" aria-label="ปี">
                    <?php foreach ($yearChoices as $y):
                        $lbl = $eraIn === 'ce' ? (string) $y : key_parts_order_year_label($y, 'be');
                        ?>
                    <a class="history-kind-filter<?= $yearIn === $y ? ' is-active' : '' ?>"
                       href="<?= e(key_parts_order_url(['year' => $y, 'era' => $eraIn, 'part' => null, 'month' => null, 'filter' => $filterIn !== 'all' ? $filterIn : null])) ?>"><?= e($lbl) ?></a>
                    <?php endforeach; ?>
                </nav>
                <nav class="history-kind-legend history-tabs kpo-era-tabs" aria-label="รูปแบบปี">
                    <a class="history-kind-filter<?= $eraIn === 'be' ? ' is-active' : '' ?>"
                       href="<?= e(key_parts_order_url(['year' => $yearIn, 'era' => 'be', 'part' => $partKeyIn ?: null, 'month' => $monthIn ?: null, 'filter' => $filterIn !== 'all' ? $filterIn : null])) ?>">พ.ศ.</a>
                    <a class="history-kind-filter<?= $eraIn === 'ce' ? ' is-active' : '' ?>"
                       href="<?= e(key_parts_order_url(['year' => $yearIn, 'era' => 'ce', 'part' => $partKeyIn ?: null, 'month' => $monthIn ?: null, 'filter' => $filterIn !== 'all' ? $filterIn : null])) ?>">ค.ศ.</a>
                </nav>
            </div>
            <p class="kpo-tools-meta muted">
                log_stock · <?= e(KEY_PARTS_ORDER_BUILD) ?>
                <?php if (!empty($data['inv_connected'])): ?>
                · inventory<?php if (isset($data['log_rows'])): ?> · <?= e(formatNumber((int) $data['log_rows'])) ?> แถว/ปี<?php endif; ?>
                <?php endif; ?>
                · แถบปี = ทุกปีที่มี movement ในรายการ
            </p>
        </div>
        <div class="kpo-tools-block kpo-tools-read">
            <span class="pc-tool-label">อ่านเซลล์</span>
            <p class="kpo-legend-inline"><span class="kpo-cell-flow-out-line"><span class="kpo-cell-flow-out">51</span><span class="kpo-cell-flow-approx">≈</span><span class="kpo-cell-flow-round">55</span></span> เบิก · <span class="kpo-cell-flow-in">500</span> รับ</p>
        </div>
    </div>

    <div class="kpo-tools-block kpo-tools-avg-mos">
        <div class="kpo-tools-head">
            <span class="pc-tool-label">เฉลี่ยจากเดือน</span>
            <span class="kpo-tools-current muted"><?= e(count($avgMonthsIn)) ?> เดือน · คลิกสลับ</span>
        </div>
        <div class="kpo-avg-mos-row" role="group" aria-label="เลือกเดือนสำหรับค่าเฉลี่ยต่อเดือน">
            <?php
            $avgUrlBase = [
                'year'   => $yearIn,
                'era'    => $eraIn,
                'part'   => $partKeyIn !== '' ? $partKeyIn : null,
                'month'  => $monthIn > 0 ? $monthIn : null,
                'filter' => $filterIn !== 'all' ? $filterIn : null,
            ];
            $defMonths = key_parts_order_default_avg_months($yearIn);
            $allMonths = range(1, 12);
            ?>
            <a class="kpo-avg-mos-preset<?= $avgMonthsIn === $defMonths ? ' is-active' : '' ?>"
               href="<?= e(key_parts_order_url(array_merge($avgUrlBase, ['avg_mos' => null]))) ?>">ถึงเดือนนี้</a>
            <a class="kpo-avg-mos-preset<?= $avgMonthsIn === $allMonths ? ' is-active' : '' ?>"
               href="<?= e(key_parts_order_url(array_merge($avgUrlBase, ['avg_mos' => key_parts_order_avg_mos_param($allMonths)]))) ?>">ทั้ง 12</a>
            <span class="kpo-avg-mos-sep" aria-hidden="true">|</span>
            <?php foreach (KEY_PARTS_ORDER_MONTHS as $mo => $ml):
                $on = in_array($mo, $avgMonthsIn, true);
                $next = key_parts_order_toggle_avg_month($avgMonthsIn, $mo);
                $nextParam = key_parts_order_avg_mos_url_value($next, $yearIn);
                ?>
            <a class="kpo-avg-mos-chip<?= $on ? ' is-on' : '' ?>"
               href="<?= e(key_parts_order_url(array_merge($avgUrlBase, ['avg_mos' => $nextParam]))) ?>"
               title="<?= e($on ? 'เอา ' . $ml . ' ออกจากเฉลี่ย' : 'รวม ' . $ml . ' ในเฉลี่ย') ?>"><?= e($ml) ?></a>
            <?php endforeach; ?>
        </div>
        <p class="muted kpo-avg-mos-hint">ยอด <b>รวมปี</b> ยังเป็นทั้งปี · ตัวเลข <b>เฉลี่ย/เดือน</b> ใช้เฉพาะเดือนที่เลือก</p>
    </div>

    <div class="kpo-chip-row" role="group" aria-label="ตัวกรองสรุปปี">
        <?php
        $chipDefs = [
            'all'   => ['label' => 'ทั้งหมด', 'qty' => null, 'cls' => ''],
            'out'   => ['label' => 'เบิกออก', 'qty' => $yearTotal, 'cls' => 'kpo-flow-out'],
            'in'    => ['label' => 'รับเข้า', 'qty' => $yearInTotal, 'cls' => 'kpo-flow-in'],
            'prod'  => ['label' => 'ใช้ผลิต', 'qty' => qty_norm($totals['prod'] ?? 0), 'cls' => 'kpo-bucket-prod'],
            'repair'=> ['label' => 'ซ่อม', 'qty' => qty_norm($totals['repair'] ?? 0), 'cls' => 'kpo-bucket-repair'],
            'other' => ['label' => 'อื่นๆ', 'qty' => qty_norm($totals['other'] ?? 0), 'cls' => 'kpo-bucket-other'],
        ];
        foreach ($chipDefs as $fk => $chip):
            if ($fk === 'in' && $chip['qty'] <= 0) {
                continue;
            }
            $active = ($filterIn === $fk) || ($filterIn === 'all' && $fk === 'all');
            ?>
        <a class="kpo-chip kpo-chip-filter<?= $active ? ' is-active' : '' ?> <?= e((string) $chip['cls']) ?>"
           href="<?= e(key_parts_order_url(['year' => $yearIn, 'era' => $eraIn, 'part' => $partKeyIn ?: null, 'month' => $monthIn ?: null, 'filter' => $fk === 'all' ? null : $fk])) ?>">
            <?= e((string) $chip['label']) ?><?php if ($chip['qty'] !== null): ?> <b><?= e(formatNumber($chip['qty'])) ?></b><?php endif; ?>
        </a>
        <?php endforeach; ?>
        <button type="button" class="kpo-chip kpo-chip-manage btn-with-icon" data-open-modal="kpo-catalog-modal" title="เพิ่มหรือลบรายการที่สรุปในหน้านี้">
            <?= ui_icon_html('plus', 14, 'btn-svg') ?> จัดการรายการ
        </button>
    </div>

    <div class="card table-wrap kpo-month-wrap">
        <table class="kpo-month-table">
            <thead>
                <tr>
                    <th scope="col" class="kpo-part">อะไหล่</th>
                    <?php foreach (KEY_PARTS_ORDER_MONTHS as $mo => $ml): ?>
                    <th scope="col"><?= e($ml) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it):
                    $k = (string) $it['key'];
                    $months = $byPart[$k] ?? [];
                    ?>
                <tr>
                    <th scope="row" class="kpo-part">
                        <?php
                        key_parts_order_echo_part_column($it, 't2', [
                            'filter'      => $filterIn,
                            'year'        => $yearIn,
                            'avg_months'  => $avgMonthsIn,
                            'months'      => $months,
                            'in_by_month' => $inByPart[$k] ?? [],
                        ]);
                        ?>
                    </th>
                    <?php for ($mo = 1; $mo <= 12; $mo++):
                        $b = $months[$mo] ?? key_parts_order_empty_buckets();
                        $inQtyRaw = qty_norm($inByPart[$k][$mo] ?? 0);
                        $cell = key_parts_order_cell_for_filter($filterIn, $b, $inQtyRaw);
                        $hasCell = !empty($cell['has']);
                        $cellTotal = qty_norm($cell['total'] ?? 0);
                        $bShow = is_array($cell['b'] ?? null) ? $cell['b'] : key_parts_order_empty_buckets();
                        $inQty = qty_norm($cell['inQty'] ?? 0);
                        $showIn = !empty($cell['showIn']);
                        $active = ($partKeyIn === $k && $monthIn === $mo);
                        $href = key_parts_order_url([
                            'year'   => $yearIn,
                            'era'    => $eraIn,
                            'part'   => $k,
                            'month'  => $mo,
                            'filter' => $filterIn !== 'all' ? $filterIn : null,
                        ]);
                        ?>
                    <td class="kpo-cell<?= $active ? ' is-active' : '' ?><?= !$hasCell ? ' is-empty' : '' ?>">
                        <?php if ($hasCell): ?>
                        <a class="kpo-cell-link" href="<?= e($href) ?>">
                            <?php
                            $cellFlow = key_parts_order_cell_flow_html($filterIn, $cell);
                            echo $cellFlow !== '' ? $cellFlow : '<span class="kpo-cell-zero">—</span>';
                            ?>
                        </a>
                        <?php else: ?>
                        <span class="kpo-cell-zero">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endfor; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($drillItem && $monthIn > 0): ?>
    <section class="kpo-drill card" id="kpo-drill" aria-labelledby="kpo-drill-title">
        <div class="kpo-drill-head">
            <h2 id="kpo-drill-title" class="kpo-drill-title">
                รายละเอียดเบิกออก/รับเข้า · <?= e((string) $drillItem['label']) ?>
                · <?= e(KEY_PARTS_ORDER_MONTHS[$monthIn] ?? '') ?>
                <?= e(key_parts_order_year_label($yearIn, $eraIn)) ?><?php if ($filterIn !== 'all'): ?>
                · กรอง: <?= e($kpoFilterLabels[$filterIn] ?? $filterIn) ?><?php endif; ?>
            </h2>
            <a class="detail-link" href="<?= e(key_parts_order_url(['year' => $yearIn, 'era' => $eraIn, 'part' => null, 'month' => null, 'filter' => $filterIn !== 'all' ? $filterIn : null])) ?>">ปิด</a>
        </div>
        <?php if (trim((string) ($drillItem['inv_log_part'] ?? '')) === ''): ?>
        <p class="muted">ยังไม่กำหนดชื่อ log_part — ปรับ config ใน key_parts_order.php</p>
        <?php elseif ($drillMovementsRaw === []): ?>
        <p class="muted">ไม่มีรายการเบิกออกหรือรับเข้าในเดือนนี้</p>
        <?php elseif ($drillMovements === []): ?>
        <p class="muted">มี <?= e(formatNumber(count($drillMovementsRaw))) ?> รายการในเดือนนี้ แต่ไม่มีรายการที่ตรงกับตัวกรอง «<?= e($kpoFilterLabels[$filterIn] ?? $filterIn) ?>»</p>
        <?php else:
            $drillMovements = key_parts_order_sort_drill_movements($drillMovements);
            $drillOut = 0;
            $drillIn = 0;
            foreach ($drillMovements as $mv) {
                if (($mv['flow'] ?? 'out') === 'in') {
                    $drillIn++;
                } else {
                    $drillOut++;
                }
            }
            ?>
        <p class="muted" style="margin:0 0 8px">
            <span class="kpo-flow-out">เบิกออก <?= e(formatNumber($drillOut)) ?> รายการ</span>
            · <span class="muted">คลิกแถวเพื่อดูสถานะยืนยันจาก inventory</span>
            <?php if ($drillIn > 0): ?>
            · <span class="kpo-flow-in">รับเข้า <?= e(formatNumber($drillIn)) ?> รายการ</span>
            <?php endif; ?>
        </p>
        <div class="table-wrap">
            <table class="pc-table">
                <thead>
                    <tr>
                        <th>วันที่</th>
                        <th>เบิกออก/รับเข้า</th>
                        <th>วัตถุประสงค์</th>
                        <th>mode</th>
                        <th class="text-right">จำนวน</th>
                        <th>ผู้ดำเนินการ</th>
                        <th>เลขเอกสาร</th>
                        <th>หมายเหตุการเบิก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $drillPurposeCounts = [];
                    foreach ($drillMovements as $gm) {
                        $gk = (string) ($gm['purpose_key'] ?? '');
                        if ($gk !== '') {
                            $drillPurposeCounts[$gk] = ($drillPurposeCounts[$gk] ?? 0) + 1;
                        }
                    }
                    $drillPurposeKey = null;
                    $drillRowIdx = 0;
                    foreach ($drillMovements as $mv):
                        $purposeKey = (string) ($mv['purpose_key'] ?? '');
                        $purposeLbl = (string) ($mv['purpose_label'] ?? '—');
                        if ($purposeKey !== '' && $purposeKey !== $drillPurposeKey):
                            $drillPurposeKey = $purposeKey;
                            $groupCount = (int) ($drillPurposeCounts[$purposeKey] ?? 0);
                            ?>
                    <tr class="kpo-drill-group">
                        <td colspan="8">
                            <span class="kpo-drill-group-label"><?php key_parts_order_echo_drill_purpose_chip($purposeKey, $purposeLbl); ?></span>
                            <span class="kpo-drill-group-count muted"><?= e(formatNumber($groupCount)) ?> รายการ</span>
                        </td>
                    </tr>
                        <?php endif;
                        $flow = (string) ($mv['flow'] ?? 'out');
                        $ymd = substr((string) ($mv['moved_at'] ?? ''), 0, 10);
                        $flowCls = $flow === 'in' ? 'kpo-flow-in' : 'kpo-flow-out';
                        ?>
                    <tr class="<?= e($flowCls) ?> kpo-drill-row"
                        data-kpo-row="<?= (int) $drillRowIdx ?>"
                        tabindex="0"
                        role="button"
                        aria-label="ดูรายละเอียดการยืนยันจาก inventory">
                        <td><?= e($ymd !== '' ? purchase_check_thai_date($ymd) : '') ?></td>
                        <td class="kpo-drill-flow-cell"><?php key_parts_order_echo_drill_flow_chip($flow); ?></td>
                        <td class="kpo-drill-purpose-cell"><?php key_parts_order_echo_drill_purpose_chip($purposeKey, $purposeLbl); ?></td>
                        <td><?= e((string) ($mv['mode'] ?? '')) ?></td>
                        <td class="text-right pc-qty"><?= e(formatNumber($mv['qty'] ?? 0)) ?></td>
                        <td><?= e((string) ($mv['made_by'] ?? '')) ?: '—' ?></td>
                        <td><?= e((string) ($mv['doc_no'] ?? '')) !== '' ? e((string) $mv['doc_no']) : '—' ?></td>
                        <td class="kpo-remark-cell"><?php
                            $rmk = trim((string) ($mv['remark'] ?? ''));
                            echo $rmk !== '' ? e($rmk) : '—';
                        ?></td>
                    </tr>
                    <?php
                        $drillRowIdx++;
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
            $kpoDrillRowPayload = [];
            foreach ($drillMovements as $mv) {
                $pk = is_array($mv['pickup'] ?? null) ? $mv['pickup'] : [];
                $kpoDrillRowPayload[] = [
                    'doc_no'  => (string) ($mv['doc_no'] ?? ''),
                    'mode'    => (string) ($mv['mode'] ?? ''),
                    'qty'     => qty_norm($mv['qty'] ?? 0),
                    'made_by' => (string) ($mv['made_by'] ?? ''),
                    'remark'  => (string) ($mv['remark'] ?? ''),
                    'moved_at'=> (string) ($mv['moved_at'] ?? ''),
                    'pickup'  => $pk,
                ];
            }
        ?>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<?php
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$kpoEditorRows = key_parts_order_catalog_editor_rows();
parts_modal_begin('kpo-catalog-modal', 'จัดการรายการอะไหล่สั่งต่างประเทศ', true, false);
?>
<p class="kpo-catalog-intro muted">ค้นจาก inventory แล้วกด <b>เพิ่ม</b> · แก้ key / ชื่อ / log_part ด้านล่าง · <b>บันทึก</b> แล้วหน้านี้จะรีเฟรช</p>

<section class="kpo-catalog-panel kpo-catalog-search-block" aria-label="ค้นหา inventory">
    <label class="pc-tool-label" for="kpo-catalog-search">ค้นหาใน inventory</label>
    <input type="search" id="kpo-catalog-search" class="kpo-catalog-search-input" placeholder="เช่น จอ 7, Raspberry, LED…" autocomplete="off" data-autofocus>
    <div id="kpo-catalog-search-results" class="kpo-catalog-search-results">
        <p class="muted">พิมพ์ชื่ออะไหล่เพื่อค้นจาก inventory</p>
    </div>
</section>

<h4 class="kpo-catalog-subtitle">รายการที่สรุปในหน้านี้</h4>
<div class="kpo-catalog-panel kpo-catalog-editor-wrap table-wrap">
    <table class="pc-table kpo-catalog-editor">
        <thead>
            <tr>
                <th style="width:100px">key</th>
                <th>ชื่อการ์ด</th>
                <th style="width:120px">Rev/Lot</th>
                <th>log_part (inventory)</th>
                <th style="width:64px"></th>
            </tr>
        </thead>
        <tbody id="kpo-catalog-editor-body"></tbody>
    </table>
</div>

<p id="kpo-catalog-status" class="kpo-catalog-status" role="status"></p>
<div class="kpo-catalog-actions">
    <button type="button" class="btn btn-primary btn-with-icon" id="kpo-catalog-save"><?= ui_btn_label('check', 'บันทึกรายการ') ?></button>
    <button type="button" class="btn btn-outline modal-close-btn">ปิด</button>
</div>
<?php
parts_modal_end();

parts_modal_begin('kpo-drill-detail-modal', 'รายละเอียดจาก inventory', true, false);
?>
<p class="muted kpo-drill-detail-intro">ยืนยันการจ่ายจาก <b>pre_stock</b> (ใบเบิก) และบันทึกตัดสต็อกจาก <b>log_stock</b></p>
<dl class="kpo-drill-detail-dl" id="kpo-drill-detail-dl"></dl>
<div class="kpo-catalog-actions">
    <button type="button" class="btn btn-outline modal-close-btn">ปิด</button>
</div>
<?php
parts_modal_end();

$kpoCatalogJsVer = @filemtime(__DIR__ . '/../assets/kpo-catalog.js') ?: time();
$kpoDrillJsVer = @filemtime(__DIR__ . '/../assets/kpo-drill.js') ?: time();
?>
<script>
window.KPO_CATALOG = {
    apiUrl: <?= json_encode(url('/pages/key-parts-catalog-api.php'), JSON_UNESCAPED_UNICODE) ?>,
    csrf: <?= json_encode((string) $_SESSION['csrf'], JSON_UNESCAPED_UNICODE) ?>,
    maxRows: <?= (int) KEY_PARTS_ORDER_CATALOG_MAX ?>,
    initialRows: <?= json_encode($kpoEditorRows, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= url('/assets/kpo-catalog.js') ?>?v=<?= (int) $kpoCatalogJsVer ?>"></script>
<?php if (!empty($kpoDrillRowPayload)): ?>
<script>
window.KPO_DRILL_ROWS = <?= json_encode($kpoDrillRowPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="<?= url('/assets/kpo-drill.js') ?>?v=<?= (int) $kpoDrillJsVer ?>"></script>
<?php endif; ?>
<?php if ($drillItem && $monthIn > 0): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var drill = document.getElementById('kpo-drill');
    if (!drill) {
        return;
    }
    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    requestAnimationFrame(function () {
        drill.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
