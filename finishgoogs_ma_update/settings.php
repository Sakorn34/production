<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/product_admin.php';

ensure_product_code_schema();
ensure_field_input_mode_schema();
$productRedirect = product_admin_handle_post();
if ($productRedirect) {
    header('Location: ' . $productRedirect);
    exit;
}

$pid = (int)(isset($_GET['product']) ? $_GET['product'] : (isset($_POST['product_id']) ? $_POST['product_id'] : 0));

// ชนิดฟิลด์: 3 แบบมาตรฐานมีความหมายเชิงระบบ (component=อะไหล่ประจำเครื่อง) ที่เหลือ + ที่พิมพ์เอง = ข้อมูลทั่วไป
$KIND_LABELS = ['component' => 'ชิ้นส่วนฮาร์ดแวร์', 'extra' => 'ข้อมูลเพิ่มเติม', 'text' => 'ข้อความทั่วไป'];
$KIND_SAVE   = array_flip($KIND_LABELS); // ป้ายไทย → ค่าจริง
function kind_label($k) { global $KIND_LABELS; return isset($KIND_LABELS[$k]) ? $KIND_LABELS[$k] : $k; }
/** แปลงค่าที่ผู้ใช้กรอก (ป้ายไทยหรือชนิดกำหนดเอง) → ค่าที่เก็บใน DB */
function kind_save($raw) {
    global $KIND_SAVE;
    $raw = trim((string)$raw);
    if (isset($KIND_SAVE[$raw])) return $KIND_SAVE[$raw];
    return $raw !== '' ? mb_substr($raw, 0, 30) : 'text';
}

$MA_KIND_LABELS = [
    'ma_ok' => 'รายการ ✅ ปกติ',
    'ma_replace' => 'รายการ 🔄 เปลี่ยน',
    'ma_repair' => 'รายการ 🔧 ซ่อม',
    'ma_fw' => 'Firmware หลังตรวจ',
    'ma_status' => 'สถานะเครื่อง',
    'ma_remark' => 'หมายเหตุ',
    'ma_item' => 'รายการตรวจ (รวมทุกช่อง — เก่า)',
    'text' => 'ข้อความทั่วไป',
];
$MA_KIND_SAVE = array_flip($MA_KIND_LABELS);

function ma_kind_label($k) {
    global $MA_KIND_LABELS;
    return isset($MA_KIND_LABELS[$k]) ? $MA_KIND_LABELS[$k] : $k;
}
function ma_kind_save($raw) {
    global $MA_KIND_SAVE;
    $raw = trim((string)$raw);
    if (isset($MA_KIND_SAVE[$raw])) return $MA_KIND_SAVE[$raw];
    if ($raw === 'รายการตรวจ') return 'ma_item';
    return $raw !== '' ? mb_substr($raw, 0, 30) : 'ma_item';
}

/** สร้าง &lt;select&gt; รูปแบบช่องกรอกสำหรับหน้าหลังบ้าน */
function input_mode_select($name, $value, $style = 'width:100%') {
    $value = normalize_input_mode($value);
    $html = '<select name="' . h($name) . '" style="' . h($style) . '">';
    foreach (FIELD_INPUT_MODES as $k => $lbl) {
        $html .= '<option value="' . h($k) . '"' . ($k === $value ? ' selected' : '') . '>' . h($lbl) . '</option>';
    }
    return $html . '</select>';
}

// ---------- บันทึก config ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pid) {
    csrf_check();
    $ctx = isset($_POST['context']) ? $_POST['context'] : '';

    if ($ctx === 'ma') {
        q("DELETE FROM product_field_config WHERE product_id=? AND context='ma'", 'i', [$pid]);
        $names = (array)(isset($_POST['field_name']) ? $_POST['field_name'] : []);
        $kinds = (array)(isset($_POST['field_kind']) ? $_POST['field_kind'] : []);
        $opts  = (array)(isset($_POST['field_options']) ? $_POST['field_options'] : []);
        $modes = (array)(isset($_POST['field_input_mode']) ? $_POST['field_input_mode'] : []);
        $sort = 0; $saved = 0;
        foreach ($names as $i => $nm) {
            $nm = trim($nm);
            if ($nm === '') continue;
            $kind = ma_kind_save(isset($kinds[$i]) ? $kinds[$i] : '');
            $optText = implode("\n", split_lines(isset($opts[$i]) ? $opts[$i] : ''));
            $mode = normalize_input_mode(isset($modes[$i]) ? $modes[$i] : '');
            q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
               VALUES (?,?,?,?,?,?,?)", 'isssssi', [$pid, 'ma', $nm, $kind, $optText, $mode, $sort++]);
            $saved++;
        }
        flash_set('บันทึกฟิลด์ "บันทึก MA" แล้ว (' . $saved . ' ฟิลด์)');
    } elseif (in_array($ctx, ['production', 'update'], true)) {
        q("DELETE FROM product_field_config WHERE product_id=? AND context=? AND field_kind NOT IN ('checklist','watch_alert','watch_alert_cfg')", 'is', [$pid, $ctx]);
        $names = (array)(isset($_POST['field_name']) ? $_POST['field_name'] : []);
        $kinds = (array)(isset($_POST['field_kind']) ? $_POST['field_kind'] : []);
        $opts  = (array)(isset($_POST['field_options']) ? $_POST['field_options'] : []);
        $modes = (array)(isset($_POST['field_input_mode']) ? $_POST['field_input_mode'] : []);
        $sort = 0; $saved = 0;
        foreach ($names as $i => $nm) {
            $nm = trim($nm);
            if ($nm === '') continue;
            $kind = kind_save(isset($kinds[$i]) ? $kinds[$i] : '');
            if ($ctx === 'update') $kind = 'component';
            // ไม่รับชนิด fw/lot จากตารางฟิลด์ปกติ — จัดการผ่านสวิตช์ด้านล่าง
            if ($kind === 'fw' || $kind === 'lot') continue;
            $optText = implode("\n", split_lines(isset($opts[$i]) ? $opts[$i] : ''));
            $mode = normalize_input_mode(isset($modes[$i]) ? $modes[$i] : '');
            q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
               VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE field_kind=VALUES(field_kind), options_text=VALUES(options_text), input_mode=VALUES(input_mode), sort_order=VALUES(sort_order), is_active=1",
              'isssssi', [$pid, $ctx, $nm, $kind, $optText, $mode, $sort++]);
            $saved++;
        }
        // สวิตช์มาตรฐาน FW / Lot / ผู้ผลิต ของรุ่นนี้
        if ($ctx === 'production') {
            $madeByMode = normalize_input_mode(isset($_POST['made_by_input_mode']) ? $_POST['made_by_input_mode'] : 'chip_single_free');
            if (!empty($_POST['show_made_by'])) {
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','ผู้ผลิต/ประกอบ','made_by','',?,?)", 'isi', [$pid, $madeByMode, $sort++]);
                $saved++;
            } else {
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','ผู้ผลิต/ประกอบ','made_by_off','','chip_single_free',?)", 'ii', [$pid, $sort++]);
            }
            if (!empty($_POST['show_fw'])) {
                $fwOpts = implode("\n", split_lines(isset($_POST['fw_options']) ? $_POST['fw_options'] : ''));
                $fwMode = normalize_input_mode(isset($_POST['fw_input_mode']) ? $_POST['fw_input_mode'] : 'chip_single_free');
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','Firmware','fw',?,?,?)", 'isss', [$pid, $fwOpts, $fwMode, $sort++]);
                $saved++;
            } else {
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','Firmware','fw_off','','chip_single_free',?)", 'ii', [$pid, $sort++]);
            }
            if (!empty($_POST['show_lot'])) {
                $lotOpts = implode("\n", split_lines(isset($_POST['lot_options']) ? $_POST['lot_options'] : ''));
                $lotMode = normalize_input_mode(isset($_POST['lot_input_mode']) ? $_POST['lot_input_mode'] : 'chip_single_free');
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','Lot','lot',?,?,?)", 'isss', [$pid, $lotOpts, $lotMode, $sort++]);
                $saved++;
            } else {
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','Lot','lot_off','','chip_single_free',?)", 'ii', [$pid, $sort++]);
            }
            // Checklist ตรวจก่อนส่งมอบ (หน้าบันทึกผลิต)
            q("DELETE FROM product_field_config WHERE product_id=? AND context='production' AND field_kind='checklist'", 'i', [$pid]);
            $chkLines = split_lines(isset($_POST['production_checklist']) ? $_POST['production_checklist'] : '');
            if ($chkLines) {
                $chkText = implode("\n", $chkLines);
                q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                   VALUES (?,'production','Checklist ตรวจก่อนส่งมอบ','checklist',?,'',?)", 'isi', [$pid, $chkText, $sort++]);
                $saved++;
            }
            // แจ้งเตือน SD Card / Battery Backup RTC ต่อรุ่น
            q("DELETE FROM product_field_config WHERE product_id=? AND context='production' AND field_kind IN ('watch_alert','watch_alert_cfg')", 'i', [$pid]);
            q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
               VALUES (?,'production','แจ้งเตือนอะไหล่','watch_alert_cfg','','',?)", 'ii', [$pid, $sort++]);
            $watchCatalog = part_watch_catalog();
            $watchPosted = (array)(isset($_POST['watch_alert']) ? $_POST['watch_alert'] : []);
            foreach (array_keys($watchCatalog) as $alertName) {
                if (in_array($alertName, $watchPosted, true)) {
                    q("INSERT INTO product_field_config (product_id,context,field_name,field_kind,options_text,input_mode,sort_order)
                       VALUES (?,'production',?,'watch_alert','','',?)", 'isi', [$pid, $alertName, $sort++]);
                    $saved++;
                }
            }
        }
        flash_set('บันทึกฟิลด์ "' . ($ctx === 'production' ? 'บันทึกผลิต' : 'อัปเดต FW/HW') . '" แล้ว (' . $saved . ' ฟิลด์)');
    } elseif ($ctx === 'reset') {
        $rc = isset($_POST['reset_ctx']) ? $_POST['reset_ctx'] : '';
        if (in_array($rc, ['production', 'ma', 'update'], true)) {
            q("DELETE FROM product_field_config WHERE product_id=? AND context=?", 'is', [$pid, $rc]);
            flash_set('ล้าง config แล้ว — กลับไปใช้ฟิลด์อัตโนมัติจากประวัติ');
        }
    }
    header('Location: ' . BASE_URL . '/settings.php?product=' . $pid); exit;
}

$products = [];
$res = qr("SELECT id, name, icon_path, code_mode, product_code FROM products WHERE is_active=1 ORDER BY name");
while ($r = $res->fetch_assoc()) $products[] = $r;

$product = null;
if ($pid) {
    $product = qr("SELECT * FROM products WHERE id=? AND is_active=1", 'i', [$pid])->fetch_assoc();
    if (!$product) $pid = 0;
}
page_header('ระบบหลังบ้าน — ตั้งค่ารุ่นและฟิลด์');
?>
<div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px">
  <a class="card clickable" href="<?= BASE_URL ?>/line_notify_settings.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('bell', 28, 'h-svg') ?>
    <div><b>แจ้งเตือน LINE</b><div class="muted">Token · Group ID · เปิด/ปิด event · ทดสอบส่ง · Outbox</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/server_config.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('settings', 28, 'h-svg') ?>
    <div><b>ตั้งค่า Server / Deploy</b><div class="muted">Path secrets · DB ทั้ง 3 ตัว · SSO · ทดสอบการเชื่อมต่อ</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/appearance.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('palette', 28, 'h-svg') ?>
    <div><b>ปรับแต่งหน้าตาระบบ</b><div class="muted">ข้อความ · โลโก้ · สีธีม · เมนู (ไอคอน/ลำดับ/ตำแหน่ง)</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/share_admin.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('switch', 28, 'h-svg') ?>
    <div><b>เปรียบเทียบ assets ↔ stock</b><div class="muted">รายการไม่ตรงกัน · ค้นหา · แก้ไข · ลบ · Sync · Import</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/share.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('clipboard', 28, 'h-svg') ?>
    <div><b>ทะเบียนสินค้า (stock)</b><div class="muted">ดู · ค้นหา · แก้ไข · ลบรายการประจำวัน</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/system_doc.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('book', 28, 'h-svg') ?>
    <div><b>หลักการทำงานของระบบ</b><div class="muted">DB · ตาราง · Data flow · ฟังก์ชัน · สิทธิ์ผู้ใช้</div></div>
  </a>
  <a class="card clickable" href="<?= BASE_URL ?>/activity_logs.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit; min-width:auto">
    <?= ui_icon_html('history', 28, 'h-svg') ?>
    <div><b>Activity Log</b><div class="muted">ความเคลื่อนไหวผู้ใช้ · Production + Parts · Export CSV</div></div>
  </a>
</div>
<p class="muted" style="margin-bottom:14px">
  จัดการรุ่นสินค้า · ตั้งรูปแบบออกรหัสเครื่อง · กำหนดฟิลด์ในหน้า "บันทึกผลิตใหม่", "บันทึก MA" และ "อัปเดต FW/HW"
  · ถ้าไม่ตั้งค่าฟิลด์ ระบบจะใช้ฟิลด์อัตโนมัติจากประวัติการใช้งานจริง
</p>

<?= ui_heading('box', 'รุ่นสินค้า', 'h2') ?>
<form method="get" class="filter" style="margin-bottom:10px">
  <label style="align-self:center">เลือกรุ่นเพื่อตั้งค่า:</label>
  <select name="product" onchange="this.form.submit()" style="min-width:280px">
    <option value="0">— ดูรายการทั้งหมด —</option>
    <?php foreach ($products as $p) { ?>
      <option value="<?= $p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h($p['product_code']) ?>)</option>
    <?php } ?>
  </select>
</form>

<?php if (!$product) {
    $rows = product_admin_list_query();
?>
<div style="margin-bottom:12px">
  <input type="text" id="prod-list-search" placeholder="ค้นหาชื่อรุ่น / รหัสสินค้า / prefix" style="width:min(360px,100%)">
</div>
<div class="grid-products" id="prod-list-grid" style="margin-bottom:24px">
<?php while ($r = $rows->fetch_assoc()) { ?>
  <a class="pcard" href="<?= BASE_URL ?>/settings.php?product=<?= (int)$r['id'] ?>" style="text-decoration:none; color:inherit"
     data-search="<?= h(mb_strtolower($r['name'] . ' ' . $r['product_code'] . ' ' . ($r['code_prefix'] ?: '') . ' ' . ($r['category'] ?: ''))) ?>">
    <?= img_tag($r['icon_path'], $r['name'], 'thumb-lg') ?>
    <div class="pname"><?= h($r['name']) ?></div>
    <div class="pmeta">
      <?= h($r['product_code']) ?> · <?= number_format($r['n']) ?> เครื่อง<br>
      <?= h(product_code_format_label($r)) ?>
      <?= ($r['code_mode'] === 'generated' && $r['code_prefix']) ? '<br><span class="muted">รันนิ่งล่าสุด ' . (int)$r['max_run'] . '</span>' : '' ?><br>
      <?php foreach (['production' => 'ผลิต', 'ma' => 'MA', 'update' => 'อัปเดต'] as $cx => $lbl) {
          echo has_product_config($r['id'], $cx) ? '<span class="badge st-new" style="font-size:10px">' . $lbl . ' ✓</span> ' : '';
      } ?>
    </div>
  </a>
<?php } ?>
</div>

<?= ui_heading('box', 'เพิ่มรุ่นสินค้าใหม่') ?>
<form method="post" class="formgrid" enctype="multipart/form-data" id="form-new-product">
  <?= csrf_field() ?><input type="hidden" name="new_product" value="1">
  <label>รหัสสินค้า (PRD)</label><input type="text" name="product_code" required placeholder="เช่น PRD004" maxlength="20">
  <label>ชื่อรุ่น</label><input type="text" name="name" required maxlength="150">
  <label>หมวด</label><input type="text" name="category" placeholder="PRD / ACC / STK" maxlength="50">
  <?php product_admin_code_fields(null, 'new'); ?>
  <label>รูปสินค้า</label><input type="file" name="icon" accept="image/*">
  <div class="full"><button type="submit">➕ เพิ่มรุ่นสินค้า</button></div>
</form>
<?php product_admin_code_script(); ?>
<script>
document.getElementById('prod-list-search').addEventListener('input', function(){
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#prod-list-grid .pcard').forEach(function(el){
    el.style.display = (!q || (el.dataset.search || '').indexOf(q) !== -1) ? '' : 'none';
  });
});
productCodeToggle('new');
</script>
<?php page_footer(); exit; } ?>

<div class="asset-head" style="align-items:center; margin-bottom:16px">
  <?= img_tag($product['icon_path'], $product['name'], 'thumb-lg') ?>
  <div class="info">
    <h2 style="margin:0"><?= h($product['name']) ?></h2>
    <div class="muted"><?= h($product['product_code']) ?> · <?= h(product_code_format_label($product)) ?></div>
    <div style="margin-top:8px">
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/assets.php?product=<?= urlencode($product['name']) ?>">ดูเครื่องในรุ่นนี้</a>
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/settings.php">← รายการรุ่นทั้งหมด</a>
    </div>
  </div>
</div>

<?= ui_heading('tag', 'ข้อมูลรุ่นและการออกรหัส') ?>
<form method="post" class="formgrid" enctype="multipart/form-data" id="form-edit-product">
  <?= csrf_field() ?>
  <input type="hidden" name="edit_product" value="1">
  <input type="hidden" name="product_id" value="<?= $pid ?>">
  <label>รหัสสินค้า (PRD)</label><input type="text" name="product_code" value="<?= h($product['product_code']) ?>" required maxlength="20">
  <label>ชื่อรุ่น</label><input type="text" name="name" value="<?= h($product['name']) ?>" required maxlength="150">
  <label>หมวด</label><input type="text" name="category" value="<?= h($product['category']) ?>" maxlength="50">
  <?php product_admin_code_fields($product, 'edit'); ?>
  <label>รูปสินค้า</label>
  <div>
    <?php if ($product['icon_path']) echo img_tag($product['icon_path'], '', 'thumb') . ' ';
    ?><input type="file" name="icon" accept="image/*">
  </div>
  <div class="full"><button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกข้อมูลรุ่น') ?></button></div>
</form>

<?php
// ---------- ส่วนบันทึกผลิต ----------
$prodEffAll = product_config_fields($pid, 'production');
$prodIsConfigured = has_product_config($pid, 'production');
$stdFields = product_std_fields($pid);
$showFwCfg = $stdFields['fw'];
$showLotCfg = $stdFields['lot'];
// ยังไม่เคยบันทึกสวิตช์ → ติ๊กเปิดไว้เป็นค่าเริ่ม; บันทึกแล้ว → ตามค่าจริง
$fwChecked = $stdFields['decided'] ? ($showFwCfg !== null) : true;
$lotChecked = $stdFields['decided'] ? ($showLotCfg !== null) : true;
$madeByMode = $stdFields['made_by'] ? $stdFields['made_by']['input_mode'] : 'chip_single_free';
$fwInputMode = $showFwCfg ? $showFwCfg['input_mode'] : 'chip_single_free';
$lotInputMode = $showLotCfg ? $showLotCfg['input_mode'] : 'chip_single_free';
$madeByChecked = $stdFields['decided'] ? ($stdFields['made_by'] !== null) : true;
// แสดงในตารางเฉพาะฟิลด์กำหนดเอง (ไม่รวมช่องมาตรฐาน made_by / fw / lot / checklist / watch)
$prodEff = [];
$checklistText = '';
foreach ($prodEffAll as $f) {
    if ($f['kind'] === 'checklist') {
        $checklistText = implode("\n", $f['options']);
        continue;
    }
    if (in_array($f['kind'], ['fw', 'lot', 'fw_off', 'lot_off', 'made_by', 'made_by_off', 'watch_alert', 'watch_alert_cfg'], true)) continue;
    $prodEff[] = $f;
}
if (!$prodEff && !$prodIsConfigured) $prodEff = derive_production_fields($pid); // แสดง preview จากประวัติถ้ายังไม่ตั้ง
$watchCatalog = part_watch_catalog();
$watchEnabled = product_watch_alerts_enabled($pid);
?>
<h2>① ฟิลด์หน้า "บันทึกผลิตใหม่"
  <?= $prodIsConfigured ? '<span class="badge st-new">ตั้งค่าเองแล้ว</span>' : '<span class="badge st-spare">อัตโนมัติจากประวัติ</span>' ?>
</h2>
<p class="muted" style="margin-bottom:6px">ชนิด: <b>ชิ้นส่วนฮาร์ดแวร์</b> = เก็บเป็นอะไหล่ประจำเครื่อง · <b>ข้อมูลเพิ่มเติม</b> = ข้อมูลเฉพาะรุ่น (เช่น Type) · <b>ผู้ผลิต</b> = เลือก/พิมพ์ชื่อ · <b>หรือพิมพ์ชื่อชนิดเองได้อิสระ</b> · ตัวเลือก: ใส่บรรทัดละ 1 ค่า · <b>ลากไอคอน ≡ เพื่อจัดลำดับฟิลด์</b></p>
<?php
// ชนิดกำหนดเองที่เคยใช้ในรุ่นนี้ (เอาไปโชว์ใน dropdown แนะนำ)
$customKinds = [];
foreach ($prodEff as $f) if (!isset($KIND_LABELS[$f['kind']]) && $f['kind'] !== 'ผู้ผลิต' && !in_array($f['kind'], $customKinds, true)) $customKinds[] = $f['kind'];
?>
<datalist id="kind-options">
  <?php foreach ($KIND_LABELS as $lbl) { ?><option value="<?= h($lbl) ?>"><?php } ?>
  <option value="ผู้ผลิต">
  <?php foreach ($customKinds as $ck) { ?><option value="<?= h($ck) ?>"><?php } ?>
</datalist>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" value="<?= $pid ?>">
  <input type="hidden" name="context" value="production">

  <div class="panel" style="margin-bottom:14px; padding:12px 14px">
    <b>ช่องมาตรฐานของรุ่นนี้</b>
    <p class="muted" style="margin:4px 0 10px; font-size:13px">ติ๊กเฉพาะรุ่นที่ต้องการให้มีช่องนี้ในหน้า "บันทึกผลิตใหม่" · ถ้าไม่ติ๊กทั้ง 3 ช่องและไม่มีฟิลด์ในตารางด้านล่าง หน้าบันทึกผลิตจะว่าง · กด "ใช้ค่าอัตโนมัติ" เมื่อต้องการกลับไปดึงฟิลด์จากประวัติ</p>
    <div style="display:grid; gap:12px">
      <div>
        <label style="display:flex; align-items:center; gap:8px; font-weight:600">
          <input type="checkbox" name="show_made_by" value="1" <?= $madeByChecked ? 'checked' : '' ?> onchange="document.getElementById('madeby-opts-wrap').hidden=!this.checked">
          แสดงฟิลด์ผู้ผลิต/ประกอบ
        </label>
        <div id="madeby-opts-wrap" style="margin-top:6px" <?= $madeByChecked ? '' : 'hidden' ?>>
          <div class="muted" style="font-size:12px; margin-bottom:6px">รูปแบบช่องกรอกในหน้า "บันทึกผลิตใหม่"</div>
          <?= input_mode_select('made_by_input_mode', $madeByMode, 'max-width:360px') ?>
        </div>
      </div>
      <div>
        <label style="display:flex; align-items:center; gap:8px; font-weight:600">
          <input type="checkbox" name="show_fw" value="1" <?= $fwChecked ? 'checked' : '' ?> onchange="document.getElementById('fw-opts-wrap').hidden=!this.checked">
          แสดงฟิลด์เวอร์ชัน Firmware
        </label>
        <div id="fw-opts-wrap" style="margin-top:6px" <?= $fwChecked ? '' : 'hidden' ?>>
          <div style="margin-bottom:6px"><?= input_mode_select('fw_input_mode', $fwInputMode, 'max-width:360px') ?></div>
          <textarea name="fw_options" rows="3" style="width:100%; max-width:480px" placeholder="ตัวเลือก FW เช่น&#10;2.6.6c&#10;2.6.5"><?= h($showFwCfg ? implode("\n", $showFwCfg['options']) : '') ?></textarea>
        </div>
      </div>
      <div>
        <label style="display:flex; align-items:center; gap:8px; font-weight:600">
          <input type="checkbox" name="show_lot" value="1" <?= $lotChecked ? 'checked' : '' ?> onchange="document.getElementById('lot-opts-wrap').hidden=!this.checked">
          แสดงฟิลด์ Lot
        </label>
        <div id="lot-opts-wrap" style="margin-top:6px" <?= $lotChecked ? '' : 'hidden' ?>>
          <div style="margin-bottom:6px"><?= input_mode_select('lot_input_mode', $lotInputMode, 'max-width:360px') ?></div>
          <textarea name="lot_options" rows="3" style="width:100%; max-width:480px" placeholder="ตัวเลือก Lot เช่น&#10;LOT-A&#10;LOT-B"><?= h($showLotCfg ? implode("\n", $showLotCfg['options']) : '') ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px; padding:12px 14px">
    <b>Checklist ตรวจก่อนส่งมอบ</b>
    <p class="muted" style="margin:4px 0 10px; font-size:13px">รายการที่แสดงในหน้า "บันทึกผลิตใหม่" — บรรทัดละ 1 ข้อ · ผู้บันทึกติ๊กเฉพาะข้อที่ตรวจแล้ว · ถ้าว่าง ระบบจะดึงจาก checklist ของเครื่องล่าสุดในรุ่นนี้</p>
    <textarea name="production_checklist" rows="6" style="width:100%; max-width:560px" placeholder="เช่น&#10;ทดสอบ Boot ผ่าน&#10;ทดสอบ Network&#10;ติดสตicker S/N"><?= h($checklistText) ?></textarea>
  </div>

  <div class="panel" style="margin-bottom:14px; padding:12px 14px">
    <b>แจ้งเตือนอะไหล่ที่ควรเปลี่ยน</b>
    <p class="muted" style="margin:4px 0 10px; font-size:13px">เลือกว่ารุ่นนี้จะแสดงข้อความ "ถึงกำหนดเปลี่ยน …" อะไรบ้าง (นับจากวันผลิตหรือวันเปลี่ยนล่าสุดใน MA) · รุ่นที่ยังไม่เคยบันทึกจะเปิดทุกรายการไว้เป็นค่าเริ่ม</p>
    <div style="display:grid; gap:8px">
      <?php foreach ($watchCatalog as $alertName => $_aliases) { ?>
      <label style="display:flex; align-items:center; gap:8px; font-weight:500">
        <input type="checkbox" name="watch_alert[]" value="<?= h($alertName) ?>" <?= !empty($watchEnabled[$alertName]) ? 'checked' : '' ?>>
        <?= h($alertName) ?>
      </label>
      <?php } ?>
    </div>
  </div>

  <div class="table-wrap">
  <table class="list" id="tbl-production">
    <tr><th style="width:34px"></th><th>ชื่อฟิลด์</th><th style="width:170px">ชนิด</th><th style="width:200px">รูปแบบช่องกรอก</th><th>ตัวเลือก (บรรทัดละ 1 ค่า)</th><th style="width:50px"></th></tr>
    <?php foreach ($prodEff as $i => $f) { ?>
    <tr>
      <td class="drag muted" style="cursor:grab; text-align:center">≡</td>
      <td><input type="text" name="field_name[]" value="<?= h($f['name']) ?>" style="width:100%"></td>
      <td><input type="text" name="field_kind[]" list="kind-options" value="<?= h(kind_label($f['kind'])) ?>" style="width:100%" placeholder="พิมพ์/เลือกชนิด"></td>
      <td><?= input_mode_select('field_input_mode[]', $f['input_mode'] ?? 'chip_multi_free') ?></td>
      <td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"><?= h(implode("\n", $f['options'])) ?></textarea></td>
      <td><button type="button" class="btn-sm btn-line" onclick="this.closest('tr').remove()">ลบ</button></td>
    </tr>
    <?php } ?>
  </table>
  </div>
  <div style="margin:8px 0; display:flex; gap:8px">
    <button type="button" class="btn btn-line" onclick="addRow('tbl-production','production')">➕ เพิ่มฟิลด์</button>
    <button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกฟิลด์ผลิต') ?></button>
    <?php if ($prodIsConfigured) { ?>
    <button type="submit" form="reset-production" class="btn-line" onclick="return confirm('ล้าง config แล้วกลับไปใช้ฟิลด์อัตโนมัติ?')">↺ ใช้ค่าอัตโนมัติ</button>
    <?php } ?>
  </div>
</form>
<form method="post" id="reset-production"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="context" value="reset"><input type="hidden" name="reset_ctx" value="production"></form>

<?php
// ---------- ส่วน MA ----------
$maConfigured = has_product_config($pid, 'ma');
$maEff = product_config_fields($pid, 'ma');
if (!$maEff) $maEff = derive_ma_form_fields($pid);
?>
<h2 style="margin-top:26px">② ฟิลด์หน้า "บันทึก MA"
  <?= $maConfigured ? '<span class="badge st-new">ตั้งค่าเองแล้ว</span>' : '<span class="badge st-spare">อัตโนมัติจากประวัติ</span>' ?>
</h2>
<p class="muted" style="margin-bottom:6px">
  กำหนดฟิลด์และตัวเลือกในฟอร์มบันทึก MA · ชนิด <b>รายการ ✅/🔄/🔧</b> = รายการให้เลือกในแต่ละช่อง (บรรทัดละ 1 ค่า) ·
  <b>Firmware</b> = ตัวเลือกเวอร์ชัน · <b>ลาก ≡ เพื่อจัดลำดับ</b>
</p>
<datalist id="ma-kind-options">
  <?php foreach ($MA_KIND_LABELS as $lbl) { ?><option value="<?= h($lbl) ?>"><?php } ?>
</datalist>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" value="<?= $pid ?>">
  <input type="hidden" name="context" value="ma">
  <div class="table-wrap">
  <table class="list" id="tbl-ma">
    <tr><th style="width:34px"></th><th>ชื่อฟิลด์</th><th style="width:200px">ชนิด</th><th style="width:200px">รูปแบบช่องกรอก</th><th>ตัวเลือก (บรรทัดละ 1 ค่า)</th><th style="width:50px"></th></tr>
    <?php foreach ($maEff as $f) { ?>
    <tr>
      <td class="drag muted" style="cursor:grab; text-align:center">≡</td>
      <td><input type="text" name="field_name[]" value="<?= h($f['name']) ?>" style="width:100%"></td>
      <td><input type="text" name="field_kind[]" list="ma-kind-options" value="<?= h(ma_kind_label($f['kind'])) ?>" style="width:100%" placeholder="พิมพ์/เลือกชนิด"></td>
      <td><?= input_mode_select('field_input_mode[]', $f['input_mode'] ?? 'chip_multi_free') ?></td>
      <td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"><?= h(implode("\n", $f['options'])) ?></textarea></td>
      <td><button type="button" class="btn-sm btn-line" onclick="this.closest('tr').remove()">ลบ</button></td>
    </tr>
    <?php } ?>
  </table>
  </div>
  <div style="margin:8px 0; display:flex; gap:8px; flex-wrap:wrap">
    <button type="button" class="btn btn-line" onclick="addRow('tbl-ma','ma')">➕ เพิ่มฟิลด์</button>
    <button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกฟิลด์ MA') ?></button>
    <?php if ($maConfigured) { ?>
    <button type="submit" form="reset-ma" class="btn-line" onclick="return confirm('ล้าง config แล้วกลับไปใช้ฟิลด์อัตโนมัติ?')">↺ ใช้ค่าอัตโนมัติ</button>
    <?php } ?>
  </div>
</form>
<form method="post" id="reset-ma"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="context" value="reset"><input type="hidden" name="reset_ctx" value="ma"></form>

<?php
// ---------- ส่วนอัปเดต FW/HW ----------
$updConfigured = has_product_config($pid, 'update');
$updEff = product_config_fields($pid, 'update');
if (!$updEff) $updEff = derive_update_fields($pid);
?>
<h2 style="margin-top:26px">③ ชิ้นส่วนหน้า "อัปเดต FW/HW"
  <?= $updConfigured ? '<span class="badge st-new">ตั้งค่าเองแล้ว</span>' : '<span class="badge st-spare">อัตโนมัติจากประวัติ</span>' ?>
</h2>
<p class="muted" style="margin-bottom:6px">รายชื่อชิ้นส่วนที่เลือกได้ตอนบันทึกการเปลี่ยน/อัปเดต พร้อมตัวเลือกค่า (เช่นเวอร์ชัน)</p>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" value="<?= $pid ?>">
  <input type="hidden" name="context" value="update">
  <div class="table-wrap">
  <table class="list" id="tbl-update">
    <tr><th style="width:34px"></th><th>ชื่อชิ้นส่วน</th><th style="width:200px">รูปแบบช่องกรอก</th><th>ตัวเลือกค่า (บรรทัดละ 1 ค่า)</th><th style="width:50px"></th></tr>
    <?php foreach ($updEff as $f) { ?>
    <tr>
      <td class="drag muted" style="cursor:grab; text-align:center">≡</td>
      <td><input type="text" name="field_name[]" value="<?= h($f['name']) ?>" style="width:100%">
          <input type="hidden" name="field_kind[]" value="component"></td>
      <td><?= input_mode_select('field_input_mode[]', $f['input_mode'] ?? 'chip_single_free') ?></td>
      <td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"><?= h(implode("\n", $f['options'])) ?></textarea></td>
      <td><button type="button" class="btn-sm btn-line" onclick="this.closest('tr').remove()">ลบ</button></td>
    </tr>
    <?php } ?>
  </table>
  </div>
  <div style="margin:8px 0; display:flex; gap:8px">
    <button type="button" class="btn btn-line" onclick="addRow('tbl-update','update')">➕ เพิ่มชิ้นส่วน</button>
    <button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกฟิลด์อัปเดต') ?></button>
    <?php if ($updConfigured) { ?>
    <button type="submit" form="reset-update" class="btn-line" onclick="return confirm('ล้าง config แล้วกลับไปใช้อัตโนมัติ?')">↺ ใช้ค่าอัตโนมัติ</button>
    <?php } ?>
  </div>
</form>
<form method="post" id="reset-update"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $pid ?>"><input type="hidden" name="context" value="reset"><input type="hidden" name="reset_ctx" value="update"></form>

<p class="muted h-with-icon" style="margin-top:22px"><?= ui_icon_html('parts', 16, 'h-svg') ?><span><b>ชุดอะไหล่ประจำรุ่น</b> ย้ายไปจัดที่หน้า "บันทึกผลิตใหม่" แล้ว (เลือกรุ่น → ช่อง "ชุดอะไหล่ที่จะเบิก")</span></p>

<script>
var INPUT_MODE_OPTS = <?= json_encode(FIELD_INPUT_MODES, JSON_UNESCAPED_UNICODE) ?>;
function inputModeSelectHtml(name, value){
  value = value || 'chip_multi_free';
  var html = '<select name="' + name + '" style="width:100%">';
  Object.keys(INPUT_MODE_OPTS).forEach(function(k){
    html += '<option value="' + k + '"' + (k === value ? ' selected' : '') + '>' + INPUT_MODE_OPTS[k] + '</option>';
  });
  return html + '</select>';
}
function addRow(tblId, ctx){
  var tbl = document.getElementById(tblId);
  var tr = document.createElement('tr');
  if (ctx === 'production') {
    tr.innerHTML = '<td class="drag muted" style="cursor:grab; text-align:center">≡</td>'
      + '<td><input type="text" name="field_name[]" style="width:100%"></td>'
      + '<td><input type="text" name="field_kind[]" list="kind-options" value="ชิ้นส่วนฮาร์ดแวร์" style="width:100%" placeholder="พิมพ์/เลือกชนิด"></td>'
      + '<td>' + inputModeSelectHtml('field_input_mode[]', 'chip_multi_free') + '</td>'
      + '<td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"></textarea></td>'
      + '<td><button type="button" class="btn-sm btn-line" onclick="this.closest(\'tr\').remove()">ลบ</button></td>';
  } else if (ctx === 'ma') {
    tr.innerHTML = '<td class="drag muted" style="cursor:grab; text-align:center">≡</td>'
      + '<td><input type="text" name="field_name[]" style="width:100%"></td>'
      + '<td><input type="text" name="field_kind[]" list="ma-kind-options" value="รายการ ✅ ปกติ" style="width:100%" placeholder="พิมพ์/เลือกชนิด"></td>'
      + '<td>' + inputModeSelectHtml('field_input_mode[]', 'chip_multi_free') + '</td>'
      + '<td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"></textarea></td>'
      + '<td><button type="button" class="btn-sm btn-line" onclick="this.closest(\'tr\').remove()">ลบ</button></td>';
  } else {
    tr.innerHTML = '<td class="drag muted" style="cursor:grab; text-align:center">≡</td>'
      + '<td><input type="text" name="field_name[]" style="width:100%"><input type="hidden" name="field_kind[]" value="component"></td>'
      + '<td>' + inputModeSelectHtml('field_input_mode[]', 'chip_single_free') + '</td>'
      + '<td><textarea name="field_options[]" rows="2" style="width:100%; min-height:0"></textarea></td>'
      + '<td><button type="button" class="btn-sm btn-line" onclick="this.closest(\'tr\').remove()">ลบ</button></td>';
  }
  tbl.appendChild(tr);
}

// ---------- ลากจัดลำดับฟิลด์ (ใช้ไอคอน ≡) ----------
function enableDrag(tblId){
  var tbl = document.getElementById(tblId);
  if (!tbl) return;
  var dragged = null;
  tbl.addEventListener('mousedown', function(e){
    var h = e.target.closest('.drag');
    if (h) { var tr = h.closest('tr'); if (tr) tr.setAttribute('draggable', 'true'); }
  });
  tbl.addEventListener('dragstart', function(e){
    dragged = e.target.closest('tr');
    if (!dragged) return;
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', ''); } catch(_){}
    dragged.style.opacity = '0.4';
  });
  tbl.addEventListener('dragover', function(e){
    if (!dragged) return;
    e.preventDefault();
    var over = e.target.closest('tr');
    if (!over || over === dragged || over.parentNode !== dragged.parentNode || over.querySelector('th')) return;
    var rect = over.getBoundingClientRect();
    var after = (e.clientY - rect.top) > rect.height / 2;
    over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
  });
  tbl.addEventListener('drop', function(e){ e.preventDefault(); });
  tbl.addEventListener('dragend', function(){
    if (dragged) { dragged.style.opacity = ''; dragged.removeAttribute('draggable'); dragged = null; }
  });
}
enableDrag('tbl-production');
enableDrag('tbl-update');
enableDrag('tbl-ma');
</script>
<?php product_admin_code_script(); ?>
<script>productCodeToggle('edit');</script>
<?php page_footer();
