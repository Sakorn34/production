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
        ['key' => 'part_out',   'label' => 'เบิกอะไหล่ (นอกงานผลิต)', 'table' => 'part_movements', 'actor' => 'made_by', 'date' => 'moved_at',
         'where' => work_summary_part_nonproduction_sql('mode')],
        ['key' => 'stock',      'label' => 'เคลื่อนไหวคลัง',   'table' => 'stock_movements',    'actor' => 'made_by',   'date' => 'moved_at',
         'where' => "reason NOT LIKE '{$esc}%'"],
    ];
}

/**
 * เงื่อนไขตัด "เบิกอะไหล่เพื่อผลิต" ออกจากหมวดเบิกอะไหล่
 *
 * เครื่องผลิตใหม่หนึ่งเครื่องถูกนับที่ "บันทึกผลิต / QC" อยู่แล้ว อะไหล่ที่เบิกไปประกอบ
 * เครื่องนั้นเป็นส่วนหนึ่งของงานเดียวกัน ไม่ใช่งานคนละชิ้น การนับซ้ำทำให้ดูเหมือน
 * ทำงานเยอะกว่าจริงและอ่านแล้วสับสนว่าเบิกไปทำอะไร
 *
 * เกณฑ์เดียวกับ part_movement_mode_label() ที่ตีความว่า mode ไหนคือ "ผลิต"
 * (เบิกผลิต 471 แถว · เบิกอัตโนมัติ (ชุดอะไหล่รุ่น) 802 แถว) — แก้ที่นั่นต้องแก้ที่นี่ด้วย
 *
 * @param  string $col ชื่อคอลัมน์ mode (ใส่ prefix ตารางมาได้)
 * @return string
 */
function work_summary_part_nonproduction_sql(string $col): string
{
    return "COALESCE($col, '') NOT LIKE '%ผลิต%'
            AND COALESCE($col, '') NOT LIKE '%BOM%'
            AND COALESCE($col, '') NOT LIKE '%ชุดอะไหล่%'";
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
 * ลำดับความสำคัญของหมวด ตอนงานชิ้นเดียวถูกบันทึกไว้หลายที่
 *
 * เครื่องหนึ่งเครื่องในวันหนึ่งควรนับเป็นงานชิ้นเดียว — ผลิตเครื่องใหม่ 1 เครื่องทิ้งร่องรอย
 * ไว้ทั้งใน production_records, part_movements (อะไหล่ที่เบิกไปประกอบ) และ
 * stock_movements (เข้าคลัง) การนับทั้งสามทำให้ดูเหมือนทำงาน 3 ชิ้น
 * เลขน้อย = สำคัญกว่า = เป็นตัวแทนของงานชิ้นนั้น
 *
 * @return array<string,int>
 */
function work_summary_cat_priority(): array
{
    return [
        'production' => 1,   // ผลิตเครื่องใหม่ — ตัวงานจริง อะไหล่/คลังเป็นผลพลอยได้
        'ma'         => 2,
        'rp_fix'     => 3,
        'update'     => 4,
        'rp_receive' => 5,
        'rp_rate'    => 6,
        'rp_send'    => 7,
        'rent_reg'   => 8,
        'rent_ma'    => 9,
        'part_out'   => 10,  // เบิกอะไหล่ให้เครื่องที่มีงานอื่นในวันเดียวกัน = งานเดียวกัน
        'stock'      => 11,
    ];
}

/**
 * รวมยอดงานรายคนของรอบหนึ่ง
 *
 * ดึงรายแถว (ไม่ใช่ COUNT ในฐาน) เพราะต้องตัดเครื่องที่ซ้ำกันในวันเดียวออกก่อนนับ
 * ซึ่งทำในฐานข้ามสามฐานไม่ได้ — และทำให้ตัวเลขหน้ารวมตรงกับหน้ารายคนเสมอ
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

    $fetched = work_summary_fetch_rows($from, $to, []);
    $bucketed = work_summary_bucket($fetched['rows'], $aliasMap, null);

    $totals = [];
    foreach ($bucketed['people'] as $pid => $byDay) {
        if (!isset($people[$pid])) {
            continue;
        }
        foreach ($byDay as $byCat) {
            foreach ($byCat as $cat => $items) {
                $n = count($items);
                $people[$pid]['counts'][$cat] = ($people[$pid]['counts'][$cat] ?? 0) + $n;
                $people[$pid]['total'] += $n;
                $totals[$cat] = ($totals[$cat] ?? 0) + $n;
            }
        }
    }

    $rows = array_values(array_filter($people, function ($p) {
        return $p['total'] > 0;
    }));
    usort($rows, function ($a, $b) {
        return $b['total'] <=> $a['total'] ?: strcasecmp($a['name'], $b['name']);
    });
    $unmatched = $bucketed['unmatched'];
    arsort($unmatched);

    return ['rows' => $rows, 'totals' => $totals, 'unmatched' => $unmatched, 'errors' => $fetched['errors']];
}

/**
 * ดึงงานรายแถวจากทุกแหล่งในช่วงวันหนึ่ง
 *
 * $aliases ว่าง = เอาทุกคน (หน้ารวม) · ใส่มา = กรองในฐานให้เหลือเฉพาะคนนั้น (หน้ารายคน)
 * ตัวกรองในฐานเป็นแค่ตัวย่อผลลัพธ์ ฝั่ง PHP ยังเช็คชื่อซ้ำอีกชั้นเสมอ
 *
 * @param  string            $from Y-m-d
 * @param  string            $to   Y-m-d
 * @param  array<int,string> $aliases ชื่อที่ normalize แล้ว
 * @return array{rows:array<int,array<string,mixed>>,errors:array<int,string>}
 */
function work_summary_fetch_rows(string $from, string $to, array $aliases): array
{
    $rows = [];
    $errors = [];
    $conns = ['prod' => db(), 'ma' => dbMaintenance(), 'lease' => dbLeasing()];
    $labels = ['ma' => 'ระบบซ่อม', 'lease' => 'ระบบเช่า'];
    $warned = [];

    foreach (work_summary_detail_sources() as $src) {
        $conn = isset($conns[$src['conn']]) ? $conns[$src['conn']] : null;
        if (!$conn) {
            $name = isset($labels[$src['conn']]) ? $labels[$src['conn']] : $src['conn'];
            if (empty($warned[$name])) {
                $errors[] = $name . ': เชื่อมต่อไม่ได้';
                $warned[$name] = true;
            }
            continue;
        }
        $isProd = $src['conn'] === 'prod';
        // production เป็น datetime จริง ส่วนระบบซ่อม/เช่าเก็บวันที่เป็น varchar ISO
        $dayExpr = $isProd ? "DATE({$src['date']})" : "LEFT({$src['date']}, 10)";
        $range = $isProd
            ? "{$src['date']} >= ? AND {$src['date']} < DATE_ADD(?, INTERVAL 1 DAY)"
            : "{$src['date']} >= ? AND {$src['date']} <= ?";
        // ระบบซ่อม/เช่าเป็นคนละฐาน join หา asset_id ตรง ๆ ไม่ได้ — ไว้ไปเทียบจาก SN ทีหลัง
        $aid = isset($src['aid']) ? $src['aid'] : '0';

        $args = [$from, $to];
        $actorSql = '';
        if ($aliases) {
            list($actorSql, $actorArgs) = work_summary_actor_filter($src['actor'], $aliases);
            $actorSql = ' AND ' . $actorSql;
            $args = array_merge($args, $actorArgs);
        }

        $sql = "SELECT $dayExpr d, {$src['actor']} actor, {$src['nm']} nm, {$src['ref']} ref,
                       {$src['ext']} ext, $aid aid
                FROM {$src['from']}
                WHERE $range AND {$src['actor']} IS NOT NULL AND TRIM({$src['actor']}) <> ''"
             . $actorSql
             . (isset($src['where']) ? ' AND ' . $src['where'] : '')
             . ' ORDER BY d, nm';
        try {
            $st = $conn->prepare($sql);
            if (!$st) {
                $errors[] = $src['key'] . ': prepare ไม่ผ่าน';
                continue;
            }
            $st->bind_param(str_repeat('s', count($args)), ...$args);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $day = substr((string) $row['d'], 0, 10);
                if ($day === '' || $day < $from || $day > $to) {
                    continue;
                }
                $nm = trim((string) $row['nm']);
                $rows[] = [
                    'cat'      => $src['key'],
                    'actor'    => (string) $row['actor'],
                    'day'      => $day,
                    'name'     => $nm !== '' ? $nm : '-',
                    'ref'      => trim((string) (isset($row['ref']) ? $row['ref'] : '')),
                    'extra'    => trim((string) (isset($row['ext']) ? $row['ext'] : '')),
                    'asset_id' => (int) (isset($row['aid']) ? $row['aid'] : 0),
                ];
            }
            $st->close();
        } catch (\Throwable $e) {
            $errors[] = $src['key'] . ': ' . $e->getMessage();
        }
    }

    work_summary_fill_asset_ids($rows);

    return ['rows' => $rows, 'errors' => $errors];
}

/**
 * จัดแถวเข้าคน > วัน > หมวด พร้อมตัดเครื่องที่ซ้ำกันในวันเดียวออก
 *
 * เครื่องเดียวกันในวันเดียวกันเหลือรายการเดียว โดยเก็บหมวดที่สำคัญที่สุดไว้
 * (ดู work_summary_cat_priority) แถวที่ไม่มีเลขเครื่องเลยถือเป็นคนละงานเสมอ
 *
 * @param  array<int,array<string,mixed>> $rows
 * @param  array<string,int>              $aliasMap
 * @param  int|null                       $onlyPid เอาเฉพาะคนนี้
 * @return array{people:array<int,array<string,array<string,array<int,array<string,mixed>>>>>,unmatched:array<string,int>}
 */
function work_summary_bucket(array $rows, array $aliasMap, ?int $onlyPid): array
{
    $prio = work_summary_cat_priority();
    $people = [];
    $unmatched = [];
    $seen = [];   // [pid][day][เลขเครื่อง] = ['cat'=>..,'prio'=>..]

    foreach ($rows as $r) {
        // เลขเครื่องใช้ asset_id ก่อน (แน่นอนกว่า) ไม่มีค่อยใช้ SN ที่เขียนไว้
        $keyRef = $r['asset_id'] > 0 ? 'a' . $r['asset_id'] : ($r['ref'] !== '' ? 's' . $r['ref'] : '');
        $p = isset($prio[$r['cat']]) ? $prio[$r['cat']] : 99;

        foreach (work_people_split($r['actor']) as $rawName) {
            $key = work_people_norm($rawName);
            if ($key === '') {
                continue;
            }
            if (!isset($aliasMap[$key])) {
                $unmatched[$rawName] = ($unmatched[$rawName] ?? 0) + 1;
                continue;
            }
            $pid = (int) $aliasMap[$key];
            if ($onlyPid !== null && $pid !== $onlyPid) {
                continue;
            }
            $day = $r['day'];

            if ($keyRef !== '') {
                if (isset($seen[$pid][$day][$keyRef])) {
                    $old = $seen[$pid][$day][$keyRef];
                    if ($p >= $old['prio']) {
                        continue;   // มีตัวแทนที่สำคัญกว่าอยู่แล้ว
                    }
                    // เจอหมวดที่สำคัญกว่า — ถอนตัวเก่าออกแล้วใส่ตัวใหม่แทน
                    unset($people[$pid][$day][$old['cat']][$old['idx']]);
                    if (!$people[$pid][$day][$old['cat']]) {
                        unset($people[$pid][$day][$old['cat']]);
                    }
                }
                $seen[$pid][$day][$keyRef] = ['cat' => $r['cat'], 'prio' => $p, 'idx' => null];
            }

            $item = ['name' => $r['name'], 'ref' => $r['ref'],
                     'extra' => $r['extra'], 'asset_id' => $r['asset_id']];
            $people[$pid][$day][$r['cat']][] = $item;
            if ($keyRef !== '') {
                end($people[$pid][$day][$r['cat']]);
                $seen[$pid][$day][$keyRef]['idx'] = key($people[$pid][$day][$r['cat']]);
            }
        }
    }

    return ['people' => $people, 'unmatched' => $unmatched];
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
 * แหล่งข้อมูลแบบ "รายละเอียด" — ดึงของจริงว่าทำอะไร ไม่ใช่แค่นับจำนวน
 *
 * nm  = ของชิ้นนั้นคืออะไร (รุ่นสินค้า / ชื่ออะไหล่ / ชนิดการอัปเดต) ใช้จัดกลุ่มใน Flex
 * ref = ตัวระบุชิ้นงาน (รหัสเครื่อง / SN) ใช้แสดงในหน้าเว็บ
 * ext = ข้อมูลประกอบสั้น ๆ (เหตุผล / สถานะ / จำนวน)
 *
 * แยกจาก work_summary_sources_*() เพราะอันนั้น GROUP BY ในฐานเพื่อความเร็ว
 * ส่วนอันนี้ดึงรายแถวของ "คนเดียว" จึงคุ้มที่จะ join หาชื่อรุ่น/ชื่ออะไหล่มาด้วย
 *
 * @return array<int,array<string,string>>
 */
function work_summary_detail_sources(): array
{
    $syncPrefix = function_exists('asset_status_sync_log_prefix')
        ? asset_status_sync_log_prefix() : 'Sync สถานะ: ';
    $esc = db()->real_escape_string($syncPrefix);
    $asset = 'LEFT JOIN assets a ON a.id = t.asset_id LEFT JOIN products p ON p.id = a.product_id';
    $ref   = "COALESCE(NULLIF(a.asset_code,''), NULLIF(a.factory_serial,''), '')";
    $model = "COALESCE(NULLIF(p.name,''), 'ไม่ระบุรุ่น')";

    return [
        ['key' => 'production', 'conn' => 'prod', 'date' => 't.recorded_at', 'actor' => 't.made_by',
         'from' => "production_records t $asset", 'nm' => $model, 'ref' => $ref, 'aid' => 't.asset_id',
         'ext' => "CONCAT_WS(' · ', NULLIF(t.lot_label,''), NULLIF(t.fw_version,''))"],

        ['key' => 'ma', 'conn' => 'prod', 'date' => 't.visited_at', 'actor' => 't.done_by',
         'from' => "ma_records t $asset", 'nm' => $model, 'ref' => $ref, 'aid' => 't.asset_id',
         'ext' => "CONCAT_WS(' · ', CONCAT('รอบ ', t.ma_round), NULLIF(t.result,''))"],

        ['key' => 'update', 'conn' => 'prod', 'date' => 't.updated_at', 'actor' => 't.made_by',
         'from' => "update_logs t $asset",
         'nm'  => "COALESCE(NULLIF(t.component_name,''), NULLIF(t.update_type,''), 'อัปเดต')",
         'ref' => $ref, 'aid' => 't.asset_id',
         'ext' => "CONCAT_WS(' → ', NULLIF(t.old_value,''), NULLIF(t.new_value,''))"],

        ['key' => 'part_out', 'conn' => 'prod', 'date' => 't.moved_at', 'actor' => 't.made_by',
         'from' => 'part_movements t LEFT JOIN parts pr ON pr.id = t.part_id
                    LEFT JOIN assets a ON a.id = t.ref_asset_id',
         'nm'  => "COALESCE(NULLIF(pr.name,''), 'ไม่ระบุอะไหล่')",
         'ref' => "COALESCE(NULLIF(a.asset_code,''), NULLIF(a.factory_serial,''), '')",
         'aid' => 't.ref_asset_id',
         'ext' => "CONCAT_WS(' · ', NULLIF(t.mode,''), CONCAT(FORMAT(t.qty, 0), ' ', COALESCE(pr.unit,'')))",
         'where' => work_summary_part_nonproduction_sql('t.mode')],

        ['key' => 'stock', 'conn' => 'prod', 'date' => 't.moved_at', 'actor' => 't.made_by',
         'from' => "stock_movements t $asset", 'nm' => $model, 'ref' => $ref, 'aid' => 't.asset_id',
         'ext' => "NULLIF(t.reason,'')", 'where' => "t.reason NOT LIKE '{$esc}%'"],

        ['key' => 'rp_receive', 'conn' => 'ma', 'date' => 't.trp_receive_date', 'actor' => 't.trp_user_recive_ma',
         'from' => 'transac_repair t', 'nm' => "COALESCE(NULLIF(t.trp_product,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.trp_sn,'')", 'ext' => "NULLIF(t.trp_repair_inform,'')"],

        ['key' => 'rp_rate', 'conn' => 'ma', 'date' => 't.trp_rate_date', 'actor' => 't.trp_user_rate',
         'from' => 'transac_repair t', 'nm' => "COALESCE(NULLIF(t.trp_product,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.trp_sn,'')", 'ext' => "NULLIF(t.trp_qv_number,'')"],

        ['key' => 'rp_fix', 'conn' => 'ma', 'date' => 't.trp_success_date', 'actor' => 't.trp_user_ma',
         'from' => 'transac_repair t', 'nm' => "COALESCE(NULLIF(t.trp_product,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.trp_sn,'')", 'ext' => "NULLIF(t.trp_repair_no1,'')"],

        ['key' => 'rp_send', 'conn' => 'ma', 'date' => 't.trp_send_date', 'actor' => 't.trp_sendby',
         'from' => 'transac_repair t', 'nm' => "COALESCE(NULLIF(t.trp_product,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.trp_sn,'')", 'ext' => "NULLIF(t.trp_tracking,'')"],

        ['key' => 'rent_reg', 'conn' => 'lease', 'date' => 't.pro_date', 'actor' => 't.pro_user_add',
         'from' => 'tbl_product t', 'nm' => "COALESCE(NULLIF(t.pro_name,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.pro_sn,'')", 'ext' => "NULLIF(t.pro_status,'')"],

        ['key' => 'rent_ma', 'conn' => 'lease', 'date' => 't.ma_date', 'actor' => 't.ma_user_add',
         'from' => 'tbl_product_ma t', 'nm' => "COALESCE(NULLIF(t.ma_product,''), 'ไม่ระบุรุ่น')",
         'ref' => "COALESCE(t.ma_sn,'')", 'ext' => "NULLIF(t.ma_status,'')"],
    ];
}

/**
 * เงื่อนไข SQL คัดเฉพาะแถวของคนคนหนึ่ง
 *
 * ช่องชื่อคนเก็บได้หลายคนคั่นด้วย , ; / จึงห่อด้วย comma แล้วเทียบทั้งก้อน
 * เป็นแค่ตัวกรองหยาบให้ฐานส่งข้อมูลมาน้อยลง — ฝั่ง PHP ยังเช็คด้วยกติกาเดิมซ้ำอีกที
 * ถ้ากรองหลวมไปก็ไม่ทำให้นับเกิน
 *
 * @param  string             $actorExpr คอลัมน์ชื่อคน (มี prefix ตารางแล้ว)
 * @param  array<int,string>  $aliases   ชื่อที่ normalize แล้วของคนนี้
 * @return array{0:string,1:array<int,string>} [SQL, ค่าที่ต้อง bind]
 */
function work_summary_actor_filter(string $actorExpr, array $aliases): array
{
    if (!$aliases) {
        return ['0 = 1', []];
    }
    $norm = "CONCAT(',', REPLACE(REPLACE(REPLACE($actorExpr, ' ', ''), ';', ','), '/', ','), ',')";
    $parts = [];
    $args = [];
    foreach ($aliases as $a) {
        $parts[] = "$norm LIKE ?";
        $args[] = '%,' . $a . ',%';
    }
    return ['(' . implode(' OR ', $parts) . ')', $args];
}


/**
 * งานของคนหนึ่งแบบละเอียด — รายวัน > หัวข้อ > ของจริงที่ทำ
 *
 * ใช้ทางเดินเดียวกับสรุปหน้ารวม (fetch_rows + bucket) ตัวเลขจึงตรงกันเสมอ
 *
 * @param  int    $personId
 * @param  string $from Y-m-d
 * @param  string $to   Y-m-d
 * @return array{days:array<int,array<string,mixed>>,total:int,errors:array<int,string>}
 */
function work_summary_person_items(int $personId, string $from, string $to): array
{
    work_people_ensure_schema();
    $aliasMap = work_people_alias_map();
    $aliases = [];
    foreach ($aliasMap as $alias => $pid) {
        if ((int) $pid === $personId) {
            $aliases[] = (string) $alias;
        }
    }
    // ไม่มี alias = ไม่มีชื่อไหนในข้อมูลงานเป็นของคนนี้ — ต้องคืนว่าง ไม่ใช่ดึงมาทั้งระบบ
    if (!$aliases) {
        return ['days' => [], 'total' => 0, 'errors' => []];
    }

    $cats = work_summary_categories();
    $fetched = work_summary_fetch_rows($from, $to, $aliases);
    $bucketed = work_summary_bucket($fetched['rows'], $aliasMap, $personId);
    $byDay = isset($bucketed['people'][$personId]) ? $bucketed['people'][$personId] : [];
    ksort($byDay);

    $days = [];
    $total = 0;
    foreach ($byDay as $day => $byCat) {
        // หัวข้อที่ทำเยอะสุดขึ้นก่อน — คนอ่านสนใจงานหลักของวันนั้น
        uasort($byCat, function ($a, $b) { return count($b) <=> count($a); });
        $catRows = [];
        $dayTotal = 0;
        foreach ($byCat as $catKey => $items) {
            $items = array_values($items);
            $groups = [];
            foreach ($items as $it) {
                $groups[$it['name']] = (isset($groups[$it['name']]) ? $groups[$it['name']] : 0) + 1;
            }
            arsort($groups);
            $g = [];
            foreach ($groups as $nm => $n) {
                $g[] = ['name' => (string) $nm, 'count' => (int) $n];
            }
            $catRows[] = [
                'key'    => (string) $catKey,
                'label'  => (string) (isset($cats[$catKey]) ? $cats[$catKey] : $catKey),
                'count'  => count($items),
                'groups' => $g,
                'items'  => $items,
            ];
            $dayTotal += count($items);
        }
        $days[] = ['date' => $day, 'label' => work_summary_day_label($day),
                   'total' => $dayTotal, 'cats' => $catRows];
        $total += $dayTotal;
    }

    return ['days' => $days, 'total' => $total, 'errors' => $fetched['errors']];
}

/**
 * เติม asset_id ให้แถวที่มาจากระบบซ่อม/เช่า โดยเทียบ SN กับทะเบียนเครื่องของเรา
 *
 * สองระบบนั้นอยู่คนละฐาน join ตรง ๆ ไม่ได้ แต่เครื่องส่วนใหญ่เป็นเครื่องเดียวกับที่เรา
 * ผลิต จึงเทียบจาก asset_code / factory_serial ได้ — เทียบทีเดียวทั้งชุด ไม่ยิงรายแถว
 *
 * สำคัญกับการตัดของซ้ำด้วย ไม่ใช่แค่ทำลิงก์: เครื่องเดียวกันที่โผล่ทั้งฝั่งเราและฝั่ง
 * ระบบซ่อมจะจับคู่กันได้ก็ต่อเมื่อรู้ asset_id ตรงกัน
 *
 * @param  array<int,array<string,mixed>> $rows แก้ในตัว
 * @return void
 */
function work_summary_fill_asset_ids(array &$rows): void
{
    $refs = [];
    foreach ($rows as $r) {
        if ((int) $r['asset_id'] <= 0 && $r['ref'] !== '') {
            $refs[$r['ref']] = true;
        }
    }
    if (!$refs) {
        return;
    }
    $map = [];
    try {
        // ยิงเป็นก้อนละ 500 กัน query ยาวเกินขีดจำกัดของ MySQL
        foreach (array_chunk(array_keys($refs), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk) * 2);
            $res = qr(
                "SELECT id, asset_code, factory_serial FROM assets
                 WHERE asset_code IN ($ph) OR factory_serial IN ($ph)",
                $types,
                array_merge($chunk, $chunk)
            );
            while ($row = $res->fetch_assoc()) {
                foreach (['asset_code', 'factory_serial'] as $col) {
                    $v = trim((string) $row[$col]);
                    if ($v !== '' && !isset($map[$v])) {
                        $map[$v] = (int) $row['id'];
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[work_summary_fill_asset_ids] ' . $e->getMessage());
        return;
    }
    if (!$map) {
        return;
    }
    foreach ($rows as $i => $r) {
        if ((int) $r['asset_id'] <= 0 && isset($map[$r['ref']])) {
            $rows[$i]['asset_id'] = $map[$r['ref']];
        }
    }
}

/**
 * ตารางปฏิทินของรอบ — แถวละ 7 ช่อง เริ่มวันอาทิตย์
 *
 * ช่องที่อยู่นอกรอบเป็น null (เว้นว่างไว้) รอบนี้คร่อมสองเดือนจึงใช้ปฏิทินเดือนเดียวไม่ได้
 * หน้าเว็บกับการ์ดในไลน์วางตารางเหมือนกัน คนอ่านจะได้เห็นภาพเดียวกันทั้งสองที่
 *
 * @param  string $from Y-m-d
 * @param  string $to   Y-m-d
 * @return array<int,array<int,?string>>
 */
function work_summary_calendar_weeks(string $from, string $to): array
{
    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
        return [];
    }
    $cur = strtotime('-' . (int) date('w', $fromTs) . ' day', $fromTs);
    $weeks = [];
    $week = [];
    $guard = 0;
    while ($cur <= $toTs && $guard++ < 70) {
        $ymd = date('Y-m-d', $cur);
        $week[] = ($ymd >= $from && $ymd <= $to) ? $ymd : null;
        if (count($week) === 7) {
            $weeks[] = $week;
            $week = [];
        }
        $cur = strtotime('+1 day', $cur);
    }
    if ($week) {
        while (count($week) < 7) {
            $week[] = null;
        }
        $weeks[] = $week;
    }
    return $weeks;
}
