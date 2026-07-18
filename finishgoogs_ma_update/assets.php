<?php
require __DIR__ . '/config.php';
require_login();

// AJAX: ค้นหาแบบพิมพ์ไปเจอไป (live search)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    $qq = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $productId = (int)(isset($_GET['product']) ? $_GET['product'] : 0);
    $out = [];
    if (mb_strlen($qq) >= 1) {
        $like = "%$qq%";
        $w = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)';
        $types = 'ss';
        $params = [$like, $like];
        if ($productId > 0) {
            $w .= ' AND a.product_id=?';
            $types .= 'i';
            $params[] = $productId;
        }
        $res = qr("SELECT a.id, a.asset_code, a.factory_serial, a.status, p.name pname
                   FROM assets a JOIN products p ON p.id=a.product_id
                   WHERE $w
                   ORDER BY (a.asset_code LIKE ?) DESC, a.asset_code LIMIT 15",
                  $types . 's', array_merge($params, ["$qq%"]));
        while ($r = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$r['id'], 'code' => $r['asset_code'],
                      'serial' => $r['factory_serial'], 'pname' => $r['pname'],
                      'status' => status_th($r['status'])];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/includes/layout.php';

$search  = trim(isset($_GET['q']) ? $_GET['q'] : '');
$status  = isset($_GET['status']) ? $_GET['status'] : '';
$product = isset($_GET['product']) ? $_GET['product'] : '';
$page    = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$per     = 50;
// ค่าเริ่มต้น: เวลาบันทึกล่าสุด → รหัสเครื่องมากสุด
$sort    = isset($_GET['sort']) ? $_GET['sort'] : 'time_code';
$sortSql = [
    // เวลาบันทึกจาก production_records (ไม่มีก็ใช้ produced_at) แล้วตามรหัสเครื่อง DESC
    'time_code' => 'COALESCE((SELECT MAX(pr.recorded_at) FROM production_records pr WHERE pr.asset_id=a.id), a.produced_at) DESC, a.asset_code DESC, a.id DESC',
    'date_desc' => '(a.produced_at IS NULL), a.produced_at DESC, a.asset_code DESC, a.id DESC',
    'date_asc'  => '(a.produced_at IS NULL), a.produced_at ASC, a.asset_code ASC, a.id ASC',
    'code'      => 'a.asset_code DESC, a.id DESC',
    'recent'    => 'a.id DESC, a.asset_code DESC',
];
if (!isset($sortSql[$sort])) $sort = 'time_code';

$where = []; $types = ''; $params = [];
if ($search !== '') {
    $like = "%$search%";
    $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ? OR p.name LIKE ? OR a.current_fw_version LIKE ?
                OR EXISTS (SELECT 1 FROM production_records pr WHERE pr.asset_id=a.id AND (pr.made_by LIKE ? OR pr.assembly_by LIKE ?)))';
    $types .= 'ssssss';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($status !== '' && in_array($status, status_list(), true)) {
    $where[] = 'a.status = ?'; $types .= 's'; $params[] = $status;
}
if ($product !== '') { $where[] = 'p.name = ?'; $types .= 's'; $params[] = $product; }
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = qr("SELECT COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id $w", $types, $params)->fetch_assoc()['c'];
$pages = max(1, (int)ceil($totalRows / $per));
$off = ($page - 1) * $per;

$rows = qr("SELECT a.id, a.asset_code, a.factory_serial, a.status, a.produced_at, a.current_fw_version,
                   p.name pname, p.icon_path, p.id product_id,
                   (SELECT pr.made_by FROM production_records pr
                    WHERE pr.asset_id=a.id AND pr.made_by IS NOT NULL AND pr.made_by<>''
                    ORDER BY pr.recorded_at ASC, pr.id ASC LIMIT 1) recorder,
                   (SELECT COUNT(*) FROM part_movements pm
                    WHERE pm.ref_asset_id=a.id AND pm.direction='out') parts_out_cnt,
                   (SELECT COUNT(*) FROM bom_items b WHERE b.product_id=a.product_id) bom_cnt,
                   (SELECT COUNT(DISTINCT pm.part_id) FROM part_movements pm
                    INNER JOIN bom_items b ON b.part_id=pm.part_id
                    WHERE pm.ref_asset_id=a.id AND pm.direction='out' AND b.product_id=a.product_id) bom_out_cnt
            FROM assets a JOIN products p ON p.id=a.product_id
            $w ORDER BY {$sortSql[$sort]} LIMIT $per OFFSET $off", $types, $params);

$productList = qr("SELECT DISTINCT p.name FROM products p JOIN assets a ON a.product_id=p.id ORDER BY p.name");

page_header('ทะเบียนเครื่องผลิตใหม่ (' . number_format($totalRows) . ')');
?>
<form class="filter" method="get">
  <span class="livesearch-wrap">
    <input type="text" name="q" id="live-q" value="<?= h($search) ?>" placeholder="ค้นหา หมายเลขสินค้า / รุ่น / ผู้ผลิต / FW" style="width:min(360px,100%)" autocomplete="off">
    <div id="live-results" class="combo-list" hidden></div>
  </span>
  <select name="product">
    <option value="">— ทุกรุ่น —</option>
    <?php while ($p = $productList->fetch_assoc()) { ?>
      <option value="<?= h($p['name']) ?>" <?= $product === $p['name'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
    <?php } ?>
  </select>
  <select name="status">
    <option value="">— ทุกสถานะ —</option>
    <?php foreach (status_list() as $s) { ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= h(status_th($s)) ?></option>
    <?php } ?>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <option value="time_code" <?= $sort === 'time_code' ? 'selected' : '' ?>>เวลาบันทึกล่าสุด+รหัสเครื่องมากสุด</option>
    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>วันที่ผลิต ใหม่ → เก่า</option>
    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>วันที่ผลิต เก่า → ใหม่</option>
    <option value="code" <?= $sort === 'code' ? 'selected' : '' ?>>เรียงตามรหัสเครื่อง</option>
    <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>เพิ่มเข้าระบบล่าสุด</option>
  </select>
  <button type="submit">ค้นหา</button>
  <a class="btn" href="<?= BASE_URL ?>/asset_new.php" style="margin-left:auto">➕ ลงทะเบียนเครื่องผลิตใหม่</a>
</form>
<script>
(function(){
  var input = document.getElementById('live-q');
  var box = document.getElementById('live-results');
  var timer = null;
  function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
  input.addEventListener('input', function(){
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 2) { box.hidden = true; return; }
    timer = setTimeout(function(){
      fetch('<?= BASE_URL ?>/assets.php?ajax=suggest&q=' + encodeURIComponent(q))
        .then(function(r){ return r.json(); })
        .then(function(items){
          if (!items.length) { box.innerHTML = '<div class="muted" style="padding:6px 10px">ไม่พบเครื่องที่ตรง</div>'; box.hidden = false; return; }
          box.innerHTML = items.map(function(it){
            return '<div onclick="location.href=\'<?= BASE_URL ?>/asset.php?id=' + it.id + '\'">'
                 + '<b>' + esc(it.code) + '</b> <span class="muted">' + esc(it.pname) + ' · ' + esc(it.status) + '</span></div>';
          }).join('');
          box.hidden = false;
        }).catch(function(){ box.hidden = true; });
    }, 350);
  });
  document.addEventListener('click', function(e){ if (!box.contains(e.target) && e.target !== input) box.hidden = true; });
})();
</script>

<table class="list">
  <tr><th></th><th>หมายเลขสินค้า</th><th>รุ่น</th><th>สถานะ</th><th>เบิกอะไหล่</th><th>ผู้บันทึกรายการ</th><th>ผลิตเมื่อ</th><th>FW</th></tr>
  <?php while ($r = $rows->fetch_assoc()) {
      $outCnt = (int)$r['parts_out_cnt'];
      $bomCnt = (int)$r['bom_cnt'];
      $bomOut = (int)$r['bom_out_cnt'];
      if ($outCnt === 0) {
          $pStatus = $bomCnt > 0 ? 'pending' : 'none';
          $pLabel = $bomCnt > 0 ? 'ยังไม่เบิก' : '—';
      } elseif ($bomCnt > 0 && $bomOut < $bomCnt) {
          $pStatus = 'partial';
          $pLabel = "เบิกแล้ว {$bomOut}/{$bomCnt}";
      } else {
          $pStatus = 'done';
          $pLabel = "เบิกแล้ว {$outCnt} รายการ";
      }
      $partsAppUrl = parts_app_base_url() . '/pages/history.php';
  ?>
  <tr>
    <td style="width:56px"><?= img_tag($r['icon_path'], $r['pname']) ?></td>
    <td><a href="<?= BASE_URL ?>/asset.php?id=<?= $r['id'] ?>"><b><?= h($r['asset_code']) ?></b></a></td>
    <td><?= h($r['pname']) ?></td>
    <td><?= status_badge($r['status']) ?></td>
    <td>
      <?php if ($pStatus === 'none') { ?>
        <span class="muted">—</span>
      <?php } else { ?>
        <a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$r['id'] ?>#parts-withdraw" class="parts-status parts-status-<?= h($pStatus) ?>" title="ดูรายละเอียดการเบิก"><?= h($pLabel) ?></a>
        <?php if ($outCnt > 0) { ?>
        <br><a href="<?= h($partsAppUrl) ?>" target="_blank" class="muted" style="font-size:11px" title="ดูใน Stock ช่าง">📦 Stock</a>
        <?php } ?>
      <?php } ?>
    </td>
    <td><?= h($r['recorder'] ?: '-') ?></td>
    <td><?= dthai($r['produced_at']) ?></td>
    <td><?= h($r['current_fw_version'] ?: '-') ?></td>
  </tr>
  <?php } ?>
</table>

<?php if ($pages > 1) {
    $qs = $_GET; ?>
<div class="pager">
  <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
      $qs['page'] = $i; $url = '?' . http_build_query($qs);
      echo $i === $page ? "<span class='cur'>$i</span>" : "<a href='" . h($url) . "'>$i</a>";
  } ?>
  <span class="muted" style="border:0;background:none"><?= number_format($totalRows) ?> รายการ</span>
</div>
<?php }
page_footer();
