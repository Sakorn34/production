<?php
/**
 * includes/work_summary_send.php — ส่งสรุปงานรายคนเข้าคิว LINE
 *
 * ใช้ท่อเดิมทั้งหมด: line_notify_dispatch() รับ recipient_id รายคนได้อยู่แล้ว และ
 * notification_outbox เก็บผู้รับรายแถว จึงส่งแยกคนได้โดยไม่ต้องแก้อะไรในระบบแจ้งเตือน
 */

require_once __DIR__ . '/work_summary.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';

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
        $daily = work_summary_person_daily($pid, (string) $cycle['from'], (string) $cycle['to']);
        $days = [];
        foreach ($daily['days'] as $d) {
            $items = [];
            foreach ($d['items'] as $it) {
                $items[] = ['label' => (string) $it['label'], 'count' => (int) $it['count']];
            }
            // payload เก็บลง DB ด้วย จึงตัดฟิลด์ที่ Flex ไม่ได้ใช้ทิ้ง
            $days[] = ['label' => (string) $d['label'], 'total' => (int) $d['total'], 'items' => $items];
        }

        $payload = [
            'person_name' => (string) $row['name'],
            'cycle_label' => (string) $cycle['label'],
            'cycle_key'   => (string) $cycle['key'],
            'cycle_from'  => (string) $cycle['from'],
            'cycle_to'    => (string) $cycle['to'],
            'total'       => (int) $row['total'],
            'day_count'   => count($days),
            'days'        => $days,
            'report_url'  => rtrim(line_notify_production_base_url(), '/') . '/work_report.php?c='
                             . rawurlencode((string) $cycle['to']) . '&p=' . $pid,
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
