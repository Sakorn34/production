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



$form = line_settings_form_defaults();

$saveMessages = [];

$testResult = null;

$sendNowResult = null;

$catalog = line_notify_type_catalog();

$weekdays = line_notify_weekday_options();



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    csrf_check();

    $action = (string)($_POST['action'] ?? 'save');



    if ($action === 'send_now') {

        $eventKey = (string)($_POST['send_event'] ?? '');

        $sendNowResult = line_notify_send_now($eventKey);

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



$outbox = line_notify_recent_outbox(12);

$secretsPath = app_line_secrets_path();

$secretsExists = is_file($secretsPath);

$tokenPh = LINE_SETTINGS_TOKEN_PLACEHOLDER;



page_header('ตั้งค่าแจ้งเตือน LINE');

?>

<style>

.ln-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:10px; margin-bottom:16px; }

.ln-card { border:1px solid var(--border,#e5e7eb); border-radius:10px; padding:12px 14px; font-size:13px; }

.ln-card.ok { border-color:#86efac; background:#f0fdf4; }

.ln-card.fail { border-color:#fecaca; background:#fef2f2; }

.ln-note { background:#eff6ff; border-left:3px solid #3b82f6; padding:10px 12px; border-radius:0 8px 8px 0; font-size:12.5px; color:#1e40af; margin-bottom:14px; }

.ln-types .tbl { font-size:13px; }

.ln-types input[type=time] { padding:4px 6px; font-size:13px; }

.ln-types select { padding:4px 6px; font-size:13px; min-width:100px; }

.ln-instant { color:#6b7280; font-size:12px; }

.ln-send-form { display:inline; margin:0; }

</style>



<div class="ln-note">

  <b>LINE Messaging API</b> — ตั้งเวลาส่งแต่ละประเภทด้านล่าง แล้วกด <b>บันทึก</b>

  · Cron แนะนำรัน <code>line_notify_scheduled.php --job=tick</code> ทุก 1 นาที

  · Worker <code>line_notify_worker.php</code> ทุก 2 นาที (ดู <code>cron/README.md</code>)

</div>



<div class="ln-grid">

  <div class="ln-card <?= $secretsExists ? 'ok' : 'fail' ?>">

    <b>ไฟล์ secrets</b>

    <span class="muted" style="word-break:break-all; font-size:12px"><?= h($secretsPath) ?></span>

  </div>

  <div class="ln-card <?= line_notify_is_enabled() ? 'ok' : 'fail' ?>">

    <b>สถานะระบบ</b>

    <?= line_notify_is_enabled() ? 'เปิดใช้งาน' : 'ปิด / ยังไม่ตั้งค่า' ?>

  </div>

</div>



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



<form method="post" class="panel" style="margin-bottom:16px">

  <?= csrf_field() ?>

  <input type="hidden" name="action" value="save">



  <h3 style="margin:0 0 12px; font-size:15px; color:var(--primary)">การเชื่อมต่อ LINE</h3>



  <label style="display:block; margin-bottom:10px">Path ไฟล์ line.secrets.php

    <input type="text" name="line_secrets_path" value="<?= h($form['line_secrets_path']) ?>" style="width:100%; max-width:640px" required>

  </label>



  <label style="display:flex; align-items:center; gap:8px; margin-bottom:12px">

    <input type="checkbox" name="line_enabled" value="1" <?= !empty($form['enabled']) ? 'checked' : '' ?>>

    <b>เปิดใช้งานแจ้งเตือน LINE</b>

  </label>



  <div class="form-grid" style="display:grid; grid-template-columns:1fr; gap:10px; max-width:640px; margin-bottom:20px">

    <label>Channel Access Token

      <input type="password" name="channel_access_token" value="<?= !empty($form['has_token']) ? h($tokenPh) : '' ?>" placeholder="วาง token ใหม่ หรือเว้นว่างถ้าไม่เปลี่ยน" autocomplete="off">

    </label>

    <label>Channel Secret

      <input type="password" name="channel_secret" value="<?= !empty($form['has_secret']) ? h($tokenPh) : '' ?>" placeholder="ไม่บังคับ" autocomplete="off">

    </label>

    <label>Group / User ID ผู้รับหลัก

      <input type="text" name="default_recipient_id" value="<?= h($form['default_recipient_id']) ?>" placeholder="Cxxxxxxxx..." required>

    </label>

    <label>URL Production (deep link)

      <input type="url" name="public_production_url" value="<?= h($form['public_production_url']) ?>" placeholder="https://.../finishgoogs_ma_update">

    </label>

    <label>URL Parts (deep link)

      <input type="url" name="public_parts_url" value="<?= h($form['public_parts_url']) ?>" placeholder="https://.../parts">

    </label>

  </div>



  <h3 style="margin:0 0 10px; font-size:15px; color:var(--primary)">ประเภทแจ้งเตือน · เวลาส่ง · เปิด/ปิด</h3>

  <p class="muted" style="font-size:12px; margin-bottom:10px">

    ประเภท <b>ทันที</b> ส่งเมื่อเกิดเหตุการณ์ในระบบ · ปุ่ม <b>ส่งทันที</b> ใช้ข้อมูลล่าสุดใน DB เป็นตัวอย่าง

  </p>



  <div class="ln-types" style="overflow-x:auto">

    <table class="tbl">

      <thead>

        <tr>

          <th>เปิด</th>

          <th>ประเภท</th>

          <th>วิธีส่ง</th>

          <th>เวลา (HH:MM)</th>
          <th>วัน (รายสัปดาห์)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($catalog as $eventKey => $meta) {

            $fk = line_settings_field_key($eventKey);

            $isScheduled = (($meta['mode'] ?? '') === 'scheduled');

            $sched = $form['schedules'][$eventKey] ?? [];

            $timeVal = h((string)($sched['time'] ?? ($meta['default_time'] ?? '08:00')));

            $weekdayVal = (int)($sched['weekday'] ?? ($meta['default_weekday'] ?? 0));

            $checked = !empty($form['events'][$eventKey]);

        ?>

        <tr>

          <td style="text-align:center">

            <input type="checkbox" name="event_<?= h($fk) ?>" value="1" <?= $checked ? 'checked' : '' ?>>

          </td>

          <td><b><?= h($meta['label']) ?></b></td>

          <td>

            <?php if ($isScheduled) { ?>

              <span class="chip" style="background:#eff6ff;color:#1d4ed8">ตามเวลา</span>

            <?php } else { ?>

              <span class="ln-instant">ทันทีเมื่อเกิดเหตุการณ์</span>

            <?php } ?>

          </td>

          <td>

            <?php if ($isScheduled) { ?>

              <input type="time" name="sched_time_<?= h($fk) ?>" value="<?= $timeVal ?>" required>

            <?php } else { ?>

              <span class="ln-instant">—</span>

            <?php } ?>

          </td>

          <td>

            <?php if (($meta['schedule_type'] ?? '') === 'weekly') { ?>

              <select name="sched_weekday_<?= h($fk) ?>">

                <?php foreach ($weekdays as $d => $label) { ?>

                  <option value="<?= (int)$d ?>" <?= $weekdayVal === (int)$d ? 'selected' : '' ?>><?= h($label) ?></option>

                <?php } ?>

              </select>

            <?php } elseif (($meta['schedule_type'] ?? '') === 'monthly_last_day') { ?>

              <span class="ln-instant">วันสุดท้ายของเดือน</span>

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



<div class="panel">

  <h3 style="margin:0 0 10px; font-size:15px">Outbox ล่าสุด</h3>

  <?php if (!$outbox) { ?>

    <p class="muted">ยังไม่มีรายการในคิว</p>

  <?php } else { ?>

  <table class="tbl">

    <thead>

      <tr><th>ID</th><th>Event</th><th>สถานะ</th><th>ครั้ง</th><th>สร้าง</th><th>ส่ง</th><th>ข้อผิดพลาด</th></tr>

    </thead>

    <tbody>

      <?php foreach ($outbox as $row) {

          $label = LINE_NOTIFY_EVENT_LABELS[$row['event_key']] ?? ($catalog[$row['event_key']]['label'] ?? $row['event_key']);

      ?>

      <tr>

        <td><?= (int)$row['id'] ?></td>

        <td><?= h($label) ?></td>

        <td><?= h($row['status']) ?></td>

        <td><?= (int)$row['attempts'] ?></td>

        <td><?= h($row['created_at']) ?></td>

        <td><?= h($row['sent_at'] ?? '-') ?></td>

        <td style="max-width:200px; word-break:break-word; font-size:12px"><?= h($row['last_error'] ?? '') ?></td>

      </tr>

      <?php } ?>

    </tbody>

  </table>

  <?php } ?>

</div>



<?php page_footer(); ?>


