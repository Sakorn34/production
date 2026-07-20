<?php
/** dashboard_data.php — คืน HTML รายการสำหรับ modal เจาะลึกจาก dashboard
 *  drill-down: ปี → เดือนของปี → รุ่นสินค้า (รูป+จำนวน) → รายการเครื่อง → timeline เครื่อง */
require __DIR__ . '/config.php';
require_login();
header('Content-Type: text/html; charset=utf-8');

$type = isset($_GET['type']) ? $_GET['type'] : '';
$v    = isset($_GET['v']) ? $_GET['v'] : '';
$LIMIT = 150;

/** onclick เปิด modal ชั้นถัดไป (ใช้ในแถว/การ์ดที่ inject เข้า modal เดิม) */
function drill_onclick($title, $url) {
    return "showListModal(" . h(json_encode($title, JSON_UNESCAPED_UNICODE)) . ", '" . h($url) . "', '')";
}

function asset_table($res, $clickTimeline = false) {
    echo '<table class="list"><tr><th>รหัสเครื่อง</th><th>รุ่น</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        $n++;
        $attr = '';
        if ($clickTimeline) {
            $attr = ' class="clickable" style="cursor:pointer" onclick="' .
                drill_onclick('⏱️ Timeline เครื่อง ' . $r['asset_code'], BASE_URL . '/dashboard_data.php?type=timeline&v=' . (int)$r['id']) . '"';
        }
        echo "<tr$attr><td><a href=\"" . BASE_URL . '/asset.php?id=' . $r['id'] . '" onclick="event.stopPropagation()"><b>' . h($r['asset_code']) . '</b></a></td>'
           . '<td>' . h($r['pname']) . '</td><td>' . status_badge($r['status']) . '</td>'
           . '<td>' . dthai($r['produced_at']) . '</td></tr>';
    }
    echo '</table>';
    if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
    elseif ($clickTimeline) echo '<p class="muted" style="margin-top:6px; font-size:12px">กดแถวเพื่อดู timeline ของเครื่อง · กดรหัสเครื่องเพื่อเปิดหน้าเต็ม</p>';
}

/** การ์ดรุ่นสินค้า (รูป + ชื่อ + จำนวน) กดแล้วเจาะไปรายการเครื่องของรุ่นนั้น */
function model_grid($res, $periodLabel, $nextType, $periodVal) {
    $n = 0;
    echo '<div class="grid-products" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">';
    while ($r = $res->fetch_assoc()) {
        $n++;
        $title = 'รุ่น ' . $r['name'] . ' — ' . $periodLabel . ' (' . number_format($r['c']) . ' เครื่อง)';
        $url = BASE_URL . '/dashboard_data.php?type=' . $nextType . '&v=' . rawurlencode($periodVal) . '&p=' . rawurlencode($r['name']);
        echo '<div class="pcard clickable" style="cursor:pointer" onclick="' . drill_onclick($title, $url) . '">'
           . img_tag($r['icon_path'], $r['name'], 'thumb-lg')
           . '<div class="pname">' . h($r['name']) . '</div>'
           . '<div class="pmeta"><b style="font-size:16px; color:var(--primary)">' . number_format($r['c']) . '</b> เครื่อง</div></div>';
    }
    echo '</div>';
    if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
    else echo '<p class="muted" style="margin-top:8px; font-size:12px">กดการ์ดรุ่นเพื่อดูรายการเครื่อง</p>';
}

$ASSET_SQL = "SELECT a.id, a.asset_code, a.status, a.produced_at, p.name pname
              FROM assets a JOIN products p ON p.id=a.product_id";

/** แปลง 'YYYY-MM' เป็นช่วงวันที่ [start, end) เพื่อให้ query ใช้ idx_assets_produced_at ได้
 *  (แทนการห่อคอลัมน์ด้วย DATE_FORMAT()/YEAR() ซึ่งทำให้ index ใช้ไม่ได้) */
function ym_range($ym) {
    $start = $ym . '-01';
    $end = date('Y-m-01', strtotime($start . ' +1 month'));
    return [$start, $end];
}
function year_range($y) {
    return [sprintf('%04d-01-01', (int)$y), sprintf('%04d-01-01', (int)$y + 1)];
}

switch ($type) {
    case 'status': // เครื่องตามสถานะ
        if (!in_array($v, status_list(), true)) exit('ไม่รู้จักสถานะ');
        asset_table(qr("$ASSET_SQL WHERE a.status=? ORDER BY a.id DESC LIMIT $LIMIT", 's', [$v]));
        break;

    case 'all':
        asset_table(qr("$ASSET_SQL ORDER BY a.id DESC LIMIT $LIMIT"));
        break;

    case 'month': // เครื่องที่ผลิตในเดือนนั้น
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        [$ms, $me] = ym_range($v);
        asset_table(qr("$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<? ORDER BY a.asset_code LIMIT $LIMIT", 'ss', [$ms, $me]), true);
        break;

    case 'year': // เครื่องที่ผลิตในปีนั้น
        if (!preg_match('/^\d{4}$/', $v)) exit('ปีไม่ถูกต้อง');
        [$ys, $ye] = year_range($v);
        asset_table(qr("$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<? ORDER BY a.produced_at DESC, a.asset_code LIMIT $LIMIT", 'ss', [$ys, $ye]), true);
        break;

    case 'year_months': // ปี → กราฟรายเดือนของปีนั้น (ชั้น 1)
        if (!preg_match('/^\d{4}$/', $v)) exit('ปีไม่ถูกต้อง');
        $y = (int)$v;
        [$ys, $ye] = year_range($y);
        $byM = array_fill(1, 12, 0);
        $res = qr("SELECT MONTH(produced_at) m, COUNT(*) c FROM assets WHERE produced_at>=? AND produced_at<? GROUP BY m", 'ss', [$ys, $ye]);
        while ($r = $res->fetch_assoc()) $byM[(int)$r['m']] = (int)$r['c'];
        $mx = max(1, max($byM));
        echo '<div class="barchart" style="border:0; height:190px; padding:22px 0 24px">';
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $title = '🏷️ รุ่นที่ผลิตเดือน ' . thai_month_period_label($ym);
            echo '<div class="bar" style="height:' . round($byM[$m] / $mx * 100) . '%; background:linear-gradient(180deg,#3b82f6,#06b6d4); cursor:pointer" '
               . 'title="' . h("$ym : {$byM[$m]} เครื่อง") . '" onclick="' . drill_onclick($title, BASE_URL . '/dashboard_data.php?type=month_models&v=' . $ym) . '">'
               . '<b>' . ($byM[$m] ?: '') . '</b><span>' . h(thai_month_short($ym)) . '</span></div>';
        }
        echo '</div><p class="muted" style="font-size:12px">กดแท่งเดือนเพื่อดูรุ่นสินค้าที่ผลิตในเดือนนั้น</p>';
        break;

    case 'month_models': // เดือน → รุ่นสินค้าที่ผลิต พร้อมรูป+จำนวน (ชั้น 2)
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        $lbl = thai_month_period_label($v);
        [$ms, $me] = ym_range($v);
        model_grid(qr("SELECT p.name, p.icon_path, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
                       WHERE a.produced_at>=? AND a.produced_at<? GROUP BY p.id ORDER BY c DESC", 'ss', [$ms, $me]), $lbl, 'month_product', $v);
        break;

    case 'month_product': // เดือน + รุ่น → รายการเครื่อง (ชั้น 3, กดแถวไป timeline)
        $pn = isset($_GET['p']) ? $_GET['p'] : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $v) || $pn === '') exit('พารามิเตอร์ไม่ถูกต้อง');
        [$ms, $me] = ym_range($v);
        asset_table(qr("$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<? AND p.name=? ORDER BY a.asset_code LIMIT $LIMIT", 'sss', [$ms, $me, $pn]), true);
        break;

    case 'timeline': // เครื่อง → timeline ประวัติทั้งหมด (ชั้น 4)
        $aid = (int)$v;
        $a = qr("SELECT a.asset_code, a.status, a.produced_at, a.current_fw_version, p.name pname, p.icon_path
                 FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$aid])->fetch_assoc();
        if (!$a) exit('<p class="muted">ไม่พบเครื่องนี้</p>');
        require __DIR__ . '/includes/timeline.php';
        $tlData = asset_timeline_items($aid);
        echo '<div style="display:flex; gap:14px; align-items:center; margin-bottom:12px; background:#f7f9fc; border:1px solid #eceff4; border-radius:8px; padding:10px 14px; flex-wrap:wrap">'
           . img_tag($a['icon_path'], $a['pname'], 'thumb')
           . '<div><b>' . h($a['asset_code']) . '</b> · ' . h($a['pname']) . ' · ' . status_badge($a['status'])
           . '<div class="muted" style="font-size:12px">ผลิต ' . dthai($a['produced_at'])
           . ($a['current_fw_version'] ? ' · FW ' . h($a['current_fw_version']) : '') . '</div></div>'
           . '<a class="btn btn-sm btn-line" style="margin-left:auto" href="' . BASE_URL . '/asset.php?id=' . $aid . '">เปิดหน้าเครื่องเต็ม →</a></div>';
        echo asset_timeline_html($tlData['tl']);
        break;

    case 'category': // เครื่องตามหมวดสินค้า
        asset_table(qr("$ASSET_SQL WHERE COALESCE(NULLIF(p.category,''),'อื่นๆ')=? ORDER BY a.id DESC LIMIT $LIMIT", 's', [$v]));
        break;

    case 'product': // เครื่องตามรุ่น
        asset_table(qr("$ASSET_SQL WHERE p.name=? ORDER BY a.id DESC LIMIT $LIMIT", 's', [$v]));
        break;

    case 'product_years': // รุ่น → กราฟรายปี (ชั้น 1)
        $pid = (int)$v;
        $prod = qr("SELECT name, icon_path FROM products WHERE id=?", 'i', [$pid])->fetch_assoc();
        if (!$prod) exit('<p class="muted">ไม่พบรุ่นนี้</p>');
        $years = [];
        $res = qr("SELECT YEAR(a.produced_at) y, COUNT(*) c FROM assets a
                   WHERE a.product_id=? AND a.produced_at IS NOT NULL GROUP BY y ORDER BY y DESC LIMIT 12", 'i', [$pid]);
        while ($r = $res->fetch_assoc()) $years[(int)$r['y']] = (int)$r['c'];
        if (!$years) { echo '<p class="muted">ยังไม่มีข้อมูลการผลิตของรุ่น ' . h($prod['name']) . '</p>'; break; }
        $years = array_reverse($years, true);
        $mx = max(1, max($years));
        echo '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px">'
           . img_tag($prod['icon_path'], $prod['name'], 'thumb')
           . '<div><b>' . h($prod['name']) . '</b><div class="muted" style="font-size:12px">กดแท่งปีเพื่อดูรายเดือน</div></div></div>';
        echo '<div class="barchart" style="border:0; height:190px; padding:22px 0 24px">';
        foreach ($years as $y => $c) {
            $title = '📊 ' . $prod['name'] . ' — ปี ' . $y . ' รายเดือน';
            echo '<div class="bar" style="height:' . round($c / $mx * 100) . '%; background:linear-gradient(180deg,#ec4899,#8b5cf6); cursor:pointer" '
               . 'title="' . h("$y : $c เครื่อง") . '" onclick="' . drill_onclick($title, BASE_URL . '/dashboard_data.php?type=product_year_months&v=' . (int)$y . '&p=' . (int)$pid) . '">'
               . '<b>' . $c . '</b><span>' . h($y) . '</span></div>';
        }
        echo '</div><p class="muted" style="font-size:12px">กดแท่งปี → รายเดือน → หมายเลขเครื่อง → เปิดโปรไฟล์สินค้า</p>';
        break;

    case 'product_year_months': // รุ่น+ปี → กราฟรายเดือน (ชั้น 2)
        $y = (int)$v;
        $pid = (int)(isset($_GET['p']) ? $_GET['p'] : 0);
        $prod = $pid ? qr("SELECT name, icon_path FROM products WHERE id=?", 'i', [$pid])->fetch_assoc() : null;
        if (!$prod || $y < 1900) exit('พารามิเตอร์ไม่ถูกต้อง');
        $byM = array_fill(1, 12, 0);
        [$ys, $ye] = year_range($y);
        $res = qr("SELECT MONTH(a.produced_at) m, COUNT(*) c FROM assets a
                   WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<? GROUP BY m", 'iss', [$pid, $ys, $ye]);
        while ($r = $res->fetch_assoc()) $byM[(int)$r['m']] = (int)$r['c'];
        $mx = max(1, max($byM));
        echo '<div class="muted" style="margin-bottom:8px">' . h($prod['name']) . ' · ปี ' . $y . '</div>';
        echo '<div class="barchart" style="border:0; height:190px; padding:22px 0 24px">';
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $title = '🏷️ ' . $prod['name'] . ' — เดือน ' . thai_month_period_label($ym);
            echo '<div class="bar" style="height:' . round($byM[$m] / $mx * 100) . '%; background:linear-gradient(180deg,#3b82f6,#06b6d4); cursor:pointer" '
               . 'title="' . h("$ym : {$byM[$m]} เครื่อง") . '" onclick="' . drill_onclick($title, BASE_URL . '/dashboard_data.php?type=product_month&v=' . $ym . '&p=' . $pid) . '">'
               . '<b>' . ($byM[$m] ?: '') . '</b><span>' . h(thai_month_short($ym)) . '</span></div>';
        }
        echo '</div><p class="muted" style="font-size:12px">กดแท่งเดือนเพื่อดูหมายเลขเครื่องที่ผลิตในเดือนนั้น</p>';
        break;

    case 'product_month': // รุ่น+เดือน → รายการเครื่อง → เปิดโปรไฟล์ (ชั้น 3)
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        $pid = (int)(isset($_GET['p']) ? $_GET['p'] : 0);
        $prod = $pid ? qr("SELECT name FROM products WHERE id=?", 'i', [$pid])->fetch_assoc() : null;
        if (!$prod) exit('พารามิเตอร์ไม่ถูกต้อง');
        [$ms, $me] = ym_range($v);
        $res = qr("$ASSET_SQL WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<? ORDER BY a.asset_code LIMIT $LIMIT", 'iss', [$pid, $ms, $me]);
        echo '<table class="list"><tr><th>หมายเลขเครื่อง</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr class="clickable" style="cursor:pointer" onclick="location.href=\'' . BASE_URL . '/asset.php?id=' . (int)$r['id'] . '\'">'
               . '<td><b>' . h($r['asset_code']) . '</b></td>'
               . '<td>' . status_badge($r['status']) . '</td>'
               . '<td>' . dthai($r['produced_at']) . '</td></tr>';
        }
        echo '</table>';
        if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
        else echo '<p class="muted" style="margin-top:6px; font-size:12px">กดแถวเพื่อเปิดโปรไฟล์สินค้า (หน้าเครื่อง)</p>';
        break;

    case 'repairs_open':
        $res = qr("SELECT r.id, r.opened_at, r.reported_issue, r.status, a.id aid, a.asset_code, p.name pname
                   FROM repairs r JOIN assets a ON a.id=r.asset_id JOIN products p ON p.id=a.product_id
                   WHERE r.status IN ('received','in_progress') ORDER BY r.opened_at LIMIT $LIMIT");
        echo '<table class="list"><tr><th>รับแจ้ง</th><th>เครื่อง</th><th>รุ่น</th><th>อาการ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr><td>' . dthai($r['opened_at']) . '</td>'
               . '<td><a href="' . BASE_URL . '/asset.php?id=' . $r['aid'] . '"><b>' . h($r['asset_code']) . '</b></a></td>'
               . '<td>' . h($r['pname']) . '</td>'
               . '<td>' . h(mb_strimwidth((string)$r['reported_issue'], 0, 80, '…')) . '</td></tr>';
        }
        echo '</table>';
        if ($n === 0) echo '<p class="muted">ไม่มีงานซ่อมค้าง</p>';
        break;

    default:
        echo 'ไม่รู้จักประเภทข้อมูล';
}
