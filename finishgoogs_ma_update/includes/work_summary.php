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
         'ext' => "CONCAT_WS(' · ', NULLIF(t.mode,''), CONCAT(FORMAT(t.qty, 0), ' ', COALESCE(pr.unit,'')))"],

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
 * งานของคนหนึ่งแบบละเอียด — รายวัน > หมวด > ของจริงที่ทำ
 *
 * @param  int    $personId
 * @param  string $from Y-m-d
 * @param  string $to   Y-m-d
 * @return array{days:array<int,array<string,mixed>>,total:int,errors:array<int,string>}
 */
function work_summary_person_items(int $personId, string $from, string $to): array
{
    work_people_ensure_schema();
    $aliases = [];
    foreach (work_people_alias_map() as $alias => $pid) {
        if ((int) $pid === $personId) {
            $aliases[] = (string) $alias;
        }
    }
    $cats = work_summary_categories();
    $errors = [];
    $bucket = [];   // [วัน][หมวด] = ['count'=>n,'groups'=>[ชื่อ=>n],'items'=>[]]
    $total = 0;

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
        list($actorSql, $actorArgs) = work_summary_actor_filter($src['actor'], $aliases);
        $isProd = $src['conn'] === 'prod';
        // production เป็น datetime จริง ส่วนระบบซ่อม/เช่าเก็บวันที่เป็น varchar ISO
        $dayExpr = $isProd ? "DATE({$src['date']})" : "LEFT({$src['date']}, 10)";
        $range = $isProd
            ? "{$src['date']} >= ? AND {$src['date']} < DATE_ADD(?, INTERVAL 1 DAY)"
            : "{$src['date']} >= ? AND {$src['date']} <= ?";

        // ระบบซ่อม/เช่าเป็นคนละฐาน join หา asset_id ตรง ๆ ไม่ได้ — ไว้ไปเทียบจาก SN ทีหลัง
        $aid = isset($src['aid']) ? $src['aid'] : '0';
        $sql = "SELECT $dayExpr d, {$src['actor']} actor, {$src['nm']} nm, {$src['ref']} ref,
                       {$src['ext']} ext, $aid aid
                FROM {$src['from']}
                WHERE $range AND {$src['actor']} IS NOT NULL AND TRIM({$src['actor']}) <> ''
                  AND $actorSql"
             . (isset($src['where']) ? ' AND ' . $src['where'] : '')
             . ' ORDER BY d, nm';
        try {
            $st = $conn->prepare($sql);
            if (!$st) {
                $errors[] = $src['key'] . ': prepare ไม่ผ่าน';
                continue;
            }
            $args = array_merge([$from, $to], $actorArgs);
            $st->bind_param(str_repeat('s', count($args)), ...$args);
            $st->execute();
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                // ยืนยันอีกชั้นด้วยกติกาเดียวกับสรุปรายรอบ — ตัวกรองใน SQL เป็นแค่ตัวย่อผลลัพธ์
                $mine = false;
                foreach (work_people_split((string) $row['actor']) as $n) {
                    $k = work_people_norm($n);
                    if ($k !== '' && in_array($k, $aliases, true)) {
                        $mine = true;
                        break;
                    }
                }
                if (!$mine) {
                    continue;
                }
                $day = substr((string) $row['d'], 0, 10);
                if ($day === '' || $day < $from || $day > $to) {
                    continue;
                }
                $nm = trim((string) $row['nm']);
                if ($nm === '') {
                    $nm = '-';
                }
                $cat = $src['key'];
                if (!isset($bucket[$day][$cat])) {
                    $bucket[$day][$cat] = ['count' => 0, 'groups' => [], 'items' => []];
                }
                $bucket[$day][$cat]['count']++;
                $bucket[$day][$cat]['groups'][$nm] = (isset($bucket[$day][$cat]['groups'][$nm])
                    ? $bucket[$day][$cat]['groups'][$nm] : 0) + 1;
                $bucket[$day][$cat]['items'][] = [
                    'name'     => $nm,
                    'ref'      => trim((string) (isset($row['ref']) ? $row['ref'] : '')),
                    'extra'    => trim((string) (isset($row['ext']) ? $row['ext'] : '')),
                    'asset_id' => (int) (isset($row['aid']) ? $row['aid'] : 0),
                ];
                $total++;
            }
            $st->close();
        } catch (\Throwable $e) {
            $errors[] = $src['key'] . ': ' . $e->getMessage();
        }
    }

    work_summary_fill_asset_ids($bucket);

    ksort($bucket);
    $days = [];
    foreach ($bucket as $day => $byCat) {
        // หมวดที่ทำเยอะสุดขึ้นก่อน — คนอ่านสนใจงานหลักของวันนั้น
        uasort($byCat, function ($a, $b) { return $b['count'] <=> $a['count']; });
        $catRows = [];
        $dayTotal = 0;
        foreach ($byCat as $catKey => $c) {
            arsort($c['groups']);
            $groups = [];
            foreach ($c['groups'] as $nm => $n) {
                $groups[] = ['name' => (string) $nm, 'count' => (int) $n];
            }
            $catRows[] = [
                'key'    => (string) $catKey,
                'label'  => (string) (isset($cats[$catKey]) ? $cats[$catKey] : $catKey),
                'count'  => (int) $c['count'],
                'groups' => $groups,
                'items'  => $c['items'],
            ];
            $dayTotal += (int) $c['count'];
        }
        $days[] = ['date' => $day, 'label' => work_summary_day_label($day),
                   'total' => $dayTotal, 'cats' => $catRows];
    }

    return ['days' => $days, 'total' => $total, 'errors' => $errors];
}

/**
 * เติม asset_id ให้รายการที่มาจากระบบซ่อม/เช่า โดยเทียบ SN กับทะเบียนเครื่องของเรา
 *
 * สองระบบนั้นอยู่คนละฐาน join ตรง ๆ ไม่ได้ แต่เครื่องส่วนใหญ่เป็นเครื่องเดียวกับที่เรา
 * ผลิต จึงเทียบจาก asset_code / factory_serial ได้ — เทียบทีเดียวทั้งชุด ไม่ยิงรายแถว
 * เทียบไม่เจอก็ปล่อยเป็น 0 แล้วหน้าเว็บจะไม่ทำเป็นลิงก์ ดีกว่าพาไปหน้าที่ไม่มีอยู่
 *
 * @param  array<string,array<string,array<string,mixed>>> $bucket แก้ในตัว
 * @return void
 */
function work_summary_fill_asset_ids(array &$bucket): void
{
    $refs = [];
    foreach ($bucket as $byCat) {
        foreach ($byCat as $c) {
            foreach ($c['items'] as $it) {
                if ((int) $it['asset_id'] <= 0 && $it['ref'] !== '') {
                    $refs[$it['ref']] = true;
                }
            }
        }
    }
    if (!$refs) {
        return;
    }
    $map = [];
    try {
        $names = array_keys($refs);
        // ยิงเป็นก้อนละ 500 กัน query ยาวเกินขีดจำกัดของ MySQL
        foreach (array_chunk($names, 500) as $chunk) {
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
    foreach ($bucket as $day => $byCat) {
        foreach ($byCat as $cat => $c) {
            foreach ($c['items'] as $i => $it) {
                if ((int) $it['asset_id'] <= 0 && isset($map[$it['ref']])) {
                    $bucket[$day][$cat]['items'][$i]['asset_id'] = $map[$it['ref']];
                }
            }
        }
    }
}
