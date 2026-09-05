<?php
/**
 * cron/plesk_line_job_work_summary.php — ส่งสรุปงานรายคนเข้าไลน์ (CLI)
 *
 * ตั้ง Plesk Scheduled Task ให้รันวันที่ 21 ของทุกเดือน เช่น cron `0 9 21 * *`
 * จะสรุปรอบที่เพิ่งปิดไป (21 เดือนก่อน – 20 เดือนนี้) ส่งให้แต่ละคนที่เปิดส่งและผูก LINE แล้ว
 *
 * รันเองด้วยมือ:
 *   php cron/plesk_line_job_work_summary.php            # รอบที่เพิ่งปิด
 *   php cron/plesk_line_job_work_summary.php --date=2026-07-20   # ระบุรอบเอง
 *   php cron/plesk_line_job_work_summary.php --dry-run  # ดูว่าจะส่งให้ใครบ้าง ไม่ส่งจริง
 */

// ผ่าน _bootstrap.php เหมือน job LINE ตัวอื่น — ได้ทั้งกันเรียกผ่านเว็บ เช็ครุ่น PHP
// และโหลด core/flex ชุดเดียวกับที่ job ซึ่งรันบนเซิร์ฟเวอร์อยู่แล้วใช้
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/includes/work_summary_send.php';

$dryRun = in_array('--dry-run', $argv, true);
$date = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--date=') === 0) {
        $date = substr($arg, 7);
    }
}

// ค่าปกติ (ไม่ระบุ --date): รันวันที่ 21 แล้วต้องสรุป "รอบที่เพิ่งปิด" ซึ่งจบไปเมื่อวาน
// ถ้าใช้วันนี้ตรง ๆ work_summary_cycle() จะให้รอบใหม่ที่เพิ่งเริ่มและยังไม่มีข้อมูล
$anchor = $date !== null && $date !== '' ? $date : date('Y-m-d', strtotime('-1 day'));
$cycle = work_summary_cycle($anchor);

if ($dryRun) {
    $summary = work_summary_for_cycle($cycle['from'], $cycle['to']);
    $work = [];
    foreach ($summary['rows'] as $r) {
        $work[(int) $r['id']] = (int) $r['total'];
    }
    $lines = [];
    foreach (work_people_recipients(WORK_SUMMARY_LINE_BOT) as $p) {
        $n = $work[(int) $p['id']] ?? 0;
        $lines[] = sprintf('  %-14s %s', $p['display_name'], $n > 0 ? number_format($n) . ' รายการ' : '— ไม่มีงาน (ข้าม)');
    }
    echo 'รอบ ' . $cycle['from'] . ' .. ' . $cycle['to'] . " (dry-run)\n";
    echo $lines ? implode("\n", $lines) . "\n" : "  ไม่มีผู้รับที่พร้อม (ต้องเปิดส่ง + ผูก LINE)\n";
    if ($summary['errors']) {
        echo 'ดึงข้อมูลไม่ครบ: ' . implode(' | ', $summary['errors']) . "\n";
    }
    exit(0);
}

$res = work_summary_send_cycle($cycle);
if (!empty($res['errors'])) {
    // ตัวเลขไม่ครบ = สรุปผิด ไม่ส่งดีกว่าส่งผิด
    fwrite(STDERR, 'ไม่ส่ง: ' . implode(' | ', $res['errors']) . "\n");
    echo json_encode(['ok' => false, 'cycle' => $cycle['key'], 'errors' => $res['errors']], JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$sent = line_notify_process_outbox(60);

echo json_encode([
    'ok'              => true,
    'cycle'           => $cycle['key'],
    'from'            => $cycle['from'],
    'to'              => $cycle['to'],
    'queued'          => $res['queued'],
    'skipped_no_line' => $res['skipped_no_line'],
    'skipped_no_work' => $res['skipped_no_work'],
    'dedup'           => $res['dedup'],
    'outbox'          => $sent,
], JSON_UNESCAPED_UNICODE) . "\n";
