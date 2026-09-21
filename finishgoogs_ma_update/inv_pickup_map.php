<?php
/**
 * inv_pickup_map.php — ผูกใบเบิกผลิตของระบบ inventory กับรุ่นของเรา (ระบบหลังบ้าน)
 *
 * inventory ไม่มีรหัสรุ่น มีแค่ชื่อชุด (bitVisitor Plus, bitStamp-G …) และชื่ออะไหล่
 * จึงต้องผูกเองครั้งเดียว:
 *   ชุดทั่วไป        → ทั้งใบ = รุ่นเดียว (bitStamp-G → bitStamp Guard)
 *   ชุด Accessories → แยกรายชิ้น แล้วผูกอะไหล่แต่ละตัว (Mobile Printer → Portable Printer · สายคล้อง = ไม่ใช่เครื่อง)
 *   ไม่ติดตาม         → ไม่ขึ้นในคิวผลิต
 * เลือกได้หลายรุ่น (อะไหล่/ชุดที่ใช้ได้หลายรุ่น) · อะไหล่ตั้ง "ชิ้นต่อเครื่อง" ได้ (บางตัวใช้ 2 ชิ้นต่อเครื่อง)
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
        $grp = $mode === 'set' ? inv_pickup_grp((array) ($_POST['set_products'][$name] ?? [])) : '';
        q('REPLACE INTO inv_set_map (inv_name, mode, product_ids, updated_by) VALUES (?, ?, ?, ?)',
          'ssss', [$name, $mode, $grp, $who]);
    }
    foreach ((array) ($_POST['part_seen'] ?? []) as $partId => $_) {
        $partId = (int) $partId;
        $picked = (array) ($_POST['part_products'][$partId] ?? []);
        if ($partId <= 0 || !$picked) {
            continue;   // ยังไม่ได้เลือกอะไรเลย = ยังไม่ตัดสินใจ ไม่บันทึก
        }
        // ติ๊ก "ไม่ใช่เครื่อง" (ค่า 0) = ไม่ติดตาม — รุ่นอื่นที่ติ๊กพร้อมกันไม่นับ
        $grp = in_array('0', $picked, true) ? '' : inv_pickup_grp($picked);
        $per = (float) str_replace(',', '.', (string) ($_POST['part_per'][$partId] ?? '1'));
        q('REPLACE INTO inv_part_map (inv_part_id, product_ids, per_unit, updated_by) VALUES (?, ?, ?, ?)',
          'isds', [$partId, $grp, $per > 0 ? min($per, 999) : 1, $who]);
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
/**
 * ช่องเลือกรุ่น (ติ๊กได้หลายรุ่น) — กางเป็นรายการติ๊กใต้ปุ่ม ไม่ใช้ <select multiple> ที่ใช้ยากบนมือถือ
 *
 * @param string $field     ชื่อ input (ต่อท้าย [] เอง)
 * @param int[]  $sel       รุ่นที่เลือก
 * @param bool   $allowNone มีตัวเลือก "ไม่ใช่เครื่อง"
 * @param bool   $noneSel   เลือก "ไม่ใช่เครื่อง" อยู่
 */
$modelPicker = function (string $field, array $sel, bool $allowNone, bool $noneSel = false) use ($products) {
    $names = array_values(array_filter(array_map(function ($id) use ($products) { return $products[$id] ?? null; }, $sel)));
    $sum = $noneSel ? 'ไม่ใช่เครื่อง' : ($names ? implode(' / ', $names) : '— เลือกรุ่น —');
    $h = '<details class="im-pick"><summary>' . h($sum) . '</summary><div class="im-pick-list">';
    if ($allowNone) {
        $h .= '<label class="im-none"><input type="checkbox" name="' . h($field) . '[]" value="0"' . ($noneSel ? ' checked' : '') . '> ไม่ใช่เครื่อง (ไม่ติดตาม)</label>';
    }
    foreach ($products as $id => $name) {
        $h .= '<label><input type="checkbox" name="' . h($field) . '[]" value="' . $id . '"' . (in_array($id, $sel, true) ? ' checked' : '') . '> ' . h($name) . '</label>';
    }
    return $h . '</div></details>';
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
          $sug = $s['saved'] ? null : inv_pickup_suggest_product($s['name'], $products);
          $sel = $s['grp'] !== '' ? inv_pickup_grp_ids($s['grp']) : ($sug ? [$sug] : []);
          $k = h($s['name']); ?>
      <tr class="<?= $s['saved'] ? '' : 'im-new' ?>">
        <td><b><?= $k ?></b><?= $s['saved'] ? '' : ' <span class="im-badge">ยังไม่บันทึก</span>' ?><div class="muted" style="font-size:12px">ล่าสุด <?= h(date('d/m/Y', strtotime($s['last']))) ?></div></td>
        <td class="num-col"><?= (int) $s['docs'] ?></td>
        <td><select name="set_mode[<?= $k ?>]" class="im-mode">
          <option value="set"<?= $mode === 'set' ? ' selected' : '' ?>>ทั้งใบ = 1 รุ่น</option>
          <option value="parts"<?= $mode === 'parts' ? ' selected' : '' ?>>แยกรายชิ้น</option>
          <option value="ignore"<?= $mode === 'ignore' ? ' selected' : '' ?>>ไม่ติดตาม</option>
        </select></td>
        <td><div class="im-prod"<?= $mode !== 'set' ? ' hidden' : '' ?>><?= $modelPicker('set_products[' . $s['name'] . ']', $sel, false) ?></div>
          <span class="muted im-parts-note"<?= $mode === 'parts' ? '' : ' hidden' ?>>ผูกรายอะไหล่ด้านล่าง</span></td>
      </tr>
      <?php } ?>
    </table></div>
  </div>

  <?php if ($rows['parts']) { ?>
  <div class="panel">
    <h3 style="margin:0 0 4px">อะไหล่ในใบแบบแยกรายชิ้น</h3>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">ของสำเร็จรูปที่เป็นเครื่องของเรา → ติ๊กรุ่น (ใช้ได้หลายรุ่นติ๊กได้หลายอัน) · ของที่ไม่ใช่เครื่อง (สายคล้อง กล่อง PCB) → "ไม่ใช่เครื่อง"<br><b>ชิ้นต่อเครื่อง</b> — ใช้ 2 ชิ้นต่อ 1 เครื่อง ใส่ 2 · จำนวนเครื่อง = ชิ้นที่เบิก ÷ ชิ้นต่อเครื่อง</p>
    <div class="table-wrap"><table class="list im-table">
      <tr><th>อะไหล่ใน inventory</th><th class="num-col">ใบ</th><th>รุ่นของเรา</th><th>ชิ้นต่อเครื่อง</th></tr>
      <?php foreach ($rows['parts'] as $p) {
          $sug = $p['saved'] ? null : inv_pickup_suggest_product($p['name'], $products);
          $sel = $p['grp'] !== '' ? inv_pickup_grp_ids($p['grp']) : ($sug ? [$sug] : []);
          $none = $p['saved'] && $p['grp'] === ''; ?>
      <tr class="<?= $p['saved'] ? '' : 'im-new' ?>">
        <td><?= h($p['name']) ?><?= $p['saved'] ? '' : ' <span class="im-badge">ยังไม่บันทึก</span>' ?><div class="muted" style="font-size:12px"><?= h(implode(', ', array_unique($p['sets']))) ?></div></td>
        <td class="num-col"><?= (int) $p['docs'] ?></td>
        <td><input type="hidden" name="part_seen[<?= (int) $p['part_id'] ?>]" value="1"><?= $modelPicker('part_products[' . (int) $p['part_id'] . ']', $sel, true, $none) ?></td>
        <td><input type="number" name="part_per[<?= (int) $p['part_id'] ?>]" value="<?= h((string) (0 + $p['per_unit'])) ?>" min="0.01" step="any" class="im-per" aria-label="ชิ้นต่อเครื่อง"></td>
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
document.querySelectorAll('.im-pick').forEach(function (d) {
  d.addEventListener('change', function (e) {
    var boxes = [].slice.call(d.querySelectorAll('input[type=checkbox]'));
    if (e.target.value === '0' && e.target.checked) {
      boxes.forEach(function (b) { if (b.value !== '0') { b.checked = false; } });
    } else if (e.target.checked) {
      boxes.forEach(function (b) { if (b.value === '0') { b.checked = false; } });
    }
    var on = boxes.filter(function (b) { return b.checked; });
    d.querySelector('summary').textContent = !on.length ? '— เลือกรุ่น —'
      : on.map(function (b) { return b.value === '0' ? 'ไม่ใช่เครื่อง' : b.parentNode.textContent.trim(); }).join(' / ');
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
.im-pick { position: relative; min-width: 0; }
.im-pick summary { cursor: pointer; padding: 6px 10px; border: 1px solid var(--border, #d9d4e6); border-radius: 8px; background: var(--surface, #fff); list-style: none; overflow-wrap: anywhere; }
.im-pick summary::-webkit-details-marker { display: none; }
.im-pick summary::after { content: ' ▾'; color: var(--muted, #6b7280); }
.im-pick-list { max-height: 260px; overflow: auto; border: 1px solid var(--border, #d9d4e6); border-radius: 8px; margin-top: 4px; padding: 4px 8px; background: var(--surface, #fff); }
.im-pick-list label { display: flex; gap: 8px; align-items: center; padding: 4px 0; font-weight: 400; }
.im-pick-list .im-none { border-bottom: 1px dashed var(--border, #e5e7eb); margin-bottom: 4px; padding-bottom: 6px; }
.im-per { width: 76px; }
</style>
<?php page_footer(); ?>
