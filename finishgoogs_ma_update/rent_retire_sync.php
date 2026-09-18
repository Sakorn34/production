<?php
/**
 * rent_retire_sync.php — ตรวจสถานะ "เสื่อมสภาพ" ของเรากับระบบเช่าว่าตรงกันไหม + กดซิงก์ย้อนหลัง
 *
 * เครื่องที่บันทึก MA เป็นเสื่อมสภาพในระบบเรา แต่ตอนนั้นส่งเข้าระบบเช่าไม่สำเร็จ
 * จะค้างสถานะไม่ตรงกันสองฝั่ง แล้ว cron sync จะดึงสถานะฝั่งเรากลับทุกคืน — หน้านี้ใช้ตามเก็บ
 *
 * อ่านระบบเช่าอย่างเดียว ยกเว้นตอนกดซิงก์ ซึ่งเขียนผ่านทางเดิม (rent_close_wait_ma)
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/rent_retire_sync.php';
require_login();

$B = BASE_URL;
$state = isset($_GET['state']) ? (string)$_GET['state'] : 'issue';
if (!in_array($state, ['issue', 'all', 'match', 'fix_lease', 'fix_ours', 'blocked', 'no_lease'], true)) {
    $state = 'issue';
}
$q = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
$backUrl = $B . '/rent_retire_sync.php?state=' . urlencode($state) . ($q !== '' ? '&q=' . urlencode($q) : '');

// ── กดซิงก์ ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = [];
    if (isset($_POST['sync_one'])) {
        $ids = [(int)$_POST['sync_one']];
    } elseif (isset($_POST['sync_selected'])) {
        $ids = (array)(isset($_POST['asset_ids']) ? $_POST['asset_ids'] : []);
    }
    if (!$ids) {
        flash_set('ยังไม่ได้เลือกเครื่องที่จะซิงก์', 'err');
        header('Location: ' . $backUrl); exit;
    }
    $r = rent_retire_sync_many($ids);
    $msg = 'ซิงก์สำเร็จ ' . number_format($r['success']) . ' เครื่อง';
    if ($r['fail'] > 0) {
        $msg .= ' · ไม่สำเร็จ ' . number_format($r['fail']) . ' เครื่อง — ' . implode(' | ', array_slice($r['failed'], 0, 5));
    }
    flash_set($msg, $r['fail'] > 0 ? 'err' : 'ok');
    header('Location: ' . $backUrl); exit;
}

// ── ข้อมูล ──────────────────────────────────────────────────────────────────
$data = rent_retire_sync_rows(['q' => $q]);
$rows = $data['rows'];
$sum = $data['summary'];
$issueStates = ['fix_lease', 'fix_ours', 'blocked'];
$view = [];
foreach ($rows as $r) {
    if ($state === 'all'
        || ($state === 'issue' && in_array($r['state'], $issueStates, true))
        || $r['state'] === $state) {
        $view[] = $r;
    }
}
$issueTotal = (int)(isset($sum['fix_lease']) ? $sum['fix_lease'] : 0)
            + (int)(isset($sum['fix_ours']) ? $sum['fix_ours'] : 0)
            + (int)(isset($sum['blocked']) ? $sum['blocked'] : 0);
$canSyncCount = 0;
foreach ($view as $r) {
    if (!empty($r['can_sync'])) { $canSyncCount++; }
}

$tabs = [
    'issue'     => 'ต้องตามแก้ (' . number_format($issueTotal) . ')',
    'fix_lease' => 'ระบบเช่ายังไม่ตาม (' . number_format((int)(isset($sum['fix_lease']) ? $sum['fix_lease'] : 0)) . ')',
    'fix_ours'  => 'ทะเบียนเรายังไม่ตาม (' . number_format((int)(isset($sum['fix_ours']) ? $sum['fix_ours'] : 0)) . ')',
    'blocked'   => 'ยังอยู่กับลูกค้า (' . number_format((int)(isset($sum['blocked']) ? $sum['blocked'] : 0)) . ')',
    'match'     => 'ตรงกัน (' . number_format((int)(isset($sum['match']) ? $sum['match'] : 0)) . ')',
    'no_lease'  => 'ไม่มีในระบบเช่า (' . number_format((int)(isset($sum['no_lease']) ? $sum['no_lease'] : 0)) . ')',
    'all'       => 'ทั้งหมด (' . number_format((int)(isset($sum['total']) ? $sum['total'] : 0)) . ')',
];

page_header('ตรวจสถานะเสื่อมสภาพ ↔ ระบบเช่า', true, 'เครื่องที่ลงเสื่อมสภาพในระบบเรา เทียบกับสถานะจริงในระบบเช่า');
?>
<?php if (!$data['ok']) { ?>
<div class="panel"><p style="margin:0;color:var(--danger,#dc2626)">อ่านทะเบียนไม่สำเร็จ: <?= h($data['error']) ?></p></div>
<?php } elseif (!$data['lease_ok']) { ?>
<div class="panel"><p style="margin:0;color:var(--danger,#dc2626)">ยังต่อระบบเช่าไม่ได้ — เทียบสถานะไม่ได้ในตอนนี้ (<?= h($data['lease_error']) ?>)</p></div>
<?php } ?>

<div class="panel" style="margin-bottom:14px">
  <p class="muted" style="margin:0 0 10px">
    เทียบเครื่องที่ <b>สถานะทะเบียน = เสื่อมสภาพ</b> หรือ <b>เคยบันทึก MA เป็นเสื่อมสภาพ</b>
    กับสถานะในระบบเช่า (<code>Asset Retirement</code>) · กดซิงก์แล้วระบบจะส่งสถานะเสื่อมสภาพไปให้ระบบเช่า
    และตั้งสถานะฝั่งเราให้ตรงกันทันที ไม่ต้องรอ cron รอบกลางคืน
  </p>
  <div class="rs-tabs">
    <?php foreach ($tabs as $key => $label) { ?>
    <a class="btn btn-sm <?= $state === $key ? 'btn-primary' : 'btn-line' ?>"
       href="<?= h($B . '/rent_retire_sync.php?state=' . urlencode($key) . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>"><?= h($label) ?></a>
    <?php } ?>
  </div>
  <form method="get" class="filter" style="margin:10px 0 0">
    <input type="hidden" name="state" value="<?= h($state) ?>">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="ค้นหา S/N" style="max-width:240px" data-scan="submit">
    <button class="btn btn-line btn-sm" type="submit">ค้นหา</button>
    <?php if ($q !== '') { ?>
    <a class="btn btn-sm btn-line" href="<?= h($B . '/rent_retire_sync.php?state=' . urlencode($state)) ?>">ล้าง</a>
    <?php } ?>
  </form>
</div>

<?php if (!$view) { ?>
<div class="panel"><p class="muted" style="margin:0">
  <?= $state === 'issue' ? 'สถานะตรงกันทุกเครื่อง ไม่มีอะไรต้องตามแก้' : 'ไม่มีรายการในมุมมองนี้' ?>
</p></div>
<?php } else { ?>
<form method="post" onsubmit="return confirm('ซิงก์สถานะเสื่อมสภาพของเครื่องที่เลือกกับระบบเช่า?');">
  <?= csrf_field() ?>
  <?php if ($canSyncCount > 0) { ?>
  <div class="rs-bulk">
    <label class="rs-check"><input type="checkbox" id="rs-all"> เลือกทั้งหมดที่ซิงก์ได้ (<?= number_format($canSyncCount) ?>)</label>
    <button type="submit" name="sync_selected" value="1" class="btn btn-primary btn-sm">ซิงก์ที่เลือก</button>
  </div>
  <?php } ?>
  <div class="table-wrap">
    <table class="list" style="margin:0">
      <tr>
        <th style="width:34px"></th>
        <th>S/N</th>
        <th>รุ่น</th>
        <th>สถานะทะเบียนเรา</th>
        <th>สถานะระบบเช่า</th>
        <th>ผลเทียบ</th>
        <th>MA เสื่อมสภาพ</th>
        <th style="width:110px"></th>
      </tr>
      <?php foreach ($view as $r) { ?>
      <tr>
        <td><?php if (!empty($r['can_sync'])) { ?>
          <input type="checkbox" class="rs-pick" name="asset_ids[]" value="<?= (int)$r['id'] ?>">
        <?php } ?></td>
        <td><a href="<?= h($B . '/asset.php?id=' . (int)$r['id']) ?>"><b><?= h($r['asset_code']) ?></b></a>
          <?php if ((string)$r['factory_serial'] !== '' && $r['factory_serial'] !== $r['asset_code']) { ?>
          <div class="muted" style="font-size:12px">S/N โรงงาน: <?= h($r['factory_serial']) ?></div>
          <?php } ?>
        </td>
        <td><?= h((string)$r['pname']) ?></td>
        <td><?= status_badge((string)$r['status']) ?></td>
        <td><?= !empty($r['lease_found'])
              ? h((string)$r['lease_label']) . '<div class="muted" style="font-size:12px">' . h((string)$r['lease_status']) . '</div>'
              : '<span class="muted">ไม่พบ S/N</span>' ?></td>
        <td><span class="rs-state rs-<?= h((string)$r['state_tone']) ?>"><?= h((string)$r['state_label']) ?></span>
          <div class="muted" style="font-size:12px"><?= h((string)$r['state_hint']) ?></div>
        </td>
        <td style="white-space:nowrap"><?= $r['retire_at'] ? h(dthai($r['retire_at'])) : '<span class="muted">—</span>' ?>
          <?php if ((string)$r['retire_by'] !== '') { ?>
          <div class="muted" style="font-size:12px">โดย <?= h((string)$r['retire_by']) ?></div>
          <?php } ?>
        </td>
        <td><?php if (!empty($r['can_sync'])) { ?>
          <button type="submit" name="sync_one" value="<?= (int)$r['id'] ?>" class="btn btn-line btn-sm">ซิงก์</button>
        <?php } ?></td>
      </tr>
      <?php } ?>
    </table>
  </div>
</form>
<script>
(function(){
  var all = document.getElementById('rs-all');
  if (!all) return;
  all.addEventListener('change', function(){
    document.querySelectorAll('.rs-pick').forEach(function(el){ el.checked = all.checked; });
  });
})();
</script>
<?php } ?>

<style>
.rs-tabs { display:flex; flex-wrap:wrap; gap:6px; }
.rs-bulk { display:flex; flex-wrap:wrap; align-items:center; gap:12px; margin:0 0 10px; }
.rs-check { display:inline-flex; align-items:center; gap:6px; font-size:13px; }
.rs-state { font-weight:600; }
.rs-ok { color:var(--ok,#16a34a); }
.rs-warn { color:var(--warn,#d97706); }
.rs-err { color:var(--danger,#dc2626); }
.rs-muted { color:var(--muted,#64748b); }
</style>

<?php page_footer();
