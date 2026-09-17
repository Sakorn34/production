<?php
/**
 * stock_movements.php — ประวัติเข้า-ออกคลัง / เปลี่ยนสถานะเครื่อง (หน้าหลังบ้าน)
 *
 * เดิมแถวพวกนี้ขึ้นใน timeline ของหน้าเครื่อง ส่วนใหญ่เป็นงานที่ระบบทำเอง
 * (ซิงก์สถานะ · เคลียร์เครื่องค้าง · นับสต็อก · ย้อนกลับ) ปนกับงานจริงจนอ่าน timeline ไม่ออก
 * จึงย้ายมาไว้ที่นี่ที่เดียว ค้นตาม S/N / ประเภท / ช่วงวันที่ได้
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$B = BASE_URL;

/** ประเภทของแถว — ดูจากข้อความเหตุผลที่แต่ละเครื่องมือเขียนไว้ */
$groups = [
    ''       => ['label' => 'ทั้งหมด', 'where' => ''],
    'manual' => ['label' => 'เปลี่ยนสถานะเอง', 'where' => "m.reason LIKE 'เปลี่ยนสถานะเป็น%'"],
    'scan'   => ['label' => 'นับสต็อก (สแกน)', 'where' => "m.reason LIKE '%นับสต็อก (สแกน)%'"],
    'clear'  => ['label' => 'เคลียร์/ซิงก์เครื่องค้าง', 'where' => "(m.reason LIKE 'เคลียร์%' OR m.reason LIKE 'ย้อนกลับการเคลียร์%' OR m.reason LIKE 'ซิงก์สถานะทั้งระบบ%' OR m.reason LIKE 'เบิกออกจากคลังแล้วตามระบบ Setup%')"],
    'follow' => ['label' => 'ติดตามเครื่องไม่มีสถานะ', 'where' => "m.reason LIKE 'ติดตามเครื่องไม่มีสถานะ%'"],
    'sync'   => ['label' => 'Sync อัตโนมัติ', 'where' => "m.reason LIKE 'Sync สถานะ:%'"],
    'import' => ['label' => 'ผลิต / นำเข้าระบบเดิม', 'where' => "(m.reason LIKE 'ผลิตเสร็จเข้าคลัง%' OR m.reason LIKE '%นำเข้าจากระบบเดิม%')"],
];

$q = trim((string) ($_GET['q'] ?? ''));
$g = (string) ($_GET['g'] ?? '');
if (!isset($groups[$g])) { $g = ''; }
$dir = (string) ($_GET['dir'] ?? '');
if (!in_array($dir, ['', 'in', 'out'], true)) { $dir = ''; }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : '';

$where = ['1=1'];
$types = '';
$params = [];
if ($q !== '') {
    $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ? OR m.reason LIKE ? OR m.made_by LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}
if ($groups[$g]['where'] !== '') { $where[] = $groups[$g]['where']; }
if ($dir !== '') { $where[] = 'm.direction = ?'; $types .= 's'; $params[] = $dir; }
if ($from !== '') { $where[] = 'm.moved_at >= ?'; $types .= 's'; $params[] = $from . ' 00:00:00'; }
if ($to !== '') { $where[] = 'm.moved_at <= ?'; $types .= 's'; $params[] = $to . ' 23:59:59'; }
$w = implode(' AND ', $where);

$per = 50;
$totalRows = (int) qr(
    "SELECT COUNT(*) FROM stock_movements m LEFT JOIN assets a ON a.id = m.asset_id WHERE $w",
    $types,
    $params
)->fetch_row()[0];
$pages = max(1, (int) ceil($totalRows / $per));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$off = ($page - 1) * $per;

$rows = [];
$res = qr(
    "SELECT m.id, m.moved_at, m.direction, m.reason, m.made_by, m.remark,
            a.id AS asset_id, a.asset_code, a.status, p.name AS pname
     FROM stock_movements m
     LEFT JOIN assets a ON a.id = m.asset_id
     LEFT JOIN products p ON p.id = a.product_id
     WHERE $w
     ORDER BY m.moved_at DESC, m.id DESC
     LIMIT $per OFFSET $off",
    $types,
    $params
);
while ($r = $res->fetch_assoc()) { $rows[] = $r; }

page_header('ประวัติเข้า-ออกคลัง', true, 'การเปลี่ยนสถานะเครื่องทั้งหมด — ทั้งที่คนทำเองและที่ระบบทำ (ซิงก์ · เคลียร์ · นับสต็อก)', $B . '/settings.php');
?>
<form method="get" class="panel sm-filters">
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="ค้นหา S/N · เหตุผล · ผู้ทำ">
  <select name="g">
    <?php foreach ($groups as $k => $gr) { ?>
    <option value="<?= h($k) ?>"<?= $k === $g ? ' selected' : '' ?>><?= h($gr['label']) ?></option>
    <?php } ?>
  </select>
  <select name="dir">
    <option value="">เข้า + ออก</option>
    <option value="in"<?= $dir === 'in' ? ' selected' : '' ?>>เข้าคลัง</option>
    <option value="out"<?= $dir === 'out' ? ' selected' : '' ?>>ออกจากคลัง</option>
  </select>
  <label class="muted">ตั้งแต่ <input type="date" name="from" value="<?= h($from) ?>"></label>
  <label class="muted">ถึง <input type="date" name="to" value="<?= h($to) ?>"></label>
  <button type="submit" class="btn btn-primary btn-sm">ค้นหา</button>
  <?php if ($q !== '' || $g !== '' || $dir !== '' || $from !== '' || $to !== '') { ?>
  <a class="btn btn-line btn-sm" href="<?= h($B) ?>/stock_movements.php">ล้าง</a>
  <?php } ?>
</form>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <thead>
    <tr>
      <th data-pri="1">เวลา</th>
      <th data-pri="1">S/N</th>
      <th data-pri="3">รุ่น</th>
      <th data-pri="2">ทิศทาง</th>
      <th data-pri="1">รายละเอียด</th>
      <th data-pri="2">โดย</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows) { ?>
    <tr><td data-pri="1" colspan="6" class="muted">ไม่พบรายการตามตัวกรอง</td></tr>
  <?php } ?>
  <?php foreach ($rows as $r) { ?>
    <tr>
      <td data-pri="1" data-nowrap><?= h(dthai((string) $r['moved_at'])) ?></td>
      <td data-pri="1">
        <?php if ($r['asset_id']) { ?>
        <a href="<?= h($B . '/asset.php?id=' . (int) $r['asset_id']) ?>"><b><?= h((string) $r['asset_code']) ?></b></a>
        <?php } else { ?><span class="muted">(ลบเครื่องแล้ว)</span><?php } ?>
        <div class="cell-sub"><?= h((string) $r['pname']) ?> · <?= $r['direction'] === 'in' ? 'เข้าคลัง' : 'ออกจากคลัง' ?> · <?= h((string) $r['made_by']) ?></div>
      </td>
      <td data-pri="3"><?= h((string) $r['pname']) ?></td>
      <td data-pri="2" data-nowrap><?= $r['direction'] === 'in' ? 'เข้าคลัง' : 'ออกจากคลัง' ?></td>
      <td data-pri="1"><?= h((string) $r['reason']) ?><?= $r['remark'] ? '<div class="muted" style="font-size:12px">' . h((string) $r['remark']) . '</div>' : '' ?></td>
      <td data-pri="2"><?= h((string) $r['made_by']) ?></td>
    </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<style>
.sm-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
.sm-filters input[type="search"] { flex: 1 1 220px; min-width: 0; }
.sm-filters label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; }
</style>
<?php
echo page_pager_html($page, $pages, $per, $totalRows, function ($n) {
    $qs = $_GET;
    $qs['page'] = $n;
    return '?' . http_build_query($qs);
}, 'รายการ');
page_footer();
