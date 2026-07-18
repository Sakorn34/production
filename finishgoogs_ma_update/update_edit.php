<?php
/**
 * update_edit.php — แก้ไขรายการอัปเดต FW/HW ที่บันทึกไว้แล้ว
 *
 * เปิดด้วย ?id= รหัส update_logs · บันทึกแล้วกลับหน้ารายการของรุ่นนั้น
 *
 * Flow:
 *   1. โหลดแถว update_logs + ข้อมูลเครื่อง
 *   2. แก้ไขฟอร์มแล้ว POST → UPDATE update_logs
 *   3. ถ้าเป็น firmware/hardware อาจ sync ค่าปัจจุบันของเครื่องตาม update_new.php
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$logId = (int)(isset($_GET['id']) ? $_GET['id'] : (isset($_POST['update_id']) ? $_POST['update_id'] : 0));

/**
 * โหลดรายการอัปเดตพร้อมข้อมูลเครื่อง
 *
 * @param int $id รหัส update_logs
 * @return array<string,mixed>|null
 */
function load_update_log($id)
{
    return qr("SELECT u.*, a.asset_code, a.current_fw_version, a.product_id, p.name pname
               FROM update_logs u
               JOIN assets a ON a.id=u.asset_id
               JOIN products p ON p.id=a.product_id
               WHERE u.id=?", 'i', [$id])->fetch_assoc() ?: null;
}

$log = $logId > 0 ? load_update_log($logId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_update'])) {
    csrf_check();
    $logId = (int)$_POST['update_id'];
    $log = load_update_log($logId);
    if (!$log) {
        flash_set('ไม่พบรายการที่จะแก้ไข', 'err');
        header('Location: ' . BASE_URL . '/updates.php');
        exit;
    }

    $type = in_array($_POST['update_type'], ['firmware', 'hardware', 'other'], true) ? $_POST['update_type'] : 'other';
    $comp = trim($_POST['component_name']);
    $old = trim($_POST['old_value']);
    $new = trim($_POST['new_value']);
    $detail = trim($_POST['detail']);
    $madeBy = trim($_POST['made_by']) ?: actor_name();
    $updatedAt = trim($_POST['updated_at'] ?? '');
    if ($updatedAt === '') {
        $updatedAt = $log['updated_at'];
    } else {
        $updatedAt = str_replace('T', ' ', $updatedAt);
        if (strlen($updatedAt) === 16) {
            $updatedAt .= ':00';
        }
    }

    $img1 = save_upload('image1', 'updates') ?: $log['image1'];
    $img2 = save_upload('image2', 'updates') ?: $log['image2'];

    q("UPDATE update_logs
       SET updated_at=?, update_type=?, component_name=?, old_value=?, new_value=?, detail=?, image1=?, image2=?, made_by=?
       WHERE id=?",
      'sssssssssi',
      [$updatedAt, $type, $comp ?: null, $old ?: null, $new ?: null, $detail ?: null, $img1, $img2, $madeBy, $logId]);

    if ($type === 'firmware' && $new !== '') {
        q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$new, $log['asset_id']]);
    }
    if ($type === 'hardware' && $comp !== '' && $new !== '') {
        q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)
           ON DUPLICATE KEY UPDATE component_value=VALUES(component_value)", 'iss', [$log['asset_id'], $comp, $new]);
    }

    flash_set('แก้ไขรายการอัปเดตของ ' . $log['asset_code'] . ' แล้ว');
    $back = trim($_POST['back'] ?? '');
    if ($back !== '' && strpos($back, BASE_URL) !== 0) {
        $back = '';
    }
    header('Location: ' . ($back !== '' ? $back : BASE_URL . '/updates.php?product=' . (int)$log['product_id']));
    exit;
}

page_header('แก้ไขรายการอัปเดต FW/HW');
if (!$log) {
    echo '<p class="muted">ไม่พบรายการ — <a href="' . h(BASE_URL . '/updates.php') . '">กลับหน้ารายการ</a></p>';
    page_footer();
    exit;
}

$backUrl = isset($_GET['back']) ? $_GET['back'] : (BASE_URL . '/updates.php?product=' . (int)$log['product_id']);
if (strpos($backUrl, BASE_URL) !== 0) {
    $backUrl = BASE_URL . '/updates.php?product=' . (int)$log['product_id'];
}
$dtLocal = $log['updated_at'] ? date('Y-m-d\TH:i', strtotime($log['updated_at'])) : '';

$comps = qr("SELECT component_name, component_value FROM asset_components WHERE asset_id=?", 'i', [(int)$log['asset_id']]);
$compData = [];
while ($c = $comps->fetch_assoc()) {
    $compData[$c['component_name']] = $c['component_value'];
}
$compChoices = $compData;
$compOptions = [];
foreach (effective_fields((int)$log['product_id'], 'update') as $f) {
    if (!isset($compChoices[$f['name']])) {
        $compChoices[$f['name']] = '';
    }
    $compOptions[$f['name']] = $f['options'];
}
?>
<p>
  แก้ไขรายการของเครื่อง <b><?= h($log['asset_code']) ?></b> (<?= h($log['pname']) ?>)
  · <a href="<?= h(BASE_URL . '/asset.php?id=' . (int)$log['asset_id']) ?>">เปิดหน้าเครื่อง</a>
</p>
<form method="post" class="formgrid form-narrow" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <input type="hidden" name="save_update" value="1">
  <input type="hidden" name="update_id" value="<?= (int)$logId ?>">
  <input type="hidden" name="back" value="<?= h($backUrl) ?>">

  <label>วันที่อัปเดต</label>
  <input type="datetime-local" name="updated_at" value="<?= h($dtLocal) ?>" required>

  <label>ประเภทการอัปเดต</label>
  <select name="update_type" id="utype" onchange="fillOld()">
    <option value="firmware" <?= $log['update_type'] === 'firmware' ? 'selected' : '' ?>>Firmware</option>
    <option value="hardware" <?= $log['update_type'] === 'hardware' ? 'selected' : '' ?>>Hardware (เปลี่ยนชิ้นส่วน)</option>
    <option value="other" <?= $log['update_type'] === 'other' ? 'selected' : '' ?>>อื่นๆ</option>
  </select>

  <label>ชิ้นส่วน (ถ้าเป็น HW)</label>
  <div>
    <input type="text" name="component_name" id="comp" list="complist"
           value="<?= h($log['component_name'] ?? '') ?>" placeholder="เช่น Display, Main Board" onchange="fillOld()">
    <datalist id="complist">
      <?php foreach ($compChoices as $k => $v) { ?><option value="<?= h($k) ?>"><?php } ?>
    </datalist>
  </div>

  <label>ค่าเดิม</label>
  <input type="text" name="old_value" id="oldv" value="<?= h($log['old_value'] ?? '') ?>">

  <label>ค่าใหม่</label>
  <div>
    <input type="text" name="new_value" id="newv" list="newvlist" value="<?= h($log['new_value'] ?? '') ?>">
    <datalist id="newvlist"></datalist>
  </div>

  <label class="full">รายละเอียด</label>
  <textarea name="detail" class="full field-note" rows="2"><?= h($log['detail'] ?? '') ?></textarea>

  <label>ผู้บันทึก</label>
  <input type="text" name="made_by" value="<?= h($log['made_by'] ?: actor_name()) ?>">

  <label>รูปประกอบ 1</label>
  <div>
    <?php if (!empty($log['image1'])) { ?>
      <a href="<?= h(img_url($log['image1'])) ?>" target="_blank" rel="noopener"><img src="<?= h(img_url($log['image1'])) ?>" class="thumb" loading="lazy"></a><br>
    <?php } ?>
    <input type="file" name="image1" accept="image/*">
    <div class="muted" style="font-size:12px">ว่าง = ใช้รูปเดิม</div>
  </div>

  <label>รูปประกอบ 2</label>
  <div>
    <?php if (!empty($log['image2'])) { ?>
      <a href="<?= h(img_url($log['image2'])) ?>" target="_blank" rel="noopener"><img src="<?= h(img_url($log['image2'])) ?>" class="thumb" loading="lazy"></a><br>
    <?php } ?>
    <input type="file" name="image2" accept="image/*">
    <div class="muted" style="font-size:12px">ว่าง = ใช้รูปเดิม</div>
  </div>

  <div class="full" style="display:flex; gap:8px; flex-wrap:wrap">
    <button type="submit">💾 บันทึกการแก้ไข</button>
    <a class="btn btn-line" href="<?= h($backUrl) ?>">ยกเลิก</a>
  </div>
</form>
<script>
var comps = <?= json_encode($compData, JSON_UNESCAPED_UNICODE) ?>;
var compOptions = <?= json_encode($compOptions, JSON_UNESCAPED_UNICODE) ?>;
var curFw = <?= json_encode($log['current_fw_version']) ?>;
function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
function fillOld(){
  var t = document.getElementById('utype').value;
  var c = document.getElementById('comp').value;
  var o = document.getElementById('oldv');
  if (t === 'firmware' && !o.value) o.value = curFw || '';
  else if (t !== 'firmware' && comps[c] && !o.dataset.touched) o.value = comps[c];
  var dl = document.getElementById('newvlist');
  var opts = compOptions[c] || [];
  dl.innerHTML = opts.map(function(v){ return '<option value="' + esc(v) + '">'; }).join('');
}
document.getElementById('comp').addEventListener('input', fillOld);
document.getElementById('oldv').addEventListener('input', function(){ this.dataset.touched = '1'; });
fillOld();
</script>
<?php page_footer();
