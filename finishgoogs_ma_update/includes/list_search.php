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

/**
 * เรียงรายการ timeline ตามเวลาจริง เก่า → ใหม่
 *
 * รายการที่ไม่มีวันที่ไปต่อท้ายสุด — วางในเส้นเวลาไม่ได้ ถ้าปล่อยให้สตริงว่างเรียงตามปกติ
 * จะไปกองอยู่หัวแถวเหมือนเป็นเรื่องที่เกิดก่อนสุด ซึ่งไม่จริง
 *
 * @param array<int, array{d:string}> $items
 * @return array<int, array{d:string}>
 */
function timeline_sort_items_asc(array $items) {
    usort($items, function ($x, $y) {
        $dx = trim((string) ($x['d'] ?? ''));
        $dy = trim((string) ($y['d'] ?? ''));
        if ($dx === '' || $dy === '') {
            return ($dx === '' ? 1 : 0) - ($dy === '' ? 1 : 0);
        }
        return strcmp($dx, $dy);
    });
    return $items;
}

/**
 * แสดง timeline รวมทุกประเภทเป็นเส้นเดียว เรียงตามเวลาบันทึก เก่า → ใหม่
 *
 * ต่างจาก board ตรงที่อ่านเป็นเรื่องราวของเครื่องตั้งแต่ผลิตจนถึงตอนนี้ได้ทีเดียว
 * ไม่ต้องกวาดสายตาข้ามคอลัมน์แล้วประกอบลำดับเวลาเอง
 *
 * @param array<int, array{d:string, type:string, html:string}> $tl
 * @param callable|null $actionsFn
 * @param int $assetId
 * @return void
 */
function asset_timeline_chrono_html(array $tl, $actionsFn = null, $assetId = 0) {
    if (!$tl) {
        echo '<p class="muted">ยังไม่มีประวัติ</p>';
        return;
    }
    echo '<ul class="timeline tl-chrono">';
    foreach (timeline_sort_items_asc($tl) as $e) {
        $actions = ($actionsFn && is_callable($actionsFn)) ? $actionsFn($e, $assetId) : '';
        $d = trim((string) ($e['d'] ?? ''));
        echo '<li><div class="tl-date">' . ($d !== '' ? dthai_full($d) : '<span class="muted">ไม่ระบุวันที่</span>') . '</div>'
           . '<div class="tl-type">' . $e['type'] . '</div>'
           . '<div class="tl-body">' . $e['html'] . $actions . '</div></li>';
    }
    echo '</ul>';
}
