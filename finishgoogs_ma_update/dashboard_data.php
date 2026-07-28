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
    echo '<div class="table-wrap"><table class="list"><tr><th>รหัสเครื่อง</th><th>รุ่น</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
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
    echo '</table></div>';
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

/** ตรวจสถานะเครื่องที่ใช้ drill-down จาก KPI */
function asset_status_valid($st) {
    return $st === 'all' || in_array($st, status_list(), true);
}

/** ป้ายชื่อสถานะสำหรับ modal */
function asset_status_label($st) {
    static $map = [
        'all'    => 'เครื่องทั้งหมด',
        'new'    => 'ใหม่ (คลัง)',
        'rental' => 'เครื่องเช่า',
        'spare'  => 'เครื่องสำรอง',
    ];
    return isset($map[$st]) ? $map[$st] : $st;
}

/**
 * สร้างเงื่อนไข SQL กรองสถานะเครื่อง
 *
 * @param string $st
 * @param string $alias alias ตาราง assets
 * @return array{sql:string, types:string, params:array<int,mixed>}
 */
function asset_status_filter($st, $alias = 'a') {
    if ($st === 'all') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!in_array($st, status_list(), true)) {
        return ['sql' => ' AND 1=0', 'types' => '', 'params' => []];
    }
    return ['sql' => " AND {$alias}.status=?", 'types' => 's', 'params' => [$st]];
}

/** การ์ดรุ่นสินค้า — ชั้นแรกของ drill-down ตามสถานะ */
function asset_model_grid($res, $st) {
    $label = asset_status_label($st);
    $n = 0;
    echo '<p class="muted" style="font-size:12px;margin:0 0 10px">' . h($label)
       . ' · กดรุ่นเพื่อดูรายปี → รายเดือน → หมายเลขเครื่อง</p>';
    echo '<div class="grid-products" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">';
    while ($r = $res->fetch_assoc()) {
        $n++;
        $title = 'รุ่น ' . $r['name'] . ' — ' . $label . ' (' . number_format($r['c']) . ' เครื่อง)';
        $url = BASE_URL . '/dashboard_data.php?type=asset_product_years&p=' . (int) $r['pid'] . '&st=' . rawurlencode($st);
        echo '<div class="pcard clickable" style="cursor:pointer" onclick="' . drill_onclick($title, $url) . '">'
           . img_tag($r['icon_path'], $r['name'], 'thumb-lg')
           . '<div class="pname">' . h($r['name']) . '</div>'
           . '<div class="pmeta"><b style="font-size:16px; color:var(--primary)">' . number_format($r['c']) . '</b> เครื่อง</div></div>';
    }
    echo '</div>';
    if ($n === 0) {
        echo '<p class="muted">ไม่มีข้อมูล</p>';
    }
}

/** ตารางรับเข้า/เบิกออกวันนี้ (parts DB) */
function render_stock_today_table(array $rows, $partsBase) {
    if (!$rows) {
        echo '<p class="muted">ไม่มีความเคลื่อนไหววันนี้</p>';
        return;
    }
    $inQty = 0;
    $outQty = 0;
    foreach ($rows as $mv) {
        if ($mv['move_type'] === 'in') {
            $inQty += (int) $mv['quantity'];
        } else {
            $outQty += (int) $mv['quantity'];
        }
    }
    echo '<p class="muted" style="font-size:12px;margin:0 0 10px">วันนี้ ' . dthai(date('Y-m-d'))
       . ' · รับเข้า ' . number_format($inQty) . ' · เบิกออก ' . number_format($outQty)
       . ' · ' . count($rows) . ' รายการ</p>';
    echo '<table class="list part-move-table"><tr>'
       . '<th>เวลา</th><th>ประเภท</th><th>อะไหล่</th><th>จำนวน</th><th>รายละเอียด</th><th>ผู้ทำรายการ</th></tr>';
    foreach ($rows as $mv) {
        $isIn = $mv['move_type'] === 'in';
        $unit = $mv['unit'] ?: 'ชิ้น';
        $detail = h($mv['note'] ?: '-');
        if (!$isIn) {
            $bits = [];
            if (!empty($mv['doc_no'])) {
                $histUrl = h($partsBase . '/pages/history.php?id=' . (int) $mv['stock_out_id']);
                $bits[] = '<a href="' . $histUrl . '" onclick="event.stopPropagation()">' . h($mv['doc_no']) . '</a>';
            }
            if (!empty($mv['set_id'])) {
                $bits[] = 'ชุด [' . h($mv['set_code']) . '] ' . h($mv['set_name']);
            } elseif (empty($mv['set_id'])) {
                $bits[] = 'เบิกรายชิ้น';
            }
            if (!empty($mv['asset_code'])) {
                $bits[] = 'S/N ' . h($mv['asset_code']);
            }
            if ($mv['note']) {
                $bits[] = h($mv['note']);
            }
            $detail = $bits ? implode(' · ', $bits) : '-';
        }
        echo '<tr>'
           . '<td>' . dthai_full($mv['created_at']) . '</td>'
           . '<td>' . ($isIn
               ? '<span class="part-move-badge part-move-badge-in">รับเข้า</span>'
               : '<span class="part-move-badge part-move-badge-out">เบิกออก</span>') . '</td>'
           . '<td><span class="muted" style="font-size:11px">' . h($mv['code']) . '</span><br>' . h($mv['name']) . '</td>'
           . '<td class="' . ($isIn ? 'part-move-in' : 'part-move-out') . '">'
           . ($isIn ? '+' : '-') . number_format((int) $mv['quantity']) . ' ' . h($unit) . '</td>'
           . '<td style="font-size:12px">' . $detail . '</td>'
           . '<td>' . h($mv['actor'] ?: '-') . '</td>'
           . '</tr>';
    }
    echo '</table>';
    echo '<p class="muted" style="margin-top:8px;font-size:12px">แสดงเฉพาะวันนี้ · ใช้ลิงก์ด้านบน modal เปิดประวัติเต็มหน้า</p>';
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
        echo '<div class="table-wrap"><table class="list"><tr><th>หมายเลขเครื่อง</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr class="clickable" style="cursor:pointer" onclick="location.href=\'' . BASE_URL . '/asset.php?id=' . (int)$r['id'] . '\'">'
               . '<td><b>' . h($r['asset_code']) . '</b></td>'
               . '<td>' . status_badge($r['status']) . '</td>'
               . '<td>' . dthai($r['produced_at']) . '</td></tr>';
        }
        echo '</table></div>';
        if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
        else echo '<p class="muted" style="margin-top:6px; font-size:12px">กดแถวเพื่อเปิดโปรไฟล์สินค้า (หน้าเครื่อง)</p>';
        break;

    case 'repairs_open':
        $res = qr("SELECT r.id, r.opened_at, r.reported_issue, r.status, a.id aid, a.asset_code, p.name pname
                   FROM repairs r JOIN assets a ON a.id=r.asset_id JOIN products p ON p.id=a.product_id
                   WHERE r.status IN ('received','in_progress') ORDER BY r.opened_at LIMIT $LIMIT");
        echo '<div class="table-wrap"><table class="list"><tr><th>รับแจ้ง</th><th>เครื่อง</th><th>รุ่น</th><th>อาการ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr><td>' . dthai_full($r['opened_at']) . '</td>'
               . '<td><a href="' . BASE_URL . '/asset.php?id=' . $r['aid'] . '"><b>' . h($r['asset_code']) . '</b></a></td>'
               . '<td>' . h($r['pname']) . '</td>'
               . '<td>' . h(mb_strimwidth((string)$r['reported_issue'], 0, 80, '…')) . '</td></tr>';
        }
        echo '</table></div>';
        if ($n === 0) echo '<p class="muted">ไม่มีงานซ่อมค้าง</p>';
        break;

    case 'part_profile': // โปรไฟล์อะไหล่ + ประวัติรับเข้า/เบิกออก
        $pid = (int) $v;
        if ($pid <= 0) {
            exit('รหัสอะไหล่ไม่ถูกต้อง');
        }
        require_once __DIR__ . '/includes/part_stock_bridge.php';
        require_once dirname(__DIR__) . '/parts/includes/StockService.php';
        try {
            $partsPdo = dbParts();
        } catch (Throwable $e) {
            exit('<p class="muted">เชื่อมต่อระบบสต็อกไม่ได้</p>');
        }
        $stockSvc = new StockService($partsPdo);
        $part = $stockSvc->getProduct($pid);
        if (!$part) {
            exit('<p class="muted">ไม่พบอะไหล่นี้</p>');
        }

        $prodMeta = production_part_labels_by_stock_codes([(string) ($part['code'] ?? '')]);
        $displayName = part_product_display_name($part, $prodMeta);
        $displaySub = part_product_display_sub($part, $prodMeta);
        $stockCode = trim((string) ($part['code'] ?? ''));

        $iconPath = null;
        if (isset($prodMeta[$stockCode]['icon_path']) && $prodMeta[$stockCode]['icon_path'] !== '') {
            $iconPath = $prodMeta[$stockCode]['icon_path'];
        }
        if ($iconPath === null) {
            $iconSt = qr(
                "SELECT icon_path FROM parts WHERE stock_code = ? AND icon_path IS NOT NULL AND icon_path <> '' LIMIT 1",
                's',
                [$part['code']]
            );
            if ($iconRow = $iconSt->fetch_assoc()) {
                $iconPath = $iconRow['icon_path'];
            }
        }

        $moves = $stockSvc->getProductMovementHistory($pid, 80);
        $partsBase = ui_parts_base_url();
        $qty = (int) $part['quantity'];
        $minStock = (int) $part['min_stock'];
        $unit = $part['unit'] ?: 'ชิ้น';
        $low = $qty <= $minStock;
        $totalIn = 0;
        $totalOut = 0;
        foreach ($moves as $mv) {
            if ($mv['move_type'] === 'in') {
                $totalIn += (int) $mv['quantity'];
            } else {
                $totalOut += (int) $mv['quantity'];
            }
        }

        echo '<div class="part-profile-head">';
        if ($iconPath) {
            echo img_tag($iconPath, $displayName, 'thumb');
        } else {
            echo '<span class="part-profile-ph">' . ui_icon_html('parts', 22) . '</span>';
        }
        $subLine = $displaySub;
        if ($stockCode !== '' && $displaySub !== $stockCode) {
            $subLine = h($displaySub) . ' <span class="muted" style="font-size:12px">· ID ' . h($stockCode) . '</span>';
        } else {
            $subLine = h($displaySub);
        }
        echo '<div class="part-profile-meta">'
           . '<div><b style="font-size:15px">' . h($displayName) . '</b>'
           . ($displaySub !== '' ? '<br><span class="muted" style="font-size:12px">' . $subLine . '</span>' : '')
           . '</div>'
           . '<div class="part-profile-stats">'
           . '<span class="part-profile-stat">คงเหลือ <b>' . number_format($qty) . '</b> ' . h($unit) . '</span>'
           . '<span class="part-profile-stat">ขั้นต่ำ <b>' . number_format($minStock) . '</b></span>'
           . '<span class="part-profile-stat">' . ($low ? '<span class="text-warn">ใกล้หมด</span>' : '<span style="color:#059669">ปกติ</span>') . '</span>'
           . '</div></div></div>';

        echo '<p class="muted" style="font-size:12px;margin:0 0 10px">สรุปในตาราง: รับเข้า ' . number_format($totalIn)
           . ' · เบิกออก ' . number_format($totalOut) . ' ' . h($unit) . ' (แสดงล่าสุด ' . count($moves) . ' รายการ)</p>';

        if (!$moves) {
            echo '<p class="muted">ยังไม่มีประวัติรับเข้า/เบิกออก</p>';
            break;
        }

        echo '<table class="list part-move-table"><tr>'
           . '<th>วันที่</th><th>ประเภท</th><th>จำนวน</th><th>รายละเอียด</th><th>ผู้ทำรายการ</th></tr>';
        foreach ($moves as $mv) {
            $isIn = $mv['move_type'] === 'in';
            $detail = h($mv['note'] ?: '-');
            if (!$isIn) {
                $bits = [];
                if (!empty($mv['doc_no'])) {
                    $histUrl = h($partsBase . '/pages/history.php?id=' . (int) $mv['stock_out_id']);
                    $bits[] = '<a href="' . $histUrl . '" onclick="event.stopPropagation()">' . h($mv['doc_no']) . '</a>';
                }
                if (!empty($mv['set_id'])) {
                    $bits[] = 'ชุด [' . h($mv['set_code']) . '] ' . h($mv['set_name']);
                } elseif (!$mv['set_id']) {
                    $bits[] = 'เบิกรายชิ้น';
                }
                if (!empty($mv['asset_code'])) {
                    $bits[] = 'S/N ' . h($mv['asset_code']);
                }
                if ($mv['note']) {
                    $bits[] = h($mv['note']);
                }
                $detail = $bits ? implode(' · ', $bits) : '-';
            }

            echo '<tr>'
               . '<td>' . dthai_full($mv['created_at']) . '</td>'
               . '<td>' . ($isIn
                   ? '<span class="part-move-badge part-move-badge-in">รับเข้า</span>'
                   : '<span class="part-move-badge part-move-badge-out">เบิกออก</span>') . '</td>'
               . '<td class="' . ($isIn ? 'part-move-in' : 'part-move-out') . '">'
               . ($isIn ? '+' : '-') . number_format((int) $mv['quantity']) . ' ' . h($unit) . '</td>'
               . '<td style="font-size:12px">' . $detail . '</td>'
               . '<td>' . h($mv['actor'] ?: '-') . '</td>'
               . '</tr>';
        }
        echo '</table>';
        echo '<p class="muted" style="margin-top:8px;font-size:12px">กด「ดูทั้งหมดแบบเต็มหน้า」ด้านบน modal เพื่อเปิดหน้ารายละเอียดอะไหล่</p>';
        break;

    case 'low_stock': // อะไหล่ใกล้หมดทั้งหมด
        require_once __DIR__ . '/includes/dash_low_stock.php';
        try {
            $partsPdo = dbParts();
            $lowRows = $partsPdo->query(
                'SELECT id, code, name, unit, quantity, min_stock FROM products
                 WHERE quantity <= min_stock ORDER BY quantity ASC LIMIT ' . (int) $LIMIT
            )->fetchAll();
        } catch (Throwable $e) {
            exit('<p class="muted">เชื่อมต่อระบบสต็อกไม่ได้</p>');
        }
        $lowProdMeta = dash_part_prod_meta_map($lowRows);
        $lowIconsMap = dash_low_stock_icon_map($lowRows);
        $lowN = count($lowRows);
        echo '<p class="muted" style="font-size:12px;margin:0 0 10px">'
           . number_format($lowN) . ' รายการที่คงเหลือ ≤ ขั้นต่ำ</p>';
        dash_render_low_stock_table($lowRows, $lowIconsMap, $lowProdMeta);
        if ($lowN >= $LIMIT) {
            echo '<p class="muted" style="margin-top:8px;font-size:12px">แสดงสูงสุด ' . number_format($LIMIT)
               . ' รายการ — ใช้ลิงก์ด้านล่าง modal เปิดหน้ารายการอะไหล่เต็ม</p>';
        }
        break;

    case 'asset_models': // KPI เครื่อง → รุ่นสินค้า (ชั้น 1)
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if (!asset_status_valid($st)) {
            exit('สถานะไม่ถูกต้อง');
        }
        $sf = asset_status_filter($st);
        asset_model_grid(qr(
            "SELECT p.id pid, p.name, p.icon_path, COUNT(*) c
             FROM assets a JOIN products p ON p.id=a.product_id
             WHERE 1=1{$sf['sql']}
             GROUP BY p.id ORDER BY c DESC",
            $sf['types'],
            $sf['params']
        ), $st);
        break;

    case 'asset_product_years': // รุ่น+สถานะ → รายปี (ชั้น 2)
        $pid = (int) (isset($_GET['p']) ? $_GET['p'] : 0);
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if ($pid <= 0 || !asset_status_valid($st)) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $sf = asset_status_filter($st);
        $prod = qr('SELECT name, icon_path FROM products WHERE id=?', 'i', [$pid])->fetch_assoc();
        if (!$prod) {
            exit('<p class="muted">ไม่พบรุ่นนี้</p>');
        }
        $years = [];
        $res = qr(
            "SELECT YEAR(a.produced_at) y, COUNT(*) c FROM assets a
             WHERE a.product_id=? AND a.produced_at IS NOT NULL{$sf['sql']}
             GROUP BY y ORDER BY y DESC LIMIT 12",
            'i' . $sf['types'],
            array_merge([$pid], $sf['params'])
        );
        while ($r = $res->fetch_assoc()) {
            $years[(int) $r['y']] = (int) $r['c'];
        }
        if (!$years) {
            echo '<p class="muted">ยังไม่มีข้อมูลการผลิตของรุ่น ' . h($prod['name']) . '</p>';
            break;
        }
        $years = array_reverse($years, true);
        $mx = max(1, max($years));
        $ctx = asset_status_label($st);
        echo '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px">'
           . img_tag($prod['icon_path'], $prod['name'], 'thumb')
           . '<div><b>' . h($prod['name']) . '</b>'
           . '<div class="muted" style="font-size:12px">' . h($ctx) . ' · กดแท่งปีเพื่อดูรายเดือน</div></div></div>';
        echo '<div class="barchart" style="border:0; height:190px; padding:22px 0 24px">';
        foreach ($years as $y => $c) {
            $title = $prod['name'] . ' — ปี ' . $y . ' รายเดือน (' . $ctx . ')';
            $url = BASE_URL . '/dashboard_data.php?type=asset_product_year_months&v=' . (int) $y
               . '&p=' . $pid . '&st=' . rawurlencode($st);
            echo '<div class="bar" style="height:' . round($c / $mx * 100) . '%; background:linear-gradient(180deg,#ec4899,#8b5cf6); cursor:pointer" '
               . 'title="' . h("$y : $c เครื่อง") . '" onclick="' . drill_onclick($title, $url) . '">'
               . '<b>' . $c . '</b><span>' . h($y) . '</span></div>';
        }
        echo '</div><p class="muted" style="font-size:12px">กดแท่งปี → รายเดือน → หมายเลขเครื่อง</p>';
        break;

    case 'asset_product_year_months': // รุ่น+ปี+สถานะ → รายเดือน (ชั้น 3)
        $y = (int) $v;
        $pid = (int) (isset($_GET['p']) ? $_GET['p'] : 0);
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if ($pid <= 0 || $y < 1900 || !asset_status_valid($st)) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $sf = asset_status_filter($st);
        $prod = qr('SELECT name, icon_path FROM products WHERE id=?', 'i', [$pid])->fetch_assoc();
        if (!$prod) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $byM = array_fill(1, 12, 0);
        [$ys, $ye] = year_range($y);
        $res = qr(
            "SELECT MONTH(a.produced_at) m, COUNT(*) c FROM assets a
             WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?{$sf['sql']}
             GROUP BY m",
            'iss' . $sf['types'],
            array_merge([$pid, $ys, $ye], $sf['params'])
        );
        while ($r = $res->fetch_assoc()) {
            $byM[(int) $r['m']] = (int) $r['c'];
        }
        $mx = max(1, max($byM));
        echo '<div class="muted" style="margin-bottom:8px">' . h($prod['name']) . ' · ปี ' . $y
           . ' · ' . h(asset_status_label($st)) . '</div>';
        echo '<div class="barchart" style="border:0; height:190px; padding:22px 0 24px">';
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $title = $prod['name'] . ' — เดือน ' . thai_month_period_label($ym);
            $url = BASE_URL . '/dashboard_data.php?type=asset_product_month&v=' . rawurlencode($ym)
               . '&p=' . $pid . '&st=' . rawurlencode($st);
            echo '<div class="bar" style="height:' . round($byM[$m] / $mx * 100) . '%; background:linear-gradient(180deg,#3b82f6,#06b6d4); cursor:pointer" '
               . 'title="' . h("$ym : {$byM[$m]} เครื่อง") . '" onclick="' . drill_onclick($title, $url) . '">'
               . '<b>' . ($byM[$m] ?: '') . '</b><span>' . h(thai_month_short($ym)) . '</span></div>';
        }
        echo '</div><p class="muted" style="font-size:12px">กดแท่งเดือนเพื่อดูหมายเลขเครื่อง</p>';
        break;

    case 'asset_product_month': // รุ่น+เดือน+สถานะ → รายการ S/N (ชั้น 4)
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) {
            exit('เดือนไม่ถูกต้อง');
        }
        $pid = (int) (isset($_GET['p']) ? $_GET['p'] : 0);
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if ($pid <= 0 || !asset_status_valid($st)) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $prod = qr('SELECT name FROM products WHERE id=?', 'i', [$pid])->fetch_assoc();
        if (!$prod) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $sf = asset_status_filter($st);
        [$ms, $me] = ym_range($v);
        $res = qr(
            "$ASSET_SQL WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?{$sf['sql']}
             ORDER BY a.asset_code LIMIT $LIMIT",
            'iss' . $sf['types'],
            array_merge([$pid, $ms, $me], $sf['params'])
        );
        echo '<div class="muted" style="margin-bottom:8px">' . h($prod['name']) . ' · '
           . h(thai_month_period_label($v)) . ' · ' . h(asset_status_label($st)) . '</div>';
        echo '<div class="table-wrap"><table class="list"><tr><th>หมายเลขเครื่อง</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr class="clickable" style="cursor:pointer" onclick="location.href=\'' . BASE_URL . '/asset.php?id=' . (int) $r['id'] . '\'">'
               . '<td><b>' . h($r['asset_code']) . '</b></td>'
               . '<td>' . status_badge($r['status']) . '</td>'
               . '<td>' . dthai($r['produced_at']) . '</td></tr>';
        }
        echo '</table></div>';
        if ($n === 0) {
            echo '<p class="muted">ไม่มีข้อมูล</p>';
        } else {
            echo '<p class="muted" style="margin-top:6px; font-size:12px">กดแถวเพื่อเปิดหน้าเครื่อง</p>';
        }
        break;

    case 'stock_today': // KPI รับเข้า/เบิกออกวันนี้
        require_once dirname(__DIR__) . '/parts/includes/StockService.php';
        try {
            $partsPdo = dbParts();
        } catch (Throwable $e) {
            exit('<p class="muted">เชื่อมต่อระบบสต็อกไม่ได้</p>');
        }
        $partsBase = ui_parts_base_url();
        $sql = "
            (SELECT 'in' AS move_type, si.id AS ref_id, p.code, p.name, p.unit, si.quantity, si.note,
                    si.received_by AS actor, si.created_at,
                    NULL AS doc_no, NULL AS asset_code, NULL AS stock_out_id,
                    NULL AS set_id, NULL AS set_code, NULL AS set_name
             FROM stock_in si
             JOIN products p ON p.id = si.product_id
             WHERE DATE(si.created_at) = CURDATE())
            UNION ALL
            (SELECT 'out' AS move_type, soi.id AS ref_id, p.code, p.name, p.unit, soi.quantity, so.note,
                    so.issued_by AS actor, so.created_at,
                    so.doc_no, so.asset_code, so.id AS stock_out_id,
                    so.set_id, s.code AS set_code, s.name AS set_name
             FROM stock_out_items soi
             JOIN stock_out so ON so.id = soi.stock_out_id
             JOIN products p ON p.id = soi.product_id
             LEFT JOIN sets s ON s.id = so.set_id
             WHERE DATE(so.created_at) = CURDATE())
            ORDER BY created_at DESC
            LIMIT " . (int) $LIMIT;
        $rows = $partsPdo->query($sql)->fetchAll();
        render_stock_today_table($rows, $partsBase);
        break;

    default:
        echo 'ไม่รู้จักประเภทข้อมูล';
}
