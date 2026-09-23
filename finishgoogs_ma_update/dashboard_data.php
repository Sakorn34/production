<?php
/** dashboard_data.php — คืน HTML รายการสำหรับ modal เจาะลึกจาก dashboard
 *  drill-down: ปี → เดือนของปี → รุ่นสินค้า (รูป+จำนวน) → รายการเครื่อง → timeline เครื่อง */
require __DIR__ . '/config.php';
require_login();
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/dashboard_chart.php';

$type = isset($_GET['type']) ? $_GET['type'] : '';
$v    = isset($_GET['v']) ? $_GET['v'] : '';
$LIMIT = 150;

function asset_table($res, $clickTimeline = false) {
    echo '<div class="table-wrap"><table class="list"><tr><th>รหัสเครื่อง</th><th>รุ่น</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        $n++;
        $attr = '';
        if ($clickTimeline) {
            $attr = ' class="clickable" style="cursor:pointer" onclick="' .
                drill_onclick('Timeline เครื่อง ' . $r['asset_code'], BASE_URL . '/dashboard_data.php?type=timeline&v=' . (int)$r['id']) . '"';
        }
        echo "<tr$attr><td><a href=\"" . BASE_URL . '/asset.php?id=' . $r['id'] . '" onclick="event.stopPropagation()"><b>' . h($r['asset_code']) . '</b></a></td>'
           . '<td>' . h($r['pname']) . '</td><td>' . status_badge($r['status']) . '</td>'
           . '<td>' . dthai($r['produced_at']) . '</td></tr>';
    }
    echo '</table></div>';
    if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
    elseif ($clickTimeline) echo '<p class="muted" style="margin-top:6px; font-size:12px">กดแถวเพื่อดู timeline ของเครื่อง · กดรหัสเครื่องเพื่อเปิดหน้าเต็ม</p>';
}

$ASSET_SQL = "SELECT a.id, a.asset_code, a.status, a.produced_at, p.name pname
              FROM assets a JOIN products p ON p.id=a.product_id";

/**
 * ลำดับรายการเครื่องใน modal dashboard — วันที่ผลิตล่าสุดก่อน, SN มาก→น้อย
 *
 * @return string
 */
function asset_list_order_sql() {
    return ' ORDER BY a.produced_at DESC, a.asset_code DESC, a.id DESC';
}

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
    // ชื่อในหน้า dashboard ต่างจาก status_th() อยู่ตัวเดียว (new = "ใหม่ (คลัง)")
    // ที่เหลือถอยไปใช้ status_th() เพื่อไม่ต้องมาไล่เติมสองที่ทุกครั้งที่เพิ่มสถานะ
    static $map = [
        'all' => 'เครื่องทั้งหมด',
        'new' => 'ใหม่ (คลัง)',
    ];
    if (isset($map[$st])) {
        return $map[$st];
    }
    return function_exists('status_th') ? status_th($st) : $st;
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

/** การ์ดรุ่นสินค้า (รูป + ชื่อ + จำนวน) กดแล้วเจาะไปรายการเครื่องของรุ่นนั้น */
function model_grid($res, $periodLabel, $nextType, $periodVal, $st = 'all') {
    $n = 0;
    // ผู้เรียกบางรายผนวกสถานะไว้ใน $periodLabel แล้ว (เช่น "ม.ค. 2569 · ใหม่ (คลัง)")
    // เติมซ้ำจะได้หัวข้อแบบ "… · ใหม่ (คลัง) — ใหม่ (คลัง)" จึงเช็คก่อนว่ามีอยู่แล้วหรือยัง
    $stName = ($st !== 'all' && asset_status_valid($st)) ? asset_status_label($st) : '';
    $stLabel = ($stName !== '' && mb_strpos($periodLabel, $stName) === false) ? ' — ' . $stName : '';
    echo '<div class="grid-products" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">';
    while ($r = $res->fetch_assoc()) {
        $n++;
        $title = 'รุ่น ' . $r['name'] . ' — ' . $periodLabel . $stLabel . ' (' . number_format($r['c']) . ' เครื่อง)';
        $url = BASE_URL . '/dashboard_data.php?type=' . $nextType . '&v=' . rawurlencode($periodVal) . '&p=' . rawurlencode($r['name']);
        if ($st !== 'all' && asset_status_valid($st)) {
            $url .= '&st=' . rawurlencode($st);
        }
        echo '<div class="pcard clickable" style="cursor:pointer" onclick="' . drill_onclick($title, $url) . '">'
           . img_tag($r['icon_path'], $r['name'], 'thumb-lg')
           . '<div class="pname">' . h($r['name']) . '</div>'
           . '<div class="pmeta"><b style="font-size:16px; color:var(--primary)">' . number_format($r['c']) . '</b> เครื่อง</div></div>';
    }
    echo '</div>';
    if ($n === 0) echo '<p class="muted">ไม่มีข้อมูล</p>';
    else echo '<p class="muted" style="margin-top:8px; font-size:12px">กดการ์ดรุ่นเพื่อดูรายการเครื่อง</p>';
}

function asset_model_grid($res, $st) {
    $label = asset_status_label($st);
    $n = 0;
    echo '<p class="muted" style="font-size:12px;margin:0 0 10px">' . h($label)
       . ' · กดรุ่นเพื่อดูหมายเลขเครื่อง</p>';
    echo '<div class="grid-products" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">';
    while ($r = $res->fetch_assoc()) {
        $n++;
        $title = 'รุ่น ' . $r['name'] . ' — ' . $label . ' (' . number_format($r['c']) . ' เครื่อง)';
        $url = BASE_URL . '/dashboard_data.php?type=asset_product_list&p=' . (int) $r['pid'] . '&st=' . rawurlencode($st);
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
        asset_table(qr("$ASSET_SQL WHERE a.status=?" . asset_list_order_sql() . " LIMIT $LIMIT", 's', [$v]));
        break;

    case 'all':
        asset_table(qr("$ASSET_SQL" . asset_list_order_sql() . " LIMIT $LIMIT"));
        break;

    case 'month': // เครื่องที่ผลิตในเดือนนั้น
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        [$ms, $me] = ym_range($v);
        asset_table(qr("$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<?" . asset_list_order_sql() . " LIMIT $LIMIT", 'ss', [$ms, $me]), true);
        break;

    case 'year': // เครื่องที่ผลิตในปีนั้น
        if (!preg_match('/^\d{4}$/', $v)) exit('ปีไม่ถูกต้อง');
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if (!asset_status_valid($st)) {
            exit('สถานะไม่ถูกต้อง');
        }
        [$ys, $ye] = year_range($v);
        $sf = asset_status_filter($st);
        asset_table(
            qr(
                "$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<?" . $sf['sql'] . asset_list_order_sql() . " LIMIT $LIMIT",
                'ss' . $sf['types'],
                array_merge([$ys, $ye], $sf['params'])
            ),
            true
        );
        break;

    case 'year_months': // ปี → กราฟรายเดือนของปีนั้น (ชั้น 1)
        if (!preg_match('/^\d{4}$/', $v)) exit('ปีไม่ถูกต้อง');
        $y = (int)$v;
        [$ys, $ye] = year_range($y);
        $byM = [];
        for ($m = 1; $m <= 12; $m++) {
            $byM[$m] = asset_status_empty_counts();
        }
        $res = qr(
            'SELECT MONTH(a.produced_at) m, ' . asset_status_count_select_sql() .
            ' FROM assets a WHERE a.produced_at>=? AND a.produced_at<? GROUP BY m',
            'ss',
            [$ys, $ye]
        );
        while ($r = $res->fetch_assoc()) {
            $byM[(int) $r['m']] = asset_status_counts_from_row($r);
        }
        $points = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $counts = $byM[$m];
            $titlePrefix = 'รุ่นที่ผลิตเดือน ' . thai_month_period_label($ym);
            $baseUrl = BASE_URL . '/dashboard_data.php?type=month_models&v=' . rawurlencode($ym);
            $points[] = dash_linechart_point(
                thai_month_short($ym),
                thai_month_period_label($ym),
                $counts,
                $titlePrefix,
                $baseUrl
            );
        }
        render_dashboard_stacked_chart($points, true, null, 'ปี ' . thai_buddhist_year($y));
        break;

    case 'month_models': // เดือน → รุ่นสินค้าที่ผลิต พร้อมรูป+จำนวน (ชั้น 2)
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if (!asset_status_valid($st)) {
            exit('สถานะไม่ถูกต้อง');
        }
        $lbl = thai_month_period_label($v);
        if ($st !== 'all') {
            $lbl .= ' · ' . asset_status_label($st);
        }
        [$ms, $me] = ym_range($v);
        $sf = asset_status_filter($st);
        model_grid(
            qr(
                'SELECT p.name, p.icon_path, COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id
                 WHERE a.produced_at>=? AND a.produced_at<?' . $sf['sql'] . ' GROUP BY p.id ORDER BY c DESC',
                'ss' . $sf['types'],
                array_merge([$ms, $me], $sf['params'])
            ),
            $lbl,
            'month_product',
            $v,
            $st
        );
        break;

    case 'month_product': // เดือน + รุ่น → รายการเครื่อง (ชั้น 3, กดแถวไป timeline)
        $pn = isset($_GET['p']) ? $_GET['p'] : '';
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if (!preg_match('/^\d{4}-\d{2}$/', $v) || $pn === '') exit('พารามิเตอร์ไม่ถูกต้อง');
        if (!asset_status_valid($st)) {
            exit('สถานะไม่ถูกต้อง');
        }
        [$ms, $me] = ym_range($v);
        $sf = asset_status_filter($st);
        if ($st !== 'all') {
            echo '<p class="muted" style="font-size:12px;margin:0 0 8px">' . h(asset_status_label($st)) . '</p>';
        }
        asset_table(qr(
            "$ASSET_SQL WHERE a.produced_at>=? AND a.produced_at<? AND p.name=?{$sf['sql']}" . asset_list_order_sql() . " LIMIT $LIMIT",
            'sss' . $sf['types'],
            array_merge([$ms, $me, $pn], $sf['params'])
        ), true);
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
        asset_table(qr("$ASSET_SQL WHERE COALESCE(NULLIF(p.category,''),'อื่นๆ')=?" . asset_list_order_sql() . " LIMIT $LIMIT", 's', [$v]));
        break;

    case 'product': // เครื่องตามรุ่น
        asset_table(qr("$ASSET_SQL WHERE p.name=?" . asset_list_order_sql() . " LIMIT $LIMIT", 's', [$v]));
        break;

    case 'product_years': // รุ่น → กราฟรายปี (ชั้น 1)
        $pid = (int)$v;
        $prod = qr("SELECT name, icon_path FROM products WHERE id=?", 'i', [$pid])->fetch_assoc();
        if (!$prod) exit('<p class="muted">ไม่พบรุ่นนี้</p>');
        $years = [];
        $res = qr(
            'SELECT YEAR(a.produced_at) y, ' . asset_status_count_select_sql() .
            ' FROM assets a WHERE a.product_id=? AND a.produced_at IS NOT NULL GROUP BY y ORDER BY y DESC LIMIT 12',
            'i',
            [$pid]
        );
        while ($r = $res->fetch_assoc()) {
            $years[(int) $r['y']] = asset_status_counts_from_row($r);
        }
        if (!$years) {
            echo '<p class="muted">ยังไม่มีข้อมูลการผลิตของรุ่น ' . h($prod['name']) . '</p>';
            break;
        }
        $years = array_reverse($years, true);
        $points = [];
        foreach ($years as $y => $counts) {
            $titlePrefix = $prod['name'] . ' — ปี ' . $y . ' รายเดือน';
            $baseUrl = BASE_URL . '/dashboard_data.php?type=product_year_months&v=' . (int) $y . '&p=' . (int) $pid;
            $points[] = dash_linechart_point((string) $y, $prod['name'] . ' ปี ' . $y, $counts, $titlePrefix, $baseUrl);
        }
        render_dashboard_stacked_chart($points, true, null, $prod['name']);
        break;

    case 'product_year_months': // รุ่น+ปี → กราฟรายเดือน (ชั้น 2)
        $y = (int)$v;
        $pid = (int)(isset($_GET['p']) ? $_GET['p'] : 0);
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        if (!$pid || $y < 1900 || !asset_status_valid($st)) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $prod = qr("SELECT name, icon_path FROM products WHERE id=?", 'i', [$pid])->fetch_assoc();
        if (!$prod) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        $byM = [];
        for ($m = 1; $m <= 12; $m++) {
            $byM[$m] = asset_status_empty_counts();
        }
        [$ys, $ye] = year_range($y);
        $sf = asset_status_filter($st);
        $res = qr(
            'SELECT MONTH(a.produced_at) m, ' . asset_status_count_select_sql() .
            ' FROM assets a WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?' . $sf['sql'] . ' GROUP BY m',
            'iss' . $sf['types'],
            array_merge([$pid, $ys, $ye], $sf['params'])
        );
        while ($r = $res->fetch_assoc()) {
            $byM[(int) $r['m']] = asset_status_counts_from_row($r);
        }
        $multiSeries = ($st === 'all');
        $points = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $counts = $byM[$m];
            $titlePrefix = $prod['name'] . ' — เดือน ' . thai_month_period_label($ym);
            $baseUrl = BASE_URL . '/dashboard_data.php?type=product_month&v=' . rawurlencode($ym) . '&p=' . $pid;
            if ($st !== 'all') {
                $baseUrl .= '&st=' . rawurlencode($st);
            }
            $points[] = dash_linechart_point(
                thai_month_short($ym),
                thai_month_period_label($ym),
                $counts,
                $titlePrefix,
                $baseUrl
            );
        }
        render_dashboard_stacked_chart(
            $points,
            $multiSeries,
            $st !== 'all' ? $st : null,
            $prod['name'] . ' · ปี ' . thai_buddhist_year($y) . ($st !== 'all' ? ' · ' . asset_status_label($st) : '')
        );
        break;

    case 'product_month': // รุ่น+เดือน → รายการเครื่อง → เปิดโปรไฟล์ (ชั้น 3)
        if (!preg_match('/^\d{4}-\d{2}$/', $v)) exit('เดือนไม่ถูกต้อง');
        $pid = (int)(isset($_GET['p']) ? $_GET['p'] : 0);
        $st = isset($_GET['st']) ? (string) $_GET['st'] : 'all';
        $prod = $pid ? qr("SELECT name FROM products WHERE id=?", 'i', [$pid])->fetch_assoc() : null;
        if (!$prod || !asset_status_valid($st)) {
            exit('พารามิเตอร์ไม่ถูกต้อง');
        }
        [$ms, $me] = ym_range($v);
        $sf = asset_status_filter($st);
        $res = qr(
            "$ASSET_SQL WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?{$sf['sql']}" . asset_list_order_sql() . " LIMIT $LIMIT",
            'iss' . $sf['types'],
            array_merge([$pid, $ms, $me], $sf['params'])
        );
        echo '<div class="muted" style="margin-bottom:8px">' . h($prod['name']) . ' · '
           . h(thai_month_period_label($v))
           . ($st !== 'all' ? ' · ' . h(asset_status_label($st)) : '') . '</div>';
        echo '<div class="table-wrap"><table class="list"><tr><th>หมายเลขเครื่อง</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr class="clickable" style="cursor:pointer" onclick="openAssetUrl(\'' . BASE_URL . '/asset.php?id=' . (int)$r['id'] . '\')">'
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
           . '<span class="part-profile-stat">' . stock_status_badge_html($qty, $minStock) . '</span>'
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

    case 'low_stock': // อะไหล่ที่ถึงขั้นต่ำแล้วทั้งหมด (ควรสั่งเพิ่ม)
        require_once __DIR__ . '/includes/dash_low_stock.php';
        try {
            $partsPdo = dbParts();
            $lowRows = $partsPdo->query(
                'SELECT id, code, name, unit, quantity, min_stock FROM products
                 WHERE quantity <= min_stock AND is_active = 1 ORDER BY quantity ASC LIMIT ' . (int) $LIMIT
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

    case 'asset_product_list': // รุ่น+สถานะ → รายการ S/N โดยตรง (ข้ามรายปี-เดือน)
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
        echo '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px">'
           . img_tag($prod['icon_path'], $prod['name'], 'thumb')
           . '<div><b>' . h($prod['name']) . '</b>'
           . '<div class="muted" style="font-size:12px">' . h(asset_status_label($st)) . '</div></div></div>';
        asset_table(qr(
            "$ASSET_SQL WHERE a.product_id=?{$sf['sql']}" . asset_list_order_sql() . " LIMIT $LIMIT",
            'i' . $sf['types'],
            array_merge([$pid], $sf['params'])
        ));
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
            'SELECT YEAR(a.produced_at) y, ' . asset_status_count_select_sql() .
            ' FROM assets a WHERE a.product_id=? AND a.produced_at IS NOT NULL' . $sf['sql'] .
            ' GROUP BY y ORDER BY y DESC LIMIT 12',
            'i' . $sf['types'],
            array_merge([$pid], $sf['params'])
        );
        while ($r = $res->fetch_assoc()) {
            $years[(int) $r['y']] = asset_status_counts_from_row($r);
        }
        if (!$years) {
            echo '<p class="muted">ยังไม่มีข้อมูลการผลิตของรุ่น ' . h($prod['name']) . '</p>';
            break;
        }
        $years = array_reverse($years, true);
        $ctx = asset_status_label($st);
        $multiSeries = ($st === 'all');
        $points = [];
        foreach ($years as $y => $counts) {
            $titlePrefix = $prod['name'] . ' — ปี ' . $y . ' รายเดือน' . ($st !== 'all' ? ' (' . $ctx . ')' : '');
            $baseUrl = BASE_URL . '/dashboard_data.php?type=asset_product_year_months&v=' . (int) $y
               . '&p=' . $pid;
            if ($st !== 'all') {
                $baseUrl .= '&st=' . rawurlencode($st);
            }
            $points[] = dash_linechart_point(
                (string) $y,
                $prod['name'] . ' ปี ' . $y,
                $counts,
                $titlePrefix,
                $baseUrl
            );
        }
        render_dashboard_stacked_chart($points, $multiSeries, $st !== 'all' ? $st : null, $prod['name'] . ' · ' . $ctx);
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
        $byM = [];
        for ($m = 1; $m <= 12; $m++) {
            $byM[$m] = asset_status_empty_counts();
        }
        [$ys, $ye] = year_range($y);
        $res = qr(
            'SELECT MONTH(a.produced_at) m, ' . asset_status_count_select_sql() .
            ' FROM assets a WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?' . $sf['sql'] .
            ' GROUP BY m',
            'iss' . $sf['types'],
            array_merge([$pid, $ys, $ye], $sf['params'])
        );
        while ($r = $res->fetch_assoc()) {
            $byM[(int) $r['m']] = asset_status_counts_from_row($r);
        }
        $multiSeries = ($st === 'all');
        $points = [];
        for ($m = 1; $m <= 12; $m++) {
            $ym = sprintf('%04d-%02d', $y, $m);
            $counts = $byM[$m];
            $titlePrefix = $prod['name'] . ' — เดือน ' . thai_month_period_label($ym);
            $baseUrl = BASE_URL . '/dashboard_data.php?type=asset_product_month&v=' . rawurlencode($ym)
               . '&p=' . $pid;
            if ($st !== 'all') {
                $baseUrl .= '&st=' . rawurlencode($st);
            }
            $points[] = dash_linechart_point(
                thai_month_short($ym),
                thai_month_period_label($ym),
                $counts,
                $titlePrefix,
                $baseUrl
            );
        }
        render_dashboard_stacked_chart(
            $points,
            $multiSeries,
            $st !== 'all' ? $st : null,
            $prod['name'] . ' · ปี ' . thai_buddhist_year($y) . ($st !== 'all' ? ' · ' . asset_status_label($st) : '')
        );
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
            "$ASSET_SQL WHERE a.product_id=? AND a.produced_at>=? AND a.produced_at<?{$sf['sql']}"
            . asset_list_order_sql() . " LIMIT $LIMIT",
            'iss' . $sf['types'],
            array_merge([$pid, $ms, $me], $sf['params'])
        );
        echo '<div class="muted" style="margin-bottom:8px">' . h($prod['name']) . ' · '
           . h(thai_month_period_label($v)) . ' · ' . h(asset_status_label($st)) . '</div>';
        echo '<div class="table-wrap"><table class="list"><tr><th>หมายเลขเครื่อง</th><th>สถานะ</th><th>ผลิตเมื่อ</th></tr>';
        $n = 0;
        while ($r = $res->fetch_assoc()) {
            $n++;
            echo '<tr class="clickable" style="cursor:pointer" onclick="openAssetUrl(\'' . BASE_URL . '/asset.php?id=' . (int) $r['id'] . '\')">'
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

    case 'inv_pickup_model': // Dashboard: popup รายละเอียดใบเบิกของกลุ่มรุ่น (v = "1-3")
        require_once __DIR__ . '/includes/inv_pickup.php';
        $grp = inv_pickup_grp((string) $v);
        $d = inv_pickup_load();
        if (!$d['ok']) {
            echo '<p class="muted">ต่อระบบ inventory ไม่ได้: ' . h($d['error']) . '</p>';
            break;
        }
        $list = array_values(array_filter($d['items'], function ($it) use ($grp) { return $it['grp'] === $grp && $it['state'] === 'ready'; }));
        if (!$list) {
            echo '<p class="muted">ไม่มีของรอผลิตของรุ่นนี้แล้ว</p>';
            break;
        }
        $pids = $list[0]['products'];
        $prods = [];
        $pr = db()->query('SELECT id, name, icon_path FROM products WHERE id IN (' . implode(',', array_map('intval', $pids)) . ')');
        while ($x = $pr->fetch_assoc()) {
            $prods[(int) $x['id']] = $x;
        }
        $icon = '';
        foreach ($pids as $p) {
            if (!empty($prods[$p]['icon_path'])) {
                $icon = $prods[$p]['icon_path'];
                break;
            }
        }
        $qtyAll = array_sum(array_column($list, 'qty'));
        $doneAll = array_sum(array_column($list, 'done'));
        $left = array_sum(array_column($list, 'left'));
        $pct = $qtyAll > 0 ? min(100, (int) round($doneAll * 100 / $qtyAll)) : 0;
        $barCol = status_bar_color('new');   // ผลิตแล้ว = เครื่องใหม่ (สีจาก ui_status_palette)
        $snByDoc = inv_pickup_alloc_assets_by_doc($grp, array_column($list, 'pre_id'));
        $B = BASE_URL;
        echo '<div class="pk-pop">';
        echo '<div class="pk-pop-head">' . ($icon ? img_tag($icon, $list[0]['model'], 'pk-pop-img') : '<span class="pk-pop-img pk-noimg"></span>')
            . '<div class="pk-pop-sum"><b class="pk-pop-name">' . h($list[0]['model']) . '</b>'
            . '<div class="muted">เบิกมา ' . number_format($qtyAll) . ' · ผลิตแล้ว ' . number_format($doneAll)
            . ' · เหลือ ' . number_format($left) . ' เครื่อง · ' . count($list) . ' ใบเบิก</div></div>'
            . '<span class="pk-pct">' . $pct . '%</span></div>';
        echo '<div class="pk-bar"><span style="width:' . $pct . '%;background:' . h($barCol) . '"></span></div>';
        // การ์ดต่อใบเบิก: ความคืบหน้าของใบนั้น · S/N ที่ผลิตจากใบนั้น · อะไหล่ที่คลังจ่าย
        foreach ($list as $it) {
            $p1 = $it['qty'] > 0 ? min(100, (int) round($it['done'] * 100 / $it['qty'])) : 0;
            echo '<div class="pk-doc' . ($it['missing'] ? ' is-short' : '') . '">';
            echo '<div class="pk-doc-head"><span class="pk-doc-id"><a href="'
                . h($B . '/inv_pickups.php?pre=' . rawurlencode($it['pre_id']) . '&grp=' . $it['grp']) . '"><b>'
                . h(date('d/m', strtotime($it['date']))) . ' · ' . h($it['pre_id']) . '</b></a>'
                . ($it['by'] !== '' ? ' <span class="muted">· ' . h($it['by']) . '</span>' : '') . '</span>'
                . '<span class="pk-doc-n"><b>' . (int) $it['done'] . '</b>/' . (int) $it['qty'] . ' · เหลือ ' . (int) $it['left'] . '</span></div>';
            echo '<div class="pk-bar pk-bar-sm"><span style="width:' . $p1 . '%;background:' . h($barCol) . '"></span></div>';
            $sns = $snByDoc[$it['pre_id']] ?? [];
            if ($sns) {
                echo '<div class="pk-doc-sns">';
                foreach (array_slice($sns, 0, 12) as $s) {
                    echo '<a class="pk-sn" href="' . h($B . '/asset.php?id=' . (int) $s['asset_id']) . '">' . h($s['asset_code']) . '</a>';
                }
                if (count($sns) > 12) {
                    echo '<a class="pk-sn pk-sn-more" href="' . h($B . '/inv_pickups.php?pre=' . rawurlencode($it['pre_id']) . '&grp=' . $it['grp']) . '">+'
                        . (count($sns) - 12) . '</a>';
                }
                echo '</div>';
            } else {
                echo '<div class="muted pk-doc-none">ยังไม่ได้ลงทะเบียนเครื่องจากใบนี้</div>';
            }
            $parts = [];
            foreach ($d['docs'][$it['pre_id']]['lines'] as $ln) {
                // ใบแยกชิ้น: นับเฉพาะอะไหล่ของรุ่นนี้ (ใบเดียวกันมีของรุ่นอื่น/สายคล้องปนอยู่)
                $mine = $it['kind'] !== 'parts' || array_filter($it['parts'], function ($p) use ($ln) { return strpos($p, $ln['name']) === 0; });
                if ($ln['got'] > 0 && $mine) {
                    $parts[] = h($ln['name']) . ' ×' . (0 + $ln['got']);
                }
            }
            if ($parts) {
                echo '<div class="pk-doc-parts">อะไหล่ที่คลังจ่าย: ' . implode(' · ', $parts) . '</div>';
            }
            if ($it['missing']) {
                echo '<div class="pk-doc-warn">คลังยังไม่ได้จ่าย: ' . h(implode(' · ', $it['missing'])) . '</div>';
            }
            echo '</div>';
        }
        echo '<div class="pk-pop-btns">';
        foreach ($pids as $p) {
            $nm = $prods[$p]['name'] ?? ('#' . $p);
            echo '<a class="btn btn-sm" href="' . h($B . '/asset_new.php?product=' . $p) . '">' . ui_btn_label('assets', count($pids) > 1 ? ' ลงทะเบียน ' . $nm : ' ลงทะเบียนเครื่องรุ่นนี้') . '</a>';
        }
        echo '</div></div>';
        break;

    case 'inv_pickup_summary': // Dashboard: การ์ดใบเบิก inventory + คอลัมน์ "เบิกแล้ว รอผลิต" ในตารางรุ่น (JSON)
        require_once __DIR__ . '/includes/inv_pickup.php';
        header('Content-Type: application/json; charset=utf-8');
        $d = inv_pickup_load();
        // ยังไม่ได้ตั้งค่า/ต่อ inventory ไม่ได้/ยังไม่ผูกรุ่นเลย = ไม่ต้องโชว์การ์ด
        if (!$d['ok'] || !inv_pickup_tracked_products()) {
            echo json_encode(['ok' => false, 'error' => (string) $d['error']], JSON_UNESCAPED_UNICODE);
            break;
        }
        $out = ['ok' => true, 'ready' => 0, 'ready_models' => 0, 'oldest' => '', 'waiting' => 0, 'waiting_last' => '',
                'waiting_by' => '', 'unmatched' => count(inv_pickup_unmatched_assets()), 'since' => date('d/m', strtotime(inv_pickup_since())),
                'by_pid' => []];
        $groups = [];
        $waitingDocs = [];
        foreach ($d['items'] as $it) {
            if ($it['state'] === 'ready') {
                $out['ready'] += $it['left'];
                $groups[$it['grp']] = true;
                if ($out['oldest'] === '' || $it['date'] < $out['oldest']) {
                    $out['oldest'] = $it['date'];
                }
                // รายรุ่นในตาราง: กลุ่มหลายรุ่นนับให้ทุกรุ่นในกลุ่ม แล้วบอกว่าเป็นยอดใช้ร่วม
                foreach ($it['products'] as $p) {
                    $row = $out['by_pid'][$p] ?? ['left' => 0, 'shared' => false];
                    $row['left'] += $it['left'];
                    $row['shared'] = $row['shared'] || count($it['products']) > 1;
                    $out['by_pid'][$p] = $row;
                }
            } elseif ($it['state'] === 'waiting') {
                $waitingDocs[$it['pre_id']] = $it;
            }
        }
        $out['ready_models'] = count($groups);
        // รายรุ่นสำหรับการ์ด (แบบ ก) — กลุ่มรุ่นที่รอผลิต เรียงเหลือมากสุดก่อน
        $icons = [];
        $ir = db()->query('SELECT id, icon_path FROM products');
        while ($x = $ir->fetch_row()) {
            $icons[(int) $x[0]] = (string) $x[1];
        }
        $models = [];
        foreach ($d['items'] as $it) {
            if ($it['state'] !== 'ready') {
                continue;
            }
            $g = $it['grp'];
            if (!isset($models[$g])) {
                $icon = '';
                foreach ($it['products'] as $p) {
                    if (!empty($icons[$p])) {
                        $icon = (string) img_url($icons[$p]);
                        break;
                    }
                }
                $models[$g] = ['grp' => $g, 'name' => $it['model'], 'icon' => $icon, 'left' => 0, 'docs' => 0, 'oldest' => $it['date'], 'missing' => []];
            }
            $models[$g]['left'] += $it['left'];
            $models[$g]['docs']++;
            $models[$g]['oldest'] = min($models[$g]['oldest'], $it['date']);
            foreach ($it['missing'] as $m) {
                $models[$g]['missing'][preg_replace('/\s*\(.*$/u', '', $m)] = true;
            }
        }
        usort($models, function ($a, $b) { return [$b['left'], $a['name']] <=> [$a['left'], $b['name']]; });
        foreach ($models as &$m) {
            $m['oldest'] = date('d/m', strtotime($m['oldest']));
            $m['missing'] = array_keys($m['missing']);
        }
        unset($m);
        $out['models'] = $models;
        $out['oldest'] = $out['oldest'] !== '' ? date('d/m', strtotime($out['oldest'])) : '';
        $out['waiting'] = count($waitingDocs);
        if ($waitingDocs) {
            $last = end($waitingDocs);
            $out['waiting_last'] = date('d/m', strtotime($last['date']));
            $out['waiting_by'] = (string) $last['by'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        break;

    case 'fg_model_stock': // การ์ดรุ่นบน Dashboard — ยอดคลัง/ขาด/เกิน รายรหัสรุ่น (JSON)
        require_once dirname(__DIR__) . '/shared/finishgood_shortage_dashboard.php';
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(fg_model_stock_map(), JSON_UNESCAPED_UNICODE);
        break;

    case 'fg_shortage_summary': // ตัวเลขสรุปรวม (JSON) — ใช้ในหน้าอื่นที่ต้องการแค่ยอดรวม
    case 'fg_shortage':         // รายการรุ่นที่ต้องผลิตเพิ่ม (HTML) — หน้า Dashboard ใช้การ์ดรุ่นรวมแทนแล้ว
        require_once dirname(__DIR__) . '/shared/finishgood_shortage_dashboard.php';
        $fg = fg_shortage_dashboard_data(isset($_GET['refresh']));
        $fgUpdated = $fg['saved_at'] > 0 ? date('d/m/Y H:i', (int) $fg['saved_at']) . ' น.' : '';

        if ($type === 'fg_shortage_summary') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'             => (bool) $fg['ok'],
                'models'         => count($fg['items']),
                'total_shortage' => (int) $fg['total_shortage'],
                'stale'          => (bool) $fg['stale'],
                'updated'        => $fgUpdated,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        if (!$fg['ok']) {
            echo '<p class="muted">ดึงข้อมูลจากระบบ Setup ไม่ได้: ' . h($fg['error']) . '</p>';
            break;
        }

        echo '<p class="muted" style="font-size:12px;margin:0 0 10px">'
           . 'ขาด = คงเหลือ − (ขั้นต่ำ + PO ค้าง) · กดการ์ดเพื่อดูหมายเลขสินค้าที่อยู่ในสต็อก · ข้อมูล ณ ' . h($fgUpdated)
           . ($fg['stale'] ? ' <b style="color:var(--warning)">(ดึงรอบล่าสุดไม่สำเร็จ — แสดงข้อมูลเก่า)</b>' : '')
           . ($fg['skipped'] > 0 ? ' · ซ่อน ' . number_format($fg['skipped']) . ' รุ่นที่ปิดแจ้งเตือนไว้' : '')
           . '</p>';
        if ($fg['registry_error'] !== '') {
            echo '<p class="muted" style="font-size:12px;color:var(--warning)">นับจากทะเบียนเครื่องไม่ได้ จึงใช้ตัวเลขของระบบ Setup ทุกรุ่น: ' . h($fg['registry_error']) . '</p>';
        }
        if (!$fg['items']) {
            echo '<p class="muted dash-low-stock-empty">ไม่มีรุ่นที่ต้องผลิตเพิ่ม — ทุกรุ่นเพียงพอ</p>';
            break;
        }

        // รูปรุ่นจากทะเบียนเรา จับคู่ด้วยชื่อ (ชื่อใน setupsystem กับของเราตรงกันเกือบทุกรุ่น)
        $fgIcons = [];
        $fgRes = db()->query("SELECT name, icon_path FROM products WHERE icon_path IS NOT NULL AND icon_path <> ''");
        while ($fgRes && ($r = $fgRes->fetch_assoc())) {
            $fgIcons[mb_strtolower(trim((string) $r['name']), 'UTF-8')] = (string) $r['icon_path'];
        }

        // การ์ดชุดเดียวกับแท็บ "จำนวนเครื่อง" / "สต็อกอะไหล่" ในแผงเดียวกัน — สลับแท็บแล้วหน้าตาไม่กระโดด
        echo '<div class="model-card-grid">';
        foreach ($fg['items'] as $it) {
            $name = (string) $it['product_name'];
            $code = (string) $it['product_code'];
            $available = (int) $it['available'];
            $required = (int) $it['required'];
            $need = (int) $it['need'];
            $fromRegistry = ($it['stock_source'] ?? '') === 'production_registry';
            $icon = $fgIcons[mb_strtolower(trim($name), 'UTF-8')] ?? '';
            $pctHave = $required > 0 ? min(100, (int) round($available / $required * 100)) : 100;
            $critical = $pctHave < 50;
            $serialUrl = BASE_URL . '/dashboard_data.php?type=fg_shortage_serials&code=' . rawurlencode($code);
            $tip = 'มี ' . number_format($available) . ' / ต้องการ ' . number_format($required)
                 . ' (ขั้นต่ำ ' . number_format((int) $it['minimum_stock']) . ' + PO ' . number_format((int) $it['po_qty']) . ')';

            echo '<div class="model-card clickable" role="button" tabindex="0" title="' . h($tip) . '"'
               . ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();this.click();}"'
               . ' onclick="showListModal(' . h(json_encode('หมายเลขสินค้าในสต็อก: ' . $name, JSON_UNESCAPED_UNICODE)) . ', '
               . h(json_encode($serialUrl, JSON_UNESCAPED_UNICODE)) . ', \'\')">';
            echo '<div class="model-card-img">' . ($icon !== ''
                    ? img_tag($icon, $name, 'model-thumb')
                    : '<span class="model-thumb model-thumb-ph">' . ui_icon_html('box', 18) . '</span>') . '</div>';
            echo '<div class="model-card-body">'
               . '<div class="model-card-name" title="' . h($name) . '">' . h($name) . '</div>'
               . '<div class="model-card-sub muted">' . h($code) . ($fromRegistry ? ' · <span class="fg-src">นับจากทะเบียนเรา</span>' : '') . '</div>'
               . '<div class="model-card-bar"><div class="model-card-fill" style="width:' . $pctHave . '%;background:' . ($critical ? 'var(--danger)' : 'var(--warning)') . '"></div></div>'
               . '<div class="model-card-foot"><div class="model-card-num">มี ' . number_format($available) . ' / ต้องการ ' . number_format($required)
               . ' · <span class="' . ($critical ? 'text-danger' : 'text-warn') . '">ขาด ' . number_format(abs($need)) . '</span></div></div>'
               . '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p style="margin-top:10px"><a href="' . h(BASE_URL . '/finishgood_shortage_preview.php') . '">ตั้งค่าการนับและการแจ้งเตือนรายรุ่น ›</a></p>';
        break;

    case 'fg_shortage_serials': // กดการ์ดรายรุ่น → หมายเลขสินค้าที่ประกอบเป็นตัวเลข "มี"
        require_once dirname(__DIR__) . '/shared/finishgood_shortage_dashboard.php';
        $fgCode = strtoupper(trim((string) ($_GET['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,31}$/', $fgCode)) {
            exit('<p class="muted">รหัสรุ่นไม่ถูกต้อง</p>');
        }
        // ใช้แถวจาก cache ชุดเดียวกับการ์ด ตัวเลขหัวรายการจะได้ตรงกับที่เพิ่งกดมา
        $fg = fg_shortage_dashboard_data();
        $fgItem = null;
        foreach ($fg['items'] as $it) {
            if (strtoupper(trim((string) $it['product_code'])) === $fgCode) {
                $fgItem = $it;
                break;
            }
        }
        $fgFallback = false;
        if ($fgItem === null) {
            // รุ่นที่ไม่ขาด API ไม่ส่งมาให้เลย — นับเองจากทะเบียนเรา จะได้เปิดดูรายละเอียดได้เหมือนกัน
            $fgFallback = true;
            $fgReg = fg_shortage_registry_rows([$fgCode]);
            if (!empty($fgReg['ok']) && isset($fgReg['rows'][$fgCode])) {
                $fgItem = $fgReg['rows'][$fgCode];
            } else {
                exit('<p class="muted">ยังไม่มีข้อมูลของรุ่นนี้'
                   . (!empty($fgReg['error']) ? ' — ' . h((string) $fgReg['error']) : '') . '</p>');
            }
        }
        $sr = fg_shortage_serials($fgItem);
        if (!$sr['ok']) {
            exit('<p class="muted">' . h($sr['error']) . '</p>');
        }

        $fgFromRegistry = $sr['mode'] === 'registry';
        $fgNeed = (int) $fgItem['need'];
        $fgVerdict = $fgNeed < 0
            ? '<b style="color:var(--danger,#dc2626)">ขาด ' . number_format(abs($fgNeed)) . '</b>'
            : '<b style="color:var(--success,#16a34a)">' . ($fgNeed > 0 ? 'เกิน ' . number_format($fgNeed) : 'ครบพอดี') . '</b>';
        echo '<p style="margin:0 0 4px">มี <b>' . number_format((int) $fgItem['available']) . '</b> · ต้องการ <b>' . number_format((int) $fgItem['required'])
           . '</b> (ขั้นต่ำ ' . number_format((int) $fgItem['minimum_stock']) . ' + PO ค้าง ' . number_format((int) $fgItem['po_qty'])
           . ') · ' . $fgVerdict
           . ($fgFromRegistry && $sr['leasing'] ? ' · <span class="muted">เครื่องใหม่ ' . number_format(count($sr['stock'])) . ' + คลังพร้อมเช่า ' . number_format(count($sr['leasing'])) . '</span>' : '')
           . '</p>';
        echo '<p class="muted" style="font-size:12px;margin:0 0 12px">'
           . ($fgFromRegistry
               ? 'รุ่นนี้นับจากทะเบียนเครื่องของเรา — มี = เครื่องใหม่ (ไม่ได้ลงทะเบียนในระบบเช่า) + คลังพร้อมเช่า (ระบบเช่าเป็น finished goods)'
               : 'ตัวเลข "มี" มาจากระบบ Setup = หมายเลขในสต็อก + เครื่องเช่าพร้อมเช่า')
           . '</p>';
        if ($fgFallback && $fgNeed < 0) {
            // ระบบ Setup ไม่ได้แจ้งว่ารุ่นนี้ขาด แต่ทะเบียนเรานับได้น้อยกว่าที่ต้องมี — บอกไว้ไม่ให้งงว่าทำไมการ์ดกับในนี้ไม่ตรง
            echo '<p class="muted" style="font-size:12px;margin:-6px 0 12px;color:var(--warning)">'
               . 'ระบบ Setup ไม่ได้แจ้งว่ารุ่นนี้ขาด — ตัวเลขนี้นับจากทะเบียนเราเอง สองฝั่งจึงอาจไม่ตรงกัน</p>';
        }
        if ($sr['error'] !== '') {
            echo '<p class="muted" style="font-size:12px;color:var(--warning)">' . h($sr['error']) . '</p>';
        }

        $fgTable = static function (string $heading, array $rows, string $note = '') {
            echo '<h4 style="margin:14px 0 6px;font-size:14px">' . h($heading) . ' <span class="muted" style="font-weight:400">(' . number_format(count($rows)) . ')</span></h4>';
            if ($note !== '') {
                echo '<p class="muted" style="font-size:12px;margin:0 0 6px">' . h($note) . '</p>';
            }
            if (!$rows) {
                echo '<p class="muted" style="font-size:13px">ไม่มี</p>';
                return;
            }
            // กริดแทนตาราง — มีแค่หมายเลขกับวันที่ ตาราง .list ที่พับบนจอเล็กและเลื่อนข้างบนจอกว้างเกินความจำเป็น
            echo '<ul class="fg-sn-list">';
            foreach ($rows as $row) {
                $sn = h($row['sn']);
                $label = $row['asset_id'] > 0
                    // เปิดแท็บใหม่ — กลับมาแล้ว popup กับรายการที่ไล่ดูอยู่ยังค้างไว้เหมือนเดิม
                    ? '<a href="' . h(BASE_URL . '/asset.php?id=' . (int) $row['asset_id']) . '" target="_blank" rel="noopener">' . $sn . '</a>'
                    : $sn;
                echo '<li class="fg-sn"><b>' . $label . '</b><span class="muted">'
                   . ($row['date'] !== '' ? dthai(substr($row['date'], 0, 10)) : '—') . '</span></li>';
            }
            echo '</ul>';
        };

        if ($fgFromRegistry) {
            $fgTable('เครื่องผลิตใหม่ในทะเบียน', $sr['stock'], 'เครื่องสถานะใหม่ที่ไม่ได้ลงทะเบียนในระบบเช่า — ผลิตแล้วยังไม่ถูกเบิกออก');
        } elseif ($sr['mode'] === 'manual') {
            echo '<h4 style="margin:14px 0 6px;font-size:14px">สต็อก <span class="muted" style="font-weight:400">(' . number_format($sr['manual_qty']) . ')</span></h4>'
               . '<p class="muted" style="font-size:13px">ยอดนี้มาจากการนับสต็อกด้วยมือในระบบ Setup ซึ่งไม่ได้เก็บหมายเลขสินค้าไว้</p>';
        } else {
            $fgNote = $sr['last_check_at'] !== ''
                ? 'นับเฉพาะหมายเลขที่บันทึกหลังการนับสต็อกครั้งล่าสุด (' . dthai(substr($sr['last_check_at'], 0, 10)) . ') ที่ยังไม่ถูกเบิกออก'
                : 'หมายเลขที่ยังไม่ถูกเบิกออก';
            $fgTable('หมายเลขในสต็อก', $sr['stock'], $fgNote);
        }
        if ($sr['leasing'] || (int) $fgItem['leasing_qty'] > 0) {
            $fgTable(
                $fgFromRegistry ? 'คลังพร้อมเช่า' : 'เครื่องเช่าวนกลับมาปล่อยใหม่',
                $sr['leasing'],
                $fgFromRegistry
                    ? 'ระบบเช่าเป็น finished goods รอปล่อยเช่า — นับรวมในยอดที่มี'
                    : 'รับคืนจากลูกค้าและตรวจ MA แล้ว พร้อมปล่อยเช่ารอบใหม่ — ไม่ใช่ของที่ผลิตใหม่'
            );
        }

        // ฝั่ง "ต้องการ" — PO ค้างมาจากใบสั่งงานใบไหนบ้าง
        $fgPo = fg_shortage_open_pos($fgCode);
        echo '<h4 style="margin:18px 0 6px;font-size:14px">PO ค้าง <span class="muted" style="font-weight:400">('
           . number_format((int) $fgItem['po_qty']) . ' ชิ้น)</span></h4>';
        if (!$fgPo['ok']) {
            echo '<p class="muted" style="font-size:13px;color:var(--warning)">' . h($fgPo['error']) . '</p>';
        } elseif (!$fgPo['rows']) {
            echo '<p class="muted" style="font-size:13px">ไม่มีใบสั่งงานค้างส่ง</p>';
        } else {
            // แยกสองกอง: ใบที่ยังใช้งานอยู่ กับใบที่ถูกลบไปแล้วแต่รายการค้างในฐานข้อมูล
            $poLive = [];
            $poGone = [];
            foreach ($fgPo['rows'] as $po) {
                if (!empty($po['orphan'])) { $poGone[] = $po; } else { $poLive[] = $po; }
            }
            $fgPoDate = function (array $po) {
                if (!empty($po['po_date']) && $po['po_date'] !== '0000-00-00') {
                    return dthai(substr((string) $po['po_date'], 0, 10));
                }
                return $po['created_at'] !== '' ? dthai(substr((string) $po['created_at'], 0, 10)) : '';
            };

            if ($poLive) {
                echo '<p class="muted" style="font-size:12px;margin:0 0 6px">ใบสั่งงานที่ยังไม่ได้ส่งของ '
                   . number_format(count($poLive)) . ' ใบ · ระบบ Setup ถือว่าส่งแล้วเมื่อปิดงานและเขียนประเภทการขายลงในรายการ</p>';
                echo '<ul class="fg-sn-list fg-po-list">';
                foreach ($poLive as $po) {
                    $sub = ['#' . (int) $po['order_id']];
                    if ($po['customer'] !== '')  { $sub[] = $po['customer']; }
                    if ($po['sale_type'] !== '') { $sub[] = $po['sale_type']; }
                    if ($po['status'] !== '')    { $sub[] = 'สถานะ ' . $po['status']; }
                    $when = $fgPoDate($po);
                    echo '<li class="fg-sn fg-po">'
                       . '<span class="fg-po-main"><b>' . h($po['po_number'] !== '' ? $po['po_number'] : 'ไม่มีเลข PO') . '</b>'
                       . '<span class="muted fg-po-sub">' . h(implode(' · ', $sub)) . '</span></span>'
                       . '<span class="muted fg-po-qty">' . number_format($po['qty']) . ' ชิ้น'
                       . ($when !== '' ? ' · ' . h($when) : '') . '</span></li>';
                }
                echo '</ul>';
            } else {
                echo '<p class="muted" style="font-size:13px">ไม่มีใบสั่งงานค้างส่ง</p>';
            }

            if ($poGone) {
                // คำอธิบายพูดครั้งเดียวตรงหัวข้อ ในการ์ดเก็บแต่ข้อมูลของใบนั้นจริง ๆ จะได้ไม่อ่านซ้ำ 5 รอบ
                echo '<h4 style="margin:18px 0 4px;font-size:14px">ใบสั่งงานที่ถูกลบทิ้งแล้ว '
                   . '<span class="muted" style="font-weight:400">(' . number_format(count($poGone)) . ' ใบ · '
                   . number_format((int) $fgPo['orphan_qty']) . ' ชิ้น — ไม่ได้นับรวม)</span></h4>';
                echo '<p class="muted" style="font-size:12px;margin:0 0 6px">'
                   . 'ใบ PO ส่วนนี้ถูกลบออกจากระบบ Setup ไปแล้ว แต่รายการสินค้าในใบยังค้างอยู่ในฐานข้อมูล '
                   . 'ระบบเราจึงไม่นับเป็นของที่ต้องผลิต'
                   . ($fgFromRegistry ? '' : ' (ตัวเลข PO ค้างด้านบนมาจากระบบ Setup ซึ่งยังนับรวมอยู่)')
                   . '</p>';
                echo '<ul class="fg-sn-list fg-po-list fg-po-gone-list">';
                foreach ($poGone as $po) {
                    $head = 'ใบสั่งงาน #' . (int) $po['order_id'];
                    $who = [];
                    if ($po['customer'] !== '') { $who[] = $po['customer']; }
                    if ($po['created_at'] !== '') {
                        $who[] = 'เปิดใบ ' . dthai(substr((string) $po['created_at'], 0, 10));
                    }
                    // ระบบ Setup ลบใบทิ้งโดยไม่เก็บ log — บอกวันที่ลบได้เฉพาะใบที่หายไปตอนระบบเราเฝ้าอยู่แล้ว
                    $who[] = !empty($po['seen_usable']) && (int) $po['seen_first'] > 0
                        ? 'ถูกลบราววันที่ ' . dthai(date('Y-m-d', (int) $po['seen_first']))
                        : 'ไม่ทราบวันที่ถูกลบ';

                    echo '<li class="fg-sn fg-po fg-po-gone">'
                       . '<span class="fg-po-main"><b>' . h($head) . '</b>'
                       . '<span class="muted fg-po-sub">' . h(implode(' · ', $who)) . '</span>';
                    if (!empty($po['items'])) {
                        $bits = [];
                        foreach (array_slice($po['items'], 0, 10) as $it) {
                            $bits[] = $it['name'] . ' ×' . number_format((int) $it['qty']);
                        }
                        $more = count($po['items']) - count($bits);
                        echo '<span class="muted fg-po-sub">ในใบมี: ' . h(implode(', ', $bits))
                           . ($more > 0 ? h(' และอีก ' . number_format($more) . ' รายการ') : '') . '</span>';
                    }
                    echo '</span>'
                       . '<span class="muted fg-po-qty">' . number_format($po['qty']) . ' ชิ้น<br>ไม่นับ</span></li>';
                }
                echo '</ul>';
            }

            if ($fgPo['total'] !== (int) $fgItem['po_qty']) {
                $diff = (int) $fgItem['po_qty'] - (int) $fgPo['total'];
                $msg = ($diff === (int) $fgPo['orphan_qty'] && $diff > 0)
                    ? 'ตัวเลข PO ค้างด้านบน (' . number_format((int) $fgItem['po_qty']) . ') ยังรวมใบที่ถูกลบทิ้งแล้ว '
                      . number_format($diff) . ' ชิ้นอยู่ · นับเฉพาะใบที่ยังใช้งานได้ ' . number_format((int) $fgPo['total']) . ' ชิ้น'
                    : 'ตัวเลข PO ค้างด้านบน (' . number_format((int) $fgItem['po_qty']) . ') มาจากรอบคำนวณก่อนหน้า · '
                      . 'ตอนนี้นับใบที่ยังใช้งานได้ ' . number_format((int) $fgPo['total']) . ' ชิ้น';
                echo '<p class="muted" style="font-size:12px;margin-top:6px;color:var(--warning)">' . h($msg) . '</p>';
            }
        }

        // จำนวนหมายเลขกับตัวเลขหัวรายการมาคนละจังหวะ (ตัวเลขเก็บ cache ไว้ 10 นาที) — ไม่ตรงก็บอกตรง ๆ
        $fgStockShown = $sr['mode'] === 'manual' ? $sr['manual_qty'] : count($sr['stock']);
        $fgLeaseShown = count($sr['leasing']);
        if ($fgStockShown !== (int) $fgItem['stock_qty'] || $fgLeaseShown !== (int) $fgItem['leasing_qty']) {
            echo '<p class="muted" style="font-size:12px;margin-top:10px;color:var(--warning)">'
               . 'จำนวนหมายเลขไม่ตรงกับตัวเลขด้านบน (ในสต็อก ' . number_format((int) $fgItem['stock_qty']) . ' · เช่า ' . number_format((int) $fgItem['leasing_qty'])
               . ') — ตัวเลขด้านบนคำนวณไว้เมื่อ ' . h($fg['saved_at'] > 0 ? date('H:i', (int) $fg['saved_at']) . ' น.' : '-')
               . ' ข้อมูลอาจเปลี่ยนไปแล้ว</p>';
        }
        break;

    default:
        echo 'ไม่รู้จักประเภทข้อมูล';
}
