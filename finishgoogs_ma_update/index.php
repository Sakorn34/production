<?php
/** index.php — Dashboard รวม 2 ระบบ: ทะเบียนเครื่อง + สต็อกอะไหล่ (layout v2) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/part_stock_bridge.php';
require_once __DIR__ . '/includes/dashboard_chart.php';
require_login();

// สถานะ sync จาก cron/sync_asset_status.php หรือ database/tools/sync_asset_status.php
// ไม่รัน full sync บนหน้า dashboard — 18k+ เครื่องใช้เวลานานและเสี่ยง timeout

// ---- สรุปสถานะ ----
$byStatus = array_fill_keys(status_list(), 0);
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
    $stock['low']   = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE quantity <= min_stock AND is_active = 1')->fetchColumn();
    $stock['in_today']  = (int)$pdo->query('SELECT COALESCE(SUM(quantity),0) FROM stock_in WHERE DATE(created_at) = CURDATE()')->fetchColumn();
    $stock['out_today'] = (int)$pdo->query("SELECT COALESCE(SUM(soi.quantity),0) FROM stock_out_items soi JOIN stock_out so ON so.id = soi.stock_out_id WHERE DATE(so.created_at) = CURDATE()")->fetchColumn();
    $stock['ok'] = true;
} catch (Throwable $e) {
    // แสดง dashboard ฝั่งเครื่องต่อได้แม้ต่อ DB สต็อกไม่ได้
}

// ---- ผลิตรายปี (6 ปีล่าสุด) + รายเดือนต่อปี (drill จากกรaph stacked) ----
$dashProdYears = [];
$res = qr(
    'SELECT YEAR(a.produced_at) y, ' . asset_status_count_select_sql('a') .
    ' FROM assets a WHERE a.produced_at IS NOT NULL GROUP BY y ORDER BY y DESC LIMIT 6'
);
while ($r = $res->fetch_assoc()) {
    $dashProdYears[(int) $r['y']] = asset_status_counts_from_row($r);
}
$dashProdYears = array_reverse($dashProdYears, true);

$dashProdYearPoints = [];
$dashProdMonthPointsByYear = [];
if ($dashProdYears) {
    $minY = (int) min(array_keys($dashProdYears));
    $maxY = (int) max(array_keys($dashProdYears));
    foreach (array_keys($dashProdYears) as $y) {
        $dashProdMonthPointsByYear[$y] = [];
        for ($m = 1; $m <= 12; $m++) {
            $dashProdMonthPointsByYear[$y][$m] = asset_status_empty_counts();
        }
    }
    $rangeStart = sprintf('%04d-01-01', $minY);
    $rangeEnd = sprintf('%04d-01-01', $maxY + 1);
    $res = qr(
        'SELECT YEAR(a.produced_at) y, MONTH(a.produced_at) m, ' . asset_status_count_select_sql('a') .
        ' FROM assets a WHERE a.produced_at>=? AND a.produced_at<? GROUP BY y, m',
        'ss',
        [$rangeStart, $rangeEnd]
    );
    while ($r = $res->fetch_assoc()) {
        $yKey = (int) $r['y'];
        if (isset($dashProdMonthPointsByYear[$yKey])) {
            $dashProdMonthPointsByYear[$yKey][(int) $r['m']] = asset_status_counts_from_row($r);
        }
    }

    foreach ($dashProdYears as $y => $counts) {
        $y = (int) $y;
        $label = (string) thai_buddhist_year($y);
        $titlePrefix = 'การผลิตปี ' . $label;
        $baseUrl = BASE_URL . '/dashboard_data.php?type=year&v=' . $y;
        $pt = dash_chart_point($label, 'ปี ' . $label, $counts, $titlePrefix, $baseUrl);
        $pt['inline_year'] = $y;
        $dashProdYearPoints[] = $pt;

        $monthPoints = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $mCounts = $dashProdMonthPointsByYear[$y][$m];
            $mTitlePrefix = 'รุ่นที่ผลิตเดือน ' . thai_month_period_label($ym);
            $mBaseUrl = BASE_URL . '/dashboard_data.php?type=month_models&v=' . rawurlencode($ym);
            $mp = dash_chart_point(
                thai_month_short($ym),
                thai_month_period_label($ym),
                $mCounts,
                $mTitlePrefix,
                $mBaseUrl
            );
            // เดือนที่ยังมาไม่ถึง ต้องแสดงต่างจากเดือนที่ผลิต 0 เครื่อง
            $mp["is_future"] = $ym > date("Y-m");
            $monthPoints[] = $mp;
        }
        $dashProdMonthPointsByYear[$y] = $monthPoints;
    }
}

// ---- จำนวนเครื่องรายรุ่น ----
$perModel = [];
$res = qr("SELECT p.id pid, p.name, p.icon_path, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY p.id ORDER BY c DESC");
while ($r = $res->fetch_assoc()) $perModel[] = $r;
$maxPM = 1; foreach ($perModel as $m) $maxPM = max($maxPM, (int)$m['c']);

// จำนวนแยกตามสถานะ สำหรับแถบ stack ในการ์ด — แยกเป็น query ที่สองแล้ว pivot ใน PHP
// เร็วกว่าการใส่ SUM(status=..) สี่ตัวในคิวรีเดียว (56ms เทียบ 83ms) เพราะ SUM บังคับให้
// เทียบสตริงทีละแถวทั้ง 18,538 แถว ส่วน GROUP BY ใช้ index ได้
// ถ้าเพิ่ม index ผสม (product_id, status) จะเหลือ 16ms ซึ่งเร็วกว่าตอนยังไม่มีฟีเจอร์นี้
$perModelStatus = [];
$rs = qr("SELECT product_id, status, COUNT(*) c FROM assets GROUP BY product_id, status");
while ($r = $rs->fetch_assoc()) {
    $perModelStatus[(int) $r['product_id']][(string) $r['status']] = (int) $r['c'];
}

// ---- รายการอะไหล่ (สต็อก) สำหรับสลับมุมมองบน Dashboard ----
$perPart = [];
$maxPart = 1;
$partIcons = [];
$partProdLabels = [];
if ($stock['ok']) {
    try {
        require_once dirname(__DIR__) . '/parts/includes/helpers.php';
        ensureProductColumns($pdo);
        // อะไหล่ที่ปิดการใช้งานแล้วไม่ต้องแสดงบนแดชบอร์ด (ทั้งกราฟรายตัว, ตัวนับควรสั่งเพิ่ม/หมดแล้ว)
        $perPart = $pdo->query(
            'SELECT id, code, name, quantity, unit, min_stock, purchase_link, supplier
             FROM products WHERE is_active = 1 ORDER BY quantity DESC'
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
    // นับตามสถานะจาก shared/stock_status.php — แท็บ "ควรสั่งเพิ่ม" รวม critical ด้วย
    // (ทั้งคู่อยู่ที่หรือต่ำกว่าขั้นต่ำ) ส่วน out แยกแท็บของตัวเอง สองแท็บจึงไม่ทับกัน
    $status = stock_status_key((int) $p['quantity'], (int) $p['min_stock']);
    if ($status === 'out') {
        $perPartOutCount++;
    } elseif ($status === 'critical' || $status === 'low') {
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

function pct($v, $t) { return $t > 0 ? round($v / $t * 100) : 0; }

/**
 * ความยาวแถบสต็อกอะไหล่ (%) — เทียบกับขั้นต่ำของอะไหล่ตัวเอง ไม่ใช่ตัวที่มีเยอะสุดในระบบ
 *
 * เดิมใช้ qty/maxPart ทำให้แถบตอบว่า "มีเยอะแค่ไหนเทียบกับอะไหล่ที่มีเยอะสุด" ซึ่งไม่เกี่ยว
 * กับสถานะสต็อกเลย — ของ 2 ชิ้นที่ขั้นต่ำ 1 (ปกติดี) ได้แถบ 0.1% ขณะที่ตัวที่ใกล้ถึงขั้นต่ำ
 * แต่จำนวนเยอะกลับได้แถบยาว อ่านแล้วเข้าใจผิด
 *
 * เต็มแถบเมื่อมีของ 3 เท่าของขั้นต่ำ · ที่ขั้นต่ำพอดีได้ราว 33% · หมดจริงเหลือแถบบางพอเห็นสี
 * (ไม่ใช้ค่าต่ำสุดสูง ๆ เพราะจะทำให้ของที่หมดแล้วดูเหมือนยังมีเหลืออยู่)
 *
 * @param int $qty คงเหลือ
 * @param int $min ขั้นต่ำ
 * @return int 0-100
 */
function part_stock_bar_pct(int $qty, int $min): int {
    if ($qty <= 0) {
        return 0;
    }
    $full = $min > 0 ? $min * 3 : max($qty, 1);
    $p = (int) round($qty / $full * 100);
    return max(6, min(100, $p)); // 6% = แค่พอเห็นสี ไม่สื่อว่ามีของเหลือมาก
}

/** เปอร์เซ็นต์สำหรับแสดง KPI — ไม่ปัดเป็น 0% เมื่อยังมีค่า (เช่น 77 จาก 18,228) */
function pct_label($v, $t) {
    if ($t <= 0 || $v <= 0) {
        return '0%';
    }
    $p = $v / $t * 100;
    if ($p < 0.05) {
        return '<1%';
    }
    if ($p < 10) {
        $s = number_format($p, 1, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return $s . '%';
    }
    return round($p) . '%';
}

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
 * @param bool   $subHtml  อนุญาต HTML ใน $sub (เช่น <br>)
 * @param bool   $numHtml  อนุญาต HTML ใน $numText
 */
function kpi($num, $label, $icon, $tone, $sub = '', $onclick = '', $sys = '', $numText = '', $subHtml = false, $numHtml = false, $href = '') {
    // การ์ดที่กดได้ต้องเป็น <a> ไม่ใช่ <div onclick> — ไม่งั้น Tab ไปไม่ถึง
    // โปรแกรมอ่านหน้าจอไม่รู้ว่ากดได้ และเปิดแท็บใหม่/คัดลอกลิงก์ไม่ได้
    // ถ้าเป็น modal: คลิกปกติเปิด modal (return false) ส่วน Ctrl/กลางคลิกไปหน้าเต็มตาม href
    $tag = $href !== '' ? 'a' : 'div';
    $attr = '';
    if ($href !== '') {
        $attr .= ' href="' . h($href) . '"';
        if ($onclick !== '') {
            $attr .= ' onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;'
                   . h($onclick) . ';return false;"';
        }
        $attr .= ' class="kpi kpi-' . h($tone) . ' clickable"';
    } elseif ($onclick !== '') {
        $attr .= ' class="kpi kpi-' . h($tone) . ' clickable" onclick="' . h($onclick) . '"'
               . ' role="button" tabindex="0"'
               . ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();this.click();}"';
    } else {
        $attr .= ' class="kpi kpi-' . h($tone) . '"';
    }
    echo '<' . $tag . $attr . '>';
    if ($sys !== '') echo '<span class="kpi-sys">' . h($sys) . '</span>';
    echo '<div class="kpi-top"><span class="kpi-ic">' . ui_icon_html($icon, 14) . '</span> ' . h($label) . '</div>';
    if ($numText !== '') {
        $numClass = 'kpi-num' . ($numHtml ? ' kpi-num-stack' : '');
        echo '<b class="' . $numClass . '">' . ($numHtml ? $numText : h($numText)) . '</b>';
    } else {
        echo '<b class="kpi-num">' . number_format($num) . '</b>';
    }
    if ($sub !== '') {
        $subClass = 'kpi-sub' . ($subHtml ? ' kpi-sub-stack' : '');
        echo '<div class="' . $subClass . '">' . ($subHtml ? $sub : h($sub)) . '</div>';
    }
    echo '</' . $tag . '>';
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
<div class="dash-top-row">
  <?php
  // การ์ดรวมสถานะเครื่อง — เดิมแยกเป็น 7 ใบ (ทั้งหมด + 6 สถานะ) กินพื้นที่ทั้งแถวแรก
  // ทั้งที่ตัวเลขชุดเดียวกันมีอยู่แล้วในกราฟ "ภาพรวมการผลิตรายปี" ถัดลงไปแค่จอเดียว
  // รวมเป็นใบเดียว ใช้สีชุดเดียวกับกราฟนั้น (dash_chart_color) คนดูจะได้เห็นว่าเป็น
  // ข้อมูลชุดเดียวกัน ไม่ใช่ตัวเลขคนละที่มา · สต็อกคงเหลือรวม (ผลรวมข้ามหน่วย ไม่มีความหมาย
  // ให้ตัดสินใจอะไรได้) ตัดออกไปเลย ตัวเลขจริงแยกหน่วยดูได้จากตาราง "รายการอะไหล่" ด้านล่าง
  //
  // การ์ดนี้อยู่นอก .kpi-grid โดยตั้งใจ — ตอนอยู่ใน grid เดียวกับ 2 การ์ดอะไหล่ด้านล่าง
  // grid ต้องแบ่งคอลัมน์ให้พอกับการ์ดนี้ (กว้างเต็มแถว) แต่ 2 การ์ดเล็กใช้แค่คอลัมน์ละ 1 ช่อง
  // เหลือช่องว่างลอยข้าง ๆ เกือบครึ่งแถว จึงแยกเป็น 2 คอลัมน์ตายตัวแทน: การ์ดนี้ฝั่งซ้าย
  // การ์ดอะไหล่ 2 ใบเรียงซ้อนกันฝั่งขวา ความสูงเท่ากันพอดีโดยไม่ต้องเดา
  $statusNote = [
      'new'     => '',
      'rental'  => '',
      'spare'   => 'คลังเครื่องสำรอง — ทดแทนเครื่องเช่า',
      'sold'    => '',
      'retired' => 'ปลดระวางจากระบบเช่าแล้ว',
      'lost'    => 'ระบบเช่าแจ้งสูญหาย',
  ];
  ?>
  <div class="kpi kpi-primary kpi-status-overview">
    <div class="kpi-top"><span class="kpi-ic"><?= ui_icon_html('assets', 14) ?></span> เครื่องทั้งหมด</div>
    <b class="kpi-num"><?= number_format($total) ?></b>
    <?php // status_th_chip() ให้ชื่อสั้น (ใหม่/เช่า/สำรอง…) — ใช้ตัวเดียวกับ legend ของกราฟ
         // "ภาพรวมการผลิตรายปี" ด้านล่าง ให้สองการ์ดอ่านเป็นชุดเดียวกัน · status_th() (เต็ม
         // "เครื่องใหม่") เก็บไว้ใช้เฉพาะหัว modal ที่กดเปิดดูรายรุ่น ซึ่งเป็นข้อความเดี่ยว
         // ไม่ได้อยู่ติดกับตัวเลข จึงยังต้องการคำเต็มเพื่อความชัดเจน
         if ($total > 0) { ?>
    <div class="status-bar" role="img" aria-label="สัดส่วนเครื่องแยกตามสถานะ">
      <?php foreach (status_list() as $st):
          $n = (int) ($byStatus[$st] ?? 0);
          if ($n <= 0) { continue; }
          $flex = max($n / $total * 100, 0.6); // ขั้นต่ำกันสถานะที่มีไม่กี่เครื่อง (เช่นสูญหาย) หายไปจากแท่งเลย
          $modalTitle = status_th($st) . ' — รายรุ่น';
          $dataUrl = "$B/dashboard_data.php?type=asset_models&st=$st";
          $href = "$B/assets.php?status=$st";
      ?>
      <a class="status-bar-seg" style="flex:<?= $flex ?> 1 0; background:<?= h(dash_chart_color($st)) ?>"
         href="<?= h($href) ?>"
         onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;<?= h(modal_js($modalTitle, $dataUrl, $href)) ?>;return false;"
         title="<?= h(status_th_chip($st) . ': ' . number_format($n) . ' เครื่อง (' . pct_label($n, $total) . ')') ?>"></a>
      <?php endforeach; ?>
    </div>
    <?php } ?>
    <div class="status-legend">
      <?php foreach (status_list() as $st):
          $n = (int) ($byStatus[$st] ?? 0);
          $modalTitle = status_th($st) . ' — รายรุ่น';
          $dataUrl = "$B/dashboard_data.php?type=asset_models&st=$st";
          $href = "$B/assets.php?status=$st";
      ?>
      <a class="status-legend-item" href="<?= h($href) ?>"
         onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;<?= h(modal_js($modalTitle, $dataUrl, $href)) ?>;return false;"
         <?= $statusNote[$st] !== '' ? 'title="' . h($statusNote[$st]) . '"' : '' ?>>
        <i class="status-dot" style="background:<?= h(dash_chart_color($st)) ?>"></i>
        <span class="status-legend-label"><?= h(status_th_chip($st)) ?></span>
        <b class="status-legend-num"><?= number_format($n) ?></b>
        <span class="status-legend-pct"><?= h(pct_label($n, $total)) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="dash-side-kpis">
    <?php
    if ($stock['ok']) {
        kpi($stock['low'], 'อะไหล่ควรสั่งเพิ่ม', 'alert', $stock['low'] > 0 ? 'warning' : 'success', $stock['low'] > 0 ? '<span class="kpi-down">ต้องตรวจสอบ/สั่งซื้อ</span>' : 'ทุกรายการเพียงพอ',
            $stock['low'] > 0
                ? modal_js('อะไหล่ที่ควรสั่งเพิ่ม (' . number_format($stock['low']) . ' รายการ)', "$B/dashboard_data.php?type=low_stock", "$partsBase/pages/products.php")
                : "location.href='" . h($partsBase) . "/pages/products.php'",
            'อะไหล่', '', true, false, $partsBase . '/pages/products.php');
        kpi(0, 'รับเข้า / เบิกออก', 'history', 'info', 'ความเคลื่อนไหววันนี้',
            modal_js('รับเข้า / เบิกออกวันนี้', "$B/dashboard_data.php?type=stock_today", "$partsBase/pages/history.php"), 'วันนี้',
            'รับเข้า ' . number_format($stock['in_today']) . ' ชิ้น · เบิกออก ' . number_format($stock['out_today']) . ' ชิ้น',
            false, true, $partsBase . '/pages/history.php');
    } else {
        echo '<div class="kpi kpi-warning"><div class="kpi-top"><span class="kpi-ic">' . ui_icon_html('alert', 14) . '</span> สต็อกอะไหล่</div><b class="kpi-num">—</b><div class="kpi-sub">เชื่อมต่อระบบสต็อกไม่ได้</div></div>';
    }
    ?>
  </div>
</div>

<div class="panel dash-prod-chart" id="dash-prod-chart">
  <div class="dash-prod-head">
    <div class="dash-prod-head-main">
      <button type="button" class="dash-prod-back" id="dash-prod-back" hidden aria-label="กลับรายปี">‹</button>
      <div class="dash-prod-head-text">
        <h3 class="dash-prod-title" id="dash-prod-title">ภาพรวมการผลิตรายปี</h3>
        <p class="muted dash-prod-subtitle" id="dash-prod-subtitle">6 ปีล่าสุด · แยกตามสถานะเครื่อง</p>
      </div>
    </div>
  </div>
  <?php if ($dashProdYearPoints) { ?>
  <div id="dash-prod-year-view">
    <?php render_dashboard_stacked_chart(
        $dashProdYearPoints,
        true,
        null,
        '',
        ['compact' => true, 'inline_drill' => true, 'root_class' => 'dash-prod-stacked', 'show_hint' => false]
    ); ?>
  </div>
  <div id="dash-prod-month-views" hidden>
    <?php foreach ($dashProdMonthPointsByYear as $y => $monthPoints) { ?>
    <div class="dash-prod-month-pane" data-year="<?= (int) $y ?>" data-be-label="<?= (int) thai_buddhist_year((int) $y) ?>" hidden>
      <?php render_dashboard_stacked_chart(
          $monthPoints,
          true,
          null,
          'ปี ' . thai_buddhist_year((int) $y),
          ['compact' => true, 'root_class' => 'dash-prod-stacked', 'show_hint' => false]
      ); ?>
    </div>
    <?php } ?>
  </div>
  <?php } else { ?>
  <p class="muted">ยังไม่มีข้อมูลการผลิต</p>
  <?php } ?>
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
          <button type="button" class="dash-parts-filter-btn" data-filter="low" role="tab" aria-selected="false"<?= $perPartLowCount <= 0 ? ' disabled title="ไม่มีอะไหล่ที่ควรสั่งเพิ่ม"' : '' ?>>ควรสั่งเพิ่ม<?= $perPartLowCount > 0 ? ' (' . number_format($perPartLowCount) . ')' : '' ?></button>
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
    <?php foreach ($perModel as $m) {
        // สัดส่วนตามสถานะ ใช้สีเดียวกับกราฟด้านบน (dash_chart_color) ให้ legend เดียวอ่านได้ทั้งหน้า
        $segs = [];
        $tipParts = [];
        foreach (status_list() as $st) {
            $n = (int) ($perModelStatus[(int) $m['pid']][$st] ?? 0);
            if ($n <= 0) { continue; }
            $segs[] = '<span class="model-card-seg" style="flex:' . $n
                . ';background:' . h(dash_chart_color($st)) . '"></span>';
            $tipParts[] = status_th($st) . ' ' . number_format($n);
        }
        $barTip = $m['name'] . ' — ' . number_format($m['c']) . ' เครื่อง'
            . ($tipParts ? ' · ' . implode(' · ', $tipParts) : '');
    ?>
    <?php // เป็น <a> เพื่อให้ Tab ถึงและเปิดแท็บใหม่ได้ · คลิกปกติยังเปิด modal เหมือนเดิม ?>
    <a class="model-card clickable" href="<?= h("$B/assets.php?product=" . urlencode($m['name'])) ?>"
         onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;showListModal(<?= h(json_encode('การผลิตรุ่น ' . $m['name'] . ' รายปี', JSON_UNESCAPED_UNICODE)) ?>, '<?= h("$B/dashboard_data.php?type=product_years&v=" . (int)$m['pid']) ?>', '<?= h("$B/assets.php?product=" . urlencode($m['name'])) ?>');return false;">
      <div class="model-card-img">
        <?= img_tag($m['icon_path'], $m['name'], 'model-thumb') ?>
      </div>
      <div class="model-card-body">
        <div class="model-card-name" title="<?= h($m['name']) ?>"><?= h($m['name']) ?></div>
        <div class="model-card-bar" title="<?= h($barTip) ?>">
          <div class="model-card-fill" style="width:<?= pct($m['c'], $maxPM) ?>%"><?= implode('', $segs) ?></div>
        </div>
        <div class="model-card-num"><?= number_format($m['c']) ?></div>
      </div>
    </a>
    <?php } ?>
  </div>
  </div>

  <div class="dash-view-pane" id="dash-view-parts" role="tabpanel" hidden>
  <?php if (!$stock['ok'] || !$perPart) { ?>
    <p class="muted" style="padding:12px 0">ไม่มีข้อมูลสต็อกอะไหล่</p>
  <?php } else { ?>
  <div class="model-card-grid" id="dash-parts-grid">
    <?php foreach ($perPart as $p) {
        $qty = (int) $p['quantity'];
        $min = (int) $p['min_stock'];
        $stockStatus = stock_status_key($qty, $min);
        $stockMeta = stock_status_meta($stockStatus);
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
        // แถบสีสื่อสถานะอย่างเดียว ใช้ชุดสีเดียวกันทุกรายการ (เดิม "ปกติ" สุ่มสีจาก PALETTE ทำให้อ่านสถานะไม่ได้)
        $barColor = $stockMeta['color'];
        $barPct = part_stock_bar_pct($qty, $min);
    ?>
    <?php // การ์ดนี้มีลิงก์ "สั่งซื้อ" อยู่ข้างใน จึงทำเป็น <a> ซ้อนไม่ได้
         // ใช้ role=button + tabindex เพื่อให้ Tab ถึงและกด Enter/Space ได้ ?>
    <div class="model-card clickable dash-part-card" role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();this.click();}"
         data-stock-status="<?= h($stockStatus) ?>" data-supplier="<?= h($supplier) ?>"
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
        <div class="model-card-bar" title="คงเหลือ <?= number_format($qty) ?> · ขั้นต่ำ <?= number_format($min) ?> <?= h($p['unit'] ?: 'ชิ้น') ?>"><div class="model-card-fill" style="width:<?= $barPct ?>%; background:<?= $barColor ?>"></div></div>
        <div class="model-card-foot">
          <?php // ป้ายสถานะมาจาก shared/stock_status.php ที่เดียว — ห้ามคำนวณเองที่นี่ ?>
          <div class="model-card-num"><?= number_format($qty) ?> <?= h($p['unit'] ?: 'ชิ้น') ?> · <span class="<?= h($stockMeta['tone']) ?>"><?= h($stockMeta['label']) ?></span></div>
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
    parts:  { title: 'สต็อกอะไหล่' }
  };
  var emptyMsgs = {
    all: 'ไม่มีอะไหล่ตามตัวกรองที่เลือก',
    low: 'ไม่มีอะไหล่ที่ควรสั่งเพิ่มตามตัวกรองที่เลือก',
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
      // เทียบเป็นชุด ไม่ใช่ค่าเดียว — สถานะมี 5 แบบแต่แท็บมี 3 ถ้าเทียบตรง ๆ
      // แท็บ "ควรสั่งเพิ่ม" จะกรอง critical ไม่เจอ แล้วตัวเลขในวงเล็บจะไม่ตรงกับแถวที่แสดง
      var stockOk = currentPartsFilter === 'all'
        || (currentPartsFilter === 'low' && (status === 'low' || status === 'critical'))
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
(function(){
  var chart = document.getElementById('dash-prod-chart');
  var yearView = document.getElementById('dash-prod-year-view');
  var monthViews = document.getElementById('dash-prod-month-views');
  var backBtn = document.getElementById('dash-prod-back');
  var titleEl = document.getElementById('dash-prod-title');
  var subtitleEl = document.getElementById('dash-prod-subtitle');
  if (!chart || !yearView) return;

  function setHeadline(title, subtitle) {
    if (titleEl) titleEl.textContent = title;
    if (subtitleEl) subtitleEl.textContent = subtitle;
  }

  function showYearView() {
    yearView.hidden = false;
    if (monthViews) monthViews.hidden = true;
    if (backBtn) backBtn.hidden = true;
    if (monthViews) {
      monthViews.querySelectorAll('.dash-prod-month-pane').forEach(function(pane) {
        pane.hidden = true;
      });
    }
    setHeadline('ภาพรวมการผลิตรายปี', '6 ปีล่าสุด · แยกตามสถานะเครื่อง');
  }

  function showMonthView(y) {
    yearView.hidden = true;
    if (monthViews) monthViews.hidden = false;
    if (backBtn) backBtn.hidden = false;
    var beLabel = String(y);
    if (monthViews) {
      monthViews.querySelectorAll('.dash-prod-month-pane').forEach(function(pane) {
        var match = pane.getAttribute('data-year') === String(y);
        pane.hidden = !match;
        if (match) {
          beLabel = pane.getAttribute('data-be-label') || beLabel;
        }
      });
    }
    setHeadline('ภาพรวมรายเดือน ปี ' + beLabel, '12 เดือน · กดตัวเลขหรือสีในแท่งเพื่อดูรายละเอียด');
  }

  chart.addEventListener('click', function(e) {
    if (!yearView.contains(e.target)) return;
    if (e.target.closest('.dash-bar-seg')) return;
    var trigger = e.target.closest('[data-inline-year]');
    if (!trigger) return;
    var y = trigger.getAttribute('data-inline-year');
    if (y) showMonthView(y);
  });

  if (backBtn) backBtn.addEventListener('click', showYearView);
})();
</script>

<?php page_footer();
