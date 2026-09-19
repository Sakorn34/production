<?php
/**
 * includes/product_admin.php — จัดการรุ่นสินค้า + ตั้งค่าการออกรหัส (ใช้ใน settings.php)
 *
 * flow: POST new_product|edit_product → validate → INSERT/UPDATE products → redirect settings.php
 */

/**
 * อ่านค่าตั้งรหัสจากฟอร์ม POST
 *
 * @param array<string, mixed> $post
 * @return array{code_mode:string,code_prefix:?string,code_year_era:string,running_digits:int,code_use_prefix:int,code_use_year:int,code_use_month:int}
 */
function product_admin_parse_code_post(array $post) {
    $mode = (isset($post['code_mode']) && $post['code_mode'] === 'factory_serial') ? 'factory_serial' : 'generated';
    $prefix = trim((string)(isset($post['code_prefix']) ? $post['code_prefix'] : ''));
    if (strlen($prefix) > 10) $prefix = mb_substr($prefix, 0, 10);
    $digits = (int)(isset($post['running_digits']) ? $post['running_digits'] : 4);
    if ($digits < 1) $digits = 1;
    if ($digits > 8) $digits = 8;
    return [
        'code_mode'       => $mode,
        'code_prefix'     => $prefix !== '' ? $prefix : null,
        'code_year_era'   => (isset($post['code_year_era']) && $post['code_year_era'] === 'be') ? 'be' : 'ce',
        'running_digits'  => $digits,
        'code_use_prefix' => !empty($post['code_use_prefix']) ? 1 : 0,
        'code_use_year'   => !empty($post['code_use_year']) ? 1 : 0,
        'code_use_month'  => !empty($post['code_use_month']) ? 1 : 0,
    ];
}

/**
 * บันทึก/เพิ่มรุ่นจาก POST — คืน URL redirect หรือ null ถ้าไม่ใช่ action นี้
 *
 * @return string|null
 */
function product_admin_handle_post() {
    ensure_product_code_schema();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return null;

    if (isset($_POST['edit_product'])) {
        csrf_check();
        $pid = (int)$_POST['product_id'];
        if ($pid <= 0) {
            flash_set('ไม่พบรุ่นที่จะแก้ไข', 'err');
            return BASE_URL . '/settings.php';
        }
        $code = product_admin_parse_code_post($_POST);
        if ($code['code_mode'] === 'generated' && $code['code_use_prefix'] && $code['code_prefix'] === null) {
            flash_set('ติ๊กใช้ชื่อย่อแล้ว กรุณากรอกค่าชื่อย่อ', 'err');
            return BASE_URL . '/settings.php?product=' . $pid;
        }
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        $pcode = trim((string)(isset($_POST['product_code']) ? $_POST['product_code'] : ''));
        $cat = trim((string)(isset($_POST['category']) ? $_POST['category'] : ''));
        if ($name === '' || $pcode === '') {
            flash_set('กรุณากรอกรหัสสินค้าและชื่อรุ่น', 'err');
            return BASE_URL . '/settings.php?product=' . $pid;
        }
        $dup = qr("SELECT id FROM products WHERE product_code=? AND id<>?", 'si', [$pcode, $pid])->fetch_assoc();
        if ($dup) {
            flash_set('รหัสสินค้า "' . $pcode . '" ถูกใช้แล้ว', 'err');
            return BASE_URL . '/settings.php?product=' . $pid;
        }
        $icon = save_upload('icon', 'products');
        q("UPDATE products SET product_code=?, name=?, category=NULLIF(?,''), code_mode=?, code_prefix=?, code_year_era=?,
           running_digits=?, code_use_prefix=?, code_use_year=?, code_use_month=?, icon_path=COALESCE(?, icon_path) WHERE id=?",
          'ssssssiiiisi',
          [$pcode, $name, $cat, $code['code_mode'], $code['code_prefix'], $code['code_year_era'],
           $code['running_digits'], $code['code_use_prefix'], $code['code_use_year'], $code['code_use_month'],
           $icon, $pid]);
        if (array_key_exists('min_stock', $_POST) && function_exists('fg_shortage_save_min_stock')) {
            fg_shortage_save_min_stock([$pcode => (string) $_POST['min_stock']]);
        }
        if (array_key_exists('leasing_name', $_POST) && function_exists('ensure_leasing_move_schema')) {
            ensure_leasing_move_schema();
            $ln = trim((string) $_POST['leasing_name']);
            q('UPDATE products SET leasing_name = ?, leasing_auto = ? WHERE id = ?', 'sii',
              [$ln !== '' ? mb_substr($ln, 0, 50) : null, ($ln !== '' && !empty($_POST['leasing_auto'])) ? 1 : 0, $pid]);
        }
        flash_set('บันทึกข้อมูลรุ่นและรูปแบบรหัสแล้ว');
        return BASE_URL . '/settings.php?product=' . $pid;
    }

    if (isset($_POST['new_product'])) {
        csrf_check();
        $code = product_admin_parse_code_post($_POST);
        $pcode = trim((string)(isset($_POST['product_code']) ? $_POST['product_code'] : ''));
        $name = trim((string)(isset($_POST['name']) ? $_POST['name'] : ''));
        if ($pcode === '' || $name === '') {
            flash_set('กรุณากรอกรหัสสินค้าและชื่อรุ่น', 'err');
            // ?new=1 ให้หน้าเปิด popup เพิ่มรุ่นค้างไว้ ผู้ใช้จะได้แก้ต่อทันทีไม่ต้องกดเปิดใหม่
            return BASE_URL . '/settings.php?new=1';
        }
        if ($code['code_mode'] === 'generated' && $code['code_use_prefix'] && $code['code_prefix'] === null) {
            flash_set('ติ๊กใช้ชื่อย่อแล้ว กรุณากรอกค่าชื่อย่อ', 'err');
            // ?new=1 ให้หน้าเปิด popup เพิ่มรุ่นค้างไว้ ผู้ใช้จะได้แก้ต่อทันทีไม่ต้องกดเปิดใหม่
            return BASE_URL . '/settings.php?new=1';
        }
        $dup = qr("SELECT id FROM products WHERE product_code=?", 's', [$pcode])->fetch_assoc();
        if ($dup) {
            flash_set('รหัสสินค้า "' . $pcode . '" มีอยู่ในระบบแล้ว', 'err');
            // ?new=1 ให้หน้าเปิด popup เพิ่มรุ่นค้างไว้ ผู้ใช้จะได้แก้ต่อทันทีไม่ต้องกดเปิดใหม่
            return BASE_URL . '/settings.php?new=1';
        }
        $icon = save_upload('icon', 'products');
        $cat = trim((string)(isset($_POST['category']) ? $_POST['category'] : ''));
        q("INSERT INTO products (product_code,name,category,code_mode,code_prefix,code_year_era,running_digits,
           code_use_prefix,code_use_year,code_use_month,icon_path,is_active)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,1)", 'ssssssiiiis',
          [$pcode, $name, $cat !== '' ? $cat : null, $code['code_mode'], $code['code_prefix'], $code['code_year_era'],
           $code['running_digits'], $code['code_use_prefix'], $code['code_use_year'], $code['code_use_month'], $icon]);
        $newId = (int)db()->insert_id;
        if (array_key_exists('min_stock', $_POST) && function_exists('fg_shortage_save_min_stock')) {
            fg_shortage_save_min_stock([$pcode => (string) $_POST['min_stock']]);
        }
        flash_set('เพิ่มรุ่นสินค้าใหม่แล้ว');
        return BASE_URL . '/settings.php?product=' . ($newId > 0 ? $newId : 0);
    }

    return null;
}

/**
 * แสดงฟอร์มตั้งค่าการออกรหัส (ใช้ร่วมกันระหว่างเพิ่ม/แก้ไข)
 *
 * @param array<string, mixed>|null $r แถว products (null = ค่าเริ่มต้น)
 * @param string                  $prefixId คำนำหน้า id สำหรับ preview JS
 * @return void
 */
function product_admin_code_fields($r = null, $prefixId = 'code') {
    $r = is_array($r) ? $r : [];
    $cfg = product_code_normalize($r);
    $mode = (isset($r['code_mode']) && $r['code_mode'] === 'factory_serial') ? 'factory_serial' : 'generated';
    ?>
    <label>วิธีออกรหัสเครื่อง</label>
    <select name="code_mode" id="<?= h($prefixId) ?>-mode" onchange="productCodeToggle('<?= h($prefixId) ?>')">
      <option value="generated" <?= $mode === 'generated' ? 'selected' : '' ?>>ออกเลข running อัตโนมัติ</option>
      <option value="factory_serial" <?= $mode === 'factory_serial' ? 'selected' : '' ?>>กรอกเอง / สแกน QR</option>
    </select>

    <div id="<?= h($prefixId) ?>-gen-panel" class="full panel" style="padding:12px 14px; <?= $mode === 'generated' ? '' : 'display:none' ?>">
      <b>ส่วนประกอบรหัส — ติ๊กเลือกว่าจะใช้ส่วนไหน</b>
      <p class="muted" style="margin:4px 0 10px; font-size:13px">เลขรันนิ่งใช้เสมอ · รันนิ่งนับต่อเนื่องตามชื่อย่อ (prefix) เดียวกัน หรือตามรุ่นถ้าไม่มี prefix</p>
      <div style="display:grid; gap:10px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr))">
        <label style="display:flex; gap:8px; align-items:flex-start">
          <input type="checkbox" name="code_use_prefix" value="1" id="<?= h($prefixId) ?>-use-prefix"
            <?= $cfg['code_use_prefix'] ? 'checked' : '' ?> onchange="productCodePreview('<?= h($prefixId) ?>')">
          <span><b>ชื่อย่อ (prefix)</b><br>
            <input type="text" name="code_prefix" id="<?= h($prefixId) ?>-prefix" value="<?= h($cfg['code_prefix']) ?>"
              placeholder="เช่น BP, BX" maxlength="10" style="width:100%; margin-top:4px"
              oninput="productCodePreview('<?= h($prefixId) ?>')"></span>
        </label>
        <label style="display:flex; gap:8px; align-items:flex-start">
          <input type="checkbox" name="code_use_year" value="1" id="<?= h($prefixId) ?>-use-year"
            <?= $cfg['code_use_year'] ? 'checked' : '' ?> onchange="productCodePreview('<?= h($prefixId) ?>')">
          <span><b>ปี (2 หลักท้าย)</b><br>
            <select name="code_year_era" id="<?= h($prefixId) ?>-era" style="width:100%; margin-top:4px" onchange="productCodePreview('<?= h($prefixId) ?>')">
              <option value="ce" <?= $cfg['code_year_era'] === 'ce' ? 'selected' : '' ?>>ค.ศ.</option>
              <option value="be" <?= $cfg['code_year_era'] === 'be' ? 'selected' : '' ?>>พ.ศ.</option>
            </select></span>
        </label>
        <label style="display:flex; gap:8px; align-items:center">
          <input type="checkbox" name="code_use_month" value="1" id="<?= h($prefixId) ?>-use-month"
            <?= $cfg['code_use_month'] ? 'checked' : '' ?> onchange="productCodePreview('<?= h($prefixId) ?>')">
          <span><b>เดือน (2 หลัก)</b></span>
        </label>
        <label style="display:flex; gap:8px; align-items:flex-start">
          <span style="width:18px"></span>
          <span><b>เลขรันนิ่ง</b> (ใช้เสมอ)<br>
            <select name="running_digits" id="<?= h($prefixId) ?>-digits" style="width:100%; margin-top:4px" onchange="productCodePreview('<?= h($prefixId) ?>')">
              <?php for ($d = 3; $d <= 6; $d++) { ?>
                <option value="<?= $d ?>" <?= (int)$cfg['running_digits'] === $d ? 'selected' : '' ?>><?= $d ?> หลัก</option>
              <?php } ?>
            </select></span>
        </label>
      </div>
      <p style="margin:12px 0 0; font-size:13.5px">ตัวอย่าง: <code id="<?= h($prefixId) ?>-preview" style="font-size:15px; font-weight:600">—</code></p>
    </div>
    <?php
}

/**
 * สคริปต์ preview รูปแบบรหัส (include ครั้งเดียวต่อหน้า)
 *
 * @return void
 */
function product_admin_code_script() {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<script>
function productCodeToggle(id){
  var mode = document.getElementById(id + '-mode');
  var panel = document.getElementById(id + '-gen-panel');
  if (!mode || !panel) return;
  panel.style.display = mode.value === 'generated' ? '' : 'none';
  productCodePreview(id);
}
function productCodePreview(id){
  var el = document.getElementById(id + '-preview');
  if (!el) return;
  var mode = document.getElementById(id + '-mode');
  if (!mode || mode.value !== 'generated') { el.textContent = '—'; return; }
  var useP = document.getElementById(id + '-use-prefix') && document.getElementById(id + '-use-prefix').checked;
  var useY = document.getElementById(id + '-use-year') && document.getElementById(id + '-use-year').checked;
  var useM = document.getElementById(id + '-use-month') && document.getElementById(id + '-use-month').checked;
  var prefix = document.getElementById(id + '-prefix') ? document.getElementById(id + '-prefix').value : '';
  var era = document.getElementById(id + '-era') ? document.getElementById(id + '-era').value : 'ce';
  var digits = document.getElementById(id + '-digits') ? parseInt(document.getElementById(id + '-digits').value, 10) : 4;
  var dt = new Date();
  var out = '';
  if (useP && prefix) out += prefix;
  if (useY) {
    var y = dt.getFullYear();
    if (era === 'be') y += 543;
    out += String(y).slice(-2);
  }
  if (useM) out += ('0' + (dt.getMonth() + 1)).slice(-2);
  var run = '1';
  while (run.length < digits) run = '0' + run;
  out += run;
  el.textContent = out || '(ว่าง — ติ๊กอย่างน้อยส่วนประกอบหนึ่ง)';
}
</script>
    <?php
}

/**
 * ดึงรายการรุ่นสำหรับ grid หน้า settings
 *
 * @return mysqli_result|false
 */
function product_admin_list_query() {
    ensure_product_code_schema();
    return qr("SELECT p.*, (SELECT COUNT(*) FROM assets a WHERE a.product_id=p.id) n,
              (SELECT MAX(a.running_no) FROM assets a JOIN products p2 ON p2.id=a.product_id
               WHERE p.code_prefix IS NOT NULL AND p.code_prefix<>'' AND p2.code_prefix=p.code_prefix) max_run
            FROM products p WHERE p.is_active=1 ORDER BY p.id DESC, p.product_code ASC");
}