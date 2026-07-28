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
<div class="asset-head" style="align-items:center; margin-bottom:14px">
  <?= img_tag($product['icon_path'], $product['name'], 'thumb-lg') ?>
  <div class="info">
    <h2 style="margin:0"><?= h($product['name']) ?></h2>
    <div class="muted"><?= h($product['product_code']) ?> · <?= number_format($total) ?> รายการอัปเดต</div>
    <div style="margin-top:8px">
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/updates.php">← เลือกรุ่นอื่น</a>
    </div>
  </div>
</div>

<form class="filter" method="get" action="<?= BASE_URL ?>/asset.php" style="margin-bottom:10px">
  <input type="text" name="code" placeholder="รหัสเครื่องที่จะบันทึกอัปเดต" required style="width:260px">
  <button type="submit">เปิดหน้าเครื่องเพื่อบันทึก</button>
</form>

<form class="filter" method="get" style="margin-bottom:14px">
  <input type="hidden" name="product" value="<?= (int)$productId ?>">
  <input type="text" name="sn" value="<?= h($searchSn) ?>" placeholder="ค้นหาหมายเลขเครื่อง" style="width:200px">
  <input type="text" name="d" value="<?= h($searchDetail) ?>" placeholder="ค้นหารายละเอียดการอัปเดต" style="min-width:240px">
  <button type="submit">🔍 ค้นหา</button>
  <?php if ($searchSn !== '' || $searchDetail !== '') { ?>
    <a class="btn btn-line" href="<?= BASE_URL ?>/updates.php?product=<?= (int)$productId ?>">ล้าง</a>
  <?php } ?>
</form>

<?php if ($searchSn !== '' || $searchDetail !== '') { ?>
  <p class="muted" style="margin-bottom:10px">พบ <b><?= number_format($total) ?></b> รายการจากการค้นหา</p>
<?php } ?>

<?php if ($total === 0) { ?>
  <p class="muted"><?= ($searchSn !== '' || $searchDetail !== '') ? 'ไม่พบรายการตามเงื่อนไขค้นหา' : 'ยังไม่มีรายการอัปเดตของรุ่นนี้' ?></p>
<?php } else { ?>
<div class="table-wrap">
<table class="list">
  <tr><th>วันเวลา</th><th>เครื่อง</th><th>ประเภท</th><th>รายละเอียด</th><th>รูป</th><th>โดย</th><th style="width:130px">จัดการ</th></tr>
  <?php while ($r = $rows->fetch_assoc()) { ?>
  <tr>
    <td><?= dthai_full($r['updated_at']) ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$r['asset_id'] ?>"><?= h($r['asset_code']) ?></a></td>
    <td><?= isset($typeMap[$r['update_type']]) ? $typeMap[$r['update_type']] : h($r['update_type']) ?></td>
    <td style="max-width:340px">
      <?= $r['component_name'] ? h($r['component_name']) . ': ' : '' ?>
      <?= ($r['old_value'] || $r['new_value']) ? h($r['old_value'] ?: '?') . ' → ' . h($r['new_value'] ?: '?') . '<br>' : '' ?>
      <?= h(mb_strimwidth((string)$r['detail'], 0, 120, '…')) ?>
    </td>
    <td>
      <?php foreach (['image1', 'image2'] as $f) if (!empty($r[$f])) { ?>
        <a href="<?= h(img_url($r[$f])) ?>" target="_blank" rel="noopener"><img src="<?= h(img_url($r[$f])) ?>" class="thumb" loading="lazy" onerror="this.remove()"></a>
      <?php } ?>
    </td>
    <td><?= h($r['made_by'] ?: '-') ?></td>
    <td style="white-space:nowrap">
      <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/update_edit.php?id=<?= (int)$r['id'] ?>&back=<?= urlencode($listUrl) ?>">แก้ไข</a>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบรายการอัปเดตนี้?')">
        <?= csrf_field() ?>
        <input type="hidden" name="del_update" value="1">
        <input type="hidden" name="update_id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="back" value="<?= h($listUrl) ?>">
        <button class="btn-sm btn-danger" type="submit">ลบ</button>
      </form>
    </td>
  </tr>
  <?php } ?>
</table>
</div>
<?php
if ($pages > 1) { ?>
<div class="pager">
  <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
      $url = '?' . http_build_query(array_filter([
          'product' => $productId,
          'sn' => $searchSn !== '' ? $searchSn : null,
          'd' => $searchDetail !== '' ? $searchDetail : null,
          'page' => $i > 1 ? $i : null,
      ]));
      echo $i === $page ? "<span class='cur'>$i</span>" : "<a href='" . h($url) . "'>$i</a>";
  } ?>
  <span class="muted" style="border:0;background:none"><?= number_format($total) ?> รายการ</span>
</div>
<?php }
}
page_footer();
