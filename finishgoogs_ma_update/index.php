<?php
/** index.php — Dashboard รวม 2 ระบบ: ทะเบียนเครื่อง + สต็อกอะไหล่ (layout v2) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/part_stock_bridge.php';
require_once dirname(__DIR__) . '/shared/activity_log_core.php';
require_login();

// ---- สรุปสถานะ ----
$byStatus = ['new' => 0, 'rental' => 0, 'spare' => 0];
$res = qr("SELECT status, COUNT(*) c FROM assets GROUP BY status");
while ($r = $res->fetch_assoc()) $byStatus[$r['status']] = (int)$r['c'];
$total = array_sum($byStatus);

// ---- สต็อกอะไหล่ (biton_tech_parts) — พังแล้วหน้าอื่นต้องยังแสดงได้ ----
$stock = ['ok' => false, 'items' => 0, 'qty' => 0, 'low' => 0, 'in_today' => 0, 'out_today' => 0, 'low_list' => []];
try {
    $pdo = dbParts();
    $row = $pdo->query('SELECT COUNT(*) c, COALESCE(SUM(quantity),0) q FROM products')->fetch();
    $stock['items'] = (int)$row['c'];
    $stock['qty']   = (int)$row['q'];
    $stock['low']   = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE quantity <= min_stock')->fetchColumn();
    $stock['in_today']  = (int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stock['out_today'] = (int)$pdo->query("SELECT COALESCE(SUM(soi.quantity),0) FROM stock_out_items soi JOIN stock_out so ON so.id = soi.stock_out_id WHERE DATE(so.created_at) = CURDATE()")->fetchColumn();
    $stock['low_list'] = $pdo->query('SELECT code, name, unit, quantity, min_stock FROM products WHERE quantity <= min_stock ORDER BY quantity ASC LIMIT 5')->fetchAll();
    $stock['ok'] = true;
} catch (Throwable $e) {
    // แสดง dashboard ฝั่งเครื่องต่อได้แม้ต่อ DB สต็อกไม่ได้
}

// รูปอะไหล่: map code → icon_path จากตาราง parts ฝั่ง production
$lowIcons = [];
if ($stock['low_list']) {
    $codes = array_map(function ($r) { return $r['code']; }, $stock['low_list']);
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $st = qr("SELECT stock_code, icon_path FROM parts WHERE stock_code IN ($ph) AND icon_path IS NOT NULL AND icon_path <> ''",
             str_repeat('s', count($codes)), $codes);
    while ($r = $st->fetch_assoc()) $lowIcons[$r['stock_code']] = $r['icon_path'];
}

// ---- ความเคลื่อนไหวล่าสุดรวม 2 ระบบ ----
$feed = [];
try {
    $fr = activity_log_search([], 6, 0);
    $feed = $fr['rows'] ?? [];
} catch (Throwable $e) {
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

// ---- ตามหมวดสินค้า (ส่วนเสริม) ----
$perCat = [];
$res = qr("SELECT COALESCE(NULLIF(p.category,''),'อื่นๆ') cat, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY cat ORDER BY c DESC");
while ($r = $res->fetch_assoc()) $perCat[$r['cat']] = (int)$r['c'];
$maxCat = max(1, $perCat ? max($perCat) : 1);

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
/** สร้างโค้ด JS ดิบสำหรับ onclick — ตัว kpi() จะ h() ให้ตอนใส่ attribute (ห้าม escape ซ้ำ) */
function modal_js($title, $dataUrl, $moreUrl = '') {
    return 'showListModal(' . json_encode($title, JSON_UNESCAPED_UNICODE) . ", '" . $dataUrl . "', '" . $moreUrl . "')";
}
$B = BASE_URL;
$partsBase = ui_parts_base_url();
?>
<div class="kpi-grid">
  <?php
  kpi($total, 'เครื่องทั้งหมด', 'assets', 'primary', pct($byStatus['new'], $total) . '% อยู่ในคลัง',
      modal_js('เครื่องทั้งหมด (แสดง 150 รายการล่าสุด)', "$B/dashboard_data.php?type=all", "$B/assets.php"), 'เครื่อง');
  kpi($byStatus['new'], 'ใหม่ (คลัง)', 'box', 'success', pct($byStatus['new'], $total) . '% ของทั้งหมด',
      modal_js('เครื่องใหม่', "$B/dashboard_data.php?type=status&v=new", "$B/assets.php?status=new"), 'เครื่อง');
  kpi($byStatus['rental'], 'เครื่องเช่า', 'updates', 'info', pct($byStatus['rental'], $total) . '% ของทั้งหมด',
      modal_js('เครื่องเช่า', "$B/dashboard_data.php?type=status&v=rental", "$B/assets.php?status=rental"), 'เครื่อง');
  kpi($byStatus['spare'], 'เครื่องสำรอง', 'box', 'warning', 'พร้อมสลับเปลี่ยนหน้างาน',
      modal_js('เครื่องสำรอง', "$B/dashboard_data.php?type=status&v=spare", "$B/assets.php?status=spare"), 'เครื่อง');
  if ($stock['ok']) {
      kpi($stock['qty'], 'สต็อกคงเหลือรวม', 'parts', 'primary', number_format($stock['items']) . ' รายการ',
          "location.href='" . h($partsBase) . "/pages/products.php'", 'อะไหล่');
      kpi($stock['low'], 'อะไหล่ใกล้หมด', 'alert', $stock['low'] > 0 ? 'warning' : 'success', $stock['low'] > 0 ? '<span class="kpi-down">ต้องตรวจสอบ/สั่งซื้อ</span>' : 'ทุกรายการเพียงพอ',
          "location.href='" . h($partsBase) . "/pages/products.php'", 'อะไหล่');
      kpi(0, 'รับเข้า / เบิกออก', 'history', 'info', 'ความเคลื่อนไหววันนี้',
          "location.href='" . h($partsBase) . "/pages/history.php'", 'วันนี้',
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

<div class="panel">
  <h3>จำนวนเครื่องรายรุ่น <span class="muted panel-meta"><?= count($perModel) ?> รุ่น</span></h3>
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

<details class="panel dash-extra">
  <summary>สัดส่วนสถานะ · หมวดสินค้า</summary>
  <div class="dash-extra-body">
    <?php
      $donut = [['เครื่องใหม่ (คลัง)', $byStatus['new'], '#3b82f6', 'new'], ['เครื่องเช่า', $byStatus['rental'], '#8b5cf6', 'rental'], ['เครื่องสำรอง', $byStatus['spare'], '#f59e0b', 'spare']];
      $acc = 0; $stops = [];
      foreach ($donut as $d) { $p = pct($d[1], $total); $stops[] = $d[2] . ' ' . $acc . '% ' . ($acc + $p) . '%'; $acc += $p; }
      if ($acc < 100 && $stops) $stops[] = '#e7e0f5 ' . $acc . '% 100%';
    ?>
    <div class="dash-extra-cols">
      <div>
        <h3>สัดส่วนตามประเภท</h3>
        <div class="donut-wrap">
          <div class="donut" style="background:conic-gradient(<?= implode(',', $stops) ?>)"><div class="donut-hole"><b><?= number_format($total) ?></b><span>เครื่อง</span></div></div>
          <div class="donut-legend">
            <?php foreach ($donut as $d) { ?>
            <div class="lg-row clickable" onclick="showListModal(<?= h(json_encode($d[0], JSON_UNESCAPED_UNICODE)) ?>, '<?= $B ?>/dashboard_data.php?type=status&v=<?= $d[3] ?>', '<?= $B ?>/assets.php?status=<?= $d[3] ?>')">
              <span class="lg-dot" style="background:<?= $d[2] ?>"></span><?= h($d[0]) ?> <b><?= number_format($d[1]) ?></b>
            </div>
            <?php } ?>
          </div>
        </div>
      </div>
      <div>
        <h3>ตามหมวดสินค้า</h3>
        <?php $ci = 0; foreach ($perCat as $cat => $c) { $col = $PALETTE[$ci++ % count($PALETTE)]; ?>
        <div class="hbar-row clickable" onclick="showListModal(<?= h(json_encode("หมวด: $cat", JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=category&v=" . rawurlencode($cat)) ?>', '')">
          <div class="hbar-label"><?= h($cat) ?></div>
          <div class="hbar-track"><div class="hbar-fill" style="width:<?= pct($c, $maxCat) ?>%; background:<?= $col ?>"></div></div>
          <div class="hbar-val"><?= number_format($c) ?></div>
        </div>
        <?php } ?>
      </div>
    </div>
  </div>
</details>

<?php if ($stock['ok'] || $feed) { ?>
<div class="dash-lists-2col">
  <?php if ($stock['ok']) { ?>
  <div class="panel">
    <?= ui_heading('alert', 'อะไหล่ใกล้หมด', 'h3') ?>
    <?php if (!$stock['low_list']) { ?>
      <p class="muted" style="padding:8px 0">ไม่มีรายการใกล้หมด — สต็อกทุกตัวเพียงพอ</p>
    <?php } else { ?>
    <table class="list low-stock-list">
      <?php foreach ($stock['low_list'] as $p) {
          $pctLeft = $p['min_stock'] > 0 ? min(100, round($p['quantity'] / max(1, $p['min_stock'] * 2) * 100)) : 50;
          $crit = (int)$p['quantity'] <= max(1, (int)$p['min_stock'] / 2);
          $icon = isset($lowIcons[$p['code']]) ? $lowIcons[$p['code']] : '';
      ?>
      <tr>
        <td class="ls-img"><?php if ($icon) { echo img_tag($icon, $p['name'], 'ls-thumb'); } else { echo '<span class="ls-thumb ls-thumb-ph">' . ui_icon_html('parts', 15) . '</span>'; } ?></td>
        <td>
          <b><?= h($p['name']) ?></b>
          <span class="muted" style="font-size:12px"> <?= h($p['code']) ?></span>
          <div class="ls-bar"><i style="width:<?= $pctLeft ?>%;background:<?= $crit ? 'var(--danger)' : 'var(--warning)' ?>"></i></div>
        </td>
        <td style="text-align:right;white-space:nowrap"><b><?= number_format($p['quantity']) ?></b> <span class="muted"><?= h($p['unit'] ?: 'ชิ้น') ?></span></td>
        <td><span class="badge-pill <?= $crit ? 'bp-danger' : 'bp-warning' ?>"><?= $crit ? 'วิกฤต' : 'ใกล้หมด' ?></span></td>
      </tr>
      <?php } ?>
    </table>
    <p style="margin-top:8px"><a href="<?= h($partsBase) ?>/pages/products.php">ดูทั้งหมด (<?= number_format($stock['low']) ?> รายการ) ›</a></p>
    <?php } ?>
  </div>
  <?php } ?>

  <?php if ($feed) { ?>
  <div class="panel">
    <?= ui_heading('history', 'ความเคลื่อนไหวล่าสุด (2 ระบบ)', 'h3') ?>
    <div class="dash-feed">
      <?php foreach ($feed as $ev) {
          $sysKey = (string)($ev['system_key'] ?? '');
          $sysLabel = defined('ACTIVITY_LOG_SYSTEM_LABELS') && isset(ACTIVITY_LOG_SYSTEM_LABELS[$sysKey])
              ? ACTIVITY_LOG_SYSTEM_LABELS[$sysKey] : $sysKey;
          $t = strtotime((string)($ev['created_at'] ?? ''));
      ?>
      <div class="dash-ev">
        <span class="dash-ev-sys"><?= h($sysLabel) ?></span>
        <p><b><?= h((string)($ev['actor_name'] ?? '')) ?></b> · <?= h((string)($ev['summary'] ?? '')) ?></p>
        <span class="dash-ev-t"><?= $t ? h(date('d/m H:i', $t)) : '' ?></span>
      </div>
      <?php } ?>
    </div>
    <p style="margin-top:8px"><a href="<?= $B ?>/activity_logs.php">ดู Activity Log ทั้งหมด ›</a></p>
  </div>
  <?php } ?>
</div>
<?php } ?>

<div class="panel">
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
<?php page_footer();
