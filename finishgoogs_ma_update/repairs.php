<?php
/**
 * repairs.php — ประวัติการซ่อม (รูปแบบเดิมจากตาราง Repair Display)
 * แสดงอย่างเดียว (read-only) · กรองตามลูกค้า/ค้นหาได้ · ทุกเคสคืองานติดฟิล์มกันรอยหน้าจอ 2 ชั้น
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$custId = (int)(isset($_GET['customer']) ? $_GET['customer'] : 0);
$search = trim(isset($_GET['q']) ? $_GET['q'] : '');

$conds = []; $types = ''; $params = [];
if ($custId)        { $conds[] = 't.customer_id = ?'; $types .= 'i'; $params[] = $custId; }
if ($search !== '') { $conds[] = '(t.asset_code LIKE ? OR t.cust LIKE ? OR t.reported_issue LIKE ? OR t.assessment LIKE ?)';
                      $types .= 'ssss'; $like = "%$search%"; array_push($params, $like, $like, $like, $like); }
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

// นับ "ครั้งที่ซ่อม" ต่อเครื่องแบบทั้งระบบ (ROW_NUMBER) แล้วค่อยกรอง
$rows = qr("SELECT * FROM (
              SELECT r.id, r.customer_id, r.opened_at, r.reported_issue, r.assessment,
                     a.id asset_id, a.asset_code, p.name pname,
                     c.name cust, c.site_label, c.security_company,
                     ROW_NUMBER() OVER (PARTITION BY r.asset_id ORDER BY r.opened_at, r.id) repair_no
              FROM repairs r
              JOIN assets a ON a.id = r.asset_id
              JOIN products p ON p.id = a.product_id
              LEFT JOIN customers c ON c.id = r.customer_id
            ) t $where
            ORDER BY t.opened_at DESC, t.id DESC LIMIT 500", $types, $params);

$total = qr("SELECT COUNT(*) c FROM repairs")->fetch_assoc()['c'];
$customers = qr("SELECT id, name, site_label FROM customers WHERE id IN (SELECT DISTINCT customer_id FROM repairs WHERE customer_id IS NOT NULL) ORDER BY name");

page_header('ประวัติการซ่อม');
?>
<p class="muted" style="margin-bottom:14px">
  ประวัติงานซ่อม (นำเข้าจากระบบเดิม — ตาราง Repair Display) · ทุกรายการเป็นงาน <b>ติดฟิล์มกันรอยหน้าจอ 2 ชั้น</b> · ทั้งหมด <?= number_format($total) ?> รายการ
</p>

<form class="filter" method="get">
  <select name="customer" onchange="this.form.submit()" style="min-width:240px">
    <option value="0">— ลูกค้าทั้งหมด —</option>
    <?php while ($c = $customers->fetch_assoc()) { ?>
      <option value="<?= $c['id'] ?>" <?= $custId === (int)$c['id'] ? 'selected' : '' ?>>
        <?= h($c['name'] . ($c['site_label'] ? ' (' . $c['site_label'] . ')' : '')) ?>
      </option>
    <?php } ?>
  </select>
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="ค้นหารหัสเครื่อง/ลูกค้า/อาการ" style="width:230px">
  <button type="submit">ค้นหา</button>
  <?php if ($custId || $search !== '') { ?><a class="btn btn-line" href="<?= BASE_URL ?>/repairs.php">ล้าง</a><?php } ?>
</form>

<div class="table-wrap">
<table class="list">
  <tr>
    <th style="text-align:center">ครั้งที่</th><th>วันที่</th><th>รหัสเครื่อง</th><th>รุ่น</th>
    <th>ลูกค้า</th><th>บริษัทผู้ดูแล</th><th>ปัญหาแจ้งมา</th><th>การประเมิน</th>
  </tr>
  <?php $n = 0; while ($r = $rows->fetch_assoc()) { $n++; ?>
  <tr>
    <td style="text-align:center"><span class="badge st-spare"><?= (int)$r['repair_no'] ?></span></td>
    <td><?= dthai_full($r['opened_at']) ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= $r['asset_id'] ?>"><?= h($r['asset_code']) ?></a></td>
    <td><?= h($r['pname']) ?></td>
    <td><?php if ($r['customer_id']) { echo h($r['cust'] . ($r['site_label'] ? ' (' . $r['site_label'] . ')' : '')); } else echo '-'; ?></td>
    <td><?= h($r['security_company'] ?: '-') ?></td>
    <td style="max-width:240px"><?= h($r['reported_issue'] ?: '-') ?></td>
    <td style="max-width:300px"><?= h($r['assessment'] ?: '-') ?></td>
  </tr>
  <?php } ?>
  <?php if (!$n) { ?><tr><td colspan="8" class="muted" style="text-align:center; padding:20px">ไม่พบประวัติการซ่อม</td></tr><?php } ?>
</table>
</div>
<?php page_footer();
