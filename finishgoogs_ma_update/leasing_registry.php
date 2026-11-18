<?php
/**
 * leasing_registry.php — ทะเบียนเครื่องระบบเช่า (หน้าหลังบ้านแบบ B)
 *
 * การ์ดสรุปสถานะอยู่บนตลอด สลับแท็บ:
 *   list = ทะเบียนเครื่องทั้งระบบเช่า (กรองรหัส / รุ่น / สถานะ · หน้าละ 50)
 *   rent = การเบิกเช่าล่าสุด จาก tbl_rent_product เรียง p_id DESC
 *
 * อ่านอย่างเดียว ไม่เขียนกลับไปที่ biton_leasing
 * เข้าจากเมนูหลังบ้านใน settings.php
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/leasing_registry.php';
require_login();

$B = BASE_URL;

$tab = (string) ($_GET['tab'] ?? 'list');
if ($tab !== 'rent') {
    $tab = 'list';
}

$q      = leasing_registry_sanitize_sn((string) ($_GET['q'] ?? ''));
$model  = trim((string) ($_GET['model'] ?? ''));
if (mb_strlen($model) > 120) {
    $model = mb_substr($model, 0, 120);
}
$status = leasing_registry_sanitize_status((string) ($_GET['status'] ?? ''));
$page   = max(1, (int) ($_GET['page'] ?? 1));
$per    = LEASING_REGISTRY_PER;

$counts = leasing_registry_counts();
$err    = $counts['ok'] ? '' : (string) $counts['error'];

$list = ['ok' => true, 'error' => '', 'total' => 0, 'page' => 1, 'pages' => 1, 'rows' => []];
$rentals = $list;
$models = ['ok' => true, 'error' => '', 'names' => []];
$local = [];

if ($err === '') {
    if ($tab === 'list') {
        $models = leasing_registry_models();
        $list = leasing_registry_list($q, $model, $status, $page, $per);
        if (!$list['ok']) {
            $err = $list['error'];
        } else {
            $sns = [];
            foreach ($list['rows'] as $r) {
                $sns[] = (string) ($r['pro_sn'] ?? '');
            }
            $local = leasing_registry_local_by_sn($sns);
        }
    } else {
        $rentals = leasing_registry_rentals($page, $per);
        if (!$rentals['ok']) {
            $err = $rentals['error'];
        } else {
            $sns = [];
            foreach ($rentals['rows'] as $r) {
                $sns[] = (string) ($r['p_sn'] ?? '');
            }
            $local = leasing_registry_local_by_sn($sns);
        }
    }
}

/**
 * สร้าง query string ของหน้านี้ โดยคงตัวกรองที่ยังใช้
 *
 * @param array<string,mixed> $over ค่าที่ทับ
 * @return string URL เต็มของหน้า
 */
function leasing_registry_url(array $over = []): string
{
    global $B, $tab, $q, $model, $status;
    $qs = ['tab' => $tab];
    if ($q !== '') {
        $qs['q'] = $q;
    }
    if ($model !== '') {
        $qs['model'] = $model;
    }
    if ($status !== '') {
        $qs['status'] = $status;
    }
    foreach ($over as $k => $v) {
        if ($v === null || $v === '') {
            unset($qs[$k]);
        } else {
            $qs[$k] = $v;
        }
    }
    return $B . '/leasing_registry.php?' . http_build_query($qs);
}

/**
 * เซลล์รหัสเครื่อง — ลิงก์โปรไฟล์เราถ้ามี และลิงก์ระบบเช่าถ้าตั้ง URL ไว้
 *
 * @param string                              $sn
 * @param array<string,array{id:int,code:string}> $local
 * @param string                              $base
 * @return string HTML ที่ escape แล้ว
 */
function leasing_registry_sn_cell(string $sn, array $local, string $base): string
{
    $sn = trim($sn);
    if ($sn === '') {
        return '—';
    }
    $key = rent_normalize_sn($sn);
    $inner = '<b class="lr-sn">' . h($sn) . '</b>';
    if (isset($local[$key])) {
        $inner = '<a href="' . h($base . '/asset.php?id=' . (int) $local[$key]['id']) . '">' . $inner . '</a>';
    }
    $ext = rent_leasing_record_url($sn);
    if ($ext !== '') {
        $inner .= ' <a class="lr-ext" href="' . h($ext) . '" target="_blank" rel="noopener noreferrer" title="เปิดในระบบเช่า">'
            . ui_icon_html('external-link', 12) . '</a>';
    }
    return $inner;
}

$kpi = [
    ['key' => '', 'label' => 'เครื่องในระบบเช่า', 'n' => (int) ($counts['total'] ?? 0)],
    ['key' => 'rent', 'label' => 'กำลังเช่า', 'n' => (int) (($counts['by']['rent'] ?? 0))],
    ['key' => 'finished goods', 'label' => 'คลังพร้อมเช่า', 'n' => (int) (($counts['by']['finished goods'] ?? 0))],
    ['key' => 'MA', 'label' => 'รอซ่อม MA', 'n' => (int) (($counts['by']['MA'] ?? 0))],
];

page_header('ทะเบียนเครื่องระบบเช่า', true, 'เครื่องที่มีในระบบเช่า · การเบิกเช่าล่าสุด', $B . '/settings.php');
?>
<p class="muted lr-lead">อ่านอย่างเดียวจากระบบเช่า — ไม่แก้ไขข้อมูลฝั่งนั้นจากหน้านี้
กดการ์ดสถานะเพื่อเปิดทะเบียนที่กรองแล้ว · สลับแท็บเพื่อดูว่าเพิ่งเบิกเช่าเครื่องอะไรไปบ้าง</p>

<?php if ($err !== '') { ?>
<p class="flash err"><?= h($err) ?></p>
<?php } ?>

<div class="lr-stats" role="group" aria-label="สรุปสถานะในระบบเช่า">
  <?php foreach ($kpi as $k) {
      $on = ($tab === 'list' && $status === $k['key']);
      $href = leasing_registry_url(['tab' => 'list', 'status' => $k['key'], 'page' => null]);
      ?>
  <a class="lr-stat<?= $on ? ' is-on' : '' ?>" href="<?= h($href) ?>">
    <div class="lr-num"><?= number_format($k['n']) ?></div>
    <div class="lr-lbl"><?= h($k['label']) ?></div>
  </a>
  <?php } ?>
</div>

<div class="lr-bar">
  <span class="muted">หลังบ้าน · ระบบเช่า</span>
  <span class="lr-tabs">
    <a class="lr-tab<?= $tab === 'list' ? ' is-on' : '' ?>" href="<?= h(leasing_registry_url(['tab' => 'list', 'page' => null])) ?>">ทะเบียนเครื่อง</a>
    <a class="lr-tab<?= $tab === 'rent' ? ' is-on' : '' ?>" href="<?= h($B . '/leasing_registry.php?tab=rent') ?>">การเบิกเช่าล่าสุด</a>
  </span>
</div>

<?php if ($tab === 'list') { ?>
<form method="get" class="assets-filter-form lr-filter">
  <input type="hidden" name="tab" value="list">
  <label class="assets-ff assets-ff-code">
    <span>รหัสเครื่อง</span>
    <input type="text" name="q" value="<?= h($q) ?>" maxlength="<?= LEASING_REGISTRY_SN_MAX ?>" placeholder="ค้น S/N" autocomplete="off">
  </label>
  <label class="assets-ff">
    <span>รุ่น</span>
    <select name="model" onchange="this.form.submit()">
      <option value="">ทุกรุ่น</option>
      <?php foreach ($models['names'] as $nm) { ?>
      <option value="<?= h($nm) ?>" <?= $model === $nm ? 'selected' : '' ?>><?= h($nm) ?></option>
      <?php } ?>
    </select>
  </label>
  <label class="assets-ff">
    <span>สถานะ</span>
    <select name="status" onchange="this.form.submit()">
      <option value="">ทุกสถานะ</option>
      <?php
      $stMap = leasing_registry_status_map();
      foreach ($stMap as $sk => $sl) {
          $n = (int) ($counts['by'][$sk] ?? 0);
          if ($n === 0 && $status !== $sk) {
              continue;
          }
          ?>
      <option value="<?= h($sk) ?>" <?= $status === $sk ? 'selected' : '' ?>><?= h($sl) ?> (<?= number_format($n) ?>)</option>
      <?php } ?>
    </select>
  </label>
  <div class="assets-ff-actions">
    <button type="submit">ค้นหา</button>
    <?php if ($q !== '' || $model !== '' || $status !== '') { ?>
    <a href="<?= h($B . '/leasing_registry.php?tab=list') ?>" class="btn btn-line btn-sm">ล้างการค้นหา</a>
    <?php } ?>
  </div>
</form>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <thead>
  <tr>
    <th data-pri="2">ลงทะเบียนเมื่อ</th>
    <th data-pri="1">รหัสเครื่อง</th>
    <th data-pri="1">รุ่น</th>
    <th data-pri="1">สถานะ</th>
    <th data-pri="2">ผู้บันทึก</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$list['rows']) { ?>
  <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">ไม่พบเครื่องตามเงื่อนไขที่ค้นหา</td></tr>
  <?php } ?>
  <?php foreach ($list['rows'] as $r) {
      $sn = trim((string) ($r['pro_sn'] ?? ''));
      $pro = trim((string) ($r['pro_status'] ?? ''));
      ?>
  <tr>
    <td data-pri="2" class="muted"><?= h(dthai($r['pro_date'] ?? '')) ?></td>
    <td data-pri="1" data-nowrap><?= leasing_registry_sn_cell($sn, $local, $B) ?></td>
    <td data-pri="1"><?= h(trim((string) ($r['pro_name'] ?? ''))) ?></td>
    <td data-pri="1"><span class="badge asset-rent-status <?= h(rent_leasing_status_badge_class($pro, '')) ?>"><?= h(leasing_registry_status_label($pro)) ?></span></td>
    <td data-pri="2"><?= h(trim((string) ($r['pro_user_add'] ?? '')) ?: '—') ?></td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php
echo page_pager_html($list['page'], $list['pages'], $per, $list['total'], function ($n) {
    return leasing_registry_url(['page' => $n]);
}, 'เครื่อง');
?>

<?php } else { ?>

<div class="table-wrap table-wrap-fold">
<table class="list">
  <thead>
  <tr>
    <th data-pri="2">วันเริ่มสัญญา</th>
    <th data-pri="1">เลขที่ / PO</th>
    <th data-pri="1">ไซต์งาน</th>
    <th data-pri="2">ลูกค้า</th>
    <th data-pri="1">รหัสเครื่อง</th>
    <th data-pri="1">รุ่น</th>
    <th data-pri="2">ผู้เบิกเช่า</th>
  </tr>
  </thead>
  <tbody>
  <?php if (!$rentals['rows']) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">ยังไม่มีรายการเบิกเช่า</td></tr>
  <?php } ?>
  <?php foreach ($rentals['rows'] as $r) {
      $sn = trim((string) ($r['p_sn'] ?? ''));
      $cus = preg_replace('/\s+/u', ' ', trim((string) ($r['cus_name'] ?? '')));
      ?>
  <tr>
    <td data-pri="2" class="muted"><?= h(dthai($r['r_startdate'] ?? '')) ?></td>
    <td data-pri="1"><?= h(leasing_registry_contract_label((string) ($r['r_code'] ?? ''), (string) ($r['r_po'] ?? ''))) ?></td>
    <td data-pri="1"><?= h(trim((string) ($r['p_sitename'] ?? '')) ?: '—') ?></td>
    <td data-pri="2"><?= h($cus !== '' ? $cus : '—') ?></td>
    <td data-pri="1" data-nowrap><?= leasing_registry_sn_cell($sn, $local, $B) ?></td>
    <td data-pri="1"><?= h(trim((string) ($r['p_product'] ?? ''))) ?></td>
    <td data-pri="2"><?= h(trim((string) ($r['p_user_add'] ?? '')) ?: '—') ?></td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>
<?php
echo page_pager_html($rentals['page'], $rentals['pages'], $per, $rentals['total'], function ($n) use ($B) {
    return $B . '/leasing_registry.php?tab=rent&page=' . (int) $n;
}, 'รายการ');
?>

<?php } ?>

<style>
.lr-lead { margin: 0 0 14px; line-height: 1.85; }
.lr-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 720px) { .lr-stats { grid-template-columns: repeat(2, 1fr); } }
.lr-stat {
  background: var(--surface, #fff); border: 1px solid var(--border, #dde3ec);
  border-radius: 10px; padding: 12px 8px; text-align: center; text-decoration: none;
  color: inherit; display: block;
}
.lr-stat:hover { border-color: var(--primary); }
.lr-stat.is-on { box-shadow: inset 0 0 0 1.5px var(--primary); background: var(--primary-soft, #fce7f3); }
.lr-num { font-size: calc(22px * var(--font-scale, 1)); font-weight: 700; color: var(--primary); line-height: 1.2; }
.lr-lbl { font-size: calc(11px * var(--font-scale, 1)); margin-top: 2px; }
.lr-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px;
  flex-wrap: wrap; margin: 0 0 12px; }
.lr-tabs { display: inline-flex; gap: 2px; padding: 2px; background: var(--surface-2, #f1efe8);
  border-radius: 999px; }
.lr-tab { font-size: calc(13px * var(--font-scale, 1)); padding: 6px 14px; border-radius: 999px;
  text-decoration: none; color: var(--text-muted, #6b6480); }
.lr-tab:hover { color: var(--text, #2a2440); }
.lr-tab.is-on { background: var(--surface, #fff); color: var(--primary); font-weight: 600; }
.lr-filter { display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: flex-end; margin-bottom: 12px; }
.lr-filter .assets-ff { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.lr-filter .assets-ff > span:first-child {
  font-size: 12px; font-weight: 600; color: var(--text-muted, #6b6480); line-height: 1.2; white-space: nowrap;
}
.lr-filter .assets-ff input[type=text],
.lr-filter .assets-ff select,
.lr-filter .assets-ff-actions button,
.lr-filter .assets-ff-actions .btn { min-height: var(--input-h, 40px); }
.lr-filter .assets-ff select { min-width: 132px; max-width: 260px; }
.lr-filter .assets-ff-code { flex: 1 1 170px; min-width: 150px; max-width: 240px; }
.lr-filter .assets-ff-code input { width: 100%; }
.lr-filter .assets-ff-actions { display: flex; gap: 8px; align-items: center; }
.lr-sn { font-variant-numeric: tabular-nums; letter-spacing: .02em; }
.lr-ext { color: var(--text-muted, #6b6480); vertical-align: middle; }
.lr-ext:hover { color: var(--primary); }
.lr-ext svg { display: inline-block; vertical-align: -1px; }
</style>
<?php page_footer(); ?>
