<?php
/**
 * share_admin.php — หลังบ้าน: เปรียบเทียบ assets ↔ stock · ค้นหา · แก้ไข · ลบ · Sync
 *
 * รวม Import CSV · Sync แบบกลุ่ม · รายการที่ไม่ตรงกันระหว่างระบบหลักกับ biton_stockparts.stock
 * เข้าได้เฉพาะผู้ที่ปลดล็อกหน้าหลังบ้าน (PIN 9981 หรือ Tom)
 *
 * Flow: เปิดหน้า → ดูสถิติ/รายการต่าง → เลือก Sync / แก้ไข / ลบ ต่อรายการหรือหลายรายการ
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/settings_gate.php';
require_settings_access();

$DB = dbStock();

/** redirect กลับหน้านี้พร้อม query string */
function share_admin_redirect($qs = '') {
    header('Location: ' . BASE_URL . '/share_admin.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

/** บันทึกวันที่จากฟอร์มแก้ไข stock */
function share_admin_parse_ts($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $raw = str_replace('T', ' ', $raw);
    if (strlen($raw) === 16) {
        $raw .= ':00';
    }
    return $raw;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $backQs = isset($_POST['back']) ? (string)$_POST['back'] : '';

    if ($act === 'sync_one') {
        $sn = trim($_POST['serial'] ?? '');
        if (share_sync_one_serial($sn)) {
            flash_set("Sync $sn จากระบบหลักไป stock แล้ว");
        } else {
            flash_set("ไม่พบ $sn ในระบบหลัก — sync ไม่ได้", 'err');
        }
    } elseif ($act === 'sync_bulk') {
        $sns = (array)($_POST['serials'] ?? []);
        $ok = 0;
        foreach ($sns as $sn) {
            if (share_sync_one_serial($sn)) {
                $ok++;
            }
        }
        flash_set($ok > 0 ? "Sync จากระบบหลักไป stock แล้ว $ok รายการ" : 'ไม่มีรายการถูก sync', $ok > 0 ? 'ok' : 'err');
    } elseif ($act === 'sync_stock_one') {
        $sn = trim($_POST['serial'] ?? '');
        $r = share_sync_stock_one_serial($sn);
        if (!empty($r['ok'])) {
            flash_set("Sync $sn จาก stock ไประบบหลักแล้ว");
        } else {
            flash_set($r['error'] ?? "Sync $sn ไม่สำเร็จ", 'err');
        }
    } elseif ($act === 'sync_stock_bulk') {
        $sns = (array)($_POST['serials'] ?? []);
        $ok = 0;
        $fail = 0;
        $lastErr = '';
        foreach ($sns as $sn) {
            $r = share_sync_stock_one_serial($sn);
            if (!empty($r['ok'])) {
                $ok++;
            } else {
                $fail++;
                $lastErr = $r['error'] ?? '';
            }
        }
        if ($ok > 0) {
            $msg = "Sync จาก stock ไประบบหลักแล้ว $ok รายการ";
            if ($fail > 0) {
                $msg .= " · ไม่สำเร็จ $fail" . ($lastErr !== '' ? " ($lastErr)" : '');
            }
            flash_set($msg);
        } else {
            flash_set($fail > 0 ? ('ไม่มีรายการถูก sync' . ($lastErr !== '' ? " — $lastErr" : '')) : 'ไม่มีรายการถูก sync', 'err');
        }
    } elseif ($act === 'fill_made_by_bulk') {
        $sns = (array)($_POST['serials'] ?? []);
        $ok = 0;
        $skip = 0;
        $fail = 0;
        foreach ($sns as $sn) {
            $r = share_fill_asset_made_by_from_stock($sn);
            if (!empty($r['ok'])) {
                $ok++;
            } elseif (!empty($r['skip'])) {
                $skip++;
            } else {
                $fail++;
            }
        }
        flash_set($ok > 0 ? "เติมผู้ผลิตจาก stock แล้ว $ok รายการ" . ($skip ? " · ข้าม $skip" : '') : 'ไม่มีรายการถูกเติม', $ok > 0 ? 'ok' : 'err');
    } elseif ($act === 'fill_made_by_all') {
        $stats = share_fill_all_asset_made_by_from_stock();
        flash_set('เติมผู้ผลิตจาก stock แล้ว ' . number_format($stats['ok']) . ' รายการ'
            . ($stats['skip'] ? ' · ข้าม ' . number_format($stats['skip']) : '')
            . ($stats['fail'] ? ' · ไม่สำเร็จ ' . number_format($stats['fail']) : ''),
            $stats['ok'] > 0 ? 'ok' : 'err');
    } elseif ($act === 'delete_stock') {
        $sn = trim($_POST['serial'] ?? '');
        if ($sn !== '' && mb_strlen($sn) <= 80) {
            share_delete_asset($sn);
            flash_set("ลบ $sn ออกจาก stock แล้ว");
        }
    } elseif ($act === 'delete_stock_bulk') {
        $sns = (array)($_POST['serials'] ?? []);
        $n = 0;
        foreach ($sns as $sn) {
            $sn = trim((string)$sn);
            if ($sn === '' || mb_strlen($sn) > 80) {
                continue;
            }
            share_delete_asset($sn);
            $n++;
        }
        flash_set($n > 0 ? "ลบออกจาก stock แล้ว $n รายการ" : 'ไม่มีรายการถูกลบ', $n > 0 ? 'ok' : 'err');
    } elseif ($act === 'delete_asset') {
        $aid = (int)($_POST['asset_id'] ?? 0);
        $sn = trim($_POST['serial'] ?? '');
        if (asset_delete_full($aid)) {
            flash_set("ลบเครื่อง $sn และประวัติในระบบหลักแล้ว");
        } else {
            flash_set('ลบไม่สำเร็จ — ไม่พบเครื่องในระบบหลัก', 'err');
        }
    } elseif ($act === 'delete_asset_bulk') {
        $aids = (array)($_POST['asset_ids'] ?? []);
        $n = 0;
        foreach ($aids as $aid) {
            $aid = (int)$aid;
            if ($aid <= 0) {
                continue;
            }
            if (asset_delete_full($aid)) {
                $n++;
            }
        }
        flash_set($n > 0 ? "ลบออกจากระบบหลักแล้ว $n รายการ" : 'ไม่มีรายการถูกลบ', $n > 0 ? 'ok' : 'err');
    } elseif ($act === 'edit_stock') {
        $sn = trim($_POST['serial'] ?? '');
        $oldSn = trim($_POST['old_serial'] ?? $sn);
        if ($sn === '') {
            flash_set('ต้องกรอก Serial Number', 'err');
        } else {
            $ts = share_admin_parse_ts($_POST['timestamp'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $cname = trim($_POST['create_name'] ?? '');
            $batch = (int)($_POST['batch_id'] ?? 0);
            $setup = trim($_POST['setup_id'] ?? '');
            $setup = $setup !== '' ? (int)$setup : null;
            $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
            if ($cname === '') {
                flash_set('ต้องกรอกชื่อผู้บันทึก', 'err');
            } elseif ($sn !== $oldSn && qr('SELECT serial_number FROM stock WHERE serial_number=?', 's', [$sn], $DB)->fetch_assoc()) {
                flash_set("Serial \"$sn\" ซ้ำกับรายการอื่น", 'err');
            } else {
                q('UPDATE stock SET `timestamp`=?, serial_number=?, model=?, id=?, create_name=?, setup_id=?, active=? WHERE serial_number=?',
                  'sssisiis', [$ts, $sn, $model, $batch, $cname, $setup, $active, $oldSn], $DB);
                flash_set("บันทึกแก้ไข stock $sn แล้ว");
            }
        }
    } elseif ($act === 'sync_all' || $act === 'sync' || $act === 'refresh_meta') {
        if ($act === 'sync_all') {
            $r = share_sync_all_from_production();
            flash_set('ซิงก์ครบแล้ว — เพิ่ม ' . number_format($r['added']) . ' · อัปเดต ' . number_format($r['updated_meta'])
                . ' · เหลือไม่ครบ ' . number_format($r['incomplete_remaining']));
        } elseif ($act === 'sync') {
            $max = (int)$DB->query("SELECT COALESCE(MAX(id),0)+1 m FROM stock")->fetch_assoc()['m'] - 1;
            $DB->query("INSERT IGNORE INTO stock (`timestamp`, serial_number, model, id, create_name, setup_id, active)
                SELECT COALESCE(pr.last_dt, a.produced_at), a.asset_code, p.name,
                       $max + DENSE_RANK() OVER (ORDER BY COALESCE(pr.last_dt, a.produced_at), p.name, COALESCE(pr.last_made_by,'')),
                       COALESCE(pr.last_made_by, ''), NULL, 1
                FROM assets a JOIN products p ON p.id = a.product_id
                LEFT JOIN (SELECT asset_id,
                                  MAX(recorded_at) last_dt,
                                  SUBSTRING_INDEX(GROUP_CONCAT(made_by ORDER BY recorded_at DESC, id DESC SEPARATOR '||'), '||', 1) last_made_by
                           FROM production_records WHERE made_by IS NOT NULL AND TRIM(made_by)<>'' GROUP BY asset_id) pr ON pr.asset_id = a.id");
            flash_set('ดึงจากระบบแล้ว — เพิ่มใหม่ ' . $DB->affected_rows . ' รายการ');
        } else {
            $n = share_refresh_meta_from_production();
            flash_set('อัปเดตรุ่น/เวลา/ผู้ผลิตจากระบบผลิตแล้ว — แก้ไข ' . number_format($n) . ' รายการ');
        }
    }

    share_admin_redirect($backQs);
}

// CSV export รายการที่ไม่ตรงกัน
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $flt = $_GET['f'] ?? '';
    $search = trim($_GET['q'] ?? '');
    $list = share_reconcile_list($flt, $search, 1, 500000);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="assets_stock_diff.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['type', 'serial', 'asset_model', 'asset_timestamp', 'asset_made_by',
        'stock_model', 'stock_timestamp', 'stock_create_name', 'diff_fields']);
    foreach ($list['rows'] as $r) {
        fputcsv($out, [
            $r['type'], $r['serial'], $r['a_model'], $r['a_ts'], $r['a_name'],
            $r['s_model'], $r['s_ts'], $r['s_name'], implode(',', $r['diffs']),
        ]);
    }
    fclose($out);
    exit;
}

require __DIR__ . '/includes/layout.php';

$syncStats = share_sync_stats();
$reconcileIdx = share_reconcile_index();
$rc = $reconcileIdx['counts'];

$flt = $_GET['f'] ?? '';
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;
$list = share_reconcile_list($flt, $search, $page, $per);
$qs = http_build_query(array_filter(['f' => $flt ?: null, 'q' => $search ?: null, 'page' => $page > 1 ? $page : null]));

function rc_link($f, $label, $cur, $counts, $qsKeep) {
    $on = $cur === $f;
    $u = '?' . http_build_query(array_filter(array_merge($qsKeep, ['f' => $f ?: null])));
    return '<a href="' . h($u) . '" class="btn-sm ' . ($on ? '' : 'btn-line') . '" style="' . ($on ? 'background:var(--primary);color:#fff' : '') . '">' . $label . '</a>';
}
$qsKeep = ['q' => $search ?: null];

$typeLabels = [
    'assets_only' => 'มีแค่ระบบหลัก',
    'stock_only' => 'มีแค่ stock',
    'meta_diff' => 'ค่าไม่ตรงกัน',
];

page_header('หลังบ้าน — เปรียบเทียบ assets ↔ stock');
?>
<p class="muted" style="margin-bottom:12px">
  จัดการความต่างระหว่าง <b>ระบบหลัก (assets)</b> กับ <b>stock</b>
  · ดูรายการประจำวัน → <a href="<?= BASE_URL ?>/share.php">ทะเบียนสินค้า</a>
  · <a href="<?= BASE_URL ?>/settings.php">← กลับหน้าตั้งค่า</a>
</p>

<?php require __DIR__ . '/includes/sync_bar.php'; ?>

<div class="rc-stats">
  <div class="rc-stat"><div class="rc-num"><?= number_format($syncStats['assets']) ?></div><div class="muted rc-lbl">ระบบหลัก</div></div>
  <div class="rc-stat"><div class="rc-num" style="color:#2a7c4a"><?= number_format($syncStats['in_stock']) ?></div><div class="muted rc-lbl">stock</div></div>
  <div class="rc-stat"><div class="rc-num" style="color:#c0392b"><?= number_format($rc['assets_only']) ?></div><div class="muted rc-lbl">มีแค่ระบบหลัก</div></div>
  <div class="rc-stat"><div class="rc-num" style="color:#b45309"><?= number_format($rc['stock_only']) ?></div><div class="muted rc-lbl">มีแค่ stock</div></div>
  <div class="rc-stat"><div class="rc-num" style="color:#7b1fa2"><?= number_format($rc['meta_diff']) ?></div><div class="muted rc-lbl">ค่าไม่ตรงกัน</div></div>
  <div class="rc-stat"><div class="rc-num"><?= number_format($rc['total_diff']) ?></div><div class="muted rc-lbl">รวมที่ต่าง</div></div>
</div>

<details class="panel rc-tools" style="padding:12px 16px; margin-bottom:14px">
  <summary style="cursor:pointer; font-weight:600; color:var(--primary)">📥 Import / ซิงก์แบบกลุ่ม (ใช้ครั้งแรกหรือเติมข้อมูล)</summary>
  <div style="margin-top:12px; display:flex; flex-wrap:wrap; gap:8px">
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="sync_all"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <button type="submit" class="btn-line" onclick="return confirm('ซิงก์ครบทุกเครื่อง?')"><?= ui_btn_label('refresh', 'Sync ทั้งหมดจากระบบหลัก') ?></button>
    </form>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="sync"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <button type="submit" class="btn-line">ดึงเครื่องที่ขาดใน stock</button>
    </form>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="refresh_meta"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <button type="submit" class="btn-line">อัปเดตรุ่น/เวลาจากระบบ</button>
    </form>
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="act" value="fill_made_by_all"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <button type="submit" class="btn-line" onclick="return confirm('เติมชื่อผู้ผลิตในระบบหลักจาก stock ทุกเครื่องที่ยังว่าง?')">👤 เติมผู้ผลิตจาก stock → ระบบหลัก</button>
    </form>
  </div>
  <div id="import-basic" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border,#dde3ec)">
    <form method="post" action="<?= BASE_URL ?>/share.php" enctype="multipart/form-data" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="import_basic">
      <input type="hidden" name="redirect_page" value="share_admin">
      <input type="hidden" name="back" value="<?= h($qs) ?>">
      <span class="muted" style="font-size:13px">Import CSV:</span>
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <button type="submit">📥 Import stock</button>
      <a class="btn btn-line btn-sm" href="<?= BASE_URL ?>/share.php?template=basic">⬇ ตัวอย่าง CSV</a>
    </form>
    <form method="post" action="<?= BASE_URL ?>/share.php" enctype="multipart/form-data" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="import">
      <input type="hidden" name="redirect_page" value="share_admin">
      <input type="hidden" name="back" value="<?= h($qs) ?>">
      <span class="muted" style="font-size:13px">AppSheet:</span>
      <input type="file" name="csv" accept=".csv" required>
      <button type="submit" class="btn-line btn-sm">Import AppSheet</button>
    </form>
  </div>
</details>

<h2 style="margin:0 0 10px; font-size:17px">📋 รายการที่ไม่ตรงกัน</h2>

<form method="get" class="filter rc-search">
  <input type="text" name="q" data-scan="submit" value="<?= h($search) ?>" placeholder="ค้นหา serial / รุ่น / ชื่อ" style="min-width:220px">
  <input type="hidden" name="f" value="<?= h($flt) ?>">
  <button type="submit">ค้นหา</button>
  <?php if ($search !== '' || $flt !== '') { ?><a class="btn btn-line" href="<?= BASE_URL ?>/share_admin.php">ล้าง</a><?php } ?>
  <a class="btn btn-line btn-sm" href="<?= BASE_URL ?>/share_admin.php?<?= h(http_build_query(array_filter(['export' => 'csv', 'f' => $flt ?: null, 'q' => $search ?: null]))) ?>">⬇ ดาวน์โหลด CSV</a>
</form>

<div class="rc-chips">
  <?= rc_link('', 'ทั้งหมดที่ต่าง (' . number_format($rc['total_diff']) . ')', $flt, $rc, $qsKeep) ?>
  <?= rc_link('assets_only', 'มีแค่ระบบหลัก (' . number_format($rc['assets_only']) . ')', $flt, $rc, $qsKeep) ?>
  <?= rc_link('stock_only', 'มีแค่ stock (' . number_format($rc['stock_only']) . ')', $flt, $rc, $qsKeep) ?>
  <?= rc_link('meta_diff', 'ค่าไม่ตรงกัน (' . number_format($rc['meta_diff']) . ')', $flt, $rc, $qsKeep) ?>
</div>

<div class="rc-bulk" id="rc-bulk" hidden>
  <span>เลือกแล้ว <b id="rc-bulk-n">0</b> รายการ</span>
  <button type="button" class="btn btn-sm" id="rc-bulk-sync" disabled><?= ui_btn_label('refresh', 'Sync → stock', 13) ?></button>
  <button type="button" class="btn btn-sm" id="rc-bulk-sync-stock" disabled><?= ui_btn_label('refresh', 'Sync → ระบบหลัก', 13) ?></button>
  <button type="button" class="btn btn-sm btn-line" id="rc-bulk-fill-madeby" disabled>👤 เติมผู้ผลิต ← stock</button>
  <button type="button" class="btn btn-sm btn-danger" id="rc-bulk-del" disabled>🗑 ลบจาก stock</button>
  <button type="button" class="btn btn-sm btn-danger" id="rc-bulk-del-asset" disabled>🗑 ลบระบบหลัก</button>
  <button type="button" class="btn btn-sm btn-line" id="rc-bulk-clr">ยกเลิก</button>
</div>

<form method="post" id="rc-bulk-form" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="back" value="<?= h($qs) ?>">
  <input type="hidden" name="act" id="rc-bulk-act" value="sync_bulk">
  <div id="rc-bulk-hidden"></div>
</form>

<div class="table-wrap table-wrap-fold">
<table class="list" id="rc-table">
  <tr>
    <th style="width:32px"><input type="checkbox" id="rc-all" title="เลือกทั้งหน้า"></th>
    <th>ประเภท</th>
    <th>Serial</th>
    <th>รุ่น (ระบบ / stock)</th>
    <th>วันที่ (ระบบ / stock)</th>
    <th>ผู้ผลิต (ระบบ / stock)</th>
    <th style="width:200px">จัดการ</th>
  </tr>
  <?php foreach ($list['rows'] as $r) {
      $canSync = $r['type'] !== 'stock_only' && $r['asset_id'];
      $canSyncStock = $r['type'] === 'stock_only';
      $canFillMadeBy = $r['type'] === 'meta_diff'
          && in_array('create_name', $r['diffs'], true)
          && trim((string)$r['a_name']) === ''
          && trim((string)$r['s_name']) !== '';
      $badge = $typeLabels[$r['type']] ?? $r['type'];
      $badgeColor = $r['type'] === 'assets_only' ? '#c0392b' : ($r['type'] === 'stock_only' ? '#b45309' : '#7b1fa2');
      $fmtTs = function ($v) {
          return $v ? date('d/m/Y H:i', strtotime((string)$v)) : '-';
      };
  ?>
  <tr data-serial="<?= h($r['serial']) ?>">
    <td style="text-align:center">
      <input type="checkbox" class="rc-pick" value="<?= h($r['serial']) ?>" data-sync="<?= $canSync ? '1' : '0' ?>" data-sync-stock="<?= $canSyncStock ? '1' : '0' ?>" data-fill-madeby="<?= $canFillMadeBy ? '1' : '0' ?>" data-asset-id="<?= (int)($r['asset_id'] ?? 0) ?>">
    </td>
    <td><span class="badge" style="background:<?= $badgeColor ?>;color:#fff;font-size:11px"><?= h($badge) ?></span>
      <?php if ($r['diffs']) { ?><div class="muted" style="font-size:11px"><?= h(implode(', ', $r['diffs'])) ?></div><?php } ?>
    </td>
    <td><b><?= h($r['serial']) ?></b>
      <?php if ($r['asset_id']) { ?><div><a href="<?= BASE_URL ?>/asset.php?id=<?= (int)$r['asset_id'] ?>" class="muted" style="font-size:11px">เปิดในระบบหลัก</a></div><?php } ?>
    </td>
    <td style="font-size:12px">
      <span title="ระบบหลัก"><?= h($r['a_model'] ?: '-') ?></span>
      <?php if ($r['type'] !== 'assets_only') { ?><br><span class="muted" title="stock"><?= h($r['s_model'] ?: '-') ?></span><?php } ?>
    </td>
    <td style="font-size:12px; white-space:nowrap">
      <?= $fmtTs($r['a_ts']) ?>
      <?php if ($r['type'] !== 'assets_only') { ?><br><span class="muted"><?= $fmtTs($r['s_ts']) ?></span><?php } ?>
    </td>
    <td style="font-size:12px">
      <?= h($r['a_name'] ?: '-') ?>
      <?php if ($r['type'] !== 'assets_only') { ?><br><span class="muted"><?= h($r['s_name'] ?: '-') ?></span><?php } ?>
    </td>
    <td>
      <?php if ($canSync) { ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="sync_one"><input type="hidden" name="serial" value="<?= h($r['serial']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
        <button type="submit" class="btn-sm btn-line" title="ดึงค่าจากระบบหลักไป stock"><?= ui_btn_label('refresh', 'Sync', 13) ?></button>
      </form>
      <?php } ?>
      <?php if ($canSyncStock) { ?>
      <form method="post" style="display:inline">
        <?= csrf_field() ?><input type="hidden" name="act" value="sync_stock_one"><input type="hidden" name="serial" value="<?= h($r['serial']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
        <button type="submit" class="btn-sm btn-line" title="สร้างเครื่องในระบบหลักจากข้อมูล stock"><?= ui_btn_label('refresh', 'Sync → ระบบหลัก', 13) ?></button>
      </form>
      <?php } ?>
      <?php if ($r['type'] !== 'assets_only') { ?>
      <details>
        <summary class="btn btn-sm btn-line" style="list-style:none;cursor:pointer;display:inline-block">แก้ไข stock</summary>
        <form method="post" style="margin-top:6px;display:grid;gap:4px;min-width:200px">
          <?= csrf_field() ?><input type="hidden" name="act" value="edit_stock"><input type="hidden" name="old_serial" value="<?= h($r['serial']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
          <input type="text" name="serial" value="<?= h($r['serial']) ?>" required placeholder="Serial">
          <input type="text" name="model" value="<?= h($r['s_model'] ?? '') ?>" placeholder="รุ่น">
          <input type="datetime-local" name="timestamp" value="<?= $r['s_ts'] ? h(date('Y-m-d\TH:i', strtotime((string)$r['s_ts']))) : '' ?>">
          <input type="text" name="create_name" value="<?= h($r['s_name'] ?? '') ?>" required placeholder="ผู้บันทึก">
          <input type="number" name="batch_id" value="<?= h((string)($r['s_batch'] ?? '')) ?>" min="1" placeholder="id ชุด">
          <input type="number" name="setup_id" value="" placeholder="Setup ID">
          <select name="active"><option value="1" <?= (int)($r['s_active'] ?? 1) === 1 ? 'selected' : '' ?>>active 1</option><option value="0" <?= (int)($r['s_active'] ?? 1) === 0 ? 'selected' : '' ?>>active 0</option></select>
          <button type="submit" class="btn-sm"><?= ui_btn_label('save', 'บันทึก', 13) ?></button>
        </form>
      </details>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบ <?= h($r['serial']) ?> ออกจาก stock?')">
        <?= csrf_field() ?><input type="hidden" name="act" value="delete_stock"><input type="hidden" name="serial" value="<?= h($r['serial']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
        <button type="submit" class="btn-sm btn-danger">ลบ stock</button>
      </form>
      <?php } ?>
      <?php if ($r['type'] === 'assets_only' && $r['asset_id']) { ?>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบเครื่อง <?= h($r['serial']) ?> และประวัติทั้งหมดในระบบหลัก?')">
        <?= csrf_field() ?><input type="hidden" name="act" value="delete_asset"><input type="hidden" name="asset_id" value="<?= (int)$r['asset_id'] ?>"><input type="hidden" name="serial" value="<?= h($r['serial']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
        <button type="submit" class="btn-sm btn-danger">ลบระบบหลัก</button>
      </form>
      <?php } ?>
    </td>
  </tr>
  <?php } ?>
  <?php if (!$list['rows']) { ?>
  <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">✅ ไม่พบรายการที่ต่างกัน<?= $flt || $search ? ' (ตามฟิลเตอร์)' : '' ?></td></tr>
  <?php } ?>
</table>
</div>

<?php
$pagerBase = BASE_URL . '/share_admin.php?' . http_build_query(array_filter(['f' => $flt ?: null, 'q' => $search ?: null]));
echo page_pager_html($list['page'], $list['pages'], $per, $list['total'], function ($n) use ($pagerBase) {
    return $pagerBase . ($pagerBase[strlen($pagerBase) - 1] === '?' ? '' : '&') . 'page=' . $n;
}, 'รายการ');
?>

<style>
.rc-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; margin:14px 0; }
.rc-stat { background:var(--surface,#fff); border:1px solid var(--border,#dde3ec); border-radius:10px; padding:10px 8px; text-align:center; }
.rc-num { font-size:20px; font-weight:700; color:var(--primary); }
.rc-lbl { font-size:11px; }
.rc-tools summary { user-select:none; }
.rc-search { margin-bottom:8px; }
.rc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
.rc-bulk { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:8px; padding:8px 12px; background:#f0f6ff; border:1px solid #b8d4f0; border-radius:8px; }
.rc-bulk[hidden] { display:none !important; }
#rc-table .rc-pick { width:15px; height:15px; accent-color:var(--primary); }
.sync-bar { margin:0 0 14px; padding:10px 14px; background:var(--surface,#fff); border:1px solid var(--border,#dde3ec); border-radius:10px; flex-wrap:wrap; }
</style>
<script>
(function(){
  var allCb = document.getElementById('rc-all');
  var bar = document.getElementById('rc-bulk');
  var cnt = document.getElementById('rc-bulk-n');
  var syncBtn = document.getElementById('rc-bulk-sync');
  var syncStockBtn = document.getElementById('rc-bulk-sync-stock');
  var fillMadeByBtn = document.getElementById('rc-bulk-fill-madeby');
  var delBtn = document.getElementById('rc-bulk-del');
  var delAssetBtn = document.getElementById('rc-bulk-del-asset');
  var clrBtn = document.getElementById('rc-bulk-clr');
  var form = document.getElementById('rc-bulk-form');
  var actInp = document.getElementById('rc-bulk-act');
  var hidden = document.getElementById('rc-bulk-hidden');

  function picks(){ return Array.prototype.slice.call(document.querySelectorAll('.rc-pick:checked')); }
  function refresh(){
    var arr = picks();
    var n = arr.length;
    if (bar) bar.hidden = n === 0;
    if (cnt) cnt.textContent = String(n);
    var syncable = arr.filter(function(cb){ return cb.dataset.sync === '1'; }).length;
    var syncableStock = arr.filter(function(cb){ return cb.dataset.syncStock === '1'; }).length;
    var fillableMadeBy = arr.filter(function(cb){ return cb.dataset.fillMadeby === '1'; }).length;
    var deletableAsset = arr.filter(function(cb){ return parseInt(cb.dataset.assetId, 10) > 0; }).length;
    if (syncBtn) syncBtn.disabled = syncable === 0;
    if (syncStockBtn) syncStockBtn.disabled = syncableStock === 0;
    if (fillMadeByBtn) fillMadeByBtn.disabled = fillableMadeBy === 0;
    if (delBtn) delBtn.disabled = n === 0;
    if (delAssetBtn) delAssetBtn.disabled = deletableAsset === 0;
    var total = document.querySelectorAll('.rc-pick').length;
    if (allCb) { allCb.indeterminate = n > 0 && n < total; allCb.checked = total > 0 && n === total; }
  }
  if (allCb) allCb.addEventListener('change', function(){
    document.querySelectorAll('.rc-pick').forEach(function(cb){ cb.checked = allCb.checked; });
    refresh();
  });
  document.addEventListener('change', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('rc-pick')) refresh();
  });
  if (clrBtn) clrBtn.addEventListener('click', function(){
    document.querySelectorAll('.rc-pick').forEach(function(cb){ cb.checked = false; });
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    refresh();
  });
  function submitBulk(act, msg, filterFn, fieldName){
    var sel = picks();
    if (filterFn) sel = sel.filter(filterFn);
    if (!sel.length) return;
    if (!confirm(msg.replace('{n}', String(sel.length)))) return;
    hidden.innerHTML = '';
    sel.forEach(function(cb){
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = fieldName || 'serials[]';
      inp.value = fieldName === 'asset_ids[]' ? cb.dataset.assetId : cb.value;
      hidden.appendChild(inp);
    });
    actInp.value = act;
    form.submit();
  }
  if (syncBtn) syncBtn.addEventListener('click', function(){
    submitBulk('sync_bulk', 'Sync {n} รายการจากระบบหลักไป stock?', function(cb){ return cb.dataset.sync === '1'; });
  });
  if (syncStockBtn) syncStockBtn.addEventListener('click', function(){
    submitBulk('sync_stock_bulk', 'Sync {n} รายการจาก stock ไปสร้างในระบบหลัก?', function(cb){ return cb.dataset.syncStock === '1'; });
  });
  if (fillMadeByBtn) fillMadeByBtn.addEventListener('click', function(){
    submitBulk('fill_made_by_bulk', 'เติมชื่อผู้ผลิต {n} รายการจาก stock ไประบบหลัก?', function(cb){ return cb.dataset.fillMadeby === '1'; });
  });
  if (delBtn) delBtn.addEventListener('click', function(){
    submitBulk('delete_stock_bulk', 'ลบ {n} รายการออกจาก stock?');
  });
  if (delAssetBtn) delAssetBtn.addEventListener('click', function(){
    submitBulk('delete_asset_bulk', 'ลบ {n} เครื่องและประวัติทั้งหมดในระบบหลัก?', function(cb){
      return parseInt(cb.dataset.assetId, 10) > 0;
    }, 'asset_ids[]');
  });
  refresh();
})();
</script>
<?php page_footer(); ?>
