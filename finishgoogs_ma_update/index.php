<?php
/** index.php — Dashboard รวม 2 ระบบ: ทะเบียนเครื่อง + สต็อกอะไหล่ (layout v2) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/part_stock_bridge.php';
require_once __DIR__ . '/includes/dashboard_chart.php';
require_once __DIR__ . '/includes/stock_scan.php';
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
$dashProdTrend = null;
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

    // ต้องคิดก่อน loop ข้างล่าง เพราะ loop นั้นเขียนทับ $dashProdMonthPointsByYear
    // ด้วยจุดกราฟ ทำให้จำนวนดิบรายเดือนหายไป
    $dashProdTrend = dash_month_trend($dashProdMonthPointsByYear);

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
$res = qr("SELECT p.id pid, p.name, p.icon_path, p.product_code, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
           GROUP BY p.id ORDER BY c DESC");
// รุ่นที่ตั้ง "ซ่อน" ไว้ในหลังบ้าน (ขั้นต่ำและการแจ้งเตือนรายรุ่น) — แผนกอื่นดูแลสต็อกเอง ไม่ต้องขึ้นในตารางนี้
require_once dirname(__DIR__) . '/shared/finishgood_shortage_filter.php';
$hiddenModelCodes = array_flip(fg_shortage_hidden_codes());
while ($r = $res->fetch_assoc()) {
    if (!isset($hiddenModelCodes[strtoupper(trim((string) $r['product_code']))])) {
        $perModel[] = $r;
    }
}

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
<?php // แท็บเฉพาะมือถือ (แบบ ข · 18 ก.ย. 2026) — จอเล็กเห็นทีละเรื่อง ไม่ต้องเลื่อนผ่านทุกการ์ด
      // จอใหญ่ซ่อนแท็บ ทุกส่วนแสดงพร้อมกันเหมือนเดิม (ดู .dash-m-tabs ใน theme-v2.css) ?>
<div class="dash-m-tabs" role="tablist" aria-label="เลือกดูบน Dashboard">
  <button type="button" class="dash-m-tab" data-mtab="stock" role="tab">สต็อกเครื่อง</button>
  <button type="button" class="dash-m-tab" data-mtab="prod" role="tab">การผลิต</button>
  <button type="button" class="dash-m-tab" data-mtab="parts" role="tab">อะไหล่</button>
</div>
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
  <?php
  // ลำดับใน status_list() เดินตามวงจรชีวิตเครื่อง (ใหม่ → เช่า → สำรอง → ขาย → ปลด → หาย)
  // ซึ่งอ่านเป็นเรื่องราวได้ แต่ตอบไม่ได้ว่าสถานะไหนเยอะสุด ทั้งที่นั่นคือคำถามแรก
  // ของการ์ดนี้ · จำนวนเท่ากันให้คงลำดับวงจรชีวิตไว้ (usort ยังไม่ stable บน PHP 7.3
  // ที่เว็บรันอยู่ จึงต้องตัดสินด้วยลำดับเดิมเอง ไม่ใช่ปล่อยให้ขึ้นกับอัลกอริทึม)
  $statusCycleIdx = array_flip(status_list());
  $statusByCount  = status_list();
  usort($statusByCount, function ($a, $b) use ($byStatus, $statusCycleIdx) {
      $d = (int) ($byStatus[$b] ?? 0) - (int) ($byStatus[$a] ?? 0);
      return $d !== 0 ? $d : ($statusCycleIdx[$a] - $statusCycleIdx[$b]);
  });
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
      <?php foreach ($statusByCount as $st):
          $n = (int) ($byStatus[$st] ?? 0);
          if ($n <= 0) { continue; }
          $flex = max($n / $total * 100, 0.6); // ขั้นต่ำกันสถานะที่มีไม่กี่เครื่อง (เช่นสูญหาย) หายไปจากแท่งเลย
          $modalTitle = status_th($st) . ' — รายรุ่น';
          $dataUrl = "$B/dashboard_data.php?type=asset_models&st=$st";
          $href = "$B/assets.php?status=$st";
      ?>
      <a class="status-bar-seg" data-status="<?= h($st) ?>" style="flex:<?= $flex ?> 1 0; background:<?= h(dash_chart_color($st)) ?>"
         href="<?= h($href) ?>"
         onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;<?= h(modal_js($modalTitle, $dataUrl, $href)) ?>;return false;"
         title="<?= h(status_th_chip($st) . ': ' . number_format($n) . ' เครื่อง (' . pct_label($n, $total) . ')') ?>"></a>
      <?php endforeach; ?>
    </div>
    <?php } ?>
    <?php // กดที่ป้าย = filter ไฮไลต์เฉพาะสถานะนั้นทั้งการ์ดนี้และกราฟรายปีด้านล่าง (ดู
         // dashStatusFilter ท้ายไฟล์) ไม่พาไปหน้าไหน — ปุ่ม modifier+คลิก (Ctrl/⌘/กลาง)
         // ยังพาไปทะเบียนเครื่องกรองแล้วเหมือนเดิม สำหรับคนอยากได้รายชื่อเต็ม ?>
    <div class="status-legend">
      <?php foreach ($statusByCount as $st):
          $n = (int) ($byStatus[$st] ?? 0);
          $href = "$B/assets.php?status=$st";
      ?>
      <a class="status-legend-item" href="<?= h($href) ?>" data-status="<?= h($st) ?>"
         onclick="if(event.ctrlKey||event.metaKey||event.shiftKey||event.button===1)return true;dashStatusFilter.toggle('<?= h($st) ?>');return false;"
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
    <?php
    // ทั้งหน้าไม่มีตัวเลขไหนบอกทิศทางเลย ตอบได้แค่ "ตอนนี้มีเท่าไหร่"
    // ป้ายนี้อยู่ติดกราฟผลิตเพราะเป็นตัวเลขชุดเดียวกัน — เดือนนี้เทียบเดือนก่อน
    if ($dashProdTrend) {
        $tDiff = (int) $dashProdTrend['diff'];
        $tTone = $tDiff > 0 ? 'up' : ($tDiff < 0 ? 'down' : 'flat');
        $tSign = $tDiff > 0 ? '+' : ($tDiff < 0 ? '−' : '±');
        $tPct  = $dashProdTrend['pct'];
    ?>
    <div class="dash-prod-trend dash-trend-<?= h($tTone) ?>"
         title="<?= h(thai_month_period_label($dashProdTrend['now_ym']) . ' ผลิต ' . number_format($dashProdTrend['now'])
                    . ' เครื่อง · ' . thai_month_period_label($dashProdTrend['prev_ym']) . ' ผลิต '
                    . number_format($dashProdTrend['prev']) . ' เครื่อง') ?>">
      <span class="dash-trend-label"><?= h(thai_month_short($dashProdTrend['now_ym'])) ?></span>
      <b class="dash-trend-num"><?= number_format($dashProdTrend['now']) ?></b>
      <span class="dash-trend-unit">เครื่อง</span>
      <span class="dash-trend-delta">
        <?= h($tSign . number_format(abs($tDiff))) ?><?php
          // เดือนก่อนเป็น 0 คิดเปอร์เซ็นต์ไม่ได้ — แสดงแค่ส่วนต่าง
          if ($tPct !== null) { echo ' · ' . h(($tPct > 0 ? '+' : '') . $tPct . '%'); } ?>
        <span class="dash-trend-vs">เทียบ <?= h(thai_month_short($dashProdTrend['prev_ym'])) ?></span>
      </span>
    </div>
    <?php } ?>
  </div>
  <?php if ($dashProdYearPoints) { ?>
  <div id="dash-prod-year-view">
    <?php render_dashboard_stacked_chart(
        $dashProdYearPoints,
        true,
        null,
        '',
        ['compact' => true, 'inline_drill' => true, 'root_class' => 'dash-prod-stacked', 'show_hint' => false, 'hide_legend' => true]
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
          ['compact' => true, 'root_class' => 'dash-prod-stacked', 'show_hint' => false, 'hide_legend' => true]
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
      <span id="dash-inventory-title-text">จำนวนเครื่องแยกตามรุ่น</span>
      <span class="muted panel-meta" id="dash-inventory-meta"><?= count($perModel) ?> รุ่น</span>
    </h3>
    <div class="panel-head-actions">
      <?php // ป้ายปุ่มบอกสิ่งที่จะเห็น ไม่ใช่ชนิดข้อมูล — "รุ่นสินค้า" เดิมอ่านแล้วไม่รู้ว่าเป็นจำนวนเครื่องหรือรายชื่อรุ่น
           // ยอดที่ต้องผลิตเพิ่มไม่แยกแท็บแล้ว — ไปอยู่บนการ์ดรุ่นเดียวกัน แล้วกรองด้วยปุ่มใต้หัวข้อแทน ?>
      <div class="dash-view-toggle" role="tablist" aria-label="เลือกดู">
        <button type="button" class="dash-view-btn active" data-view="models" role="tab" aria-selected="true">จำนวนเครื่อง</button>
        <button type="button" class="dash-view-btn" data-view="parts" role="tab" aria-selected="false"
          <?= ($stock['ok'] && $perPart) ? '' : 'disabled title="เชื่อมต่อสต็อกอะไหล่ไม่ได้"' ?>>สต็อกอะไหล่</button>
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

  <?php // สีเครื่องใหม่/เครื่องเช่าต้องมาจาก palette สถานะชุดเดียวกับกราฟด้านบนเท่านั้น (shared/ui_status_palette.php)
        $stNew = status_palette_entry('new'); $stRent = status_palette_entry('rental'); ?>
  <div class="dash-view-pane" id="dash-view-models" role="tabpanel" style="<?= h('--st-new-bar:' . $stNew['bar'] . ';--st-new-fg:' . $stNew['fg'] . ';--st-rental-bar:' . $stRent['bar'] . ';--st-rental-fg:' . $stRent['fg']) ?>">
  <?php // สรุปยอดทั้งคลังก่อน แล้วค่อยไล่รายรุ่น — โผล่เมื่อคำนวณตัวเลขเสร็จ ?>
  <div class="dash-fg-summary" id="dash-fg-summary" hidden>
    <div class="dash-fg-tile"><span class="dash-fg-tile-label">เครื่องใหม่ในคลัง</span><span class="dash-fg-tile-num" id="dash-fg-sum-new">—</span></div>
    <div class="dash-fg-tile"><span class="dash-fg-tile-label">คลังพร้อมเช่า</span><span class="dash-fg-tile-num is-pool" id="dash-fg-sum-pool">—</span></div>
    <div class="dash-fg-tile"><span class="dash-fg-tile-label">ต้องผลิตเพิ่ม</span><span class="dash-fg-tile-num is-short" id="dash-fg-sum-models">—</span></div>
    <div class="dash-fg-tile"><span class="dash-fg-tile-label">ขาดรวม</span><span class="dash-fg-tile-num is-short" id="dash-fg-sum-gap">—</span></div>
  </div>
  <div class="dash-fg-filters" id="dash-fg-filters" hidden>
    <div class="dash-parts-filter" id="dash-fg-filter" role="tablist" aria-label="กรองรุ่นสินค้า">
      <button type="button" class="dash-parts-filter-btn active" data-fg-filter="all" role="tab" aria-selected="true">ทั้งหมด</button>
      <button type="button" class="dash-parts-filter-btn" data-fg-filter="short" role="tab" aria-selected="false">ต้องผลิตเพิ่ม</button>
      <button type="button" class="dash-parts-filter-btn" data-fg-filter="over" role="tab" aria-selected="false">ครบแล้ว</button>
      <?php // รุ่นที่เกินรอบนับ — นับไว้ฝั่ง PHP เพราะไม่ต้องรอตัวเลขสต็อก ?>
      <button type="button" class="dash-parts-filter-btn" data-fg-filter="due" role="tab" aria-selected="false" title="รุ่นที่ไม่ได้นับสต็อกเกิน <?= (int) stock_count_interval_days() ?> วัน หรือยังไม่เคยนับ">ถึงรอบนับ</button>
    </div>
    <span class="muted dash-fg-hint" id="dash-fg-hint"></span>
  </div>
  <?php // ตาราง ไม่ใช่การ์ด — 35 รุ่นเป็นการ์ดเรียงลงมาแล้วเลื่อนยาวมาก ตารางอ่านเทียบกันได้ในจอเดียว
        // data-pri = ระดับความสำคัญของคอลัมน์ (shared/ui_table.css ยุบ/ซ่อนให้เองตามความกว้าง)
        // กรอบเลื่อนเฉพาะรายการรุ่น หัวตารางติดขอบบน — ไม่ใช้ .table-wrap-fold เพราะ ui_table.js
        // ตั้งความสูงจากตำแหน่งบนจอ ตารางนี้อยู่ล่างของ Dashboard จะได้กรอบเตี้ยเกินไป ?>
  <div class="table-wrap dash-models-scroll">
  <?php // นับเฉพาะเครื่องสถานะ "ใหม่" ในทะเบียนเรา (ไม่รวมเครื่องที่ลงทะเบียนในระบบเช่า)
        // แถบ = เครื่องใหม่ ÷ ต้องมี — เต็มแถบคือพอแล้ว ?>
  <table class="list dash-models-table">
    <thead>
      <tr>
        <th data-pri="2" style="width:44px"></th>
        <th data-pri="1">รุ่น</th>
        <th data-pri="2" class="fg-bar-col" title="เต็มแถบ = ยอดที่ต้องมี · ส่วนสีจาง = เกินจากที่ต้องมี"><span class="fg-legend"><i class="is-new"></i>เครื่องใหม่</span> <span class="fg-legend"><i class="is-pool"></i>คลังพร้อมเช่า</span> <span class="fg-legend"><b class="fg-legend-mark">▼</b>ขั้นต่ำ</span> <span class="fg-legend"><b class="fg-legend-mark is-po">▼</b>ขั้นต่ำ+PO</span></th>
        <th data-pri="1" class="num-col">เครื่องใหม่</th>
        <th data-pri="2" class="num-col" title="ระบบเช่าเป็น finished goods รอปล่อยเช่า — นับรวมในยอดที่มี">คลังพร้อมเช่า</th>
        <th data-pri="2" class="num-col" title="(เครื่องใหม่ + คลังพร้อมเช่า) ÷ (ขั้นต่ำ + PO)">มี / ต้องมี</th>
        <th data-pri="1">ผล</th>
      </tr>
    </thead>
    <tbody id="dash-models-grid">
    <?php foreach ($perModel as $m) { ?>
    <?php $mCode = strtoupper(trim((string) $m['product_code'])); $mCount = stock_count_status((int) $m['pid']); ?>
    <?php // แบ่งแถวเป็น 2 โซนกด: รูป+ชื่อรุ่น = การผลิตรายปี · แถบสีถึงคอลัมน์สุดท้าย = หมายเลขสินค้าในสต็อก
          // (จัดการคลิกรวมที่ tbody ด้านล่าง) ?>
    <tr class="dash-model-row" data-code="<?= h($mCode) ?>" data-count="<?= (int) $m['c'] ?>" data-fg="none" data-due="<?= $mCount['due'] ? '1' : '0' ?>"
        data-name="<?= h($m['name']) ?>"
        data-years-url="<?= h("$B/dashboard_data.php?type=product_years&v=" . (int) $m['pid']) ?>"
        data-list-url="<?= h("$B/assets.php?product=" . urlencode($m['name'])) ?>">
      <td data-pri="2" class="fg-zone-name" title="กดดูการผลิตรายปี"><?= img_tag($m['icon_path'], $m['name'], 'model-thumb') ?></td>
      <td data-pri="1" class="fg-zone-name" title="กดดูการผลิตรายปี">
        <b class="fg-model-name"><?= h($m['name']) ?></b>
        <?php // รหัสรุ่น (ACC027 ฯลฯ) ไม่แสดง — ผู้ใช้ส่วนใหญ่ไม่ต้องรู้ ยังใช้จับคู่ข้อมูลผ่าน data-code ของแถวเหมือนเดิม ?>
        <?php if ($mCount['due']) { ?>
        <a class="fg-count-due" href="<?= h($B . '/stock_scan.php?pick=' . (int) $m['pid']) ?>" title="ถึงรอบนับสต็อก — กดเพื่อเริ่มนับรุ่นนี้"><?= h($mCount['label']) ?> · นับเลย ›</a>
        <?php } else { ?>
        <div class="fg-count-age"><?= h($mCount['label']) ?></div>
        <?php } ?>
      </td>
      <td data-pri="1" class="fg-bar-col fg-zone-stock">
        <?php // หมุดอยู่นอก .fg-bar เพราะแถบตัดส่วนที่ล้นทิ้ง (overflow hidden) ?>
        <div class="fg-bar-wrap" data-fg-cell="marks"><div class="fg-bar" data-fg-cell="bar"><i></i></div></div>
        <div class="fg-bar-note" data-fg-cell="note"></div>
      </td>
      <td data-pri="1" class="num-col fg-cell fg-zone-stock" data-fg-cell="ours">—</td>
      <td data-pri="2" class="num-col fg-cell fg-zone-stock" data-fg-cell="pool">—</td>
      <td data-pri="2" class="num-col fg-cell fg-zone-stock" data-fg-cell="pct">—</td>
      <td data-pri="1" class="fg-cell fg-zone-stock" data-fg-cell="verdict"></td>
    </tr>
    <?php } ?>
    </tbody>
  </table>
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

  <p class="dash-fg-foot" id="dash-fg-foot" hidden>
    <a href="<?= h($B . '/finishgood_shortage_preview.php') ?>">ตั้งค่าการนับและการแจ้งเตือนรายรุ่น ›</a>
  </p>
</div>

<script>
/* แท็บมือถือ: สต็อกเครื่อง = ตารางรุ่น · ภาพรวม = การ์ดสถานะ + กราฟรายปี · อะไหล่ = การ์ดอะไหล่ + สต็อกอะไหล่
   สลับมุมมองในกล่องรุ่น/อะไหล่ด้วยปุ่มเดิมของกล่อง (ซ่อนบนมือถือ) — ตัวกรองและตัวนับทำงานตามเดิม */
(function(){
  var tabs = document.querySelectorAll('.dash-m-tab');
  if (!tabs.length) return;
  var mq = window.matchMedia('(max-width: 640px)');
  var partsBtn = document.querySelector('.dash-view-btn[data-view="parts"]');
  var canParts = partsBtn && !partsBtn.disabled;
  function pick(name, remember){
    if (name === 'parts' && !canParts) name = 'stock';
    document.documentElement.setAttribute('data-dash-tab', name);
    tabs.forEach(function(t){
      var on = t.getAttribute('data-mtab') === name;
      t.classList.toggle('is-on', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    if (mq.matches) {
      var v = document.querySelector('.dash-view-btn[data-view="' + (name === 'parts' ? 'parts' : 'models') + '"]');
      if (v && !v.classList.contains('active')) v.click();
    }
    if (remember) { try { localStorage.setItem('dashMobileTab', name); } catch (e) {} }
  }
  tabs.forEach(function(t){
    if (t.getAttribute('data-mtab') === 'parts' && !canParts) t.hidden = true;
    t.addEventListener('click', function(){ pick(t.getAttribute('data-mtab'), true); window.scrollTo(0, 0); });
  });
  var saved = null;
  try { saved = localStorage.getItem('dashMobileTab'); } catch (e) {}
  var first = saved === 'prod' || saved === 'parts' ? saved : 'stock';
  document.documentElement.setAttribute('data-dash-tab', first);   // ซ่อนส่วนอื่นทันที ไม่กระพริบ
  // ปุ่มสลับมุมมองของกล่องรุ่นผูก event ในสคริปต์ถัดไป — รอให้หน้าโหลดครบก่อนกด
  document.addEventListener('DOMContentLoaded', function(){ pick(first, false); });
})();
</script>

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
  var modelsGrid = document.getElementById('dash-models-grid');
  var fgFilters = document.getElementById('dash-fg-filters');
  var fgHint = document.getElementById('dash-fg-hint');
  var fgSummary = document.getElementById('dash-fg-summary');
  var fgFoot = document.getElementById('dash-fg-foot');
  var fgBtns = fgFilters ? fgFilters.querySelectorAll('.dash-parts-filter-btn') : [];
  var modelCards = modelsGrid ? Array.prototype.slice.call(modelsGrid.querySelectorAll('[data-code]')) : [];
  var currentFgFilter = 'all';
  var dueCount = modelCards.filter(function (c) { return c.getAttribute('data-due') === '1'; }).length;
  var fgCounts = { all: modelCards.length, short: 0, over: 0, due: dueCount };
  var labels = {
    models:   { title: 'จำนวนเครื่องแยกตามรุ่น', meta: '<?= count($perModel) ?> รุ่น' },
    parts:    { title: 'สต็อกอะไหล่' }
  };
  var fgBase = '<?= h($B) ?>/dashboard_data.php';

  function fgNum(n) { return Number(n).toLocaleString('en-US'); }

  // ตัวเลขคลังของแต่ละรุ่น — โหลดหลังหน้าขึ้น ไม่ให้ Dashboard รอ API ของระบบ Setup (เซิร์ฟเวอร์ cache 10 นาที)
  fetch(fgBase + '?type=fg_model_stock', { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d || !d.ok || !d.codes) { return; }
      applyFgData(d);
    })
    .catch(function () {});

  function applyFgData(d) {
    var short = 0, over = 0, gapTotal = 0, newTotal = 0, poolTotal = 0;
    modelCards.forEach(function (row0) {
      var row = d.codes[row0.getAttribute('data-code') || ''];
      if (!row) { return; }
      // บอกให้ชัดว่า PO ค้างต้องใช้เครื่องกี่เครื่อง ไม่ใช่แค่ "+ PO 2" ที่อ่านแล้วไม่รู้ว่า 2 อะไร
      var base = 'ขั้นต่ำ ' + fgNum(row.min) + ' + PO ต้องใช้ ' + fgNum(row.po) + ' เครื่อง';
      // have = เครื่องใหม่อย่างเดียว · pool = คลังพร้อมเช่า · ยอดที่มีใช้คิดขาด = ทั้งสองรวมกัน
      var have = Number(row.new) || 0;
      var pool = Math.max(0, Number(row.rent) || 0);
      var req = Number(row.req) || 0;
      newTotal += have;
      var tag = '';
      var gap = -1;
      if (row.state === 'short') {
        short++;
        gap = Number(row.gap) || 0;
        gapTotal += gap;
        row0.setAttribute('data-fg', 'short');
        tag = '<span class="fg-chip fg-chip-short">ขาด ' + fgNum(row.gap) + '</span>';
      } else if (row.state === 'muted') {
        // ขาดจริง แต่ตั้งไม่ให้แจ้งเตือนรุ่นนี้ไว้ — โชว์ยอด แต่ไม่นับรวมในยอดขาด
        short++;
        gap = Number(row.gap) || 0;
        row0.setAttribute('data-fg', 'short');
        tag = '<span class="fg-chip fg-chip-muted" title="ปิดแจ้งเตือนรุ่นนี้ไว้ ไม่นับรวมในยอดขาด">ขาด ' + fgNum(row.gap) + ' · ปิดแจ้งเตือน</span>';
      } else {
        over++;
        row0.setAttribute('data-fg', 'over');
        // เกิน 0 = มีเท่าที่ต้องมีพอดี — เขียนว่า "ครบพอดี" อ่านง่ายกว่าเลข 0
        tag = Number(row.over) > 0
          ? '<span class="fg-chip fg-chip-over">เกิน ' + fgNum(row.over) + '</span>'
          : '<span class="fg-chip fg-chip-over">ครบพอดี</span>';
      }
      row0.setAttribute('data-gap', String(gap));
      // สำหรับเรียงแบบกลุ่ม: 0 = ต่ำกว่าขั้นต่ำ · 1 = ถึงขั้นต่ำแต่ไม่พอส่ง PO · 2 = ครบ
      var minNum = Number(row.min) || 0;
      row0.setAttribute('data-have', String(have + pool));
      row0.setAttribute('data-req', String(req));
      row0.setAttribute('data-stage', row.state === 'over' ? '2' : (have + pool < minNum ? '0' : '1'));
      var pctCell = row0.querySelector('[data-fg-cell="pct"]');
      if (pctCell) {
        pctCell.innerHTML = req > 0 ? '<b>' + Math.round((have + pool) / req * 100) + '%</b>' : '<span class="muted">—</span>';
      }
      row0.setAttribute('data-over', row.state === 'over' ? String(Number(row.over) || 0) : '-1');

      var cell = function (key) { return row0.querySelector('[data-fg-cell="' + key + '"]'); };
      var put = function (key, val, sub2) {
        var c = cell(key);
        if (!c) { return; }
        c.innerHTML = '<b>' + fgNum(val) + '</b>'
          + (sub2 ? '<div class="cell-sub muted">' + sub2 + '</div>' : '');
      };
      // จอมือถือซ่อนคอลัมน์ต้องมี/คลังพร้อมเช่า — สรุปไว้ใต้ตัวเลขแทน
      // สูตรอยู่ใต้แถบแล้ว — บนมือถือเหลือแค่คำกำกับตัวเลข
      put('ours', have, '<span class="fg-m-only">เครื่องใหม่'
        + (pool > 0 ? ' · คลังพร้อมเช่า ' + fgNum(pool) : '') + '</span>');
      // ขั้นต่ำ / PO / ต้องมี อยู่ในบรรทัดสูตรใต้แถบแล้ว ไม่แยกคอลัมน์ซ้ำ
      poolTotal += pool;
      put('pool', pool, '');
      // แถบแบ่งสีตามชนิด (สีสถานะ "ใหม่" / "เช่า") · เต็มแถบ = ยอดที่ต้องมี
      // มีเกินกว่าที่ต้องมี → ขยายสเกล แล้วส่วนที่เกินเป็นสีจาง
      var bar = cell('bar');
      if (bar) {
        var scale = Math.max(req, have + pool, 1);
        var pc = function (n) { return Math.round(n / scale * 1000) / 10; };
        var newIn = Math.min(have, req);
        var poolIn = Math.min(pool, Math.max(0, req - newIn));
        var seg = function (cls, n) { return n > 0 ? '<i class="' + cls + '" style="width:' + pc(n) + '%"></i>' : ''; };
        bar.innerHTML = seg('is-new', newIn) + seg('is-pool', poolIn)
          + seg('is-new is-extra', have - newIn) + seg('is-pool is-extra', pool - poolIn);
        bar.title = 'เครื่องใหม่ ' + fgNum(have) + (pool > 0 ? ' · คลังพร้อมเช่า ' + fgNum(pool) : '')
          + ' · ต้องมี ' + fgNum(req) + (pool > 0 ? ' (ยอดขาดนับเฉพาะเครื่องใหม่)' : '');

        // หมุด ▼ เหนือแถบ: ดำ = ขั้นต่ำ · ส้ม = ขั้นต่ำ + PO (= ต้องมี) — PO เป็น 0 จะทับกัน เหลือหมุดเดียว
        var minQ = Number(row.min) || 0;
        var poQ = Number(row.po) || 0;
        var marks = cell('marks');
        if (marks) {
          marks.querySelectorAll('.fg-mark').forEach(function (m) { m.remove(); });
          // ชิดขอบไม่ให้หมุดครึ่งซีกล้นออกนอกช่อง
          var at = function (n) { return Math.min(99, Math.max(1, pc(n))); };
          var mark = function (cls, n, tip) {
            var el = document.createElement('span');
            el.className = 'fg-mark' + cls;
            el.style.left = at(n) + '%';
            el.textContent = '▼';
            el.title = tip;
            marks.appendChild(el);
          };
          if (minQ > 0) { mark('', minQ, 'ขั้นต่ำ ' + fgNum(minQ)); }
          if (poQ > 0 || minQ === 0) { mark(' is-po', req, 'ขั้นต่ำ ' + fgNum(minQ) + ' + PO ' + fgNum(poQ) + ' = ต้องมี ' + fgNum(req)); }
        }
        var note = cell('note');
        if (note) {
          note.textContent = 'ขั้นต่ำ ' + fgNum(minQ) + ' + PO ' + fgNum(poQ) + ' = ต้องมี ' + fgNum(req);
        }
      }
      var v = cell('verdict');
      if (v) {
        // ปุ่ม "ดู S/N" ไม่มีแล้ว — ทั้งโซนตั้งแต่แถบสีถึงคอลัมน์นี้กดดูได้ มีลูกศรบอกไว้
        v.innerHTML = tag + '<span class="fg-go" aria-hidden="true">›</span>';
      }
      var stockTip = 'เครื่องใหม่ ' + fgNum(have) + (pool > 0 ? ' · คลังพร้อมเช่า ' + fgNum(pool) + ' · รวม ' + fgNum(have + pool) : '')
        + ' · ต้องมี ' + fgNum(req) + ' (' + base + ')'
        + (row.src === 'registry' ? '' : ' · ตัวเลขจากระบบ Setup (รุ่นนี้ไม่มีในทะเบียนเรา)')
        + ' — กดดูหมายเลขสินค้าในสต็อก';
      row0.querySelectorAll('.fg-zone-stock').forEach(function (td) { td.title = stockTip; });
    });

    if (!short && !over) { return; }
    fgCounts = { all: modelCards.length, short: short, over: over, due: dueCount };
    // แบ่ง 3 กลุ่มตามความเร่งด่วน (ต่ำกว่าขั้นต่ำ → ถึงขั้นต่ำแต่ไม่พอส่ง PO → ครบ)
    // ในกลุ่มเรียงจากที่มีอยู่น้อยสุดก่อน · มีเท่ากัน = รุ่นที่ต้องมีมากกว่าขึ้นก่อน
    // รุ่นที่ยังไม่มีตัวเลขอยู่ท้ายสุด · การ์ด "เช็คสต็อก" ในไลน์เรียงแบบเดียวกัน (includes/line_bot.php)
    var num = function (el, k, d) { var v = el.getAttribute(k); return v === null ? d : Number(v); };
    var sorted = modelCards.slice().sort(function (a, b) {
      return num(a, 'data-stage', 3) - num(b, 'data-stage', 3)
        || num(a, 'data-have', 0) - num(b, 'data-have', 0)
        || num(b, 'data-req', 0) - num(a, 'data-req', 0)
        || num(b, 'data-count', 0) - num(a, 'data-count', 0);
    });
    modelsGrid.querySelectorAll('tr.dash-fg-group').forEach(function (g) { g.remove(); });
    var lastStage = null;
    sorted.forEach(function (card) {
      var st = card.getAttribute('data-stage');
      if (st !== null && st !== lastStage) {
        lastStage = st;
        var n = sorted.filter(function (c) { return c.getAttribute('data-stage') === st; }).length;
        var gr = document.createElement('tr');
        gr.className = 'dash-fg-group is-stage-' + st;
        gr.setAttribute('data-stage', st);
        gr.innerHTML = '<td data-pri="1" colspan="7">' + fgGroupLabels[st] + ' <span class="dash-fg-group-n">' + fgNum(n) + ' รุ่น</span></td>';
        modelsGrid.appendChild(gr);
      }
      modelsGrid.appendChild(card);
    });
    syncFgGroups();

    if (fgSummary) {
      document.getElementById('dash-fg-sum-new').textContent = fgNum(newTotal) + ' เครื่อง';
      document.getElementById('dash-fg-sum-pool').textContent = fgNum(poolTotal) + ' เครื่อง';
      document.getElementById('dash-fg-sum-models').textContent = fgNum(short) + ' รุ่น';
      document.getElementById('dash-fg-sum-gap').textContent = fgNum(gapTotal) + ' เครื่อง';
      fgSummary.hidden = currentView !== 'models';
    }

    setFgLabel(fgBtns[1], 'ต้องผลิตเพิ่ม', short);
    setFgLabel(fgBtns[2], 'ครบแล้ว', over);
    setFgLabel(fgBtns[0], 'ทั้งหมด', modelCards.length);
    setFgLabel(fgBtns[3], 'ถึงรอบนับ', dueCount);
    if (fgHint) {
      fgHint.textContent = 'ขาด = (เครื่องใหม่ + คลังพร้อมเช่า) − (ขั้นต่ำ + PO ค้าง)'
        + (d.updated ? ' · ข้อมูล ณ ' + d.updated : '')
        + (d.stale ? ' (ดึงรอบล่าสุดไม่สำเร็จ — แสดงข้อมูลเก่า)' : '');
    }
    if (fgFilters) { fgFilters.hidden = currentView !== 'models'; }
    if (fgFoot) { fgFoot.hidden = currentView !== 'models'; }
  }

  // คลิกในตารางรุ่น: รูป/ชื่อรุ่น = การผลิตรายปี · แถบสีถึงคอลัมน์สุดท้าย = หมายเลขสินค้าในสต็อก
  if (modelsGrid) {
    modelsGrid.addEventListener('click', function (e) {
      if (e.target.closest('a')) { return; }   // ลิงก์ "นับเลย" ในแถว — ไปหน้านับสต็อก ไม่เปิด popup
      var tr = e.target.closest('tr.dash-model-row');
      if (!tr) { return; }
      var name = tr.getAttribute('data-name') || '';
      var code = tr.getAttribute('data-code') || '';
      var stockZone = e.target.closest('.fg-zone-stock');
      if (stockZone && code && tr.getAttribute('data-fg') !== 'none') {
        showListModal('หมายเลขสินค้าในสต็อก: ' + name,
          fgBase + '?type=fg_shortage_serials&code=' + encodeURIComponent(code), '');
        return;
      }
      if (e.target.closest('.fg-zone-name') || stockZone) {
        showListModal('การผลิตรุ่น ' + name + ' รายปี', tr.getAttribute('data-years-url'), tr.getAttribute('data-list-url'));
      }
    });
  }

  var fgGroupLabels = {
    '0': 'ต่ำกว่าขั้นต่ำ — ต้องผลิตด่วน',
    '1': 'ถึงขั้นต่ำแล้ว แต่ไม่พอส่ง PO',
    '2': 'ครบแล้ว'
  };
  // หัวกลุ่มซ่อนตามแถวในกลุ่ม — กรองแล้วไม่เหลือรุ่นในกลุ่มไหน หัวกลุ่มนั้นก็ไม่ต้องขึ้น
  function syncFgGroups() {
    if (!modelsGrid) { return; }
    modelsGrid.querySelectorAll('tr.dash-fg-group').forEach(function (g) {
      var st = g.getAttribute('data-stage');
      g.hidden = !modelCards.some(function (c) { return !c.hidden && c.getAttribute('data-stage') === st; });
    });
  }

  function setFgLabel(btn, text, n) {
    if (btn) { btn.textContent = text + ' (' + fgNum(n) + ')'; }
  }

  function applyFgFilter(mode) {
    currentFgFilter = mode || 'all';
    fgBtns.forEach(function (b) {
      var on = (b.getAttribute('data-fg-filter') || 'all') === currentFgFilter;
      b.classList.toggle('active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    modelCards.forEach(function (card) {
      var state = card.getAttribute('data-fg') || 'none';
      card.hidden = currentFgFilter === 'due'
        ? card.getAttribute('data-due') !== '1'
        : !(currentFgFilter === 'all' || state === currentFgFilter);
    });
    syncFgGroups();
    if (metaEl && currentView === 'models') {
      metaEl.textContent = fgNum(fgCounts[currentFgFilter] || 0) + ' รุ่น';
    }
  }

  fgBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (currentView !== 'models') { switchView('models'); }
      applyFgFilter(btn.getAttribute('data-fg-filter') || 'all');
    });
  });
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
    // แถบกรองรุ่นกับลิงก์ตั้งค่าเป็นของฝั่งจำนวนเครื่อง — ซ่อนตามแท็บ และซ่อนไว้ก่อนถ้ายังไม่มีตัวเลข
    var fgReady = fgCounts.short > 0 || fgCounts.over > 0;
    if (fgFilters) fgFilters.hidden = !(view === 'models' && fgReady);
    if (fgSummary) fgSummary.hidden = !(view === 'models' && fgReady);
    if (fgFoot) fgFoot.hidden = !(view === 'models' && fgReady);
    var lb = labels[view] || labels.models;
    if (titleText) titleText.textContent = lb.title;
    if (view === 'parts') {
      applyPartsFilter(currentPartsFilter, currentSupplierFilter, true);
    } else {
      resetPartsFilter();
      if (metaEl) metaEl.textContent = fgReady ? fgNum(fgCounts[currentFgFilter] || 0) + ' รุ่น' : lb.meta;
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
(function(){
  // กดป้ายสถานะ (การ์ด "เครื่องทั้งหมด" หรือ legend บนกราฟรายปี) = highlight เฉพาะ
  // สถานะนั้นในทั้งสองที่พร้อมกัน ไม่ใช่แค่การ์ดเดียว — ผูกด้วย [data-status] แบบเดียวกัน
  // ที่ฝังไว้ในทุกจุด (แถบสัดส่วน, legend การ์ด, legend กราฟ, แท่งกราฟรายปี/รายเดือน)
  // เป็น state บนหน้าเว็บล้วน ๆ ไม่ยิง request ใหม่ ตัวเลขที่ต้องใช้มีอยู่ใน DOM แล้ว
  var TARGET_SEL = '.status-legend-item[data-status], .status-bar-seg[data-status],'
    + ' .dash-bar-seg[data-status]';
  var active = null;

  function apply() {
    document.querySelectorAll(TARGET_SEL).forEach(function (el) {
      var st = el.getAttribute('data-status');
      el.classList.toggle('is-active-filter', active !== null && st === active);
      el.classList.toggle('is-dimmed-filter', active !== null && st !== active);
    });
    applyToBars();
  }

  // แท่งกราฟรายปี/รายเดือน: ตอนกรอง ตัวเลขหัวแท่งกับความสูงต้องเป็นของสถานะที่เลือก
  // ไม่ใช่ยอดรวมทุกสถานะ ไม่งั้นตัวเลขจะไม่ตรงกับสิ่งที่ไฮไลต์อยู่
  //
  // จำนวนของแต่ละสถานะอ่านจาก flex-grow ของ segment ได้เลย (ฝั่ง PHP ใส่ค่า count ลงไป
  // ตรง ๆ) จึงไม่ต้องยิงขอข้อมูลใหม่ · ความสูงคิดเป็นสัดส่วนจากของเดิม (origH × count/total)
  // แกน Y เลยยังอ่านค่าได้ถูกเหมือนเดิม ไม่ต้องคำนวณสเกลใหม่
  function applyToBars() {
    document.querySelectorAll('.dash-bar-col').forEach(function (col) {
      var area = col.querySelector('.dash-bar-area');
      var totalEl = col.querySelector('.dash-bar-total');
      var segs = col.querySelectorAll('.dash-bar-seg[data-status]');
      if (!area || !segs.length) { return; }

      if (!area.hasAttribute('data-orig-h')) {
        area.setAttribute('data-orig-h', area.style.height || '');
        if (totalEl) { totalEl.setAttribute('data-orig-num', totalEl.textContent); }
      }
      var origH = parseFloat(area.getAttribute('data-orig-h')) || 0;

      if (active === null) {
        area.style.height = area.getAttribute('data-orig-h');
        if (totalEl) { totalEl.textContent = totalEl.getAttribute('data-orig-num'); }
        segs.forEach(function (s) { s.hidden = false; });
        return;
      }

      var total = 0;
      var picked = 0;
      segs.forEach(function (s) {
        var n = parseFloat(s.style.flexGrow || s.style.flex) || 0;
        total += n;
        if (s.getAttribute('data-status') === active) { picked = n; }
        // ซ่อนสถานะอื่นไปเลย ไม่ใช่แค่ทำจาง เพราะแท่งย่อลงมาเหลือเฉพาะสถานะที่เลือกแล้ว
        s.hidden = s.getAttribute('data-status') !== active;
      });
      area.style.height = (total > 0 ? origH * (picked / total) : 0) + '%';
      if (totalEl) { totalEl.textContent = picked.toLocaleString('en-US'); }
    });
  }

  function toggle(st) {
    active = (active === st) ? null : st;
    apply();
  }
  window.dashStatusFilter = { toggle: toggle };
})();
</script>

<?php page_footer();
