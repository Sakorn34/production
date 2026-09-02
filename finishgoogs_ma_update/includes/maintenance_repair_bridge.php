<?php
/**
 * maintenance_repair_bridge.php — ดึงประวัติงานซ่อมจากระบบ MA (biton_maintenance)
 * มาแสดงบนหน้าโปรไฟล์เครื่องของระบบ production
 *
 * ระบบซ่อมเป็นคนละแอปคนละฐานข้อมูล และมีข้อตกลงว่า **ห้ามแก้โค้ดฝั่งนั้น**
 * ไฟล์นี้จึงอ่านจากฐานของมันตรง ๆ อย่างเดียว — มีแต่ SELECT ไม่มี INSERT/UPDATE/DELETE
 *
 * S/N ผูกกันผ่าน assets.asset_code = transac_repair.trp_sn (ตรวจแล้ว factory_serial
 * ไม่ช่วยเพิ่มการจับคู่แม้แต่ตัวเดียว) และมี 3 ตารางที่เกี่ยวกับเครื่องหนึ่งตัว
 *   1) transac_repair  งานซ่อมของเครื่องนี้            (2,198 เครื่องจับคู่ได้)
 *   2) repair_history  ประวัติเก่าก่อนย้ายระบบ 2561–2563 (312 เครื่อง)
 *   3) transac_spare   ตอนที่เครื่องนี้ถูกยืมเป็นเครื่องสำรอง (183 เครื่อง)
 */

if (!function_exists('h')) {
    require_once __DIR__ . '/../config.php';
}

/** จำนวนแถวเครื่องสำรองที่แสดงก่อนต้องกดกาง — บางตัวถูกยืมเป็นสิบ ๆ ครั้ง */
const MA_SPARE_VISIBLE = 5;

/**
 * ค่าที่ถือว่าว่าง — ข้อมูลจริงมีทั้ง '' , '0' และ '-' ปนกัน
 *
 * @param  mixed $v
 * @return string|null  null ถ้าไม่มีค่าใช้ได้
 */
function ma_val($v)
{
    $s = trim((string) $v);
    if ($s === '' || $s === '0' || $s === '-' || $s === '--') {
        return null;
    }
    return $s;
}

/**
 * วันที่แบบ ISO (2026-08-25) → d/m/พ.ศ.
 * แปลงไม่ได้ให้คืนค่าดิบ ดีกว่าโชว์ช่องว่างหรือปี 1970
 *
 * @param  mixed  $v
 * @return string|null
 */
function ma_date_iso($v)
{
    $s = ma_val($v);
    if ($s === null) {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
        $y = (int) $m[1];
        if ($y > 1900 && $y < 2200) {
            return sprintf('%02d/%02d/%d', (int) $m[3], (int) $m[2], $y + 543);
        }
    }
    return $s;
}

/**
 * วันที่ของตารางเก่า — เป็น พ.ศ. แบบไม่เติมศูนย์ เช่น 26/10/2561 หรือ 24/3/2561
 *
 * @param  mixed  $v
 * @return string|null
 */
function ma_date_be($v)
{
    $s = ma_val($v);
    if ($s === null) {
        return null;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
        return sprintf('%02d/%02d/%d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    return $s;
}

/**
 * รายการที่ซ่อม — trp_repair_no1..no10 เป็นชื่อรายการตรง ๆ ไม่ใช่ FK
 *
 * @param  array<string,mixed> $row
 * @return array<int,string>
 */
function ma_repair_items(array $row): array
{
    $out = [];
    for ($i = 1; $i <= 10; $i++) {
        $v = ma_val($row['trp_repair_no' . $i] ?? null);
        if ($v !== null) {
            $out[] = $v;
        }
    }
    return $out;
}

/**
 * บริการเพิ่มเติมที่ทำกับบอร์ด — แต่ละช่องเก็บข้อความของตัวเอง
 *
 * @param  array<string,mixed> $row
 * @return array<int,string>
 */
function ma_repair_services(array $row): array
{
    $out = [];
    foreach (['trp_clean', 'trp_repeat_solder', 'trp_spray', 'trp_fw'] as $f) {
        $v = ma_val($row[$f] ?? null);
        if ($v !== null) {
            $out[] = $v;
        }
    }
    return $out;
}

/**
 * งานนี้ปิดแล้วหรือยัง — สถานะมี 10 แบบ มีแค่ "เสร็จสิ้น" ที่ถือว่าจบ
 *
 * @param  mixed $status
 * @return bool
 */
function ma_job_is_open($status): bool
{
    $s = ma_val($status);
    return $s !== null && $s !== 'เสร็จสิ้น';
}

/**
 * งานเร่งด่วนไหม — ความเร่งด่วนไม่มีช่องของตัวเอง คนคีย์พิมพ์ปนมากับ
 * บันทึกท้ายการอนุมัติ (trp_backup, 360 แถว) และบางทีก็อยู่ในอาการที่แจ้ง (35 แถว)
 *
 * @param  array<string,mixed> $row
 * @return bool
 */
function ma_job_is_urgent(array $row): bool
{
    foreach (['trp_backup', 'trp_repair_inform', 'trp_repair_remarks', 'trp_other_comment'] as $f) {
        if (mb_strpos((string) ($row[$f] ?? ''), 'ด่วน') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * ลูกค้าไม่อนุมัติให้ซ่อมหรือเปล่า — 1,425 งานจาก 6,468 เป็นแบบนี้
 * (มักตามด้วยบันทึกว่า "ซื้อใหม่" หรือ "คืนซาก")
 *
 * @param  mixed $v
 * @return bool
 */
function ma_job_is_declined($v): bool
{
    $s = ma_val($v);
    return $s !== null && mb_strpos($s, 'ไม่อนุมัติ') !== false;
}

/**
 * ค้นประวัติซ่อมทั้งหมดของ S/N หนึ่งตัว
 *
 * @param  string $serial
 * @return array<string,mixed>
 */
function asset_maintenance_info(string $serial): array
{
    $sn = trim($serial);
    $empty = [
        'ok'      => false,
        'configured' => true,
        'serial'  => $sn,
        'error'   => '',
        'jobs'    => [],
        'legacy'  => [],
        'spare'   => [],
        'counts'  => ['jobs' => 0, 'legacy' => 0, 'spare' => 0],
        'open'    => 0,
        'urgent'  => 0,
        'has_any' => false,
    ];

    // ไม่มีรหัสเครื่องก็ไม่ต้องเสียเวลาต่อฐานข้อมูล
    if ($sn === '') {
        return $empty;
    }

    // ยังไม่ได้ตั้งค่าฐานระบบซ่อม = ยังไม่เปิดใช้ฟีเจอร์นี้ ไม่ใช่ความผิดพลาด
    // ถ้าไม่แยกกรณีนี้ออก เซิร์ฟเวอร์ที่ยังไม่ตั้งค่าจะขึ้นข้อความเตือนทุกหน้าเครื่อง
    $sec = db_secrets();
    if (empty($sec['maintenance']) || !is_array($sec['maintenance'])
        || trim((string)($sec['maintenance']['host'] ?? '')) === ''
        || trim((string)($sec['maintenance']['db'] ?? '')) === ''
        || trim((string)($sec['maintenance']['user'] ?? '')) === '') {
        $empty['configured'] = false;
        return $empty;
    }

    $db = dbMaintenance();
    if ($db === null) {
        $empty['error'] = dbMaintenanceError();
        return $empty;
    }

    $jobs = $legacy = $spare = [];

    // ── 1) งานซ่อม ──────────────────────────────────────────────────────────
    $sql = 'SELECT r.*, t.ts_mtn_number, t.ts_date, t.ts_enduser_com,
                   t.ts_security_com, t.ts_report_name
            FROM transac_repair r
            LEFT JOIN transac t ON t.ts_id = r.trp_ts_id
            WHERE UPPER(TRIM(r.trp_sn)) = UPPER(?)
            ORDER BY r.trp_id DESC';
    if ($st = $db->prepare($sql)) {
        $st->bind_param('s', $sn);
        if ($st->execute()) {
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $jobs[] = $row;
            }
        }
        $st->close();
    }

    // ── 2) ประวัติเก่า ──────────────────────────────────────────────────────
    $sql = 'SELECT * FROM repair_history
            WHERE UPPER(TRIM(rh_sn)) = UPPER(?)
            ORDER BY rh_id DESC';
    if ($st = $db->prepare($sql)) {
        $st->bind_param('s', $sn);
        if ($st->execute()) {
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $legacy[] = $row;
            }
        }
        $st->close();
    }

    // ── 3) ถูกยืมไปเป็นเครื่องสำรอง ─────────────────────────────────────────
    $sql = 'SELECT s.*, t.ts_mtn_number, t.ts_enduser_com,
                   r.trp_sn AS repaired_sn, r.trp_product AS repaired_product
            FROM transac_spare s
            LEFT JOIN transac t ON t.ts_id = s.tsp_ts_id
            LEFT JOIN transac_repair r ON r.trp_id = s.tsp_trp_id
            WHERE UPPER(TRIM(s.tsp_sn)) = UPPER(?)
            ORDER BY s.tsp_id DESC';
    if ($st = $db->prepare($sql)) {
        $st->bind_param('s', $sn);
        if ($st->execute()) {
            $res = $st->get_result();
            while ($row = $res->fetch_assoc()) {
                $spare[] = $row;
            }
        }
        $st->close();
    }

    $open = $urgent = 0;
    foreach ($jobs as $j) {
        if (ma_job_is_open($j['trp_status_job'] ?? null)) {
            $open++;
        }
        if (ma_job_is_urgent($j)) {
            $urgent++;
        }
    }

    return [
        'ok'      => true,
        'configured' => true,
        'serial'  => $sn,
        'error'   => '',
        'jobs'    => $jobs,
        'legacy'  => $legacy,
        'spare'   => $spare,
        'counts'  => ['jobs' => count($jobs), 'legacy' => count($legacy), 'spare' => count($spare)],
        'open'    => $open,
        'urgent'  => $urgent,
        'has_any' => ($jobs || $legacy || $spare),
    ];
}

/* ═══════════════════════════════════════════════════════════════════════════
   ส่วนแสดงผล
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * ขั้นตอนของงานซ่อมหนึ่งใบ — ใช้ทำแถบบอกว่า "ตอนนี้ถึงไหนแล้ว"
 * ถ้าลูกค้าไม่อนุมัติซ่อม งานจบที่ขั้นอนุมัติ ขั้นถัดไปไม่นับว่าค้าง
 *
 * @param  array<string,mixed> $j
 * @return array<int,array<string,mixed>>
 */
function ma_job_stages(array $j): array
{
    $declined = ma_job_is_declined($j['trp_status_firmma'] ?? null);

    $steps = [
        ['รับเครื่อง',  ma_date_iso($j['trp_receive_date'] ?? null), ma_val($j['trp_user_recive_ma'] ?? null)],
        ['ประเมิน',     ma_date_iso($j['trp_rate_date'] ?? null),    ma_val($j['trp_user_rate'] ?? null)],
        ['เสนอราคา',    ma_date_iso($j['trp_qv_date'] ?? null),      ma_val($j['trp_qv_send'] ?? null)],
        [$declined ? 'ไม่อนุมัติซ่อม' : 'อนุมัติซ่อม',
                        ma_date_iso($j['trp_firm_qv_date'] ?? null), null],
    ];
    if (!$declined) {
        $steps[] = ['ซ่อมเสร็จ', ma_date_iso($j['trp_success_date'] ?? null), ma_val($j['trp_user_ma'] ?? null)];
        $steps[] = ['QC',        ma_date_iso($j['trp_qc_date'] ?? null),      ma_val($j['trp_qc_user'] ?? null)];
    }
    $steps[] = ['ส่งคืน', ma_date_iso($j['trp_send_date'] ?? null), ma_val($j['trp_logistics'] ?? null)];

    // ขั้นแรกที่ยังไม่มีวันที่คือขั้นที่ค้างอยู่ ที่เหลือถือว่ายังไม่ถึง
    $out = [];
    $seenBlank = false;
    foreach ($steps as [$label, $date, $by]) {
        if ($date === null) {
            $state = $seenBlank ? 'todo' : 'now';
            $seenBlank = true;
        } else {
            $state = 'done';
        }
        $out[] = ['label' => $label, 'date' => $date, 'by' => $by, 'state' => $state];
    }

    // งานที่ปิดแล้วไม่ควรมีขั้นไหนขึ้นว่ากำลังทำอยู่
    if (!ma_job_is_open($j['trp_status_job'] ?? null)) {
        foreach ($out as &$s) {
            if ($s['state'] === 'now') {
                $s['state'] = 'todo';
            }
        }
        unset($s);
    }
    if ($declined) {
        foreach ($out as &$s2) {
            if ($s2['label'] === 'ไม่อนุมัติซ่อม' && $s2['state'] === 'done') {
                $s2['state'] = 'stop';
            }
        }
        unset($s2);
    }

    return $out;
}

/**
 * แถบขั้นตอน
 *
 * @param  array<string,mixed> $j
 * @return string
 */
function ma_stages_html(array $j): string
{
    $stages = ma_job_stages($j);
    $out = '<div class="ma-stagewrap"><p class="ma-caption">ขั้นตอนงาน</p><ol class="ma-stages">';
    foreach ($stages as $s) {
        $lbl = $s['label'] . ($s['by'] !== null ? ' · ' . $s['by'] : '');
        $out .= '<li class="ma-stage is-' . $s['state'] . '">'
            . '<span class="ma-dot"></span>'
            . '<span class="ma-lbl">' . h($lbl) . '</span>'
            . '<span class="ma-dt">' . h($s['date'] ?? '—') . '</span>'
            . '</li>';
    }
    return $out . '</ol></div>';
}

/**
 * แถวคู่ป้าย–ค่า ในกล่องข้อเท็จจริง
 *
 * @param  string      $label
 * @param  string|null $value  HTML ที่ escape มาแล้ว
 * @return string
 */
function ma_fact_row(string $label, $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return '<dt>' . h($label) . '</dt><dd>' . $value . '</dd>';
}

/**
 * กล่องข้อเท็จจริงหนึ่งกลุ่ม — ไม่มีแถวไหนเลยก็ไม่ต้องขึ้นหัวข้อ
 *
 * @param  string $title
 * @param  string $rows
 * @return string
 */
function ma_fact_group(string $title, string $rows): string
{
    if (trim($rows) === '') {
        return '';
    }
    return '<div class="ma-fgroup"><h4>' . h($title) . '</h4><dl>' . $rows . '</dl></div>';
}

/**
 * รายละเอียดงานซ่อมหนึ่งใบ (ส่วนที่กางออกมา)
 *
 * @param  array<string,mixed> $j
 * @return string
 */
function ma_job_detail_html(array $j): string
{
    $declined = ma_job_is_declined($j['trp_status_firmma'] ?? null);
    $out = '<div class="ma-jobdetail">' . ma_stages_html($j);

    // ── บันทึกท้ายการอนุมัติ — ที่ที่คนคีย์ใส่ "ซ่อมด่วน" / "เปลี่ยนเฉพาะหน้าจอ"
    //    / "ซื้อใหม่" ไว้ ต้องเด่นพอ ไม่ใช่ซ่อนอยู่ท้ายกล่องข้อมูล
    $firm = ma_val($j['trp_status_firmma'] ?? null);
    $note = ma_val($j['trp_backup'] ?? null);
    if ($firm !== null || $note !== null) {
        $cls = $declined ? ' is-declined' : '';
        $out .= '<div class="ma-decision' . $cls . '">';
        if ($firm !== null) {
            $out .= '<span class="ma-decision-label">' . h($firm) . '</span>';
        }
        if ($note !== null) {
            $out .= '<span class="ma-decision-note">' . h($note) . '</span>';
        }
        $out .= '</div>';
    }

    $out .= '<div class="ma-jobbody"><div class="ma-storyline">';

    // ① อาการที่ลูกค้าแจ้ง
    $inform = ma_val($j['trp_repair_inform'] ?? null);
    if ($inform !== null) {
        $out .= '<div class="ma-step"><h4>อาการที่ลูกค้าแจ้ง</h4>'
            . '<p class="ma-longtext">' . h($inform) . '</p></div>';
    }

    // ② ผลวินิจฉัยของช่าง
    $tech = ma_val($j['trp_repair_technician'] ?? null);
    if ($tech !== null) {
        $by = ma_val($j['trp_user_rate'] ?? null);
        $on = ma_date_iso($j['trp_rate_date'] ?? null);
        $sub = trim(($by ?? '') . ($by !== null && $on !== null ? ' · ' : '') . ($on ?? ''));
        $out .= '<div class="ma-step"><h4>ผลวินิจฉัยของช่าง'
            . ($sub !== '' ? '<span class="ma-by">' . h($sub) . '</span>' : '')
            . '</h4><p class="ma-longtext">' . h($tech) . '</p></div>';
    }

    // ③ สิ่งที่ทำกับเครื่อง
    $items = ma_repair_items($j);
    $svcs = ma_repair_services($j);
    $remarks = ma_val($j['trp_repair_remarks'] ?? null);
    $comment = ma_val($j['trp_other_comment'] ?? null);
    if ($items || $svcs || $remarks || $comment) {
        $by = ma_val($j['trp_user_ma'] ?? null);
        $on = ma_date_iso($j['trp_success_date'] ?? null);
        $sub = trim(($by ?? '') . ($by !== null && $on !== null ? ' · เสร็จ ' : '') . ($on ?? ''));
        $out .= '<div class="ma-step"><h4>' . ($declined ? 'รายการที่เสนอไป' : 'สิ่งที่ทำกับเครื่อง')
            . ($sub !== '' ? '<span class="ma-by">' . h($sub) . '</span>' : '')
            . '</h4>';
        if ($items) {
            $out .= '<ul class="ma-items">';
            foreach ($items as $it) {
                $out .= '<li>' . h($it) . '</li>';
            }
            $out .= '</ul>';
        }
        if ($svcs) {
            $out .= '<p class="ma-sublabel">บริการเพิ่ม</p><div class="ma-svcs">';
            foreach ($svcs as $s) {
                $out .= '<span>' . h($s) . '</span>';
            }
            $out .= '</div>';
        }
        if ($remarks !== null) {
            $out .= '<p class="ma-sublabel">หมายเหตุของช่าง</p>'
                . '<p class="ma-longtext">' . h($remarks) . '</p>';
        }
        if ($comment !== null) {
            $out .= '<p class="ma-sublabel">บันทึกเพิ่มเติม</p>'
                . '<p class="ma-longtext">' . h($comment) . '</p>';
        }
        $out .= '</div>';
    }

    $out .= '</div><div class="ma-facts">';

    // ลูกค้า
    $rows = ma_fact_row('เลขงาน', h(ma_val($j['ts_mtn_number'] ?? null) ?? '—'))
        . ma_fact_row('วันที่แจ้ง', h(ma_date_iso($j['ts_date'] ?? null) ?? ''))
        . ma_fact_row('บริษัท', h(ma_val($j['ts_enduser_com'] ?? null) ?? ''))
        . ma_fact_row('หน่วยงาน', h(ma_val($j['ts_security_com'] ?? null) ?? ''))
        . ma_fact_row('ผู้แจ้ง', h(ma_val($j['ts_report_name'] ?? null) ?? ''));
    $out .= ma_fact_group('ลูกค้า', $rows);

    // ตัวเครื่อง
    $rows = ma_fact_row('รุ่น', h(ma_val($j['trp_product'] ?? null) ?? ''))
        . ma_fact_row('วันที่ซื้อ', h(ma_date_iso($j['trp_buy_date'] ?? null) ?? ''))
        . ma_fact_row('อายุเครื่อง', h(ma_val($j['trp_repair_age'] ?? null) ?? ''))
        . ma_fact_row('ประกัน', h(ma_val($j['trp_insurance'] ?? null) ?? ''))
        . ma_fact_row('ผู้รับเครื่อง', h(ma_val($j['trp_user_recive_ma'] ?? null) ?? ''));
    $out .= ma_fact_group('ตัวเครื่อง', $rows);

    // ค่าใช้จ่าย
    $price = ma_val($j['trp_qv_price'] ?? null);
    $rows = ma_fact_row('เลขที่ใบเสนอราคา', h(ma_val($j['trp_qv_number'] ?? null) ?? ''))
        . ma_fact_row('ราคา', $price !== null ? '<b>' . h($price) . '</b>' : null)
        . ma_fact_row('วันที่เสนอ', h(ma_date_iso($j['trp_qv_date'] ?? null) ?? ''))
        . ma_fact_row('ส่งทาง', h(ma_val($j['trp_qv_send'] ?? null) ?? ''))
        . ma_fact_row('วันที่ตอบกลับ', h(ma_date_iso($j['trp_firm_qv_date'] ?? null) ?? ''));
    $out .= ma_fact_group('ใบเสนอราคา', $rows);

    // การส่งคืน
    $rows = ma_fact_row('วันที่ส่ง', h(ma_date_iso($j['trp_send_date'] ?? null) ?? ''))
        . ma_fact_row('ขนส่ง', h(ma_val($j['trp_logistics'] ?? null) ?? ''))
        . ma_fact_row('เลขพัสดุ', h(ma_val($j['trp_tracking'] ?? null) ?? ''))
        . ma_fact_row('ผู้ส่ง', h(ma_val($j['trp_sendby'] ?? null) ?? ''));
    $out .= ma_fact_group('การส่งคืน', $rows);

    return $out . '</div></div></div>';
}

/**
 * ตารางงานซ่อม
 *
 * @param  array<int,array<string,mixed>> $jobs
 * @return string
 */
function ma_jobs_table_html(array $jobs): string
{
    $out = '<div class="ma-card"><div class="ma-tblscroll"><div class="ma-tbl">'
        . '<div class="ma-thead">'
        . '<span>เลขงาน</span><span>วันที่รับ</span><span>สถานะ</span>'
        . '<span>อาการที่แจ้ง</span><span>วันที่เสร็จ</span><span></span>'
        . '</div>';

    foreach ($jobs as $j) {
        $status = ma_val($j['trp_status_job'] ?? null);
        $open = ma_job_is_open($j['trp_status_job'] ?? null);
        $declined = ma_job_is_declined($j['trp_status_firmma'] ?? null);
        $urgent = ma_job_is_urgent($j);

        $flags = '';
        if ($urgent) {
            $flags .= ' <span class="ma-pill ma-pill-urgent">ด่วน</span>';
        }
        if ($declined) {
            $flags .= ' <span class="ma-pill ma-pill-declined">ไม่อนุมัติซ่อม</span>';
        }

        $out .= '<details class="ma-jobrow"><summary>'
            . '<span class="ma-mono">' . h(ma_val($j['ts_mtn_number'] ?? null) ?? '—') . '</span>'
            . '<span class="ma-mono">' . h(ma_date_iso($j['trp_receive_date'] ?? null) ?? '—') . '</span>'
            . '<span><span class="ma-pill ' . ($open ? 'ma-pill-open' : 'ma-pill-done') . '">'
            . h($status ?? 'ไม่ระบุ') . '</span></span>'
            . '<span>' . h(ma_val($j['trp_repair_inform'] ?? null) ?? '—') . $flags . '</span>'
            . '<span class="ma-mono">' . h(ma_date_iso($j['trp_success_date'] ?? null) ?? '—') . '</span>'
            . '<svg class="ma-chev" width="14" height="14" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>'
            . '</summary>' . ma_job_detail_html($j) . '</details>';
    }

    return $out . '</div></div></div>';
}

/**
 * ตารางประวัติเก่า
 *
 * @param  array<int,array<string,mixed>> $rows
 * @return string
 */
function ma_legacy_table_html(array $rows): string
{
    $out = '<h3 class="ma-subhead">ประวัติเก่า (ก่อนย้ายระบบ)</h3>'
        . '<p class="ma-hint">บันทึกจากระบบเดิมช่วงปี 2561–2563 · มีเฉพาะข้อมูลพื้นฐาน</p>'
        . '<div class="ma-card ma-card-flat"><div class="ma-tblscroll">'
        . '<table class="ma-table"><thead><tr>'
        . '<th>เลขงาน</th><th>วันที่</th><th>หน่วยงาน</th><th>สถานที่</th><th>รุ่น</th>'
        . '</tr></thead><tbody>';

    foreach ($rows as $r) {
        $out .= '<tr>'
            . '<td class="ma-mono">' . h(ma_val($r['rh_mtn_number'] ?? null) ?? '—') . '</td>'
            . '<td class="ma-mono">' . h(ma_date_be($r['rh_date'] ?? null) ?? '—') . '</td>'
            . '<td>' . h(ma_val($r['rh_com_security'] ?? null) ?? '—') . '</td>'
            . '<td>' . h(ma_val($r['rh_com_enduser'] ?? null) ?? '—') . '</td>'
            . '<td class="ma-muted">' . h(ma_val($r['rh_product'] ?? null) ?? '—') . '</td>'
            . '</tr>';
    }

    return $out . '</tbody></table></div></div>';
}

/**
 * ตารางการถูกยืมเป็นเครื่องสำรอง
 *
 * @param  array<int,array<string,mixed>> $rows
 * @return string
 */
function ma_spare_table_html(array $rows): string
{
    $total = count($rows);
    $stillOut = $total > 0 && ma_val($rows[0]['tsp_datereturn'] ?? null) === null;

    $out = '<h3 class="ma-subhead">เคยถูกยืมเป็นเครื่องสำรอง'
        . ($stillOut ? ' <span class="ma-pill ma-pill-out">ตอนนี้อยู่กับลูกค้า</span>' : '')
        . '</h3><p class="ma-hint">' . h(number_format($total)) . ' ครั้ง';
    $last = ma_date_iso($rows[0]['tsp_datesend'] ?? null);
    if ($last !== null) {
        $out .= ' · ล่าสุดส่งออก ' . h($last);
        $out .= $stillOut ? ' ยังไม่มีวันที่รับคืน' : '';
    }
    $out .= '</p>';

    $renderRows = function (array $slice): string {
        $s = '';
        foreach ($slice as $r) {
            $back = ma_date_iso($r['tsp_datereturn'] ?? null);
            $s .= '<tr>'
                . '<td class="ma-mono">' . h(ma_val($r['ts_mtn_number'] ?? null) ?? '—') . '</td>'
                . '<td class="ma-mono">' . h(ma_date_iso($r['tsp_datesend'] ?? null) ?? '—') . '</td>'
                . '<td>' . ($back !== null
                    ? '<span class="ma-mono">' . h($back) . '</span>'
                    : '<span class="ma-pill ma-pill-out">ยังไม่คืน</span>') . '</td>'
                . '<td>' . h(ma_val($r['ts_enduser_com'] ?? null) ?? '—') . '</td>'
                . '<td class="ma-mono">' . h(ma_val($r['repaired_sn'] ?? null) ?? '—') . '</td>'
                . '<td class="ma-muted">' . h(ma_val($r['tsp_logistics'] ?? null) ?? '—') . '</td>'
                . '</tr>';
        }
        return $s;
    };

    $head = '<table class="ma-table"><thead><tr>'
        . '<th>เลขงาน</th><th>ส่งออก</th><th>รับคืน</th><th>ลูกค้า</th>'
        . '<th>ไปแทนเครื่อง</th><th>ขนส่ง</th></tr></thead><tbody>';

    $out .= '<div class="ma-card ma-card-flat"><div class="ma-tblscroll">'
        . $head . $renderRows(array_slice($rows, 0, MA_SPARE_VISIBLE)) . '</tbody></table></div>';

    if ($total > MA_SPARE_VISIBLE) {
        $rest = array_slice($rows, MA_SPARE_VISIBLE);
        $out .= '<details class="ma-more"><summary>ดูอีก ' . h(number_format(count($rest))) . ' ครั้ง</summary>'
            . '<div class="ma-tblscroll">' . $head . $renderRows($rest) . '</tbody></table></div></details>';
    }

    return $out . '</div>';
}

/**
 * ส่วนแสดงผลทั้งก้อนบนหน้าโปรไฟล์เครื่อง
 * ไม่มีข้อมูลเลย → คืนค่าว่าง ไม่ต้องขึ้นกล่องเปล่า (86% ของเครื่องเข้าเคสนี้)
 *
 * @param  array<string,mixed> $info
 * @return string
 */
function asset_maintenance_section_html(array $info): string
{
    // ยังไม่ได้ตั้งค่าฐานระบบซ่อม → เงียบสนิท ยังไม่เปิดใช้ก็ไม่ต้องมีอะไรบนหน้า
    if (isset($info['configured']) && $info['configured'] === false) {
        return '';
    }

    // ตั้งค่าแล้วแต่ต่อไม่ได้ → เรื่องนี้ควรรู้ บอกสั้น ๆ แบบจาง ๆ ไม่ใช่หน้า error
    if (empty($info['ok'])) {
        $err = trim((string) ($info['error'] ?? ''));
        if ($err === '') {
            return '';
        }
        return '<p class="muted ma-unavailable">ดึงประวัติซ่อมจากระบบ MA ไม่ได้ ('
            . h($err) . ') — ข้อมูลส่วนอื่นของหน้านี้ยังใช้ได้ตามปกติ</p>';
    }

    if (empty($info['has_any'])) {
        return '';
    }

    $c = $info['counts'];
    $jobs = $info['jobs'];

    $out = '<section class="ma-repair-section">';
    $out .= '<h2 class="h-with-icon">' . ui_icon_html('repairs', 18) . 'ประวัติงานซ่อม (ระบบ MA)</h2>';

    // บรรทัดสรุป — ต้องครอบทุกกรณี ไม่ใช่แค่เครื่องที่เคยซ่อม
    // เครื่องสำรองบางตัวไม่เคยเข้าซ่อมเลย มีแต่ประวัติการถูกยืม
    $bits = [];
    if ($c['jobs'] > 0) {
        $bits[] = 'ซ่อม ' . number_format($c['jobs']) . ' ครั้ง';
        $last = ma_date_iso($jobs[0]['trp_receive_date'] ?? null);
        if ($last !== null) {
            $bits[] = 'ล่าสุด ' . $last;
        }
    }
    if ($c['legacy'] > 0) {
        $bits[] = 'ประวัติเก่า ' . number_format($c['legacy']) . ' รายการ';
    }
    if ($c['spare'] > 0) {
        $bits[] = 'ถูกยืมเป็นเครื่องสำรอง ' . number_format($c['spare']) . ' ครั้ง';
    }
    $out .= '<p class="ma-hint">' . h(implode(' · ', $bits));
    if (!empty($info['open'])) {
        $st = ma_val($jobs[0]['trp_status_job'] ?? null);
        $out .= ' <span class="ma-pill ma-pill-open">ยังไม่ปิดงาน'
            . ($st !== null ? ' — ' . h($st) : '') . '</span>';
    }
    if (!empty($info['urgent'])) {
        $out .= ' <span class="ma-pill ma-pill-urgent">มีงานด่วน '
            . h(number_format($info['urgent'])) . '</span>';
    }
    $out .= ($bits ? ' &nbsp;·&nbsp; ' : '')
        . '<span class="ma-faint">อ่านจากระบบซ่อม ไม่ใช่ตาราง'
        . ' &ldquo;ประวัติซ่อม&rdquo; ของ production</span></p>';

    if ($jobs) {
        $out .= ma_jobs_table_html($jobs);
    }
    if (!empty($info['legacy'])) {
        $out .= ma_legacy_table_html($info['legacy']);
    }
    if (!empty($info['spare'])) {
        $out .= ma_spare_table_html($info['spare']);
    }

    return $out . '</section>';
}
