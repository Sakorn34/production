<?php

/**

 * line_notify_settings.php — ตั้งค่าแจ้งเตือน LINE (ระบบหลังบ้าน)

 */

require __DIR__ . '/config.php';

require __DIR__ . '/includes/settings_gate.php';

require_settings_access();

require __DIR__ . '/includes/line_notify_settings.php';

require __DIR__ . '/includes/layout.php';



line_notify_ensure_schema();

if (!empty($_GET['saved'])) {
    line_notify_config(true);
}

$form = line_settings_form_defaults();

$saveMessages = [];

$testResult = null;

$sendNowResult = null;

$workerTestResult = null;

require_once dirname(__DIR__) . '/shared/finishgood_shortage_filter.php';
$catalog = line_notify_type_catalog();



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = (string)($_POST['action'] ?? 'save');



    if ($action === 'send_now') {

        $eventKey = (string)($_POST['send_event'] ?? '');

        $sendNowResult = line_notify_send_now($eventKey);

    } elseif ($action === 'test_worker') {

        $workerTestResult = line_plesk_test_worker(10);

    } elseif ($action === 'test') {

        $cfg = line_settings_parse_post($_POST);

        $testResult = line_settings_test_push($cfg);

        $form = array_merge($form, $cfg);

        $form['schedules'] = line_notify_schedules();

        $form['has_token'] = trim((string)$cfg['channel_access_token']) !== '';

        $form['has_secret'] = trim((string)$cfg['channel_secret']) !== '';

    } else {

        $cfg = line_settings_parse_post($_POST);

        $errors = line_settings_validate($cfg);

        if ($errors) {

            flash_set(implode(' · ', $errors), 'err');

            $form = array_merge($form, $cfg);

        } else {

            $save = line_settings_save($cfg);

            $saveMessages = $save['messages'];

            if ($save['ok']) {

                line_notify_config(true);

                flash_set('บันทึกการตั้งค่า LINE แล้ว', 'ok');

                header('Location: ' . BASE_URL . '/line_notify_settings.php?saved=1');

                exit;

            }

            flash_set('บันทึกไม่สำเร็จ', 'err');

            $form = array_merge($form, $cfg);

        }

    }

}



$outbox = line_notify_recent_outbox(15);

$outboxSummary = line_settings_outbox_summary($outbox);

$secretsPath = app_line_secrets_path();

$secretsExists = is_file($secretsPath);

$tokenPh = LINE_SETTINGS_TOKEN_PLACEHOLDER;

$pleskDiag = line_plesk_task_diagnostics();



page_header('ตั้งค่าแจ้งเตือน LINE');

?>

<style>

.ln-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; margin-bottom:16px; }

.ln-card { border:1px solid var(--border,var(--border, #e5e7eb)); border-radius:10px; padding:12px 14px; font-size:13px; }

.ln-card.ok { border-color:#86efac; background:#f0fdf4; }

.ln-card.fail { border-color:#fecaca; background:#fef2f2; }

.ln-note { background:#eff6ff; border-left:3px solid #3b82f6; padding:10px 12px; border-radius:0 8px 8px 0; font-size:12.5px; color:#1e40af; margin-bottom:14px; }

.ln-types select { padding:6px 8px; font-size:calc(13px * var(--font-scale, 1)); min-width:100px; min-height:34px; }

.ln-instant { color:#6b7280; font-size:calc(12.5px * var(--font-scale, 1)); }

.ln-delivery-select { padding:6px 8px; font-size:calc(13px * var(--font-scale, 1)); min-width:148px; min-height:34px; }

.ln-send-form { display:inline; margin:0; }

.ln-plesk-path { font-family:Consolas,'Courier New',monospace; font-size:11px; overflow-wrap:anywhere; color:var(--text, #374151); line-height:1.35; }

.ln-plesk-hint { font-size:11px; color:#6b7280; margin-top:2px; }

.ln-outbox-panel { margin-bottom:16px; }

.ln-outbox-summary { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }

.ln-outbox-stat { font-size:12px; padding:5px 12px; border-radius:999px; font-weight:600; border:1px solid transparent; }

.ln-outbox-stat--sent { background:#ecfdf5; color:#047857; border-color:#a7f3d0; }

.ln-outbox-stat--pending { background:#fffbeb; color:#b45309; border-color:#fde68a; }

.ln-outbox-stat--failed { background:#fff7ed; color:#c2410c; border-color:#fed7aa; }

.ln-outbox-stat--dead { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }

.ln-outbox-list { display:flex; flex-direction:column; gap:10px; }

.ln-outbox-item { border:1px solid var(--border,var(--border, #e5e7eb)); border-radius:10px; padding:12px 14px; background:#fff; }

.ln-outbox-item.ln-ob-sent { border-left:3px solid #22c55e; }

.ln-outbox-item.ln-ob-pending { border-left:3px solid #f59e0b; }

.ln-outbox-item.ln-ob-failed { border-left:3px solid #f97316; }

.ln-outbox-item.ln-ob-dead { border-left:3px solid #ef4444; }

.ln-outbox-head { display:flex; flex-wrap:wrap; align-items:center; gap:8px 10px; margin-bottom:6px; }

.ln-outbox-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; }

.ln-outbox-badge.ln-ob-sent { background:#dcfce7; color:#166534; }

.ln-outbox-badge.ln-ob-pending { background:#fef3c7; color:#92400e; }

.ln-outbox-badge.ln-ob-failed { background:#ffedd5; color:#9a3412; }

.ln-outbox-badge.ln-ob-dead { background:#fee2e2; color:#991b1b; }

.ln-outbox-title { font-size:14px; flex:1 1 auto; min-width:140px; }

.ln-outbox-id { font-size:11px; color:#9ca3af; }

.ln-outbox-meta { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:4px 16px; font-size:12.5px; color:var(--text-muted, #4b5563); }

.ln-outbox-meta b { color:var(--text, #374151); font-weight:600; }

.ln-outbox-error { margin-top:8px; padding:8px 10px; border-radius:6px; background:#fef2f2; color:#991b1b; font-size:12px; line-height:1.45; word-break:break-word; }

.ln-outbox-empty { font-size:13px; color:#6b7280; padding:8px 0; }

.ln-plesk-check-panel { margin-bottom:16px; }

.ln-plesk-status { font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; display:inline-block; }

.ln-plesk-status--ok { background:#dcfce7; color:#166534; }

.ln-plesk-status--stale { background:#fef3c7; color:#92400e; }

.ln-plesk-status--needs { background:#fee2e2; color:#991b1b; }

.ln-plesk-status--skip { background:#f3f4f6; color:#6b7280; }

.ln-plesk-status--failed { background:#ffedd5; color:#9a3412; }

.ln-plesk-recipe { font-size:11.5px; color:var(--text-muted, #4b5563); line-height:1.45; }

.ln-plesk-check .tbl { font-size:12.5px; }

.ln-plesk-check .tbl td { vertical-align:top; }

.ln-form { font-size: calc(13.5px * var(--font-scale, 1)); }

.ln-form h3 { margin: 0 0 12px; font-size: calc(14px * var(--font-scale, 1)); color: var(--primary); font-weight: 700; }

.ln-form h3.ln-form-section { margin-top: 20px; }

.ln-form-field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; max-width: 640px; }

.ln-form-field > label,
.ln-form-grid label { display: flex; flex-direction: column; gap: 4px; font-size: calc(13px * var(--font-scale, 1)); color: var(--text-muted, #45506a); font-weight: 500; line-height: 1.4; }

.ln-form-grid { display: grid; grid-template-columns: 1fr; gap: 12px; max-width: 640px; margin-bottom: 20px; }

.ln-form-grid input[type=text],
.ln-form-grid input[type=password],
.ln-form-grid input[type=url],
.ln-form-field input[type=text] {
  width: 100%; box-sizing: border-box;
  padding: 7px 10px; border: 1px solid var(--border, #c9d2e0); border-radius: 6px;
  font-family: inherit; font-size: calc(14px * var(--font-scale, 1));
  min-height: 36px; background: #fff;
}

.ln-form-grid input:focus,
.ln-form-field input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(225,29,116,.13); }

.ln-form-check { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; font-size: calc(13.5px * var(--font-scale, 1)); color: var(--text, #374151); }

.ln-form-check input[type=checkbox] { width: 16px; height: 16px; flex-shrink: 0; }

.ln-form-help { font-size: calc(12.5px * var(--font-scale, 1)); margin: 0 0 10px; line-height: 1.5; }

.ln-hint { font-size: calc(12px * var(--font-scale, 1)); line-height: 1.45; }

.ln-types .tbl { width: 100%; border-collapse: collapse; font-size: calc(13px * var(--font-scale, 1)); }

.ln-types .tbl th { background: #eef1f6; text-align: left; padding: 8px 10px; font-size: calc(13px * var(--font-scale, 1)); color: var(--text-muted, #45506a); font-weight: 600; white-space: nowrap; border-bottom: 1px solid var(--border, #dfe4ec); }

.ln-types .tbl td { padding: 9px 10px; border-top: 1px solid #eceff4; vertical-align: middle; line-height: 1.45; }

.ln-types .tbl tbody tr:first-child td { border-top: 0; }

</style>



<div class="ln-note">

  <b>LINE Messaging API</b> — เลือกวิธีส่งแต่ละประเภท (ทันที / ตามเวลา / ทั้งสอง) แล้วกด <b>บันทึก</b><br>

  <b>Plesk:</b> ตั้ง Scheduled Task <b>รายการละ 1 task</b> — Run a PHP script ตามคอลัมน์ <b>Plesk script</b> · ตั้งเวลา/วันใน Plesk เท่านั้น · PHP 8.2

</div>



<div class="ln-grid">

  <div class="ln-card <?= $secretsExists ? 'ok' : 'fail' ?>">

    <b>ไฟล์ secrets</b>

    <span class="muted" style="overflow-wrap:anywhere; font-size:12px"><?= h($secretsPath) ?></span>

  </div>

  <div class="ln-card <?= line_notify_is_enabled() ? 'ok' : 'fail' ?>">

    <b>สถานะระบบ</b>

    <?= line_notify_is_enabled() ? 'เปิดใช้งาน' : 'ปิด / ยังไม่ตั้งค่า' ?>

  </div>

</div>



<div class="panel ln-plesk-check-panel ln-plesk-check">

  <h3 style="margin:0 0 4px; font-size:15px; color:var(--primary)">ตรวจสอบ Plesk Scheduled Task</h3>

  <p class="muted" style="font-size:12px; margin:0 0 12px">อนุมานจาก config + line-cron.log — PHP ไม่สามารถอ่าน task ใน Plesk โดยตรง</p>

  <div class="ln-grid">

    <div class="ln-card <?= !empty($pleskDiag['system_enabled']) ? 'ok' : 'fail' ?>">

      <b>ระบบ LINE</b>

      <?= !empty($pleskDiag['system_enabled']) ? 'เปิดใช้งาน' : 'ปิด / ยังไม่ตั้งค่า' ?>

    </div>

    <div class="ln-card <?= ($pleskDiag['tasks_required'] > 0 && $pleskDiag['tasks_ok'] >= $pleskDiag['tasks_required']) ? 'ok' : (($pleskDiag['tasks_required'] > 0) ? 'fail' : 'ok') ?>">

      <b>Task ที่ต้องตั้ง</b>

      <?= (int)$pleskDiag['tasks_ok'] ?> / <?= (int)$pleskDiag['tasks_required'] ?> ตรวจแล้วปกติ

    </div>

    <div class="ln-card <?= !empty($pleskDiag['log_exists']) ? 'ok' : 'fail' ?>">

      <b>ไฟล์ log</b>

      <span class="muted" style="overflow-wrap:anywhere; font-size:11px"><?= h((string)$pleskDiag['log_path']) ?></span>

      <?= !empty($pleskDiag['log_exists']) ? ' · พบแล้ว' : ' · ยังไม่มี' ?>

    </div>

    <?php if (($pleskDiag['outbox']['pending'] ?? 0) > 0 || ($pleskDiag['outbox']['failed'] ?? 0) > 0) { ?>

    <div class="ln-card fail">

      <b>Outbox</b>

      <?php if (($pleskDiag['outbox']['pending'] ?? 0) > 0) { ?>รอส่ง <?= (int)$pleskDiag['outbox']['pending'] ?><?php } ?>

      <?php if (($pleskDiag['outbox']['failed'] ?? 0) > 0) { ?> · ล้มเหลว <?= (int)$pleskDiag['outbox']['failed'] ?><?php } ?>

    </div>

    <?php } ?>

  </div>

  <div style="overflow-x:auto; margin-bottom:12px">

    <table class="tbl">

      <thead>

        <tr>

          <th>ประเภท</th>

          <th>ต้องตั้ง?</th>

          <th>Script path</th>

          <th>แนะนำ Plesk</th>

          <th>รันล่าสุด</th>

          <th>สถานะ</th>

        </tr>

      </thead>

      <tbody>

        <?php foreach ($pleskDiag['tasks'] as $task) {

            $st = (string)($task['status'] ?? 'needs_setup');

            $stClass = line_plesk_status_class($st);

        ?>

        <tr>

          <td><b><?= h((string)$task['label']) ?></b></td>

          <td><?= !empty($task['required']) ? 'ใช่' : 'ไม่' ?></td>

          <td><div class="ln-plesk-path"><?= h((string)$task['script_path']) ?></div></td>

          <td>

            <div class="ln-plesk-recipe"><?= h((string)($task['recommended']['recipe'] ?? '')) ?></div>

            <div class="ln-plesk-hint"><?= h((string)$task['plesk_hint']) ?></div>

          </td>

          <td><?= h(line_settings_outbox_format_time($task['last_run_at'] ?? null)) ?></td>

          <td>

            <span class="ln-plesk-status <?= h($stClass) ?>" title="<?= h((string)$task['status_message']) ?>"><?= h(line_plesk_status_label($st)) ?></span>

          </td>

        </tr>

        <?php } ?>

      </tbody>

    </table>

  </div>

  <div class="ln-note" style="margin-bottom:12px">

    <b>คำแนะนำ Plesk</b><br>

    · ลบ/ปิด task เก่า: <code>plesk_line_tick.php</code>, <code>line_notify_scheduled.php --job=tick</code><br>

    <?php if (!empty($pleskDiag['needs_instant_worker'])) { ?>

    · มี event แบบ <b>ทันที</b> — พิจารณา worker สำรอง <code>production/finishgoogs_ma_update/cron/plesk_line_worker.php</code> ทุก 2 นาที (Cron <code>*/2 * * * *</code>)<br>

    <?php } ?>

    · ทดสอบ: กด <b>Run Now</b> ใน Plesk → ควรได้ JSON เช่น <code>{"job":"daily","result":{...}}</code>

  </div>

  <form method="post" style="margin:0">

    <?= csrf_field() ?>

    <input type="hidden" name="action" value="test_worker">

    <button type="submit" class="btn btn-sm btn-line" <?= line_notify_is_enabled() ? '' : 'disabled' ?>>ทดสอบ worker (process outbox)</button>

  </form>

</div>



<?php if ($workerTestResult) { ?>

<div class="panel" style="margin-bottom:14px; font-size:13px; border-color:<?= $workerTestResult['ok'] ? '#86efac' : '#fecaca' ?>">

  <b><?= $workerTestResult['ok'] ? '✓' : '✗' ?> <?= h($workerTestResult['message']) ?></b>

  <?php if (!empty($workerTestResult['detail'])) { ?><div class="muted"><?= h($workerTestResult['detail']) ?></div><?php } ?>

</div>

<?php } ?>



<?php if ($saveMessages) { ?>

<div class="panel" style="margin-bottom:14px; font-size:13px">

  <?php foreach ($saveMessages as $m) { ?><div><?= h($m) ?></div><?php } ?>

</div>

<?php } ?>



<?php if ($testResult) { ?>

<div class="panel" style="margin-bottom:14px; font-size:13px; border-color:<?= $testResult['ok'] ? '#86efac' : '#fecaca' ?>">

  <b><?= $testResult['ok'] ? '✓' : '✗' ?> <?= h($testResult['message']) ?></b>

  <?php if (!empty($testResult['detail'])) { ?><div class="muted"><?= h($testResult['detail']) ?></div><?php } ?>

</div>

<?php } ?>



<?php if ($sendNowResult) { ?>

<div class="panel" style="margin-bottom:14px; font-size:13px; border-color:<?= $sendNowResult['ok'] ? '#86efac' : '#fecaca' ?>">

  <b><?= $sendNowResult['ok'] ? '✓' : '✗' ?> <?= h($sendNowResult['message']) ?></b>

  <?php if (!empty($sendNowResult['detail'])) { ?><div class="muted"><?= h($sendNowResult['detail']) ?></div><?php } ?>

</div>

<?php } ?>



<div class="panel ln-outbox-panel">

  <h3 style="margin:0 0 4px; font-size:15px; color:var(--primary)">คิวส่ง LINE ล่าสุด</h3>

  <p class="muted" style="font-size:12px; margin:0 0 12px">รายการ 15 รายการล่าสุด — ดูว่าส่งสำเร็จหรือมีปัญหาอะไร</p>

  <?php if (!$outbox) { ?>

    <p class="ln-outbox-empty">ยังไม่มีรายการในคิว</p>

  <?php } else { ?>

  <div class="ln-outbox-summary">

    <?php if ($outboxSummary['sent'] > 0) { ?>

      <span class="ln-outbox-stat ln-outbox-stat--sent">ส่งแล้ว <?= (int)$outboxSummary['sent'] ?></span>

    <?php } ?>

    <?php if ($outboxSummary['pending'] > 0) { ?>

      <span class="ln-outbox-stat ln-outbox-stat--pending">รอส่ง <?= (int)$outboxSummary['pending'] ?></span>

    <?php } ?>

    <?php if ($outboxSummary['failed'] > 0) { ?>

      <span class="ln-outbox-stat ln-outbox-stat--failed">ล้มเหลว <?= (int)$outboxSummary['failed'] ?></span>

    <?php } ?>

    <?php if ($outboxSummary['dead'] > 0) { ?>

      <span class="ln-outbox-stat ln-outbox-stat--dead">ส่งไม่ได้ <?= (int)$outboxSummary['dead'] ?></span>

    <?php } ?>

  </div>

  <div class="ln-outbox-list">

    <?php foreach ($outbox as $row) {

        $status = (string)($row['status'] ?? 'pending');

        $statusClass = line_settings_outbox_status_class($status);

        $label = line_settings_outbox_event_label((string)$row['event_key'], $catalog);

        $attempts = (int)($row['attempts'] ?? 0);

        $lastError = trim((string)($row['last_error'] ?? ''));

    ?>

    <article class="ln-outbox-item <?= h($statusClass) ?>">

      <div class="ln-outbox-head">

        <span class="ln-outbox-badge <?= h($statusClass) ?>"><?= h(line_settings_outbox_status_label($status)) ?></span>

        <strong class="ln-outbox-title"><?= h($label) ?></strong>

        <span class="ln-outbox-id">#<?= (int)$row['id'] ?></span>

      </div>

      <div class="ln-outbox-meta">

        <span><b>เข้าคิว</b> <?= h(line_settings_outbox_format_time($row['created_at'] ?? null)) ?></span>

        <span><b>ส่งเมื่อ</b> <?= h(line_settings_outbox_format_time($row['sent_at'] ?? null)) ?></span>

        <?php if ($attempts > 1) { ?>

          <span><b>ลองส่ง</b> <?= $attempts ?> ครั้ง</span>

        <?php } ?>

      </div>

      <?php if ($lastError !== '') { ?>

        <div class="ln-outbox-error"><b>สาเหตุ:</b> <?= h($lastError) ?></div>

      <?php } ?>

    </article>

    <?php } ?>

  </div>

  <?php } ?>

</div>



<form method="post" class="panel ln-form" style="margin-bottom:16px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <?php if (function_exists('line_notify_test_mode') && line_notify_test_mode()) { ?>
  <div class="ln-testmode-on">
    <b>โหมดทดสอบเปิดอยู่</b> — แจ้งเตือนทุกอย่างรวมทั้งงานตามเวลาถูกส่งเข้าห้องทดสอบ กลุ่มจริงจะไม่ได้รับ
  </div>
  <?php } ?>

  <?php // แยกเป็นหมวดตาม "ของใคร/ใช้ทำอะไร" — เดิมกอง token ของ bot สองตัว, URL ของเว็บ
        // และ token ของระบบอื่นไว้ในลิสต์เดียวกัน หาแล้วไม่รู้ว่าช่องไหนของอะไร ?>
  <h3>ตั้งค่าระบบ</h3>
  <div class="ln-form-field">
    <label for="line_secrets_path">Path ไฟล์ line.secrets.php</label>
    <input type="text" id="line_secrets_path" name="line_secrets_path" value="<?= h($form['line_secrets_path']) ?>" required>
  </div>
  <label class="ln-form-check">
    <input type="checkbox" name="line_enabled" value="1" <?= !empty($form['enabled']) ? 'checked' : '' ?>>
    <span>เปิดใช้งานแจ้งเตือน LINE</span>
  </label>

  <h3 class="ln-form-section">bot ตัวจริง — ส่งเข้ากลุ่ม</h3>
  <p class="muted ln-form-help">ใช้ส่งแจ้งเตือนทุกประเภทเข้ากลุ่มงาน · ไม่ได้เปิด webhook</p>
  <div class="ln-form-grid">
    <label>Channel Access Token
      <input type="password" name="channel_access_token" value="<?= !empty($form['has_token']) ? h($tokenPh) : '' ?>" placeholder="วาง token ใหม่ หรือเว้นว่างถ้าไม่เปลี่ยน" autocomplete="off">
    </label>
    <label>Channel Secret
      <input type="password" name="channel_secret" value="<?= !empty($form['has_secret']) ? h($tokenPh) : '' ?>" placeholder="ไม่บังคับ" autocomplete="off">
      <span class="muted ln-hint">ไม่ได้ใช้ — การผูกไลน์รายคนใช้ Channel Secret ของ bot สำรองแทน</span>
    </label>
    <label>Group / User ID ผู้รับหลัก
      <input type="text" name="default_recipient_id" value="<?= h($form['default_recipient_id']) ?>" placeholder="Cxxxxxxxx..." required>
    </label>
  </div>

  <h3 class="ln-form-section">bot สำรอง — ส่งหาไลน์ส่วนตัว</h3>
  <p class="muted ln-form-help">ทำ 2 หน้าที่ — ส่ง "สรุปงานรายคน" เข้าไลน์ส่วนตัวเสมอ (bot ตัวจริงใช้ในกลุ่มอย่างเดียว)
    และเป็นห้องปลายทางตอนเปิดโหมดทดสอบ</p>
  <div class="ln-form-grid">
    <label>Channel Access Token
      <input type="password" name="test_channel_access_token" value="<?= !empty($form['has_test_token']) ? h($tokenPh) : '' ?>" placeholder="ของ bot อีกตัวที่ใช้ส่งหาไลน์ส่วนตัว" autocomplete="off">
    </label>
    <label>Channel Secret
      <input type="password" name="test_channel_secret" value="<?= !empty($form['has_test_secret']) ? h($tokenPh) : '' ?>" placeholder="ต้องใส่ถ้าจะให้พนักงานผูกไลน์เอง" autocomplete="off">
      <span class="muted ln-hint">ใช้ตรวจลายเซ็น webhook ตอนพนักงานทักรหัสมาผูกไลน์
        — Webhook URL ต้องตั้งที่ channel ของ bot สำรอง ไม่ใช่ตัวจริง</span>
    </label>
    <label>Group / User ID ห้องทดสอบ
      <input type="text" name="test_recipient_id" value="<?= h($form['test_recipient_id']) ?>" placeholder="ห้องที่ bot สำรองอยู่">
    </label>
  </div>
  <label class="ln-form-check">
    <input type="checkbox" name="test_mode" value="1" <?= !empty($form['test_mode']) ? 'checked' : '' ?>>
    <span>โหมดทดสอบ — ส่งเข้าห้องทดสอบแทนกลุ่มจริงทั้งหมด</span>
  </label>

  <h3 class="ln-form-section">ลิงก์และรูปในข้อความ</h3>
  <p class="muted ln-form-help">งานตามเวลารันแบบ CLI ไม่มีชื่อโดเมนให้เดา ถ้าไม่ตั้งค่าตรงนี้
    รูปที่อัปเองจะไม่ขึ้นและปุ่มในข้อความจะกดไม่ได้</p>
  <div class="ln-form-grid">
    <label>โดเมนสาธารณะของเว็บ (สำหรับรูปใน LINE)
      <input type="url" name="public_site_host" value="<?= h($form['public_site_host']) ?>" placeholder="https://example.com — ต้องเป็น https ไม่งั้นรูปที่อัปเองจะไม่ขึ้น">
    </label>
    <label>URL Production (deep link)
      <input type="url" name="public_production_url" value="<?= h($form['public_production_url']) ?>" placeholder="https://.../finishgoogs_ma_update">
    </label>
    <label>URL Parts (deep link)
      <input type="url" name="public_parts_url" value="<?= h($form['public_parts_url']) ?>" placeholder="https://.../parts">
    </label>
  </div>

  <h3 class="ln-form-section">เชื่อมต่อระบบอื่น</h3>
  <p class="muted ln-form-help">ดึงยอดสินค้าที่ต้องผลิตเพิ่มมาจาก setupsystem — production ไม่ได้คำนวณเอง</p>
  <div class="ln-form-grid">
    <label>URL API สินค้าที่ต้องผลิตเพิ่ม
      <input type="url" name="finishgood_shortage_api_url" value="<?= h($form['finishgood_shortage_api_url']) ?>" placeholder="เว้นว่าง = ใช้ค่าตั้งต้นของระบบ">
    </label>
    <label>Token API สินค้าที่ต้องผลิตเพิ่ม
      <input type="password" name="finishgood_shortage_api_token" value="<?= !empty($form['has_shortage_token']) ? h($tokenPh) : '' ?>" placeholder="ต้องตรงกับ FINISHGOOD_SHORTAGE_API_TOKEN ฝั่ง setupsystem" autocomplete="off">
    </label>
  </div>


  <h3 class="ln-form-section">ประเภทแจ้งเตือน · วิธีส่ง · Plesk</h3>

  <p class="muted ln-form-help">

    <b>ทันที</b> = ส่งเมื่อเกิดเหตุการณ์ · <b>ตามเวลา</b> = Plesk trigger ตาม script · <b>ทั้งสอง</b> = ใช้ได้ทั้งสองแบบ (เช่น อะไหล่ควรสั่งเพิ่ม)

  </p>



  <div class="ln-types" style="overflow-x:auto">

    <table class="tbl">

      <thead>

        <tr>

          <th>เปิด</th>

          <th>ประเภท</th>

          <th>วิธีส่ง</th>

          <th>Plesk script</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($catalog as $eventKey => $meta) {

            $fk = line_settings_field_key($eventKey);

            $canInstant = !empty($meta['can_instant']);

            $canScheduled = !empty($meta['can_scheduled']);

            $sched = $form['schedules'][$eventKey] ?? [];

            $delivery = line_notify_normalize_delivery($eventKey, (string)($sched['delivery'] ?? 'instant'));

            $checked = !empty($form['events'][$eventKey]);

            $schedActive = $canScheduled && ($delivery === 'scheduled' || $delivery === 'both');

        ?>

        <tr>

          <td style="text-align:center">

            <input type="checkbox" name="event_<?= h($fk) ?>" value="1" <?= $checked ? 'checked' : '' ?>>

          </td>

          <td>

            <b><?= h($meta['label']) ?></b>

            <?php if ($eventKey === 'finishgood.shortage') {
                // หน้าเลือกรุ่นอยู่คนละหน้าและไม่มีลิงก์ในเมนู — พาไปจากตรงนี้
                $fgSkip = function_exists('fg_shortage_skipped_codes') ? fg_shortage_skipped_codes() : [];
            ?>

            <a class="ln-pick-models" href="<?= h(BASE_URL . '/finishgood_shortage_preview.php') ?>">เลือกรุ่นที่จะแจ้ง<?= $fgSkip ? ' (ปิดไว้ ' . count($fgSkip) . ' รุ่น)' : '' ?> &rarr;</a>

            <?php } ?>

          </td>

          <td>

            <?php if ($canInstant && $canScheduled) { ?>

              <select name="sched_delivery_<?= h($fk) ?>" class="ln-delivery-select">

                <option value="instant" <?= $delivery === 'instant' ? 'selected' : '' ?>>ทันทีเมื่อเกิดเหตุการณ์</option>

                <option value="scheduled" <?= $delivery === 'scheduled' ? 'selected' : '' ?>>ตามเวลา</option>

                <option value="both" <?= $delivery === 'both' ? 'selected' : '' ?>>ทั้งสองแบบ</option>

              </select>

            <?php } elseif ($canScheduled) { ?>

              <input type="hidden" name="sched_delivery_<?= h($fk) ?>" value="scheduled">

              <span class="chip" style="background:#eff6ff;color:#1d4ed8">ตามเวลา</span>

            <?php } else { ?>

              <input type="hidden" name="sched_delivery_<?= h($fk) ?>" value="instant">

              <span class="ln-instant">ทันทีเมื่อเกิดเหตุการณ์</span>

            <?php } ?>

          </td>

          <td>
            <?php
            $pleskScript = line_notify_plesk_script_name($eventKey);
            if ($canScheduled && $pleskScript !== null && $schedActive) {
                $pleskPath = line_notify_plesk_script_path($eventKey);
                $pleskRun = line_notify_plesk_run_hint($eventKey);
            ?>
              <div class="ln-plesk-path"><?= h($pleskPath) ?></div>
              <div class="ln-plesk-hint"><?= h($pleskRun) ?></div>
            <?php } else { ?>
              <span class="ln-instant">—</span>
            <?php } ?>
          </td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:16px">

    <button type="submit" class="btn btn-primary">บันทึกการตั้งค่า</button>

    <button type="submit" name="action" value="test" class="btn btn-line">ทดสอบส่ง LINE (ข้อความทั่วไป)</button>

  </div>

</form>



<div class="panel ln-types" style="margin-bottom:16px">

  <h3 style="margin:0 0 10px; font-size:15px">ส่งทันที (แต่ละประเภท)</h3>

  <p class="muted" style="font-size:12px; margin-bottom:12px">กดแล้วระบบ enqueue และส่ง LINE ทันที (ไม่รอ cron)</p>

  <table class="tbl">

    <thead>

      <tr><th>ประเภท</th><th></th></tr>

    </thead>

    <tbody>

      <?php foreach ($catalog as $eventKey => $meta) { ?>

      <tr>

        <td><?= h($meta['label']) ?></td>

        <td>

          <form method="post" class="ln-send-form">

            <?= csrf_field() ?>

            <input type="hidden" name="action" value="send_now">

            <input type="hidden" name="send_event" value="<?= h($eventKey) ?>">

            <button type="submit" class="btn btn-sm btn-line" <?= line_notify_is_enabled() ? '' : 'disabled' ?>>ส่งทันที</button>

          </form>

        </td>

      </tr>

      <?php } ?>

    </tbody>

  </table>

</div>



<?php page_footer(); ?>
