<?php
/**
 * includes/dashboard_chart.php — helper กรaph stacked bar บน dashboard
 *
 * ใช้ร่วมกับ index.php (กรaphผลิตรายปี inline) และ dashboard_data.php (modal drill-down)
 * รองรับ compact mode และ inline drill สลับมุมมองรายเดือนบนหน้าแรก
 */

/**
 * onclick เปิด modal ชั้นถัดไป
 *
 * @param string $title
 * @param string $url
 * @return string
 */
function drill_onclick($title, $url)
{
    return 'showListModal(' . h(json_encode($title, JSON_UNESCAPED_UNICODE)) . ", '" . h($url) . "', '')";
}

/**
 * SELECT นับเครื่องแยกตาม status สำหรับ stacked bar
 *
 * @param string $alias alias ตาราง assets
 * @return string
 */
function asset_status_count_select_sql($alias = 'a')
{
    $parts = [];
    foreach (status_list() as $st) {
        $parts[] = "SUM(CASE WHEN {$alias}.status='" . $st . "' THEN 1 ELSE 0 END) AS c_{$st}";
    }
    return 'COUNT(*) AS total, ' . implode(', ', $parts);
}

/**
 * @return array<string,int>
 */
function asset_status_empty_counts()
{
    $counts = [];
    foreach (status_list() as $st) {
        $counts[$st] = 0;
    }
    return $counts;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,int>
 */
function asset_status_counts_from_row(array $row)
{
    $counts = [];
    foreach (status_list() as $st) {
        $counts[$st] = (int) ($row['c_' . $st] ?? 0);
    }
    return $counts;
}

/**
 * @param array<string,int> $counts
 * @return int
 */
function asset_status_counts_total(array $counts)
{
    return array_sum($counts);
}

/**
 * ยอดผลิตเดือนล่าสุดเทียบเดือนก่อนหน้า
 *
 * หน้าแดชบอร์ดตอบได้แต่ "ตอนนี้มีเท่าไหร่" ทุกตัวเลขเป็นภาพนิ่ง ไม่มีที่ไหนบอกว่า
 * กำลังเพิ่มหรือลด · ใช้ข้อมูลรายเดือนที่ index.php ดึงมาอยู่แล้วสำหรับกราฟ
 * จึงไม่มี query เพิ่ม
 *
 * เดือนล่าสุด = เดือนปัจจุบันตามปฏิทิน ไม่ใช่เดือนสุดท้ายที่มีข้อมูล — ต้นเดือนที่ยัง
 * ผลิตไม่กี่เครื่องก็ต้องเห็นว่าน้อย ไม่ใช่ให้ระบบข้ามไปหยิบเดือนก่อนมาแสดงแทน
 *
 * @param array<int,array<int,array<string,int>>> $monthCountsByYear [ปี][เดือน] => counts
 * @param string|null $today วันที่อ้างอิง (null = วันนี้) — รับเข้ามาเพื่อให้เทสต์ได้
 * @return array<string,mixed>|null null = ไม่มีข้อมูลพอจะเทียบ
 */
function dash_month_trend(array $monthCountsByYear, $today = null)
{
    $today = $today === null ? date('Y-m-d') : $today;
    $ts = strtotime($today);
    if ($ts === false) {
        return null;
    }

    $nowY = (int) date('Y', $ts);
    $nowM = (int) date('n', $ts);
    $prevY = $nowM === 1 ? $nowY - 1 : $nowY;
    $prevM = $nowM === 1 ? 12 : $nowM - 1;

    // เดือนก่อนหน้าต้องอยู่ในช่วงปีที่ดึงมา ไม่งั้นเทียบไม่ได้จริง
    if (!isset($monthCountsByYear[$prevY][$prevM])) {
        return null;
    }

    $now = isset($monthCountsByYear[$nowY][$nowM])
        ? asset_status_counts_total($monthCountsByYear[$nowY][$nowM])
        : 0;
    $prev = asset_status_counts_total($monthCountsByYear[$prevY][$prevM]);

    return [
        'now'        => $now,
        'prev'       => $prev,
        'diff'       => $now - $prev,
        // เดือนก่อนเป็น 0 คิดเปอร์เซ็นต์ไม่ได้ (หารศูนย์) — ให้ null แล้วไปแสดงแค่ส่วนต่าง
        'pct'        => $prev > 0 ? (int) round(($now - $prev) / $prev * 100) : null,
        'now_ym'     => sprintf('%04d-%02d', $nowY, $nowM),
        'prev_ym'    => sprintf('%04d-%02d', $prevY, $prevM),
    ];
}

/**
 * @param string            $label
 * @param array<string,int> $counts
 * @return string
 */
function asset_status_stacked_tooltip($label, array $counts)
{
    $bits = [];
    foreach (status_list() as $st) {
        $c = (int) ($counts[$st] ?? 0);
        if ($c > 0) {
            $bits[] = status_th($st) . ' ' . number_format($c);
        }
    }
    return $label . ($bits ? ': ' . implode(' · ', $bits) : '');
}

/**
 * สร้าง onclick drill รวม + แยกตาม status
 *
 * @param string            $titlePrefix
 * @param string            $baseUrl URL ชั้นถัดไป (ไม่มี st)
 * @param array<string,int> $counts
 * @return array{all:string, by_status:array<string,string>}
 */
function dash_status_drill_onclicks($titlePrefix, $baseUrl, array $counts)
{
    $all = drill_onclick($titlePrefix, $baseUrl);
    $bySt = [];
    foreach (status_list() as $st) {
        if ((int) ($counts[$st] ?? 0) <= 0) {
            continue;
        }
        $bySt[$st] = drill_onclick(
            $titlePrefix . ' — ' . status_th($st),
            $baseUrl . '&st=' . rawurlencode($st)
        );
    }
    return ['all' => $all, 'by_status' => $bySt];
}

/**
 * สร้างจุดข้อมูลกรaphหนึ่งจุด
 *
 * @param string            $label
 * @param string            $title
 * @param array<string,int> $counts
 * @param string            $titlePrefix
 * @param string            $baseUrl
 * @return array<string,mixed>
 */
function dash_chart_point($label, $title, array $counts, $titlePrefix, $baseUrl)
{
    return [
        'label' => $label,
        'title' => $title,
        'counts' => $counts,
        'drill' => dash_status_drill_onclicks($titlePrefix, $baseUrl, $counts),
    ];
}

/** @deprecated alias */
function dash_linechart_point($label, $title, array $counts, $titlePrefix, $baseUrl)
{
    return dash_chart_point($label, $title, $counts, $titlePrefix, $baseUrl);
}

/**
 * สีเส้นกรaph ตาม status
 *
 * @param string $st
 * @return string
 */
function dash_chart_color($st)
{
    // สีแท่งกราฟ = สี bar ของ palette ชุดเดียวกับ badge ในตาราง (hue เดียวกับตัวอักษร)
    // เดิมเป็นพาสเทลคนละชุด ทำให้สถานะเดียวมีสองสีในหน้าเดียว
    return status_bar_color((string) $st);
}

/**
 * คำนวณค่าแกน Y แบบ округสวย
 *
 * @param int $maxVal
 * @return array<int,int>
 */
function dash_chart_y_ticks($maxVal)
{
    $maxVal = max(1, (int) $maxVal);
    $step = max(1, (int) ceil($maxVal / 3));
    $niceMax = $step * 3;
    if ($niceMax < $maxVal) {
        $niceMax = (int) ceil($maxVal / $step) * $step;
    }
    return [$niceMax, $step * 2, $step, 0];
}

/**
 * legend ด้านบนกรaph
 *
 * @param string|null $activeSt
 * @return void
 */
function render_status_legend($activeSt = null)
{
    $items = status_list();
    if ($activeSt !== null && in_array($activeSt, status_list(), true)) {
        $items = [$activeSt];
    }
    echo '<div class="dash-status-legend dash-status-legend-top">';
    foreach ($items as $st) {
        echo '<span class="dash-status-legend-item"><i class="dash-status-swatch dash-swatch-' . h($st) . '"></i>'
           . h(status_th_chip($st)) . '</span>';
    }
    echo '</div>';
}

/**
 * render stacked bar — segment มี gap คลิกง่าย
 *
 * @param array<int,array<string,mixed>> $points
 * @param bool                           $multiSeries
 * @param string|null                    $filterSt
 * @param string                         $periodLabel
 * @param array<string,mixed>            $opts compact, inline_drill, root_class, show_hint
 * @return void
 */
function render_dashboard_stacked_chart(array $points, $multiSeries = true, $filterSt = null, $periodLabel = '', array $opts = [])
{
    if (count($points) === 0) {
        echo '<p class="muted">ไม่มีข้อมูล</p>';
        return;
    }

    $compact = !empty($opts['compact']);
    $inlineDrill = !empty($opts['inline_drill']);
    $showHint = array_key_exists('show_hint', $opts) ? (bool) $opts['show_hint'] : !$compact;
    $rootClass = 'dash-stacked-chart' . ($compact ? ' dash-stacked-chart-compact' : '');
    if (!empty($opts['root_class'])) {
        $rootClass .= ' ' . h((string) $opts['root_class']);
    }

    $grandTotal = 0;
    $maxTotal = 0;
    foreach ($points as $pt) {
        $t = asset_status_counts_total($pt['counts']);
        $grandTotal += $t;
        $maxTotal = max($maxTotal, $t);
    }
    $yTicks = dash_chart_y_ticks($maxTotal);
    $yMax = $yTicks[0];

    $activeSt = null;
    if (!$multiSeries) {
        $activeSt = $filterSt;
        if ($activeSt === null || !in_array($activeSt, status_list(), true)) {
            foreach (status_list() as $st) {
                $activeSt = $st;
                break;
            }
        }
    }

    echo '<div class="' . $rootClass . '">';
    // hide_legend: มุมมองรายเดือน (drill) หรือที่ต้องการประหยัดพื้นที่ — กราฟรายปีคู่ MA
    // บนแดชบอร์ดควรแสดง legend เพื่ออ่านสีแท่งได้โดยไม่ต้องมองขึ้นไปที่การ์ด KPI
    $hideLegend = !empty($opts['hide_legend']);
    if (!$compact || !$hideLegend) {
        echo '<div class="dash-chart-top">';
        if (!$compact) {
            echo '<div class="dash-chart-head">';
            echo '<div class="dash-chart-total">' . number_format($grandTotal) . ' <span class="dash-chart-unit">เครื่อง</span>';
            if ($periodLabel !== '') {
                echo ' <span class="dash-chart-period muted">· ' . h($periodLabel) . '</span>';
            }
            echo '</div></div>';
        }
        if (!$hideLegend) {
            render_status_legend($multiSeries ? null : $activeSt);
        }
        echo '</div>';
    }

    echo '<div class="dash-chart-body">';
    echo '<div class="dash-chart-yaxis" aria-hidden="true">';
    foreach ($yTicks as $tick) {
        echo '<span>' . number_format($tick) . '</span>';
    }
    echo '</div>';

    echo '<div class="dash-chart-plot">';
    echo '<div class="dash-chart-grid" aria-hidden="true">';
    foreach ($yTicks as $tick) {
        echo '<div class="dash-chart-grid-line"></div>';
    }
    echo '</div>';

    echo '<div class="dash-chart-bars" style="--dash-bar-count:' . count($points) . '">';
    foreach ($points as $pt) {
        $total = asset_status_counts_total($pt['counts']);
        $tooltip = asset_status_stacked_tooltip($pt['title'], $pt['counts']);
        $drill = $pt['drill'];
        $barH = $yMax > 0 ? max(0, min(100, ($total / $yMax) * 100)) : 0;
        $inlineYear = isset($pt['inline_year']) ? (int) $pt['inline_year'] : 0;
        $useInline = $inlineDrill && $inlineYear > 0;

        echo '<div class="dash-bar-col" title="' . h($tooltip) . '"';
        if ($useInline) {
            echo ' data-inline-year="' . $inlineYear . '"';
        }
        echo '>';

        echo '<div class="dash-bar-slot">';
        if ($total > 0) {
            if ($useInline) {
                echo '<button type="button" class="dash-bar-total dash-bar-total-inline" data-inline-year="' . $inlineYear
                   . '" title="ดูรายเดือนปี ' . h($pt['label']) . '">' . number_format($total) . '</button>';
            } else {
                echo '<button type="button" class="dash-bar-total" onclick="' . $drill['all'] . '" title="ดูรวมทั้งหมด">'
                   . number_format($total) . '</button>';
            }
        } else {
            // เดิมเป็นเป้าคลิกตาย: cursor เป็นมือแต่กดแล้วไม่มีอะไรเกิด และไม่บอกว่าทำไมว่าง
            // แยก "ยังไม่ถึงช่วงนี้" ออกจาก "ผลิต 0 เครื่อง" ให้อ่านออก
            $future = !empty($pt['is_future']);
            echo '<span class="dash-bar-total dash-bar-total-empty' . ($future ? ' is-future' : '') . '" title="' . ($future ? 'ยังไม่ถึงช่วงนี้' : 'ไม่มีการผลิตในช่วงนี้') . '" aria-label="' . h($pt['label']) . ' — '
               . ($future ? 'ยังไม่ถึงช่วงนี้' : 'ผลิต 0 เครื่อง') . '">' . ($future ? '–' : '0') . '</span>';
        }

        echo '<div class="dash-bar-area" style="height:' . round($barH, 2) . '%"';
        if ($useInline) {
            echo ' data-inline-year="' . $inlineYear . '"';
        }
        echo '>';
        if ($total > 0 && $multiSeries) {
            echo '<div class="dash-bar-stack">';
            foreach (status_list() as $st) {
                $c = (int) ($pt['counts'][$st] ?? 0);
                if ($c <= 0) {
                    continue;
                }
                $segOnclick = $drill['by_status'][$st] ?? '';
                echo '<div class="dash-bar-seg dash-bar-seg-' . h($st) . '" data-status="' . h($st) . '" style="flex:' . $c . ' 1 12px;background:' . h(dash_chart_color($st)) . '"';
                if ($segOnclick !== '') {
                    echo ' onclick="' . $segOnclick . '" title="' . h(status_th_chip($st) . ' ' . number_format($c)) . '" role="button" tabindex="0"';
                }
                echo '></div>';
            }
            echo '</div>';
        } elseif ($total > 0 && $activeSt) {
            echo '<div class="dash-bar-seg dash-bar-simple dash-bar-seg-' . h($activeSt) . '" style="flex:1;background:' . h(dash_chart_color($activeSt))
               . '" onclick="' . $drill['all'] . '" title="' . h(status_th_chip($activeSt) . ' ' . number_format($total)) . '" role="button" tabindex="0"></div>';
        }
        echo '</div></div>';
        echo '<span class="dash-bar-label">' . h($pt['label']) . '</span>';
        echo '</div>';
    }
    echo '</div></div></div>';

    if ($showHint) {
        $hint = $multiSeries
            ? 'กดสีในแท่ง = เฉพาะสถานะ · กดตัวเลข = รวมทั้งหมด'
            : 'กดแท่งหรือตัวเลขเพื่อดูรายละเอียด';
        if ($inlineDrill) {
            $hint = 'กดสีในแท่ง = เฉพาะสถานะ · กดตัวเลข = ดูรายเดือน';
        }
        echo '<p class="dash-chart-hint muted">' . h($hint) . '</p>';
    }
    echo '</div>';
}

/** @deprecated alias */
function render_dashboard_linechart(array $points, $multiSeries = true, $filterSt = null)
{
    render_dashboard_stacked_chart($points, $multiSeries, $filterSt);
}

// ────────────────────────────────────────────────────────────────────────────────
// MA stacked chart — ภาพรวม MA แยกตามผลลัพธ์ (ปกติ / เปลี่ยน / ซ่อม / เสื่อมสภาพ)
// ────────────────────────────────────────────────────────────────────────────────

/**
 * เงื่อนไข SQL ว่าแถว ma_records เป็นรายการเสื่อมสภาพ (บันทึกผ่าน rent_close retire / bulk retire)
 *
 * @param string $remarkCol ชื่อคอลัมน์ remark เช่น remark หรือ m.remark
 */
function ma_sql_row_is_retire(string $remarkCol = 'remark'): string
{
    return "({$remarkCol} IS NOT NULL AND TRIM({$remarkCol}) LIKE 'เสื่อมสภาพ%')";
}

/**
 * ชุด SUM(CASE...) สำหรับ aggregate กราฟ MA — เสื่อมสภาพไม่นับซ้ำใน ok/replace/repair
 */
function ma_sql_chart_aggregate_select(): string
{
    $ret = ma_sql_row_is_retire('remark');
    return "SUM(CASE WHEN {$ret} THEN 1 ELSE 0 END) c_retire,
     SUM(CASE WHEN NOT {$ret} AND result='repair' THEN 1 ELSE 0 END) c_repair,
     SUM(CASE WHEN NOT {$ret} AND result='replace' THEN 1 ELSE 0 END) c_replace,
     SUM(CASE WHEN NOT {$ret} AND (result='ok' OR result IS NULL) THEN 1 ELSE 0 END) c_ok";
}

/**
 * ตรวจจากหมายเหตุว่าเป็น MA เสื่อมสภาพ (สอดคล้อง ma.php / rent_ensure_retire_remark)
 *
 * @param string|null $remark
 */
function ma_remark_indicates_retire($remark): bool
{
    $rm = trim((string) $remark);
    return $rm !== '' && mb_strpos($rm, 'เสื่อมสภาพ') === 0;
}

/** @return array<string,array{th:string,chip:string,bar:string}> */
function ma_result_palette(): array
{
    return [
        'ok'      => ['th' => 'ปกติ',           'chip' => 'ปกติ',       'bar' => '#6ee7b7'],
        'replace' => ['th' => 'เปลี่ยนอะไหล่',  'chip' => 'เปลี่ยน',     'bar' => '#fde68a'],
        'repair'  => ['th' => 'ต้องซ่อม',       'chip' => 'ซ่อม',        'bar' => '#fca5a5'],
        'retire'  => ['th' => 'เสื่อมสภาพ',     'chip' => 'เสื่อมสภาพ', 'bar' => '#cbd5e1'],
    ];
}

/** @return string[] */
function ma_result_list(): array { return array_keys(ma_result_palette()); }

/** @return string */
function ma_result_th(string $r): string
{
    $p = ma_result_palette();
    return isset($p[$r]) ? $p[$r]['th'] : $r;
}

/** @return string */
function ma_result_bar_color(string $r): string
{
    $p = ma_result_palette();
    return isset($p[$r]) ? $p[$r]['bar'] : '#94a3b8';
}

/** จำนวนรวมจาก counts array */
function ma_result_counts_total(array $counts): int
{
    return (int) array_sum($counts);
}

/** tooltip ข้อความ hover */
function ma_result_tooltip(string $label, array $counts): string
{
    $bits = [];
    foreach (ma_result_list() as $r) {
        $c = (int) ($counts[$r] ?? 0);
        if ($c > 0) { $bits[] = ma_result_th($r) . ' ' . number_format($c); }
    }
    return $label . ($bits ? ': ' . implode(' · ', $bits) : '');
}

/**
 * ตรวจผลการตรวจ MA ที่ใช้ drill จากกราฟได้
 *
 * @param string $result ok|replace|repair|retire
 * @return bool
 */
function ma_result_valid(string $result): bool
{
    return in_array($result, ma_result_list(), true);
}

/**
 * เงื่อนไข SQL กรองผลการตรวจ MA
 *
 * @param string $result all|ok|replace|repair|retire
 * @param string $alias alias ตาราง ma_records
 * @return array{sql:string, types:string, params:array<int,mixed>}
 */
function ma_result_filter_sql(string $result, string $alias = 'm'): array
{
    if ($result === '' || $result === 'all') {
        return ['sql' => '', 'types' => '', 'params' => []];
    }
    if (!ma_result_valid($result)) {
        return ['sql' => ' AND 1=0', 'types' => '', 'params' => []];
    }
    $notRetire = 'NOT ' . ma_sql_row_is_retire($alias . '.remark');
    if ($result === 'retire') {
        return [
            'sql'    => ' AND ' . ma_sql_row_is_retire($alias . '.remark'),
            'types'  => '',
            'params' => [],
        ];
    }
    if ($result === 'ok') {
        return [
            'sql'    => " AND {$notRetire} AND ({$alias}.result='ok' OR {$alias}.result IS NULL)",
            'types'  => '',
            'params' => [],
        ];
    }
    return [
        'sql'    => " AND {$notRetire} AND {$alias}.result=?",
        'types'  => 's',
        'params' => [$result],
    ];
}

/**
 * onclick เปิด modal รายการ MA — กดตัวเลข = ทั้งหมด · กดสี = แยกผลการตรวจ
 *
 * @param string            $titlePrefix
 * @param string            $baseUrl
 * @param array<string,int> $counts
 * @return array{all:string, by_result:array<string,string>}
 */
function ma_drill_onclicks(string $titlePrefix, string $baseUrl, array $counts): array
{
    $all = drill_onclick($titlePrefix, $baseUrl);
    $byResult = [];
    foreach (ma_result_list() as $r) {
        if ((int) ($counts[$r] ?? 0) <= 0) {
            continue;
        }
        $byResult[$r] = drill_onclick(
            $titlePrefix . ' — ' . ma_result_th($r),
            $baseUrl . '&result=' . rawurlencode($r)
        );
    }
    return ['all' => $all, 'by_result' => $byResult];
}

/**
 * จุดข้อมูลกราฟ MA พร้อม drill
 *
 * @param array<string,int> $counts
 * @return array<string,mixed>
 */
function ma_chart_point(string $label, string $title, array $counts, string $titlePrefix, string $baseUrl, int $inlineYear = 0): array
{
    $pt = [
        'label'  => $label,
        'title'  => $title,
        'counts' => $counts,
        'drill'  => ma_drill_onclicks($titlePrefix, $baseUrl, $counts),
    ];
    if ($inlineYear > 0) {
        $pt['inline_year'] = $inlineYear;
    }
    return $pt;
}

/**
 * Trend เดือนนี้ vs เดือนก่อนสำหรับข้อมูล MA
 *
 * @param array<int,array<int,array<string,int>>> $monthCountsByYear [year][month] => counts
 * @return array{now:int,prev:int,diff:int,pct:int|null,now_ym:string,prev_ym:string}|null
 */
function dash_ma_month_trend(array $monthCountsByYear, $today = null)
{
    $today = $today === null ? date('Y-m-d') : $today;
    $ts = strtotime($today);
    if ($ts === false) { return null; }

    $nowY  = (int) date('Y', $ts);
    $nowM  = (int) date('n', $ts);
    $prevY = $nowM === 1 ? $nowY - 1 : $nowY;
    $prevM = $nowM === 1 ? 12 : $nowM - 1;

    if (!isset($monthCountsByYear[$prevY][$prevM])) { return null; }

    $now  = isset($monthCountsByYear[$nowY][$nowM])
          ? ma_result_counts_total($monthCountsByYear[$nowY][$nowM]) : 0;
    $prev = ma_result_counts_total($monthCountsByYear[$prevY][$prevM]);

    return [
        'now'     => $now,
        'prev'    => $prev,
        'diff'    => $now - $prev,
        'pct'     => $prev > 0 ? (int) round(($now - $prev) / $prev * 100) : null,
        'now_ym'  => sprintf('%04d-%02d', $nowY, $nowM),
        'prev_ym' => sprintf('%04d-%02d', $prevY, $prevM),
    ];
}

/**
 * Render stacked bar chart สำหรับข้อมูล MA (ปกติ / เปลี่ยน / ซ่อม)
 *
 * โครงสร้าง HTML/CSS ใช้คลาสชุดเดียวกับกราฟผลิต (dash-stacked-chart, dash-bar-*)
 * ต่างกันที่สีแท่งและ tooltip ใช้ ma_result_palette แทน status_palette
 *
 * @param array $points [{label, title, counts, drill?, inline_year?:int}]
 * @param array $opts   compact, inline_drill, root_class, hide_legend, show_hint
 */
function render_ma_stacked_chart(array $points, array $opts = []): void
{
    if (!$points) { echo '<p class="muted">ยังไม่มีข้อมูล MA</p>'; return; }

    $compact     = !empty($opts['compact']);
    $inlineDrill = !empty($opts['inline_drill']);
    $showHint    = array_key_exists('show_hint', $opts) ? (bool) $opts['show_hint'] : !$compact;
    $rootClass   = 'dash-stacked-chart' . ($compact ? ' dash-stacked-chart-compact' : '');
    if (!empty($opts['root_class'])) { $rootClass .= ' ' . h((string) $opts['root_class']); }

    $maxTotal = 0;
    foreach ($points as $pt) {
        $maxTotal = max($maxTotal, ma_result_counts_total($pt['counts']));
    }
    $yTicks = dash_chart_y_ticks($maxTotal);
    $yMax   = $yTicks[0];

    $hideLegend = !empty($opts['hide_legend']);
    echo '<div class="' . $rootClass . '">';

    // legend
    if (!$hideLegend) {
        echo '<div class="dash-chart-top"><div class="dash-status-legend dash-status-legend-top">';
        foreach (ma_result_palette() as $r => $info) {
            echo '<span class="dash-status-legend-item">'
               . '<i class="dash-status-swatch" style="background:' . h($info['bar']) . '"></i>'
               . h($info['chip']) . '</span>';
        }
        echo '</div></div>';
    }

    // chart body
    echo '<div class="dash-chart-body">';
    echo '<div class="dash-chart-yaxis" aria-hidden="true">';
    foreach ($yTicks as $tick) { echo '<span>' . number_format($tick) . '</span>'; }
    echo '</div>';

    echo '<div class="dash-chart-plot">';
    echo '<div class="dash-chart-grid" aria-hidden="true">';
    foreach ($yTicks as $_) { echo '<div class="dash-chart-grid-line"></div>'; }
    echo '</div>';

    echo '<div class="dash-chart-bars" style="--dash-bar-count:' . count($points) . '">';
    foreach ($points as $pt) {
        $total   = ma_result_counts_total($pt['counts']);
        $tooltip = ma_result_tooltip($pt['title'], $pt['counts']);
        $barH    = $yMax > 0 ? max(0, min(100, ($total / $yMax) * 100)) : 0;
        $inlineYear = (int) ($pt['inline_year'] ?? 0);
        $useInline  = $inlineDrill && $inlineYear > 0;
        $drill      = is_array($pt['drill'] ?? null) ? $pt['drill'] : ['all' => '', 'by_result' => []];
        $drillAll   = (string) ($drill['all'] ?? '');
        $drillBy    = is_array($drill['by_result'] ?? null) ? $drill['by_result'] : [];

        echo '<div class="dash-bar-col" title="' . h($tooltip) . '"';
        if ($useInline) { echo ' data-ma-inline-year="' . $inlineYear . '"'; }
        echo '>';

        echo '<div class="dash-bar-slot">';
        if ($total > 0) {
            if ($useInline) {
                echo '<button type="button" class="dash-bar-total dash-bar-total-inline" data-ma-inline-year="'
                   . $inlineYear . '" title="ดูรายเดือนปี ' . h($pt['label']) . '">'
                   . number_format($total) . '</button>';
            } elseif ($drillAll !== '') {
                echo '<button type="button" class="dash-bar-total" onclick="' . $drillAll
                   . '" title="ดูรายการทั้งหมด">' . number_format($total) . '</button>';
            } else {
                echo '<span class="dash-bar-total">' . number_format($total) . '</span>';
            }
        } else {
            $future = !empty($pt['is_future']);
            echo '<span class="dash-bar-total dash-bar-total-empty' . ($future ? ' is-future' : '') . '">'
               . ($future ? '–' : '0') . '</span>';
        }

        echo '<div class="dash-bar-area" style="height:' . round($barH, 2) . '%">';
        if ($total > 0) {
            echo '<div class="dash-bar-stack">';
            foreach (ma_result_list() as $r) {
                $c = (int) ($pt['counts'][$r] ?? 0);
                if ($c <= 0) { continue; }
                $segOn = (string) ($drillBy[$r] ?? '');
                echo '<div class="dash-bar-seg dash-bar-seg-ma-' . h($r) . '" data-ma-result="' . h($r)
                   . '" style="flex:' . $c . ' 1 0;background:' . h(ma_result_bar_color($r)) . '" title="'
                   . h(ma_result_th($r) . ' ' . number_format($c)) . '"';
                if ($segOn !== '') {
                    echo ' onclick="' . $segOn . '" role="button" tabindex="0"';
                }
                echo '></div>';
            }
            echo '</div>';
        }
        echo '</div></div>';
        echo '<span class="dash-bar-label">' . h($pt['label']) . '</span>';
        echo '</div>';
    }
    echo '</div></div></div>';
    if ($showHint) {
        $hint = $inlineDrill
            ? 'กดสีในแท่ง = เฉพาะผลการตรวจ · กดตัวเลข = ดูรายเดือน'
            : 'กดสีในแท่ง = เฉพาะผลการตรวจ · กดตัวเลข = รายการทั้งหมด';
        echo '<p class="dash-chart-hint muted">' . h($hint) . '</p>';
    }
    echo '</div>';
}
