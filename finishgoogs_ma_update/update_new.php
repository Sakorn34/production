<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_can('update');

$aid = (int)(isset($_GET['asset']) ? $_GET['asset'] : (isset($_POST['asset_id']) ? $_POST['asset_id'] : 0));
$a = $aid ? qr("SELECT a.id, a.asset_code, a.current_fw_version, a.product_id, p.name pname FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$aid])->fetch_assoc() : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$a) exit('ไม่พบเครื่อง');
    $type = in_array($_POST['update_type'], ['firmware','hardware','other'], true) ? $_POST['update_type'] : 'other';
    if ($type === 'firmware' && !product_show_fw((int) $a['product_id'])) {
        $type = 'other';
    }
    $comp = trim($_POST['component_name']);
    $old  = trim($_POST['old_value']);
    $new  = trim($_POST['new_value']);
    $detail = trim($_POST['detail']);
    $img1 = save_upload('image1', 'updates');
    $img2 = save_upload('image2', 'updates');
    q("INSERT INTO update_logs (asset_id,updated_at,update_type,component_name,old_value,new_value,detail,image1,image2,made_by)
       VALUES (?,NOW(),?,?,?,?,?,?,?,?)", 'issssssss',
      [$a['id'], $type, $comp ?: null, $old ?: null, $new ?: null, $detail ?: null, $img1, $img2, actor_name()]);
    if ($type === 'firmware' && $new !== '') {
        q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$new, $a['id']]);
    }
    if ($type === 'hardware' && $comp !== '' && $new !== '') {
        q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE component_value=VALUES(component_value)", 'iss', [$a['id'], $comp, $new]);
    }
    flash_set('บันทึกการอัปเดตของ ' . $a['asset_code'] . ' แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $a['id']); exit;
}

page_header('บันทึกอัปเดต FW/HW');
if (!$a) { ?>
  <form class="filter" method="get">
    <input type="text" name="code" placeholder="กรอกรหัสเครื่อง เช่น BP23021294" required>
    <button formaction="<?= BASE_URL ?>/asset.php" type="submit">ค้นหาเครื่องก่อน</button>
  </form>
<?php page_footer(); exit; }

// เติมค่าเดิมจากชิ้นส่วนปัจจุบันของเครื่อง
$comps = qr("SELECT component_name, component_value FROM asset_components WHERE asset_id=?", 'i', [$aid]);
$compData = [];
while ($c = $comps->fetch_assoc()) $compData[$c['component_name']] = $c['component_value'];
// รายชื่อชิ้นส่วนให้เลือก = config admin (ถ้ามี) รวมกับประวัติของรุ่น + ชิ้นส่วนของเครื่องนี้
$compChoices = $compData; // ชื่อ => ค่าปัจจุบัน
$compOptions = [];        // ชื่อ => [ตัวเลือกค่า]
$compInputModes = [];     // ชื่อ => input_mode
foreach (effective_fields($a['product_id'], 'update') as $f) {
    if (!isset($compChoices[$f['name']])) $compChoices[$f['name']] = '';
    $compOptions[$f['name']] = $f['options'];
    $compInputModes[$f['name']] = normalize_input_mode($f['input_mode'] ?? 'chip_single_free');
}
$updShowFw = product_show_fw((int) $a['product_id']);
if ($updShowFw) {
    $compOptions['Firmware'] = effective_fw_options((int) $a['product_id']);
    $compInputModes['Firmware'] = effective_fw_input_mode((int) $a['product_id']);
}
?>
<p>เครื่อง <b><?= h($a['asset_code']) ?></b> (<?= h($a['pname']) ?>) — FW ปัจจุบัน: <?= h($a['current_fw_version'] ?: '-') ?></p>
<form method="post" class="upd-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="asset_id" value="<?= $aid ?>">

  <div class="upd-cols">
    <div class="panel upd-card">
      <h3 class="upd-card-title"><?= ui_icon_html('updates', 16, 'h-svg') ?><span>ข้อมูลการอัปเดต</span></h3>

      <div class="upd-field upd-field-half">
        <label for="utype">ประเภทการอัปเดต</label>
        <select name="update_type" id="utype" onchange="fillOld()">
          <?php if ($updShowFw) { ?><option value="firmware">Firmware</option><?php } ?>
          <option value="hardware"<?= !$updShowFw ? ' selected' : '' ?>>Hardware (เปลี่ยนชิ้นส่วน)</option>
          <option value="other">อื่นๆ</option>
        </select>
      </div>

      <div class="upd-field" id="comp-field">
        <label for="comp">ชิ้นส่วน <span class="muted">(เฉพาะประเภท Hardware)</span></label>
        <input type="text" name="component_name" id="comp" list="complist" placeholder="เช่น Display, Main Board" onchange="fillOld()">
        <datalist id="complist">
          <?php foreach ($compChoices as $k => $v) { ?><option value="<?= h($k) ?>"><?php } ?>
        </datalist>
      </div>

      <?php // ค่าเดิม → ค่าใหม่ คู่กันเหมือนหน้าแก้ไข · #newv-slot ถูก JS แทนที่ด้วย chip dropdown ?>
      <div class="upd-change">
        <div class="upd-field">
          <label for="oldv">ค่าเดิม</label>
          <input type="text" name="old_value" id="oldv" value="<?= h($a['current_fw_version']) ?>" placeholder="ค่าก่อนเปลี่ยน">
        </div>
        <div class="upd-arrow" aria-hidden="true">→</div>
        <?php // label ต้องอยู่นอก #newv-slot เพราะ renderNewValue() เขียนทับ innerHTML ของ slot ทั้งก้อน ?>
        <div class="upd-field">
          <label for="newv">ค่าใหม่</label>
          <div id="newv-slot">
            <input type="text" name="new_value" id="newv" placeholder="เช่น 2.6.0 หรือ V3" required>
          </div>
        </div>
      </div>

      <div class="upd-field">
        <label for="detail">รายละเอียด</label>
        <textarea name="detail" id="detail" rows="3" placeholder="อธิบายสิ่งที่ทำ เช่น เปลี่ยนรุ่นกล้อง ตัดเสายาว 27cm."></textarea>
      </div>
    </div>

    <div class="panel upd-card">
      <h3 class="upd-card-title"><?= ui_icon_html('camera', 16, 'h-svg') ?><span>รูปประกอบ</span></h3>
      <?php foreach ([1, 2] as $slot) { ?>
      <div class="upd-img-slot">
        <div class="upd-img-preview"><span class="upd-img-empty">ยังไม่มีรูป</span></div>
        <div class="upd-img-input">
          <label for="image<?= $slot ?>">รูปประกอบ <?= $slot ?></label>
          <input type="file" id="image<?= $slot ?>" name="image<?= $slot ?>" accept="image/*">
        </div>
      </div>
      <?php } ?>
    </div>
  </div>

  <div class="upd-actions">
    <button type="submit" class="btn btn-primary"><?= ui_btn_label('save', 'บันทึกการอัปเดต') ?></button>
    <a class="btn btn-line" href="<?= BASE_URL ?>/asset.php?id=<?= $aid ?>">ยกเลิก</a>
  </div>
</form>
<script>
var comps = <?= json_encode($compData, JSON_UNESCAPED_UNICODE) ?>;
var compOptions = <?= json_encode($compOptions, JSON_UNESCAPED_UNICODE) ?>;
var compInputModes = <?= json_encode($compInputModes, JSON_UNESCAPED_UNICODE) ?>;
var updShowFw = <?= $updShowFw ? 'true' : 'false' ?>;
var updFwInputMode = <?= json_encode(effective_fw_input_mode((int) $a['product_id']), JSON_UNESCAPED_UNICODE) ?>;
var curFw = <?= json_encode($a['current_fw_version']) ?>;
function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function renderNewValue(){
  var slot = document.getElementById('newv-slot');
  // chipDdHtml ถูกนิยามในสคริปต์ของ page_footer() ซึ่งออกมาหลังบล็อกนี้
  // ถ้าเรียกตอนยังไม่มีจะโยน ReferenceError แล้วขวางคำสั่งที่เหลือทั้งหมด
  // (ใช้การ์ดแบบเดียวกับ ma.php) — ไม่มีก็ปล่อยเป็น input ธรรมดาไป
  if (!slot || typeof chipDdHtml !== 'function') return;
  var t = document.getElementById('utype').value;
  var c = document.getElementById('comp').value;
  var mode = 'chip_single_free';
  var opts = [];
  if (t === 'firmware') {
    mode = updFwInputMode || 'chip_single_free';
    opts = compOptions['Firmware'] || compOptions['FW'] || [];
  } else if (c) {
    mode = compInputModes[c] || 'chip_single_free';
    opts = compOptions[c] || [];
  }
  slot.innerHTML = chipDdHtml('new_value', '', opts, '', mode);
  initChipDd(slot);
}
function fillOld(){
  var t = document.getElementById('utype').value;
  var c = document.getElementById('comp').value;
  var o = document.getElementById('oldv');
  if (t === 'firmware') o.value = curFw || '';
  else if (comps[c]) o.value = comps[c];
  renderNewValue();
}
document.getElementById('comp').addEventListener('input', fillOld);
document.getElementById('utype').addEventListener('change', fillOld);

// ซ่อนช่องชิ้นส่วนเมื่อไม่ใช่ Hardware — ให้เหมือนหน้าแก้ไข
function syncCompField(){
  var el = document.getElementById('comp-field');
  if (el) el.hidden = document.getElementById('utype').value !== 'hardware';
}
document.getElementById('utype').addEventListener('change', syncCompField);
document.getElementById('utype').addEventListener('change', function(){
  if (!updShowFw && document.getElementById('utype').value === 'firmware') {
    document.getElementById('utype').value = 'hardware';
    syncCompField();
  }
});

// รอให้สคริปต์ของ footer นิยาม chipDdHtml เสร็จก่อน ช่อง "ค่าใหม่" จึงจะเป็น dropdown ได้จริง
document.addEventListener('DOMContentLoaded', function () {
  renderNewValue();
  syncCompField();
});
</script>
<?php page_footer();
