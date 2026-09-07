<?php
/**
 * updates.php — บันทึกอัปเดต FW/HW
 *
 * ขั้นที่ 1: เลือกรุ่นสินค้า (การ์ดรูป)
 * ขั้นที่ 2: ดูรายการอัปเดตของรุ่นนั้น · ค้นหาตามหมายเลขเครื่อง/รายละเอียด · แก้ไข/ลบรายการ
 */
require __DIR__ . '/config.php';
require_login();

// ─ POST: ลบรายการอัปเดต ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_update'])) {
    csrf_check();
    $uid = (int)$_POST['update_id'];
    $rec = qr("SELECT u.id, u.asset_id, a.product_id, a.asset_code
               FROM update_logs u JOIN assets a ON a.id=u.asset_id WHERE u.id=?", 'i', [$uid])->fetch_assoc();
    if (!$rec) {
        flash_set('ไม่พบรายการที่จะลบ', 'err');
    } else {
        q("DELETE FROM update_logs WHERE id=?", 'i', [$uid]);
        recompute_asset_fw((int) $rec['asset_id']);
        flash_set('ลบรายการอัปเดตของ ' . $rec['asset_code'] . ' แล้ว');
    }
    $back = trim($_POST['back'] ?? '');
    if ($back !== '' && strpos($back, BASE_URL) !== 0) {
        $back = '';
    }
    header('Location: ' . ($back !== '' ? $back : BASE_URL . '/updates.php?product=' . (int)($rec['product_id'] ?? 0)));
    exit;
}

require __DIR__ . '/includes/layout.php';

$productId = (int)(isset($_GET['product']) ? $_GET['product'] : 0);
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$per = 50;
$off = ($page - 1) * $per;
$typeMap = ['firmware' => '📲 FW', 'hardware' => '🔩 HW', 'other' => '⚙️ อื่นๆ'];

// ─ ขั้นที่ 1: เลือกรุ่นสินค้า ─────────────────────────────────────────────────
if ($productId <= 0) {
    $products = qr("SELECT p.id, p.name, p.icon_path, p.product_code,
                           (SELECT COUNT(*) FROM assets a WHERE a.product_id=p.id) assets_n,
                           (SELECT COUNT(*) FROM update_logs u
                            JOIN assets a2 ON a2.id=u.asset_id
                            WHERE a2.product_id=p.id) updates_n
                    FROM products p
                    WHERE p.is_active=1
                    ORDER BY updates_n DESC, p.name ASC");

    page_header('อัปเดต FW/HW — เลือกรุ่นสินค้า');
    ?>
    <p class="muted" style="margin-bottom:12px">เลือกรุ่นสินค้าเพื่อดูประวัติอัปเดต FW/HW ของรุ่นนั้น</p>
    <div style="margin-bottom:12px">
      <input type="text" id="prod-filter" placeholder="พิมพ์กรองชื่อรุ่น…" style="width:min(320px,100%)">
    </div>
    <div class="grid-products" id="prod-grid">
      <?php while ($p = $products->fetch_assoc()) { ?>
        <a class="pcard" href="<?= BASE_URL ?>/updates.php?product=<?= (int)$p['id'] ?>"
           data-name="<?= h(mb_strtolower($p['name'] . ' ' . $p['product_code'])) ?>"
           style="text-decoration:none; color:inherit">
          <?= img_tag($p['icon_path'], $p['name'], 'thumb-lg') ?>
          <div class="pname"><?= h($p['name']) ?></div>
          <div class="pmeta">
            <?= h($p['product_code']) ?><br>
            <?= number_format((int)$p['assets_n']) ?> เครื่อง ·
            <b><?= number_format((int)$p['updates_n']) ?></b> รายการอัปเดต
          </div>
        </a>
      <?php } ?>
    </div>
    <script>
    document.getElementById('prod-filter').addEventListener('input', function(){
      var q = this.value.trim().toLowerCase();
      document.querySelectorAll('#prod-grid .pcard').forEach(function(el){
        el.style.display = (!q || (el.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
      });
    });
    </script>
    <?php
    page_footer();
    exit;
}

// ─ ขั้นที่ 2: รายการอัปเดตของรุ่นที่เลือก ─────────────────────────────────────
$product = qr("SELECT id, name, icon_path, product_code FROM products WHERE id=? AND is_active=1", 'i', [$productId])->fetch_assoc();
if (!$product) {
    flash_set('ไม่พบรุ่นสินค้า', 'err');
    header('Location: ' . BASE_URL . '/updates.php');
    exit;
}

$searchSn = trim($_GET['sn'] ?? '');
$searchDetail = trim($_GET['d'] ?? '');

$where = ['a.product_id=?'];
$types = 'i';
$params = [$productId];

if ($searchSn !== '') {
    $where[] = 'a.asset_code LIKE ?';
    $types .= 's';
    $params[] = '%' . $searchSn . '%';
}
if ($searchDetail !== '') {
    $where[] = '(u.detail LIKE ? OR u.component_name LIKE ? OR u.old_value LIKE ? OR u.new_value LIKE ?)';
    $types .= 'ssss';
    $like = '%' . $searchDetail . '%';
    array_push($params, $like, $like, $like, $like);
}
$w = 'WHERE ' . implode(' AND ', $where);

$total = (int)qr("SELECT COUNT(*) c FROM update_logs u
                  JOIN assets a ON a.id=u.asset_id
                  $w", $types, $params)->fetch_assoc()['c'];
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$off = ($page - 1) * $per;
$rows = qr("SELECT u.*, a.asset_code
            FROM update_logs u
            JOIN assets a ON a.id=u.asset_id
            $w
            ORDER BY u.updated_at DESC, u.id DESC
            LIMIT $per OFFSET $off", $types, $params);

$listQs = http_build_query(array_filter([
    'product' => $productId,
    'sn' => $searchSn !== '' ? $searchSn : null,
    'd' => $searchDetail !== '' ? $searchDetail : null,
    'page' => $page > 1 ? $page : null,
]));
$listUrl = BASE_URL . '/updates.php' . ($listQs ? '?' . $listQs : '');

page_header('อัปเดต FW/HW — ' . $product['name'] . ' (' . number_format($total) . ')');
?>
<?php // หัวหน้าแบบกระชับให้พอดีเนื้อหา (asset-head เดิมยืดเต็มจอเพราะ .info{flex:1}) ?>
<div class="asset-head asset-head-compact" style="align-items:center; margin-bottom:14px">
  <?= img_tag($product['icon_path'], $product['name'], 'thumb') ?>
  <div class="info">
    <h2 style="margin:0"><?= h($product['name']) ?></h2>
    <div class="muted">
      <?= h($product['product_code']) ?> · <?= number_format($total) ?> รายการอัปเดต
      · <a href="<?= BASE_URL ?>/updates.php">← เลือกรุ่นอื่น</a>
    </div>
  </div>
</div>

<?php // แถบเครื่องมือรูปแบบเดียวกับหน้า MA — ปุ่มหลักซ้ายสุด ตามด้วยช่องค้นหาแถวเดียว ?>
<div class="list-toolbar">
  <form method="get" action="<?= BASE_URL ?>/asset.php">
    <input type="text" name="code" placeholder="รหัสเครื่องที่จะบันทึกอัปเดต" required style="width:230px">
    <button type="submit" class="btn btn-primary btn-with-icon"><?= ui_btn_label('updates', 'บันทึกอัปเดตเครื่องนี้', 15) ?></button>
  </form>
</div>

<div class="list-toolbar">
  <form method="get">
    <input type="hidden" name="product" value="<?= (int)$productId ?>">
    <input type="text" name="sn" value="<?= h($searchSn) ?>" placeholder="รหัสเครื่อง" style="width:180px">
    <input type="text" name="d" value="<?= h($searchDetail) ?>" placeholder="รายละเอียดการอัปเดต" style="min-width:220px">
    <button type="submit" class="btn btn-with-icon"><?= ui_btn_label('search', 'ค้นหา', 15) ?></button>
    <?php if ($searchSn !== '' || $searchDetail !== '') { ?>
      <a class="btn btn-line" href="<?= BASE_URL ?>/updates.php?product=<?= (int)$productId ?>">ล้าง</a>
    <?php } ?>
  </form>
</div>

<?php if ($searchSn !== '' || $searchDetail !== '') { ?>
  <p class="muted" style="margin-bottom:10px">พบ <b><?= number_format($total) ?></b> รายการจากการค้นหา</p>
<?php } ?>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) ?>
  <thead>
  <tr><th data-pri="2">วันเวลา</th><th data-pri="1">เครื่อง</th><th data-pri="2">ประเภท</th><th data-pri="1">รายละเอียด</th><th data-pri="3">รูป</th><th data-pri="3">โดย</th><th data-pri="1" style="width:130px">จัดการ</th></tr>
  </thead>
  <tbody>
  <?php if ($total === 0) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:20px"><?= ($searchSn !== '' || $searchDetail !== '') ? 'ไม่พบรายการตามเงื่อนไขค้นหา' : 'ยังไม่มีรายการอัปเดตของรุ่นนี้' ?></td></tr>
  <?php } else { while ($r = $rows->fetch_assoc()) { ?>
  <tr>
    <td data-pri="2" data-nowrap><?= dthai_full($r['updated_at']) ?></td>
    <td data-pri="1"><a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$r['asset_id'] ?>"><?= h($r['asset_code']) ?></a>
      <?php // บรรทัดรอง — โผล่เมื่อคอลัมน์ระดับ 2 ถูกยุบที่จอแคบ ?>
      <span class="cell-sub"><?= dthai_full($r['updated_at']) ?></span></td>
    <td data-pri="2"><?= isset($typeMap[$r['update_type']]) ? $typeMap[$r['update_type']] : h($r['update_type']) ?></td>
    <?php
    // แยกเป็น 3 ชั้น: ชื่อชิ้นส่วน (ป้าย) · ค่าเดิม→ค่าใหม่ (ขีดฆ่าของเก่า เน้นของใหม่) · รายละเอียด (สีจาง)
    // เดิมทั้งสามส่วนต่อกันเป็นข้อความก้อนเดียว อ่านแล้วแยกไม่ออกว่าอะไรเป็นอะไร
    $uOld = trim((string) $r['old_value']);
    $uNew = trim((string) $r['new_value']);
    $uDetail = trim((string) $r['detail']);
    ?>
    <td data-pri="1" style="max-width:360px">
      <div class="upd-detail">
        <?php if (!empty($r['component_name'])) { ?>
          <span class="upd-detail-comp"><?= h($r['component_name']) ?></span>
        <?php } ?>
        <?php if ($uOld !== '' || $uNew !== '') { ?>
          <span class="upd-detail-change">
            <?php if ($uOld !== '' && $uOld === $uNew) { ?>
              <span class="upd-detail-to"><?= h($uNew) ?></span>
              <span class="upd-detail-same" title="ค่าเดิมกับค่าใหม่เหมือนกัน">· ไม่เปลี่ยนค่า</span>
            <?php } else { ?>
              <span class="upd-detail-from"><?= h($uOld !== '' ? $uOld : '—') ?></span>
              <span class="upd-detail-arrow">→</span>
              <span class="upd-detail-to"><?= h($uNew !== '' ? $uNew : '—') ?></span>
            <?php } ?>
          </span>
        <?php } ?>
        <?php if ($uDetail !== '') { ?>
          <span class="upd-detail-note"><?= h(mb_strimwidth($uDetail, 0, 120, '…')) ?></span>
        <?php } ?>
      </div>
    </td>
    <td>
      <?php foreach (['image1', 'image2'] as $f) if (!empty($r[$f])) { ?>
        <a href="<?= h(img_url($r[$f])) ?>" target="_blank" rel="noopener"><img src="<?= h(img_url($r[$f])) ?>" class="thumb" loading="lazy" onerror="this.remove()"></a>
      <?php } ?>
    </td>
    <td><?= h($r['made_by'] ?: '-') ?></td>
    <td class="row-actions">
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/update_edit.php?id=<?= (int)$r['id'] ?>&back=<?= urlencode($listUrl) ?>">แก้ไข</a>
      <form method="post" onsubmit="return confirm('ลบรายการอัปเดตนี้?')">
        <?= csrf_field() ?>
        <input type="hidden" name="del_update" value="1">
        <input type="hidden" name="update_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="back" value="<?= h($listUrl) ?>">
        <button class="btn-sm btn-danger" type="submit">ลบ</button>
      </form>
    </td>
  </tr>
  <?php } } ?>
  </tbody>
</table>
</div>
<?php
echo page_pager_html($page, $pages, $per, $total, function ($n) use ($productId, $searchSn, $searchDetail) {
    return '?' . http_build_query(array_filter([
        'product' => $productId,
        'sn' => $searchSn !== '' ? $searchSn : null,
        'd' => $searchDetail !== '' ? $searchDetail : null,
        'page' => $n > 1 ? $n : null,
    ]));
}, 'รายการ');
page_footer();
