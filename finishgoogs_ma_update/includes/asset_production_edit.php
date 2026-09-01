<?php

/**

 * asset_production_edit.php — ฟอร์มแก้ไขข้อมูลผลิตบนหน้า asset.php

 *

 * โหลดค่าจาก production_records / asset_components / extra_json

 * แสดงฟิลด์ตาม config รุ่น (เหมือนหน้าบันทึกผลิตใหม่ ยกเว้น BOM/เบิกอะไหล่)

 */



/**

 * โหลดบริบทสำหรับฟอร์มแก้ไขข้อมูลผลิต

 *

 * @param int                  $assetId

 * @param int                  $productId

 * @param array<int,array<string,mixed>> $componentRows

 * @param array<string,mixed>            $assetRow    แถว assets ปัจจุบัน

 * @return array<string,mixed>

 */

function asset_production_edit_load(int $assetId, int $productId, array $componentRows, array $assetRow = []): array {

    $assetId = (int)$assetId;

    $productId = (int)$productId;

    $prodRec = qr(

        "SELECT * FROM production_records WHERE asset_id=? ORDER BY id DESC LIMIT 1",

        'i',

        [$assetId]

    )->fetch_assoc();



    $compMap = [];

    foreach ($componentRows as $row) {

        $compMap[(string)$row['component_name']] = (string)$row['component_value'];

    }



    $extras = [];

    if ($prodRec && !empty($prodRec['extra_json'])) {

        $decoded = json_decode((string)$prodRec['extra_json'], true);

        if (is_array($decoded)) {

            $extras = $decoded;

        }

    }



    $std = product_std_fields($productId);

    $showFw = $std['decided'] ? ($std['fw'] !== null) : true;

    $showLot = $std['decided'] ? ($std['lot'] !== null) : true;

    $showMadeBy = $std['decided'] ? ($std['made_by'] !== null) : true;



    $used = [];

    $fields = [];

    foreach (effective_fields($productId, 'production') as $f) {

        $val = '';

        if ($f['kind'] === 'component') {

            $val = $compMap[$f['name']] ?? '';

        } elseif ($f['kind'] === 'ผู้ผลิต') {
            $val = '';
            if (isset($extras[$f['name']])) {
                $val = trim((string)$extras[$f['name']]);
            }
            if ($val === '' || $val === '-') {
                $val = trim((string)($prodRec['made_by'] ?? ''));
            }

        } else {

            $val = (string)($extras[$f['name']] ?? '');

        }

        $fields[] = [

            'name' => $f['name'],

            'kind' => $f['kind'],

            'value' => $val,

            'options' => $f['options'],

        ];

        $used[$f['name']] = true;

    }

    foreach ($compMap as $name => $val) {

        if (isset($used[$name])) {

            continue;

        }

        $fields[] = ['name' => $name, 'kind' => 'component', 'value' => $val, 'options' => []];

        $used[$name] = true;

    }

    foreach ($extras as $name => $val) {

        if (isset($used[$name]) || $name === 'ประเภทบันทึก') {

            continue;

        }

        $fields[] = ['name' => (string)$name, 'kind' => 'extra', 'value' => (string)$val, 'options' => []];

    }



    $checklistItems = effective_production_checklist($productId);

    $checklistSaved = [];

    if ($prodRec && trim((string)($prodRec['checklist'] ?? '')) !== '') {

        foreach (preg_split('/\s*,\s*/', (string)$prodRec['checklist']) as $it) {

            $it = trim($it);

            if ($it !== '' && $it !== '-') {

                $checklistSaved[$it] = true;

            }

        }

    }



    $madeByOpts = [];

    $res = qr(

        "SELECT pr.made_by v FROM production_records pr JOIN assets a ON a.id=pr.asset_id

         WHERE a.product_id=? AND pr.made_by IS NOT NULL AND TRIM(pr.made_by)<>''

         GROUP BY pr.made_by ORDER BY MAX(pr.id) DESC LIMIT 12",

        'i',

        [$productId]

    );

    while ($r = $res->fetch_assoc()) {

        $madeByOpts[] = (string)$r['v'];

    }



    return [

        'prod_rec' => $prodRec,

        'show_fw' => $showFw,

        'show_lot' => $showLot,

        'show_made_by' => $showMadeBy,

        'made_by' => (string)($prodRec['made_by'] ?? ''),

        'fw_version' => trim((string)($prodRec['fw_version'] ?? '')) !== ''

            ? trim((string)$prodRec['fw_version'])

            : trim((string)($assetRow['current_fw_version'] ?? '')),

        'lot_label' => trim((string)($prodRec['lot_label'] ?? '')) !== ''

            ? trim((string)$prodRec['lot_label'])

            : trim((string)($assetRow['lot_label'] ?? '')),

        'problems_found' => (string)($prodRec['problems_found'] ?? ''),

        'fix' => (string)($prodRec['fix'] ?? ''),

        'fields' => $fields,

        'checklist_items' => $checklistItems,

        'checklist_saved' => $checklistSaved,

        'made_by_options' => $madeByOpts,

        'fw_options' => $std['fw']['options'] ?? [],

        'lot_options' => $std['lot']['options'] ?? [],

    ];

}



/**

 * บันทึกข้อมูลผลิต (production_records + components + extra) หลังแก้ไขเครื่อง

 *

 * @param int   $assetId

 * @param int   $productId

 * @param array<string,mixed> $post $_POST

 * @return void

 */

function asset_production_edit_save(int $assetId, int $productId, array $post): void {

    $assetId = (int)$assetId;

    $productId = (int)$productId;



    $madeBy = trim((string)($post['made_by'] ?? ''));

    $fw = trim((string)($post['fw_version'] ?? ''));

    $problems = trim((string)($post['problems_found'] ?? ''));

    $fix = trim((string)($post['fix'] ?? ''));

    $lot = trim((string)($post['lot_label'] ?? ''));



    $chkPosted = (array)($post['checklist_item'] ?? []);

    $chkLines = [];

    foreach ($chkPosted as $it) {

        $it = trim((string)$it);

        if ($it !== '' && !in_array($it, $chkLines, true)) {

            $chkLines[] = $it;

        }

    }

    $checklist = $chkLines ? implode(', ', $chkLines) : '';



    $fNames = (array)($post['field_names'] ?? []);

    $fKinds = (array)($post['field_kinds'] ?? []);

    $fVals = (array)($post['field_values'] ?? []);

    $components = [];

    $extras = [];

    foreach ($fNames as $i => $fn) {

        $fn = trim((string)$fn);

        $fv = trim((string)($fVals[$i] ?? ''));

        if ($fn === '' || $fv === '') {

            continue;

        }

        $kind = (string)($fKinds[$i] ?? 'extra');

        if ($kind === 'component') {

            $components[$fn] = $fv;

        } else {

            $extras[$fn] = $fv;

        }

    }

    $extraJson = $extras ? json_encode($extras, JSON_UNESCAPED_UNICODE) : null;



    $prodRec = qr(

        "SELECT id FROM production_records WHERE asset_id=? ORDER BY id DESC LIMIT 1",

        'i',

        [$assetId]

    )->fetch_assoc();



    if ($prodRec) {

        q(

            "UPDATE production_records SET made_by=?, fw_version=?, problems_found=?, fix=?, checklist=?, lot_label=?, extra_json=?

             WHERE id=? AND asset_id=?",

            'sssssssii',

            [

                $madeBy !== '' ? $madeBy : null,

                $fw !== '' ? $fw : null,

                $problems !== '' ? $problems : null,

                $fix !== '' ? $fix : null,

                $checklist !== '' ? $checklist : null,

                $lot !== '' ? $lot : null,

                $extraJson,

                (int)$prodRec['id'],

                $assetId,

            ]

        );

    } else {

        q(

            "INSERT INTO production_records (asset_id,recorded_at,made_by,fw_version,problems_found,fix,checklist,lot_label,extra_json)

             VALUES (?,NOW(),?,?,?,?,?,?,?)",

            'isssssss',

            [

                $assetId,

                $madeBy !== '' ? $madeBy : null,

                $fw !== '' ? $fw : null,

                $problems !== '' ? $problems : null,

                $fix !== '' ? $fix : null,

                $checklist !== '' ? $checklist : null,

                $lot !== '' ? $lot : null,

                $extraJson,

            ]

        );

    }



    q("DELETE FROM asset_components WHERE asset_id=?", 'i', [$assetId]);

    foreach ($components as $cn => $cv) {

        q(

            "INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)",

            'iss',

            [$assetId, $cn, $cv]

        );

    }



    if ($fw !== '') {

        q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$fw, $assetId]);

    }

}



/**

 * แสดงช่องกรอกพร้อม datalist (ถ้ามีตัวเลือก)

 *

 * @param string $inputName

 * @param string $value

 * @param array<int,string> $options

 * @param string $hiddenHtml

 * @param string $listId

 * @return string

 */

function asset_production_edit_field_input($inputName, $value, array $options, $hiddenHtml, $listId) {

    $out = $hiddenHtml;

    $out .= '<input type="text" name="' . h($inputName) . '" value="' . h($value) . '" style="width:100%"';

    if ($options) {

        $out .= ' list="' . h($listId) . '"';

    }

    $out .= '>';

    if ($options) {

        $out .= '<datalist id="' . h($listId) . '">';

        foreach ($options as $o) {

            if ($o === '') {

                continue;

            }

            $out .= '<option value="' . h($o) . '">';

        }

        $out .= '</datalist>';

    }

    return $out;

}



/**

 * แถว label + ค่า สำหรับ asset-head (แสดงอย่างเดียว)

 *

 * @param string $label หัวข้อ

 * @param string $valueHtml เนื้อหา (escape แล้วหรือ HTML ที่ปลอดภัย)

 * @return string

 */

function asset_head_row_html(string $label, string $valueHtml): string {

    if ($valueHtml === '') {

        return '';

    }

    return '<dt>' . h($label) . '</dt><dd class="asset-dd-span">' . $valueHtml . '</dd>';

}



/**
 * ตรวจว่าฟิลด์เป็นชื่อผู้ผลิต (Board *) ไม่ใช่เวอร์ชันฮาร์ดแวร์
 *
 * @param string $name ชื่อฟิลด์
 * @param string $kind field_kind จาก config
 * @return bool
 */
function asset_head_is_hw_manufacturer_field(string $name, string $kind = 'component'): bool
{
    if ($kind === 'ผู้ผลิต') {
        return true;
    }
    return (bool) preg_match('/^Board\s+/ui', trim($name));
}



/**
 * รายการชิ้นส่วนฮาร์ดแวร์สำหรับแสดงบน asset-head — เฉพาะเวอร์ชัน/ค่าที่มีข้อมูล
 *
 * @param array<string,mixed>            $ctx           จาก asset_production_edit_load()
 * @param array<int,array<string,mixed>> $componentRows จาก asset_components
 * @return array<int,array{name:string,value:string}>
 */
function asset_head_hardware_field_rows(array $ctx, array $componentRows): array
{
    $rows = [];
    $seen = [];

    foreach ((array)($ctx['fields'] ?? []) as $f) {
        $kind = (string)($f['kind'] ?? '');
        if ($kind !== 'component') {
            continue;
        }
        $name = (string)($f['name'] ?? '');
        if ($name === '' || asset_head_is_hw_manufacturer_field($name, $kind)) {
            continue;
        }
        $val = trim((string)($f['value'] ?? ''));
        if ($val === '' || $val === '-') {
            continue;
        }
        $rows[] = ['name' => $name, 'value' => $val];
        $seen[$name] = true;
    }

    foreach ($componentRows as $c) {
        $name = (string)($c['component_name'] ?? '');
        if ($name === '' || isset($seen[$name]) || asset_head_is_hw_manufacturer_field($name)) {
            continue;
        }
        $val = trim((string)($c['component_value'] ?? ''));
        if ($val === '' || $val === '-') {
            continue;
        }
        $rows[] = ['name' => $name, 'value' => $val];
        $seen[$name] = true;
    }

    return $rows;
}



/**
 * HTML ส่วน asset-head — จัดกลุ่มข้อมูลเครื่อง / บันทึกผลิต / ฮาร์ดแวร์ (read-only)
 *
 * @param array<string,mixed>            $assetRow

 * @param array<string,mixed>            $ctx

 * @param array<int,array<string,mixed>> $componentRows

 * @return string

 */

function asset_head_dl_html(array $assetRow, array $ctx, array $componentRows): string {

    $out = '';



    $out .= '<dt class="asset-dl-section">ข้อมูลเครื่อง</dt>';

    $codeHtml = '<b>' . h($assetRow['asset_code']) . '</b>';

    if (!empty($assetRow['running_no'])) {

        $codeHtml .= '<span class="muted" style="font-size:12px"> (running ' . (int)$assetRow['running_no'] . ')</span>';

    }

    $out .= asset_head_row_html('รหัสเครื่อง', $codeHtml);

    $out .= asset_head_row_html('รุ่น', h($assetRow['pname']));

    $out .= asset_head_row_html('สถานะ', status_badge($assetRow['status']));

    $producedAt = trim((string)($assetRow['produced_at'] ?? ''));
    $producedSrc = '';
    if (!empty($ctx['prod_rec']['recorded_at'])) {
        $producedSrc = (string)$ctx['prod_rec']['recorded_at'];
        $produced = dthai_full($producedSrc);
    } elseif ($producedAt !== '') {
        $producedSrc = $producedAt;
        $produced = dthai_full($producedSrc);
    } else {
        $produced = '';
    }

    if ($assetRow['lot_label']) {

        $produced .= ' <span class="muted">(Lot ' . h($assetRow['lot_label']) . ')</span>';

    }

    $out .= asset_head_row_html('ผลิตเมื่อ', $produced);

    $ageText = dt_age_text($producedSrc);
    if ($ageText !== '') {
        $out .= asset_head_row_html('อายุสินค้า', h($ageText));
    }

    $fw = trim((string)($assetRow['current_fw_version'] ?? ''));

    if ($fw !== '' && $fw !== '-') {

        $out .= asset_head_row_html('FW ปัจจุบัน', h($fw));

    }

    $note = trim((string)($assetRow['note'] ?? ''));

    if ($note !== '' && $note !== '-') {

        $out .= asset_head_row_html('หมายเหตุ', nl2br(h($note)));

    }



    if (!empty($ctx['prod_rec'])) {

        $prod = $ctx['prod_rec'];

        $prodBuf = '<dt class="asset-dl-section">ข้อมูลบันทึกผลิต</dt>';



        if (!empty($ctx['show_made_by'])) {

            $madeBy = trim((string)($ctx['made_by'] ?? ''));

            if ($madeBy !== '' && $madeBy !== '-') {

                $prodBuf .= asset_head_row_html('ผู้ผลิต', h($madeBy));

            }

        }

        $assembly = trim((string)($prod['assembly_by'] ?? ''));

        if ($assembly !== '' && $assembly !== '-') {

            $prodBuf .= asset_head_row_html('ประกอบ', h($assembly));

        }
        $prodLot = trim((string)($ctx['lot_label'] ?? ''));
        if ($prodLot !== '' && $prodLot !== '-') {
            $prodBuf .= asset_head_row_html('Lot', h($prodLot));
        }

        foreach ($ctx['fields'] ?? [] as $f) {
            $kind = (string)($f['kind'] ?? '');
            if ($kind === 'component') {
                continue;
            }
            $name = (string)$f['name'];
            if ($kind === 'ผู้ผลิต' && ($name === 'ผู้ผลิต' || $name === 'ผู้ผลิต/ประกอบ')) {
                continue;
            }
            $val = trim((string)($f['value'] ?? ''));
            if ($val === '' || $val === '-') {
                continue;
            }
            $prodBuf .= asset_head_row_html($name, h($val));
        }

        $prob = trim((string)($ctx['problems_found'] ?? ''));
        if ($prob !== '' && $prob !== '-') {
            $prodBuf .= asset_head_row_html('ปัญหาที่พบ', nl2br(h($prob)));
        }
        $fix = trim((string)($ctx['fix'] ?? ''));
        if ($fix !== '' && $fix !== '-') {
            $prodBuf .= asset_head_row_html('การแก้ไข', nl2br(h($fix)));
        }

        $out .= $prodBuf;

    }



    $hwRows = asset_head_hardware_field_rows($ctx, $componentRows);

    if ($hwRows) {

        $out .= '<dt class="asset-dl-section">ชิ้นส่วนฮาร์ดแวร์</dt>';

        foreach ($hwRows as $c) {

            $out .= asset_head_row_html((string)$c['name'], h((string)$c['value']));

        }

    }



    return $out;

}



/** @deprecated ใช้ asset_head_dl_html() แทน */

function asset_production_head_rows_html(array $ctx): string {

    return '';

}



/**

 * HTML ฟอร์มแก้ไขข้อมูลผลิตครบ (ยกเว้น BOM)

 *

 * @param array<string,mixed> $assetRow แถว assets + pname

 * @param array<string,mixed> $ctx      จาก asset_production_edit_load()

 * @param array<int,array<string,mixed>> $productList

 * @param array<int,string> $fwSuggest ตัวเลือก FW สำหรับ datalist

 * @return string

 */

function asset_production_edit_form_html(array $assetRow, array $ctx, array $productList, array $fwSuggest = []): string {

    ob_start();

    ?>

    <form method="post" class="formgrid form-wide asset-edit-prod-form" style="margin-top:10px">

      <?= csrf_field() ?><input type="hidden" name="edit_asset" value="1">

      <label>รหัสเครื่อง</label>

      <input type="text" name="asset_code" value="<?= h($assetRow['asset_code']) ?>" required>



      <label>รุ่นสินค้า</label>

      <select name="product_id">

        <?php foreach ($productList as $po) { ?>

        <option value="<?= (int)$po['id'] ?>" <?= (int)$po['id'] === (int)$assetRow['product_id'] ? 'selected' : '' ?>><?= h($po['name']) ?></option>

        <?php } ?>

      </select>



      <label>สถานะเครื่อง</label>

      <select name="asset_status">

        <?php foreach (status_list() as $s) { ?>

        <option value="<?= h($s) ?>" <?= $assetRow['status'] === $s ? 'selected' : '' ?>><?= h(status_th($s)) ?></option>

        <?php } ?>

      </select>



      <label>วันที่ผลิต</label>

      <input type="date" name="produced_at" value="<?= h($assetRow['produced_at']) ?>">



      <?php if (!empty($ctx['show_made_by'])) { ?>

      <label>ผู้ผลิต/ประกอบ</label>

      <?= asset_production_edit_field_input(

          'made_by',

          (string)$ctx['made_by'],

          (array)$ctx['made_by_options'],

          '',

          'asset-edit-madeby'

      ) ?>

      <?php } else { ?>

      <input type="hidden" name="made_by" value="<?= h($ctx['made_by']) ?>">

      <?php } ?>



      <?php if (!empty($ctx['show_fw'])) { ?>

      <label>FW ปัจจุบัน / เวอร์ชัน Firmware</label>

      <?= asset_production_edit_field_input(

          'fw_version',

          (string)$ctx['fw_version'],

          array_values(array_unique(array_merge((array)$ctx['fw_options'], $fwSuggest))),

          '',

          'asset-edit-fw'

      ) ?>

      <?php } else { ?>

      <input type="hidden" name="fw_version" value="<?= h($ctx['fw_version']) ?>">

      <?php } ?>



      <?php if (!empty($ctx['show_lot'])) { ?>

      <label>Lot</label>

      <?= asset_production_edit_field_input(

          'lot_label',

          (string)($ctx['lot_label'] ?? $assetRow['lot_label'] ?? ''),

          (array)$ctx['lot_options'],

          '',

          'asset-edit-lot'

      ) ?>

      <?php } elseif (trim((string)($assetRow['lot_label'] ?? '')) !== '' || trim((string)($ctx['lot_label'] ?? '')) !== '') { ?>

      <label>Lot</label>

      <input type="text" name="lot_label" value="<?= h($ctx['lot_label'] ?? $assetRow['lot_label'] ?? '') ?>">

      <?php } else { ?>

      <input type="hidden" name="lot_label" value="">

      <?php } ?>



      <?php

      $idx = 0;

      foreach ((array)($ctx['fields'] ?? []) as $f) {

          $idx++;

          $hidden = '<input type="hidden" name="field_names[]" value="' . h($f['name']) . '">'

                  . '<input type="hidden" name="field_kinds[]" value="' . h($f['kind']) . '">';

          ?>

      <label><?= h($f['name']) ?> <span class="muted" style="font-weight:400">(<?= h($f['kind']) ?>)</span></label>

      <?= asset_production_edit_field_input('field_values[]', (string)$f['value'], (array)$f['options'], $hidden, 'asset-edit-f' . $idx) ?>

      <?php } ?>



      <label class="full">ปัญหาที่พบ (ถ้ามี)</label>

      <textarea name="problems_found" class="full field-note" rows="2"><?= h($ctx['problems_found']) ?></textarea>



      <label class="full">การแก้ไข (ถ้ามี)</label>

      <textarea name="fix" class="full field-note" rows="2"><?= h($ctx['fix']) ?></textarea>



      <label class="full">หมายเหตุประจำเครื่อง</label>

      <textarea name="note" class="full field-note" rows="2"><?= h($assetRow['note']) ?></textarea>



      <?php if (!empty($ctx['checklist_items'])) { ?>

      <label class="full">Checklist ที่ตรวจแล้ว</label>

      <div class="full chk-list">

        <?php

        $saved = (array)($ctx['checklist_saved'] ?? []);

        $hasSaved = count($saved) > 0;

        foreach ((array)$ctx['checklist_items'] as $it) {

            $checked = $hasSaved ? !empty($saved[$it]) : true;

            ?>

        <label class="chk-item"><input type="checkbox" name="checklist_item[]" value="<?= h($it) ?>"<?= $checked ? ' checked' : '' ?>><span><?= h($it) ?></span></label>

        <?php } ?>

      </div>

      <?php } ?>



      <p class="full muted" style="font-size:12px;margin:0">ชุดอะไหล่/BOM และประวัติการเบิก — จัดการที่ส่วน「อะไหล่ที่เบิกใช้กับเครื่องนี้」ด้านล่าง (ไม่รวมในฟอร์มนี้)</p>



      <div class="full">

        <button type="submit" class="btn-with-icon"><?= ui_btn_label('save', 'บันทึกการแก้ไข') ?></button>

      </div>

    </form>

    <?php

    return ob_get_clean();

}

