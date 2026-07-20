<?php
/**
 * activity_logs.php — ดู Activity Log ทั้งระบบ Production และ Parts + export CSV
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require_once dirname(__DIR__) . '/shared/activity_log_core.php';
require __DIR__ . '/includes/layout.php';

activity_log_ensure_schema();

$filters = [
    'system'    => trim((string)(isset($_GET['system']) ? $_GET['system'] : '')),
    'actor'     => trim((string)(isset($_GET['actor']) ? $_GET['actor'] : '')),
    'action'    => trim((string)(isset($_GET['action']) ? $_GET['action'] : '')),
    'q'         => trim((string)(isset($_GET['q']) ? $_GET['q'] : '')),
    'date_from' => trim((string)(isset($_GET['date_from']) ? $_GET['date_from'] : '')),
    'date_to'   => trim((string)(isset($_GET['date_to']) ? $_GET['date_to'] : '')),
];
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$perPage = 80;
$offset = ($page - 1) * $perPage;

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $GLOBALS['activity_log_skip_auto'] = true;
    $all = activity_log_search($filters, 5000, 0);
    activity_log_csv_output($all['rows']);
    exit;
}

$result = activity_log_search($filters, $perPage, $offset);
$rows = $result['rows'];
$total = $result['total'];
$totalPages = max(1, (int)ceil($total / $perPage));

$queryBase = $_GET;
unset($queryBase['page'], $queryBase['export']);
$filterQs = http_build_query($queryBase);

page_header('Activity Log — ระบบหลังบ้าน');
?>
<div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px">
  <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/activity_logs.php?<?= h($filterQs) ?>&export=csv">⬇ Export CSV</a>
</div>

<p class="muted" style="margin-bottom:12px">
  บันทึกความเคลื่อนไหวจาก <b>ทะเบียนเครื่อง/MA</b> และ <b>สต็อกอะไหล่ (Parts)</b> — การบันทึก/แก้ไข/ลบ (POST) และรายละเอียดที่ระบบเก็บได้
</p>

<form method="get" class="filter" style="flex-wrap:wrap; gap:8px; margin-bottom:14px">
  <select name="system" style="min-width:160px">
    <option value="">— ทุกระบบ —</option>
    <?php foreach (ACTIVITY_LOG_SYSTEM_LABELS as $k => $lbl) { ?>
      <option value="<?= h($k) ?>" <?= $filters['system'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
    <?php } ?>
  </select>
  <input type="text" name="actor" value="<?= h($filters['actor']) ?>" placeholder="ผู้ใช้" style="width:120px">
  <input type="text" name="action" value="<?= h($filters['action']) ?>" placeholder="action" style="width:120px">
  <input type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="ค้นหาสรุป/รายละเอียด" style="width:min(220px,100%)">
  <input type="date" name="date_from" value="<?= h($filters['date_from']) ?>" title="ตั้งแต่">
  <input type="date" name="date_to" value="<?= h($filters['date_to']) ?>" title="ถึง">
  <button type="submit">ค้นหา</button>
  <a class="btn btn-line btn-sm" href="<?= BASE_URL ?>/activity_logs.php">ล้าง filter</a>
</form>

<p class="muted" style="margin-bottom:8px">พบ <?= number_format($total) ?> รายการ · หน้า <?= $page ?>/<?= $totalPages ?></p>

<table class="list" style="font-size:13px">
  <tr>
    <th style="white-space:nowrap">เวลา</th>
    <th>ระบบ</th>
    <th>ผู้ใช้</th>
    <th>การทำงาน</th>
    <th>สรุป</th>
    <th>รายละเอียด</th>
  </tr>
  <?php if (!$rows) { ?>
  <tr><td colspan="6" class="muted" style="text-align:center; padding:24px">ยังไม่มี log หรือไม่ตรงเงื่อนไขค้นหา</td></tr>
  <?php } ?>
  <?php foreach ($rows as $r) {
      $sysLbl = isset(ACTIVITY_LOG_SYSTEM_LABELS[$r['system_key']]) ? ACTIVITY_LOG_SYSTEM_LABELS[$r['system_key']] : $r['system_key'];
      $detail = trim((string)$r['detail']);
      if (mb_strlen($detail) > 160) {
          $detail = mb_substr($detail, 0, 160) . '…';
      }
  ?>
  <tr>
    <td style="white-space:nowrap"><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td>
    <td><span class="badge st-new" style="font-size:10px"><?= h($sysLbl) ?></span></td>
    <td><?= h($r['actor_name'] ?: '-') ?></td>
    <td class="muted" style="font-size:11px"><?= h($r['action_key']) ?></td>
    <td><b><?= h($r['summary']) ?></b></td>
    <td class="muted" style="max-width:320px; word-break:break-word"><?= h($detail ?: '-') ?></td>
  </tr>
  <?php } ?>
</table>

<?php if ($totalPages > 1) { ?>
<div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap">
  <?php if ($page > 1) { ?>
    <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/activity_logs.php?<?= h($filterQs) ?>&page=<?= $page - 1 ?>">← ก่อนหน้า</a>
  <?php } ?>
  <?php if ($page < $totalPages) { ?>
    <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/activity_logs.php?<?= h($filterQs) ?>&page=<?= $page + 1 ?>">ถัดไป →</a>
  <?php } ?>
</div>
<?php } ?>

<?php page_footer();
