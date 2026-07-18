<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_customer'])) {
    csrf_check();
    if (!(can('ma') || can('repair'))) exit('ไม่มีสิทธิ์');
    q("INSERT INTO customers (name,site_label,security_company,contact_name,phone,note) VALUES (?,?,?,?,?,?)", 'ssssss',
      [trim($_POST['name']), trim($_POST['site_label']) ?: null, trim($_POST['security_company']) ?: null,
       trim($_POST['contact_name']) ?: null, trim($_POST['phone']) ?: null, trim($_POST['note']) ?: null]);
    flash_set('เพิ่มลูกค้าแล้ว');
    header('Location: ' . BASE_URL . '/customers.php'); exit;
}

$search = trim(isset($_GET['q']) ? $_GET['q'] : '');
$w = ''; $types = ''; $params = [];
if ($search !== '') { $w = "WHERE c.name LIKE ?"; $types = 's'; $params = ["%$search%"]; }
$rows = qr("SELECT c.*,
              (SELECT COUNT(*) FROM assets a WHERE a.current_customer_id=c.id) n_assets,
              (SELECT COUNT(*) FROM repairs r WHERE r.customer_id=c.id) n_repairs,
              (SELECT COUNT(*) FROM deployments d WHERE d.customer_id=c.id AND d.status='active') n_deploy
            FROM customers c $w ORDER BY c.name LIMIT 300", $types, $params);

page_header('ลูกค้า');
?>
<form class="filter" method="get">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="ค้นหาชื่อลูกค้า" style="width:250px">
  <button type="submit">ค้นหา</button>
</form>
<table class="list">
  <tr><th>ลูกค้า</th><th>บริษัทผู้ดูแล</th><th>ผู้ติดต่อ</th><th style="text-align:right">เครื่องที่อยู่ด้วย</th><th style="text-align:right">ติดตั้ง/เช่าอยู่</th><th style="text-align:right">เคยซ่อม</th></tr>
  <?php while ($r = $rows->fetch_assoc()) { ?>
  <tr>
    <td><a href="<?= BASE_URL ?>/customer.php?id=<?= $r['id'] ?>"><b><?= h($r['name']) ?></b></a><?= $r['site_label'] ? ' <span class="muted">(' . h($r['site_label']) . ')</span>' : '' ?></td>
    <td><?= h($r['security_company'] ?: '-') ?></td>
    <td><?= h(trim($r['contact_name'] . ' ' . $r['phone']) ?: '-') ?></td>
    <td style="text-align:right"><?= $r['n_assets'] ?></td>
    <td style="text-align:right"><?= $r['n_deploy'] ?></td>
    <td style="text-align:right"><a href="<?= BASE_URL ?>/customer.php?id=<?= $r['id'] ?>"><?= $r['n_repairs'] ?></a></td>
  </tr>
  <?php } ?>
</table>

<?php if (can('ma') || can('repair')) { ?>
<h2>เพิ่มลูกค้าใหม่</h2>
<form method="post" class="formgrid form-narrow">
  <?= csrf_field() ?><input type="hidden" name="new_customer" value="1">
  <label>ชื่อลูกค้า</label><input type="text" name="name" required>
  <label>ไซต์/สาขา</label><input type="text" name="site_label">
  <label>บริษัทผู้ดูแลสถานที่</label><input type="text" name="security_company">
  <label>ผู้ติดต่อ</label><input type="text" name="contact_name">
  <label>โทรศัพท์</label><input type="text" name="phone">
  <label class="full">หมายเหตุ</label><textarea name="note" class="full field-note" rows="2"></textarea>
  <div class="full"><button type="submit">➕ เพิ่มลูกค้า</button></div>
</form>
<?php }
page_footer();
