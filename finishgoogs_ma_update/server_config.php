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
$searchProbe = null;
$searchProbeQ = trim((string) ($_POST['probe_q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');
    $cfg = deploy_parse_form($_POST);

    if ($action === 'probe') {
        // ค้นจริงด้วยคำที่กรอก แล้วนับผลแยกตามแหล่ง — ตอบคำถาม "ทำไมค้นแล้วไม่เจอ"
        // ได้ตรงกว่าการทดสอบ connection เฉย ๆ เพราะเห็นด้วยว่าแหล่งไหนตอบ 0 รายการ
        require_once __DIR__ . '/includes/smart_search.php';
        $t0 = microtime(true);
        $hits = $searchProbeQ !== '' ? smart_search_query($searchProbeQ, 3) : [];
        $byKind = [];
        foreach ($hits as $h) {
            $k = (string) $h['kind'];
            $byKind[$k] = ($byKind[$k] ?? 0) + 1;
        }
        // นับแถวดิบในตารางต้นทางด้วย — ถ้าตารางมีข้อมูลตรงคำค้นแต่ผลค้นหาเป็น 0
        // แปลว่าโค้ดค้นหาผิด · ถ้าตารางเองก็ 0 แปลว่าข้อมูลไม่ได้อยู่ตรงที่คิด
        $rawCounts = [];
        $lk = '%' . $searchProbeQ . '%';
        if ($searchProbeQ !== '') {
            try {
                $rawCounts['customers ทั้งหมด'] = (int) qr('SELECT COUNT(*) n FROM customers')->fetch_assoc()['n'];
                $rawCounts['customers ที่ชื่อ/ไซต์/ผู้ติดต่อตรง'] = (int) qr(
                    'SELECT COUNT(*) n FROM customers WHERE name LIKE ? OR IFNULL(site_label,"") LIKE ?
                       OR IFNULL(contact_name,"") LIKE ? OR IFNULL(security_company,"") LIKE ?',
                    'ssss', [$lk, $lk, $lk, $lk])->fetch_assoc()['n'];
                $rawCounts['deployments ทั้งหมด'] = (int) qr('SELECT COUNT(*) n FROM deployments')->fetch_assoc()['n'];
                $rawCounts['repairs ทั้งหมด'] = (int) qr('SELECT COUNT(*) n FROM repairs')->fetch_assoc()['n'];
            } catch (Throwable $e) {
                $rawCounts['production'] = 'อ่านไม่ได้: ' . $e->getMessage();
            }
            $sdb = dbSetup();
            if ($sdb) {
                try {
                    $st = $sdb->prepare('SELECT COUNT(*) n FROM equipment_claim_history');
                    $st->execute(); $rawCounts['equipment_claim_history ทั้งหมด'] = (int) $st->get_result()->fetch_assoc()['n']; $st->close();
                    $st = $sdb->prepare('SELECT COUNT(*) n FROM equipment_claim_history
                        WHERE IFNULL(customer_name,"") LIKE ? OR IFNULL(site_name,"") LIKE ?');
                    $st->bind_param('ss', $lk, $lk);
                    $st->execute(); $rawCounts['ขาย/เคลม ที่ชื่อลูกค้าตรง'] = (int) $st->get_result()->fetch_assoc()['n']; $st->close();
                } catch (Throwable $e) {
                    $rawCounts['equipment_claim_history'] = 'อ่านไม่ได้: ' . $e->getMessage();
                }
            }
        }
        $searchProbe = [
            'q'     => $searchProbeQ,
            'raw'   => $rawCounts,
            'total' => count($hits),
            'ms'    => (int) round((microtime(true) - $t0) * 1000),
            'kinds' => $byKind,
            'dbs'   => [
                'stockparts (ทะเบียน stock)' => dbStock() ? '' : 'ต่อไม่ได้',
                'maintenance (งานซ่อม)'      => dbMaintenance() ? '' : dbMaintenanceError(),
                'setup (ขาย/เคลม)'           => dbSetup() ? '' : dbSetupError(),
            ],
        ];
    } elseif ($action === 'test') {
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
        <span class="muted" style="font-size:12px; overflow-wrap:anywhere"><?= h($c['detail']) ?></span>
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
        'maintenance' => 'biton_maintenance (ระบบซ่อม — ไม่บังคับ)',
        'setup'      => 'biton_setup (ประวัติขาย/เคลม — ไม่บังคับ)',
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
      $testLabels = ['production' => 'Production', 'stockparts' => 'Stockparts', 'techparts' => 'Tech parts', 'leasing' => 'Leasing (เช่า)', 'maintenance' => 'Maintenance (ซ่อม)', 'setup' => 'Setup (ขาย/เคลม)', 'parts' => 'Parts app'];
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

  <?php // ตรวจว่าช่องค้นหาหาเจอจริงไหม — พิมพ์คำที่รู้ว่ามีอยู่ (ชื่อลูกค้า, S/N, เลข PO)
        // แล้วดูว่าแหล่งไหนตอบมากี่รายการ · connection ผ่านแต่ตอบ 0 = ข้อมูลไม่ตรงคำค้น
        // ไม่ใช่ต่อฐานไม่ได้ ซึ่งเป็นคนละปัญหาและแก้คนละทาง ?>
  <h3 style="margin-top:20px">ตรวจช่องค้นหา</h3>
  <div style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end">
    <label style="flex:1 1 260px">คำค้นที่รู้ว่ามีอยู่จริง (ชื่อลูกค้า / S/N / เลข PO)
      <input type="text" name="probe_q" value="<?= h($searchProbeQ) ?>" placeholder="เช่น ชื่อลูกค้าที่มีในระบบ">
    </label>
    <button type="submit" name="action" value="probe" class="btn btn-line">ค้นทดสอบ</button>
  </div>
  <?php if ($searchProbe) { ?>
  <div style="margin-top:12px">
    <div class="test-row">
      <?php foreach ($searchProbe['dbs'] as $name => $err) { ?>
      <span class="test-pill <?= $err === '' ? 'ok' : 'fail' ?>"><?= h($name) ?>: <?= $err === '' ? 'ต่อได้' : h($err) ?></span>
      <?php } ?>
    </div>
    <?php $kindNames = ['asset' => 'เครื่อง', 'customer' => 'ลูกค้า/ไซต์', 'person' => 'คน', 'product' => 'รุ่นสินค้า',
        'ma' => 'MA', 'update' => 'อัปเดต FW/HW', 'part' => 'อะไหล่', 'stockout' => 'ใบเบิก',
        'stock' => 'ทะเบียน stock', 'repair' => 'งานซ่อม', 'sale' => 'ขาย', 'claim' => 'เคลม', 'order' => 'ส่งมอบ Order']; ?>
    <p style="margin:10px 0 4px; font-size:13px">
      ค้น <b><?= h($searchProbe['q']) ?></b> ได้ <b><?= (int) $searchProbe['total'] ?></b> รายการ
      ใน <?= (int) $searchProbe['ms'] ?> ms
    </p>
    <div class="test-row">
      <?php foreach ($kindNames as $k => $lbl) { $n = (int) ($searchProbe['kinds'][$k] ?? 0); ?>
      <span class="test-pill <?= $n > 0 ? 'ok' : 'fail' ?>"><?= h($lbl) ?>: <?= $n ?></span>
      <?php } ?>
    </div>
    <?php if (!empty($searchProbe['raw'])) { ?>
    <p style="margin:12px 0 4px; font-size:13px">จำนวนแถวในตารางต้นทาง (ไม่ผ่านตัวค้นหา)</p>
    <div class="test-row">
      <?php foreach ($searchProbe['raw'] as $lbl => $n) { $bad = is_string($n) || (int) $n === 0; ?>
      <span class="test-pill <?= $bad ? 'fail' : 'ok' ?>"><?= h($lbl) ?>: <?= h(is_string($n) ? $n : number_format($n)) ?></span>
      <?php } ?>
    </div>
    <?php } ?>
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
