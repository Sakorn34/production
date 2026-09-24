<?php
/**
 * leasing_sn_fix.php — แก้ S/N ที่กรอกผิดไว้ในระบบเช่า (24 ก.ย. 2026)
 *
 * ค้นหาเครื่องจาก S/N ที่ผิด → ดูว่าเป็นเครื่องอะไร ผูกสัญญา/ประวัติ MA ไว้เท่าไร → ใส่ S/N ที่ถูก
 * บันทึกทีเดียวแก้ครบทั้งทะเบียนเครื่อง สัญญา และประวัติ MA (ดู includes/leasing_sn_fix.php)
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/leasing_sn_fix.php';
require_login();

$B = BASE_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old_sn'])) {
    csrf_check();
    $r = leasing_sn_rename((string) $_POST['old_sn'], (string) ($_POST['new_sn'] ?? ''), actor_name());
    if ($r['ok']) {
        flash_set('แก้ S/N ในระบบเช่าแล้ว: ' . h((string) $_POST['old_sn']) . ' → ' . h(trim((string) $_POST['new_sn']))
            . ' (ทะเบียนเครื่อง ' . $r['product'] . ' · สัญญา ' . $r['contracts'] . ' · ประวัติ MA ' . $r['ma'] . ' รายการ)');
        header('Location: ' . $B . '/leasing_sn_fix.php?q=' . rawurlencode(trim((string) $_POST['new_sn'])));
        exit;
    }
    flash_set($r['error'], 'err');
    header('Location: ' . $B . '/leasing_sn_fix.php?q=' . rawurlencode((string) $_POST['old_sn']) . '&sn=' . rawurlencode((string) $_POST['old_sn']));
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$sn = trim((string) ($_GET['sn'] ?? ''));
$found = $q !== '' ? leasing_sn_search($q) : ['ok' => true, 'error' => '', 'rows' => []];
$detail = $sn !== '' ? leasing_sn_detail($sn) : null;
$fmtD = function ($v) {
    $v = trim((string) $v);
    return ($v === '' || strpos($v, '0000-00-00') === 0) ? '—' : date('d/m/y', strtotime(substr($v, 0, 10)));
};
$stLabel = ['finished goods' => 'คลังพร้อมเช่า', 'rent' => 'กำลังเช่า', 'received' => 'รับคืนแล้ว', 'claim' => 'เคลม', 'sale' => 'ขายแล้ว'];

page_header('แก้ S/N ในระบบเช่า', true, 'กรอกผิดตอนลงทะเบียน — แก้ทะเบียนเครื่อง สัญญา และประวัติ MA พร้อมกัน', $B . '/settings.php');
?>
<div class="panel ls-note">
  ข้อมูลนี้เป็นของ<b>ระบบเช่า</b> — แก้แล้วมีผลกับสัญญาและใบแจ้งหนี้ของทีมเช่าทันที ตรวจให้แน่ใจก่อนบันทึก
  ทุกครั้งที่แก้ระบบจะบันทึกไว้ใน <a href="<?= $B ?>/activity_logs.php">Activity Log</a> ว่าใครแก้จากอะไรเป็นอะไร
</div>

<form method="get" class="filter">
  <input type="search" name="q" value="<?= h($q) ?>" data-scan="submit" autocomplete="off" placeholder="พิมพ์หรือสแกน S/N ที่ผิด (บางส่วนก็ได้)" style="width:min(320px,100%)">
  <button type="submit" class="btn">ค้นหาในระบบเช่า</button>
  <?php if ($q !== '' || $sn !== '') { ?><a class="btn btn-line btn-sm" href="<?= $B ?>/leasing_sn_fix.php" data-same-tab>ล้างการค้นหา</a><?php } ?>
</form>

<?php if (!$found['ok']) { ?>
<div class="panel"><p class="err" style="margin:0"><?= h($found['error']) ?></p></div>
<?php } elseif ($q !== '') { ?>
<div class="panel">
  <h3 style="margin:0 0 8px">ผลการค้นหา (<?= count($found['rows']) ?>)</h3>
  <?php if (!$found['rows']) { ?>
  <p class="muted" style="margin:0">ไม่พบ S/N นี้ในระบบเช่า — ลองพิมพ์แค่บางส่วนของรหัส</p>
  <?php } else { ?>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">S/N ในระบบเช่า</th><th data-pri="2">รุ่น</th><th data-pri="2">สถานะ</th><th data-pri="3">ลงทะเบียน</th><th data-pri="2">ผูกอยู่</th><th data-pri="1"></th></tr>
    <?php foreach ($found['rows'] as $r) { $code = (string) $r['pro_sn']; $ours = leasing_sn_our_asset($code); ?>
    <tr<?= $sn === $code ? ' class="is-on"' : '' ?>>
      <td data-pri="1"><b class="ls-sn"><?= h($code) ?></b>
        <div class="muted" style="font-size:12px"><?= $ours ? 'มีในทะเบียนเรา · ' . h($ours['pname']) : 'ไม่มีในทะเบียนเรา' ?></div></td>
      <td data-pri="2"><?= h((string) $r['pro_name']) ?></td>
      <td data-pri="2"><?= h($stLabel[(string) $r['pro_status']] ?? (string) $r['pro_status']) ?></td>
      <td data-pri="3"><?= h($fmtD($r['pro_date'])) ?><?= trim((string) $r['pro_user_add']) !== '' ? ' · ' . h((string) $r['pro_user_add']) : '' ?></td>
      <td data-pri="2">สัญญา <b><?= (int) $r['contracts'] ?></b> · MA <b><?= (int) $r['ma'] ?></b></td>
      <td data-pri="1"><a class="btn btn-sm btn-line" href="<?= $B ?>/leasing_sn_fix.php?q=<?= rawurlencode($q) ?>&amp;sn=<?= rawurlencode($code) ?>" data-same-tab>เลือกแก้</a></td>
    </tr>
    <?php } ?>
  </table></div>
  <?php } ?>
</div>
<?php } ?>

<?php if ($detail && $detail['ok']) { $p = $detail['product']; ?>
<div class="panel ls-fix">
  <h3 style="margin:0 0 4px">แก้ S/N ของเครื่องนี้</h3>
  <div class="muted" style="margin-bottom:10px">
    <b class="ls-sn"><?= h((string) $p['pro_sn']) ?></b> · <?= h((string) $p['pro_name']) ?> ·
    สถานะ <?= h($stLabel[(string) $p['pro_status']] ?? (string) $p['pro_status']) ?> ·
    ลงทะเบียน <?= h($fmtD($p['pro_date'])) ?><?= trim((string) $p['pro_user_add']) !== '' ? ' โดย ' . h((string) $p['pro_user_add']) : '' ?>
  </div>
  <p class="ls-touch">แก้แล้วจะเปลี่ยนให้ทั้ง <b>ทะเบียนเครื่อง 1</b> รายการ · <b>บรรทัดในสัญญา <?= count($detail['contracts']) ?></b> รายการ · <b>ประวัติ MA <?= (int) $detail['ma'] ?></b> รายการ</p>

  <?php if ($detail['contracts']) { ?>
  <div class="table-wrap"><table class="list">
    <tr><th data-pri="1">สัญญา</th><th data-pri="2">ไซต์งาน</th><th data-pri="2">ช่วงสัญญา</th><th data-pri="2">สถานะ</th></tr>
    <?php foreach ($detail['contracts'] as $c) { ?>
    <tr><td data-pri="1"><?= h(trim((string) $c['r_code']) !== '' ? (string) $c['r_code'] : '—') ?></td>
      <td data-pri="2"><?= h(trim((string) $c['p_sitename']) !== '' ? (string) $c['p_sitename'] : '—') ?></td>
      <td data-pri="2"><?= h($fmtD($c['r_startdate'])) ?> – <?= h($fmtD($c['r_enddate'])) ?></td>
      <td data-pri="2"><?= h((string) $c['p_status']) ?></td></tr>
    <?php } ?>
  </table></div>
  <?php } ?>

  <form method="post" class="ls-form">
    <?= csrf_field() ?>
    <input type="hidden" name="old_sn" value="<?= h((string) $p['pro_sn']) ?>">
    <label for="new_sn">S/N ที่ถูกต้อง</label>
    <input type="text" id="new_sn" name="new_sn" data-scan="fill" autocomplete="off" maxlength="<?= LEASING_SN_MAX ?>" placeholder="พิมพ์หรือสแกนจากตัวเครื่อง" required>
    <button type="submit" class="btn" onclick="return confirm('แก้ S/N ในระบบเช่าจาก <?= h((string) $p['pro_sn']) ?> เป็นค่าที่กรอก?\nมีผลกับสัญญาและประวัติ MA ของทีมเช่าทันที')">บันทึก S/N ใหม่</button>
  </form>
  <p class="muted" style="font-size:12.5px;margin:6px 0 0">ระบบจะไม่ให้บันทึกถ้า S/N ใหม่มีอยู่ในระบบเช่าแล้ว หรือไม่มีในทะเบียนเครื่องของเรา (กันพิมพ์ผิดซ้ำสอง)</p>
</div>
<?php } elseif ($detail && !$detail['ok']) { ?>
<div class="panel"><p class="err" style="margin:0"><?= h($detail['error']) ?></p></div>
<?php } ?>

<style>
.ls-note { background: #fff7ed; border-color: #f59e0b; color: #92400e; line-height: 1.6; }
.ls-sn { font-family: var(--mono, ui-monospace, monospace); }
.list tr.is-on td { background: var(--surface-2, #f6f4fb); }
.ls-touch { background: var(--surface-2, #f6f4fb); border-radius: 8px; padding: 6px 10px; margin: 0 0 10px; font-size: calc(13px * var(--font-scale, 1)); }
.ls-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
.ls-form label { font-weight: 600; }
.ls-form input { flex: 1 1 220px; min-width: 0; }
</style>
<?php page_footer(); ?>
