<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

/* ─── AJAX: เครื่องที่เบิกอะไหล่ชิ้นนี้ ─────────────────────────── */
if (isset($_GET['part_used'])) {
    $pid  = (int)$_GET['part_used'];
    $part = qr("SELECT name, unit FROM parts WHERE id=?", 'i', [$pid])->fetch_assoc();
    $unit = ($part && $part['unit']) ? $part['unit'] : '';
    $res  = qr("SELECT pm.moved_at, pm.qty, pm.made_by, pm.remark,
                       a.id asset_id, a.asset_code, a.status, p.name pname, c.name cust
                FROM   part_movements pm
                LEFT JOIN assets a   ON a.id  = pm.ref_asset_id
                LEFT JOIN products p ON p.id  = a.product_id
                LEFT JOIN customers c ON c.id = a.current_customer_id
                WHERE  pm.part_id = ? AND pm.direction = 'out'
                ORDER  BY pm.moved_at DESC, pm.id DESC LIMIT 300", 'i', [$pid]);
    echo '<table class="list"><tr><th>วันที่</th><th>เครื่อง</th><th>รุ่น</th><th>ลูกค้า</th><th style="text-align:right">จำนวน</th><th>ผู้เบิก</th></tr>';
    $n = 0;
    while ($r = $res->fetch_assoc()) {
        $n++;
        $code = $r['asset_code']
            ? '<a href="' . BASE_URL . '/asset.php?id=' . $r['asset_id'] . '">' . h($r['asset_code']) . '</a> ' . status_badge($r['status'])
            : '<span class="muted">ไม่ระบุเครื่อง</span>';
        $rem = trim((string)$r['remark']);
        $qty = rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.');
        echo '<tr>'
           . '<td style="white-space:nowrap">' . dthai_full($r['moved_at']) . '</td>'
           . '<td>' . $code . ($rem !== '' ? '<br><span class="muted">' . h($rem) . '</span>' : '') . '</td>'
           . '<td>' . h($r['pname'] ?: '-') . '</td>'
           . '<td>' . h($r['cust'] ?: '-') . '</td>'
           . '<td style="text-align:right">' . $qty . ($unit ? ' <span class="muted">' . h($unit) . '</span>' : '') . '</td>'
           . '<td>' . h($r['made_by'] ?: '-') . '</td>'
           . '</tr>';
    }
    if (!$n) echo '<tr><td colspan="6" class="muted" style="text-align:center;padding:14px">ยังไม่มีการเบิกอะไหล่ชิ้นนี้</td></tr>';
    echo '</table>';
    exit;
}

/* ─── AJAX: รายการอะไหล่ทั้งหมด (popup) ─────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'parts_list') {
    header('Content-Type: text/html; charset=utf-8');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $w = ''; $types = ''; $params = [];
    if ($q !== '') {
        $like = "%$q%";
        $w = "WHERE (p.name LIKE ? OR p.part_code LIKE ? OR p.category LIKE ?)";
        $types = 'sss'; $params = [$like, $like, $like];
    }
    $rows = qr("SELECT p.*, COALESCE((SELECT SUM(pm.qty) FROM part_movements pm WHERE pm.part_id=p.id AND pm.direction='out'),0) used_qty
                FROM parts p $w ORDER BY p.name LIMIT 300", $types, $params);

    $baseUrl = BASE_URL;
    ?>
    <form onsubmit="event.preventDefault();var qv=encodeURIComponent(this.q.value);showListModal('รายการอะไหล่','<?= h($baseUrl) ?>/parts.php?ajax=parts_list&q='+qv,'');" style="display:flex;gap:8px;margin-bottom:10px">
      <input name="q" type="text" value="<?= h($q) ?>" placeholder="ค้นหาชื่อ / รหัส / หมวด" style="flex:1;min-width:0">
      <button type="submit">ค้นหา</button>
    </form>
    <table class="list">
      <tr><th></th><th>อะไหล่</th><th>หมวด</th><th style="text-align:right">เบิกแล้ว</th><th>ร้านค้า</th><?= can('parts') ? '<th style="white-space:nowrap">การดำเนินการ</th>' : '' ?></tr>
      <?php
      $hasRows = false;
      while ($r = $rows->fetch_assoc()) {
          $hasRows = true;
          $qty = rtrim(rtrim(number_format((float)$r['used_qty'], 2), '0'), '.');
          $usedLink = (float)$r['used_qty'] > 0
              ? '<a href="javascript:void(0)" onclick="showListModal(' . h(json_encode('เครื่องที่เบิก: ' . $r['name'], JSON_UNESCAPED_UNICODE)) . ',' . h(json_encode($baseUrl . '/parts.php?part_used=' . $r['id'])) . ",'')\">"
                . '<b>' . $qty . '</b>' . ($r['unit'] ? ' <span class="muted">' . h($r['unit']) . '</span>' : '') . '</a>'
              : '<span class="muted">0</span>';
          ?>
      <tr>
        <td style="width:48px"><?= img_tag($r['icon_path'], $r['name']) ?></td>
        <td>
          <b><?= h($r['name']) ?></b>
          <?= $r['part_code'] ? '<br><span class="muted">' . h($r['part_code']) . '</span>' : '' ?>
        </td>
        <td><?= h($r['category'] ?: '-') ?></td>
        <td style="text-align:right"><?= $usedLink ?></td>
        <td><?= h($r['dealer'] ?: '-') ?><?= $r['link'] ? ' · <a href="' . h($r['link']) . '" target="_blank">ลิงก์</a>' : '' ?></td>
        <?php if (can('parts')) { ?>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-line" style="margin-right:4px"
                  onclick="openWithdraw(<?= $r['id'] ?>,<?= h(json_encode($r['name'], JSON_UNESCAPED_UNICODE)) ?>)">เบิกใช้</button>
          <button class="btn btn-sm"
                  onclick="showListModal(<?= h(json_encode('แก้ไข: ' . $r['name'], JSON_UNESCAPED_UNICODE)) ?>,<?= h(json_encode($baseUrl . '/parts.php?ajax=edit_part_form&id=' . $r['id'])) ?>,'')">แก้ไข</button>
        </td>
        <?php } ?>
      </tr>
      <?php } if (!$hasRows) echo '<tr><td colspan="6" class="muted" style="text-align:center;padding:16px">ไม่พบอะไหล่</td></tr>'; ?>
    </table>
    <?php
    exit;
}

/* ─── AJAX: แบบฟอร์มแก้ไขรายการเบิก ─────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_move_form') {
    header('Content-Type: text/html; charset=utf-8');
    require_can('parts');
    $mid = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    $m = $mid ? qr("SELECT pm.*, pt.name pname, a.asset_code
                    FROM part_movements pm
                    JOIN parts pt ON pt.id=pm.part_id
                    LEFT JOIN assets a ON a.id=pm.ref_asset_id
                    WHERE pm.id=? AND pm.direction='out'", 'i', [$mid])->fetch_assoc() : null;
    if (!$m) { echo '<p class="muted">ไม่พบรายการ</p>'; exit; }
    ?>
    <form method="post" action="<?= BASE_URL ?>/parts.php">
      <?= csrf_field() ?>
      <input type="hidden" name="edit_movement" value="1">
      <input type="hidden" name="movement_id" value="<?= (int)$m['id'] ?>">
      <div class="formgrid form-narrow">
        <label>อะไหล่</label><input type="text" value="<?= h($m['pname']) ?>" readonly>
        <label>จำนวน</label>
        <div class="qty-stepper">
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="-0.5">−</button>
          <input type="number" name="qty" value="<?= h(rtrim(rtrim(number_format((float)$m['qty'], 2), '0'), '.')) ?>" step="0.5" min="0.5" required>
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="0.5">+</button>
        </div>
        <label>หมายเลขสินค้า</label><input type="text" name="asset_code" class="asset-search" value="<?= h($m['asset_code'] ?? '') ?>" placeholder="พิมพ์แล้วเลือก">
        <label>หมายเหตุ</label><input type="text" name="remark" value="<?= h($m['remark'] ?? '') ?>">
        <div class="full" style="margin-top:10px; display:flex; gap:8px">
          <button type="submit" class="btn">บันทึกการแก้ไข</button>
        </div>
      </div>
    </form>
    <?php
    exit;
}

/* ─── AJAX: แบบฟอร์มเพิ่มรายการเบิก ─────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'add_move_form') {
    header('Content-Type: text/html; charset=utf-8');
    require_can('parts');
    $parts = qr("SELECT id, name FROM parts ORDER BY name LIMIT 500");
    ?>
    <form method="post" action="<?= BASE_URL ?>/parts.php">
      <?= csrf_field() ?>
      <input type="hidden" name="move_part" value="1">
      <div class="formgrid form-narrow">
        <label>อะไหล่</label>
        <select name="part_id" required>
          <option value="">— เลือกอะไหล่ —</option>
          <?php while ($p = $parts->fetch_assoc()) { ?>
            <option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option>
          <?php } ?>
        </select>
        <label>จำนวน</label>
        <div class="qty-stepper">
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="-0.5">−</button>
          <input type="number" name="qty" value="1" step="0.5" min="0.5" required>
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="0.5">+</button>
        </div>
        <label>หมายเลขสินค้า</label><input type="text" name="asset_code" class="asset-search" placeholder="พิมพ์แล้วเลือก">
        <label>หมายเหตุ</label><input type="text" name="remark" placeholder="ถ้ามี">
        <div class="full" style="margin-top:10px"><button type="submit" class="btn">บันทึกการเบิก</button></div>
      </div>
    </form>
    <?php
    exit;
}

/* ─── AJAX: แบบฟอร์มแก้ไขอะไหล่ ────────────────────────────────── */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'edit_part_form') {
    header('Content-Type: text/html; charset=utf-8');
    require_can('parts');
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    $r  = $id ? qr("SELECT * FROM parts WHERE id=?", 'i', [$id])->fetch_assoc() : null;
    if (!$r) { echo '<p class="muted">ไม่พบข้อมูล</p>'; exit; }
    ?>
    <form method="post" enctype="multipart/form-data" action="<?= BASE_URL ?>/parts.php">
      <?= csrf_field() ?>
      <input type="hidden" name="edit_part" value="1">
      <input type="hidden" name="part_id"   value="<?= (int)$r['id'] ?>">
      <div class="formgrid form-narrow">
        <label>ชื่ออะไหล่</label><input type="text" name="name"      value="<?= h($r['name']) ?>" required>
        <label>รหัส</label>      <input type="text" name="part_code" value="<?= h($r['part_code'] ?? '') ?>">
        <label>หมวด</label>      <input type="text" name="category"  value="<?= h($r['category'] ?? '') ?>">
        <label>หน่วย</label>     <input type="text" name="unit"      value="<?= h($r['unit'] ?? '') ?>" placeholder="ชิ้น, เมตร, ม้วน">
        <label>ร้านค้า</label>   <input type="text" name="dealer"    value="<?= h($r['dealer'] ?? '') ?>">
        <label>ลิงก์</label>    <input type="url"  name="link"      value="<?= h($r['link'] ?? '') ?>" placeholder="https://...">
        <label>รูป</label>
        <div>
          <?php if ($r['icon_path']) { echo img_tag($r['icon_path'], $r['name']); } ?>
          <input type="file" name="icon" accept="image/*" style="margin-top:6px;display:block">
          <span class="muted" style="font-size:11px">ไม่ต้องเลือกถ้าไม่ต้องการเปลี่ยนรูป</span>
        </div>
        <div class="full" style="margin-top:10px">
          <button type="submit" class="btn">บันทึกการแก้ไข</button>
        </div>
      </div>
    </form>
    <?php
    exit;
}

/* ─── POST: เบิกอะไหล่ (out เท่านั้น) ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_part'])) {
    csrf_check(); require_can('parts');
    $pid  = (int)$_POST['part_id'];
    $qty  = max(0.01, (float)$_POST['qty']);
    $refId = null;
    $code = trim(isset($_POST['asset_code']) ? $_POST['asset_code'] : '');
    if ($code !== '') {
        $a = qr("SELECT id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
        if ($a) $refId = (int)$a['id'];
    }
    $remark = trim(isset($_POST['remark']) ? $_POST['remark'] : '') ?: null;
    q("INSERT INTO part_movements (part_id,moved_at,direction,qty,mode,ref_asset_id,made_by,remark)
       VALUES (?,NOW(),'out',?,'เบิกใช้',?,?,?)", 'idiss',
      [$pid, $qty, $refId, actor_name(), $remark]);
    q("UPDATE parts SET stock_qty = stock_qty - ? WHERE id=?", 'di', [$qty, $pid]);

    flash_set('บันทึกการเบิกอะไหล่แล้ว');
    header('Location: ' . BASE_URL . '/parts.php'); exit;
}

/* ─── POST: แก้ไขรายการเบิก ─────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_movement'])) {
    csrf_check(); require_can('parts');
    $mid = (int)$_POST['movement_id'];
    $old = qr("SELECT part_id, qty, ref_asset_id FROM part_movements WHERE id=? AND direction='out'", 'i', [$mid])->fetch_assoc();
    if (!$old) { flash_set('ไม่พบรายการที่จะแก้ไข', 'err'); header('Location: ' . BASE_URL . '/parts.php'); exit; }
    $qty = max(0.01, (float)$_POST['qty']);
    $refId = null;
    $code = trim(isset($_POST['asset_code']) ? $_POST['asset_code'] : '');
    if ($code !== '') {
        $a = qr("SELECT id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [$code, $code])->fetch_assoc();
        if ($a) $refId = (int)$a['id'];
    }
    $remark = trim(isset($_POST['remark']) ? $_POST['remark'] : '') ?: null;
    $diff = $qty - (float)$old['qty'];
    if ($diff != 0) {
        q("UPDATE parts SET stock_qty = stock_qty - ? WHERE id=?", 'di', [$diff, (int)$old['part_id']]);
    }
    q("UPDATE part_movements SET qty=?, ref_asset_id=?, remark=? WHERE id=?", 'dissi', [$qty, $refId, $remark, $mid]);
    flash_set('แก้ไขรายการเบิกอะไหล่แล้ว');
    header('Location: ' . BASE_URL . '/parts.php'); exit;
}

/* ─── POST: ลบรายการเบิก ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_movement'])) {
    csrf_check(); require_can('parts');
    $mid = (int)$_POST['movement_id'];
    $old = qr("SELECT part_id, qty FROM part_movements WHERE id=? AND direction='out'", 'i', [$mid])->fetch_assoc();
    if ($old) {
        q("UPDATE parts SET stock_qty = stock_qty + ? WHERE id=?", 'di', [(float)$old['qty'], (int)$old['part_id']]);
        q("DELETE FROM part_movements WHERE id=?", 'i', [$mid]);
        flash_set('ลบรายการเบิกอะไหล่แล้ว');
    }
    header('Location: ' . BASE_URL . '/parts.php'); exit;
}

/* ─── POST: เพิ่มอะไหล่ใหม่ ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_part'])) {
    csrf_check(); require_can('parts');
    $icon     = save_upload('icon', 'parts');
    $stockQty = (isset($_POST['stock_qty']) && $_POST['stock_qty'] !== '') ? (float)$_POST['stock_qty'] : 0;
    $stockMin = (isset($_POST['stock_min']) && $_POST['stock_min'] !== '') ? (float)$_POST['stock_min'] : null;
    q("INSERT INTO parts (part_code,name,category,unit,stock_qty,stock_min,dealer,link,icon_path)
       VALUES (?,?,?,?,?,?,?,?,?)", 'ssssddsss',
      [trim($_POST['part_code']) ?: null, trim($_POST['name']),
       trim($_POST['category']) ?: null, trim($_POST['unit']) ?: null,
       $stockQty, $stockMin,
       trim($_POST['dealer']) ?: null, trim(isset($_POST['link']) ? $_POST['link'] : '') ?: null, $icon]);
    flash_set('เพิ่มอะไหล่ใหม่แล้ว');
    header('Location: ' . BASE_URL . '/parts.php'); exit;
}

/* ─── POST: แก้ไขข้อมูลอะไหล่ ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_part'])) {
    csrf_check(); require_can('parts');
    $id   = (int)$_POST['part_id'];
    $icon = save_upload('icon', 'parts');
    $params = [
        trim($_POST['name']),
        trim($_POST['part_code']) ?: null,
        trim($_POST['category']) ?: null,
        trim($_POST['unit']) ?: null,
        trim($_POST['dealer']) ?: null,
        trim(isset($_POST['link']) ? $_POST['link'] : '') ?: null,
    ];
    $types = 'ssssss';
    if ($icon !== null) { $params[] = $icon; $types .= 's'; $iconSql = ', icon_path=?'; }
    else { $iconSql = ''; }
    $params[] = $id; $types .= 'i';
    q("UPDATE parts SET name=?,part_code=?,category=?,unit=?,dealer=?,link=?$iconSql WHERE id=?", $types, $params);
    flash_set('แก้ไขข้อมูลอะไหล่แล้ว');
    header('Location: ' . BASE_URL . '/parts.php'); exit;
}

/* ─── หน้าหลัก: ดึงข้อมูลความเคลื่อนไหว ────────────────────────── */
$rs   = trim(isset($_GET['rs']) ? $_GET['rs'] : '');   // asset_code
$rm   = trim(isset($_GET['rm']) ? $_GET['rm'] : '');   // model
$rd   = trim(isset($_GET['rd']) ? $_GET['rd'] : '');   // date YYYY-MM-DD
$rb   = trim(isset($_GET['rb']) ? $_GET['rb'] : '');   // made_by
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

$sortMap = [
    'date_desc' => 'pm.moved_at DESC, pm.id DESC',
    'date_asc'  => 'pm.moved_at ASC,  pm.id ASC',
    'asset'     => 'a.asset_code ASC,  pm.moved_at DESC',
    'part'      => 'pt.name ASC,       pm.moved_at DESC',
];
$orderBy = isset($sortMap[$sort]) ? $sortMap[$sort] : $sortMap['date_desc'];

$wClauses = ["pm.direction='out'"]; $rTypes = ''; $rParams = [];
if ($rs !== '') { $wClauses[] = 'a.asset_code LIKE ?'; $rTypes .= 's'; $rParams[] = "%$rs%"; }
if ($rm !== '') { $wClauses[] = 'pr.name LIKE ?';      $rTypes .= 's'; $rParams[] = "%$rm%"; }
if ($rd !== '') { $wClauses[] = 'DATE(pm.moved_at)=?'; $rTypes .= 's'; $rParams[] = $rd; }
if ($rb !== '') { $wClauses[] = 'pm.made_by LIKE ?';   $rTypes .= 's'; $rParams[] = "%$rb%"; }
$where = 'WHERE ' . implode(' AND ', $wClauses);

$recent = qr("SELECT pm.id, pm.moved_at, pm.qty, pm.made_by, pm.remark,
                     pt.id part_id, pt.name pname, pt.unit,
                     a.id asset_id, a.asset_code,
                     pr.name product_name
              FROM   part_movements pm
              JOIN   parts pt     ON pt.id = pm.part_id
              LEFT JOIN assets a  ON a.id  = pm.ref_asset_id
              LEFT JOIN products pr ON pr.id = a.product_id
              $where ORDER BY $orderBy LIMIT 200", $rTypes, $rParams);

$groups = [];
$groupOrder = [];
while ($m = $recent->fetch_assoc()) {
    $key = ($m['asset_id'] !== null) ? 'a' . $m['asset_id'] : 'x' . $m['id'];
    if (!isset($groups[$key])) {
        $groups[$key] = [];
        $groupOrder[] = $key;
    }
    $groups[$key][] = $m;
}

page_header('อะไหล่');
require __DIR__ . '/includes/list_search.php';
?>

<!-- ─── ปุ่มหลัก ──────────────────────────────────────────────── -->
<div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center">
  <button class="btn" onclick="showListModal('รายการอะไหล่','<?= h(BASE_URL) ?>/parts.php?ajax=parts_list','')">
    📦 ดูรายการอะไหล่
  </button>
  <?php if (can('parts')) { ?>
  <button class="btn btn-line" onclick="showListModal('เพิ่มรายการเบิกอะไหล่','<?= h(BASE_URL) ?>/parts.php?ajax=add_move_form','')">
    ➕ เพิ่มรายการเบิก
  </button>
  <button class="btn btn-line" onclick="var p=document.getElementById('panel-add-part');p.style.display=p.style.display==='none'?'block':'none'">
    ➕ เพิ่มอะไหล่ใหม่
  </button>
  <?php } ?>
</div>

<!-- ─── แผงเพิ่มอะไหล่ใหม่ (ซ่อนโดยค่าเริ่มต้น) ─────────────── -->
<?php if (can('parts')) { ?>
<div id="panel-add-part" class="panel compact-panel" style="display:none; margin-bottom:20px">
  <b style="display:block;margin-bottom:12px;font-size:14px">เพิ่มอะไหล่ใหม่</b>
  <form method="post" class="formgrid form-narrow" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="new_part" value="1">
    <label>ชื่ออะไหล่</label><input type="text" name="name" required>
    <label>รหัส</label>      <input type="text" name="part_code">
    <label>หมวด</label>      <input type="text" name="category">
    <label>หน่วย</label>     <input type="text" name="unit" placeholder="ชิ้น, เมตร, ม้วน">
    <label>ร้านค้า</label>   <input type="text" name="dealer">
    <label>ลิงก์</label>    <input type="url"  name="link" placeholder="https://...">
    <label>รูปอะไหล่</label><input type="file" name="icon" accept="image/*">
    <div class="full"><button type="submit">➕ เพิ่มอะไหล่</button></div>
  </form>
</div>
<?php } ?>

<!-- ─── ความเคลื่อนไหวล่าสุด ────────────────────────────────── -->
<h2>ความเคลื่อนไหวล่าสุด</h2>
<?php
list_search_form([
    ['name' => 'rs', 'placeholder' => 'หมายเลขสินค้า', 'value' => $rs, 'width' => '140px'],
    ['name' => 'rm', 'placeholder' => 'รุ่น', 'value' => $rm, 'width' => '110px'],
    ['name' => 'rd', 'placeholder' => 'วันที่', 'value' => $rd, 'width' => '135px', 'type' => 'date'],
    ['name' => 'rb', 'placeholder' => 'ผู้เบิก', 'value' => $rb, 'width' => '110px'],
], BASE_URL . '/parts.php');
?>
<form class="filter" method="get" style="flex-wrap:wrap;gap:6px;margin-bottom:12px;margin-top:-6px">
  <input type="hidden" name="rs" value="<?= h($rs) ?>">
  <input type="hidden" name="rm" value="<?= h($rm) ?>">
  <input type="hidden" name="rd" value="<?= h($rd) ?>">
  <input type="hidden" name="rb" value="<?= h($rb) ?>">
  <select name="sort" style="height:34px" onchange="this.form.submit()">
    <?php foreach (['date_desc' => 'วันที่ล่าสุด', 'date_asc' => 'วันที่เก่าสุด', 'asset' => 'หมายเลขสินค้า', 'part' => 'ชื่ออะไหล่'] as $v => $l) {
        echo '<option value="' . h($v) . '"' . ($sort === $v ? ' selected' : '') . '>' . h($l) . '</option>';
    } ?>
  </select>
</form>

<table class="list">
  <tr>
    <th>วันที่</th>
    <th>อะไหล่</th>
    <th style="text-align:right">จำนวน</th>
    <th>รุ่น</th>
    <th>หมายเลขสินค้า</th>
    <th>โดย</th>
    <?php if (can('parts')) { ?><th style="width:120px">จัดการ</th><?php } ?>
  </tr>
  <?php
  if (empty($groupOrder)) { ?>
  <tr><td colspan="<?= can('parts') ? 7 : 6 ?>" class="muted" style="text-align:center;padding:16px">ไม่มีข้อมูล</td></tr>
  <?php }
  foreach ($groupOrder as $key) {
      $items = $groups[$key];
      $first = $items[0];
      $extra = count($items) - 1;
      $qty = rtrim(rtrim(number_format((float)$first['qty'], 2), '0'), '.');
      $assetCell = $first['asset_code']
          ? '<a href="' . BASE_URL . '/asset.php?id=' . $first['asset_id'] . '"><b>' . h($first['asset_code']) . '</b></a>'
          : '<span class="muted">ไม่ระบุ</span>';
      ?>
  <tr>
    <td style="white-space:nowrap"><?= dthai_full($first['moved_at']) ?></td>
    <td>
      <?= h($first['pname']) ?>
      <?php if ($extra > 0) { ?>
        <br><span class="muted" style="font-size:11px">และอีก <?= $extra ?> รายการ</span>
      <?php } ?>
      <?php if ($first['remark']) { ?><br><span class="muted"><?= h($first['remark']) ?></span><?php } ?>
    </td>
    <td style="text-align:right"><?= $qty ?><?= $first['unit'] ? ' <span class="muted">' . h($first['unit']) . '</span>' : '' ?></td>
    <td><?= h($first['product_name'] ?: '-') ?></td>
    <td>
      <?= $assetCell ?>
      <?php if ($extra > 0) { ?>
        <br><button type="button" class="btn btn-sm btn-line" style="margin-top:3px;font-size:11px"
                    onclick="var r=document.getElementById('exp-<?= h($key) ?>');var b=this;if(r.style.display==='none'){r.style.display='';b.textContent='▲ ซ่อน'}else{r.style.display='none';b.textContent='▼ ดูเพิ่ม <?= $extra ?> รายการ'}">▼ ดูเพิ่ม <?= $extra ?> รายการ</button>
      <?php } ?>
    </td>
    <td><?= h($first['made_by'] ?: '-') ?></td>
    <?php if (can('parts')) { ?>
    <td style="white-space:nowrap">
      <button class="btn btn-sm btn-line" onclick="showListModal(<?= h(json_encode('แก้ไข: ' . $first['pname'], JSON_UNESCAPED_UNICODE)) ?>,<?= h(json_encode(BASE_URL . '/parts.php?ajax=edit_move_form&id=' . $first['id'])) ?>,'')">แก้ไข</button>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบรายการเบิกนี้?')">
        <?= csrf_field() ?><input type="hidden" name="del_movement" value="1"><input type="hidden" name="movement_id" value="<?= (int)$first['id'] ?>">
        <button class="btn-sm btn-danger" type="submit">ลบ</button>
      </form>
    </td>
    <?php } ?>
  </tr>
  <?php if ($extra > 0) { ?>
  <tr id="exp-<?= h($key) ?>" style="display:none">
    <td colspan="<?= can('parts') ? 7 : 6 ?>" style="padding:0 0 6px 24px;background:var(--hover,#f7f9fc)">
      <table class="list" style="margin:0">
        <tr><th>วันที่</th><th>อะไหล่</th><th style="text-align:right">จำนวน</th><th>โดย</th><th>หมายเหตุ</th><?= can('parts') ? '<th></th>' : '' ?></tr>
        <?php foreach (array_slice($items, 1) as $sub) {
            $sq = rtrim(rtrim(number_format((float)$sub['qty'], 2), '0'), '.'); ?>
        <tr>
          <td style="white-space:nowrap"><?= dthai_full($sub['moved_at']) ?></td>
          <td><?= h($sub['pname']) ?></td>
          <td style="text-align:right"><?= $sq ?><?= $sub['unit'] ? ' <span class="muted">' . h($sub['unit']) . '</span>' : '' ?></td>
          <td><?= h($sub['made_by'] ?: '-') ?></td>
          <td><?= h($sub['remark'] ?: '') ?></td>
          <?php if (can('parts')) { ?>
          <td style="white-space:nowrap">
            <button class="btn btn-sm btn-line" onclick="showListModal(<?= h(json_encode('แก้ไข: ' . $sub['pname'], JSON_UNESCAPED_UNICODE)) ?>,<?= h(json_encode(BASE_URL . '/parts.php?ajax=edit_move_form&id=' . $sub['id'])) ?>,'')">แก้ไข</button>
            <form method="post" style="display:inline" onsubmit="return confirm('ลบรายการเบิกนี้?')">
              <?= csrf_field() ?><input type="hidden" name="del_movement" value="1"><input type="hidden" name="movement_id" value="<?= (int)$sub['id'] ?>">
              <button class="btn-sm btn-danger" type="submit">ลบ</button>
            </form>
          </td>
          <?php } ?>
        </tr>
        <?php } ?>
      </table>
    </td>
  </tr>
  <?php }
  } ?>
</table>

<!-- ─── Modal เบิกอะไหล่ ─────────────────────────────────────────── -->
<div id="withdraw-overlay" class="notif-overlay" hidden>
  <div class="notif-box" style="width:min(400px,92vw); padding:18px 20px">
    <b id="withdraw-modal-title" style="display:block;margin-bottom:12px;font-size:15px">เบิกใช้: —</b>
    <form method="post" action="<?= BASE_URL ?>/parts.php">
      <?= csrf_field() ?>
      <input type="hidden" name="move_part" value="1">
      <input type="hidden" name="part_id"   id="withdraw-pid" value="">
      <div class="formgrid form-narrow" style="border:0; padding:0; background:transparent; box-shadow:none; min-width:0">
        <label>จำนวน</label>
        <div class="qty-stepper">
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="-0.5">−</button>
          <input type="number" name="qty" id="withdraw-qty" value="1" step="0.5" min="0.5">
          <button type="button" class="qty-btn" onclick="qtyStepClick(this)" data-delta="0.5">+</button>
        </div>
        <label>หมายเลขสินค้า</label><input type="text" name="asset_code" class="asset-search" placeholder="พิมพ์แล้วเลือก">
        <label>หมายเหตุ</label><input type="text" name="remark" placeholder="ถ้ามี">
        <div class="full" style="display:flex;gap:8px;margin-top:6px">
          <button type="submit" class="btn">บันทึก</button>
          <button type="button" class="btn btn-line" onclick="closeWithdrawModal()">ยกเลิก</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function openWithdraw(pid, pname) {
    document.getElementById('withdraw-pid').value = pid;
    document.getElementById('withdraw-modal-title').textContent = 'เบิกใช้: ' + pname;
    document.getElementById('withdraw-qty').value = 1;
    document.getElementById('withdraw-overlay').hidden = false;
}
function closeWithdrawModal() {
    document.getElementById('withdraw-overlay').hidden = true;
}
document.getElementById('withdraw-overlay').addEventListener('click', function(e){
  if (e.target === this) closeWithdrawModal();
});
</script>
<?php page_footer();
