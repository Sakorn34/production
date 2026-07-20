<?php
/**
 * includes/list_search.php — helper ฟอร์มค้นหารายการ (ใช้ร่วมหลายหน้า)
 *
 * ตัวอย่าง:
 *   list_search_form([
 *     ['name' => 'q', 'placeholder' => 'หมายเลขเครื่อง', 'value' => $q, 'width' => '160px'],
 *   ], BASE_URL . '/ma.php?product=1');
 */

/**
 * แสดงฟอร์มค้นหาแบบ GET พร้อม hidden fields และปุ่มล้าง
 *
 * @param array<int, array{name:string, placeholder?:string, value?:string, width?:string, type?:string}> $fields
 * @param string $clearUrl URL สำหรับปุ่มล้าง (ว่าง = ไม่แสดง)
 * @param array<string, scalar|null> $hidden ค่า hidden อื่นๆ
 * @return void
 */
function list_search_form(array $fields, $clearUrl = '', array $hidden = []) {
    $hasValue = false;
    foreach ($fields as $f) {
        if (trim((string)(isset($f['value']) ? $f['value'] : '')) !== '') {
            $hasValue = true;
            break;
        }
    }
    echo '<form class="filter list-search-form" method="get" style="flex-wrap:wrap; gap:6px; margin-bottom:12px">';
    foreach ($hidden as $k => $v) {
        if ($v === null || $v === '') continue;
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
    }
    foreach ($fields as $f) {
        $type = isset($f['type']) ? $f['type'] : 'text';
        $w = isset($f['width']) ? $f['width'] : 'min(200px, 100%)';
        $val = isset($f['value']) ? $f['value'] : '';
        echo '<input type="' . h($type) . '" name="' . h($f['name']) . '" value="' . h($val) . '"'
           . ' placeholder="' . h(isset($f['placeholder']) ? $f['placeholder'] : '') . '"'
           . ' style="width:' . h($w) . '">';
    }
    echo '<button type="submit" class="btn-with-icon">' . ui_btn_label('search', 'ค้นหา') . '</button>';
    if ($hasValue && $clearUrl !== '') {
        echo ' <a href="' . h($clearUrl) . '" class="btn btn-line btn-sm" style="align-self:center">ล้าง</a>';
    }
    echo '</form>';
}

/**
 * จัดกลุ่ม timeline ตามประเภทการทำรายการ
 *
 * @param array<int, array{d:string, type:string, html:string}> $tl
 * @return array<string, array<int, array{d:string, type:string, html:string}>>
 */
function timeline_group_by_type(array $tl) {
    $groups = [];
    foreach ($tl as $e) {
        $key = isset($e['type_key']) ? ui_timeline_group_key($e['type_key']) : timeline_type_group_legacy($e['type'] ?? '');
        if (!isset($groups[$key])) $groups[$key] = [];
        $groups[$key][] = $e;
    }
    foreach ($groups as $g => $items) {
        $groups[$g] = timeline_sort_items($items);
    }
    return $groups;
}

/**
 * เรียงรายการ timeline ตามวันที่ (ใหม่ → เก่า)
 *
 * @param array<int, array{d:string}> $items
 * @return array<int, array{d:string}>
 */
function timeline_sort_items(array $items) {
    usort($items, function ($x, $y) { return strcmp($y['d'], $x['d']); });
    return $items;
}

/**
 * ตัดกลุ่มออกจาก timeline (เช่น ไม่แสดงเบิกอะไหล่ซ้ำเมื่อมีตารางด้านบนแล้ว)
 *
 * @param array<int, array<string,mixed>> $tl
 * @param array<int, string> $groupKeys กลุ่มจาก ui_timeline_group_key เช่น 'parts'
 * @return array<int, array<string,mixed>>
 */
function timeline_exclude_groups(array $tl, array $groupKeys) {
    if ($groupKeys === []) {
        return $tl;
    }
    $skip = array_flip($groupKeys);
    return array_values(array_filter($tl, function ($e) use ($skip) {
        $key = isset($e['type_key']) ? ui_timeline_group_key($e['type_key']) : timeline_type_group_legacy($e['type'] ?? '');
        return !isset($skip[$key]);
    }));
}

/**
 * ลำดับคอลัมน์กลุ่มประวัติมาตรฐาน
 *
 * @return array<int, string>
 */
function timeline_group_order() {
    return ui_timeline_group_order();
}

/**
 * แปลงหัวข้อ timeline แบบเก่า (emoji) → กลุ่ม — รองรับข้อมูล legacy
 *
 * @param string $type
 * @return string
 */
function timeline_type_group_legacy($type) {
    static $map = [
        '🏭 บันทึกผลิต / QC' => 'production',
        '📲 อัปเดต Firmware' => 'update',
        '🔩 อัปเดต Hardware' => 'update',
        '⚙️ อัปเดต' => 'update',
        '📅 เข้า MA' => 'ma',
        '🔧 งานซ่อม' => 'repair',
        '📥 เข้าคลัง' => 'stock',
        '📤 ออกจากคลัง' => 'stock',
        '🔁 ถูกยืมเป็นเครื่องสำรอง' => 'spare',
        '🔩 เบิกอะไหล่ใช้กับเครื่องนี้' => 'parts',
        '↩️ คืนอะไหล่' => 'parts',
    ];
    return isset($map[$type]) ? $map[$type] : 'other';
}

/** @deprecated ใช้ ui_timeline_group_key แทน */
function timeline_type_group($type) {
    return timeline_type_group_legacy($type);
}

/**
 * แสดงรายการใน 1 คอลัมน์ของ board ประวัติ
 *
 * @param string $title ชื่อกลุ่ม
 * @param array<int, array{d:string, type:string, html:string}> $items
 * @param callable|null $actionsFn ฟังก์ชันสร้างปุ่มแก้ไข/ลบ (รับ $e, $assetId)
 * @param int $assetId
 * @return void
 */
function timeline_board_column($title, array $items, $actionsFn = null, $assetId = 0) {
    echo '<div class="tl-col">';
    $headHtml = (strpos($title, '<') !== false) ? $title : ui_timeline_group_title_html($title);
    echo '<div class="tl-col-head">' . $headHtml . ' <span class="muted">(' . count($items) . ')</span></div>';
    echo '<ul class="timeline tl-col-list">';
    foreach ($items as $e) {
        $actions = ($actionsFn && is_callable($actionsFn)) ? $actionsFn($e, $assetId) : '';
        echo '<li><div class="tl-date">' . dthai_full($e['d']) . '</div>'
           . '<div class="tl-type">' . $e['type'] . '</div>'
           . '<div class="tl-body">' . $e['html'] . $actions . '</div></li>';
    }
    echo '</ul></div>';
}

/**
 * แสดง timeline แบบจัดกลุ่มตามประเภท (details/summary แนวตั้ง)
 *
 * @param array<int, array{d:string, type:string, html:string}> $tl
 * @param callable|null $actionsFn
 * @param int $assetId
 * @return void
 */
function asset_timeline_grouped_html(array $tl, $actionsFn = null, $assetId = 0) {
    if (!$tl) {
        echo '<p class="muted">ยังไม่มีประวัติ</p>';
        return;
    }
    $groups = timeline_group_by_type($tl);
    $order = timeline_group_order();
    $seen = [];
    foreach ($order as $g) {
        if (empty($groups[$g])) continue;
        $seen[$g] = true;
        $items = $groups[$g];
        echo '<details class="tl-group" style="margin-bottom:10px" open>';
        echo '<summary style="cursor:pointer; font-weight:600; padding:8px 0">' . ui_timeline_group_title_html($g) . ' <span class="muted">(' . count($items) . ')</span></summary>';
        echo '<ul class="timeline" style="margin-top:6px">';
        foreach ($items as $e) {
            $actions = ($actionsFn && is_callable($actionsFn)) ? $actionsFn($e, $assetId) : '';
            echo '<li><div class="tl-date">' . dthai_full($e['d']) . '</div>'
               . '<div class="tl-type">' . $e['type'] . '</div>'
               . '<div style="font-size:13.5px; margin-top:3px">' . $e['html'] . $actions . '</div></li>';
        }
        echo '</ul></details>';
    }
    foreach ($groups as $g => $items) {
        if (isset($seen[$g])) continue;
        echo '<details class="tl-group" style="margin-bottom:10px">';
        echo '<summary style="cursor:pointer; font-weight:600; padding:8px 0">' . ui_timeline_group_title_html($g) . ' <span class="muted">(' . count($items) . ')</span></summary>';
        echo '<ul class="timeline" style="margin-top:6px">';
        foreach ($items as $e) {
            $actions = ($actionsFn && is_callable($actionsFn)) ? $actionsFn($e, $assetId) : '';
            echo '<li><div class="tl-date">' . dthai_full($e['d']) . '</div>'
               . '<div class="tl-type">' . $e['type'] . '</div>'
               . '<div style="font-size:13.5px; margin-top:3px">' . $e['html'] . $actions . '</div></li>';
        }
        echo '</ul></details>';
    }
}

/**
 * แสดง timeline แบบ board แนวนอน — แต่ละคอลัมน์เป็น 1 ประเภทงาน เรียงตาม timestamp ภายในกลุ่ม
 *
 * @param array<int, array{d:string, type:string, html:string}> $tl
 * @param callable|null $actionsFn
 * @param int $assetId
 * @return void
 */
function asset_timeline_board_html(array $tl, $actionsFn = null, $assetId = 0) {
    if (!$tl) {
        echo '<p class="muted">ยังไม่มีประวัติ</p>';
        return;
    }
    $groups = timeline_group_by_type($tl);
    $order = timeline_group_order();
    echo '<div class="tl-board">';
    $seen = [];
    foreach ($order as $g) {
        if (empty($groups[$g])) continue;
        $seen[$g] = true;
        timeline_board_column($g, $groups[$g], $actionsFn, $assetId);
    }
    foreach ($groups as $g => $items) {
        if (isset($seen[$g])) continue;
        timeline_board_column($g, $items, $actionsFn, $assetId);
    }
    echo '</div>';
}
