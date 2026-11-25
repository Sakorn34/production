<?php
/**
 * assets.php — ทะเบียนเครื่องผลิตใหม่ (รายการ + ค้นหา + กรองสถานะ)
 *
 * ตัวกรอง "การเบิกใช้งาน" จัดประเภทตามป้ายคอลัมน์ท้ายตาราง (ระบบเช่า / ใบเบิกขาย)
 * ไม่ใช่ assets.status ซึ่งกรองในช่อง "สถานะ" คนละช่อง
 */
require __DIR__ . '/config.php';
require_login();

// AJAX: ค้นหาแบบพิมพ์ไปเจอไป (live search)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    $qq = trim(isset($_GET['q']) ? $_GET['q'] : '');
    $productFilter = trim((string) (isset($_GET['product']) ? $_GET['product'] : ''));
    $out = [];
    if (mb_strlen($qq) >= 1) {
        $like = "%$qq%";
        $w = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)';
        $types = 'ss';
        $params = [$like, $like];
        // หน้า MA ส่ง product เป็น id · ตารางทะเบียนส่งชื่อรุ่น — รองรับทั้งสองแบบ
        if ($productFilter !== '') {
            if (ctype_digit($productFilter)) {
                $w .= ' AND a.product_id=?';
                $types .= 'i';
                $params[] = (int) $productFilter;
            } else {
                $w .= ' AND p.name=?';
                $types .= 's';
                $params[] = $productFilter;
            }
        }
        $res = qr("SELECT a.id, a.asset_code, a.factory_serial, a.status, a.produced_at, p.name pname
                   FROM assets a JOIN products p ON p.id=a.product_id
                   WHERE $w
                   ORDER BY (a.asset_code LIKE ?) DESC, a.asset_code LIMIT 15",
                  $types . 's', array_merge($params, ["$qq%"]));
        while ($r = $res->fetch_assoc()) {
            $out[] = ['id' => (int)$r['id'], 'code' => $r['asset_code'],
                      'serial' => $r['factory_serial'], 'pname' => $r['pname'],
                      'status' => status_th($r['status']),
                      'produced' => dthai($r['produced_at'] ?? ''),
                      'age' => dt_age_text($r['produced_at'] ?? '')];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

require __DIR__ . '/includes/layout.php';

$search  = trim(isset($_GET['q']) ? $_GET['q'] : '');
$status  = isset($_GET['status']) ? $_GET['status'] : '';
$product = isset($_GET['product']) ? $_GET['product'] : '';
$recorder = trim((string) (isset($_GET['recorder']) ? $_GET['recorder'] : ''));
if (mb_strlen($recorder) > 100) {
    $recorder = mb_substr($recorder, 0, 100);
}
$fw = trim((string) (isset($_GET['fw']) ? $_GET['fw'] : ''));
if (mb_strlen($fw) > 40) {
    $fw = mb_substr($fw, 0, 40);
}
// ตัวกรองการเบิกอะไหล่ — ปกติไม่แสดงในตาราง (ดูรายละเอียดที่หน้าโปรไฟล์เครื่อง)
// ไว้เรียกดูเฉพาะตอนอยากตรวจว่ามีเครื่องไหนตกหล่น
$pw      = isset($_GET['pw']) ? (string) $_GET['pw'] : '';
if (!in_array($pw, ['none', 'partial'], true)) { $pw = ''; }
// ตัวกรอง "การเบิกใช้งาน" — ตรงกับป้ายในคอลัมน์ท้ายตาราง (คำนวณใน PHP ไม่ใช่ SQL เพราะข้อมูลอยู่ 3 ระบบ)
// แยกสถานะระบบเช่าตามป้ายจริง ไม่กอง MA / คลังพร้อมเช่า / รับคืนไว้ช่องเดียว
$wdGroups = [
    'ระบบเช่า' => [
        'lease_active'   => 'ใช้งานอยู่กับลูกค้า',
        'lease_fg'       => 'คลังพร้อมเช่า',
        'lease_ma'       => 'รอซ่อม MA',
        'lease_claim'    => 'เคลม',
        'lease_await'    => 'รอส่งคืน',
        'lease_received' => 'รับคืนแล้ว',
        'lease_other'    => 'สถานะอื่นในระบบเช่า',
    ],
    'ขาย / คลังผลิต' => [
        'sold'    => 'เบิกขาย / ส่งมอบแล้ว',
        'instock' => 'อยู่ในคลัง',
        'nodoc'   => 'ขายแล้วแต่ไม่มีใบเบิก',
    ],
];
$wdOptions = [];
foreach ($wdGroups as $wdOpts) {
    foreach ($wdOpts as $wk => $wl) {
        $wdOptions[$wk] = $wl;
    }
}
$wd = isset($_GET['wd']) ? (string) $_GET['wd'] : '';
if ($wd === 'unknown') {
    $wd = 'instock';
}
if (!isset($wdOptions[$wd])) { $wd = ''; }
$wsync = isset($_GET['wsync']) && (string) $_GET['wsync'] === '1';
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

$hasActiveFilter = ($search !== '' || $product !== '' || $status !== '' || $recorder !== '' || $fw !== '' || $pw !== '' || $wd !== '' || $sort !== 'time_code' || $wsync);

/**
 * จัดประเภท "การเบิกใช้งาน" ของเครื่องหนึ่งเครื่อง — ลำดับเดียวกับป้ายในคอลัมน์ท้ายตาราง
 *
 * สถานะระบบเช่าใช้ลำดับเดียวกับ rent_leasing_status_label() เพื่อให้ตัวกรองกับป้ายตรงกัน
 * (เช่น active ชนะ finished goods ที่ค้างในทะเบียน)
 *
 * @param array<string,mixed>|null $lease   จาก asset_leasing_status_by_assets()
 * @param array<string,mixed>|null $sale    จาก asset_stockparts_sale_status_by_sn() (null = ไม่พบใน stock)
 * @param array<string,mixed>|null $saleSu  จาก asset_status_setup_sold_map()
 * @param string                   $status  assets.status
 * @return string lease_active|lease_fg|lease_ma|lease_claim|lease_await|lease_received|lease_other|sold|instock|nodoc
 */
function assets_page_withdraw_class($lease, $sale, $saleSu, string $status): string
{
    if (!empty($lease['found'])) {
        $p = trim((string) ($lease['p_status'] ?? ''));
        $pro = trim((string) ($lease['pro_status'] ?? ''));
        if ($p === 'active' || ($lease['badge_class'] ?? '') === 'asset-rent-badge-active') {
            return 'lease_active';
        }
        if ($pro === 'MA' || $p === 'MA') {
            return 'lease_ma';
        }
        if ($pro === 'claim' || $p === 'claim') {
            return 'lease_claim';
        }
        if ($pro === 'Awaiting Return') {
            return 'lease_await';
        }
        if ($pro === 'finished goods') {
            return 'lease_fg';
        }
        if ($p === 'received') {
            return 'lease_received';
        }
        return 'lease_other';
    }
    if (!empty($sale['sold']) || $saleSu) {
        return 'sold';
    }
    return $status === 'sold' ? 'nodoc' : 'instock';
}

/**
 * สร้าง WHERE สำหรับ filter หน้า assets.php — ช่องละคอลัมน์ตามหัวตาราง
 *
 * @param string $search   รหัสเครื่อง / factory_serial
 * @param string $status   สถานะเครื่อง
 * @param string $product  ชื่อรุ่น
 * @param string $pw       '' ทั้งหมด · none ยังไม่เบิกเลย · partial เบิกไม่ครบชุด
 * @param string $recorder ผู้บันทึกรายการ (made_by / assembly_by)
 * @param string $fw       เวอร์ชัน FW
 * @return array{w:string,types:string,params:array<int|string>}
 */
function assets_page_build_where(
    string $search,
    string $status,
    string $product,
    string $pw = '',
    string $recorder = '',
    string $fw = ''
): array {
    $where = [];
    $types = '';
    $params = [];
    if ($search !== '') {
        $like = "%{$search}%";
        $where[] = '(a.asset_code LIKE ? OR a.factory_serial LIKE ?)';
        $types .= 'ss';
        array_push($params, $like, $like);
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
    if ($recorder !== '') {
        $like = "%{$recorder}%";
        $where[] = 'EXISTS (SELECT 1 FROM production_records pr
                WHERE pr.asset_id = a.id AND (pr.made_by LIKE ? OR pr.assembly_by LIKE ?))';
        $types .= 'ss';
        array_push($params, $like, $like);
    }
    if ($fw !== '') {
        $like = "%{$fw}%";
        $where[] = 'a.current_fw_version LIKE ?';
        $types .= 's';
        $params[] = $like;
    }
    // เทียบจำนวนอะไหล่ที่เบิกจริง (นับชนิด ไม่ใช่จำนวนชิ้น) กับชุดเบิกของรุ่น
    // เครื่องของรุ่นที่ยังไม่ได้ตั้งชุดเบิกไม่นับว่าตกหล่น
    if ($pw === 'none') {
        $where[] = "EXISTS (SELECT 1 FROM bom_items b WHERE b.product_id = a.product_id)
                AND NOT EXISTS (SELECT 1 FROM part_movements pm WHERE pm.ref_asset_id = a.id AND pm.direction = 'out')";
    } elseif ($pw === 'partial') {
        $where[] = "EXISTS (SELECT 1 FROM part_movements pm WHERE pm.ref_asset_id = a.id AND pm.direction = 'out')
                AND (SELECT COUNT(DISTINCT pm.part_id) FROM part_movements pm
                     WHERE pm.ref_asset_id = a.id AND pm.direction = 'out')
                    < (SELECT COUNT(*) FROM bom_items b WHERE b.product_id = a.product_id)";
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return ['w' => $w, 'types' => $types, 'params' => $params];
}

/**
 * URL หน้าทะเบียนเครื่องตามตัวกรองปัจจุบัน
 *
 * @param array<string,mixed> $over ค่าที่ทับ ว่าง/null = ลบคีย์
 * @return string
 */
function assets_page_url(array $over = []): string
{
    global $search, $status, $product, $recorder, $fw, $pw, $wd, $sort, $wsync;
    $qs = [];
    if ($search !== '') {
        $qs['q'] = $search;
    }
    if ($product !== '') {
        $qs['product'] = $product;
    }
    if ($status !== '') {
        $qs['status'] = $status;
    }
    if ($recorder !== '') {
        $qs['recorder'] = $recorder;
    }
    if ($fw !== '') {
        $qs['fw'] = $fw;
    }
    if ($pw !== '') {
        $qs['pw'] = $pw;
    }
    if ($wd !== '') {
        $qs['wd'] = $wd;
    }
    if ($sort !== 'time_code') {
        $qs['sort'] = $sort;
    }
    if ($wsync) {
        $qs['wsync'] = '1';
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return BASE_URL . '/assets.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$filter = assets_page_build_where($search, $status, $product, $pw, $recorder, $fw);
$w = $filter['w'];
$types = $filter['types'];
$params = $filter['params'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_withdraw_list_bulk'])) {
    csrf_check();
    if (array_key_exists('filter_product', $_POST)) {
        $search = trim((string) ($_POST['filter_q'] ?? ''));
        $status = trim((string) ($_POST['filter_status'] ?? ''));
        $product = trim((string) ($_POST['filter_product'] ?? ''));
        $recorder = trim((string) ($_POST['filter_recorder'] ?? ''));
        if (mb_strlen($recorder) > 100) {
            $recorder = mb_substr($recorder, 0, 100);
        }
        $fw = trim((string) ($_POST['filter_fw'] ?? ''));
        if (mb_strlen($fw) > 40) {
            $fw = mb_substr($fw, 0, 40);
        }
        $pw = trim((string) ($_POST['filter_pw'] ?? ''));
        if (!in_array($pw, ['none', 'partial'], true)) { $pw = ''; }
        $wd = trim((string) ($_POST['filter_wd'] ?? ''));
        if ($wd === 'unknown') {
            $wd = 'instock';
        }
        if (!isset($wdOptions[$wd])) { $wd = ''; }
        $sort = trim((string) ($_POST['filter_sort'] ?? 'time_code'));
        if (!isset($sortSql[$sort])) {
            $sort = 'time_code';
        }
        $filter = assets_page_build_where($search, $status, $product, $pw, $recorder, $fw);
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
        $phIds = implode(',', array_fill(0, count($syncIds), '?'));
        $still = asset_ids_needing_withdraw_list_sync(
            'WHERE a.id IN (' . $phIds . ')',
            str_repeat('i', count($syncIds)),
            array_map('intval', $syncIds)
        );
        if ($sync['ok']) {
            $msg = $sync['message'] ?? 'Sync รายการเบิกสำเร็จ';
            if ($still) {
                // บอกเหตุให้เห็นเลย — ข้อความรวม ๆ ทำให้กดซ้ำแล้วไม่รู้ว่าทำไมยังไม่หาย
                $why = [];
                foreach (array_slice($still, 0, 3) as $sid) {
                    $r = asset_withdraw_sync_reason((int) $sid);
                    if ($r !== '') {
                        $why[] = $r;
                    }
                }
                $msg .= ' — ยังไม่ตรง ' . count($still) . ' เครื่อง' . ($why ? ': ' . implode(' · ', $why) : '');
            } elseif (!empty($sync['errors'])) {
                $msg .= ' — ข้าม/ผิดพลาด ' . count($sync['errors']) . ' รายการ เช่น ' . $sync['errors'][0];
            }
            flash_set($msg, $still ? 'err' : 'ok');
        } else {
            flash_set($sync['errors'][0] ?? ($sync['message'] ?? 'Sync รายการเบิกไม่สำเร็จ'), 'err');
        }
    }
    $redirectQs = array_filter([
        'q'        => $search,
        'product'  => $product,
        'status'   => $status,
        'recorder' => $recorder,
        'fw'       => $fw,
        'pw'       => $pw,
        'wd'       => $wd,
        'sort'     => $sort,
    ], static function ($v) {
        return $v !== '';
    });
    header('Location: ' . BASE_URL . '/assets.php?' . http_build_query($redirectQs));
    exit;
}

$syncPendingIds = asset_withdraw_list_sync_pending_ids_cached($w, $types, $params);
$withdrawSyncPendingCount = count($syncPendingIds);
$syncPendingSet = array_fill_keys($syncPendingIds, true);
if ($wsync) {
    if ($syncPendingIds === []) {
        $w .= ($w === '' ? 'WHERE ' : ' AND ') . '0=1';
    } else {
        $ph = implode(',', array_fill(0, count($syncPendingIds), '?'));
        $w .= ($w === '' ? 'WHERE ' : ' AND ') . 'a.id IN (' . $ph . ')';
        $types .= str_repeat('i', count($syncPendingIds));
        $params = array_merge($params, $syncPendingIds);
    }
}

require_once __DIR__ . '/includes/stockparts_withdraw.php';
require_once __DIR__ . '/includes/rent_ma_bridge.php';
require_once __DIR__ . '/includes/asset_status_sync.php';

$totalRows = qr("SELECT COUNT(*) c FROM assets a JOIN products p ON p.id=a.product_id $w", $types, $params)->fetch_assoc()['c'];
$off = ($page - 1) * $per;
$rowLimitSql = ' LIMIT ' . (int) $per . ' OFFSET ' . (int) $off;

if ($wd !== '') {
    // กรอง "การเบิกใช้งาน" ต้องรู้ประเภทของทุกเครื่องที่ผ่านตัวกรองอื่นก่อน (หลักพันแถว — เบา)
    // แล้วค่อยแบ่งหน้าเอง ด้วยการเติม a.id IN (...) เข้า WHERE ของคิวรีหลัก
    $cand = [];
    $cr = qr("SELECT a.id, a.asset_code, a.factory_serial, a.status
              FROM assets a JOIN products p ON p.id=a.product_id
              $w ORDER BY {$sortSql[$sort]}", $types, $params);
    while ($x = $cr->fetch_assoc()) {
        $cand[] = $x;
    }
    // แคชประเภทของ "ทุกเครื่อง" 2 นาที ในไฟล์ชั่วคราว — การคำนวณข้าม 3 ระบบหลักหมื่นเครื่องใช้เวลาหลายวินาที
    $clsFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'assets_wd_class_v3_' . md5(__DIR__) . '.json';
    $clsMap = null;
    if (is_file($clsFile) && (time() - (int) @filemtime($clsFile)) < 120) {
        $clsMap = json_decode((string) @file_get_contents($clsFile), true);
        if (!is_array($clsMap)) {
            $clsMap = null;
        }
    }
    $needBuild = $clsMap === null;
    if (!$needBuild) {
        foreach ($cand as $x) {
            if (!isset($clsMap[$x['asset_code']])) { // มีเครื่องใหม่หลังสร้างแคช
                $needBuild = true;
                break;
            }
        }
    }
    if ($needBuild) {
        $all = [];
        $ar = qr('SELECT a.id, a.asset_code, a.factory_serial, a.status FROM assets a');
        while ($x = $ar->fetch_assoc()) {
            $all[] = $x;
        }
        $codes = array_column($all, 'asset_code');
        $cLease = asset_leasing_status_by_assets($all);
        $cSale  = asset_stockparts_sale_status_by_sn($codes);
        $cSetup = asset_status_setup_sold_map($codes);
        $clsMap = [];
        foreach ($all as $x) {
            $code = $x['asset_code'];
            $clsMap[$code] = assets_page_withdraw_class(
                $cLease[$code] ?? null,
                $cSale[$code] ?? null,
                $cSetup[strtoupper((string) $code)] ?? null,
                (string) $x['status']
            );
        }
        @file_put_contents($clsFile, json_encode($clsMap, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    $matchIds = [];
    foreach ($cand as $x) {
        $cls = $clsMap[$x['asset_code']] ?? '';
        if ($cls === $wd) {
            $matchIds[] = (int) $x['id'];
        }
    }
    $totalRows = count($matchIds);
    $pageIds = array_slice($matchIds, $off, $per);
    $w = ($w === '' ? 'WHERE' : $w . ' AND')
       . ' a.id IN (' . ($pageIds ? implode(',', $pageIds) : '0') . ')';
    $rowLimitSql = ''; // จำกัดด้วย id แล้ว
}
$pages = max(1, (int)ceil($totalRows / $per));

$rows = qr("SELECT a.id, a.asset_code, a.factory_serial, a.status, a.produced_at, a.current_fw_version,
                   p.name pname, p.icon_path, p.id product_id,
                   (SELECT pr.made_by FROM production_records pr
                    WHERE pr.asset_id=a.id AND pr.made_by IS NOT NULL AND pr.made_by<>''
                    ORDER BY pr.recorded_at ASC, pr.id ASC LIMIT 1) recorder
            FROM assets a JOIN products p ON p.id=a.product_id
            $w ORDER BY {$sortSql[$sort]}{$rowLimitSql}", $types, $params);

$assetRows = [];
while ($r = $rows->fetch_assoc()) {
    $assetRows[] = $r;
}
// สถานะการเบิกขาย — อ่านจากระบบ stock ที่เดียวกับการ์ดในหน้าโปรไฟล์เครื่อง
// เดิมคอลัมน์นี้อ่าน stock_movements ซึ่งนับ "เบิกผลิต" เป็นเบิกออกด้วย จึงไม่ใช่การขาย
$saleStatus = asset_stockparts_sale_status_by_sn(array_column($assetRows, 'asset_code'));
// ใบสั่งงาน/ประวัติขายฝั่งระบบ setup — บางเครื่องคลังจ่ายออกทางนั้นโดยไม่มีใบเบิกใน stock
// ถ้าไม่อ่านด้วย คอลัมน์นี้จะขึ้นว่า "อยู่ในคลัง" ทั้งที่ของส่งถึงลูกค้าแล้ว
$saleSetup = asset_status_setup_sold_map(array_column($assetRows, 'asset_code'));
$leaseStatus = asset_leasing_status_by_assets($assetRows);
try {
    asset_status_sync_batch($assetRows, true);
    foreach ($assetRows as &$ar) {
        $ref = qr('SELECT status FROM assets WHERE id=? LIMIT 1', 'i', [(int) ($ar['id'] ?? 0)])->fetch_assoc();
        if ($ref) {
            $ar['status'] = $ref['status'];
        }
    }
    unset($ar);
} catch (Throwable $e) {
    error_log('[asset_status_sync_batch] ' . $e->getMessage());
}

$productList = qr("SELECT DISTINCT p.name FROM products p JOIN assets a ON a.product_id=p.id ORDER BY p.name");

$assetsCta = '<a class="btn" href="' . BASE_URL . '/asset_new.php">'
    . ui_btn_label('assets', ' ลงทะเบียนเครื่องผลิตใหม่') . '</a>'
    . '<a class="btn btn-line" href="' . BASE_URL . '/leasing_move.php" data-same-tab>'
    . ui_btn_label('upload', ' ย้ายไประบบเช่า') . '</a>';
page_header('ทะเบียนเครื่องผลิตใหม่', true, number_format($totalRows) . ' เครื่อง', '', $assetsCta);
?>
<div class="filter assets-filter">
<form method="get" class="assets-filter-form" id="assets-form">
  <?php if ($wsync) { ?><input type="hidden" name="wsync" value="1"><?php } ?>
  <?php if ($pw !== '') { ?><input type="hidden" name="pw" value="<?= h($pw) ?>"><?php } ?>
  <?php // ── แถวหลัก: ค้นหา + กรองสถานะ (เสมอ) + ปุ่มตัวกรองเพิ่มเติม ?>
  <div class="af-top">
    <div class="assets-ff assets-ff-code af-search">
      <span class="livesearch-wrap">
        <input type="text" name="q" id="live-q" data-scan="submit" value="<?= h($search) ?>" placeholder="ค้นหารหัสเครื่อง" autocomplete="off">
        <div id="live-results" class="combo-list" hidden></div>
      </span>
    </div>
    <?php // กรองสถานะแบบ pill — กดครั้งเดียวโดยไม่ต้องกดปุ่มค้นหา ?>
    <div class="af-status-row">
      <button type="button" class="af-spill<?= $status === '' ? ' af-on' : '' ?>" data-val="">ทั้งหมด</button>
      <?php foreach (status_palette() as $sk => $se) { ?>
      <button type="button" class="af-spill<?= $status === $sk ? ' af-on' : '' ?>" data-val="<?= h($sk) ?>" style="--sp-bg:<?= h($se['bg']) ?>;--sp-fg:<?= h($se['fg']) ?>"><?= h($se['chip']) ?></button>
      <?php } ?>
      <input type="hidden" name="status" id="af-status-val" value="<?= h($status) ?>">
    </div>
    <div class="af-top-actions">
      <button type="button" id="af-toggle" class="btn btn-line btn-sm af-toggle-btn" aria-expanded="false">
        ตัวกรอง<span class="af-cnt" id="af-cnt" hidden></span>
      </button>
      <button type="submit" class="btn">ค้นหา</button>
      <?php if ($hasActiveFilter) { ?>
      <a href="<?= BASE_URL ?>/assets.php" class="btn btn-line btn-sm">ล้าง</a>
      <?php } ?>
    </div>
  </div>
  <?php // ── แถวเพิ่มเติม: พับได้ — เรียงตาม / รุ่น / ผู้บันทึก / FW / การเบิก ?>
  <div class="af-extra" id="af-extra">
    <label class="assets-ff">
      <span>เรียงตาม</span>
      <select name="sort" onchange="this.form.submit()">
        <option value="time_code" <?= $sort==='time_code'?'selected':'' ?>>บันทึกล่าสุด</option>
        <option value="date_desc" <?= $sort==='date_desc'?'selected':'' ?>>ใหม่ → เก่า</option>
        <option value="date_asc"  <?= $sort==='date_asc' ?'selected':'' ?>>เก่า → ใหม่</option>
        <?php if ($sort==='code')   { ?><option value="code"   selected>รหัสเครื่อง</option><?php } ?>
        <?php if ($sort==='recent') { ?><option value="recent" selected>เพิ่มเข้าระบบล่าสุด</option><?php } ?>
      </select>
    </label>
    <label class="assets-ff">
      <span>รุ่น</span>
      <select name="product" onchange="this.form.submit()">
        <option value="">ทั้งหมด</option>
        <?php while ($p = $productList->fetch_assoc()) { ?>
          <option value="<?= h($p['name']) ?>" <?= $product===$p['name']?'selected':'' ?>><?= h($p['name']) ?></option>
        <?php } ?>
      </select>
    </label>
    <label class="assets-ff">
      <span>ผู้บันทึกรายการ</span>
      <input type="text" name="recorder" value="<?= h($recorder) ?>" placeholder="ชื่อคนบันทึก" maxlength="100" autocomplete="off">
    </label>
    <label class="assets-ff assets-ff-fw">
      <span>FW</span>
      <input type="text" name="fw" value="<?= h($fw) ?>" placeholder="เวอร์ชัน" maxlength="40" autocomplete="off">
    </label>
    <label class="assets-ff assets-ff-wd">
      <span>การเบิกใช้งาน</span>
      <select name="wd" onchange="this.form.submit()">
        <option value="">ทั้งหมด</option>
        <?php foreach ($wdGroups as $wgLabel => $wdOpts) { ?>
        <optgroup label="<?= h($wgLabel) ?>">
          <?php foreach ($wdOpts as $wk => $wl) { ?>
          <option value="<?= h($wk) ?>" <?= $wd===$wk?'selected':'' ?>><?= h($wl) ?></option>
          <?php } ?>
        </optgroup>
        <?php } ?>
      </select>
    </label>
  </div>
</form>
  <?php if ($withdrawSyncPendingCount > 0) { ?>
  <div class="assets-filter-sync">
    <?php if ($wsync) { ?>
    <span class="muted assets-wsync-note">กำลังแสดงเฉพาะ <?= number_format($withdrawSyncPendingCount) ?> เครื่องที่รายการเบิกยังไม่ตรงสต็อก</span>
    <a href="<?= h(assets_page_url(['wsync' => null, 'page' => null])) ?>" class="btn btn-line btn-sm">แสดงทั้งหมด</a>
    <?php } else { ?>
    <a href="<?= h(assets_page_url(['wsync' => '1', 'page' => null])) ?>" class="btn btn-line btn-sm" title="เปิดเฉพาะเครื่องที่รายการเบิกยังไม่ตรงสต็อกอะไหล่">ดู <?= number_format($withdrawSyncPendingCount) ?> เครื่องนี้</a>
    <?php } ?>
  <form method="post" onsubmit="return confirm('จับคู่ยอดเบิกกับสต็อกให้ตรงกัน สำหรับ <?= (int)$withdrawSyncPendingCount ?> เครื่อง (ตามตัวกรองที่เลือกอยู่)?\n\nระบบจะจับคู่ยอดเบิกกับสต็อก และลบใบเบิกที่ซ้ำ/เกินออก (คืนสต็อกให้)')">
    <?= csrf_field() ?>
    <input type="hidden" name="sync_withdraw_list_bulk" value="1">
    <input type="hidden" name="filter_q" value="<?= h($search) ?>">
    <input type="hidden" name="filter_product" value="<?= h($product) ?>">
    <input type="hidden" name="filter_status" value="<?= h($status) ?>">
    <input type="hidden" name="filter_recorder" value="<?= h($recorder) ?>">
    <input type="hidden" name="filter_fw" value="<?= h($fw) ?>">
    <input type="hidden" name="filter_pw" value="<?= h($pw) ?>">
    <input type="hidden" name="filter_wd" value="<?= h($wd) ?>">
    <input type="hidden" name="filter_sort" value="<?= h($sort) ?>">
    <button type="submit" class="btn btn-sm btn-line btn-with-icon"><?= ui_btn_label('refresh', 'Sync รายการเบิก (' . number_format($withdrawSyncPendingCount) . ')') ?></button>
  </form>
  </div>
  <?php } ?>
</div>

<style>
/* ── Filter: layout ──────────────────────────────────────── */
.assets-filter { margin-bottom: 10px; }
.assets-filter-form { display: flex; flex-direction: column; gap: 10px; }
.af-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-start; }
.af-search { flex: 1 1 160px; min-width: 140px; max-width: 260px; }
.af-search .livesearch-wrap { display: block; width: 100%; position: relative; }
.af-search input { width: 100%; }

/* ── Status pills ─────────────────────────────────────────── */
.af-status-row { flex: 1 1 auto; display: flex; flex-wrap: wrap; gap: 5px; align-items: center; }
.af-spill {
  padding: 5px 11px; border-radius: 999px; cursor: pointer;
  font-size: calc(12px * var(--font-scale,1)); font-weight: 600; white-space: nowrap;
  border: 1.5px solid var(--border,#dfe4ec);
  background: var(--surface-muted,#f4f1fb); color: var(--text-muted,#6b6480);
  transition: background .1s, color .1s, border-color .1s, transform .1s;
  line-height: 1.4;
}
.af-spill:hover { background: #fff; transform: translateY(-1px); border-color: var(--border-strong,#c3c0d0); }
.af-spill.af-on {
  background: var(--sp-bg,var(--primary)); color: var(--sp-fg,#fff);
  border-color: var(--sp-fg,var(--primary));
}
.af-spill[data-val=""].af-on { background: var(--primary); color: #fff; border-color: var(--primary); }

/* ── Filter toggle + badge ────────────────────────────────── */
.af-top-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.af-toggle-btn { display: inline-flex; align-items: center; gap: 5px; }
.af-toggle-btn::after { content: '\25B8'; font-size: 11px; transition: transform .2s; }
.af-toggle-btn[aria-expanded="true"]::after { transform: rotate(90deg); }
.af-cnt {
  display: inline-block; min-width: 18px; padding: 0 5px; border-radius: 999px;
  background: var(--primary); color: #fff; font-size: calc(11px * var(--font-scale,1));
  font-weight: 700; text-align: center; line-height: 18px; margin-left: 2px;
}

/* ── Extra filters: พับ/กาง ───────────────────────────────── */
.af-extra {
  display: none; flex-wrap: wrap; gap: 8px 10px; align-items: flex-end;
  padding-top: 10px; border-top: 1px solid var(--border,#dfe4ec);
}
.af-extra.af-open { display: flex; }
.assets-ff { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.assets-ff > span:first-child {
  font-size: 12px; font-weight: 600; color: var(--text-muted,#6b6480);
  line-height: 1.2; white-space: nowrap;
}
.assets-ff input[type=text], .assets-ff select { min-height: var(--input-h,40px); }
.af-extra .assets-ff select { min-width: 132px; max-width: 220px; }
.af-extra .assets-ff-fw { width: 108px; flex: 0 0 108px; }
.af-extra .assets-ff-fw input { width: 100%; }
.af-extra .assets-ff-wd select { min-width: 170px; max-width: 260px; }
.assets-filter-sync { display: inline-flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
.assets-wsync-note { font-size: calc(12.5px * var(--font-scale,1)); align-self: center; }
.assets-wsync-flag {
  display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px;
  font-size: calc(11px * var(--font-scale,1)); font-weight: 600; white-space: nowrap;
  background: var(--warning-soft,#fef9c3); color: var(--warning,#a16207);
}

/* ── Mobile ───────────────────────────────────────────────── */
@media (max-width: 780px) {
  .af-search { max-width: none; }
  .af-status-row { flex: 1 1 100%; }
  .af-extra .assets-ff { flex: 1 1 calc(50% - 8px); max-width: none; }
  .af-extra .assets-ff select { min-width: 0; max-width: none; width: 100%; }
}

/* ── การ์ดบนมือถือ: code+sub ซ้าย / status ขวา ─────────── */
@media (max-width: 760px) {
  table.assets-list tr { display: flex; flex-wrap: wrap; gap: 2px 10px; }
  table.assets-list td[data-pri="1"]:first-of-type { flex: 1 1 0; min-width: 0; }
  table.assets-list td[data-pri="1"]:last-of-type  { flex: 0 0 auto; align-self: center; }
  table.assets-list .cell-sub { font-size: calc(12px * var(--font-scale,1)); margin-top: 2px; }
}

/* ── ป้ายการเบิกขาย ───────────────────────────────────────── */
.sale-tag {
  display: inline-block; padding: 2px 10px; border-radius: 999px;
  font-size: calc(12px * var(--font-scale,1)); font-weight: 600; white-space: nowrap;
}
.sale-in     { background: var(--success-soft,#dcfce7); color: #15803d; }
.sale-out    { background: var(--warning-soft,#fef9c3); color: #a16207; }
.sale-unknown{ background: transparent; color: var(--text-muted,#6b6480); font-weight: 500; }
.sale-none   { background: var(--surface-2,#f1efe8); color: var(--text-muted,#5f5e5a); }
.sale-tag.asset-rent-status { border: 1px solid transparent; font-size: calc(11px * var(--font-scale,1)); }
</style>
<script>
(function(){
  // ── livesearch ──────────────────────────────────────────────
  var input = document.getElementById('live-q');
  var box   = document.getElementById('live-results');
  var timer = null;
  function esc(s){ var d=document.createElement('div');d.textContent=s==null?'':s;return d.innerHTML; }
  input.addEventListener('input', function(){
    clearTimeout(timer);
    var q = input.value.trim();
    if (q.length < 2) { box.hidden = true; return; }
    timer = setTimeout(function(){
      var prod = document.querySelector('.af-extra select[name="product"]');
      var url  = '<?= BASE_URL ?>/assets.php?ajax=suggest&q=' + encodeURIComponent(q);
      if (prod && prod.value) url += '&product=' + encodeURIComponent(prod.value);
      fetch(url).then(function(r){return r.json();}).then(function(items){
        if (!items.length) { box.innerHTML = '<div class="muted" style="padding:6px 10px">ไม่พบเครื่องที่ตรง</div>'; box.hidden=false; return; }
        box.innerHTML = items.map(function(it){
          return '<div onclick="openAssetUrl(\'<?= BASE_URL ?>/asset.php?id=' + it.id + '\')">'
               + '<b>' + esc(it.code) + '</b> <span class="muted">' + esc(it.pname) + ' \xb7 ' + esc(it.status) + '</span></div>';
        }).join('');
        box.hidden = false;
      }).catch(function(){ box.hidden = true; });
    }, 350);
  });
  document.addEventListener('click', function(e){ if(!box.contains(e.target)&&e.target!==input) box.hidden=true; });

  // ── Status pills ────────────────────────────────────────────
  var spills = document.querySelectorAll('.af-spill');
  var statusInput = document.getElementById('af-status-val');
  var form = document.getElementById('assets-form');
  spills.forEach(function(pill){
    pill.addEventListener('click', function(){
      spills.forEach(function(p){ p.classList.remove('af-on'); });
      pill.classList.add('af-on');
      statusInput.value = pill.dataset.val;
      form.submit();
    });
  });

  // ── Filter toggle ───────────────────────────────────────────
  var toggle = document.getElementById('af-toggle');
  var extra  = document.getElementById('af-extra');
  var cntEl  = document.getElementById('af-cnt');
  var extraCnt = <?= (int)($product!=='') + (int)($recorder!=='') + (int)($fw!=='') + (int)($wd!=='') + (int)($sort!=='time_code') ?>;

  if (extraCnt > 0) { cntEl.textContent = extraCnt; cntEl.hidden = false; }

  var open = extraCnt > 0;
  try {
    var saved = localStorage.getItem('af_open');
    if (saved !== null && extraCnt === 0) open = saved === '1';
    if (window.innerWidth > 780 && saved === null) open = true;
  } catch(e) {}

  function setOpen(v){
    open = v;
    extra.classList.toggle('af-open', v);
    toggle.setAttribute('aria-expanded', v ? 'true' : 'false');
    try { localStorage.setItem('af_open', v ? '1' : '0'); } catch(e) {}
  }
  setOpen(open);
  toggle.addEventListener('click', function(){ setOpen(!open); });
})();
</script>
<div class="table-wrap table-wrap-fold">
<table class="list assets-list">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css)
        // 1 เห็นทุกความกว้าง · 2 ยุบลงบรรทัดรองที่ < 900px · 3 ซ่อนที่ < 1100px ?>
  <?php // ต้องมี <thead> จริง ไม่ใช่ <tr><th> ลอย ๆ — โหมดการ์ดบนมือถือซ่อนหัวตาราง
        // ด้วย thead{display:none} เบราว์เซอร์เติม tbody ให้เอง แต่ไม่เติม thead ?>
  <thead>
  <tr>
    <th data-pri="2">ผลิตเมื่อ</th><th data-pri="2"></th><th data-pri="1">รหัสเครื่อง</th>
    <th data-pri="2">รุ่น</th><th data-pri="1">สถานะ</th>
    <th data-pri="3">ผู้บันทึกรายการ</th><th data-pri="3">FW</th><th data-pri="3">การเบิกใช้งาน</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$assetRows) { ?>
  <tr><td colspan="8" class="muted" style="text-align:center;padding:20px">ไม่พบเครื่องที่ตรงกับเงื่อนไข</td></tr>
  <?php } ?>
  <?php foreach ($assetRows as $r) { ?>
  <tr>
    <td data-pri="2" data-nowrap><?= dthai($r['produced_at']) ?></td>
    <td data-pri="2" style="width:56px"><?= img_tag($r['icon_path'], $r['pname']) ?></td>
    <td data-pri="1">
      <a href="<?= BASE_URL ?>/asset.php?id=<?= $r['id'] ?>"><b><?= h($r['asset_code']) ?></b></a>
      <?php if (isset($syncPendingSet[(int) $r['id']])) { ?>
      <?php $syncWhy = asset_withdraw_sync_reason((int) $r['id']); ?>
      <span class="assets-wsync-flag" title="<?= h($syncWhy !== '' ? $syncWhy : 'รายการเบิกบนเครื่องนี้ยังไม่ตรงกับสต็อกอะไหล่') ?>">ต้อง Sync</span>
      <?php } ?>
      <?php // บรรทัดรอง — โผล่เองเมื่อคอลัมน์ระดับ 2 ถูกยุบที่จอแคบ ?>
      <span class="cell-sub"><?= h($r['pname']) ?> · <?= dthai($r['produced_at']) ?></span>
    </td>
    <td data-pri="2"><?= h($r['pname']) ?></td>
    <td data-pri="1"><?= status_badge($r['status']) ?></td>
    <td data-pri="3"><?= h($r['recorder'] ?: '-') ?></td>
    <td data-pri="3"><?= h($r['current_fw_version'] ?: '-') ?></td>
    <?php
      $lease = $leaseStatus[$r['asset_code']] ?? null;
      $sale = $saleStatus[$r['asset_code']] ?? null;
      $saleSu = $saleSetup[strtoupper((string) $r['asset_code'])] ?? null;
    ?>
    <td data-pri="3" data-nowrap>
      <?php if (!empty($lease['found'])) { ?>
        <?= rent_leasing_list_status_html($lease) ?>
      <?php } elseif ($sale === null) { ?>
        <span class="sale-tag sale-in" title="ไม่พบ S/N นี้ในทะเบียน stock — นับว่าอยู่ในคลังฝั่งผลิต">อยู่ในคลัง</span>
      <?php } elseif ($sale['sold']) {
          $saleSrc = (string) ($sale['resolve_source'] ?? '');
          if ($saleSrc === 'stock_old') {
              $saleTitle = 'ส่งมอบแล้ว · ' . (string) ($sale['setup_id'] ?? '');
          } else {
              $saleTitle = 'Setup ID #' . (string) ($sale['setup_id'] ?? '');
          }
      ?>
        <span class="sale-tag sale-out" title="<?= h($saleTitle) ?>">เบิกขายแล้ว</span>
      <?php } elseif ($saleSu) {
          // ไม่มีใบเบิกใน stock แต่ระบบ setup มีใบสั่งงาน/ประวัติขาย = ของออกไปแล้วจริง
          $suLabel = ['order' => 'ใบสั่งงาน', 'claim' => 'ส่งออกไปเคลม', 'sale' => 'ขายตามระบบ setup'][(string) $saleSu['src']] ?? 'ส่งมอบแล้ว';
          $suTitle = trim(implode(' · ', array_filter([
              'ระบบ setup',
              (string) $saleSu['ref'],
              (string) $saleSu['date'],
              (string) $saleSu['customer'],
          ]))); ?>
        <span class="sale-tag sale-out" title="<?= h($suTitle) ?>"><?= h($suLabel) ?></span>
      <?php } elseif ($r['status'] === 'sold') { ?>
        <?php // สถานะบอกว่าขายแล้ว แต่ไม่มีเอกสารการเบิกที่ไหนเลย — เขียนว่า "อยู่ในคลัง"
              // จะขัดกับคอลัมน์สถานะข้าง ๆ บอกตรง ๆ ว่าไม่มีใบเบิกดีกว่า ?>
        <span class="sale-tag sale-none" title="สถานะเป็นขายแล้ว แต่หาใบเบิกขาย ใบสั่งงาน และประวัติส่งมอบไม่เจอเลย">ไม่มีใบเบิก</span>
      <?php } else { ?>
        <span class="sale-tag sale-in">อยู่ในคลัง</span>
      <?php } ?>
    </td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<?php
echo page_pager_html($page, $pages, $per, $totalRows, function ($n) {
    $qs = $_GET;
    $qs['page'] = $n;
    return '?' . http_build_query($qs);
}, 'เครื่อง');
page_footer();
