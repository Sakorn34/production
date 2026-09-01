<?php
/**
 * server_config.php — ตั้งค่า path / DB / SSO ก่อน deploy ขึ้น server
 *
 * เข้าได้ผ่านระบบหลังบ้าน (PIN 9981) · บันทึกลง config.paths.php และ secrets นอก web root
 *
 * Flow: เปิดหน้า → ตรวจสุขภาพ → กรอกค่า → ทดสอบการเชื่อมต่อ → บันทึก
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require __DIR__ . '/includes/deploy_config.php';
require __DIR__ . '/includes/layout.php';

$defaults = deploy_form_defaults();
$testResults = null;
$saveMessages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');
    $cfg = deploy_parse_form($_POST);

    if ($action === 'test') {
        $testResults = deploy_test_all_connections($cfg);
    } else {
        $errors = deploy_validate_config($cfg);
        if ($errors) {
            flash_set(implode(' · ', $errors), 'err');
        } else {
            $save = deploy_save_config($cfg);
            $saveMessages = $save['messages'];
            if ($save['ok']) {
                flash_set('บันทึกการตั้งค่าแล้ว — แนะนำทดสอบการเชื่อมต่ออีกครั้ง', 'ok');
                header('Location: ' . BASE_URL . '/server_config.php?saved=1');
                exit;
            }
            flash_set('บันทึกไม่สำเร็จ — ดูรายละเอียดด้านล่าง', 'err');
        }
        $defaults = $cfg;
        $defaults['paths'] = $cfg['paths'];
        $defaults['finishgoogs'] = $cfg['finishgoogs'];
        $defaults['parts'] = $cfg['parts'];
        $defaults['parts_sync_tech'] = !empty($cfg['parts_sync_tech']);
    }
}

$health = deploy_health_checks();
$paths = $defaults['paths'];
$fg = $defaults['finishgoogs'];
$parts = $defaults['parts'];
$partsSync = !empty($defaults['parts_sync_tech']);
$pwMask = DEPLOY_PASSWORD_PLACEHOLDER;

page_header('ตั้งค่า Server / Deploy');
?>
<style>
.deploy-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:10px; margin-bottom:16px; }
.deploy-card { border:1px solid var(--border,var(--border, #e5e7eb)); border-radius:10px; padding:12px 14px; font-size:13px; }
.deploy-card.ok { border-color:#86efac; background:#f0fdf4; }
.deploy-card.fail { border-color:#fecaca; background:#fef2f2; }
.deploy-card b { display:block; margin-bottom:4px; }
.deploy-section { margin-bottom:22px; }
.deploy-section h3 { font-size:15px; margin:0 0 10px; color:var(--primary); }
.deploy-db-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:12px; }
.deploy-db-box { border:1px solid var(--border); border-radius:8px; padding:12px; background:#fafafa; }
.deploy-db-box h4 { margin:0 0 10px; font-size:13px; }
.deploy-db-box label { display:block; font-size:12px; margin:6px 0 2px; color:#6b7280; }
.deploy-db-box input { width:100%; box-sizing:border-box; }
.test-row { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.test-pill { font-size:12px; padding:4px 10px; border-radius:20px; }
.test-pill.ok { background:#dcfce7; color:#166534; }
.test-pill.fail { background:#fee2e2; color:#991b1b; }
.deploy-note { background:#eff6ff; border-left:3px solid #3b82f6; padding:10px 12px; border-radius:0 8px 8px 0; font-size:12.5px; color:#1e40af; margin-bottom:14px; }
</style>


<div class="deploy-note">
  <b>ก่อนเอาขึ้น Server</b> — ตั้ง path ไฟล์ secrets (นอก web root), การเชื่อมต่อ MySQL (production / stockparts / tech_parts และ leasing ถ้าใช้คิวรอ MA จากระบบเช่า),
  SSO URL และทดสอบการเชื่อมต่อจากหน้านี้ · หลังบันทึก ระบบจะเขียน
  <code>config.paths.php</code>, <code>finishgoogs.secrets.php</code> และ <code>parts.secrets.php</code>
</div>

<?php if ($saveMessages) { ?>
<div class="panel" style="margin-bottom:14px; font-size:13px">
  <?php foreach ($saveMessages as $m) { ?><div><?= h($m) ?></div><?php } ?>
</div>
<?php } ?>

<div class="deploy-section">
  <h3>สถานะระบบ</h3>
  <div class="deploy-grid">
    <?php foreach ($health as $c) { ?>
      <div class="deploy-card <?= $c['ok'] ? 'ok' : 'fail' ?>">
        <b><?= h($c['label']) ?></b>
        <span class="muted" style="font-size:12px; word-break:break-all"><?= h($c['detail']) ?></span>
      </div>
    <?php } ?>
  </div>
</div>

<form method="post" class="panel deploy-section">
  <?= csrf_field() ?>

  <h3>Path และ URL</h3>
  <div class="form-grid" style="display:grid; grid-template-columns:1fr; gap:10px; max-width:720px">
    <label>finishgoogs.secrets.php
      <input type="text" name="path_finishgoogs_secrets" value="<?= h($paths['finishgoogs_secrets'] ?? '') ?>" required>
    </label>
    <label>parts.secrets.php
      <input type="text" name="path_parts_secrets" value="<?= h($paths['parts_secrets'] ?? '') ?>" required>
    </label>
    <label>error_log (PHP)
      <input type="text" name="path_error_log" value="<?= h($paths['error_log'] ?? '') ?>" required>
    </label>
    <label>SSO Production (finishgoogs)
      <input type="url" name="sso_production_login_url" value="<?= h($paths['sso_production_login_url'] ?? '') ?>">
    </label>
    <label>SSO Parts
      <input type="url" name="sso_parts_login_url" value="<?= h($paths['sso_parts_login_url'] ?? '') ?>">
    </label>
    <label>URL สาธารณะ uploads (ว่าง = default)
      <input type="text" name="uploads_public_base" value="<?= h($paths['uploads_public_base'] ?? '') ?>" placeholder="/production/finishgoogs_ma_update/uploads/">
    </label>
  </div>

  <h3 style="margin-top:20px">ฐานข้อมูล MySQL</h3>
  <div class="deploy-db-grid">
    <?php
    $dbLabels = [
        'production' => 'bit_production (หลัก)',
        'stockparts' => 'biton_stockparts (S/N)',
        'techparts'  => 'biton_tech_parts (สต็อกช่าง)',
        'leasing'    => 'biton_leasing (ระบบเช่า — ไม่บังคับ)',
    ];
    foreach ($dbLabels as $key => $label) {
        $b = $fg[$key] ?? deploy_empty_db_block();
        ?>
    <div class="deploy-db-box">
      <h4><?= h($label) ?></h4>
      <label>Host<input type="text" name="<?= h($key) ?>_host" value="<?= h($b['host']) ?>"></label>
      <label>Database<input type="text" name="<?= h($key) ?>_db" value="<?= h($b['db']) ?>"></label>
      <label>User<input type="text" name="<?= h($key) ?>_user" value="<?= h($b['user']) ?>" autocomplete="off"></label>
      <label>Password<input type="password" name="<?= h($key) ?>_pass" value="<?= h($pwMask) ?>" autocomplete="new-password" placeholder="ว่าง = ไม่เปลี่ยน"></label>
    </div>
    <?php } ?>
  </div>

  <h3 style="margin-top:20px">Parts app (parts.secrets.php)</h3>
  <label style="display:flex; align-items:center; gap:8px; margin-bottom:10px; font-size:13px">
    <input type="checkbox" name="parts_sync_techparts" value="1" <?= $partsSync ? 'checked' : '' ?> id="parts-sync">
    ใช้ค่าเดียวกับ biton_tech_parts ด้านบน (แนะนำ)
  </label>
  <div class="deploy-db-grid" id="parts-fields">
    <div class="deploy-db-box">
      <h4>biton_tech_parts</h4>
      <label>Host<input type="text" name="parts_host" value="<?= h($parts['host']) ?>" <?= $partsSync ? 'readonly' : '' ?>></label>
      <label>Database<input type="text" name="parts_db" value="<?= h($parts['db']) ?>" <?= $partsSync ? 'readonly' : '' ?>></label>
      <label>User<input type="text" name="parts_user" value="<?= h($parts['user']) ?>" autocomplete="off" <?= $partsSync ? 'readonly' : '' ?>></label>
      <label>Password<input type="password" name="parts_pass" value="<?= h($pwMask) ?>" autocomplete="new-password" <?= $partsSync ? 'readonly' : '' ?>></label>
      <label>Charset<input type="text" name="parts_charset" value="<?= h($parts['charset'] ?? 'utf8mb4') ?>"></label>
    </div>
  </div>

  <?php if ($testResults) { ?>
  <div style="margin-top:16px">
    <b>ผลทดสอบการเชื่อมต่อ</b>
    <div class="test-row">
      <?php
      $testLabels = ['production' => 'Production', 'stockparts' => 'Stockparts', 'techparts' => 'Tech parts', 'leasing' => 'Leasing (เช่า)', 'parts' => 'Parts app'];
      foreach ($testLabels as $k => $lbl) {
          $t = $testResults[$k] ?? ['ok' => false, 'message' => '-', 'detail' => ''];
          ?>
        <span class="test-pill <?= $t['ok'] ? 'ok' : 'fail' ?>">
          <?= h($lbl) ?>: <?= h($t['message']) ?><?= $t['detail'] !== '' ? ' — ' . h($t['detail']) : '' ?>
        </span>
      <?php } ?>
    </div>
  </div>
  <?php } ?>

  <div style="display:flex; flex-wrap:wrap; gap:10px; margin-top:20px">
    <button type="submit" name="action" value="test" class="btn btn-line">🔌 ทดสอบการเชื่อมต่อ</button>
    <button type="submit" name="action" value="save" class="btn btn-primary"><?= ui_btn_label('save', 'บันทึกการตั้งค่า') ?></button>
  </div>
</form>

<div class="panel deploy-section muted" style="font-size:12.5px; line-height:1.7">
  <b>Checklist หลังบันทึก</b>
  <ol style="margin:8px 0 0 18px">
    <li>Import DB บน server (production, stockparts, tech_parts และ biton_leasing ถ้าใช้คิวรอ MA)</li>
    <li>Deploy โฟลเดอร์ <code>/production/</code> ครบ (finishgoogs_ma_update, parts, shared)</li>
    <li>ตั้ง SSO callback ให้กลับ domain ที่ deploy</li>
    <li>ทดสอบ login ทั้ง Production และ Parts</li>
    <li>ทดสอบเบิกอะไหล่ → quantity ใน tech_parts ลด</li>
  </ol>
</div>

<script>
(function(){
  var sync = document.getElementById('parts-sync');
  if (!sync) return;
  var fields = document.querySelectorAll('#parts-fields input');
  function toggle(){
    var ro = sync.checked;
    fields.forEach(function(el){ el.readOnly = ro; if (ro) el.classList.add('muted'); else el.classList.remove('muted'); });
  }
  sync.addEventListener('change', toggle);
  toggle();
})();
</script>

<?php page_footer(); ?>
