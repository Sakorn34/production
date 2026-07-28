<?php
/** index.php — Dashboard รวม 2 ระบบ: ทะเบียนเครื่อง + สต็อกอะไหล่ (layout v2) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/part_stock_bridge.php';
require_login();

// ---- สรุปสถานะ ----
$byStatus = ['new' => 0, 'rental' => 0, 'spare' => 0];
$res = qr("SELECT status, COUNT(*) c FROM assets GROUP BY status");
while ($r = $res->fetch_assoc()) $byStatus[$r['status']] = (int)$r['c'];
$total = array_sum($byStatus);

// ---- สต็อกอะไหล่ (biton_tech_parts) — พังแล้วหน้าอื่นต้องยังแสดงได้ ----
$stock = ['ok' => false, 'items' => 0, 'qty' => 0, 'low' => 0, 'in_today' => 0, 'out_today' => 0];
try {
    $pdo = dbParts();
    $row = $pdo->query('SELECT COUNT(*) c, COALESCE(SUM(quantity),0) q FROM products')->fetch();
    $stock['items'] = (int)$row['c'];
    $stock['qty']   = (int)$row['q'];
    $stock['low']   = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE quantity <= min_stock')->fetchColumn();
    $stock['in_today']  = (int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stock['out_today'] = (int)$pdo->query("SELECT COALESCE(SUM(soi.quantity),0) FROM stock_out_items soi JOIN stock_out so ON so.id = soi.stock_out_id WHERE DATE(so.created_at) = CURDATE()")->fetchColumn();
    $stock['ok'] = true;
} catch (Throwable $e) {
    // แสดง dashboard ฝั่งเครื่องต่อได้แม้ต่อ DB สต็อกไม่ได้
}

// ---- ผลิตราย 12 เดือน ----
$prod12 = [];
$res = qr("SELECT DATE_FORMAT(produced_at,'%Y-%m') ym, COUNT(*) c FROM assets
           WHERE produced_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) GROUP BY ym");
while ($r = $res->fetch_assoc()) $prod12[$r['ym']] = (int)$r['c'];
$months = [];
for ($i = 11; $i >= 0; $i--) { $ym = date('Y-m', strtotime("-$i months")); $months[$ym] = isset($prod12[$ym]) ? $prod12[$ym] : 0; }
$maxM = max(1, max($months));

// ---- ผลิตรายปี (6 ปีล่าสุด) ----
$years = [];
$res = qr("SELECT YEAR(produced_at) y, COUNT(*) c FROM assets WHERE produced_at IS NOT NULL GROUP BY y ORDER BY y DESC LIMIT 6");
while ($r = $res->fetch_assoc()) $years[$r['y']] = (int)$r['c'];
$years = array_reverse($years, true);
$maxY = max(1, $years ? max($years) : 1);

// ---- จำนวนเครื่องรายรุ่น ----
$perModel = [];
$res = qr("SELECT p.id pid, p.name, p.icon_path, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY p.id ORDER BY c DESC");
while ($r = $res->fetch_assoc()) $perModel[] = $r;
$maxPM = 1; foreach ($perModel as $m) $maxPM = max($maxPM, (int)$m['c']);

// ---- รายการอะไหล่ (สต็อก) สำหรับสลับมุมมองบน Dashboard ----
$perPart = [];
$maxPart = 1;
$partIcons = [];
$partProdLabels = [];
if ($stock['ok']) {
    try {
        require_once dirname(__DIR__) . '/parts/includes/helpers.php';
        ensureProductColumns($pdo);
        $perPart = $pdo->query(
            'SELECT id, code, name, quantity, unit, min_stock, purchase_link, supplier FROM products ORDER BY quantity DESC'
        )->fetchAll();
        foreach ($perPart as $p) {
            $maxPart = max($maxPart, (int) $p['quantity']);
        }
        if ($perPart) {
            $partCodes = array_map(function ($r) { return $r['code']; }, $perPart);
            $partProdLabels = production_part_labels_by_stock_codes($partCodes);
            foreach ($partProdLabels as $sc => $meta) {
                if (!empty($meta['icon_path'])) {
                    $partIcons[$sc] = $meta['icon_path'];
                }
            }
        }
    } catch (Throwable $e) {
        $perPart = [];
        $partProdLabels = [];
    }
}

$perPartLowCount = 0;
$perPartOutCount = 0;
$partSuppliers = [];
$partSupplierNoneCount = 0;
foreach ($perPart as $p) {
    $qty = (int) $p['quantity'];
    $min = (int) $p['min_stock'];
    if ($qty <= 0) {
        $perPartOutCount++;
    } elseif ($qty <= $min) {
        $perPartLowCount++;
    }
    $sup = trim((string) ($p['supplier'] ?? ''));
    if ($sup === '') {
        $partSupplierNoneCount++;
    } else {
        $partSuppliers[$sup] = ($partSuppliers[$sup] ?? 0) + 1;
    }
}
ksort($partSuppliers, SORT_NATURAL | SORT_FLAG_CASE);

$PALETTE = ['#ec4899','#8b5cf6','#3b82f6','#f59e0b','#10b981','#06b6d4','#f43f5e','#a855f7','#14b8a6','#eab308','#6366f1','#ef4444'];
function pct($v, $t) { return $t > 0 ? round($v / $t * 100) : 0; }

page_header('Dashboard', true, 'ภาพรวมการผลิตและสต็อกอะไหล่');

/**
 * KPI การ์ดขาว + แถบสีความหมาย (แทน stat tile gradient เดิม)
 *
 * @param int    $num     ตัวเลขหลัก
 * @param string $label   ป้ายบนการ์ด
 * @param string $icon    icon key จาก ui_icons
 * @param string $tone    primary|success|info|warning
 * @param string $sub     ข้อความบรรทัดล่าง
 * @param string $onclick JS เมื่อคลิก ('' = ไม่คลิก)
 * @param string $sys     ป้ายมุมขวา (เครื่อง/อะไหล่/วันนี้)
 * @param string $numText override ตัวเลข (เช่น "12 / 38")
 */
function kpi($num, $label, $icon, $tone, $sub = '', $onclick = '', $sys = '', $numText = '') {
    $click = $onclick !== '' ? ' clickable" onclick="' . h($onclick) : '';
    echo '<div class="kpi kpi-' . h($tone) . $click . '">';
    if ($sys !== '') echo '<span class="kpi-sys">' . h($sys) . '</span>';
    echo '<div class="kpi-top"><span class="kpi-ic">' . ui_icon_html($icon, 14) . '</span> ' . h($label) . '</div>';
    echo '<b class="kpi-num">' . ($numText !== '' ? h($numText) : number_format($num)) . '</b>';
    if ($sub !== '') echo '<div class="kpi-sub">' . $sub . '</div>';
    echo '</div>';
}
/** สร้างโค้ด JS ดิบสำหรับ onclick — ต้องครอบด้วย h() เมื่อใส่ใน attribute */
function modal_js($title, $dataUrl, $moreUrl = '') {
    return 'showListModal('
        . json_encode($title, JSON_UNESCAPED_UNICODE) . ', '
        . json_encode($dataUrl, JSON_UNESCAPED_UNICODE) . ', '
        . json_encode($moreUrl, JSON_UNESCAPED_UNICODE) . ')';
}
$B = BASE_URL;
$partsBase = ui_parts_base_url();
?>
<div class="kpi-grid">
  <?php
  kpi($total, 'เครื่องทั้งหมด', 'assets', 'primary', pct($byStatus['new'], $total) . '% อยู่ในคลัง',
      modal_js('เครื่องทั้งหมด — รายรุ่น', "$B/dashboard_data.php?type=asset_models&st=all", "$B/assets.php"), 'เครื่อง');
  kpi($byStatus['new'], 'ใหม่ (คลัง)', 'box', 'success', pct($byStatus['new'], $total) . '% ของทั้งหมด',
      modal_js('เครื่องใหม่ — รายรุ่น', "$B/dashboard_data.php?type=asset_models&st=new", "$B/assets.php?status=new"), 'เครื่อง');
  kpi($byStatus['rental'], 'เครื่องเช่า', 'updates', 'info', pct($byStatus['rental'], $total) . '% ของทั้งหมด',
      modal_js('เครื่องเช่า — รายรุ่น', "$B/dashboard_data.php?type=asset_models&st=rental", "$B/assets.php?status=rental"), 'เครื่อง');
  kpi($byStatus['spare'], 'เครื่องสำรอง', 'box', 'warning', 'พร้อมสลับเปลี่ยนหน้างาน',
      modal_js('เครื่องสำรอง — รายรุ่น', "$B/dashboard_data.php?type=asset_models&st=spare", "$B/assets.php?status=spare"), 'เครื่อง');
  if ($stock['ok']) {
      kpi($stock['qty'], 'สต็อกคงเหลือรวม', 'parts', 'primary', number_format($stock['items']) . ' รายการ',
          "location.href='" . h($partsBase) . "/pages/products.php'", 'อะไหล่');
      kpi($stock['low'], 'อะไหล่ใกล้หมด', 'alert', $stock['low'] > 0 ? 'warning' : 'success', $stock['low'] > 0 ? '<span class="kpi-down">ต้องตรวจสอบ/สั่งซื้อ</span>' : 'ทุกรายการเพียงพอ',
          $stock['low'] > 0
              ? modal_js('อะไหล่ใกล้หมด (' . number_format($stock['low']) . ' รายการ)', "$B/dashboard_data.php?type=low_stock", "$partsBase/pages/products.php")
              : "location.href='" . h($partsBase) . "/pages/products.php'",
          'อะไหล่');
      kpi(0, 'รับเข้า / เบิกออก', 'history', 'info', 'ความเคลื่อนไหววันนี้',
          modal_js('รับเข้า / เบิกออกวันนี้', "$B/dashboard_data.php?type=stock_today", "$partsBase/pages/history.php"), 'วันนี้',
          number_format($stock['in_today']) . ' / ' . number_format($stock['out_today']));
  } else {
      echo '<div class="kpi kpi-warning"><div class="kpi-top"><span class="kpi-ic">' . ui_icon_html('alert', 14) . '</span> สต็อกอะไหล่</div><b class="kpi-num">—</b><div class="kpi-sub">เชื่อมต่อระบบสต็อกไม่ได้</div></div>';
  }
  ?>
</div>

<div class="dash-charts-2col">
  <div class="panel">
    <h3>จำนวนเครื่องที่ผลิต — 12 เดือนล่าสุด</h3>
    <div class="barchart dash-barchart">
      <?php foreach ($months as $ym => $c) { ?>
      <div class="bar" style="height:<?= round($c / $maxM * 100) ?>%" title="<?= h("$ym : $c เครื่อง") ?>"
           onclick="showListModal(<?= h(json_encode('รุ่นที่ผลิตเดือน ' . thai_month_period_label($ym), JSON_UNESCAPED_UNICODE)) ?>, '<?= $B ?>/dashboard_data.php?type=month_models&v=<?= $ym ?>', '')">
        <b><?= $c ?: '' ?></b><span><?= h(thai_month_short($ym)) ?></span>
      </div>
      <?php } ?>
    </div>
  </div>

  <div class="panel">
    <h3>ผลิตรายปี (6 ปีล่าสุด)</h3>
    <div class="year-hbar-list">
      <?php foreach ($years as $y => $c) { ?>
      <div class="year-hbar-row clickable"
           onclick="showListModal(<?= h(json_encode("การผลิตปี $y รายเดือน", JSON_UNESCAPED_UNICODE)) ?>, '<?= $B ?>/dashboard_data.php?type=year_months&v=<?= (int)$y ?>', '')">
        <div class="year-hbar-label"><?= thai_buddhist_year((int)$y) ?></div>
        <div class="hbar-track"><div class="hbar-fill" style="width:<?= pct($c, $maxY) ?>%"></div></div>
        <div class="year-hbar-val"><?= number_format($c) ?></div>
      </div>
      <?php } ?>
    </div>
  </div>
</div>

<div class="panel dash-quick-panel">
  <?= ui_heading('check', 'ทางลัดที่ใช้บ่อย', 'h3') ?>
  <div class="quick-grid">
    <a href="<?= $B ?>/asset_new.php" class="quick-item"><span class="q-ic q-primary"><?= ui_icon_html('assets', 17) ?></span><span>บันทึกเครื่องใหม่<small>ทะเบียนเครื่อง</small></span></a>
    <a href="<?= $B ?>/ma.php" class="quick-item"><span class="q-ic q-warning"><?= ui_icon_html('ma', 17) ?></span><span>บันทึก MA<small>บำรุงรักษา</small></span></a>
    <a href="<?= $B ?>/update_new.php" class="quick-item"><span class="q-ic q-info"><?= ui_icon_html('updates', 17) ?></span><span>อัปเดต FW/HW<small>บันทึกเวอร์ชัน</small></span></a>
    <a href="<?= h($partsBase) ?>/pages/stock-out.php" class="quick-item"><span class="q-ic q-info"><?= ui_icon_html('stock-out-set', 17) ?></span><span>เบิกอะไหล่<small>Set / รายชิ้น</small></span></a>
    <a href="<?= h($partsBase) ?>/pages/stock-in.php" class="quick-item"><span class="q-ic q-success"><?= ui_icon_html('stock-in', 17) ?></span><span>รับอะไหล่เข้า<small>สต็อกอะไหล่</small></span></a>
    <a href="<?= $B ?>/scan.php" class="quick-item"><span class="q-ic q-primary"><?= ui_icon_html('scan', 17) ?></span><span>สแกน QR<small>ค้นเครื่องเร็ว</small></span></a>
  </div>
</div>

<div class="panel" id="dash-inventory-panel">
  <div class="panel-head-row">
    <h3 id="dash-inventory-title">
      <span id="dash-inventory-title-text">จำนวนเครื่องรายรุ่น</span>
      <span class="muted panel-meta" id="dash-inventory-meta"><?= count($perModel) ?> รุ่น</span>
    </h3>
    <div class="panel-head-actions">
      <div class="dash-view-toggle" role="tablist" aria-label="โหมดรายการ">
        <button type="button" class="dash-view-btn active" data-view="models" role="tab" aria-selected="true">รุ่นสินค้า</button>
        <button type="button" class="dash-view-btn" data-view="parts" role="tab" aria-selected="false"
          <?= ($stock['ok'] && $perPart) ? '' : 'disabled title="เชื่อมต่อสต็อกอะไหล่ไม่ได้"' ?>>รายการอะไหล่</button>
      </div>
    </div>
  </div>

  <div class="dash-parts-filters" id="dash-parts-filters" hidden>
    <div class="dash-parts-filters-inner">
      <div class="dash-parts-filter-group">
        <span class="dash-parts-filter-label">สถานะ</span>
        <div class="dash-parts-filter" id="dash-parts-filter" role="tablist" aria-label="ตัวกรองสต็อกอะไหล่">
          <button type="button" class="dash-parts-filter-btn active" data-filter="all" role="tab" aria-selected="true">อะไหล่ทั้งหมด</button>
          <button type="button" class="dash-parts-filter-btn" data-filter="low" role="tab" aria-selected="false"<?= $perPartLowCount <= 0 ? ' disabled title="ไม่มีอะไหล่ใกล้หมด"' : '' ?>>ใกล้หมด<?= $perPartLowCount > 0 ? ' (' . number_format($perPartLowCount) . ')' : '' ?></button>
          <button type="button" class="dash-parts-filter-btn" data-filter="out" role="tab" aria-selected="false"<?= $perPartOutCount <= 0 ? ' disabled title="ไม่มีอะไหล่ที่หมดแล้ว"' : '' ?>>หมดแล้ว<?= $perPartOutCount > 0 ? ' (' . number_format($perPartOutCount) . ')' : '' ?></button>
        </div>
      </div>
      <?php if ($partSuppliers || $partSupplierNoneCount) { ?>
      <div class="dash-parts-filter-group dash-parts-filter-group--suppliers">
        <span class="dash-parts-filter-label">ผู้จำหน่าย</span>
        <div class="dash-parts-supplier-filter" id="dash-parts-supplier-filter" role="tablist" aria-label="ตัวกรองผู้จำหน่าย">
          <button type="button" class="dash-parts-supplier-btn active" data-supplier="" role="tab" aria-selected="true">ทั้งหมด</button>
          <?php foreach ($partSuppliers as $supName => $supCnt) { ?>
          <button type="button" class="dash-parts-supplier-btn" data-supplier="<?= h($supName) ?>" role="tab" aria-selected="false"><?= h($supName) ?> (<?= number_format($supCnt) ?>)</button>
          <?php } ?>
          <?php if ($partSupplierNoneCount > 0) { ?>
          <button type="button" class="dash-parts-supplier-btn" data-supplier="__none__" role="tab" aria-selected="false">ไม่ระบุ (<?= number_format($partSupplierNoneCount) ?>)</button>
          <?php } ?>
        </div>
      </div>
      <?php } ?>
    </div>
  </div>

  <div class="dash-view-pane" id="dash-view-models" role="tabpanel">
  <div class="model-card-grid">
    <?php $mi = 0; foreach ($perModel as $m) { $col = $PALETTE[$mi++ % count($PALETTE)]; ?>
    <div class="model-card clickable"
         onclick="showListModal(<?= h(json_encode('การผลิตรุ่น ' . $m['name'] . ' รายปี', JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=product_years&v=" . (int)$m['pid']) ?>', '<?= h("$B/assets.php?product=" . urlencode($m['name'])) ?>')">
      <div class="model-card-img">
        <?= img_tag($m['icon_path'], $m['name'], 'model-thumb') ?>
      </div>
      <div class="model-card-body">
        <div class="model-card-name" title="<?= h($m['name']) ?>"><?= h($m['name']) ?></div>
        <div class="model-card-bar"><div class="model-card-fill" style="width:<?= pct($m['c'], $maxPM) ?>%; background:<?= h($col) ?>"></div></div>
        <div class="model-card-num"><?= number_format($m['c']) ?></div>
      </div>
    </div>
    <?php } ?>
  </div>
  </div>

  <div class="dash-view-pane" id="dash-view-parts" role="tabpanel" hidden>
  <?php if (!$stock['ok'] || !$perPart) { ?>
    <p class="muted" style="padding:12px 0">ไม่มีข้อมูลสต็อกอะไหล่</p>
  <?php } else { ?>
  <div class="model-card-grid" id="dash-parts-grid">
    <?php $pi = 0; foreach ($perPart as $p) {
        $col = $PALETTE[$pi++ % count($PALETTE)];
        $qty = (int) $p['quantity'];
        $min = (int) $p['min_stock'];
        if ($qty <= 0) {
            $stockStatus = 'out';
        } elseif ($qty <= $min) {
            $stockStatus = 'low';
        } else {
            $stockStatus = 'ok';
        }
        $icon = isset($partIcons[$p['code']]) ? $partIcons[$p['code']] : '';
        $supplier = trim((string) ($p['supplier'] ?? ''));
        $purchaseLink = trim((string) ($p['purchase_link'] ?? ''));
        $displayName = part_product_display_name($p, $partProdLabels);
        $displaySub = part_product_display_sub($p, $partProdLabels);
        $stockCode = trim((string) ($p['code'] ?? ''));
        $cardTitle = $stockCode;
        if ($displaySub !== '' && $displaySub !== $stockCode) {
            $cardTitle .= ' · ' . $displaySub;
        }
        $barColor = $stockStatus === 'out'
            ? 'var(--danger,#dc2626)'
            : ($stockStatus === 'low' ? 'var(--warning,#f59e0b)' : h($col));
    ?>
    <div class="model-card clickable dash-part-card" data-stock-status="<?= h($stockStatus) ?>" data-supplier="<?= h($supplier) ?>"
         onclick="showListModal(<?= h(json_encode('โปรไฟล์: ' . $displayName, JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=part_profile&v=" . (int) $p['id']) ?>', '<?= h("$partsBase/pages/product-detail.php?id=" . (int) $p['id']) ?>')"
         title="<?= h($cardTitle) ?>">
      <div class="model-card-img">
        <?php if ($icon) {
            echo img_tag($icon, $displayName, 'model-thumb');
        } else {
            echo '<span class="model-thumb model-thumb-ph">' . ui_icon_html('parts', 18) . '</span>';
        } ?>
      </div>
      <div class="model-card-body">
        <div class="model-card-name" title="<?= h($displayName) ?>"><?= h($displayName) ?></div>
        <div class="model-card-sub muted"><?= h($displaySub) ?></div>
        <div class="model-card-bar"><div class="model-card-fill" style="width:<?= pct($qty, $maxPart) ?>%; background:<?= $barColor ?>"></div></div>
        <div class="model-card-foot">
          <div class="model-card-num"><?= number_format($qty) ?> <?= h($p['unit'] ?: 'ชิ้น') ?><?php
            if ($stockStatus === 'out') {
                echo ' · <span class="text-danger">หมดแล้ว</span>';
            } elseif ($stockStatus === 'low') {
                echo ' · <span class="text-warn">ใกล้หมด</span>';
            }
          ?></div>
          <?php if ($purchaseLink !== '') { ?>
          <a href="<?= h($purchaseLink) ?>" class="btn btn-sm btn-line dash-part-order-btn" target="_blank" rel="noopener noreferrer"
             onclick="event.stopPropagation();" title="เปิดลิงก์สั่งซื้อ"><?= ui_btn_label('external-link', 'สั่งซื้อ', 13) ?></a>
          <?php } ?>
        </div>
      </div>
    </div>
    <?php } ?>
  </div>
  <p class="muted dash-parts-filter-empty" id="dash-parts-filter-empty" hidden>ไม่มีอะไหล่ตามตัวกรองที่เลือก</p>
  <p style="margin-top:10px"><a href="<?= h($partsBase) ?>/pages/products.php">ดูรายการอะไหล่ทั้งหมด ›</a></p>
  <?php } ?>
  </div>
</div>

<script>
(function(){
  var panel = document.getElementById('dash-inventory-panel');
  if (!panel) return;
  var titleText = document.getElementById('dash-inventory-title-text');
  var metaEl = document.getElementById('dash-inventory-meta');
  var paneModels = document.getElementById('dash-view-models');
  var paneParts = document.getElementById('dash-view-parts');
  var partsFilter = document.getElementById('dash-parts-filter');
  var partsFiltersWrap = document.getElementById('dash-parts-filters');
  var partsSupplierFilter = document.getElementById('dash-parts-supplier-filter');
  var partsGrid = document.getElementById('dash-parts-grid');
  var partsEmpty = document.getElementById('dash-parts-filter-empty');
  var viewBtns = panel.querySelectorAll('.dash-view-btn');
  var filterBtns = partsFilter ? partsFilter.querySelectorAll('.dash-parts-filter-btn') : [];
  var supplierBtns = partsSupplierFilter ? partsSupplierFilter.querySelectorAll('.dash-parts-supplier-btn') : [];
  var partCards = partsGrid ? partsGrid.querySelectorAll('.dash-part-card[data-stock-status]') : [];
  var currentView = 'models';
  var currentPartsFilter = 'all';
  var currentSupplierFilter = '';
  var labels = {
    models: { title: 'จำนวนเครื่องรายรุ่น', meta: '<?= count($perModel) ?> รุ่น' },
    parts:  { title: 'สต็อกอะไหล่รายการ' }
  };
  var emptyMsgs = {
    all: 'ไม่มีอะไหล่ตามตัวกรองที่เลือก',
    low: 'ไม่มีอะไหล่ใกล้หมดตามตัวกรองที่เลือก',
    out: 'ไม่มีอะไหล่ที่หมดแล้วตามตัวกรองที่เลือก'
  };

  function setFilterButtons(mode) {
    filterBtns.forEach(function(b) {
      var on = (b.getAttribute('data-filter') || 'all') === mode;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function setSupplierButtons(mode) {
    supplierBtns.forEach(function(b) {
      var key = b.getAttribute('data-supplier') || '';
      var on = key === mode;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function applyPartsFilter(stockMode, supplierMode, updateMeta) {
    if (!partCards.length) return;
    if (typeof stockMode === 'string') currentPartsFilter = stockMode;
    if (typeof supplierMode === 'string') currentSupplierFilter = supplierMode;
    setFilterButtons(currentPartsFilter);
    setSupplierButtons(currentSupplierFilter);
    var shown = 0;
    partCards.forEach(function(card) {
      var status = card.getAttribute('data-stock-status') || 'ok';
      var supplier = card.getAttribute('data-supplier') || '';
      var stockOk = currentPartsFilter === 'all'
        || (currentPartsFilter === 'low' && status === 'low')
        || (currentPartsFilter === 'out' && status === 'out');
      var supplierOk = currentSupplierFilter === '' || (currentSupplierFilter === '__none__' ? supplier === '' : supplier === currentSupplierFilter);
      var show = stockOk && supplierOk;
      card.hidden = !show;
      if (show) shown++;
    });
    if (partsEmpty) {
      var noMatch = shown === 0;
      partsEmpty.hidden = !noMatch;
      if (noMatch) {
        partsEmpty.textContent = emptyMsgs[currentPartsFilter] || emptyMsgs.all;
      }
    }
    if (updateMeta !== false && metaEl && currentView === 'parts') {
      metaEl.textContent = shown.toLocaleString('th-TH') + ' รายการ';
    }
  }

  function resetPartsFilter() {
    currentPartsFilter = 'all';
    currentSupplierFilter = '';
    setFilterButtons('all');
    setSupplierButtons('');
    partCards.forEach(function(card) { card.hidden = false; });
    if (partsEmpty) partsEmpty.hidden = true;
  }

  function setPartsFilterVisible(on) {
    if (partsFiltersWrap) {
      partsFiltersWrap.hidden = !on;
      partsFiltersWrap.setAttribute('aria-hidden', on ? 'false' : 'true');
    }
  }

  function switchView(view) {
    if (view === 'parts') {
      var partsBtn = panel.querySelector('.dash-view-btn[data-view="parts"]');
      if (partsBtn && partsBtn.disabled) return;
    }
    currentView = view;
    viewBtns.forEach(function(b) {
      var on = b.getAttribute('data-view') === view;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (paneModels) paneModels.hidden = view !== 'models';
    if (paneParts) paneParts.hidden = view !== 'parts';
    setPartsFilterVisible(view === 'parts');
    var lb = labels[view] || labels.models;
    if (titleText) titleText.textContent = lb.title;
    if (view === 'parts') {
      applyPartsFilter(currentPartsFilter, currentSupplierFilter, true);
    } else {
      resetPartsFilter();
      if (metaEl) metaEl.textContent = lb.meta;
    }
  }

  setPartsFilterVisible(false);

  filterBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      if (btn.disabled) return;
      var mode = btn.getAttribute('data-filter') || 'all';
      if (currentView !== 'parts') {
        switchView('parts');
      }
      applyPartsFilter(mode, currentSupplierFilter, true);
    });
  });

  supplierBtns.forEach(function(btn) {
    btn.addEventListener('click', function() {
      var key = btn.getAttribute('data-supplier') || '';
      if (currentView !== 'parts') {
        switchView('parts');
      }
      applyPartsFilter(currentPartsFilter, key, true);
    });
  });

  viewBtns.forEach(function(btn){
    btn.addEventListener('click', function(){
      if (btn.disabled) return;
      switchView(btn.getAttribute('data-view') || 'models');
    });
  });
})();
</script>

<?php page_footer();
