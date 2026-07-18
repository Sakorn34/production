<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

// เปิดด้วย id หรือ code (จากการสแกน QR)
if (isset($_GET['code'])) {
    $a0 = qr("SELECT id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [trim($_GET['code']), trim($_GET['code'])])->fetch_assoc();
    if ($a0) { header('Location: ' . BASE_URL . '/asset.php?id=' . $a0['id']); exit; }
    flash_set('ไม่พบเครื่องรหัส "' . $_GET['code'] . '"', 'err');
    header('Location: ' . BASE_URL . '/assets.php'); exit;
}
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$a = qr("SELECT a.*, p.name pname, p.icon_path, c.name cust
         FROM assets a JOIN products p ON p.id=a.product_id
         LEFT JOIN customers c ON c.id=a.current_customer_id WHERE a.id=?", 'i', [$id])->fetch_assoc();
if (!$a) { http_response_code(404); exit('ไม่พบเครื่องนี้'); }

// เปลี่ยนสถานะเครื่อง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    csrf_check();
    $ns = $_POST['set_status'];
    if (in_array($ns, status_list(), true)) {
        q("UPDATE assets SET status=? WHERE id=?", 'si', [$ns, $id]);
        q("INSERT INTO stock_movements (asset_id,moved_at,direction,reason,made_by) VALUES (?,NOW(),?,?,?)",
          'isss', [$id, $ns === 'new' ? 'in' : 'out', 'เปลี่ยนสถานะเป็น ' . status_th($ns), actor_name()]);
        flash_set('เปลี่ยนสถานะเป็น "' . status_th($ns) . '" เรียบร้อยแล้ว');
    }
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// แก้ไข FW ปัจจุบันของเครื่อง (แก้ค่าผิด/อัปเดตโดยตรง)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_fw'])) {
    csrf_check();
    $fw = trim($_POST['current_fw_version']);
    if (strlen($fw) > 50) { flash_set('FW ยาวเกิน 50 ตัวอักษร', 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    q("UPDATE assets SET current_fw_version=NULLIF(?,'') WHERE id=?", 'si', [$fw, $id]);
    flash_set($fw !== '' ? 'บันทึก FW เป็น "' . $fw . '" แล้ว' : 'ล้างค่า FW แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// แก้ไขข้อมูลเครื่อง (บันทึกผิด/พิมพ์ผิด)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    csrf_check();
    $newCode = trim($_POST['asset_code']);
    $newPid  = (int)$_POST['product_id'];
    if ($newCode === '') { flash_set('รหัสเครื่องห้ามว่าง', 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    $dup = qr("SELECT id FROM assets WHERE asset_code=? AND id<>?", 'si', [$newCode, $id])->fetch_assoc();
    if ($dup) { flash_set("รหัส $newCode ถูกใช้กับเครื่องอื่นแล้ว", 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    // คำนวณเลขรันนิ่งใหม่จากรหัส (ถ้ารุ่นเป็นแบบ gen และรหัสเข้าสูตร)
    $np = qr("SELECT code_mode, code_prefix, running_digits, code_use_prefix, code_use_year, code_use_month FROM products WHERE id=?", 'i', [$newPid])->fetch_assoc();
    $run = null;
    if ($np && $np['code_mode'] === 'generated') {
        $run = parse_running_from_asset_code($np, $newCode);
    }
    q("UPDATE assets SET asset_code=?, factory_serial=?, product_id=?, running_no=?, produced_at=NULLIF(?,''), lot_label=NULLIF(?,''), note=NULLIF(?,'') WHERE id=?",
      'ssiisssi', [$newCode, $newCode, $newPid, $run, $_POST['produced_at'], trim($_POST['lot_label']), trim($_POST['note']), $id]);
    share_upsert_asset($id, $a['asset_code']); // sync ตารางแชร์ (ถ้าเปลี่ยนรหัส แถว serial เดิมจะถูกแทนที่)
    flash_set('แก้ไขข้อมูลเครื่อง ' . $newCode . ' เรียบร้อยแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// แก้ไข/ลบชิ้นส่วนฮาร์ดแวร์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_component'])) {
    csrf_check();
    $oldName = trim($_POST['old_name']);
    $newName = trim($_POST['component_name']);
    $newVal  = trim($_POST['component_value']);
    if ($oldName !== '' && $newName !== '') {
        q("DELETE FROM asset_components WHERE asset_id=? AND component_name=?", 'is', [$id, $oldName]);
        q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)", 'iss', [$id, $newName, $newVal]);
        flash_set('บันทึกชิ้นส่วนแล้ว');
    }
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_component'])) {
    csrf_check();
    $nm = trim($_POST['component_name']);
    if ($nm !== '') q("DELETE FROM asset_components WHERE asset_id=? AND component_name=?", 'is', [$id, $nm]);
    flash_set('ลบชิ้นส่วนแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบบันทึกผลิต
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_production'])) {
    csrf_check();
    $prId = (int)$_POST['record_id'];
    q("DELETE FROM production_records WHERE id=? AND asset_id=?", 'ii', [$prId, $id]);
    flash_set('ลบบันทึกผลิตแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการอัปเดต FW/HW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_update'])) {
    csrf_check();
    $uid = (int)$_POST['record_id'];
    q("DELETE FROM update_logs WHERE id=? AND asset_id=?", 'ii', [$uid, $id]);
    flash_set('ลบรายการอัปเดตแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการเบิกอะไหล่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_part_move'])) {
    csrf_check();
    $mid = (int)$_POST['record_id'];
    $old = qr("SELECT part_id, qty, direction, tech_stock_out_id FROM part_movements WHERE id=? AND ref_asset_id=?", 'ii', [$mid, $id])->fetch_assoc();
    if ($old && $old['direction'] === 'out') {
        $synced = false;
        if (!empty($old['tech_stock_out_id']) && function_exists('production_sync_delete_stock_out_from_movement')) {
            $synced = production_sync_delete_stock_out_from_movement($mid);
        }
        if (!$synced) {
            $ret = tech_parts_stock_in_by_part_id((int)$old['part_id'], (float)$old['qty'], 'ลบรายการเบิก (เครื่อง)', actor_name());
            if (!$ret['ok']) {
                flash_set($ret['error'], 'err');
                header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
                exit;
            }
            q("DELETE FROM part_movements WHERE id=? AND ref_asset_id=?", 'ii', [$mid, $id]);
        }
    } elseif ($old) {
        q("DELETE FROM part_movements WHERE id=? AND ref_asset_id=?", 'ii', [$mid, $id]);
    }
    flash_set('ลบรายการเบิกอะไหล่แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการ MA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ma_asset'])) {
    csrf_check();
    $mid = (int)$_POST['record_id'];
    q("DELETE FROM ma_records WHERE id=? AND asset_id=?", 'ii', [$mid, $id]);
    flash_set('ลบรายการ MA แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบเครื่อง (ลบประวัติทั้งหมดของเครื่องนี้ด้วย)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    csrf_check();
    $delCode = $a['asset_code'];
    if (!asset_delete_full($id)) {
        flash_set('ลบเครื่องไม่สำเร็จ', 'err');
        header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
        exit;
    }
    flash_set("ลบเครื่อง $delCode และประวัติทั้งหมดเรียบร้อยแล้ว");
    header('Location: ' . BASE_URL . '/assets.php'); exit;
}

$components = qr("SELECT component_name, component_value FROM asset_components WHERE asset_id=? ORDER BY component_name", 'i', [$id]);
$productOptions = qr("SELECT id, name FROM products WHERE is_active=1 ORDER BY name");

/** ตัวเลือก FW ที่เคยใช้กับรุ่นนี้ (สำหรับ datalist) */
$fwSuggest = [];
$stdFw = product_std_fields((int)$a['product_id']);
if (!empty($stdFw['fw']['options'])) {
    foreach ($stdFw['fw']['options'] as $o) {
        if ($o !== '' && !in_array($o, $fwSuggest, true)) $fwSuggest[] = $o;
    }
}
foreach (effective_ma_fw_options((int)$a['product_id']) as $o) {
    if ($o !== '' && !in_array($o, $fwSuggest, true)) $fwSuggest[] = $o;
}
$resFw = qr("SELECT pr.fw_version v FROM production_records pr JOIN assets ax ON ax.id=pr.asset_id
             WHERE ax.product_id=? AND pr.fw_version IS NOT NULL AND TRIM(pr.fw_version)<>'' AND pr.fw_version<>'-'
             GROUP BY pr.fw_version ORDER BY MAX(pr.id) DESC LIMIT 12", 'i', [(int)$a['product_id']]);
while ($rf = $resFw->fetch_assoc()) {
    if (!in_array($rf['v'], $fwSuggest, true)) $fwSuggest[] = $rf['v'];
}
$resFw2 = qr("SELECT u.new_value v FROM update_logs u JOIN assets ax ON ax.id=u.asset_id
              WHERE ax.product_id=? AND u.update_type='firmware' AND u.new_value IS NOT NULL AND TRIM(u.new_value)<>''
              GROUP BY u.new_value ORDER BY MAX(u.id) DESC LIMIT 12", 'i', [(int)$a['product_id']]);
while ($rf = $resFw2->fetch_assoc()) {
    if (!in_array($rf['v'], $fwSuggest, true)) $fwSuggest[] = $rf['v'];
}
if ($a['current_fw_version'] && !in_array($a['current_fw_version'], $fwSuggest, true)) {
    array_unshift($fwSuggest, $a['current_fw_version']);
}

// แจ้งเตือนอะไหล่ครบกำหนดเปลี่ยน (ฟังก์ชันกลางใน config.php — ใช้ร่วมกับฟอร์ม MA)
$partAlertsHtml = part_alerts_html($id, $a['produced_at']);

// รวม timeline ทุกประเภท (ฟังก์ชันกลาง includes/timeline.php — ใช้ร่วมกับ popup เจาะลึกจาก dashboard)
require __DIR__ . "/includes/timeline.php";
require __DIR__ . "/includes/list_search.php";
$tlData = asset_timeline_items($id);
$tl = $tlData["tl"];
$partsUsed = $tlData["partsUsed"];
$partsSummary = asset_parts_withdraw_summary($id);

/** ปุ่มแก้ไข/ลบรายการในประวัติ */
function asset_tl_actions($e, $assetId) {
    if (empty($e['kind']) || empty($e['rid'])) return '';
    $id = (int)$e['rid'];
    $out = '<div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap">';
    if ($e['kind'] === 'update') {
        $back = urlencode(BASE_URL . '/asset.php?id=' . $assetId);
        $out .= '<a class="btn btn-sm btn-line" href="' . BASE_URL . '/update_edit.php?id=' . $id . '&back=' . $back . '">✏️ แก้ไข</a>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_update" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger" type="submit">🗑️ ลบ</button></form>';
    } elseif ($e['kind'] === 'ma') {
        $out .= '<a class="btn btn-sm btn-line" href="' . BASE_URL . '/ma.php?edit=' . $id . '">✏️ แก้ไข</a>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการ MA นี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_ma_asset" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger" type="submit">🗑️ ลบ</button></form>';
    } elseif ($e['kind'] === 'part_move') {
        $out .= '<button class="btn btn-sm btn-line" onclick="showListModal(' . h(json_encode('แก้ไขรายการเบิก', JSON_UNESCAPED_UNICODE)) . ',' . h(json_encode(BASE_URL . '/parts.php?ajax=edit_move_form&id=' . $id)) . ',\'\')">✏️ แก้ไข</button>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการเบิกนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_part_move" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger" type="submit">🗑️ ลบ</button></form>';
    } elseif ($e['kind'] === 'production') {
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบบันทึกผลิตนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_production" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger" type="submit">🗑️ ลบ</button></form>';
    }
    return $out . '</div>';
}

page_header('เครื่อง ' . $a['asset_code']);
?>
<div class="asset-head">
  <div><?= img_tag($a['icon_path'], $a['pname'], 'thumb-lg') ?></div>
  <div class="info">
    <dl>
      <dt>หมายเลขสินค้า</dt><dd><b><?= h($a['asset_code']) ?></b>
        <?php if ($a['running_no']) { ?><span class="muted" style="font-size:12px"> (running <?= (int)$a['running_no'] ?>)</span><?php } ?>
      </dd>
      <dt>รุ่น</dt><dd><?= h($a['pname']) ?></dd>
      <dt>สถานะ</dt><dd><?= status_badge($a['status']) ?></dd>
      <dt>ลูกค้าปัจจุบัน</dt><dd><?= h($a['cust'] ?: '-') ?></dd>
      <dt>ผลิตเมื่อ</dt><dd><?= dthai($a['produced_at']) ?><?= $a['lot_label'] ? ' (Lot ' . h($a['lot_label']) . ')' : '' ?></dd>
      <dt>FW ปัจจุบัน</dt>
      <dd>
        <form method="post" class="fw-inline">
          <?= csrf_field() ?><input type="hidden" name="edit_fw" value="1">
          <input type="text" name="current_fw_version" value="<?= h($a['current_fw_version']) ?>" placeholder="เช่น 2.6.6c" list="fw-suggest" maxlength="50" autocomplete="off">
          <button class="btn-sm" type="submit">💾 บันทึก FW</button>
        </form>
        <datalist id="fw-suggest">
          <?php foreach ($fwSuggest as $fo) { ?><option value="<?= h($fo) ?>"><?php } ?>
        </datalist>
      </dd>
      <?php if ($a['note']) { ?><dt>หมายเหตุ</dt><dd><?= h($a['note']) ?></dd><?php } ?>
    </dl>
    <div style="margin-top:12px; display:flex; gap:8px; flex-wrap:wrap">
      <a class="btn btn-sm" href="<?= BASE_URL ?>/update_new.php?asset=<?= $id ?>">⚙️ บันทึกอัปเดต FW/HW</a>
      <a class="btn btn-sm" href="<?= BASE_URL ?>/ma.php?record=<?= $id ?>">📅 บันทึก MA</a>
      <form method="post" style="display:inline-flex; gap:6px">
        <?= csrf_field() ?>
        <select name="set_status">
          <?php foreach (status_list() as $s) { ?>
            <option value="<?= $s ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= h(status_th($s)) ?></option>
          <?php } ?>
        </select>
        <button class="btn-sm" type="submit">เปลี่ยนสถานะ</button>
      </form>
    </div>
  </div>
  <?php if ($components->num_rows) { ?>
  <div>
    <b style="font-size:14px">ชิ้นส่วนฮาร์ดแวร์</b>
    <table class="list" style="margin-top:6px">
      <?php while ($c = $components->fetch_assoc()) { ?>
        <tr>
          <td class="muted"><?= h($c['component_name']) ?></td>
          <td><?= h($c['component_value']) ?></td>
          <td style="white-space:nowrap">
            <details style="display:inline">
              <summary class="btn btn-sm btn-line" style="list-style:none; cursor:pointer; display:inline-block">✏️</summary>
              <form method="post" style="margin-top:6px; display:grid; gap:4px; min-width:200px">
                <?= csrf_field() ?><input type="hidden" name="edit_component" value="1">
                <input type="hidden" name="old_name" value="<?= h($c['component_name']) ?>">
                <input type="text" name="component_name" value="<?= h($c['component_name']) ?>" required>
                <input type="text" name="component_value" value="<?= h($c['component_value']) ?>">
                <button class="btn-sm" type="submit">บันทึก</button>
              </form>
            </details>
            <form method="post" style="display:inline" onsubmit="return confirm('ลบชิ้นส่วนนี้?')">
              <?= csrf_field() ?><input type="hidden" name="del_component" value="1">
              <input type="hidden" name="component_name" value="<?= h($c['component_name']) ?>">
              <button class="btn-sm btn-danger" type="submit">🗑️</button>
            </form>
          </td>
        </tr>
      <?php } ?>
    </table>
  </div>
  <?php } ?>
</div>

<?php if ($partAlertsHtml) { ?>
<div style="margin-bottom:16px"><?= $partAlertsHtml ?></div>
<?php } ?>

<details style="margin-bottom:16px">
  <summary class="btn btn-line btn-sm" style="list-style:none; cursor:pointer; display:inline-block">✏️ แก้ไข / ลบเครื่องนี้</summary>
  <form method="post" class="formgrid form-narrow" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="edit_asset" value="1">
    <label>หมายเลขสินค้า</label><input type="text" name="asset_code" value="<?= h($a['asset_code']) ?>" required>
    <label>รุ่นสินค้า</label>
    <select name="product_id">
      <?php while ($po = $productOptions->fetch_assoc()) { ?>
        <option value="<?= $po['id'] ?>" <?= (int)$po['id'] === (int)$a['product_id'] ? 'selected' : '' ?>><?= h($po['name']) ?></option>
      <?php } ?>
    </select>
    <label>วันที่ผลิต</label><input type="date" name="produced_at" value="<?= h($a['produced_at']) ?>">
    <label>Lot</label><input type="text" name="lot_label" value="<?= h($a['lot_label']) ?>">
    <label class="full">หมายเหตุ</label><textarea name="note" class="full field-note" rows="2"><?= h($a['note']) ?></textarea>
    <div class="full" style="display:flex; gap:10px; align-items:center">
      <button type="submit">💾 บันทึกการแก้ไข</button>
      <button type="submit" form="del-asset-form" class="btn-danger"
        onclick="return confirm('ลบเครื่อง <?= h($a['asset_code']) ?> พร้อมประวัติทั้งหมด (<?= count($tl) ?> รายการ)?\nการลบย้อนกลับไม่ได้!')">🗑️ ลบเครื่องนี้</button>
    </div>
  </form>
  <form method="post" id="del-asset-form"><?= csrf_field() ?><input type="hidden" name="delete_asset" value="1"></form>
</details>

<?php if ($partAlertsHtml) { ?>
<div style="margin-bottom:16px"><?= $partAlertsHtml ?></div>
<?php } ?>

<div id="parts-withdraw" style="margin-bottom:20px;padding:14px 16px;background:#fff;border:1px solid #dfe4ec;border-radius:8px">
  <b style="font-size:14px">🔩 สถานะการเบิกอะไหล่</b>
  <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:12px 20px;align-items:flex-start">
    <div>
      <span class="muted" style="font-size:12px">สถานะ</span><br>
      <?= asset_parts_status_badge($partsSummary) ?>
      <?php if ($partsSummary['bom_count'] > 0) { ?>
      <span class="muted" style="font-size:11px;margin-left:6px">BOM <?= (int)$partsSummary['bom_withdrawn'] ?>/<?= (int)$partsSummary['bom_count'] ?> ชนิด</span>
      <?php } ?>
    </div>
    <?php if ($partsSummary['out_count'] > 0) { ?>
    <div>
      <span class="muted" style="font-size:12px">อ้างอิง Stock ช่าง</span><br>
      <?php
      $docShown = [];
      foreach ($partsSummary['movements'] as $mv) {
          $sid = (int)($mv['tech_stock_out_id'] ?? 0);
          if ($sid <= 0 || isset($docShown[$sid])) continue;
          $docShown[$sid] = true;
          $doc = $partsSummary['stock_docs'][$sid] ?? null;
          $docNo = $doc ? $doc['doc_no'] : ('#' . $sid);
          echo '<span style="display:inline-block;margin:2px 6px 2px 0;font-size:12px"><code>' . h($docNo) . '</code></span>';
      }
      if (!$docShown) {
          echo '<span class="muted" style="font-size:12px">movement ในระบบ (ยังไม่ link doc)</span>';
      }
      ?>
    </div>
    <div>
      <a class="btn btn-sm btn-line" href="<?= h(parts_app_base_url() . '/pages/history.php') ?>" target="_blank">📦 ดูใน Stock ช่าง</a>
      <a class="btn btn-sm btn-line" href="<?= h(BASE_URL . '/parts.php?rs=' . urlencode($a['asset_code'])) ?>">📋 ประวัติเบิก (production)</a>
    </div>
    <?php } elseif ($partsSummary['bom_count'] > 0) { ?>
    <div><span class="muted" style="font-size:12px">รุ่นนี้มี BOM <?= (int)$partsSummary['bom_count'] ?> ชนิด — ยังไม่มีการเบิกอะไหล่สำหรับเครื่องนี้</span></div>
    <?php } else { ?>
    <div><span class="muted" style="font-size:12px">ไม่มี BOM กำหนดไว้สำหรับรุ่นนี้</span></div>
    <?php } ?>
  </div>
</div>

<?php if ($partsUsed) {
    $stockCodeMap = [];
    $scRes = qr('SELECT name, stock_code FROM parts WHERE stock_code IS NOT NULL AND TRIM(stock_code)<>""');
    while ($scRow = $scRes->fetch_assoc()) {
        $stockCodeMap[$scRow['name']] = $scRow['stock_code'];
    }
?>
<h2>🔩 อะไหล่ที่เบิกใช้กับเครื่องนี้</h2>
<table class="list" style="max-width:640px; margin-bottom:20px">
  <tr><th>อะไหล่</th><th style="text-align:right">จำนวนที่เบิกใช้</th><th>รหัส Stock</th></tr>
  <?php foreach ($partsUsed as $pu) { ?>
  <tr>
    <td><?= h($pu['name']) ?></td>
    <td style="text-align:right"><b><?= qty_fmt($pu["qty"]) ?></b><?= $pu['unit'] ? ' ' . h($pu['unit']) : '' ?></td>
    <td class="muted"><?= h($stockCodeMap[$pu['name']] ?? '—') ?></td>
  </tr>
  <?php } ?>
</table>
<?php } ?>

<h2>ประวัติทั้งหมด (<?= count($tl) ?> รายการ) — จัดกลุ่มตามประเภท · เรียงตามวันที่ในแต่ละกลุ่ม</h2>
<p class="muted" style="margin:-6px 0 12px; font-size:13px">เลื่อนแนวนอนเพื่อดูแต่ละประเภทงาน · รายการในแต่ละคอลัมน์เรียงจากใหม่ → เก่า</p>
<?php asset_timeline_board_html($tl, 'asset_tl_actions', $id); ?>
<?php page_footer();
