<?php
/**
 * includes/work_summary_send.php — ส่งสรุปงานรายคนเข้าคิว LINE
 *
 * ใช้ท่อเดิมทั้งหมด: line_notify_dispatch() รับ recipient_id รายคนได้อยู่แล้ว และ
 * notification_outbox เก็บผู้รับรายแถว จึงส่งแยกคนได้โดยไม่ต้องแก้อะไรในระบบแจ้งเตือน
 */

require_once __DIR__ . '/work_summary.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';

/** จำนวนชื่อของที่ยกมาต่อหัวข้อในไลน์ — แต่ละชื่อมีรูปประกอบ มากกว่านี้การ์ดยาวเกิน */
const WORK_SUMMARY_SEND_GROUPS = 3;

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
 * ชื่อของที่ทำในหัวข้อหนึ่ง — ตัดให้เหลือเท่าที่การ์ดรับไหว
 *
 * ไม่ใส่จำนวนครั้ง — ข้อความในไลน์ตอบว่า "ทำอะไร" ไม่ใช่ "ทำกี่ครั้ง"
 * ตัวเลขทั้งหมดยังดูได้ที่หน้าเว็บ · รูปสินค้าให้ฝั่ง Flex ไปหาเอาจากชื่อรุ่นเอง
 * (แก้รูปในระบบแล้วข้อความที่ค้างอยู่ในคิวจะได้ใช้รูปใหม่)
 *
 * @param  array<int,array<string,mixed>> $groups เรียงจากมากไปน้อยมาแล้ว
 * @param  int                            $limit
 * @return array{names:array<int,string>,more:int}
 */
function work_summary_group_names(array $groups, int $limit): array
{
    $names = [];
    foreach (array_slice($groups, 0, $limit) as $g) {
        $names[] = (string) $g['name'];
    }
    return ['names' => $names, 'more' => max(0, count($groups) - $limit)];
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

    foreach (work_people_recipients() as $person) {
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
        $days = [];
        foreach ($daily['days'] as $d) {
            $cats = [];
            foreach ($d['cats'] as $c) {
                $g = work_summary_group_names($c['groups'], WORK_SUMMARY_SEND_GROUPS);
                $cats[] = [
                    // key ไปบอกฝั่ง Flex ว่าจะหารูปจากตารางไหน (อะไหล่ ≠ รุ่นเครื่อง)
                    'key'   => (string) $c['key'],
                    'label' => (string) $c['label'],
                    'names' => $g['names'],
                    'more'  => $g['more'],
                ];
            }
            // payload เก็บลง DB ด้วย จึงส่งเฉพาะข้อความที่ Flex ใช้จริง ไม่ยัดรายการดิบทั้งก้อน
            // date ใช้วาดปฏิทินในการ์ด ส่วน label ใช้เขียนหัววัน
            $days[] = ['date' => (string) $d['date'], 'label' => (string) $d['label'], 'cats' => $cats];
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
