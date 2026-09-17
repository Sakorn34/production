<?php
/**
 * production_dedupe.php — รวมบันทึกผลิตที่ซ้ำกัน (หน้าหลังบ้าน)
 *
 * ดูรายละเอียดต้นเหตุใน includes/production_dedupe.php
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/production_dedupe.php';
require_login();

$B = BASE_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    csrf_check();
    $d = production_dedupe_apply();
    flash_set('รวมบันทึกผลิตที่ซ้ำแล้ว ' . number_format($d['merged']) . ' รายการ · ย้ายชื่อผู้ผลิตไปแถวที่มี checklist ' . number_format($d['named']) . ' รายการ');
    header('Location: ' . $B . '/production_dedupe.php'); exit;
}

$total = (int) qr(
    "SELECT COUNT(*) FROM production_records p WHERE " . PRODUCTION_DEDUPE_EMPTY_SQL . "
       AND EXISTS (SELECT 1 FROM production_records o WHERE o.asset_id = p.asset_id AND o.id <> p.id AND DATE(o.recorded_at) = DATE(p.recorded_at))"
)->fetch_row()[0];
$sample = $total > 0 ? production_dedupe_rows(30) : [];

page_header('รวมบันทึกผลิตที่ซ้ำ', true, 'หน้าเครื่องมี "บันทึกผลิต / QC" สองอันวันเดียวกัน — อันหนึ่งมีแค่ชื่อผู้ผลิต ไม่มี checklist', $B . '/settings.php');
?>
<div class="panel">
  <p style="margin:0 0 8px">
    <b>สาเหตุ:</b> ปุ่มเติมชื่อผู้ผลิตจากทะเบียน stock เดิมสร้างบันทึกผลิต<b>แถวใหม่</b>ที่มีแค่ชื่อ แทนที่จะเติมชื่อลงบันทึกผลิตเดิม
    (แก้แล้ว — ต่อไปจะเติมลงแถวเดิม)
  </p>
  <p class="muted" style="margin:0;font-size:14px">
    กดรวมแล้ว: ชื่อผู้ผลิตย้ายไปอยู่ในบันทึกที่มี checklist (ถ้ายังไม่มีชื่อ) แล้วลบแถวที่มีแค่ชื่อ ·
    ไม่แตะบันทึกที่มีข้อมูลอื่น และเครื่องที่มีบันทึกผลิตแถวเดียว
  </p>
</div>

<div class="panel pd-head">
  <div><b style="font-size:24px"><?= number_format($total) ?></b> <span class="muted">บันทึกผลิตที่ซ้ำ (มีแค่ชื่อผู้ผลิต)</span></div>
  <?php if ($total > 0) { ?>
  <form method="post" onsubmit="return confirm('รวมบันทึกผลิตที่ซ้ำ <?= $total ?> รายการ?\n\nแถวที่มีแค่ชื่อผู้ผลิตจะถูกลบ หลังย้ายชื่อไปแถวที่มี checklist แล้ว');">
    <?= csrf_field() ?>
    <button type="submit" name="apply" value="1" class="btn btn-primary btn-sm">รวม <?= number_format($total) ?> รายการ</button>
  </form>
  <?php } ?>
</div>

<?php if ($sample) { ?>
<div class="panel">
  <b>ตัวอย่าง <?= count($sample) ?> รายการแรก</b>
  <div class="table-wrap">
  <table class="list" style="margin-top:8px">
    <thead><tr><th data-pri="1">เครื่อง</th><th data-pri="2">วันที่</th><th data-pri="1">แถวที่จะลบ (มีแค่ชื่อ)</th><th data-pri="1">แถวที่เก็บไว้</th></tr></thead>
    <tbody>
    <?php foreach ($sample as $r) { ?>
      <tr>
        <td data-pri="1"><a href="<?= h($B . '/asset.php?id=' . (int) $r['asset_id']) ?>"><b><?= h((string) $r['asset_code']) ?></b></a></td>
        <td data-pri="2"><?= h(dthai(substr((string) $r['recorded_at'], 0, 10))) ?></td>
        <td data-pri="1">#<?= (int) $r['id'] ?> · ผู้ผลิต <?= h((string) $r['made_by'] ?: '—') ?></td>
        <td data-pri="1">#<?= (int) $r['keep_id'] ?> · ผู้ผลิต
          <?= trim((string) $r['keep_made_by']) !== '' ? h((string) $r['keep_made_by']) : '<span class="muted">ว่าง → จะเติม ' . h((string) $r['made_by']) . '</span>' ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
</div>
<?php } ?>
<style>.pd-head{display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:center}</style>
<?php page_footer();