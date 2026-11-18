<?php
/**
 * sd_image_check.php — คิวเขียน SD image และตั้งชื่อไฟล์ชุดปัจจุบัน
 * ────────────────────────────────────────────────────────────────────────────────
 * ไล่เครื่องเช่า + เครื่องสำรอง ของ Plus / M / S ว่ารุ่นบอร์ดและชื่อไฟล์ image
 * ตรงชุดปัจจุบันหรือยัง · Plus/M แยก Pi2 กับ Pi3 · รุ่น S เป็น Pi3 อย่างเดียว
 * เลือกช่อง Pi2 / Pi3 / S ก่อน แล้วแก้ไฟล์ปัจจุบัน ไฟล์เก่า และ FW เริ่มต้น เก็บใน site_settings
 *
 * ตัวอย่าง: เปิดจากระบบหลังบ้าน หรือจากแบนเนอร์ในหน้าบันทึก MA
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/sd_image.php';
require_login();

$B = BASE_URL;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sd_image'])) {
    csrf_check();
    $saved = sd_image_save_catalog($_POST);
    flash_set($saved['message'], !empty($saved['ok']) ? 'ok' : 'err');
    header('Location: ' . $B . '/sd_image_check.php');
    exit;
}

$filter = (string) ($_GET['f'] ?? 'queue');
$allowed = ['queue', 'need_write', 'need_board', 'guessed', 'pi2', 'done', 'all'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'queue';
}
$productId = (int) ($_GET['product'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) > 40) {
    $q = mb_substr($q, 0, 40);
}

$idMap = sd_image_product_id_map();
if ($productId > 0 && !isset($idMap[$productId])) {
    $productId = 0;
}

$data = sd_image_queue($filter, $productId, $q);
$counts = $data['counts'];
$names = [];
if ($idMap) {
    $pids = array_keys($idMap);
    $ph = implode(',', array_fill(0, count($pids), '?'));
    $pr = qr('SELECT id, name FROM products WHERE id IN (' . $ph . ')', str_repeat('i', count($pids)), $pids);
    while ($pr && ($x = $pr->fetch_assoc())) {
        $names[(int) $x['id']] = (string) $x['name'];
    }
}

/**
 * URL ของหน้านี้คงตัวกรองที่ยังใช้
 *
 * @param array<string,mixed> $over ค่าที่ทับ
 * @return string
 */
function sd_image_check_url(array $over = []): string
{
    global $B, $filter, $productId, $q;
    $qs = ['f' => $filter];
    if ($productId > 0) {
        $qs['product'] = $productId;
    }
    if ($q !== '') {
        $qs['q'] = $q;
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return $B . '/sd_image_check.php?' . http_build_query($qs);
}

page_header('คิวเขียน SD image');
$campSlots = sd_image_campaign_slots();
$allFiles = sd_image_files();
$slotLabels = sd_image_slot_labels();
$fwStarts = sd_image_fw_starts();
$allVersions = sd_image_versions();
$fwKnown = sd_image_known_fw_versions();
$campNames = [];
foreach ($campSlots as $slot) {
    $campNames[] = $slotLabels[$slot] ?? $slot;
}
?>
<p class="sdq-lead">รอบนี้ไล่เขียนใหม่เฉพาะ <?= $campNames ? '<b>' . h(implode(' และ ', $campNames)) . '</b>' : 'ช่องที่ติ๊กไว้ด้านล่าง' ?> · ถ้ายังไม่ยืนยัน จะเดาชื่อไฟล์จาก <b>version FW</b> แล้วแสดงบนโปรไฟล์/คิว · หน้า MA เทียบจากช่อง Firmware หลังตรวจ ถ้าถึงจุดเริ่มชุดปัจจุบันจะบันทึกชื่อไฟล์ให้ · เปลี่ยน SD Card ก็ถือว่าเขียนแล้ว</p>

<form method="post" class="sdq-cat" id="sdq-cat">
  <?= csrf_field() ?>
  <input type="hidden" name="save_sd_image" value="1">
  <h2 class="sdq-cat-title">ชุด image</h2>
  <p class="muted sdq-cat-help">เลือกช่องก่อน แล้วแก้ไฟล์ปัจจุบัน ไฟล์เก่า และ FW เริ่มต้นของช่องนั้น · ถ้าว่าง FW จะไม่เทียบ</p>
  <?php if ($fwKnown) { ?>
  <datalist id="sd-fw-list">
    <?php foreach ($fwKnown as $fwOpt) { ?>
    <option value="<?= h($fwOpt) ?>"></option>
    <?php } ?>
  </datalist>
  <?php } ?>
  <?php
  $slotKeys = array_keys($allFiles);
  $openSlot = in_array('plus_m_pi3', $slotKeys, true) ? 'plus_m_pi3' : (string) ($slotKeys[0] ?? '');
  ?>
  <div class="sdq-cat-picks" role="tablist" aria-label="เลือกช่อง image">
    <?php foreach ($allFiles as $slot => $fn) {
        $on = $slot === $openSlot;
        $fwLab = trim((string) ($fwStarts[$slot] ?? ''));
        $oldN = max(0, count($allVersions[$slot] ?? []) - 1);
        $campOn = in_array($slot, $campSlots, true);
        ?>
    <button type="button" class="sdq-cat-pick<?= $on ? ' is-on' : '' ?>" data-slot="<?= h($slot) ?>" role="tab" aria-selected="<?= $on ? 'true' : 'false' ?>">
      <span class="sdq-cat-pick-name"><?= h($slotLabels[$slot] ?? $slot) ?></span>
      <span class="sdq-cat-pick-file" title="<?= h($fn) ?>"><?= h($fn) ?></span>
      <span class="sdq-cat-pick-meta">
        <?= $fwLab !== '' ? 'FW ' . h($fwLab) : 'ยังไม่ตั้ง FW' ?>
        <?= $oldN > 0 ? ' · เก่า ' . $oldN . ' ไฟล์' : '' ?>
        <?php if ($campOn) { ?> · <b>ต้องเขียนรอบนี้</b><?php } ?>
      </span>
    </button>
    <?php } ?>
  </div>
  <?php foreach ($allFiles as $slot => $fn) {
      $oldRows = array_slice($allVersions[$slot] ?? [], 1);
      $oldRows[] = ['file' => '', 'fw_start' => ''];
      $isOpen = $slot === $openSlot;
      ?>
  <div class="sdq-cat-pane" data-slot="<?= h($slot) ?>" role="tabpanel" <?= $isOpen ? '' : 'hidden' ?>>
    <h3 class="sdq-cat-pane-title"><?= h($slotLabels[$slot] ?? $slot) ?></h3>
    <label class="sdq-cat-field">
      <span>ไฟล์ปัจจุบัน</span>
      <input type="text" name="sd_file[<?= h($slot) ?>]" value="<?= h($fn) ?>" maxlength="180" required>
    </label>
    <div class="sdq-cat-fwline">
      <label class="sdq-cat-field">
        <span>FW เริ่มต้น</span>
        <input type="text" name="sd_fw_start[<?= h($slot) ?>]" value="<?= h($fwStarts[$slot] ?? '') ?>" maxlength="40" list="sd-fw-list" placeholder="เช่น 2.6.7l" autocomplete="off">
      </label>
      <label class="sdq-cat-camp">
        <input type="checkbox" name="sd_campaign[]" value="<?= h($slot) ?>" <?= in_array($slot, $campSlots, true) ? 'checked' : '' ?>>
        ต้องเขียนรอบนี้
      </label>
    </div>
    <p class="muted sdq-cat-fwnote">ใช้กับ version นี้และที่ใหม่กว่า จนถึงไฟล์ถัดไป</p>
    <div class="sdq-cat-old">
      <span class="sdq-cat-old-lab">ไฟล์เก่า</span>
      <div class="sdq-cat-hist-head">
        <span>ชื่อไฟล์</span>
        <span>FW เริ่มต้น</span>
      </div>
      <?php foreach ($oldRows as $i => $old) { ?>
      <div class="sdq-cat-hist">
        <input type="text" name="sd_hist[<?= h($slot) ?>][<?= (int) $i ?>][file]" value="<?= h((string) ($old['file'] ?? '')) ?>" maxlength="180" placeholder="ชื่อไฟล์ .img" autocomplete="off">
        <input type="text" name="sd_hist[<?= h($slot) ?>][<?= (int) $i ?>][fw_start]" value="<?= h((string) ($old['fw_start'] ?? '')) ?>" maxlength="40" list="sd-fw-list" placeholder="เช่น 2.5.0" autocomplete="off">
      </div>
      <?php } ?>
    </div>
  </div>
  <?php } ?>
  <div class="sdq-cat-act"><button type="submit">บันทึกชุด image</button></div>
</form>
<script>
(function(){
  var form = document.getElementById('sdq-cat');
  if (!form) return;
  var picks = form.querySelectorAll('.sdq-cat-pick');
  var panes = form.querySelectorAll('.sdq-cat-pane');
  function showSlot(slot){
    picks.forEach(function(btn){
      var on = btn.getAttribute('data-slot') === slot;
      btn.classList.toggle('is-on', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panes.forEach(function(pane){
      pane.hidden = pane.getAttribute('data-slot') !== slot;
    });
  }
  picks.forEach(function(btn){
    btn.addEventListener('click', function(){ showSlot(btn.getAttribute('data-slot')); });
  });
})();
</script>

<div class="sdq-stats">
  <?php
  $statCards = [
      ['queue', 'ต้องทำ', (int) $counts['queue']],
      ['need_write', 'ยังไม่ได้เขียน', (int) $counts['need_write']],
      ['need_board', 'ต้องดูบอร์ด', (int) $counts['need_board']],
      ['guessed', 'โดยประมาณ', (int) ($counts['guessed'] ?? 0)],
      ['pi2', 'Pi2 ไม่ต้องเขียน', (int) $counts['pi2']],
      ['done', 'เขียนแล้ว', (int) $counts['done']],
      ['all', 'เช่า+สำรองทั้งหมด', (int) $counts['queue'] + (int) ($counts['guessed'] ?? 0) + (int) $counts['pi2'] + (int) $counts['done']],
  ];
  foreach ($statCards as $sc) {
      $on = $filter === $sc[0] ? ' is-on' : '';
      echo '<a class="sdq-stat' . $on . '" href="' . h(sd_image_check_url(['f' => $sc[0]])) . '">'
         . '<div class="sdq-num">' . number_format($sc[2]) . '</div>'
         . '<div class="sdq-lbl">' . h($sc[1]) . '</div></a>';
  }
  ?>
</div>

<form class="sdq-filter" method="get" action="<?= h($B . '/sd_image_check.php') ?>">
  <input type="hidden" name="f" value="<?= h($filter) ?>">
  <label class="assets-ff assets-ff-code">
    <span>รหัสเครื่อง</span>
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="พิมพ์ S/N…" maxlength="40">
  </label>
  <label class="assets-ff">
    <span>รุ่น</span>
    <select name="product">
      <option value="0">ทุกรุ่นที่ตาม</option>
      <?php foreach ($names as $pid => $nm) { ?>
      <option value="<?= (int) $pid ?>" <?= $productId === (int) $pid ? 'selected' : '' ?>><?= h($nm) ?></option>
      <?php } ?>
    </select>
  </label>
  <div class="assets-ff-actions">
    <button type="submit">ค้นหา</button>
    <?php if ($q !== '' || $productId > 0) { ?>
    <a href="<?= h(sd_image_check_url(['q' => '', 'product' => 0])) ?>" class="btn btn-line btn-sm">ล้างการค้นหา</a>
    <?php } ?>
  </div>
</form>

<p class="muted" style="margin:0 0 10px">Plus / M ใช้ไฟล์คนละตัวตาม Raspberry · รุ่น S มีไฟล์เดียว (Pi3)</p>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <thead>
  <tr>
    <th data-pri="1">รหัสเครื่อง</th>
    <th data-pri="1">รุ่น</th>
    <th data-pri="1">สถานะ</th>
    <th data-pri="1">บอร์ด</th>
    <th data-pri="2">ไฟล์ image</th>
    <th data-pri="1">สถานะ image</th>
    <th data-pri="1"></th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$data['rows']) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">ไม่มีเครื่องตามเงื่อนไขนี้</td></tr>
  <?php } ?>
  <?php foreach ($data['rows'] as $r) {
      $pid = (int) $r['product_id'];
      $aid = (int) $r['asset_id'];
      $pi = (string) $r['raspberry'];
      $guess = (string) ($r['pi_guess'] ?? '');
      $boardHtml = '<span class="muted">—</span>';
      if ($pi !== '') {
          $boardHtml = h($pi);
      } elseif ($guess !== '') {
          $boardHtml = h($guess) . ' <span class="muted">โดยประมาณ</span>';
      }
      $shown = sd_image_shown_file($r);
      $need = (string) ($r['need_file'] ?? '');
      if ($shown !== '') {
          $fileHtml = h($shown);
          if ($need !== '' && $shown !== $need) {
              $fileHtml .= '<div class="muted">ต้องเขียน ' . h($need) . '</div>';
          }
      } elseif ($need !== '') {
          $fileHtml = '<span class="muted">ต้องเขียน ' . h($need) . '</span>';
      } elseif ($guess !== '' && (string) ($r['slug'] ?? '') === 'plus_m') {
          $gFile = sd_image_file_for_slot(sd_image_slot('plus_m', $guess));
          $fileHtml = $gFile !== '' ? '<span class="muted">น่าจะเป็น ' . h($gFile) . '</span>' : '<span class="muted">ยังไม่รู้บอร์ด</span>';
      } else {
          $fileHtml = '<span class="muted">ยังไม่รู้บอร์ด</span>';
      }
      $maHref = $B . '/ma.php?product=' . $pid . '&record=' . $aid;
      ?>
  <tr>
    <td data-pri="1" data-nowrap><a href="<?= h($B . '/asset.php?id=' . $aid) ?>"><?= h($r['asset_code']) ?></a></td>
    <td data-pri="1"><?= h($r['product_name']) ?></td>
    <td data-pri="1"><?= status_badge($r['status']) ?></td>
    <td data-pri="1"><?= $boardHtml ?></td>
    <td data-pri="2" class="sdq-file"><?= $fileHtml ?></td>
    <td data-pri="1"><?= h($r['reason']) ?></td>
    <td data-pri="1" data-nowrap><a class="btn btn-sm btn-line sdq-ma-btn" href="<?= h($maHref) ?>">บันทึก MA</a></td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<style>
.sdq-lead { margin: 0 0 14px; line-height: 1.85; }
.sdq-stats { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 1100px) { .sdq-stats { grid-template-columns: repeat(4, 1fr); } }
@media (max-width: 900px) { .sdq-stats { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 520px) { .sdq-stats { grid-template-columns: repeat(2, 1fr); } }
.sdq-stat {
  background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec);
  border-radius: 10px; padding: 12px 8px; text-align: center; text-decoration: none;
  color: inherit; display: block;
}
.sdq-stat:hover { border-color: var(--primary); }
.sdq-stat.is-on { box-shadow: inset 0 0 0 1.5px var(--primary); background: var(--primary-soft, #fce7f3); }
.sdq-num { font-size: calc(22px * var(--font-scale, 1)); font-weight: 700; color: var(--primary); line-height: 1.2; }
.sdq-lbl { font-size: calc(11px * var(--font-scale, 1)); margin-top: 2px; }
.sdq-filter { display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: flex-end; margin-bottom: 12px; }
.sdq-filter .assets-ff { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.sdq-filter .assets-ff > span:first-child {
  font-size: 12px; font-weight: 600; color: var(--text-muted, #6b6480); line-height: 1.2; white-space: nowrap;
}
.sdq-filter .assets-ff input[type=text],
.sdq-filter .assets-ff select,
.sdq-filter .assets-ff-actions button,
.sdq-filter .assets-ff-actions .btn { min-height: var(--input-h, 40px); }
.sdq-filter .assets-ff select { min-width: 160px; }
.sdq-filter .assets-ff-code { flex: 1 1 170px; min-width: 150px; max-width: 240px; }
.sdq-filter .assets-ff-code input { width: 100%; }
.sdq-filter .assets-ff-actions { display: flex; gap: 8px; align-items: center; }
.sdq-file { font-size: calc(12px * var(--font-scale, 1)); word-break: break-all; }
.sdq-ma-btn { white-space: nowrap; }
.sdq-cat {
  background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec);
  border-radius: 10px; padding: 12px 14px; margin: 0 0 14px;
}
.sdq-cat-title { margin: 0 0 4px; font-size: calc(15px * var(--font-scale, 1)); }
.sdq-cat-help { margin: 0 0 12px; }
.sdq-cat-picks {
  display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin: 0 0 14px;
}
@media (max-width: 720px) { .sdq-cat-picks { grid-template-columns: 1fr; } }
.sdq-cat-pick {
  display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
  text-align: left; padding: 10px 12px; border: 1px solid var(--border, #dde3ec);
  border-radius: 10px; background: #fff; cursor: pointer; color: inherit;
  min-height: 74px; width: 100%;
}
.sdq-cat-pick:hover, .sdq-cat-pick:focus-visible {
  background: var(--surface-muted, #eef2f9);
  border-color: var(--primary);
  color: inherit;
}
.sdq-cat-pick.is-on, .sdq-cat-pick.is-on:hover {
  box-shadow: inset 0 0 0 1.5px var(--primary);
  background: var(--primary-soft, #fce7f3);
  color: inherit;
}
.sdq-cat-pick-name { font-weight: 700; font-size: calc(13px * var(--font-scale, 1)); color: inherit; }
.sdq-cat-pick-file {
  font-size: calc(11px * var(--font-scale, 1)); color: var(--text-muted, #6b6480);
  max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.sdq-cat-pick:hover .sdq-cat-pick-file,
.sdq-cat-pick.is-on .sdq-cat-pick-file { color: var(--text-muted, #6b6480); }
.sdq-cat-pick-meta { font-size: calc(11px * var(--font-scale, 1)); line-height: 1.4; color: inherit; }
.sdq-cat-pane { margin: 0 0 8px; }
.sdq-cat-pane-title { margin: 0 0 10px; font-size: calc(14px * var(--font-scale, 1)); }
.sdq-cat-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }
.sdq-cat-field > span { font-size: 12px; font-weight: 600; color: var(--text-muted, #6b6480); }
.sdq-cat-field input[type=text],
.sdq-cat-hist input[type=text] { width: 100%; min-height: var(--input-h, 40px); }
.sdq-cat-fwline { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: end; margin-top: 10px; }
.sdq-cat-fwnote { margin: 6px 0 0; font-size: 12px; }
.sdq-cat-camp { display: flex; align-items: center; gap: 6px; white-space: nowrap; font-size: 13px; padding-bottom: 8px; }
.sdq-cat-old { margin: 14px 0 0; padding: 12px 0 0; border-top: 1px dashed var(--border, #dde3ec); }
.sdq-cat-old-lab { display: block; font-size: 13px; font-weight: 700; margin: 0 0 8px; }
.sdq-cat-hist-head, .sdq-cat-hist {
  display: grid; grid-template-columns: minmax(0, 1fr) minmax(140px, 180px); gap: 8px 10px; margin-bottom: 6px;
}
.sdq-cat-hist-head { margin-bottom: 4px; }
.sdq-cat-hist-head span { font-size: 12px; font-weight: 600; color: var(--text-muted, #6b6480); }
@media (max-width: 720px) {
  .sdq-cat-fwline, .sdq-cat-hist-head, .sdq-cat-hist { grid-template-columns: 1fr; }
  .sdq-cat-hist-head { display: none; }
}
.sdq-cat-act { margin-top: 10px; }
</style>
<?php page_footer(); ?>
