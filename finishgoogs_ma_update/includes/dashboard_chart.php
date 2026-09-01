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
 * ชื่อสั้นสำหรับ legend / เมนูกรaph
 *
 * @param string $st
 * @return string
 */
function status_th_chip($st)
{
    $m = ['new' => 'ใหม่', 'rental' => 'เช่า', 'spare' => 'สำรอง', 'sold' => 'ขายแล้ว'];
    return isset($m[$st]) ? $m[$st] : $st;
}

/**
 * สีเส้นกรaph ตาม status
 *
 * @param string $st
 * @return string
 */
function dash_chart_color($st)
{
    $m = ['new' => '#6ee7b7', 'rental' => '#93c5fd', 'sold' => '#fdba74', 'spare' => '#fde68a'];
    return isset($m[$st]) ? $m[$st] : '#cbd5e1';
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
    echo '<div class="dash-chart-top">';
    if (!$compact) {
        echo '<div class="dash-chart-head">';
        echo '<div class="dash-chart-total">' . number_format($grandTotal) . ' <span class="dash-chart-unit">เครื่อง</span>';
        if ($periodLabel !== '') {
            echo ' <span class="dash-chart-period muted">· ' . h($periodLabel) . '</span>';
        }
        echo '</div></div>';
    }

    render_status_legend($multiSeries ? null : $activeSt);
    echo '</div>';

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
            echo '<span class="dash-bar-total dash-bar-total-empty">·</span>';
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
                echo '<div class="dash-bar-seg dash-bar-seg-' . h($st) . '" style="flex:' . $c . ' 1 12px;background:' . h(dash_chart_color($st)) . '"';
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
