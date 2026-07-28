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

/**
 * สร้าง WHERE สำหรับ filter หน้า assets.php
 *
 * @param string $search
 * @param string $status
 * @param string $product
 * @return array{w:string,types:string,params:array<int|string>}
 */
function assets_page_build_where(string $search, string $status, string $product): array
{
    $where = [];
    $types = '';
    $params = [];
    if ($search !== '') {
        $like = "%{$search}%";
        $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ? OR p.name LIKE ? OR a.current_fw_version LIKE ?
                OR EXISTS (SELECT 1 FROM production_records pr WHERE pr.asset_id=a.id AND (pr.made_by LIKE ? OR pr.assembly_by LIKE ?)))';
        $types .= 'ssssss';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($status !== '' && in_array($status, status_list(), true)) {
        $where[] = 'a.status = ?';
        $types .= 's';
        $params[] = $status;
    }
    if ($product !== '') {
        $where[] = 'p.name = ?';
        $types .= 's';
        $params[] = $product;
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return ['w' => $w, 'types' => $types, 'params' => $params];
}

$filter = assets_page_build_where($search, $status, $product);
$w = $filter['w'];
$types = $filter['types'];
$params = $filter['params'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_withdraw_list_bulk'])) {
    csrf_check();
    if (array_key_exists('filter_product', $_POST)) {
        $search = trim((string) ($_POST['filter_q'] ?? ''));
        $status = trim((string) ($_POST['filter_status'] ?? ''));
        $product = trim((string) ($_POST['filter_product'] ?? ''));
        $sort = trim((string) ($_POST['filter_sort'] ?? 'time_code'));
        if (!isset($sortSql[$sort])) {
            $sort = 'time_code';
        }
        $filter = assets_page_build_where($search, $status, $product);
        $w = $filter['w'];
        $types = $filter['types'];
        $params = $filter['params'];
    }
    $syncIds = asset_ids_needing_withdraw_list_sync($w, $types, $params);
    if ($syncIds === []) {
        flash_set('ไม่มีเครื่องที่ Stock ไม่ตรงรายการเบิก (ตาม filter ปัจจุบัน)');
    } else {
        $sync = asset_reconcile_withdraw_list_bulk($syncIds, actor_name());
        asset_withdraw_list_sync_pending_cache_clear($w, $types, $params);
        if ($sync['ok']) {
            $msg = $sync['message'] ?? 'Sync รายการเบิกสำเร็จ';
            if (!empty($sync['errors'])) {
                $msg .= ' — มีรายการข้าม/ผิดพลาดบางส่วน';
            }
            flash_set($msg);
        } else {
            flash_set($sync['errors'][0] ?? ($sync['message'] ?? 'Sync รายการเบิกไม่สำเร็จ'), 'err');
        }
    }
    $redirectQs = array_filter([
        'q'       => $search,
        'product' => $product,
        'status'  => $status,
        'sort'    => $sort,
    ], static function ($v) {
        return $v !== '';
    });
    header('Location: ' . BASE_URL . '/assets.php?' . http_build_query($redirectQs));
    exit;
}

$withdrawSyncPendingCount = asset_withdraw_list_sync_pending_count_cached($w, $types, $params);

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
                   (SELECT COUNT(*) FROM part_movements pm
                    WHERE pm.ref_asset_id=a.id AND pm.direction='out'
                      AND pm.tech_stock_out_id IS NOT NULL AND pm.tech_stock_out_id > 0) parts_linked_cnt,
                   (SELECT COUNT(*) FROM bom_items b WHERE b.product_id=a.product_id) bom_cnt
            FROM assets a JOIN products p ON p.id=a.product_id
            $w ORDER BY {$sortSql[$sort]} LIMIT $per OFFSET $off", $types, $params);

$assetRows = [];
while ($r = $rows->fetch_assoc()) {
    $assetRows[] = $r;
}
$partsSnCounts = parts_stock_out_counts_by_sn(array_column($assetRows, 'asset_code'));

$productList = qr("SELECT DISTINCT p.name FROM products p JOIN assets a ON a.product_id=p.id ORDER BY p.name");

page_header('ทะเบียนเครื่องผลิตใหม่ (' . number_format($totalRows) . ')');
?>
<div class="filter" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
<form method="get" style="display:contents">
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
</form>
  <?php if ($withdrawSyncPendingCount > 0) { ?>
  <form method="post" style="display:inline-flex" onsubmit="return confirm('Sync ตามรายการเบิก <?= (int)$withdrawSyncPendingCount ?> เครื่อง (ตาม filter ปัจจุบัน)?\n\nผูก Stock ตามตาราง · ลบใบเบิกซ้ำ/เกิน (คืนสต็ock)')">
    <?= csrf_field() ?>
    <input type="hidden" name="sync_withdraw_list_bulk" value="1">
    <input type="hidden" name="filter_q" value="<?= h($search) ?>">
    <input type="hidden" name="filter_product" value="<?= h($product) ?>">
    <input type="hidden" name="filter_status" value="<?= h($status) ?>">
    <input type="hidden" name="filter_sort" value="<?= h($sort) ?>">
    <button type="submit" class="btn btn-sm btn-line btn-with-icon"><?= ui_btn_label('refresh', 'Sync รายการเบิก (' . number_format($withdrawSyncPendingCount) . ')') ?></button>
  </form>
  <?php } ?>
  <a class="btn" href="<?= BASE_URL ?>/asset_new.php" style="margin-left:auto">➕ ลงทะเบียนเครื่องผลิตใหม่</a>
</div>
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

<div class="table-wrap">
<table class="list">
  <tr><th></th><th>หมายเลขสินค้า</th><th>รุ่น</th><th>สถานะ</th><th>เบิกอะไหล่</th><th>ผู้บันทึกรายการ</th><th>ผลิตเมื่อ</th><th>FW</th></tr>
  <?php if (!$assetRows) { ?>
  <tr><td colspan="8" class="muted" style="text-align:center;padding:20px">ไม่พบเครื่องที่ตรงกับเงื่อนไข</td></tr>
  <?php } ?>
  <?php foreach ($assetRows as $r) {
      $sn = trim((string)$r['asset_code']);
      $stockOutCnt = $sn !== '' ? (int)($partsSnCounts[$sn] ?? 0) : 0;
      $outCnt = (int)$r['parts_out_cnt'];
      $linkedCnt = (int)$r['parts_linked_cnt'];
      $bomCnt = (int)$r['bom_cnt'];
      $pRow = asset_parts_withdraw_row_status($outCnt, $stockOutCnt, $bomCnt, 0);
      if ($outCnt > 0) {
          $wst = asset_withdraw_list_sync_status_from_counts($outCnt, $stockOutCnt, $linkedCnt);
          $pRow['label'] = $wst['label'];
          $pRow['status'] = $wst['match'] === 'ok' ? 'done' : 'partial';
      }
      $pStatus = $pRow['status'];
      $pLabel = $pRow['label'];
      $partsProdUrl = asset_parts_production_url($sn);
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
        <br><a href="<?= h($partsProdUrl) ?>" class="muted" style="font-size:11px" title="ประวัติเบิก production/MA">Production</a>
        <?php } ?>
      <?php } ?>
    </td>
    <td><?= h($r['recorder'] ?: '-') ?></td>
    <td><?= dthai($r['produced_at']) ?></td>
    <td><?= h($r['current_fw_version'] ?: '-') ?></td>
  </tr>
  <?php } ?>
</table>
</div>

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
