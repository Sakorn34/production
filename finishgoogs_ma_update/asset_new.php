<?php
require __DIR__ . '/config.php';
require_can('production');

// ---------------------------------------------------------------
// AJAX: ดึงฟิลด์เฉพาะรุ่น + ค่าเก่า (dropdown) + ค่าจากเครื่องล่าสุด
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fields') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)$_GET['product_id'];
    $p = qr("SELECT * FROM products WHERE id=?", 'i', [$pid])->fetch_assoc();
    if (!$p) { echo json_encode(['error' => 'ไม่พบรุ่น']); exit; }

    $out = [
        'mode'   => $p['code_mode'],
        'prefix' => $p['code_prefix'],
        'era'    => $p['code_year_era'],
        'digits' => (int)$p['running_digits'],
        'usePrefix' => (int)(isset($p['code_use_prefix']) ? $p['code_use_prefix'] : 1),
        'useYear'   => (int)(isset($p['code_use_year']) ? $p['code_use_year'] : 1),
        'useMonth'  => (int)(isset($p['code_use_month']) ? $p['code_use_month'] : 1),
        'nextRun' => 1,
        'fields' => [],
        'fw' => ['last' => '', 'options' => []],
        'made_by_options' => [],
        'made_by_last' => '',
        'made_by_input_mode' => 'chip_single_free',
        'fw_input_mode' => 'chip_single_free',
        'lot_input_mode' => 'chip_single_free',
        'checklist_last' => '', 'checklist_items' => [], 'lot_last' => '',
        'last_asset_code' => '',
    ];
    if ($p['code_mode'] === 'generated' && $p['code_prefix']) {
        $r = qr("SELECT MAX(a.running_no) m FROM assets a JOIN products pr ON pr.id=a.product_id WHERE pr.code_prefix=?",
                's', [$p['code_prefix']])->fetch_assoc();
        $out['nextRun'] = (int)$r['m'] + 1;
    } elseif ($p['code_mode'] === 'generated') {
        $r = qr("SELECT MAX(running_no) m FROM assets WHERE product_id=?", 'i', [$pid])->fetch_assoc();
        $out['nextRun'] = (int)$r['m'] + 1;
    }
    $out['checklist_items'] = effective_production_checklist($pid);

    $lastCode = latest_asset_code_for_product($p, $pid);
    if ($lastCode !== '') {
        $out['last_asset_code'] = $lastCode;
    }

    // บันทึกผลิตล่าสุดของรุ่นนี้ = ข้อมูลต้นแบบ
    $last = qr("SELECT pr.*, a.id aid FROM production_records pr JOIN assets a ON a.id=pr.asset_id
                WHERE a.product_id=? ORDER BY pr.recorded_at DESC, pr.id DESC LIMIT 1", 'i', [$pid])->fetch_assoc();
    if ($last) {
        $out['fw']['last'] = (string)$last['fw_version'];
        $out['checklist_last'] = (string)$last['checklist'];
        $out['lot_last'] = (string)$last['lot_label'];
        if (!empty($last['made_by'])) {
            $out['made_by_last'] = (string)$last['made_by'];
        }
    }

    // ตัวเลือก FW / ผู้ผลิต (เรียงตามที่ใช้ล่าสุด)
    $res = qr("SELECT pr.fw_version v FROM production_records pr JOIN assets a ON a.id=pr.asset_id
               WHERE a.product_id=? AND pr.fw_version IS NOT NULL AND pr.fw_version<>'' AND pr.fw_version<>'-'
               GROUP BY pr.fw_version ORDER BY MAX(pr.id) DESC LIMIT 10", 'i', [$pid]);
    while ($r = $res->fetch_assoc()) $out['fw']['options'][] = $r['v'];
    $res = qr("SELECT pr.made_by v FROM production_records pr JOIN assets a ON a.id=pr.asset_id
               WHERE a.product_id=? AND pr.made_by IS NOT NULL AND pr.made_by<>''
               GROUP BY pr.made_by ORDER BY MAX(pr.id) DESC LIMIT 10", 'i', [$pid]);
    while ($r = $res->fetch_assoc()) $out['made_by_options'][] = $r['v'];

    // ตัวเลือก Lot (ประวัติของรุ่น) — ให้เลือก dropdown หรือพิมพ์เองได้
    $out['lot_options'] = [];
    $res = qr("SELECT pr.lot_label v FROM production_records pr JOIN assets a ON a.id=pr.asset_id
               WHERE a.product_id=? AND pr.lot_label IS NOT NULL AND pr.lot_label<>''
               GROUP BY pr.lot_label ORDER BY MAX(pr.id) DESC LIMIT 15", 'i', [$pid]);
    while ($r = $res->fetch_assoc()) $out['lot_options'][] = $r['v'];

    // รายชื่อสำหรับฟิลด์ชนิด "ผู้ผลิต" — login_name จาก SSO + ประวัติ made_by
    $out['user_names'] = [];
    $actor = actor_name();
    if ($actor !== '') {
        $out['user_names'][] = $actor;
    }
    foreach ($out['made_by_options'] as $mb) {
        if ($mb !== '' && !in_array($mb, $out['user_names'], true)) {
            $out['user_names'][] = $mb;
        }
    }
    $out['actor_name'] = $actor;

    // รายการอะไหล่ทั้งหมด + ชุดอะไหล่ประจำรุ่น (BOM) — จัดชุดเบิกได้ในหน้านี้เลย
    $out['all_parts'] = [];
    $partRows = [];
    $rp = qr("SELECT id, name, unit, part_code, stock_code, icon_path FROM parts WHERE is_active=1 ORDER BY name");
    while ($r = $rp->fetch_assoc()) {
        $partRows[] = $r;
    }
    $qtyMap = tech_parts_qty_map_for_parts($partRows);
    foreach ($partRows as $r) {
        $codeKey = part_row_stock_code($r);
        if ($codeKey === '') {
            $codeKey = trim((string)($r['part_code'] ?? ''));
        }
        $out['all_parts'][] = [
            'id'        => (int)$r['id'],
            'name'      => (string)$r['name'],
            'unit'      => (string)($r['unit'] ?? ''),
            'icon'      => img_url($r['icon_path'] ?? '') ?: '',
            'stock_qty' => ($codeKey !== '' && isset($qtyMap[$codeKey])) ? (int)$qtyMap[$codeKey] : null,
        ];
    }
    $out['bom'] = [];
    $rb = qr("SELECT b.part_id, b.qty_per_unit FROM bom_items b JOIN parts pa ON pa.id=b.part_id WHERE b.product_id=? ORDER BY pa.name", 'i', [$pid]);
    while ($r = $rb->fetch_assoc()) $out['bom'][] = ['part_id' => (int)$r['part_id'], 'qty' => (float)$r['qty_per_unit']];

    // ค่าจากเครื่องล่าสุด (สำหรับ prefill)
    $lastComp = [];
    if ($last) {
        $res = qr("SELECT component_name, component_value FROM asset_components WHERE asset_id=?", 'i', [$last['aid']]);
        while ($r = $res->fetch_assoc()) $lastComp[$r['component_name']] = $r['component_value'];
    }
    $lastExtra = $last && $last['extra_json'] ? (json_decode($last['extra_json'], true) ?: []) : [];

    // ฟิลด์จากตั้งค่าหลังบ้าน (ถ้ามี) — options จาก config ∪ ประวัติ; ค่ายังพิมพ์ใหม่ได้อิสระที่ฟอร์ม
    $out['configured'] = has_product_config($pid, 'production');
    $std = product_std_fields($pid);
    // ยังไม่เคยตั้งสวิตช์ FW/Lot → แสดงตามเดิม · ตั้งแล้ว → ตามที่ติ๊ก
    $out['show_fw'] = $std['decided'] ? ($std['fw'] !== null) : true;
    $out['show_lot'] = $std['decided'] ? ($std['lot'] !== null) : true;
    $out['show_made_by'] = $std['decided'] ? ($std['made_by'] !== null) : true;
    $out['made_by_input_mode'] = $std['made_by'] ? $std['made_by']['input_mode'] : 'chip_single_free';
    $out['fw_input_mode'] = $std['fw'] ? $std['fw']['input_mode'] : 'chip_single_free';
    $out['lot_input_mode'] = $std['lot'] ? $std['lot']['input_mode'] : 'chip_single_free';
    // รวมตัวเลือกจากตั้งค่าเข้ากับประวัติ
    if ($std['fw'] && $std['fw']['options']) {
        foreach ($std['fw']['options'] as $o) {
            if ($o !== '' && !in_array($o, $out['fw']['options'], true)) {
                array_unshift($out['fw']['options'], $o);
            }
        }
        if ($out['fw']['last'] === '' && !empty($std['fw']['options'][0])) {
            $out['fw']['last'] = $std['fw']['options'][0];
        }
    }
    if ($std['lot'] && $std['lot']['options']) {
        foreach ($std['lot']['options'] as $o) {
            if ($o !== '' && !in_array($o, $out['lot_options'], true)) {
                array_unshift($out['lot_options'], $o);
            }
        }
        if ($out['lot_last'] === '' && !empty($std['lot']['options'][0])) {
            $out['lot_last'] = $std['lot']['options'][0];
        }
    }

    foreach (effective_fields($pid, 'production') as $f) {
        $lastVal = '';
        if ($f['kind'] === 'component') {
            $lastVal = isset($lastComp[$f['name']]) ? (string)$lastComp[$f['name']] : '';
        } elseif ($f['kind'] === 'ผู้ผลิต') {
            if ($last && !empty($last['made_by'])) {
                $lastVal = (string)$last['made_by'];
            } elseif (isset($lastExtra[$f['name']])) {
                $lastVal = (string)$lastExtra[$f['name']];
            }
        } else {
            $lastVal = isset($lastExtra[$f['name']]) ? (string)$lastExtra[$f['name']] : '';
        }
        if ($lastVal === '' && !empty($f['options'][0])) {
            $lastVal = $f['options'][0];
        }
        $opts = $f['options'];
        if ($f['kind'] === 'ผู้ผลิต') {
            foreach ($out['user_names'] as $nm) {
                if ($nm !== '' && !in_array($nm, $opts, true)) {
                    array_unshift($opts, $nm);
                }
            }
        }
        $out['fields'][] = [
            'name' => $f['name'],
            'kind' => $f['kind'],
            'last' => $lastVal,
            'options' => array_values($opts),
            'input_mode' => normalize_input_mode($f['input_mode'] ?? ''),
            'from_settings' => (bool)$out['configured'],
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// AJAX: ตรวจรหัสซ้ำก่อนยืนยันบันทึก
// ---------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'precheck' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)(isset($_POST['product_id']) ? $_POST['product_id'] : 0);
    $pdate = isset($_POST['produced_at']) ? $_POST['produced_at'] : date('Y-m-d');
    $p = $pid ? qr("SELECT code_mode FROM products WHERE id=?", 'i', [$pid])->fetch_assoc() : null;
    if (!$p) {
        echo json_encode(['ok' => false, 'message' => 'ยังไม่ได้เลือกรุ่นสินค้า'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $serials = [];
    $count = 1;
    if ($p['code_mode'] === 'generated') {
        $count = min(200, max(1, (int)(isset($_POST['gen_count']) ? $_POST['gen_count'] : 1)));
    } else {
        foreach ((array)(isset($_POST['serials']) ? $_POST['serials'] : []) as $s) {
            $s = trim($s);
            if ($s !== '') $serials[] = $s;
        }
    }
    $chk = precheck_production_save($pid, $pdate, $count, $serials);
    echo json_encode($chk, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------
// บันทึก (POST)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid   = (int)$_POST['product_id'];
    $p     = qr("SELECT * FROM products WHERE id=?", 'i', [$pid])->fetch_assoc();
    if (!$p) { flash_set('ยังไม่ได้เลือกรุ่นสินค้า', 'err'); header('Location: ' . BASE_URL . '/asset_new.php'); exit; }
    $pdate = $_POST['produced_at'] ?: date('Y-m-d');
    $madeBy = trim($_POST['made_by'] ?? '');
    if ($madeBy === '') {
        $madeBy = actor_name();
    }
    $fw = trim($_POST['fw_version']);
    $checklist = trim($_POST['checklist']);
    $problems = trim($_POST['problems_found']);
    $fix = trim($_POST['fix']);
    $lot = trim($_POST['lot_label']);
    $note = trim($_POST['note']);

    $fNames = isset($_POST['field_names']) ? (array)$_POST['field_names'] : [];
    $fKinds = isset($_POST['field_kinds']) ? (array)$_POST['field_kinds'] : [];
    $fVals  = isset($_POST['field_values']) ? (array)$_POST['field_values'] : [];
    $components = []; $extras = [];
    foreach ($fNames as $i => $fn) {
        $fv = trim(isset($fVals[$i]) ? $fVals[$i] : '');
        if ($fn === '' || $fv === '') continue;
        if ((isset($fKinds[$i]) ? $fKinds[$i] : '') === 'component') $components[$fn] = $fv;
        else $extras[$fn] = $fv;
    }
    $extraJson = $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null;

    $units = [];
    if ($p['code_mode'] === 'generated') {
        $qty = min(200, max(1, (int)$_POST['gen_count']));
        for ($i = 0; $i < $qty; $i++) $units[] = '';
    } else {
        foreach ((array)(isset($_POST['serials']) ? $_POST['serials'] : []) as $s) {
            $s = trim($s);
            if ($s !== '') $units[] = $s;
        }
        $units = array_values(array_unique($units));
        if (!$units) { flash_set('ยังไม่ได้กรอกหมายเลขสินค้า', 'err'); header('Location: ' . BASE_URL . '/asset_new.php'); exit; }
    }

    // ชุดอะไหล่ประจำรุ่น (BOM) — จัดชุดในฟอร์มนี้ → บันทึกเป็นเทมเพลตรุ่น + เบิกอัตโนมัติต่อเครื่อง
    $bomPartIds = (array)(isset($_POST['bom_part_id']) ? $_POST['bom_part_id'] : []);
    $bomQtys    = (array)(isset($_POST['bom_qty']) ? $_POST['bom_qty'] : []);
    $bomList = []; $seenBom = [];
    foreach ($bomPartIds as $i => $partId) {
        $partId = (int)$partId;
        if ($partId <= 0 || isset($seenBom[$partId])) continue;
        $bq = max(0.5, (float)(isset($bomQtys[$i]) ? $bomQtys[$i] : 1));
        $bomList[] = ['part_id' => $partId, 'qty_per_unit' => $bq];
        $seenBom[$partId] = true;
    }
    // บันทึกชุดอะไหล่เป็นเทมเพลตของรุ่น (ครั้งถัดไปจะ prefill ให้)
    q("DELETE FROM bom_items WHERE product_id=?", 'i', [$pid]);
    foreach ($bomList as $bi) {
        q("INSERT INTO bom_items (product_id, part_id, qty_per_unit) VALUES (?,?,?)", 'iid', [$pid, $bi['part_id'], $bi['qty_per_unit']]);
    }
    $bomBy = $madeBy;

    $codes = []; $err = null;
    $notifyAssets = [];
    foreach ($units as $serial) {
        // ไม่ส่ง user id เข้า DB แล้ว — เก็บเฉพาะชื่อจาก profile/ฟอร์มใน made_by
        $r = create_produced_asset($pid, $pdate, $serial, $note ?: null, null);
        if (isset($r['error'])) { $err = $r['error']; break; }
        $codes[] = $r['code'];
        q("INSERT INTO production_records (asset_id,recorded_at,made_by,fw_version,problems_found,fix,checklist,lot_label,extra_json)
           VALUES (?,NOW(),?,?,?,?,?,?,?)", 'isssssss',
          [$r['asset_id'], $madeBy !== '' ? $madeBy : null, $fw !== '' ? $fw : null, $problems !== '' ? $problems : null,
           $fix !== '' ? $fix : null, $checklist !== '' ? $checklist : null, $lot !== '' ? $lot : null, $extraJson]);
        share_upsert_asset($r['asset_id']); // อัปเดตตารางแชร์อีกรอบให้ได้ชื่อผู้ผลิต+เวลาบันทึกจริง
        foreach ($components as $cn => $cv) {
            q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE component_value=VALUES(component_value)", 'iss', [$r['asset_id'], $cn, $cv]);
        }
        q("INSERT INTO stock_movements (asset_id,moved_at,direction,reason,made_by) VALUES (?,NOW(),'in','ผลิตเสร็จเข้าคลัง',?)",
          'is', [$r['asset_id'], $bomBy]);
        // เบิกชุดอะไหล่ประจำรุ่นอัตโนมัติ (ผูกกับเครื่องนี้) — ตัดสต็อกจริงที่ biton_tech_parts
        $deductedThisUnit = [];
        foreach ($bomList as $bi) {
            $bq = (float)$bi['qty_per_unit'];
            $partId = (int)$bi['part_id'];
            $existMv = qr(
                "SELECT id FROM part_movements WHERE ref_asset_id=? AND part_id=? AND direction='out' AND mode=? LIMIT 1",
                'iis',
                [(int)$r['asset_id'], $partId, 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)']
            )->fetch_assoc();
            if ($existMv) {
                continue;
            }
            $bomAssetCode = null;
            if (!empty($r['asset_id'])) {
                $acRow = qr('SELECT asset_code FROM assets WHERE id=?', 'i', [(int)$r['asset_id']])->fetch_assoc();
                $bomAssetCode = $acRow['asset_code'] ?? null;
            }
            $out = tech_parts_stock_out_by_part_id($partId, $bq, 'เบิกผลิต', $bomBy, $bomAssetCode);
            if (!$out['ok']) {
                foreach (array_reverse($deductedThisUnit) as $rev) {
                    tech_parts_stock_in_by_part_id($rev['part_id'], $rev['qty'], 'ยกเลิกเบิกอัตโนมัติ', $bomBy);
                }
                $err = $out['error'];
                break 2;
            }
            $deductedThisUnit[] = ['part_id' => $partId, 'qty' => (int)($out['qty'] ?? tech_parts_qty_to_int($bq))];
            q("INSERT INTO part_movements (part_id,moved_at,direction,qty,mode,ref_asset_id,made_by,remark,tech_stock_out_id)
               VALUES (?,NOW(),'out',?,?,?,?,NULL,?)", 'idsisi',
              [$partId, $bq, 'เบิกอัตโนมัติ (ชุดอะไหล่รุ่น)', $r['asset_id'], $bomBy, (int)($out['stock_out_id'] ?? 0)]);
            $bomMid = (int)db()->insert_id;
            if (!empty($out['stock_out_id']) && function_exists('production_link_stock_out')) {
                $ac = qr('SELECT asset_code FROM assets WHERE id=?', 'i', [$r['asset_id']])->fetch_assoc();
                production_link_stock_out(dbParts(), (int)$out['stock_out_id'], $bomMid, $ac['asset_code'] ?? null);
            }
        }
        if ($fw !== '') q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$fw, $r['asset_id']]);
        if ($lot !== '') q("UPDATE assets SET lot_label=? WHERE id=?", 'si', [$lot, $r['asset_id']]);
        if ($problems !== '') {
            $notifyAssets[] = [
                'asset_id'    => (int)$r['asset_id'],
                'asset_code'  => (string)$r['code'],
                'model'       => (string)$p['name'],
                'produced_at' => $pdate,
                'made_by'     => $madeBy,
                'problems'    => $problems,
                'fix'         => $fix,
            ];
        }
    }
    if (!$err && $notifyAssets !== [] && function_exists('line_notify_instant_enabled') && line_notify_instant_enabled('production.problem_found')) {
        foreach ($notifyAssets as $na) {
            line_notify_dispatch('production.problem_found', $na, [
                'dedup_key' => 'production.problem_found:' . (int)$na['asset_id'] . ':' . date('Y-m-d'),
            ]);
        }
    }
    if ($err) {
        $msg = $err;
        if ($codes) $msg .= ' — บันทึกสำเร็จก่อนหน้านั้น: ' . implode(', ', $codes);
        flash_set($msg, 'err');
    } else {
        flash_set('บันทึกสำเร็จ ' . count($codes) . ' เครื่อง: ' . implode(', ', $codes));
    }
    header('Location: ' . BASE_URL . ($codes ? '/assets.php?product=' . urlencode($p['name']) : '/asset_new.php'));
    exit;
}

// ---------------------------------------------------------------
// แสดงฟอร์ม
// ---------------------------------------------------------------
require __DIR__ . '/includes/layout.php';
$products = [];
$res = qr("SELECT id, name, code_mode, icon_path FROM products WHERE is_active=1 ORDER BY name");
while ($r = $res->fetch_assoc()) $products[] = $r;
page_header('บันทึกสินค้าผลิตใหม่');
?>
<form method="post" id="mainform">
  <?= csrf_field() ?>
  <input type="hidden" name="product_id" id="product_id" value="">
  <input type="hidden" name="checklist" id="checklist_hidden" value="">

  <!-- เลือกรุ่นสินค้า (เต็มความกว้าง) -->
  <div class="panel" style="margin-bottom:16px">
    <label class="fld"><b>เลือกรุ่นสินค้า</b> <input type="text" id="prod-filter" placeholder="พิมพ์กรองชื่อรุ่น…" style="margin-left:10px; width:220px"></label>
    <div class="prod-pick" id="prod-pick" style="margin-top:8px">
      <?php foreach ($products as $p) { ?>
      <div class="pp" data-id="<?= $p['id'] ?>" data-name="<?= h(mb_strtolower($p['name'])) ?>" onclick="pickProduct(this)">
        <?= img_tag($p['icon_path'], $p['name'], '') ?>
        <div class="ppn"><?= h($p['name']) ?></div>
      </div>
      <?php } ?>
    </div>
  </div>

  <div class="produce-cols">
    <!-- ซ้าย: ข้อมูลการผลิต + ข้อมูลประจำรุ่น (รวมผู้ผลิต/FW/Lot) -->
    <div class="panel">
      <h3>🏭 ข้อมูลการผลิต</h3>
      <div class="field">
        <label>วันที่ผลิต</label>
        <input type="date" name="produced_at" id="produced_at" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="field">
        <label>รายการเครื่องในชุดนี้ <span class="muted" id="unit-hint"></span></label>
        <div id="last-asset-hint" class="muted" style="font-size:12px; margin-bottom:4px; display:none"></div>
        <div id="unit-list"></div>
        <button type="button" class="btn btn-line btn-sm" id="add-unit" onclick="addUnit()" disabled>➕ เพิ่มเครื่อง</button>
        <input type="hidden" name="gen_count" id="gen_count" value="0">
      </div>
      <div class="field">
        <label>ข้อมูลประจำรุ่น <span class="muted">(จากตั้งค่าหลังบ้าน · กด ▾ เลือก หรือพิมพ์ใหม่ได้อิสระ)</span></label>
        <div id="dyn-fields"><span class="muted">เลือกรุ่นสินค้าก่อน</span></div>
        <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap; align-items:center">
          <button type="button" class="btn btn-line btn-sm" id="add-free-field" onclick="addFreeField()" disabled>➕ เพิ่มฟิลด์กรอกเอง</button>
          <span class="muted" style="font-size:12px">เพิ่มฟิลด์นอกเหนือจากที่ตั้งไว้หลังบ้านได้</span>
        </div>
      </div>
      <div class="field" id="madeby-field-wrap"><label>ผู้ผลิต/ประกอบ</label><div id="madeby-slot"></div></div>
      <div class="field" id="fw-field-wrap"><label>เวอร์ชัน Firmware</label><div id="fw-slot"></div></div>
      <div class="field" id="lot-field-wrap"><label>Lot</label><div id="lot-slot"></div></div>
      <div class="field">
        <label>📝 ปัญหา / การแก้ไข / หมายเหตุ</label>
        <div class="note-stack">
          <div class="field"><label>ปัญหาที่พบ (ถ้ามี)</label><textarea name="problems_found" class="field-note" rows="2"></textarea></div>
          <div class="field"><label>การแก้ไข (ถ้ามี)</label><textarea name="fix" class="field-note" rows="2"></textarea></div>
          <div class="field"><label>หมายเหตุประจำเครื่อง</label><textarea name="note" class="field-note" rows="2"></textarea></div>
        </div>
      </div>
    </div>

    <!-- ขวา: ชุดอะไหล่ + checklist -->
    <div class="panel">
      <h3>🔩 ชุดอะไหล่ &amp; ตรวจสอบ</h3>
      <div class="field">
        <label>ชุดอะไหล่ที่จะเบิก <span class="muted">(เบิกอัตโนมัติต่อเครื่องตอนบันทึก)</span></label>
        <div id="bom-fields"><span class="muted">เลือกรุ่นสินค้าก่อน</span></div>
      </div>
      <div class="field">
        <label>Checklist ที่ตรวจแล้ว <span class="muted">(ติ๊กเฉพาะข้อที่ตรวจ)</span></label>
        <div class="chk-list" id="chk-list"><span class="muted">เลือกรุ่นสินค้าก่อน</span></div>
        <div style="display:flex; gap:8px; margin-top:8px">
          <input type="text" id="chk-new" placeholder="เพิ่มข้อตรวจใหม่…" style="flex:1">
          <button type="button" class="btn btn-line btn-sm" onclick="addChkItem()">➕ เพิ่มข้อ</button>
        </div>
      </div>
    </div>
  </div>

  <div style="margin-top:16px"><button type="submit" id="save-btn" disabled>💾 บันทึกทั้งชุด</button></div>
</form>

<!-- popup ยืนยันก่อนบันทึก -->
<div id="confirm-overlay" class="notif-overlay" hidden>
  <div class="notif-box" style="width:min(680px,94vw); max-height:84vh">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px">
      <h2 style="margin:0">🔎 ตรวจสอบก่อนบันทึก</h2>
      <button type="button" class="btn-sm btn-line" onclick="closeOverlay('confirm-overlay')">✕ ปิด</button>
    </div>
    <div id="confirm-body" style="overflow:auto; max-height:62vh"></div>
    <div id="confirm-error" hidden style="margin-top:10px; padding:12px 14px; background:#fff4f4; border:1px solid #f5c2c2; border-radius:8px; color:#b42318; font-size:14px; line-height:1.5; white-space:pre-wrap"></div>
    <div style="margin-top:14px; display:flex; gap:10px; justify-content:flex-end">
      <button type="button" class="btn btn-line" onclick="closeOverlay('confirm-overlay')">← กลับไปแก้ไข</button>
      <button type="button" id="confirm-submit-btn" onclick="doConfirmSubmit()">✅ ยืนยันบันทึก</button>
    </div>
  </div>
</div>

<!-- modal สแกน QR -->
<div id="qr-overlay" class="notif-overlay" hidden>
  <div class="notif-box" style="width:min(460px,94vw)">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px">
      <h2 style="margin:0">📷 สแกนหมายเลขสินค้า</h2>
      <button type="button" class="btn-sm btn-line" onclick="stopScan()">✕ ปิด</button>
    </div>
    <div id="qr-reader"></div>
    <p class="muted" id="qr-status">กำลังเปิดกล้อง…</p>
  </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
var cfg = null, unitCount = 0;
var BASE = '<?= BASE_URL ?>';

function esc(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML.replace(/"/g, '&quot;'); }

// ---------- เลือกรุ่นแบบการ์ดรูป ----------
document.getElementById('prod-filter').addEventListener('input', function(){
  var q = this.value.trim().toLowerCase();
  document.querySelectorAll('#prod-pick .pp').forEach(function(el){
    el.style.display = (!q || el.dataset.name.indexOf(q) !== -1) ? '' : 'none';
  });
});
function pickProduct(el){
  document.querySelectorAll('#prod-pick .pp.sel').forEach(function(x){ x.classList.remove('sel'); });
  el.classList.add('sel');
  document.getElementById('product_id').value = el.dataset.id;
  loadProduct(el.dataset.id);
}
document.getElementById('produced_at').addEventListener('change', refreshPreviews);

function loadProduct(pid){
  cfg = null; unitCount = 0;
  document.getElementById('unit-list').innerHTML = '';
  document.getElementById('gen_count').value = 0;
  document.getElementById('add-unit').disabled = true;
  document.getElementById('save-btn').disabled = true;
  document.getElementById('dyn-fields').innerHTML = 'กำลังโหลดข้อมูลรุ่น…';
  fetch(BASE + '/asset_new.php?ajax=fields&product_id=' + pid)
    .then(function(r){ return r.json(); })
    .then(function(d){
      cfg = d;
      document.getElementById('add-unit').disabled = false;
      document.getElementById('save-btn').disabled = false;
      document.getElementById('add-free-field').disabled = false;
      document.getElementById('unit-hint').textContent = d.mode === 'generated'
        ? '(ระบบออกเลข running อัตโนมัติ — หมายเลขสินค้าจริงยืนยันตอนกดบันทึก)'
        : '(กรอกหมายเลขสินค้าเองหรือสแกน QR ทีละเครื่อง)';
      var hintEl = document.getElementById('last-asset-hint');
      hintEl.style.display = 'none';
      hintEl.textContent = '';
      renderFields(d);
      renderChecklist(d.checklist_items || [], d.checklist_last || '');
      renderBom(d);
      addUnit();
    })
    .catch(function(){ document.getElementById('dyn-fields').innerHTML = 'โหลดข้อมูลรุ่นไม่สำเร็จ'; });
}

// ---------- dropdown chip: ช่องกรอก + chip ค่าเก่าของรุ่น ----------
function comboHtml(inputName, value, options, hidden, inputMode){
  return chipDdHtml(inputName, value, options, hidden, inputMode || 'chip_multi_free');
}

// ---------- people picker (ชนิด "ผู้ผลิต") ----------
document.addEventListener('click', function(e){
  var tg = e.target.closest('.ppl-toggle');
  if (tg){ var list = tg.parentNode.querySelector('.ppl-list'); list.hidden = !list.hidden; e.preventDefault(); return; }
  document.querySelectorAll('.ppl-list').forEach(function(l){
    if (!l.parentNode.contains(e.target)) l.hidden = true;
  });
});
document.addEventListener('change', function(e){
  if (e.target.classList && e.target.classList.contains('ppl-cb')){
    var ppl = e.target.closest('.ppl');
    var sel = Array.prototype.slice.call(ppl.querySelectorAll('.ppl-cb:checked')).map(function(c){ return c.value; });
    ppl.querySelector('input[type=hidden][name="field_values[]"]').value = sel.join(', ');
    var tgl = ppl.querySelector('.ppl-toggle');
    tgl.querySelector('.ppl-label').textContent = sel.length ? sel.join(', ') : 'เลือกชื่อผู้ผลิต…';
    tgl.classList.toggle('has-sel', sel.length > 0);
  }
});

var MAKER_KIND = 'ผู้ผลิต';
function kindLabelText(k){
  var m = { component: 'ชิ้นส่วนฮาร์ดแวร์', extra: 'ข้อมูลเพิ่มเติม', text: 'ข้อความทั่วไป' };
  return m[k] || k;
}
/** สร้างแถวฟิลด์ 1 ช่อง (combo เลือก+พิมพ์อิสระ) */
function fieldRowHtml(f){
  var hidden = '<input type="hidden" name="field_names[]" value="' + esc(f.name) + '">'
             + '<input type="hidden" name="field_kinds[]" value="' + esc(f.kind) + '">';
  // ชนิดผู้ผลิต: ใช้ combo เหมือนช่องอื่น — เลือกจากรายชื่อหรือพิมพ์เองได้
  var opts = f.options || [];
  if (f.kind === MAKER_KIND && cfg && cfg.user_names) {
    opts = opts.slice();
    (cfg.user_names || []).forEach(function(nm){
      if (nm && opts.indexOf(nm) === -1) opts.unshift(nm);
    });
  }
  var control = comboHtml('field_values[]', f.last || '', opts, hidden, f.input_mode);
  return '<div class="dyn-field-row">'
       + '<label>' + esc(f.name) + '</label>'
       + control + '</div>';
}
/** เพิ่มฟิลด์กรอกเองนอกเหนือจากที่ตั้งค่าหลังบ้าน */
function addFreeField(){
  var name = prompt('ชื่อฟิลด์ที่ต้องการเพิ่ม (เช่น สีเครื่อง / หมายเหตุพิเศษ)');
  if (!name) return;
  name = name.trim();
  if (!name) return;
  var box = document.getElementById('dyn-fields');
  if (box.querySelector('.muted') && !box.querySelector('.dyn-field-row')) box.innerHTML = '';
  box.insertAdjacentHTML('beforeend', fieldRowHtml({ name: name, kind: 'extra', last: '', options: [], from_settings: false }));
  initChipDd(box);
}
function renderFields(d){
  var html = '';
  if (!d.fields.length) {
    html = '<span class="muted">รุ่นนี้ยังไม่ได้ตั้งค่าฟิลด์หลังบ้าน — กด "เพิ่มฟิลด์กรอกเอง" หรือไปตั้งค่าที่เมนูระบบหลังบ้าน</span>';
  }
  d.fields.forEach(function(f){ html += fieldRowHtml(f); });
  document.getElementById('dyn-fields').innerHTML = html;
  document.getElementById('add-free-field').disabled = false;
  // แสดง FW / Lot ตามที่ตั้งค่าหลังบ้านของรุ่นนี้
  var showFw = d.show_fw !== false;
  var showLot = d.show_lot !== false;
  var showMadeBy = d.show_made_by !== false;
  document.getElementById('fw-field-wrap').hidden = !showFw;
  document.getElementById('lot-field-wrap').hidden = !showLot;
  document.getElementById('madeby-field-wrap').hidden = !showMadeBy;
  if (showFw) {
    document.getElementById('fw-slot').innerHTML = comboHtml('fw_version', d.fw.last || '', d.fw.options || [], '', d.fw_input_mode || 'chip_single_free');
  } else {
    document.getElementById('fw-slot').innerHTML = '<input type="hidden" name="fw_version" value="">';
  }
  // ผู้ผลิต/ประกอบ — ค่าเริ่มเป็นชื่อผู้ทำรายการ (login name)
  if (showMadeBy) {
    var defaultMade = d.actor_name || '';
    var madeOpts = (d.made_by_options || []).slice();
    if (d.actor_name) {
      madeOpts = madeOpts.filter(function(o){ return o !== d.actor_name; });
      madeOpts.unshift(d.actor_name);
    }
    document.getElementById('madeby-slot').innerHTML = comboHtml('made_by', defaultMade, madeOpts, '', d.made_by_input_mode || 'chip_single_free');
  } else {
    document.getElementById('madeby-slot').innerHTML = '<input type="hidden" name="made_by" value="">';
  }
  if (showLot) {
    document.getElementById('lot-slot').innerHTML = comboHtml('lot_label', d.lot_last || '', d.lot_options || [], '', d.lot_input_mode || 'chip_single_free');
  } else {
    document.getElementById('lot-slot').innerHTML = '<input type="hidden" name="lot_label" value="">';
  }
  initChipDd(document.getElementById('dyn-fields'));
  if (showMadeBy) initChipDd(document.getElementById('madeby-slot'));
  if (showFw) initChipDd(document.getElementById('fw-slot'));
  if (showLot) initChipDd(document.getElementById('lot-slot'));
}

// ---------- checklist ติ๊กถูก ----------
function renderChecklist(items, lastText){
  items = Array.isArray(items) ? items : [];
  var lastSet = {};
  if (lastText) {
    lastText.split(',').map(function(s){ return s.trim(); }).filter(function(s){ return s !== '' && s !== '-'; })
      .forEach(function(s){ lastSet[s] = true; });
  }
  if (!items.length && lastText) {
    items = Object.keys(lastSet);
  }
  var box = document.getElementById('chk-list');
  if (!items.length) {
    box.innerHTML = '<span class="muted">รุ่นนี้ยังไม่มี checklist — ตั้งค่าได้ที่ระบบหลังบ้าน หรือเพิ่มข้อเองด้านล่าง</span>';
    return;
  }
  var hasLast = Object.keys(lastSet).length > 0;
  box.innerHTML = items.map(function(it){
    var checked = hasLast ? !!lastSet[it] : true;
    return '<label class="chk-item"><input type="checkbox"' + (checked ? ' checked' : '') + ' data-item="' + esc(it) + '"><span>' + esc(it) + '</span></label>';
  }).join('');
}
function addChkItem(){
  var inp = document.getElementById('chk-new');
  var v = inp.value.trim();
  if (!v) return;
  var box = document.getElementById('chk-list');
  if (box.querySelector('span.muted')) box.innerHTML = '';
  box.insertAdjacentHTML('beforeend',
    '<label class="chk-item"><input type="checkbox" checked data-item="' + esc(v) + '"><span>' + esc(v) + '</span></label>');
  inp.value = '';
}
document.getElementById('chk-new').addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); addChkItem(); }
});
// ---------- ชุดอะไหล่ที่จะเบิก (BOM) จัดในฟอร์มนี้ ----------
var bomAllParts = [];
function bomPartById(id){
  id = parseInt(id, 10) || 0;
  for (var i = 0; i < bomAllParts.length; i++) if (bomAllParts[i].id === id) return bomAllParts[i];
  return null;
}
function bomThumbHtml(partId){
  var p = bomPartById(partId);
  if (p && p.icon) return '<img src="' + esc(p.icon) + '" alt="" class="thumb-sm ma-part-opt-thumb" loading="lazy" onerror="this.classList.add(\'broken\')">';
  return '<span class="thumb-sm noimg ma-part-opt-thumb">—</span>';
}
function bomPartLabel(partId){
  var p = bomPartById(partId);
  if (!p) return '';
  return p.name + (p.unit ? ' (' + p.unit + ')' : '');
}
function bomPartStockHtml(p){
  if (!p || p.stock_qty == null) return '<span class="ma-part-opt-stock ma-part-opt-stock-na">ไม่มีใน Stock ช่าง</span>';
  var cls = 'ma-part-opt-stock';
  if (p.stock_qty <= 0) cls += ' ma-part-opt-stock-out';
  else if (p.stock_qty <= 5) cls += ' ma-part-opt-stock-low';
  var unit = p.unit ? ' ' + esc(p.unit) : '';
  return '<span class="' + cls + '">คงเหลือ ' + p.stock_qty + unit + '</span>';
}
function bomPartSelectedChipHtml(partId){
  var label = bomPartLabel(partId);
  if (!label) return '';
  return '<span class="chip chip-pick ma-part-chip-sel">'
    + bomThumbHtml(partId)
    + '<span class="ma-part-chip-label">' + esc(label) + '</span></span>';
}
function bomPartOptsHtml(selId, filter){
  filter = (filter || '').toLowerCase();
  if (!bomAllParts.length) {
    return '<div class="ma-part-opt-empty muted">เลือกรุ่นสินค้าก่อน หรือกำลังโหลดรายการอะไหล่…</div>';
  }
  var list = bomAllParts.filter(function(p){
    return !filter || p.name.toLowerCase().indexOf(filter) !== -1;
  });
  if (!list.length) {
    return '<div class="ma-part-opt-empty muted">ไม่พบอะไหล่' + (filter ? ' ที่ตรงกับ "' + esc(filter) + '"' : '') + '</div>';
  }
  return '<div class="ma-part-opt-items">' + list.map(function(p){
    var sel = p.id === selId ? ' is-selected' : '';
    return '<div class="ma-part-opt-row' + sel + '" data-pid="' + p.id + '" role="button" tabindex="0">'
      + bomThumbHtml(p.id)
      + '<div class="ma-part-opt-body">'
      + '<span class="ma-part-opt-name">' + esc(p.name) + '</span>'
      + bomPartStockHtml(p)
      + '</div></div>';
  }).join('') + '</div>';
}
function bomPartChipHtml(partId){
  var hasPart = !!bomPartById(partId);
  return '<div class="chip-dd bom-part-dd ma-part-dd" data-pid="' + (partId || 0) + '">'
    + '<input type="hidden" name="bom_part_id[]" value="' + (partId || '') + '">'
    + '<div class="chip-dd-box">'
    + '<div class="chip-dd-chips">' + (hasPart ? bomPartSelectedChipHtml(partId) : '') + '</div>'
    + '<input type="text" class="chip-dd-filter bom-part-filter" placeholder="' + (hasPart ? '' : 'ค้นหาอะไหล่…') + '" autocomplete="off">'
    + '<button type="button" class="chip-dd-btn" tabindex="-1">▾</button>'
    + '</div>'
    + '<div class="chip-dd-list" hidden><div class="chip-dd-opts">' + bomPartOptsHtml(partId, '') + '</div></div>'
    + '</div>';
}
function setBomPart(dd, partId){
  var hid = dd.querySelector('input[type=hidden][name="bom_part_id[]"]');
  hid.value = partId || '';
  dd.dataset.pid = partId || 0;
  var chips = dd.querySelector('.chip-dd-chips');
  chips.innerHTML = partId ? bomPartSelectedChipHtml(partId) : '';
  var filt = dd.querySelector('.bom-part-filter');
  if (filt) {
    filt.value = '';
    filt.placeholder = partId ? '' : 'ค้นหาอะไหล่…';
  }
  dd.querySelector('.chip-dd-opts').innerHTML = bomPartOptsHtml(partId, '');
}
document.addEventListener('focusin', function(e){
  if (!e.target.classList || !e.target.classList.contains('bom-part-filter')) return;
  var dd = e.target.closest('.bom-part-dd');
  if (!dd) return;
  var selId = parseInt(dd.dataset.pid, 10) || 0;
  dd.querySelector('.chip-dd-opts').innerHTML = bomPartOptsHtml(selId, e.target.value.trim());
  dd.querySelector('.chip-dd-list').hidden = false;
  dd.classList.add('chip-dd-open');
});
document.addEventListener('click', function(e){
  var btn = e.target.closest('.bom-part-dd .chip-dd-btn');
  if (btn) {
    var dd = btn.closest('.bom-part-dd');
    var list = dd.querySelector('.chip-dd-list');
    var selId = parseInt(dd.dataset.pid, 10) || 0;
    var filtInp = dd.querySelector('.bom-part-filter');
    if (list.hidden) {
      dd.querySelector('.chip-dd-opts').innerHTML = bomPartOptsHtml(selId, filtInp ? filtInp.value.trim() : '');
      list.hidden = false;
      dd.classList.add('chip-dd-open');
      if (filtInp) filtInp.focus();
    } else {
      list.hidden = true;
      dd.classList.remove('chip-dd-open');
    }
    e.preventDefault();
    return;
  }
  var row = e.target.closest('.bom-part-dd .ma-part-opt-row[data-pid]');
  if (row) {
    var dd2 = row.closest('.bom-part-dd');
    setBomPart(dd2, parseInt(row.dataset.pid, 10));
    dd2.querySelector('.chip-dd-list').hidden = true;
    dd2.classList.remove('chip-dd-open');
    return;
  }
});
document.addEventListener('input', function(e){
  if (!e.target.classList || !e.target.classList.contains('bom-part-filter')) return;
  var dd = e.target.closest('.bom-part-dd');
  var selId = parseInt(dd.dataset.pid, 10) || 0;
  dd.querySelector('.chip-dd-opts').innerHTML = bomPartOptsHtml(selId, e.target.value.trim());
  dd.querySelector('.chip-dd-list').hidden = false;
  dd.classList.add('chip-dd-open');
});
function bomRowHtml(partId, qty){
  return '<div class="bom-row">'
    + bomPartChipHtml(partId)
    + qtyStepHtml('bom_qty[]', qty || 1, 0.5, 0.5)
    + '<button type="button" class="btn-sm btn-line" onclick="this.closest(\'.bom-row\').remove()">ลบ</button>'
    + '</div>';
}
function renderBom(d){
  bomAllParts = d.all_parts || [];
  var rows = (d.bom || []).map(function(b){ return bomRowHtml(b.part_id, b.qty); }).join('');
  document.getElementById('bom-fields').innerHTML =
    '<div id="bom-list">' + rows + '</div>'
    + '<button type="button" class="btn btn-line btn-sm" style="margin-top:6px" onclick="addBomRow()">➕ เพิ่มอะไหล่</button>';
}
function addBomRow(){
  document.getElementById('bom-list').insertAdjacentHTML('beforeend', bomRowHtml(0, 1));
}

// ---------- popup ยืนยันก่อนบันทึก ----------
function setChecklistHidden(){
  var checked = [];
  document.querySelectorAll('#chk-list input[type=checkbox]:checked').forEach(function(c){ checked.push(c.dataset.item); });
  document.getElementById('checklist_hidden').value = checked.join(' , ');
}
function rowHtml(k, v){ return '<tr><td class="muted" style="white-space:nowrap; vertical-align:top">' + k + '</td><td>' + v + '</td></tr>'; }
function buildConfirm(){
  var val = function(sel){ var el = document.querySelector(sel); return el ? el.value.trim() : ''; };
  var prodEl = document.querySelector('#prod-pick .pp.sel .ppn');
  var prodName = prodEl ? prodEl.textContent : '-';
  // เครื่อง
  var machines = [];
  if (cfg && cfg.mode === 'generated') {
    document.querySelectorAll('.gen-preview').forEach(function(c){ machines.push(c.textContent); });
  } else {
    document.querySelectorAll('input[name="serials[]"]').forEach(function(i){ if (i.value.trim()) machines.push(i.value.trim()); });
  }
  var mcount = machines.length;
  var html = '<table class="list">';
  html += rowHtml('รุ่นสินค้า', '<b>' + esc(prodName) + '</b>');
  html += rowHtml('วันที่ผลิต', esc(val('#produced_at')));
  html += rowHtml('จำนวนเครื่อง', '<b>' + mcount + '</b> เครื่อง' + (machines.length ? '<br><span class="muted">' + machines.map(esc).join(', ') + '</span>' : ''));
  html += rowHtml('ผู้ผลิต/ประกอบ', esc(val('[name="made_by"]')) || '-');
  if (val('[name="fw_version"]')) html += rowHtml('Firmware', esc(val('[name="fw_version"]')));
  if (val('[name="lot_label"]')) html += rowHtml('Lot', esc(val('[name="lot_label"]')));
  html += '</table>';
  // ข้อมูลประจำรุ่น
  var dynRows = '';
  document.querySelectorAll('#dyn-fields input[name="field_names[]"]').forEach(function(nm){
    var wrap = nm.closest('.chip-dd') || nm.closest('.ppl');
    var vi = wrap ? (wrap.querySelector('input[data-chip-val]') || wrap.querySelector('[name="field_values[]"]')) : null;
    var v = vi ? vi.value.trim() : '';
    if (v) dynRows += rowHtml(esc(nm.value), esc(v));
  });
  if (dynRows) html += '<h3 style="margin:14px 0 6px">ข้อมูลประจำรุ่น</h3><table class="list">' + dynRows + '</table>';
  // ชุดอะไหล่ที่จะเบิก (× จำนวนเครื่อง)
  var bomRows = '';
  document.querySelectorAll('#bom-list .bom-row').forEach(function(r){
    var hid = r.querySelector('input[name="bom_part_id[]"]');
    var qtyInp = r.querySelector('.qty-stepper input') || r.querySelector('input[name="bom_qty[]"]');
    var qty = qtyInp ? (parseFloat(qtyInp.value) || 0) : 0;
    if (hid && hid.value && qty > 0) {
      var pid = parseInt(hid.value, 10);
      var p = bomPartById(pid);
      var nm = p ? p.name + (p.unit ? ' (' + p.unit + ')' : '') : '-';
      bomRows += rowHtml(esc(nm), qty + ' × ' + mcount + ' = <b>' + (Math.round(qty * mcount * 100) / 100) + '</b>');
    }
  });
  if (bomRows) html += '<h3 style="margin:14px 0 6px">🔩 อะไหล่ที่จะเบิก (รวมทั้งชุด)</h3><table class="list">' + bomRows + '</table>';
  // checklist / ปัญหา / แก้ไข / หมายเหตุ
  var chk = [];
  document.querySelectorAll('#chk-list input[type=checkbox]:checked').forEach(function(c){ chk.push(c.dataset.item); });
  var extra = '';
  if (chk.length) extra += rowHtml('Checklist ✓', chk.map(esc).join(' · '));
  if (val('[name="problems_found"]')) extra += rowHtml('ปัญหาที่พบ', esc(val('[name="problems_found"]')));
  if (val('[name="fix"]')) extra += rowHtml('การแก้ไข', esc(val('[name="fix"]')));
  if (val('[name="note"]')) extra += rowHtml('หมายเหตุ', esc(val('[name="note"]')));
  if (extra) html += '<h3 style="margin:14px 0 6px">ตรวจสอบ / หมายเหตุ</h3><table class="list">' + extra + '</table>';
  document.getElementById('confirm-body').innerHTML = html;
}
var confirmedSubmit = false;
document.getElementById('mainform').addEventListener('submit', function(e){
  setChecklistHidden();
  if (confirmedSubmit) return;      // ยืนยันแล้ว ปล่อยบันทึกจริง
  e.preventDefault();               // ครั้งแรก: โชว์ popup ตรวจสอบก่อน
  buildConfirm();
  document.getElementById('confirm-error').hidden = true;
  document.getElementById('confirm-error').textContent = '';
  document.getElementById('confirm-overlay').hidden = false;
});
function doConfirmSubmit(){
  var errBox = document.getElementById('confirm-error');
  var btn = document.getElementById('confirm-submit-btn');
  errBox.hidden = true;
  errBox.textContent = '';
  btn.disabled = true;
  btn.textContent = 'กำลังตรวจสอบ…';
  var fd = new FormData(document.getElementById('mainform'));
  fetch(BASE + '/asset_new.php?ajax=precheck', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(d){
      btn.disabled = false;
      btn.textContent = '✅ ยืนยันบันทึก';
      if (!d.ok) {
        errBox.textContent = d.message || 'ไม่สามารถบันทึกได้ — กรุณาตรวจสอบรหัสเครื่อง';
        errBox.hidden = false;
        errBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        return;
      }
      confirmedSubmit = true;
      document.getElementById('confirm-overlay').hidden = true;
      document.getElementById('mainform').submit();
    })
    .catch(function(){
      btn.disabled = false;
      btn.textContent = '✅ ยืนยันบันทึก';
      errBox.textContent = 'ตรวจสอบรหัสไม่สำเร็จ — กรุณาลองใหม่หรือตรวจสอบการเชื่อมต่อ';
      errBox.hidden = false;
    });
}

function updateAssetHints(){
  var hintEl = document.getElementById('last-asset-hint');
  if (!cfg) { hintEl.style.display = 'none'; return; }
  var lines = [];
  if (cfg.last_asset_code) {
    lines.push('เครื่องล่าสุดในระบบ: ' + cfg.last_asset_code + ' — ค่าฟอร์มด้านล่างดึงจากเครื่องนี้');
  }
  if (cfg.mode === 'generated' && unitCount > 0) {
    var next = previewCode(0);
    lines.push('หมายเลขถัดไป (ตามวันที่ผลิตที่เลือก): ' + next
      + (unitCount > 1 ? ' · เครื่องที่ ' + unitCount + ': ' + previewCode(unitCount - 1) : ''));
  }
  if (lines.length) {
    hintEl.innerHTML = lines.join('<br>');
    hintEl.style.display = '';
  } else {
    hintEl.style.display = 'none';
  }
}

// ---------- รายการเครื่อง (+ / สแกน) ----------
function previewCode(idx){
  if (!cfg || cfg.mode !== 'generated') return '?';
  var dt = new Date(document.getElementById('produced_at').value || new Date());
  var out = '';
  if (cfg.usePrefix && cfg.prefix) out += cfg.prefix;
  if (cfg.useYear) {
    var y = dt.getFullYear(); if (cfg.era === 'be') y += 543;
    out += String(y).slice(-2);
  }
  if (cfg.useMonth) out += ('0' + (dt.getMonth() + 1)).slice(-2);
  var run = String(cfg.nextRun + idx);
  var digits = cfg.digits || 4;
  while (run.length < digits) run = '0' + run;
  return out + run;
}
function addUnit(){
  if (!cfg) return;
  var list = document.getElementById('unit-list');
  var row = document.createElement('div');
  row.className = 'unit-row';
  row.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:6px';
  unitCount++;
  if (cfg.mode === 'generated') {
    row.innerHTML = '<span class="badge st-new">#' + unitCount + '</span>'
      + '<code class="gen-preview" style="font-size:15px; font-weight:600"></code>'
      + '<button type="button" class="btn-sm btn-line" style="margin-left:auto" onclick="removeUnit(this)">ลบ</button>';
    document.getElementById('gen_count').value = unitCount;
  } else {
    row.innerHTML = '<span class="badge st-new">#' + unitCount + '</span>'
      + '<input type="text" name="serials[]" placeholder="หมายเลขสินค้า" style="flex:1; min-width:0" required autocomplete="off">'
      + '<div style="display:flex; gap:8px; margin-left:auto; flex-shrink:0">'
      + '<button type="button" class="btn-sm btn-line" onclick="scanInto(this)">📷 สแกน</button>'
      + '<button type="button" class="btn-sm btn-line" onclick="removeUnit(this)">ลบ</button>'
      + '</div>';
    list.appendChild(row);
    var serialInp = row.querySelector('input[name="serials[]"]');
    if (serialInp) serialInp.focus();
    refreshPreviews();
    return;
  }
  list.appendChild(row);
  refreshPreviews();
}
document.getElementById('unit-list').addEventListener('keydown', function(e){
  if (e.key !== 'Enter') return;
  var inp = e.target;
  if (!inp.matches || !inp.matches('input[name="serials[]"]')) return;
  if (!cfg || cfg.mode === 'generated') return;
  e.preventDefault();
  e.stopPropagation();
  addUnit();
});
function removeUnit(btn){
  var row = btn.closest('.unit-row');
  if (row) row.remove();
  else btn.parentNode.remove();
  var rows = document.getElementById('unit-list').children;
  unitCount = rows.length;
  document.getElementById('gen_count').value = (cfg && cfg.mode === 'generated') ? unitCount : 0;
  for (var i = 0; i < rows.length; i++) rows[i].querySelector('.badge').textContent = '#' + (i + 1);
  refreshPreviews();
}
function refreshPreviews(){
  if (!cfg || cfg.mode !== 'generated') {
    updateAssetHints();
    return;
  }
  var codes = document.querySelectorAll('.gen-preview');
  for (var i = 0; i < codes.length; i++) codes[i].textContent = previewCode(i);
  updateAssetHints();
}

// ---------- สแกน QR เข้าช่องกรอก ----------
var qrScanner = null, qrTarget = null;
function scanInto(btn){
  var row = btn.closest('.unit-row');
  qrTarget = row ? row.querySelector('input[name="serials[]"]') : btn.parentNode.querySelector('input[name="serials[]"]');
  document.getElementById('qr-overlay').hidden = false;
  document.getElementById('qr-status').textContent = 'กำลังเปิดกล้อง…';
  if (typeof Html5Qrcode === 'undefined') {
    document.getElementById('qr-status').textContent = 'โหลดตัวสแกนไม่ได้ (ไม่มีอินเทอร์เน็ต) — พิมพ์รหัสเองได้';
    return;
  }
  qrScanner = new Html5Qrcode('qr-reader');
  qrScanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: 200 },
    function(text){
      if (qrTarget) qrTarget.value = text.trim();
      stopScan();
    }, function(){}
  ).then(function(){ document.getElementById('qr-status').textContent = 'เล็งกล้องไปที่ QR/Barcode'; })
   .catch(function(e){ document.getElementById('qr-status').textContent = 'เปิดกล้องไม่ได้: ' + e; });
}
function stopScan(){
  document.getElementById('qr-overlay').hidden = true;
  if (qrScanner) { try { qrScanner.stop(); } catch(e){} qrScanner = null; }
}
</script>
<?php page_footer();
