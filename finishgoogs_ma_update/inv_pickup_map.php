<?php
/**
 * inv_pickup_map.php — ผูกใบเบิกผลิตของระบบ inventory กับรุ่นของเรา (ระบบหลังบ้าน)
 *
 * inventory ไม่มีรหัสรุ่น มีแค่ชื่อชุด (bitVisitor Plus, bitStamp-G …) และชื่ออะไหล่
 * จึงต้องผูกเองครั้งเดียว:
 *   ชุดทั่วไป        → ทั้งใบ = รุ่นเดียว (bitStamp-G → bitStamp Guard)
 *   ชุด Accessories → แยกรายชิ้น แล้วผูกอะไหล่แต่ละตัว (Mobile Printer → Portable Printer · สายคล้อง = ไม่ใช่เครื่อง)
 *   ไม่ติดตาม         → ไม่ขึ้นในคิวผลิต
 * ค่าที่ขึ้นมาให้ตอนยังไม่ได้บันทึกเป็นแค่คำแนะนำจากชื่อที่คล้ายกัน
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/inv_pickup.php';
ensure_inv_pickup_schema();

$B = BASE_URL;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backfill'])) {
    csrf_check();
    $bf = inv_pickup_backfill(actor_name());
    flash_set($bf['ok'] ? 'จับคู่ย้อนหลังแล้ว ' . $bf['allocated'] . ' เครื่อง' : 'จับคู่ไม่สำเร็จ: ' . $bf['error'], $bf['ok'] ? 'ok' : 'err');
    header('Location: ' . $B . '/inv_pickup_map.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_map'])) {
    csrf_check();
    $who = actor_name();
    $since = trim((string) ($_POST['since'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
        set_setting(INV_PICKUP_SINCE_KEY, $since);
    }
    foreach ((array) ($_POST['set_mode'] ?? []) as $name => $mode) {
        $name = mb_substr(trim((string) $name), 0, 191);
        if ($name === '' || !in_array($mode, ['set', 'parts', 'ignore'], true)) {
            continue;
        }
        $pid = (int) ($_POST['set_product'][$name] ?? 0);
        q('REPLACE INTO inv_set_map (inv_name, mode, product_id, updated_by) VALUES (?, ?, ?, ?)',
          'ssis', [$name, $mode, $mode === 'set' && $pid > 0 ? $pid : null, $who]);
    }
    foreach ((array) ($_POST['part_product'] ?? []) as $partId => $pid) {
        $partId = (int) $partId;
        if ($partId <= 0 || $pid === '') {
            continue;   // "— เลือก —" = ยังไม่ตัดสินใจ ไม่บันทึก
        }
        q('REPLACE INTO inv_part_map (inv_part_id, product_id, updated_by) VALUES (?, ?, ?)',
          'iis', [$partId, (int) $pid > 0 ? (int) $pid : null, $who]);
    }
    flash_set('บันทึกการผูกรุ่นแล้ว');
    header('Location: ' . $B . '/inv_pickup_map.php');
    exit;
}

$rows = inv_pickup_mapping_rows();
$products = [];
$r = db()->query('SELECT id, name FROM products WHERE is_active = 1 ORDER BY name');
while ($x = $r->fetch_row()) {
    $products[(int) $x[0]] = (string) $x[1];
}
$productOptions = function (?int $sel, bool $allowNone) use ($products) {
    $h = '<option value="">— เลือก —</option>';
    if ($allowNone) {
        $h .= '<option value="0"' . ($sel === 0 ? ' selected' : '') . '>ไม่ใช่เครื่อง (ไม่ติดตาม)</option>';
    }
    foreach ($products as $id => $name) {
        $h .= '<option value="' . $id . '"' . ($sel === $id ? ' selected' : '') . '>' . h($name) . '</option>';
    }
    return $h;
};

page_header('ผูกใบเบิก inventory กับรุ่น', true, 'ทำครั้งเดียว — ใช้แยกใบเบิกผลิตเป็นรุ่นในหน้า "ใบเบิกรอผลิต"');
?>
<?php if (!$rows['ok']) { ?>
<div class="panel"><p class="err" style="margin:0">ต่อระบบ inventory ไม่ได้: <?= h($rows['error']) ?></p>
  <p class="muted" style="margin:8px 0 0;font-size:13px">ต้องเพิ่มค่าเชื่อมต่อ <code>inventory</code> (host · db · user · pass) ในไฟล์ finishgoogs.secrets.php บนเซิร์ฟเวอร์ — แนะนำ MySQL user ที่อ่านได้อย่างเดียว</p></div>
<?php } else { ?>
<form method="post" class="im-form">
  <?= csrf_field() ?>
  <div class="panel">
    <label class="im-since">เริ่มติดตามใบเบิกตั้งแต่วันที่
      <input type="date" name="since" value="<?= h(inv_pickup_since()) ?>">
    </label>
    <p class="muted" style="margin:4px 0 0;font-size:12.5px">ใบที่เบิกก่อนวันนี้ถือว่าผลิตไปหมดแล้ว ไม่ขึ้นในคิวผลิต</p>
  </div>

  <div class="panel">
    <h3 style="margin:0 0 4px">ชุด / หมวดในใบเบิก</h3>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px"><b>ทั้งใบ = 1 รุ่น</b> — ใบผลิตทั้งชุด นับจำนวนเครื่องจากจำนวนชุดที่เบิก ·
      <b>แยกรายชิ้น</b> — ใบที่รวมของสำเร็จรูปหลายอย่าง (Accessories) ผูกรายอะไหล่ในตารางถัดไป</p>
    <div class="table-wrap"><table class="list im-table">
      <tr><th>ในระบบ inventory</th><th class="num-col">ใบ (1 ปี)</th><th>วิธีนับ</th><th>รุ่นของเรา</th></tr>
      <?php foreach ($rows['sets'] as $s) {
          $mode = $s['mode'] !== '' ? $s['mode'] : (stripos($s['name'], 'accessor') !== false ? 'parts' : 'set');
          $sel = $s['product_id'] !== null ? (int) $s['product_id'] : ($s['saved'] ? null : inv_pickup_suggest_product($s['name'], $products));
          $k = h($s['name']); ?>
      <tr class="<?= $s['saved'] ? '' : 'im-new' ?>">
        <td><b><?= $k ?></b><?= $s['saved'] ? '' : ' <span class="im-badge">ยังไม่บันทึก</span>' ?><div class="muted" style="font-size:12px">ล่าสุด <?= h(date('d/m/Y', strtotime($s['last']))) ?></div></td>
        <td class="num-col"><?= (int) $s['docs'] ?></td>
        <td><select name="set_mode[<?= $k ?>]" class="im-mode">
          <option value="set"<?= $mode === 'set' ? ' selected' : '' ?>>ทั้งใบ = 1 รุ่น</option>
          <option value="parts"<?= $mode === 'parts' ? ' selected' : '' ?>>แยกรายชิ้น</option>
          <option value="ignore"<?= $mode === 'ignore' ? ' selected' : '' ?>>ไม่ติดตาม</option>
        </select></td>
        <td><select name="set_product[<?= $k ?>]" class="im-prod"<?= $mode !== 'set' ? ' hidden' : '' ?>><?= $productOptions($sel, false) ?></select>
          <span class="muted im-parts-note"<?= $mode === 'parts' ? '' : ' hidden' ?>>ผูกรายอะไหล่ด้านล่าง</span></td>
      </tr>
      <?php } ?>
    </table></div>
  </div>

  <?php if ($rows['parts']) { ?>
  <div class="panel">
    <h3 style="margin:0 0 4px">อะไหล่ในใบแบบแยกรายชิ้น</h3>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">ของสำเร็จรูปที่เป็นเครื่องของเรา → เลือกรุ่น · ของที่ไม่ใช่เครื่อง (สายคล้อง กล่อง PCB) → "ไม่ใช่เครื่อง"</p>
    <div class="table-wrap"><table class="list im-table">
      <tr><th>อะไหล่ใน inventory</th><th class="num-col">ใบ</th><th>รุ่นของเรา</th></tr>
      <?php foreach ($rows['parts'] as $p) {
          $sel = $p['saved'] ? ($p['product_id'] !== null ? (int) $p['product_id'] : 0) : inv_pickup_suggest_product($p['name'], $products); ?>
      <tr class="<?= $p['saved'] ? '' : 'im-new' ?>">
        <td><?= h($p['name']) ?><?= $p['saved'] ? '' : ' <span class="im-badge">ยังไม่บันทึก</span>' ?><div class="muted" style="font-size:12px"><?= h(implode(', ', array_unique($p['sets']))) ?></div></td>
        <td class="num-col"><?= (int) $p['docs'] ?></td>
        <td><select name="part_product[<?= (int) $p['part_id'] ?>]"><?= $productOptions($sel, true) ?></select></td>
      </tr>
      <?php } ?>
    </table></div>
  </div>
  <?php } ?>

  <div class="im-actions"><button type="submit" name="save_map" value="1" class="btn">บันทึกการผูก</button>
    <a class="btn btn-line" href="<?= $B ?>/inv_pickups.php">ไปหน้าใบเบิกรอผลิต</a></div>
</form>
<form method="post" class="panel">
  <?= csrf_field() ?>
  <h3 style="margin:0 0 4px">จับคู่เครื่องที่ลงทะเบียนไปแล้ว (ย้อนหลัง)</h3>
  <p class="muted" style="margin:0 0 10px;font-size:12.5px">ใช้ตอนเริ่มใช้ หรือหลังผูกรุ่นเพิ่ม — เครื่องที่ลงทะเบียน<b>หลัง</b>คลังจ่ายของ แต่ยังไม่ได้ตัดยอด
    จะถูกตัดจากใบเบิกรุ่นเดียวกันที่เก่าสุดก่อน (แบบเดียวกับตอนลงทะเบียน) · กดซ้ำได้ ไม่ตัดซ้ำ</p>
  <button type="submit" name="backfill" value="1" class="btn btn-line" onclick="return confirm('จับคู่เครื่องที่ลงทะเบียนไปแล้วกับใบเบิกที่ยังค้าง?')">จับคู่ย้อนหลัง</button>
</form>
<script>
document.querySelectorAll('.im-mode').forEach(function (s) {
  s.addEventListener('change', function () {
    var tr = s.closest('tr');
    tr.querySelector('.im-prod').hidden = s.value !== 'set';
    tr.querySelector('.im-parts-note').hidden = s.value !== 'parts';
  });
});
</script>
<?php } ?>
<style>
.im-table select { max-width: 100%; min-width: 0; }
.im-table tr.im-new td { background: #fffbeb; }
.im-badge { display: inline-block; font-size: 11px; background: #fef3c7; color: #854d0e; border-radius: 6px; padding: 0 6px; margin-left: 4px; font-weight: 400; }
.im-since { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; font-weight: 600; }
.im-actions { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 20px; }
.im-table [hidden] { display: none !important; }
</style>
<?php page_footer(); ?>
