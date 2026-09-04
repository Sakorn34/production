<?php
/**
 * includes/work_summary.php — รวมยอดงานรายคนต่อรอบเดือน จาก 3 ระบบ
 * ────────────────────────────────────────────────────────────────────────────────
 * รอบเดือนของที่นี่ไม่ใช่ 1–31 แต่เป็น "21 เดือนก่อน – 20 เดือนนี้"
 *
 * ระบบซ่อม (biton_maintenance) และระบบเช่า (biton_leasing) อ่านอย่างเดียว — SELECT เท่านั้น
 * ต่อไม่ได้ก็คืนเฉพาะส่วน production พร้อม flag ไม่ให้ทั้งหน้าล้ม
 * ────────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/work_people.php';

/** วันตัดรอบ — งานของวันที่ 21 นับเป็นรอบใหม่ */
const WORK_SUMMARY_CUTOFF_DAY = 20;

/**
 * ช่วงวันของรอบที่ครอบวันที่ที่ให้มา
 *
 * 20 ก.ย. → 21 ส.ค.–20 ก.ย. (รอบที่กำลังจะปิด) · 21 ก.ย. → 21 ก.ย.–20 ต.ค. (รอบใหม่)
 *
 * @param  string|null $today Y-m-d (null = วันนี้)
 * @return array{from:string,to:string,key:string,label:string}
 */
function work_summary_cycle(?string $today = null): array
{
    $ts = $today !== null && $today !== '' ? strtotime($today) : time();
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $d = (int) date('j', $ts);

    // ถึงวันที่ 20 ยังอยู่ในรอบที่เริ่มเดือนก่อน — เลยวันที่ 20 ไปแล้วคือรอบถัดไป
    if ($d <= WORK_SUMMARY_CUTOFF_DAY) {
        $endY = $y;
        $endM = $m;
    } else {
        $endTs = mktime(0, 0, 0, $m + 1, 1, $y);
        $endY = (int) date('Y', $endTs);
        $endM = (int) date('n', $endTs);
    }
    $startTs = mktime(0, 0, 0, $endM - 1, 21, $endY); // mktime หมุนปีให้เองตอนเดือนเป็น 0
    $from = date('Y-m-d', $startTs);
    $to = sprintf('%04d-%02d-%02d', $endY, $endM, WORK_SUMMARY_CUTOFF_DAY);

    return [
        'from'  => $from,
        'to'    => $to,
        'key'   => sprintf('%04d-%02d', $endY, $endM),
        'label' => work_summary_cycle_label($from, $to),
    ];
}

/**
 * เลื่อนรอบไปข้างหน้า/ถอยหลัง
 *
 * @param  array<string,mixed> $cycle
 * @param  int                 $step  -1 = รอบก่อน, +1 = รอบถัดไป
 * @return array{from:string,to:string,key:string,label:string}
 */
function work_summary_cycle_shift(array $cycle, int $step): array
{
    $ts = strtotime((string) $cycle['to']);
    // ขยับไปกลางเดือนถัดไป/ก่อนหน้า แล้วให้ work_summary_cycle() คิดขอบรอบให้เอง
    return work_summary_cycle(date('Y-m-15', strtotime(($step >= 0 ? '+' : '-') . abs($step) . ' month', $ts)));
}

/**
 * ป้ายชื่อรอบเป็นวันที่ไทย
 *
 * @param  string $from
 * @param  string $to
 * @return string
 */
function work_summary_cycle_label(string $from, string $to): string
{
    return dthai($from) . ' – ' . dthai($to);
}

/**
 * แหล่งข้อมูลฝั่ง production
 *
 * where: เงื่อนไขเสริม — stock_movements ต้องตัดแถวที่ cron เขียน (11,022 แถวจาก 13,142)
 * ไม่งั้นสรุปงานคนจะบวมด้วยรายการที่ระบบ sync สถานะเขียนเองใต้ชื่อคน
 *
 * @return array<int,array<string,string>>
 */
function work_summary_sources_production(): array
{
    $syncPrefix = function_exists('asset_status_sync_log_prefix')
        ? asset_status_sync_log_prefix() : 'Sync สถานะ: ';
    $esc = db()->real_escape_string($syncPrefix);
    return [
        ['key' => 'production', 'label' => 'บันทึกผลิต / QC', 'table' => 'production_records', 'actor' => 'made_by',   'date' => 'recorded_at'],
        ['key' => 'ma',         'label' => 'บันทึก MA',        'table' => 'ma_records',         'actor' => 'done_by',   'date' => 'visited_at'],
        ['key' => 'update',     'label' => 'อัปเดต FW/HW',     'table' => 'update_logs',        'actor' => 'made_by',   'date' => 'updated_at'],
        ['key' => 'part_out',   'label' => 'เบิกอะไหล่',       'table' => 'part_movements',     'actor' => 'made_by',   'date' => 'moved_at'],
        ['key' => 'stock',      'label' => 'เคลื่อนไหวคลัง',   'table' => 'stock_movements',    'actor' => 'made_by',   'date' => 'moved_at',
         'where' => "reason NOT LIKE '{$esc}%'"],
    ];
}

/**
 * แหล่งข้อมูลฝั่งระบบซ่อม — งานซ่อม 1 ใบมีคนเกี่ยวข้องได้ 4 บทบาท นับแยกกัน
 *
 * @return array<int,array<string,string>>
 */
function work_summary_sources_maintenance(): array
{
    return [
        ['key' => 'rp_receive', 'label' => 'รับเครื่องเข้าซ่อม', 'table' => 'transac_repair', 'actor' => 'trp_user_recive_ma', 'date' => 'trp_receive_date'],
        ['key' => 'rp_rate',    'label' => 'ประเมิน/เสนอราคา',   'table' => 'transac_repair', 'actor' => 'trp_user_rate',      'date' => 'trp_rate_date'],
        ['key' => 'rp_fix',     'label' => 'ซ่อมเสร็จ',           'table' => 'transac_repair', 'actor' => 'trp_user_ma',        'date' => 'trp_success_date'],
        ['key' => 'rp_send',    'label' => 'ส่งคืนลูกค้า',        'table' => 'transac_repair', 'actor' => 'trp_sendby',         'date' => 'trp_send_date'],
    ];
}

/**
 * แหล่งข้อมูลฝั่งระบบเช่า
 *
 * ไม่รวม tbl_rent_product เพราะไม่มีคอลัมน์ "บันทึกเมื่อไหร่" ที่เชื่อถือได้
 * (มีแต่ p_date_received ซึ่งคือวันรับคืน คนละความหมายกับวันที่ลงบันทึก)
 *
 * @return array<int,array<string,string>>
 */
function work_summary_sources_leasing(): array
{
    return [
        ['key' => 'rent_reg', 'label' => 'ลงทะเบียนเครื่องเช่า', 'table' => 'tbl_product',    'actor' => 'pro_user_add', 'date' => 'pro_date'],
        ['key' => 'rent_ma',  'label' => 'MA เครื่องเช่า',        'table' => 'tbl_product_ma', 'actor' => 'ma_user_add',  'date' => 'ma_date'],
    ];
}

/** ทุกหมวดพร้อมป้าย เรียงตามที่จะโชว์ */
function work_summary_categories(): array
{
    $out = [];
    foreach (array_merge(
        work_summary_sources_production(),
        work_summary_sources_maintenance(),
        work_summary_sources_leasing()
    ) as $s) {
        $out[$s['key']] = $s['label'];
    }
    return $out;
}

/**
 * GROUP BY ชื่อคนบนฐานภายนอก (ไม่กรองวันที่) — ใช้ตอน scan ทะเบียนชื่อ
 *
 * @param  \mysqli|null $conn
 * @param  string       $table
 * @param  string       $actor
 * @return array<string,int>
 */
function work_summary_external_group($conn, string $table, string $actor): array
{
    if (!$conn) {
        return [];
    }
    $out = [];
    try {
        $res = @$conn->query(
            "SELECT `$actor` v, COUNT(*) c FROM `$table`
             WHERE `$actor` IS NOT NULL AND TRIM(`$actor`) <> '' GROUP BY v"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[(string) $row['v']] = (int) $row['c'];
            }
        }
    } catch (\Throwable $e) {
        error_log('[work_summary_external_group] ' . $table . ': ' . $e->getMessage());
    }
    return $out;
}

/**
 * รวมยอดงานรายคนของรอบหนึ่ง
 *
 * ยิง query ทีเดียวต่อแหล่ง (11 แหล่ง) แล้ว group ใน PHP — ไม่ยิงต่อคน เพราะ 30 คน × 11
 * แหล่งคือ 330 query ซึ่งช้าเกินไปสำหรับหน้าเว็บ
 *
 * @param  string $from Y-m-d
 * @param  string $to   Y-m-d (รวมวันนี้ด้วย)
 * @return array{rows:array<int,array<string,mixed>>,totals:array<string,int>,unmatched:array<string,int>,errors:array<int,string>}
 */
function work_summary_for_cycle(string $from, string $to): array
{
    work_people_ensure_schema();
    $aliasMap = work_people_alias_map();
    $people = [];
    foreach (work_people_all() as $p) {
        $people[(int) $p['id']] = [
            'id'           => (int) $p['id'],
            'name'         => (string) $p['display_name'],
            'line_user_id' => (string) ($p['line_user_id'] ?? ''),
            'notify'       => (int) ($p['notify_enabled'] ?? 1) === 1,
            'active'       => (int) ($p['is_active'] ?? 1) === 1,
            'counts'       => [],
            'total'        => 0,
        ];
    }

    $unmatched = [];
    $totals = [];

    $bump = function (string $rawActor, string $catKey, int $n)
        use (&$people, &$unmatched, &$totals, $aliasMap) {
        foreach (work_people_split($rawActor) as $name) {
            $key = work_people_norm($name);
            if ($key === '') {
                continue;
            }
            if (!isset($aliasMap[$key])) {
                $unmatched[$name] = ($unmatched[$name] ?? 0) + $n;
                continue;
            }
            $pid = $aliasMap[$key];
            if (!isset($people[$pid])) {
                continue;
            }
            $people[$pid]['counts'][$catKey] = ($people[$pid]['counts'][$catKey] ?? 0) + $n;
            $people[$pid]['total'] += $n;
            $totals[$catKey] = ($totals[$catKey] ?? 0) + $n;
        }
    };

    $collected = work_summary_collect($from, $to, false);
    $errors = $collected['errors'];
    foreach ($collected['rows'] as $r) {
        $bump($r['actor'], $r['cat'], $r['n']);
    }

    $rows = array_values(array_filter($people, function ($p) {
        return $p['total'] > 0;
    }));
    usort($rows, function ($a, $b) {
        return $b['total'] <=> $a['total'] ?: strcasecmp($a['name'], $b['name']);
    });
    arsort($unmatched);

    return ['rows' => $rows, 'totals' => $totals, 'unmatched' => $unmatched, 'errors' => $errors];
}

/**
 * ยิง query ทุกแหล่งของช่วงวันหนึ่ง แล้วคืนเป็นแถวดิบ (ยังไม่จับคู่กับทะเบียนคน)
 *
 * แยกออกมาเพราะทั้งสรุปรายรอบและสรุปรายวันใช้ query ชุดเดียวกัน ต่างแค่ GROUP BY
 * วันที่หรือไม่ — เขียนซ้ำสองที่แล้วเงื่อนไข (เช่น การตัดแถว sync) จะหลุดกันเอง
 *
 * @param  string $from  Y-m-d
 * @param  string $to    Y-m-d (รวมวันนี้)
 * @param  bool   $byDay true = แยกรายวันด้วย
 * @return array{rows:array<int,array{cat:string,actor:string,day:string,n:int}>,errors:array<int,string>}
 */
function work_summary_collect(string $from, string $to, bool $byDay): array
{
    $rows = [];
    $errors = [];

    // ── production: วันที่เป็น datetime จริง เทียบด้วย >= / < วันถัดไป ──────────
    foreach (work_summary_sources_production() as $src) {
        $sel = $byDay ? ", DATE(`{$src['date']}`) d" : '';
        $sql = "SELECT `{$src['actor']}` v{$sel}, COUNT(*) c FROM `{$src['table']}`
                WHERE `{$src['date']}` >= ? AND `{$src['date']}` < DATE_ADD(?, INTERVAL 1 DAY)
                  AND `{$src['actor']}` IS NOT NULL AND TRIM(`{$src['actor']}`) <> ''"
             . (isset($src['where']) ? ' AND ' . $src['where'] : '')
             . ' GROUP BY v' . ($byDay ? ', d' : '');
        try {
            $res = qr($sql, 'ss', [$from, $to]);
            while ($row = $res->fetch_assoc()) {
                $rows[] = ['cat' => $src['key'], 'actor' => (string) $row['v'],
                           'day' => $byDay ? (string) $row['d'] : '', 'n' => (int) $row['c']];
            }
        } catch (\Throwable $e) {
            $errors[] = $src['table'] . ': ' . $e->getMessage();
        }
    }

    // ── ระบบซ่อม/เช่า: วันที่บางคอลัมน์เป็น varchar แต่เป็น ISO (YYYY-MM-DD)
    //    เทียบสตริงตรง ๆ ได้ผลถูกเพราะ ISO เรียงตามพจนานุกรมเท่ากับเรียงตามเวลา
    //    ค่าที่ไม่ใช่รูปแบบนี้ (ว่าง, '0000-00-00', '-') จะตกนอกช่วงไปเอง
    //    LEFT(...,10) ตัดส่วนเวลาทิ้ง ใช้ได้ทั้งคอลัมน์ varchar และ datetime
    $ext = [
        [dbMaintenance(), work_summary_sources_maintenance(), 'ระบบซ่อม'],
        [dbLeasing(),     work_summary_sources_leasing(),     'ระบบเช่า'],
    ];
    foreach ($ext as $spec) {
        list($conn, $sources, $label) = $spec;
        if (!$conn) {
            $errors[] = $label . ': เชื่อมต่อไม่ได้';
            continue;
        }
        foreach ($sources as $src) {
            $sel = $byDay ? ", LEFT(`{$src['date']}`, 10) d" : '';
            $sql = "SELECT `{$src['actor']}` v{$sel}, COUNT(*) c FROM `{$src['table']}`
                    WHERE `{$src['date']}` >= ? AND `{$src['date']}` <= ?
                      AND `{$src['actor']}` IS NOT NULL AND TRIM(`{$src['actor']}`) <> ''
                    GROUP BY v" . ($byDay ? ', d' : '');
            try {
                $st = $conn->prepare($sql);
                if (!$st) {
                    $errors[] = $label . ' ' . $src['table'] . ': prepare ไม่ผ่าน';
                    continue;
                }
                $st->bind_param('ss', $from, $to);
                $st->execute();
                $res = $st->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rows[] = ['cat' => $src['key'], 'actor' => (string) $row['v'],
                               'day' => $byDay ? (string) $row['d'] : '', 'n' => (int) $row['c']];
                }
                $st->close();
            } catch (\Throwable $e) {
                $errors[] = $label . ' ' . $src['table'] . ': ' . $e->getMessage();
            }
        }
    }

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * ป้ายวันแบบสั้นภาษาไทย — "พฤ 21 ส.ค."
 *
 * @param  string $ymd Y-m-d
 * @return string
 */
function work_summary_day_label(string $ymd): string
{
    static $dow = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
    $ts = strtotime($ymd);
    if ($ts === false) {
        return $ymd;
    }
    return $dow[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ' ' . thai_month_short(date('Y-m', $ts));
}

/**
 * งานของคนหนึ่ง แยกรายวันตลอดรอบ — ใช้ทั้งใน Flex และหน้ารายละเอียดรายคน
 *
 * เรียงจากวันแรกของรอบไปวันท้าย และตัดวันที่ไม่มีงานออก (คนไม่ได้ทำงานทุกวัน
 * การโชว์วันว่างเปล่ายาว ๆ ทำให้อ่านยากโดยไม่ได้ข้อมูลเพิ่ม)
 *
 * @param  int    $personId
 * @param  string $from Y-m-d
 * @param  string $to   Y-m-d
 * @return array{days:array<int,array<string,mixed>>,total:int,errors:array<int,string>}
 */
function work_summary_person_daily(int $personId, string $from, string $to): array
{
    work_people_ensure_schema();
    $aliasMap = work_people_alias_map();
    $cats = work_summary_categories();
    $collected = work_summary_collect($from, $to, true);

    $byDay = [];
    $total = 0;
    foreach ($collected['rows'] as $r) {
        $day = substr($r['day'], 0, 10);
        if ($day === '') {
            continue;
        }
        // ชื่อเดียวอาจมีหลายคนคั่นด้วย comma — นับให้ทุกคนในแถวนั้น เหมือนสรุปรายรอบ
        foreach (work_people_split($r['actor']) as $name) {
            $key = work_people_norm($name);
            if ($key === '' || !isset($aliasMap[$key]) || $aliasMap[$key] !== $personId) {
                continue;
            }
            $byDay[$day][$r['cat']] = ($byDay[$day][$r['cat']] ?? 0) + $r['n'];
            $total += $r['n'];
            break; // คนคนเดียวโผล่ซ้ำในแถวเดียวไม่ควรนับสองรอบ
        }
    }
    ksort($byDay);

    $days = [];
    foreach ($byDay as $day => $counts) {
        arsort($counts);
        $items = [];
        $dayTotal = 0;
        foreach ($counts as $k => $n) {
            $items[] = ['key' => $k, 'label' => (string) ($cats[$k] ?? $k), 'count' => (int) $n];
            $dayTotal += (int) $n;
        }
        $days[] = ['date' => $day, 'label' => work_summary_day_label($day), 'total' => $dayTotal, 'items' => $items];
    }

    return ['days' => $days, 'total' => $total, 'errors' => $collected['errors']];
}
