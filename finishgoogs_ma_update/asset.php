<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/asset_production_edit.php';
require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/rent_retire_sync.php';
require_login();

// เปิดด้วย id หรือ code (จากการสแกน QR)
if (isset($_GET['code'])) {
    $a0 = qr("SELECT id FROM assets WHERE asset_code=? OR factory_serial=?", 'ss', [trim($_GET['code']), trim($_GET['code'])])->fetch_assoc();
    if ($a0) { header('Location: ' . BASE_URL . '/asset.php?id=' . $a0['id']); exit; }
    flash_set('ไม่พบเครื่องรหัส "' . $_GET['code'] . '"', 'err');
    header('Location: ' . BASE_URL . '/assets.php'); exit;
}
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$a = qr("SELECT a.*, p.name pname, p.icon_path
         FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$id])->fetch_assoc();
if (!$a) { http_response_code(404); exit('ไม่พบเครื่องนี้'); }

try {
    asset_status_sync_one($id, true);
    $a = qr("SELECT a.*, p.name pname, p.icon_path
             FROM assets a JOIN products p ON p.id=a.product_id WHERE a.id=?", 'i', [$id])->fetch_assoc();
} catch (Throwable $e) {
    error_log('[asset_status_sync_one] ' . $e->getMessage());
}

// เปลี่ยนสถานะเครื่อง
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_status'])) {
    csrf_check();
    $ns = $_POST['set_status'];
    if (in_array($ns, status_list(), true)) {
        q("UPDATE assets SET status=? WHERE id=?", 'si', [$ns, $id]);
        q("INSERT INTO stock_movements (asset_id,moved_at,direction,reason,made_by) VALUES (?,NOW(),?,?,?)",
          'isss', [$id, $ns === 'new' ? 'in' : 'out', 'เปลี่ยนสถานะเป็น ' . status_th($ns), actor_name()]);
        flash_set('เปลี่ยนสถานะเป็น "' . status_th($ns) . '" เรียบร้อยแล้ว');
    }
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ซิงก์สถานะเสื่อมสภาพกับระบบเช่า (เครื่องที่ลง MA ไว้แล้วแต่สองระบบไม่ตรงกัน)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retire_sync'])) {
    csrf_check();
    $rs = rent_retire_sync_one($id);
    flash_set($rs['message'], $rs['ok'] ? 'ok' : 'err');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}
// แก้ไข FW ปัจจุบันของเครื่อง (แก้ค่าผิด/อัปเดตโดยตรง)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_fw'])) {
    csrf_check();
    $fw = trim($_POST['current_fw_version']);
    if (strlen($fw) > 50) { flash_set('FW ยาวเกิน 50 ตัวอักษร', 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    q("UPDATE assets SET current_fw_version=NULLIF(?,'') WHERE id=?", 'si', [$fw, $id]);
    flash_set($fw !== '' ? 'บันทึก FW เป็น "' . $fw . '" แล้ว' : 'ล้างค่า FW แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// แก้ไขข้อมูลเครื่อง (บันทึกผิด/พิมพ์ผิด)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_asset'])) {
    csrf_check();
    $newCode = trim($_POST['asset_code']);
    $newPid  = (int)$_POST['product_id'];
    if ($newCode === '') { flash_set('รหัสเครื่องห้ามว่าง', 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    $dup = qr("SELECT id FROM assets WHERE asset_code=? AND id<>?", 'si', [$newCode, $id])->fetch_assoc();
    if ($dup) { flash_set("รหัส $newCode ถูกใช้กับเครื่องอื่นแล้ว", 'err'); header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit; }
    $np = qr("SELECT code_mode, code_prefix, running_digits, code_use_prefix, code_use_year, code_use_month FROM products WHERE id=?", 'i', [$newPid])->fetch_assoc();
    if (!$np) {
        flash_set('ไม่พบรุ่นสินค้าที่เลือก กรุณาเลือกรุ่นใหม่', 'err');
        header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
        exit;
    }
    // คำนวณเลขรันนิ่งใหม่จากรหัส (ถ้ารุ่นเป็นแบบ gen และรหัสเข้าสูตร)
    $run = null;
    if ($np['code_mode'] === 'generated') {
        $run = parse_running_from_asset_code($np, $newCode);
    }
    q("UPDATE assets SET asset_code=?, factory_serial=?, product_id=?, running_no=?, produced_at=NULLIF(?,''), lot_label=NULLIF(?,''), note=NULLIF(?,'') WHERE id=?",
      'ssiisssi', [$newCode, $newCode, $newPid, $run, $_POST['produced_at'], trim($_POST['lot_label'] ?? ''), trim($_POST['note'] ?? ''), $id]);
    $newStatus = isset($_POST['asset_status']) ? (string)$_POST['asset_status'] : $a['status'];
    if (in_array($newStatus, status_list(), true) && $newStatus !== $a['status']) {
        q("UPDATE assets SET status=? WHERE id=?", 'si', [$newStatus, $id]);
        q("INSERT INTO stock_movements (asset_id,moved_at,direction,reason,made_by) VALUES (?,NOW(),?,?,?)",
          'isss', [$id, $newStatus === 'new' ? 'in' : 'out', 'เปลี่ยนสถานะเป็น ' . status_th($newStatus), actor_name()]);
    }
    asset_production_edit_save($id, $newPid, $_POST);
    share_upsert_asset($id, $a['asset_code']); // sync ตารางแชร์ (ถ้าเปลี่ยนรหัส แถว serial เดิมจะถูกแทนที่)
    flash_set('แก้ไขข้อมูลเครื่อง ' . $newCode . ' เรียบร้อยแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// แก้ไข/ลบชิ้นส่วนฮาร์ดแวร์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_component'])) {
    csrf_check();
    $oldName = trim($_POST['old_name']);
    $newName = trim($_POST['component_name']);
    $newVal  = trim($_POST['component_value']);
    if ($oldName !== '' && $newName !== '') {
        q("DELETE FROM asset_components WHERE asset_id=? AND component_name=?", 'is', [$id, $oldName]);
        q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)", 'iss', [$id, $newName, $newVal]);
        flash_set('บันทึกชิ้นส่วนแล้ว');
    }
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_component'])) {
    csrf_check();
    $nm = trim($_POST['component_name']);
    if ($nm !== '') q("DELETE FROM asset_components WHERE asset_id=? AND component_name=?", 'is', [$id, $nm]);
    flash_set('ลบชิ้นส่วนแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบบันทึกผลิต
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_production'])) {
    csrf_check();
    $prId = (int)$_POST['record_id'];
    q("DELETE FROM production_records WHERE id=? AND asset_id=?", 'ii', [$prId, $id]);
    recompute_asset_fw($id); // บันทึกผลิตเป็นแหล่ง FW ด้วย ต้องคำนวณใหม่หลังลบ
    flash_set('ลบบันทึกผลิตแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการอัปเดต FW/HW
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_update'])) {
    csrf_check();
    $uid = (int)$_POST['record_id'];
    q("DELETE FROM update_logs WHERE id=? AND asset_id=?", 'ii', [$uid, $id]);
    recompute_asset_fw($id);
    flash_set('ลบรายการอัปเดตแล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการเบิกอะไหล่
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_part_move'])) {
    csrf_check();
    $mid = (int)$_POST['record_id'];
    $old = qr("SELECT id FROM part_movements WHERE id=? AND ref_asset_id=? AND direction='out'", 'ii', [$mid, $id])->fetch_assoc();
    if ($old) {
        $del = production_delete_out_movement_with_stock($mid, actor_name());
        if (!$del['ok']) {
            flash_set($del['error'] ?? 'ลบไม่สำเร็จ', 'err');
            header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
            exit;
        }
    }
    flash_set('ลบรายการเบิกอะไหล่แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบรายการ MA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_ma_asset'])) {
    csrf_check();
    $mid = (int)$_POST['record_id'];
    if (ma_withdrawal_count($mid) > 0) {
        $rb = ma_rollback_withdrawals($mid, actor_name());
        if (!$rb['ok']) {
            flash_set($rb['error'], 'err');
            header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
            exit;
        }
    }
    q("DELETE FROM ma_records WHERE id=? AND asset_id=?", 'ii', [$mid, $id]);
    recompute_asset_status_from_ma($id);
    recompute_asset_fw($id);
    flash_set('ลบรายการ MA แล้ว');
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id); exit;
}

// ลบเครื่อง (ลบประวัติทั้งหมดของเครื่องนี้ด้วย)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_asset'])) {
    csrf_check();
    $delCode = $a['asset_code'];
    if (!asset_delete_full($id)) {
        flash_set('ลบเครื่องไม่สำเร็จ', 'err');
        header('Location: ' . BASE_URL . '/asset.php?id=' . $id);
        exit;
    }
    flash_set("ลบเครื่อง $delCode และประวัติทั้งหมดเรียบร้อยแล้ว");
    header('Location: ' . BASE_URL . '/assets.php'); exit;
}

// Sync ตามรายการเบิก — ผูก/สร้าง stock_out + ลบใบเบิกซ้ำ/เกินใน Parts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_withdraw_list'])) {
    csrf_check();
    $sync = asset_reconcile_withdraw_list_to_stock($id, actor_name());
    if ($sync['ok']) {
        $msg = $sync['message'] ?? 'Sync ตามรายการเบิกสำเร็จ';
        if (!empty($sync['errors'])) {
            $msg .= ' (มี ' . count($sync['errors']) . ' รายการข้าม/ผิดพลาด)';
        }
        flash_set($msg);
    } else {
        $err = $sync['errors'][0] ?? ($sync['message'] ?? 'Sync รายการเบิกไม่สำเร็จ');
        flash_set($err, 'err');
    }
    header('Location: ' . BASE_URL . '/asset.php?id=' . $id . '#parts-withdraw');
    exit;
}

$components = qr("SELECT component_name, component_value FROM asset_components WHERE asset_id=? ORDER BY component_name", 'i', [$id]);
$componentRows = [];
while ($row = $components->fetch_assoc()) {
    $componentRows[] = $row;
}
$productList = [];
$resProd = qr("SELECT id, name FROM products WHERE is_active=1 ORDER BY name");
while ($po = $resProd->fetch_assoc()) {
    $productList[] = $po;
}
$assetEditCtx = asset_production_edit_load($id, (int)$a['product_id'], $componentRows, $a);

/** ตัวเลือก FW ที่เคยใช้กับรุ่นนี้ (สำหรับ datalist) */
$fwSuggest = [];
if (product_show_fw((int)$a['product_id'])) {
    $fwSuggest = effective_fw_options((int)$a['product_id']);
}
if ($a['current_fw_version'] && !in_array($a['current_fw_version'], $fwSuggest, true)) {
    array_unshift($fwSuggest, $a['current_fw_version']);
}

// แจ้งเตือนอะไหล่ครบกำหนดเปลี่ยน (ฟังก์ชันกลางใน config.php — ใช้ร่วมกับฟอร์ม MA)
$partAlertsHtml = part_alerts_html($id, $a['produced_at']);

// รวม timeline ทุกประเภท (ฟังก์ชันกลาง includes/timeline.php — ใช้ร่วมกับ popup เจาะลึกจาก dashboard)
require __DIR__ . "/includes/timeline.php";
require __DIR__ . "/includes/list_search.php";
require_once __DIR__ . '/includes/stockparts_withdraw.php';
require_once __DIR__ . '/includes/rent_ma_bridge.php';
require_once __DIR__ . '/includes/maintenance_repair_bridge.php';
require __DIR__ . '/includes/ma_snippets.php';
$tlData = asset_timeline_items($id);
$tl = $tlData["tl"];
$partsUsed = $tlData["partsUsed"];
$partsSummary = asset_parts_withdraw_summary($id);
$showPartsWithdraw = !empty($partsSummary['show_section']);
// มีตารางอะไหล่ด้านบนแล้ว — ไม่แสดงคอลัมน์เบิกอะไหล่ซ้ำใน timeline
if ($showPartsWithdraw && ($partsSummary['out_count'] > 0 || $partsUsed)) {
    $tl = timeline_exclude_groups($tl, ['parts']);
}

require_once __DIR__ . '/includes/setup_sale_history.php';
$stockWithdraw = asset_stockparts_withdraw_info((string) $a['asset_code']);
$leaseInfo = asset_leasing_info(
    (string) ($a['asset_code'] ?? ''),
    (string) ($a['factory_serial'] ?? '')
);
// ประวัติงาน MA ฝั่งระบบเช่าไปรวมใน timeline board เป็นคอลัมน์ของตัวเอง
// ใช้ข้อมูลที่ asset_leasing_info() โหลดมาแล้ว ไม่ query ซ้ำ
$tl = array_merge($tl, rent_leasing_ma_timeline_items($leaseInfo));

// ประวัติซ่อมจากระบบ MA — อ่านอย่างเดียว ต่อฐานไม่ได้ก็ไม่ล้มทั้งหน้า
$maRepairInfo = asset_maintenance_info((string) $a['asset_code']);
// ปุ่มเพิ่มรายการเบิกย้ายไปอยู่แถบเครื่องมือ จึงต้องรู้ค่าพวกนี้ตั้งแต่ก่อนวาดแถบ
$assetPartsBackUrl = urlencode(BASE_URL . '/asset.php?id=' . $id . '#parts-withdraw');
$addWithdrawModalUrl = BASE_URL . '/parts.php?ajax=add_move_form&asset_id=' . (int)$id
    . '&back=' . $assetPartsBackUrl;
// ไม่มีรายการเบิกเลย = ไม่ต้องมีข้อความเรื่องอะไหล่บนหน้านี้สักบรรทัด
$hasPartsRows = $showPartsWithdraw
    && (((int)($partsSummary['out_count'] ?? 0) > 0) || !empty($partsUsed));

$assetBackHref = page_back_url('');

$assetShowSnippets = product_show_snippets((int)$a['product_id']);
$assetSnippetPayload = [
    'code' => (string)$a['asset_code'],
    'replace' => '',
    'repair' => '',
    'fw' => trim((string)($a['current_fw_version'] ?? '')),
    'remark' => trim((string)($a['note'] ?? '')),
];
foreach ($tl as $e) {
    if (($e['kind'] ?? '') === 'ma' && !empty($e['snippet']) && is_array($e['snippet'])) {
        $assetSnippetPayload = array_merge($assetSnippetPayload, $e['snippet']);
        $assetSnippetPayload['code'] = (string)$a['asset_code'];
        break;
    }
}

/**
 * ปุ่มแก้ไข/ลบ/คำสั่งตั้งค่าหมายเลขสินค้า ในประวัติ timeline
 *
 * ปุ่มคำสั่งต้องเคารพสวิตช์หลังบ้านของรุ่นนั้น — ฟังก์ชันนี้อยู่นอก scope จึงมองไม่เห็น
 * $assetShowSnippets ที่คำนวณไว้ด้านบน ต้องอ่านผ่าน global ไม่งั้นปุ่มจะโผล่ทุกรุ่น
 * แม้หลังบ้านจะไม่ได้ติ๊กเปิดไว้
 */
function asset_tl_actions($e, $assetId) {
    global $assetShowSnippets;
    if (empty($e['kind']) || empty($e['rid'])) return '';
    $id = (int)$e['rid'];
    $out = '<div class="tl-actions" style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap">';
    $snippetBtn = (!empty($assetShowSnippets) && !empty($e['snippet']) && is_array($e['snippet']))
        ? ma_snippet_open_button($e['snippet'])
        : '';
    if ($e['kind'] === 'update') {
        $back = urlencode(BASE_URL . '/asset.php?id=' . $assetId);
        $out .= '<a class="btn btn-sm btn-line btn-with-icon" href="' . BASE_URL . '/update_edit.php?id=' . $id . '&back=' . $back . '">' . ui_btn_label('edit', 'แก้ไข') . '</a>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_update" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger btn-with-icon" type="submit">' . ui_icon_html('trash', 16, 'btn-svg') . '<span>ลบ</span></button></form>';
    } elseif ($e['kind'] === 'ma') {
        $out .= '<a class="btn btn-sm btn-line btn-with-icon" href="' . BASE_URL . '/ma.php?edit=' . $id . '">' . ui_btn_label('edit', 'แก้ไข') . '</a>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการ MA นี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_ma_asset" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger btn-with-icon" type="submit">' . ui_icon_html('trash', 16, 'btn-svg') . '<span>ลบ</span></button></form>';
    } elseif ($e['kind'] === 'part_move') {
        $out .= '<button class="btn btn-sm btn-line btn-with-icon" onclick="showListModal(' . h(json_encode('แก้ไขรายการเบิก', JSON_UNESCAPED_UNICODE)) . ',' . h(json_encode(BASE_URL . '/parts.php?ajax=edit_move_form&id=' . $id . '&back=' . urlencode(BASE_URL . '/asset.php?id=' . $assetId))) . ',\'\')">' . ui_btn_label('edit', 'แก้ไข') . '</button>';
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบรายการเบิกนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_part_move" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger btn-with-icon" type="submit">' . ui_icon_html('trash', 16, 'btn-svg') . '<span>ลบ</span></button></form>';
    } elseif ($e['kind'] === 'production') {
        $out .= '<form method="post" style="display:inline" onsubmit="return confirm(\'ลบบันทึกผลิตนี้?\')">' . csrf_field()
              . '<input type="hidden" name="del_production" value="1"><input type="hidden" name="record_id" value="' . $id . '">'
              . '<button class="btn-sm btn-danger btn-with-icon" type="submit">' . ui_icon_html('trash', 16, 'btn-svg') . '<span>ลบ</span></button></form>';
    }
    return $out . $snippetBtn . '</div>';
}

page_header('เครื่อง ' . $a['asset_code'], false);
?>
<div class="asset-toolbar">
  <?= page_back_button_html($assetBackHref) ?>
  <h1 class="asset-toolbar-title">เครื่อง <?= h($a['asset_code']) ?></h1>
  <?php // มือถือ (แบบ ข): เหลือปุ่มบันทึก MA กับปุ่ม "อื่นๆ" ที่เปิดคำสั่งที่เหลือ — เดิม 5 ปุ่มใหญ่กินเกือบ 1/3 จอ
        // จอใหญ่ .asset-more-menu เป็น display:contents ปุ่มเรียงแถวเดียวเหมือนเดิม ?>
  <div class="asset-toolbar-actions">
    <a class="btn btn-sm btn-with-icon asset-act-main" href="<?= BASE_URL ?>/ma.php?record=<?= $id ?>"><?= ui_btn_label('ma', 'บันทึก MA') ?></a>
    <button type="button" class="btn btn-sm btn-line asset-more-btn" aria-expanded="false" aria-controls="asset-more-menu">อื่นๆ ▾</button>
    <div class="asset-more-menu" id="asset-more-menu">
    <a class="btn btn-sm btn-with-icon asset-act-update" href="<?= BASE_URL ?>/update_new.php?asset=<?= $id ?>"><?= ui_btn_label('updates', 'บันทึกอัปเดต FW/HW') ?></a>
    <?php if ($assetShowSnippets) { ?>
    <button type="button" class="btn btn-sm btn-with-icon asset-snippet-open"<?= ma_snippet_data_attrs($assetSnippetPayload) ?>><?= ui_btn_label('clipboard', ma_snippets_title(false)) ?></button>
    <?php } ?>
  <?php if ($showPartsWithdraw) { ?>
      <button type="button" class="btn btn-sm btn-line btn-with-icon"
        onclick="showListModal(<?= h(json_encode('เพิ่มรายการเบิก — ' . $a['asset_code'], JSON_UNESCAPED_UNICODE)) ?>,<?= h(json_encode($addWithdrawModalUrl)) ?>,'')">
        <?= ui_btn_label('stock-out-item', 'เพิ่มรายการเบิก') ?>
      </button>
      <?php } ?>

      <button type="button" class="btn btn-sm btn-line btn-with-icon asset-edit-toggle"
        aria-expanded="false" aria-controls="asset-edit-details"><?= ui_btn_label('edit', 'แก้ไขเครื่อง') ?></button>
    </div>
      </div>
</div>
<script>
(function(){
  var btn = document.querySelector('.asset-more-btn');
  var menu = document.getElementById('asset-more-menu');
  if (!btn || !menu) return;
  function set(open){ menu.classList.toggle('is-open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  btn.addEventListener('click', function(e){ e.stopPropagation(); set(!menu.classList.contains('is-open')); });
  // เลือกคำสั่งแล้วปิดเมนู · แตะนอกเมนูก็ปิด
  menu.addEventListener('click', function(){ set(false); });
  document.addEventListener('click', function(e){ if (!menu.contains(e.target)) set(false); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') set(false); });
})();
</script>

<div class="asset-head">
  <div class="asset-head-photo"><?= img_tag($a['icon_path'], $a['pname'], 'thumb-lg') ?></div>
  <div class="info asset-head-main">
    <dl class="asset-head-dl">
      <?= asset_head_dl_html($a, $assetEditCtx, $componentRows) ?>
    </dl>
  </div>
  <?php if (!empty($leaseInfo['found'])) { ?>
  <?= asset_leasing_card_html($leaseInfo, rent_retire_sync_asset_box_html($a, $leaseInfo)) ?>
  <?php } else { ?>
  <?= asset_stockparts_withdraw_card_html($stockWithdraw) ?>
  <?php } ?>
</div>
<?php
// ใบเบิกผลิตจาก inventory ที่เครื่องนี้ถูกตัดยอด (ตารางมีเมื่อเปิดใช้ฟีเจอร์ใบเบิกแล้ว)
require_once __DIR__ . '/includes/inv_pickup.php';
$invAlloc = inv_pickup_for_asset($id);
if ($invAlloc) { ?>
<p class="muted asset-inv-note">ผลิตจากใบเบิก inventory <a href="<?= BASE_URL ?>/inv_pickups.php?pre=<?= rawurlencode($invAlloc['pre_id']) ?>&amp;grp=<?= h($invAlloc['grp']) ?>"><?= h($invAlloc['pre_id']) ?></a> · <?= $invAlloc['source'] === 'manual' ? 'ตัดยอดเอง' : 'ตัดยอดอัตโนมัติ' ?> โดย <?= h((string) $invAlloc['allocated_by']) ?></p>
<?php } ?>

<?php if ($partAlertsHtml) { ?>
<div style="margin-bottom:16px"><?= $partAlertsHtml ?></div>
<?php } ?>

<details id="asset-edit-details" style="margin-bottom:16px" class="asset-edit-details">
  <?php // ปุ่มเดิมถูกย้ายขึ้นไปบนแถบเครื่องมือแล้ว — ซ่อน summary ไว้เพราะ
         // <details> ที่ไม่มี summary เบราว์เซอร์จะเติมคำว่า "Details" ให้เอง ?>
  <summary hidden><?= ui_btn_label('edit', 'แก้ไขข้อมูลเครื่อง') ?></summary>
  <?= asset_production_edit_form_html($a, $assetEditCtx, $productList, $fwSuggest) ?>
  <div style="margin-top:16px;padding:12px 14px;border:1px solid #b91c1c;background:var(--danger-soft, #fee2e2);border-radius:8px">
    <b style="font-size:13px;color:#b91c1c">โซนอันตราย</b>
    <p class="muted" style="margin:4px 0 10px;font-size:12.5px">ลบเครื่องนี้พร้อมประวัติทั้งหมด — การลบย้อนกลับไม่ได้</p>
    <button type="submit" form="del-asset-form" class="btn-danger btn-with-icon"
      onclick="return confirm('ลบเครื่อง <?= h($a['asset_code']) ?> พร้อมประวัติทั้งหมด (<?= count($tl) ?> รายการ)?\nการลบย้อนกลับไม่ได้!')"><?= ui_btn_label('trash', 'ลบเครื่องนี้') ?></button>
  </div>
  <form method="post" id="del-asset-form"><?= csrf_field() ?><input type="hidden" name="delete_asset" value="1"></form>
</details>

<?php // ไม่มีรายการเบิกเลยก็ไม่ต้องขึ้นแถบนี้
     if ($hasPartsRows) { ?>
<?php
// เคยมีป้ายผลตรวจ BOM อยู่ตรงนี้ด้วย — เอาออกเพราะเครื่องผลิตใหม่เบิกอะไหล่ไม่ตรง BOM เป๊ะเสมอไป
// (เปลี่ยนไปตาม lot ที่มีของตอนนั้น) และการนับก็มี bug นับ mode เบิกผลิตได้ไม่ครบทุกแบบอยู่แล้ว
// ป้ายจึงไม่ได้บอกอะไรที่เชื่อถือได้ ดู git history ของบรรทัดนี้ถ้าจะเอากลับมาทำใหม่
?>
<?php // หัวข้อสร้างที่เดียวตรงนี้ แถบข้อมูลอยู่ใต้หัวข้อ ?>
<div class="parts-head-row">
  <?= ui_heading('parts', 'อะไหล่ที่เบิกใช้กับเครื่องนี้', 'h2') ?>
<div id="parts-withdraw" class="parts-meta">

  <?php if (($partsSummary['out_count'] ?? 0) > 0) { ?>
    <?php
    $docShown = [];
    foreach ($partsSummary['movements'] as $mv) {
        $sid = (int) ($mv['tech_stock_out_id'] ?? 0);
        if ($sid <= 0 || isset($docShown[$sid])) { continue; }
        $docShown[$sid] = true;
        $doc = $partsSummary['stock_docs'][$sid] ?? null;
        echo '<code class="parts-meta-doc">' . h($doc ? $doc['doc_no'] : ('#' . $sid)) . '</code>';
    }
    ?>
    <?php
    // ปุ่ม Sync โผล่เฉพาะตอนข้อมูลจริงหลุดกันเท่านั้น — วัดจาก 366 เครื่องที่มีรายการเบิก
    // ทุกตัว "Stock ตรง" อยู่แล้วไม่มีข้อยกเว้น ปุ่มที่ไม่เคยมีอะไรให้ sync ก็ไม่ต้องโชว์ทุกครั้ง
    if (asset_needs_withdraw_list_sync($partsSummary)) { ?>
    <span class="parts-meta-links">
      <?php
      $withdrawN = (int) ($partsSummary['out_count'] ?? 0);
      $withdrawConfirm = "Sync ตามรายการเบิกในตาราง?\n\n"
          . "• ผูก Stock ตาม {$withdrawN} รายการในตาราง\n"
          . "• ลบใบเบิกซ้ำ/เกินใน Parts (คืนสต็อก)\n\n"
          . "รายการในตาราง production จะไม่ถูกลบ";
      ?>
      <form method="post" class="parts-meta-sync" onsubmit="return confirm(<?= h(json_encode($withdrawConfirm, JSON_UNESCAPED_UNICODE)) ?>)">
        <?= csrf_field() ?>
        <input type="hidden" name="sync_withdraw_list" value="1">
        <button type="submit">ตรวจ Sync</button>
      </form>
    </span>
    <?php } ?>
  <?php } elseif (($partsSummary['stock_out_count'] ?? 0) > 0) { ?>
    <span class="muted">มีเบิกใน Parts app แต่ยังไม่ผูกกับเครื่องนี้</span>
  <?php } ?>
</div>
</div>

<?php if ($partsSummary['out_count'] > 0) { ?>
<div class="table-wrap">
<table class="list" style="max-width:920px; margin-bottom:20px">
  <tr><th>อะไหล่</th><th style="text-align:right">จำนวน</th><th>ประเภท</th><th>วันเวลา</th><th>รหัส Stock</th><th></th></tr>
  <?php foreach ($partsSummary['movements'] as $mv) {
      $modeLabel = part_movement_mode_label($mv['mode'] ?? '');
      $modeClass = $modeLabel === 'MA' ? 'st-spare'
          : ($modeLabel === 'ผลิต' ? 'st-new'
          : ($modeLabel === 'ซ่อม' ? 'st-in_repair' : 'st-rental'));
      $editUrl = BASE_URL . '/parts.php?ajax=edit_move_form&id=' . (int)$mv['id'] . '&back=' . $assetPartsBackUrl;
  ?>
  <tr>
    <td>
      <?php // รูปอะไหล่ช่วยให้กวาดตาหาของที่ต้องการได้เร็วกว่าอ่านชื่อทีละบรรทัด
           // อะไหล่ 79 จาก 146 รายการมีรูป ตัวที่ไม่มีก็เว้นช่องไว้ให้คอลัมน์ตรงกัน ?>
      <div class="part-row-name">
        <?php $mvIcon = img_url($mv['icon_path'] ?? null); ?>
        <?php if ($mvIcon) { ?>
          <img src="<?= h($mvIcon) ?>" alt="" class="thumb-sm" loading="lazy"
            onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'thumb-sm part-row-noimg'}))">
        <?php } else { ?>
          <span class="thumb-sm part-row-noimg" aria-hidden="true"></span>
        <?php } ?>
        <span>
          <?= h($mv['pname']) ?>
          <?php if (!empty($mv['part_code']) && trim((string)$mv['part_code']) !== trim((string)$mv['pname'])) { ?>
            <br><span class="muted" style="font-size:11px"><?= h($mv['part_code']) ?></span>
          <?php } ?>
        </span>
      </div>
    </td>
    <td style="text-align:right"><b><?= qty_fmt($mv['qty']) ?></b><?= !empty($mv['unit']) ? ' ' . h($mv['unit']) : '' ?></td>
    <td><span class="badge <?= h($modeClass) ?>"><?= h($modeLabel) ?></span></td>
    <td style="white-space:nowrap"><?= dthai_full($mv['moved_at']) ?></td>
    <td class="muted"><?= h($mv['stock_code'] ?: '—') ?></td>
    <td style="white-space:nowrap">
      <button type="button" class="btn btn-sm btn-line" onclick="showListModal(<?= h(json_encode('แก้ไข: ' . $mv['pname'], JSON_UNESCAPED_UNICODE)) ?>,<?= h(json_encode($editUrl)) ?>,'')">แก้ไข</button>
      <form method="post" style="display:inline" onsubmit="return confirm('ลบรายการเบิกนี้?')">
        <?= csrf_field() ?>
        <input type="hidden" name="del_part_move" value="1">
        <input type="hidden" name="record_id" value="<?= (int)$mv['id'] ?>">
        <button type="submit" class="btn-sm btn-danger">ลบ</button>
      </form>
    </td>
  </tr>
  <?php } ?>
</table>
</div>
<?php } elseif ($partsUsed) {
    $stockCodeMap = [];
    $scRes = qr('SELECT name, stock_code FROM parts WHERE stock_code IS NOT NULL AND TRIM(stock_code)<>""');
    while ($scRow = $scRes->fetch_assoc()) {
        $stockCodeMap[$scRow['name']] = $scRow['stock_code'];
    }
?>
<div class="table-wrap">
<table class="list" style="max-width:640px; margin-bottom:20px">
  <tr><th>อะไหล่</th><th style="text-align:right">จำนวนที่เบิกใช้</th><th>รหัส Stock</th></tr>
  <?php foreach ($partsUsed as $pu) { ?>
  <tr>
    <td><?= h($pu['name']) ?></td>
    <td style="text-align:right"><b><?= qty_fmt($pu["qty"]) ?></b><?= $pu['unit'] ? ' ' . h($pu['unit']) : '' ?></td>
    <td class="muted"><?= h($stockCodeMap[$pu['name']] ?? '—') ?></td>
  </tr>
  <?php } ?>
</table>
</div>
<?php } ?>
<?php } ?>

<?= asset_maintenance_section_html($maRepairInfo) ?>

<h2>ประวัติทั้งหมด (<?= count($tl) ?> รายการ) — เรียงตามเวลาบันทึก</h2>
<?php $moveCount = (int) qr('SELECT COUNT(*) FROM stock_movements WHERE asset_id = ?', 'i', [$id])->fetch_row()[0]; ?>
<p class="muted" style="margin:-6px 0 12px; font-size:13px">ทุกประเภทงานอยู่ในเส้นเดียวกัน เรียงจากอดีต → ปัจจุบัน<?php if ($moveCount > 0) { ?> · <a href="<?= h(BASE_URL . '/stock_movements.php?q=' . urlencode((string) $a['asset_code'])) ?>">ประวัติเข้า-ออกคลัง (<?= number_format($moveCount) ?>) ›</a><?php } ?></p>
<?php asset_timeline_chrono_html($tl, 'asset_tl_actions', $id); ?>

<div id="asset-snippet-overlay" class="notif-overlay ma-sn-overlay" hidden>
  <div class="notif-box ma-sn-modal" role="dialog" aria-modal="true" aria-labelledby="asset-snippet-title">
    <div class="ma-sn-modal-hd">
      <div>
        <h2 id="asset-snippet-title" class="ma-snippets-title h-with-icon"><?= ui_icon_html('clipboard', 16, 'h-svg') ?><span><?= h(ma_snippets_title(false)) ?></span></h2>
        <p class="muted ma-snippets-lead">อัปเดตตามรหัสเครื่องและฟอร์ม · กดคัดลอกทีละข้อ</p>
      </div>
      <button type="button" class="btn-sm btn-line" onclick="closeOverlay('asset-snippet-overlay')">✕ ปิด</button>
    </div>
    <?= ma_snippets_inner_html('asset-tl-sn', ['title' => false, 'lead' => false, 'rental' => false]) ?>
  </div>
</div>
<script src="<?= BASE_URL ?>/assets/ma-snippets.js?v=<?= @filemtime(__DIR__ . '/assets/ma-snippets.js') ?: time() ?>"></script>
<script>
// ปุ่มแก้ไขอยู่บนแถบเครื่องมือ แต่ฟอร์มอยู่ใน <details> ด้านล่าง — ต่อสองอันเข้าด้วยกัน
// ซ่อน summary เดิมด้วย JS เท่านั้น ไม่ได้ซ่อนใน HTML เพื่อให้ยังกดได้ถ้า JS ไม่ทำงาน
(function () {
  var box = document.getElementById('asset-edit-details');
  var btn = document.querySelector('.asset-edit-toggle');
  if (!box || !btn) { return; }

  function sync() {
    btn.setAttribute('aria-expanded', box.open ? 'true' : 'false');
  }
  btn.addEventListener('click', function () {
    box.open = !box.open;
    sync();
    if (box.open) { box.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  });
  box.addEventListener('toggle', sync);
  sync();
})();
</script>
<?php page_footer();
