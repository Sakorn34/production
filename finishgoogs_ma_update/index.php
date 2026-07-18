<?php
/** index.php — Dashboard หลัก: สรุปตัวเลข + กราฟรายงานการผลิต + สถิติรายรุ่น (พร้อมรูป) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

// ---- สรุปสถานะ ----
$byStatus = ['new' => 0, 'rental' => 0, 'spare' => 0];
$res = qr("SELECT status, COUNT(*) c FROM assets GROUP BY status");
while ($r = $res->fetch_assoc()) $byStatus[$r['status']] = (int)$r['c'];
$total = array_sum($byStatus);

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

// ---- จำนวนเครื่องรายรุ่น (ทุกรุ่นที่มีเครื่องในระบบ พร้อมรูป) ----
$perModel = [];
$res = qr("SELECT p.id pid, p.name, p.icon_path, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY p.id ORDER BY c DESC");
while ($r = $res->fetch_assoc()) $perModel[] = $r;
$maxPM = 1; foreach ($perModel as $m) $maxPM = max($maxPM, (int)$m['c']);

// ---- ตามหมวดสินค้า ----
$perCat = [];
$res = qr("SELECT COALESCE(NULLIF(p.category,''),'อื่นๆ') cat, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY cat ORDER BY c DESC");
while ($r = $res->fetch_assoc()) $perCat[$r['cat']] = (int)$r['c'];
$maxCat = max(1, $perCat ? max($perCat) : 1);

$PALETTE = ['#ec4899','#8b5cf6','#3b82f6','#f59e0b','#10b981','#06b6d4','#f43f5e','#a855f7','#14b8a6','#eab308','#6366f1','#ef4444'];
function pct($v, $t) { return $t > 0 ? round($v / $t * 100) : 0; }

page_header('Dashboard');

/** stat tile ไล่เฉดสี — มี dataUrl = คลิกเปิด modal เจาะลึกได้ */
function tile($num, $label, $g1, $g2, $modalTitle = '', $dataUrl = '', $moreUrl = '') {
    $attrs = 'class="stat-tile' . ($dataUrl ? ' clickable' : '') . '" style="--g1:' . $g1 . '; --g2:' . $g2 . '"';
    if ($dataUrl) $attrs .= ' onclick="showListModal(' . h(json_encode($modalTitle, JSON_UNESCAPED_UNICODE)) . ', \'' . h($dataUrl) . '\', \'' . h($moreUrl) . '\')"';
    echo "<div $attrs><div class=\"st-num\">" . number_format($num) . '</div><div class="st-lbl">' . h($label) . ($dataUrl ? ' ›' : '') . '</div></div>';
}
$B = BASE_URL;
?>
<div class="stat-grid">
  <?php
  tile($total, 'เครื่องทั้งหมด', '#ec4899', '#f472b6', 'เครื่องทั้งหมด (แสดง 150 รายการล่าสุด)', "$B/dashboard_data.php?type=all", "$B/assets.php");
  tile($byStatus['new'], 'เครื่องไม่มีสถานะ ', '#3b82f6', '#60a5fa', 'เครื่องใหม่', "$B/dashboard_data.php?type=status&v=new", "$B/assets.php?status=new");
  tile($byStatus['rental'], 'เครื่องเช่า', '#8b5cf6', '#a78bfa', 'เครื่องเช่า', "$B/dashboard_data.php?type=status&v=rental", "$B/assets.php?status=rental");
  tile($byStatus['spare'], 'เครื่องสำรอง', '#f59e0b', '#fbbf24', 'เครื่องสำรอง', "$B/dashboard_data.php?type=status&v=spare", "$B/assets.php?status=spare");
  ?>
</div>

<!-- ผลิตราย 12 เดือน -->
<div class="panel" style="margin-bottom:16px">
  <h3>จำนวนเครื่องที่ผลิต 12 เดือนล่าสุด <span class="muted" style="font-weight:400; font-size:12px">(กดแท่ง → รุ่นสินค้า → รายการเครื่อง → timeline)</span></h3>
  <div class="barchart" style="border:0; padding:8px 0; height:150px">
    <?php foreach ($months as $ym => $c) { ?>
    <div class="bar" style="height:<?= round($c / $maxM * 100) ?>%; background:linear-gradient(180deg,#ec4899,#8b5cf6)" title="<?= h("$ym : $c เครื่อง") ?>"
         onclick="showListModal('🏷️ รุ่นที่ผลิตเดือน <?= h(date('m/Y', strtotime($ym . '-01'))) ?>', '<?= $B ?>/dashboard_data.php?type=month_models&v=<?= $ym ?>', '')">
      <b><?= $c ?: '' ?></b><span><?= h(date('m/y', strtotime($ym . '-01'))) ?></span>
    </div>
    <?php } ?>
  </div>
</div>

<div class="report-cols">
  <!-- โดนัท: สัดส่วนประเภทการขาย -->
  <div class="panel">
    <h3>สัดส่วนตามประเภท (สถานะการขาย)</h3>
    <?php
      $donut = [['เครื่องใหม่ (คลัง)', $byStatus['new'], '#3b82f6', 'new'], ['เครื่องเช่า', $byStatus['rental'], '#8b5cf6', 'rental'], ['เครื่องสำรอง', $byStatus['spare'], '#f59e0b', 'spare']];
      $acc = 0; $stops = [];
      foreach ($donut as $d) { $p = pct($d[1], $total); $stops[] = $d[2] . ' ' . $acc . '% ' . ($acc + $p) . '%'; $acc += $p; }
      if ($acc < 100 && $stops) $stops[] = '#e5e7eb ' . $acc . '% 100%';
    ?>
    <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap">
      <div class="donut" style="background: conic-gradient(<?= implode(',', $stops) ?>)"><div class="donut-hole"><b><?= number_format($total) ?></b><span>เครื่อง</span></div></div>
      <div style="flex:1; min-width:160px">
        <?php foreach ($donut as $d) { ?>
        <div class="lg-row clickable" onclick="showListModal(<?= h(json_encode($d[0], JSON_UNESCAPED_UNICODE)) ?>, '<?= $B ?>/dashboard_data.php?type=status&v=<?= $d[3] ?>', '<?= $B ?>/assets.php?status=<?= $d[3] ?>')">
          <span class="lg-dot" style="background:<?= $d[2] ?>"></span><?= h($d[0]) ?> <b style="margin-left:auto"><?= number_format($d[1]) ?></b> <span class="muted">(<?= pct($d[1], $total) ?>%)</span></div>
        <?php } ?>
      </div>
    </div>
    <h3 style="margin-top:18px">ผลิตรายปี <span class="muted" style="font-weight:400; font-size:12px">(กดแท่ง → รายเดือน → รุ่น → เครื่อง → timeline)</span></h3>
    <div class="barchart" style="border:0; padding:8px 0; height:130px">
      <?php foreach ($years as $y => $c) { ?>
      <div class="bar" style="height:<?= round($c / $maxY * 100) ?>%; background:linear-gradient(180deg,#3b82f6,#06b6d4)" title="<?= h("$y : $c") ?>"
           onclick="showListModal(<?= h(json_encode("📊 การผลิตปี $y รายเดือน", JSON_UNESCAPED_UNICODE)) ?>, '<?= $B ?>/dashboard_data.php?type=year_months&v=<?= (int)$y ?>', '')"><b><?= $c ?></b><span><?= h($y) ?></span></div>
      <?php } ?>
    </div>
    <h3 style="margin-top:18px">ตามหมวดสินค้า <span class="muted" style="font-weight:400; font-size:12px">(กดแถวเพื่อดูรายการ)</span></h3>
    <?php $ci = 0; foreach ($perCat as $cat => $c) { $col = $PALETTE[$ci++ % count($PALETTE)]; ?>
    <div class="hbar-row clickable" onclick="showListModal(<?= h(json_encode("หมวด: $cat", JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=category&v=" . rawurlencode($cat)) ?>', '')">
      <div class="hbar-label"><?= h($cat) ?></div>
      <div class="hbar-track"><div class="hbar-fill" style="width:<?= pct($c, $maxCat) ?>%; background:<?= $col ?>"></div></div>
      <div class="hbar-val"><?= number_format($c) ?></div>
    </div>
    <?php } ?>
  </div>

  <!-- รายรุ่น (พร้อมรูปสินค้า) -->
  <div class="panel">
    <h3>จำนวนเครื่องรายรุ่น <span class="muted" style="font-weight:400; font-size:12px">(<?= count($perModel) ?> รุ่น · กดแถว → กราฟรายปี → รายเดือน → หมายเลขเครื่อง → โปรไฟล์)</span></h3>
    <div style="max-height:560px; overflow-y:auto; margin:0 -4px; padding:0 4px">
    <?php $mi = 0; foreach ($perModel as $m) { $col = $PALETTE[$mi++ % count($PALETTE)]; ?>
    <div class="hbar-row hbar-model clickable"
         onclick="showListModal(<?= h(json_encode('📊 การผลิตรุ่น ' . $m['name'] . ' รายปี', JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=product_years&v=" . (int)$m['pid']) ?>', '<?= h("$B/assets.php?product=" . urlencode($m['name'])) ?>')">
      <div class="hbar-label" title="<?= h($m['name']) ?>">
        <?= img_tag($m['icon_path'], $m['name'], 'thumb-sm') ?>
        <span><?= h($m['name']) ?></span>
      </div>
      <div class="hbar-track"><div class="hbar-fill" style="width:<?= pct($m['c'], $maxPM) ?>%; background:<?= $col ?>"></div></div>
      <div class="hbar-val"><?= number_format($m['c']) ?></div>
    </div>
    <?php } ?>
    </div>
  </div>
</div>
<?php page_footer();
