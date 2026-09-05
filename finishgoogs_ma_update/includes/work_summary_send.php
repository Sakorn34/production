<?php
/**
 * includes/work_summary_send.php — ส่งสรุปงานรายคนเข้าคิว LINE
 *
 * ใช้ท่อเดิมทั้งหมด: line_notify_dispatch() รับ recipient_id รายคนได้อยู่แล้ว และ
 * notification_outbox เก็บผู้รับรายแถว จึงส่งแยกคนได้โดยไม่ต้องแก้อะไรในระบบแจ้งเตือน
 */

require_once __DIR__ . '/work_summary.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';

/** จำนวนหัวข้องานที่เขียนต่อวันในไลน์ — เกินนี้ยุบเป็น "และอื่น ๆ" */
const WORK_SUMMARY_SEND_CATS = 3;

/**
 * bot ที่ใช้ส่งสรุปงานรายคน — "bot สำรอง" (ในโค้ดเดิมเรียก test)
 *
 * bot ตัวจริงตั้งไว้ให้ทำงานในกลุ่มเท่านั้น จึงส่งหาไลน์ส่วนตัวไม่ได้ สรุปงานรายคน
 * เลยต้องออกทาง bot สำรองเสมอ ไม่ว่าโหมดทดสอบจะเปิดหรือปิด
 *
 * ผลที่ตามมาที่ต้องรู้: userId ของ LINE **ผูกกับ channel** คนที่เคยผูกไว้กับ bot
 * ตัวจริงจึงใช้กับ bot สำรองไม่ได้ ต้องผูกใหม่ผ่าน bot สำรอง (ดู work_people.line_user_bot)
 */
const WORK_SUMMARY_LINE_BOT = 'test';

/**
 * URL ฐานของแอปนี้สำหรับลิงก์ในไลน์
 *
 * BASE_URL เดามาจาก SCRIPT_NAME ซึ่งตอนรันผ่าน cron (CLI) ไม่มีให้เดา เลยตกไปที่
 * '/production' ที่ขาดโฟลเดอร์แอป — ปุ่มในไลน์ที่ส่งจาก cron จะกดแล้ว 404
 * ทั้งที่กดจากหน้าเว็บแล้วปกติ เติมให้ตรงนี้เพื่อให้ทั้งสองทางได้ลิงก์เดียวกัน
 *
 * @return string ไม่มี / ปิดท้าย
 */
function work_summary_app_base_url(): string
{
    $base = rtrim(line_notify_production_base_url(), '/');
    $dir = '/finishgoogs_ma_update';
    if (substr($base, -strlen($dir)) !== $dir) {
        $base .= $dir;
    }
    return $base;
}

/**
 * ย่อ 1 วันให้เหลือเท่าที่การ์ดต้องใช้
 *
 * การ์ดตอบคำถามเดียว: "เดือนนี้ทำงานวันไหน เวลาไหน งานอะไร" — เอาไว้กรอกใบ OT
 * ตัวเลขและรายชื่อเครื่องทั้งหมดอยู่ที่หน้าเว็บ
 *
 * เวลาเป็น "เวลาที่บันทึกข้อมูล" ไม่ใช่เวลาเข้า–ออกงาน และมีเฉพาะงานฝั่ง production
 * (ระบบซ่อม/เช่าเก็บแค่วันที่) วันที่ไม่มีเวลาจึงเว้นไว้ ไม่เดาให้
 *
 * @param  array<string,mixed> $d จาก work_summary_person_items()
 * @return array<string,mixed>
 */
function work_summary_send_day(array $d): array
{
    $labels = [];
    foreach ($d['cats'] as $c) {
        $labels[] = (string) $c['label'];
    }
    $work = implode('  ·  ', array_slice($labels, 0, WORK_SUMMARY_SEND_CATS));
    if (count($labels) > WORK_SUMMARY_SEND_CATS) {
        $work .= '  ·  และอื่น ๆ';
    }

    $from = (string) $d['time_from'];
    $to = (string) $d['time_to'];
    $time = $from === '' ? '' : ($from === $to ? $from : $from . '–' . $to);

    // รูปประกอบใช้ของชิ้นแรกของหัวข้อแรก — หัวข้อเรียงจากงานที่ทำเยอะสุดมาแล้ว
    $lead = ['name' => '', 'key' => ''];
    if (!empty($d['cats'][0]['groups'][0]['name'])) {
        $lead = ['name' => (string) $d['cats'][0]['groups'][0]['name'],
                 'key'  => (string) $d['cats'][0]['key']];
    }

    return ['date' => (string) $d['date'], 'label' => (string) $d['label'],
            'time' => $time, 'work' => $work, 'lead' => $lead];
}

/**
 * ส่งสรุปของรอบหนึ่งให้ผู้รับที่เข้าเงื่อนไข
 *
 * @param  array<string,mixed> $cycle    จาก work_summary_cycle()
 * @param  int|null            $onlyPerson ส่งเฉพาะคนนี้ (ใช้ตอนกดส่งทดสอบ)
 * @param  bool                $force      ข้าม dedup — สำหรับการกดส่งเองที่ต้องได้ส่งจริงทุกครั้ง
 * @return array{queued:int,skipped_no_line:int,skipped_no_work:int,dedup:int,errors:array<int,string>}
 */
function work_summary_send_cycle(array $cycle, ?int $onlyPerson = null, bool $force = false): array
{
    $out = ['queued' => 0, 'skipped_no_line' => 0, 'skipped_no_work' => 0, 'dedup' => 0, 'errors' => []];

    // line_notify_dispatch() คืน null ทั้งตอนโดน dedup และตอนอีเวนต์ถูกปิดไว้ ถ้าไม่ดัก
    // ตรงนี้ หน้าจอจะขึ้นว่า "เคยส่งรอบนี้แล้ว" ทั้งที่จริงคือปิดสวิตช์ไว้ — ตามหากันไม่เจอ
    if (!line_notify_is_enabled('work.summary.monthly')) {
        $out['errors'][] = 'แจ้งเตือน "สรุปงานรายคน" ถูกปิดอยู่ในหน้าตั้งค่า LINE';
        return $out;
    }

    $summary = work_summary_for_cycle((string) $cycle['from'], (string) $cycle['to']);
    if ($summary['errors']) {
        // ดึงบางระบบไม่ได้ = ตัวเลขไม่ครบ ส่งไปจะเป็นสรุปที่ผิด — หยุดดีกว่า
        $out['errors'] = $summary['errors'];
        return $out;
    }
    // งานรายคนของรอบนี้ (คนที่ไม่มีงานเลยจะไม่อยู่ใน rows)
    $work = [];
    foreach ($summary['rows'] as $row) {
        $work[(int) $row['id']] = $row;
    }

    foreach (work_people_recipients(WORK_SUMMARY_LINE_BOT) as $person) {
        $pid = (int) $person['id'];
        if ($onlyPerson !== null && $pid !== $onlyPerson) {
            continue;
        }
        if (empty($work[$pid]) || (int) $work[$pid]['total'] <= 0) {
            $out['skipped_no_work']++;
            continue;
        }
        $row = $work[$pid];
        // เจ้าตัวอยากรู้ว่า "วันไหนทำอะไร" ไม่ใช่ยอดรวมทั้งรอบ — ส่งเป็นไทม์ไลน์รายวัน
        // พร้อมของจริงที่ทำ (รุ่นที่ผลิต / อะไหล่ที่เบิก / รุ่นที่ซ่อม)
        $daily = work_summary_person_items($pid, (string) $cycle['from'], (string) $cycle['to']);
        // payload เก็บลง DB ด้วย จึงส่งเฉพาะข้อความที่ Flex ใช้จริง ไม่ยัดรายการดิบทั้งก้อน
        $days = [];
        foreach ($daily['days'] as $d) {
            $days[] = work_summary_send_day($d);
        }

        $payload = [
            'person_name' => (string) $row['name'],
            'cycle_label' => (string) $cycle['label'],
            'cycle_key'   => (string) $cycle['key'],
            'cycle_from'  => (string) $cycle['from'],
            'cycle_to'    => (string) $cycle['to'],
            // total เก็บไว้ดูตอนไล่ปัญหาใน outbox — ตัว Flex ไม่ได้แสดงจำนวนแล้ว
            'total'       => (int) $row['total'],
            'days'        => $days,
            // ลิงก์ไปหน้าของเจ้าตัวโดยเฉพาะ พร้อมโทเคนประจำตัว — คนทำงานส่วนใหญ่ไม่มี
            // บัญชีในระบบ ถ้าให้ไปหน้ารวมที่ต้อง login เท่ากับกดแล้วดูอะไรไม่ได้
            'report_url'  => work_summary_app_base_url() . '/my_work.php?t='
                             . rawurlencode(work_people_view_token($pid))
                             . '&c=' . rawurlencode((string) $cycle['to']),
        ];

        $id = line_notify_dispatch('work.summary.monthly', $payload, [
            'recipient_id' => (string) $person['line_user_id'],
            'bot'          => WORK_SUMMARY_LINE_BOT,
            // กันส่งซ้ำถ้า cron รันซ้ำรอบเดิม — ผูกกับคน+รอบ
            'dedup_key'    => 'work.summary.monthly:' . $pid . ':' . $cycle['key'],
            'dedup_ttl'    => 60 * 86400,
            'skip_dedup'   => $force,
        ]);
        if ($id === null) {
            $out['dedup']++;
        } else {
            $out['queued']++;
        }
    }

    if ($onlyPerson === null) {
        foreach (work_people_all() as $p) {
            if ((int) ($p['notify_enabled'] ?? 1) === 1 && (int) ($p['is_active'] ?? 1) === 1
                && empty($p['line_user_id'])) {
                $out['skipped_no_line']++;
            }
        }
    }
    return $out;
}
