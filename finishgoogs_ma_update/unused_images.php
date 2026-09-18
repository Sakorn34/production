<?php
/**
 * unused_images.php — รูปที่ไม่ได้ใช้งานแล้ว (ระบบหลังบ้าน · เครื่องมือแก้ข้อมูล)
 *
 * สแกน uploads/ เทียบกับข้อมูลที่อ้างถึงรูป (includes/unused_images.php) · เลือกแล้วย้ายไปถังขยะ
 * ถังขยะกู้คืนได้ทั้งชุด · ลบถาวรเป็นปุ่มแยกของแต่ละชุด
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/unused_images.php';
require_once __DIR__ . '/includes/support_report.php';
require_login();
ensure_support_reports_schema();   // ตารางต้องมีก่อน ไม่งั้นรูปแนบเรื่องแจ้งปัญหาจะถูกนับว่าไม่ได้ใช้

$B = BASE_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['trash'])) {
        $r = unused_img_trash((array) ($_POST['files'] ?? []));
        flash_set('ย้ายไปถังขยะ ' . number_format($r['moved']) . ' รูป' . ($r['skipped'] ? ' (ข้าม ' . $r['skipped'] . ' รูปที่ยังมีข้อมูลใช้อยู่หรือย้ายไม่ได้)' : '') . ' — กู้คืนได้จากถังขยะด้านล่าง');
    } elseif (isset($_POST['restore'])) {
        $n = unused_img_restore((string) $_POST['restore']);
        flash_set('กู้คืน ' . number_format($n) . ' รูปกลับที่เดิมแล้ว');
    } elseif (isset($_POST['purge'])) {
        $n = unused_img_purge((string) $_POST['purge']);
        flash_set('ลบถาวร ' . number_format($n) . ' รูปแล้ว');
    }
    header('Location: ' . $B . '/unused_images.php');
    exit;
}

$scan = unused_img_scan();
$trash = unused_img_trash_batches();
$mb = function ($b) { return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024) . ' KB'; };
$groups = [];
foreach ($scan['unused'] as $f) {
    $top = strpos($f['rel'], '/') !== false ? substr($f['rel'], 0, strpos($f['rel'], '/')) : '(โฟลเดอร์หลัก)';
    if ($top === 'legacy') {   // รูป AppSheet เดิมแยกตามโฟลเดอร์ย่อยจะอ่านง่ายกว่า
        $parts = explode('/', $f['rel']);
        $top = 'legacy/' . ($parts[1] ?? '');
    }
    $groups[$top][] = $f;
}
$folderLabel = [
    'products' => 'รูปรุ่นสินค้า', 'parts' => 'รูปอะไหล่', 'updates' => 'รูปอัปเดต FW/HW', 'brand' => 'โลโก้/แบรนด์',
    'fonts' => 'ฟอนต์', 'support' => 'รูปแนบแจ้งปัญหา',
];
page_header('รูปที่ไม่ได้ใช้งาน', true, 'รูปใน uploads ที่ไม่มีข้อมูลไหนอ้างถึงแล้ว · ย้ายไปถังขยะก่อน กู้คืนได้', $B . '/settings.php');
?>
<div class="ui-summary">
  <div class="ui-tile"><span>รูปทั้งหมด</span><b><?= number_format($scan['total_n']) ?></b></div>
  <div class="ui-tile"><span>ใช้อยู่</span><b><?= number_format($scan['used_n']) ?></b><small><?= $mb($scan['used_bytes']) ?></small></div>
  <div class="ui-tile is-warn"><span>ไม่ได้ใช้แล้ว</span><b><?= number_format(count($scan['unused'])) ?></b><small><?= $mb($scan['unused_bytes']) ?></small></div>
</div>
<p class="muted ui-note">ตรวจจาก: รูปรุ่นสินค้า · รูปอะไหล่ · รูปอัปเดต FW/HW · รูปแนบเรื่องแจ้งปัญหา · โลโก้/favicon/ฟอนต์ในตั้งค่าหน้าตาระบบ
  <?= $scan['young_n'] ? ' · ไม่นับรูปที่เพิ่งอัปโหลดภายใน 1 วัน (' . $scan['young_n'] . ' รูป)' : '' ?></p>

<?php if (!$scan['unused']) { ?>
<div class="panel"><p style="margin:0">ไม่มีรูปที่ไม่ได้ใช้ 🎉</p></div>
<?php } else { ?>
<form method="post" id="ui-form" onsubmit="return uiConfirm();">
  <?= csrf_field() ?>
  <div class="ui-bar">
    <label><input type="checkbox" id="ui-all"> เลือกทั้งหมด</label>
    <span class="muted" id="ui-picked">เลือก 0 รูป</span>
    <button type="submit" name="trash" value="1" class="btn">ย้ายที่เลือกไปถังขยะ</button>
  </div>
  <?php foreach ($groups as $g => $files) {
      $gBytes = array_sum(array_column($files, 'size'));
      $gKey = explode('/', $g)[0]; ?>
  <details class="panel ui-group" open>
    <summary><b><?= h($folderLabel[$gKey] ?? $g) ?></b> <span class="muted"><?= h($g) ?> · <?= number_format(count($files)) ?> รูป · <?= $mb($gBytes) ?></span>
      <label class="ui-grp-all" onclick="event.stopPropagation()"><input type="checkbox" data-grp="<?= h($g) ?>"> เลือกทั้งกลุ่ม</label></summary>
    <div class="ui-grid">
      <?php foreach ($files as $f) {
          $url = $B . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', $f['rel'])));
          $isFont = preg_match('/\.(woff2?|ttf|otf)$/i', $f['rel']); ?>
      <label class="ui-item">
        <input type="checkbox" name="files[]" value="<?= h($f['rel']) ?>" data-grp="<?= h($g) ?>">
        <?php if ($isFont) { ?><span class="ui-thumb ui-font">Aa</span><?php } else { ?>
        <img class="ui-thumb" src="<?= h($url) ?>" alt="" loading="lazy"><?php } ?>
        <span class="ui-name" title="<?= h($f['rel']) ?>"><?= h(basename($f['rel'])) ?></span>
        <span class="muted ui-meta"><?= $mb($f['size']) ?> · <?= h(date('d/m/Y', $f['mtime'])) ?> · <a href="<?= h($url) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()">เปิด</a></span>
      </label>
      <?php } ?>
    </div>
  </details>
  <?php } ?>
</form>
<?php } ?>

<?php if ($trash) { ?>
<div class="panel ui-trash">
  <h3>ถังขยะ</h3>
  <p class="muted" style="margin-top:0">รูปที่ย้ายมาอยู่ใน uploads/_trash — กู้คืนกลับที่เดิมได้ทั้งชุด หรือลบถาวรเพื่อคืนพื้นที่</p>
  <?php foreach ($trash as $t) {
      $when = DateTime::createFromFormat('Ymd_His', $t['batch']); ?>
  <form method="post" class="ui-trash-row">
    <?= csrf_field() ?>
    <span><b><?= h($when ? $when->format('d/m/Y H:i') : $t['batch']) ?></b> · <?= number_format($t['n']) ?> รูป · <?= $mb($t['bytes']) ?></span>
    <button type="submit" name="restore" value="<?= h($t['batch']) ?>" class="btn btn-line btn-sm">กู้คืน</button>
    <button type="submit" name="purge" value="<?= h($t['batch']) ?>" class="btn btn-line btn-sm ui-danger"
      onclick="return confirm('ลบถาวร <?= (int) $t['n'] ?> รูปในชุดนี้? กู้คืนไม่ได้อีก');">ลบถาวร</button>
  </form>
  <?php } ?>
</div>
<?php } ?>

<?php if ($scan['missing']) { ?>
<details class="panel">
  <summary><b>ข้อมูลที่อ้างถึงรูปแต่ไม่มีไฟล์</b> <span class="muted"><?= number_format(count($scan['missing'])) ?> รายการ — แสดงเป็นรูปเสียในหน้าเว็บ (ไม่ต้องทำอะไรในหน้านี้)</span></summary>
  <ul class="ui-missing">
    <?php foreach (array_slice($scan['missing'], 0, 300, true) as $rel => $src) { ?><li><?= h($src) ?>: <code><?= h($rel) ?></code></li><?php } ?>
  </ul>
</details>
<?php } ?>

<script>
(function(){
  var form = document.getElementById('ui-form');
  if (!form) return;
  var boxes = function(){ return Array.prototype.slice.call(form.querySelectorAll('input[name="files[]"]')); };
  function count(){ var n = boxes().filter(function(b){ return b.checked; }).length; document.getElementById('ui-picked').textContent = 'เลือก ' + n + ' รูป'; return n; }
  form.addEventListener('change', function(e){
    var t = e.target;
    if (t.id === 'ui-all') { boxes().forEach(function(b){ b.checked = t.checked; }); form.querySelectorAll('input[data-grp]:not([name])').forEach(function(g){ g.checked = t.checked; }); }
    else if (t.hasAttribute('data-grp') && !t.name) { boxes().forEach(function(b){ if (b.getAttribute('data-grp') === t.getAttribute('data-grp')) b.checked = t.checked; }); }
    count();
  });
  window.uiConfirm = function(){
    var n = count();
    if (!n) { alert('ยังไม่ได้เลือกรูป'); return false; }
    return confirm('ย้าย ' + n + ' รูปไปถังขยะ? (กู้คืนได้)');
  };
})();
</script>
<style>
.ui-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 8px; }
.ui-tile { background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, 12px); padding: 10px 14px; display: flex; flex-direction: column; }
.ui-tile span { color: var(--text-muted); font-size: 13px; }
.ui-tile b { font-size: 22px; }
.ui-tile small { color: var(--text-muted); }
.ui-tile.is-warn b { color: var(--warning, #b45309); }
.ui-note { margin: 0 0 12px; font-size: 12.5px; }
.ui-bar { position: sticky; top: 0; z-index: 5; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 10px 12px; margin-bottom: 10px; background: var(--surface, #fff); border: 1px solid var(--border); border-radius: var(--radius, 12px); }
.ui-bar .btn { margin-left: auto; }
.ui-group summary { cursor: pointer; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ui-grp-all { margin-left: auto; font-size: 13px; }
.ui-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; margin-top: 12px; }
.ui-item { position: relative; display: flex; flex-direction: column; gap: 4px; padding: 8px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; min-width: 0; }
.ui-item:has(input:checked) { border-color: var(--danger, #b91c1c); background: color-mix(in srgb, var(--danger, #b91c1c) 6%, transparent); }
.ui-item input { position: absolute; top: 12px; left: 12px; width: 20px; height: 20px; }
.ui-thumb { width: 100%; aspect-ratio: 1 / 1; object-fit: contain; background: var(--surface-soft, #f4f1fa); border-radius: 8px; display: grid; place-items: center; font-size: 28px; color: var(--text-muted); }
.ui-name { font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ui-meta { font-size: 11.5px; }
.ui-trash-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 8px 0; border-top: 1px solid var(--border); }
.ui-trash-row > span { flex: 1 1 200px; }
.ui-danger { color: var(--danger, #b91c1c) !important; border-color: var(--danger, #b91c1c) !important; }
.ui-missing { margin: 8px 0 0; padding-left: 18px; font-size: 12.5px; line-height: 1.7; }
@media (max-width: 640px) { .ui-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ui-bar .btn { margin-left: 0; width: 100%; } }
</style>
<?php page_footer();
