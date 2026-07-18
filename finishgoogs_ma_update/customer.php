<?php
/** customer.php — หน้ารายลูกค้า: ข้อมูลลูกค้า + เครื่องที่อยู่ด้วย + ประวัติการซ่อม (รูปแบบ Repair Display เดิม) */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$c = qr("SELECT * FROM customers WHERE id=?", 'i', [$id])->fetch_assoc();
if (!$c) { http_response_code(404); exit('ไม่พบลูกค้ารายนี้'); }

// เครื่องที่อยู่กับลูกค้ารายนี้ตอนนี้
$assets = qr("SELECT a.id, a.asset_code, a.status, a.current_fw_version, p.name pname, p.icon_path
              FROM assets a JOIN products p ON p.id=a.product_id
              WHERE a.current_customer_id=? ORDER BY p.name, a.asset_code", 'i', [$id]);

// ประวัติซ่อมของลูกค้ารายนี้ (นับครั้งที่ซ่อมต่อเครื่องแบบทั้งระบบ)
$repairs = qr("SELECT * FROM (
                 SELECT r.id, r.customer_id, r.opened_at, r.reported_issue, r.assessment,
                        a.id asset_id, a.asset_code, p.name pname,
                        ROW_NUMBER() OVER (PARTITION BY r.asset_id ORDER BY r.opened_at, r.id) repair_no
                 FROM repairs r
                 JOIN assets a ON a.id=r.asset_id
                 JOIN products p ON p.id=a.product_id
               ) t WHERE t.customer_id=? ORDER BY t.opened_at DESC, t.id DESC", 'i', [$id]);
$nRepairs = qr("SELECT COUNT(*) c FROM repairs WHERE customer_id=?", 'i', [$id])->fetch_assoc()['c'];

page_header('ลูกค้า: ' . $c['name']);
?>
<div class="asset-head">
  <div class="info">
    <dl>
      <dt>ชื่อลูกค้า</dt><dd><b><?= h($c['name']) ?></b></dd>
      <?php if ($c['site_label']) { ?><dt>ไซต์/สาขา</dt><dd><?= h($c['site_label']) ?></dd><?php } ?>
      <dt>บริษัทผู้ดูแลสถานที่</dt><dd><?= h($c['security_company'] ?: '-') ?></dd>
      <dt>ผู้ติดต่อ</dt><dd><?= h(trim($c['contact_name'] . ' ' . $c['phone']) ?: '-') ?></dd>
      <?php if ($c['note']) { ?><dt>หมายเหตุ</dt><dd><?= h($c['note']) ?></dd><?php } ?>
    </dl>
  </div>
</div>

<h2>เครื่องที่อยู่กับลูกค้ารายนี้ (<?= $assets->num_rows ?>)</h2>
<table class="list" style="margin-bottom:22px">
  <tr><th></th><th>รหัสเครื่อง</th><th>รุ่น</th><th>สถานะ</th><th>FW</th></tr>
  <?php $na = 0; while ($a = $assets->fetch_assoc()) { $na++; ?>
  <tr>
    <td style="width:56px"><?= img_tag($a['icon_path'], $a['pname']) ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= $a['id'] ?>"><?= h($a['asset_code']) ?></a></td>
    <td><?= h($a['pname']) ?></td>
    <td><?= status_badge($a['status']) ?></td>
    <td><?= h($a['current_fw_version'] ?: '-') ?></td>
  </tr>
  <?php } if (!$na) { ?><tr><td colspan="5" class="muted" style="text-align:center; padding:14px">ไม่มีเครื่องที่ผูกกับลูกค้ารายนี้</td></tr><?php } ?>
</table>

<h2>ประวัติการซ่อม (<?= number_format($nRepairs) ?> รายการ)</h2>
<p class="muted" style="margin-bottom:8px">รูปแบบเดิมจากตาราง Repair Display · งานติดฟิล์มกันรอยหน้าจอ 2 ชั้น</p>
<table class="list">
  <tr>
    <th style="text-align:center">ครั้งที่</th><th>วันที่</th><th>รหัสเครื่อง</th><th>รุ่น</th>
    <th>ปัญหาแจ้งมา</th><th>การประเมิน</th>
  </tr>
  <?php $nr = 0; while ($r = $repairs->fetch_assoc()) { $nr++; ?>
  <tr>
    <td style="text-align:center"><span class="badge st-spare"><?= (int)$r['repair_no'] ?></span></td>
    <td><?= dthai($r['opened_at']) ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= $r['asset_id'] ?>"><?= h($r['asset_code']) ?></a></td>
    <td><?= h($r['pname']) ?></td>
    <td style="max-width:240px"><?= h($r['reported_issue'] ?: '-') ?></td>
    <td style="max-width:320px"><?= h($r['assessment'] ?: '-') ?></td>
  </tr>
  <?php } if (!$nr) { ?><tr><td colspan="6" class="muted" style="text-align:center; padding:14px">ยังไม่มีประวัติการซ่อม</td></tr><?php } ?>
</table>
<?php page_footer();
