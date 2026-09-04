<?php
/**
 * work_report.php — สรุปงานรายคนต่อรอบเดือน (21 เดือนก่อน – 20 เดือนนี้)
 *
 * รวมงานจาก 3 ระบบ: production (ฐานเรา) · งานซ่อม · งานเช่า (สองอันหลังอ่านอย่างเดียว)
 * และเป็นที่ตั้งค่าว่าจะส่งสรุปเข้าไลน์ให้ใครบ้าง
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/work_summary.php';
require_once __DIR__ . '/includes/work_summary_send.php';
require_login();

$B = BASE_URL;

// ── รอบที่กำลังดู ───────────────────────────────────────────────────────────
$anchor = trim((string) (isset($_GET['c']) ? $_GET['c'] : ''));
$cycle = work_summary_cycle($anchor !== '' ? $anchor : null);
$prev = work_summary_cycle_shift($cycle, -1);
$next = work_summary_cycle_shift($cycle, +1);

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $back = $B . '/work_report.php?c=' . urlencode($cycle['to']);

    if (isset($_POST['sync_directory'])) {
        $r = work_people_sync_directory();
        flash_set($r['added'] > 0
            ? 'เพิ่มชื่อใหม่เข้าทะเบียน ' . number_format($r['added']) . ' ชื่อ: ' . implode(', ', $r['names'])
            : 'ทะเบียนครบแล้ว ไม่มีชื่อใหม่ (พบทั้งหมด ' . number_format($r['total']) . ' ชื่อ)');
        header('Location: ' . $back); exit;
    }
    if (isset($_POST['toggle_notify'])) {
        work_people_set_notify((int) $_POST['person_id'], !empty($_POST['on']));
        header('Location: ' . $back . '#people'); exit;
    }
    if (isset($_POST['issue_code'])) {
        $code = work_people_issue_link_code((int) $_POST['person_id']);
        flash_set($code !== null
            ? 'รหัสผูกบัญชี: ' . $code . ' — ให้เจ้าตัวทักรหัสนี้ไปหาบอต LINE ภายใน 60 นาที'
            : 'ออกรหัสไม่สำเร็จ', $code !== null ? 'ok' : 'err');
        header('Location: ' . $back . '#people'); exit;
    }
    if (isset($_POST['unlink'])) {
        work_people_unlink((int) $_POST['person_id']);
        flash_set('ถอดการผูก LINE แล้ว');
        header('Location: ' . $back . '#people'); exit;
    }
    if (isset($_POST['send_summary'])) {
        $only = (int) ($_POST['only_person'] ?? 0);
        // กดส่งทีละคน ("ส่งทดสอบ") = สั่งเอง ต้องได้ส่งจริงทุกครั้ง ไม่ให้ dedup บล็อก
        $res = work_summary_send_cycle($cycle, $only > 0 ? $only : null, $only > 0);
        // เข้าคิวแล้วต้องรีดคิวออกตรงนี้เลย ไม่งั้นข้อความค้างรอ worker รอบถัดไป
        // (หน้าตั้งค่า LINE ก็ทำแบบเดียวกันหลังกดส่งทดสอบ)
        $sent = line_notify_process_outbox(60);
        $msg = 'ส่งเข้าคิว ' . number_format($res['queued']) . ' คน';
        if ($sent['sent'] > 0)   { $msg .= ' · ส่งถึงไลน์แล้ว ' . $sent['sent']; }
        if ($sent['failed'] > 0) { $msg .= ' · ส่งไม่ผ่าน ' . $sent['failed'] . ' (ระบบจะลองใหม่ให้)'; }
        if ($sent['dead'] > 0)   { $msg .= ' · ส่งไม่สำเร็จถาวร ' . $sent['dead'] . ' — ดูสาเหตุที่หน้าตั้งค่า LINE'; }
        if ($res['skipped_no_line'] > 0) { $msg .= ' · ข้าม (ยังไม่ผูก LINE) ' . $res['skipped_no_line']; }
        if ($res['skipped_no_work'] > 0) { $msg .= ' · ข้าม (ไม่มีงานในรอบ) ' . $res['skipped_no_work']; }
        if ($res['dedup'] > 0) {
            $msg .= ' · เคยส่งรอบนี้แล้ว ' . $res['dedup'];
            if ($res['queued'] === 0 && $sent['sent'] === 0) {
                $msg .= ' — ถ้าต้องการส่งซ้ำ ใช้ปุ่ม "ส่งทดสอบ" รายคนในตารางด้านล่าง';
            }
        }
        if (!empty($res['errors'])) { $msg .= ' · ปัญหา: ' . implode(' | ', $res['errors']); }
        flash_set($msg, (empty($res['errors']) && $sent['dead'] === 0) ? 'ok' : 'err');
        header('Location: ' . $back); exit;
    }
}

$summary = work_summary_for_cycle($cycle['from'], $cycle['to']);
$cats = work_summary_categories();
// โชว์เฉพาะหมวดที่มีงานจริงในรอบนี้ — 11 หมวดเต็มทำให้ตารางกว้างเกินอ่าน
$activeCats = array_filter($cats, function ($label, $key) use ($summary) {
    return !empty($summary['totals'][$key]);
}, ARRAY_FILTER_USE_BOTH);
$people = work_people_all();
// นับเฉพาะที่ผูกไว้กับ bot ตัวที่ใช้ส่งจริง — userId จาก bot อื่นส่งไปไม่ถึง ต้องผูกใหม่
$linkedOk = function ($p) {
    return !empty($p['line_user_id'])
        && (string) ($p['line_user_bot'] ?? 'main') === WORK_SUMMARY_LINE_BOT;
};
$linked = array_filter($people, $linkedOk);
$stale = array_filter($people, function ($p) use ($linkedOk) {
    return !empty($p['line_user_id']) && !$linkedOk($p);
});
$willSend = work_people_recipients(WORK_SUMMARY_LINE_BOT);
$willSendIds = [];
foreach ($willSend as $w) {
    $willSendIds[(int) $w['id']] = true;
}

page_header('สรุปงานรายคน', true, $cycle['label'] . ' · รวมงานจาก production · ซ่อม · เช่า');
?>
<div class="wr-cyclebar">
  <a class="btn btn-sm btn-line" href="<?= $B ?>/work_report.php?c=<?= h($prev['to']) ?>">‹ รอบก่อน</a>
  <b class="wr-cycle-label"><?= h($cycle['label']) ?></b>
  <a class="btn btn-sm btn-line" href="<?= $B ?>/work_report.php?c=<?= h($next['to']) ?>">รอบถัดไป ›</a>
  <a class="btn btn-sm btn-line" href="<?= $B ?>/work_report.php">รอบปัจจุบัน</a>
  <form method="post" class="wr-inline" onsubmit="return confirm('ส่งสรุปรอบ <?= h($cycle['label']) ?> เข้าไลน์?\n\nผู้รับ <?= count($willSend) ?> คน (เฉพาะคนที่เปิดส่งและผูก LINE แล้ว)')">
    <?= csrf_field() ?>
    <button type="submit" name="send_summary" value="1" class="btn btn-sm"
      <?= count($willSend) === 0 ? 'disabled title="ยังไม่มีใครพร้อมรับ — ต้องเปิดส่งและผูก LINE ก่อน"' : '' ?>>
      <?= ui_btn_label('bell', 'ส่ง LINE สรุปรอบนี้ (' . count($willSend) . ' คน)') ?>
    </button>
  </form>
</div>

<?php if ($summary['errors']) { ?>
<p class="muted wr-warn"><?= ui_icon_html('alert', 13) ?> ดึงข้อมูลบางส่วนไม่ได้: <?= h(implode(' · ', $summary['errors'])) ?> — ตัวเลขที่เห็นจึงยังไม่ครบทุกระบบ</p>
<?php } ?>

<?php if (!$summary['rows']) { ?>
<p class="muted">ยังไม่มีงานที่บันทึกในรอบนี้</p>
<?php } else { ?>
<div class="table-wrap">
<table class="list wr-table">
  <tr>
    <th>คน</th>
    <?php foreach ($activeCats as $k => $label) { ?><th class="wr-num"><?= h($label) ?></th><?php } ?>
    <th class="wr-num">รวม</th>
  </tr>
  <?php foreach ($summary['rows'] as $p) { ?>
  <tr>
    <td>
      <a class="wr-person" title="เปิดหน้าสรุปงานของคนนี้ — ดูว่าวันไหนทำอะไรบ้าง"
         href="<?= $B ?>/my_work.php?p=<?= (int) $p['id'] ?>&amp;c=<?= h($cycle['to']) ?>"><?= h($p['name']) ?></a>
      <?php $ready = isset($willSendIds[(int) $p['id']]);
      if ($p['line_user_id'] === '') { ?><span class="wr-tag wr-tag-off">ยังไม่ผูก LINE</span>
      <?php } elseif (!$p['notify']) { ?><span class="wr-tag wr-tag-mute">ปิดส่ง</span>
      <?php } elseif (!$ready) { ?><span class="wr-tag wr-tag-mute">ต้องผูกใหม่</span>
      <?php } else { ?><span class="wr-tag wr-tag-on">ส่ง</span><?php } ?>
    </td>
    <?php foreach ($activeCats as $k => $label) {
        $n = (int) ($p['counts'][$k] ?? 0); ?>
    <td class="wr-num<?= $n === 0 ? ' wr-zero' : '' ?>"><?= $n === 0 ? '–' : number_format($n) ?></td>
    <?php } ?>
    <td class="wr-num"><b><?= number_format($p['total']) ?></b></td>
  </tr>
  <?php } ?>
  <tr class="wr-total-row">
    <td>รวมทุกคน</td>
    <?php $grand = 0; foreach ($activeCats as $k => $label) { $n = (int) ($summary['totals'][$k] ?? 0); $grand += $n; ?>
    <td class="wr-num"><?= number_format($n) ?></td>
    <?php } ?>
    <td class="wr-num"><b><?= number_format($grand) ?></b></td>
  </tr>
</table>
</div>
<?php } ?>

<?php if ($summary['unmatched']) { ?>
<p class="muted wr-warn"><?= ui_icon_html('alert', 13) ?>
  มีชื่อที่ยังไม่อยู่ในทะเบียน จึงไม่ถูกนับ:
  <?= h(implode(' · ', array_map(function ($k, $v) { return $k . ' (' . number_format($v) . ')'; },
      array_keys($summary['unmatched']), $summary['unmatched']))) ?>
  — กด "อัปเดตทะเบียนชื่อ" ด้านล่างเพื่อเพิ่ม
</p>
<?php } ?>

<h2 id="people" class="wr-h2"><?= ui_icon_html('user', 18) ?> ทะเบียนคน &amp; การส่งไลน์
  <span class="muted wr-h2-meta"><?= count($linked) ?>/<?= count($people) ?> คนผูก LINE แล้ว · จะส่งจริง <?= count($willSend) ?> คน</span>
</h2>
<p class="muted wr-hint">ติ๊ก <b>ส่ง</b> เพื่อเลือกว่าจะส่งสรุปให้ใครบ้าง — คนที่ยังไม่ผูก LINE จะถูกข้ามแม้ติ๊กไว้
  · วิธีผูก: กด "ออกรหัส" แล้วให้เจ้าตัวทักรหัสนั้นไปหา <b>bot สำรอง</b> (bot ตัวจริงใช้ในกลุ่มอย่างเดียว)</p>
<?php if ($stale) { ?>
<p class="muted wr-warn"><?= ui_icon_html('alert', 13) ?>
  <?= count($stale) ?> คนผูกไว้กับ bot ตัวเดิม — LINE ให้ userId แยกตาม bot ของเดิมจึงส่งไม่ถึง
  ต้องกด "ออกรหัส" ให้ผูกใหม่ผ่าน bot สำรอง:
  <?= h(implode(' · ', array_map(function ($p) { return (string) $p['display_name']; }, $stale))) ?>
</p>
<?php } ?>
<form method="post" class="wr-inline wr-sync">
  <?= csrf_field() ?>
  <button type="submit" name="sync_directory" value="1" class="btn btn-sm btn-line"><?= ui_btn_label('refresh', 'อัปเดตทะเบียนชื่อ') ?></button>
</form>

<div class="table-wrap">
<table class="list wr-people">
  <tr><th>ชื่อ</th><th>ชื่อที่ใช้ในระบบต่าง ๆ</th><th>LINE</th><th class="wr-num">ส่ง</th><th></th></tr>
  <?php foreach ($people as $p) {
      $pid = (int) $p['id'];
      $hasLine = !empty($p['line_user_id']);
      $on = (int) ($p['notify_enabled'] ?? 1) === 1; ?>
  <tr>
    <td><b><?= h($p['display_name']) ?></b></td>
    <td class="muted wr-alias"><?= h((string) ($p['aliases'] ?? '')) ?></td>
    <td>
      <?php if ($hasLine && !$linkedOk($p)) { ?>
        <span class="wr-tag wr-tag-mute">ผูกกับ bot เดิม</span>
        <span class="muted">ต้องออกรหัสให้ผูกใหม่</span>
      <?php } elseif ($hasLine) { ?>
        <span class="wr-tag wr-tag-on">ผูกแล้ว</span>
        <?php if (!empty($p['line_display_name'])) { ?><span class="muted"><?= h($p['line_display_name']) ?></span><?php } ?>
      <?php } elseif (!empty($p['link_code']) && strtotime((string) $p['link_code_expires_at']) > time()) { ?>
        <code class="wr-code"><?= h($p['link_code']) ?></code>
        <span class="muted">รอทักบอต · หมดอายุ <?= h(date('H:i', strtotime((string) $p['link_code_expires_at']))) ?></span>
      <?php } else { ?>
        <span class="muted">ยังไม่ผูก</span>
      <?php } ?>
    </td>
    <td class="wr-num">
      <form method="post" class="wr-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="person_id" value="<?= $pid ?>">
        <input type="hidden" name="on" value="<?= $on ? '' : '1' ?>">
        <button type="submit" name="toggle_notify" value="1"
          class="wr-switch<?= $on ? ' is-on' : '' ?>"
          title="<?= $on ? 'กดเพื่อปิดการส่งให้คนนี้' : 'กดเพื่อเปิดการส่งให้คนนี้' ?>"
          aria-pressed="<?= $on ? 'true' : 'false' ?>"><span></span></button>
      </form>
    </td>
    <td class="wr-actions">
      <form method="post" class="wr-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="person_id" value="<?= $pid ?>">
        <?php // ผูกไว้กับ bot เดิมก็ยังต้องออกรหัสใหม่ได้ ไม่งั้นย้าย bot แล้วติดตาย
        if ($hasLine && $linkedOk($p)) { ?>
        <button type="submit" name="unlink" value="1" class="btn btn-sm btn-line"
          onclick="return confirm('ถอดการผูก LINE ของ <?= h($p['display_name']) ?>?')">ถอด</button>
        <?php } else { ?>
        <button type="submit" name="issue_code" value="1" class="btn btn-sm btn-line">ออกรหัส</button>
        <?php } ?>
      </form>
      <?php if (isset($willSendIds[$pid]) && $on) { ?>
      <form method="post" class="wr-inline"
        onsubmit="return confirm('ส่งสรุปรอบนี้ให้ <?= h($p['display_name']) ?> คนเดียว?')">
        <?= csrf_field() ?>
        <input type="hidden" name="only_person" value="<?= $pid ?>">
        <button type="submit" name="send_summary" value="1" class="btn btn-sm btn-line">ส่งทดสอบ</button>
      </form>
      <?php } ?>
    </td>
  </tr>
  <?php } ?>
</table>
</div>

<?php page_footer();
