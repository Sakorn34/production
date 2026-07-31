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
?>
<p>เครื่อง <b><?= h($a['asset_code']) ?></b> (<?= h($a['pname']) ?>) — FW ปัจจุบัน: <?= h($a['current_fw_version'] ?: '-') ?></p>
<form method="post" class="formgrid form-narrow" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="asset_id" value="<?= $aid ?>">

  <label>ประเภทการอัปเดต</label>
  <select name="update_type" id="utype" onchange="fillOld()">
    <option value="firmware">Firmware</option>
    <option value="hardware">Hardware (เปลี่ยนชิ้นส่วน)</option>
    <option value="other">อื่นๆ</option>
  </select>

  <label>ชิ้นส่วน (ถ้าเป็น HW)</label>
  <div>
    <input type="text" name="component_name" id="comp" list="complist" placeholder="เช่น Display, Main Board" onchange="fillOld()">
    <datalist id="complist">
      <?php foreach ($compChoices as $k => $v) { ?><option value="<?= h($k) ?>"><?php } ?>
    </datalist>
  </div>

  <label>ค่าเดิม</label>
  <input type="text" name="old_value" id="oldv" value="<?= h($a['current_fw_version']) ?>">

  <label>ค่าใหม่</label>
  <div id="newv-slot">
    <input type="text" name="new_value" id="newv" placeholder="เช่น 2.6.0 หรือ V3" required>
  </div>

  <label class="full">รายละเอียด</label>
  <textarea name="detail" class="full field-note" rows="2"></textarea>

  <label>รูปประกอบ 1</label>
  <input type="file" name="image1" accept="image/*">
  <label>รูปประกอบ 2</label>
  <input type="file" name="image2" accept="image/*">

  <div class="full"><button type="submit"><?= ui_btn_label('save', 'บันทึกการอัปเดต') ?></button></div>
</form>
<script>
var comps = <?= json_encode($compData, JSON_UNESCAPED_UNICODE) ?>;
var compOptions = <?= json_encode($compOptions, JSON_UNESCAPED_UNICODE) ?>;
var compInputModes = <?= json_encode($compInputModes, JSON_UNESCAPED_UNICODE) ?>;
var curFw = <?= json_encode($a['current_fw_version']) ?>;
function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function renderNewValue(){
  var t = document.getElementById('utype').value;
  var c = document.getElementById('comp').value;
  var slot = document.getElementById('newv-slot');
  var mode = 'chip_single_free';
  var opts = [];
  if (t === 'firmware') {
    mode = 'chip_single_free';
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
renderNewValue();
</script>
<?php page_footer();
