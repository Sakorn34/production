<?php
/**
 * shared/ui_icons.php — ไอคอน SVG ชุดเดียวสำหรับ Production และ Stock ช่าง
 *
 * ใช้ stroke icon 16px แทน emoji ทุกหน้า
 * รองรับ icon key และ emoji เดิม (แปลงเป็น SVG อัตโนมัติ)
 */

// ─ Icon paths ────────────────────────────────────────────────────────────────

/**
 * คืน SVG path ตามชื่อ icon
 *
 * @param string $name
 * @return string|null
 */
function ui_icon_paths(string $name): ?string
{
    static $icons = [
        'dashboard'      => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'assets'         => '<rect x="2" y="4" width="20" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'updates'        => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
        'ma'             => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'parts'          => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'customers'      => '<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/>',
        'repairs'        => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'scan'           => '<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/>',
        'settings'       => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'products'       => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'stock-in'       => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M4 21h16"/>',
        'stock-out-set'  => '<path d="M21 8v13H3V8M1 3h22v5H1zM10 12h4"/>',
        'stock-out-item' => '<path d="M12 19V5M5 12l7-7 7 7"/>',
        'sets'           => '<path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>',
        'history'        => '<path d="M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8"/><path d="M12 7v5l3 3"/>',
        'box'            => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05M12 22.08V12"/>',
        'bell'           => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'check'          => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle'   => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/>',
        'alert'          => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'edit'           => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'trash'          => '<path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'copy'           => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'clipboard'      => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
        'download'       => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
        'upload'         => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/>',
        'external-link'  => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/>',
        'refresh'        => '<path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8M3 22v-6h6M21 12a9 9 0 0 1-15 6.7L3 16"/>',
        'chart'          => '<path d="M3 3v18h18"/><path d="M7 16V9M12 16V5M17 16v-3"/>',
        'tag'            => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
        'camera'         => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'status-ok'      => '<path d="M20 6 9 17l-5-5"/>',
        'status-replace' => '<path d="M21 2v6h-6M3 12a9 9 0 0 1 15-6.7L21 8"/>',
        'status-repair'  => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        'close'          => '<path d="M18 6 6 18M6 6l12 12"/>',
        'search'         => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'logout'         => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'save'           => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
        'palette'        => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'switch'         => '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
        'font'           => '<path d="M4 7V4h16v3M9 20h6M12 4v16"/>',
        'test'           => '<path d="M10 2v7.31M14 9.3V2M8.5 2h7M7 16h10l1.5 6H5.5L7 16z"/>',
        'book'           => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
        'basket'         => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0"/>',
        'menu'           => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ];
    return $icons[$name] ?? null;
}

/**
 * แมป emoji → icon key
 *
 * @param string $emoji
 * @return string|null
 */
function ui_icon_key_from_emoji(string $emoji): ?string
{
    static $map = [
        '📊' => 'chart', '🖥️' => 'assets', '⚙️' => 'updates', '⚙' => 'updates',
        '📅' => 'ma', '🔩' => 'parts', '🏢' => 'customers', '🔧' => 'repairs',
        '📷' => 'scan', '📸' => 'scan', '🛠️' => 'settings', '🛠' => 'settings',
        '📋' => 'clipboard', '📥' => 'stock-in', '📤' => 'stock-out-set',
        '🧩' => 'sets', '📜' => 'history', '📦' => 'box', '🔔' => 'bell',
        '✅' => 'check-circle', '⚠️' => 'alert', '⚠' => 'alert',
        '✏️' => 'edit', '✏' => 'edit', '🗑️' => 'trash', '🗑' => 'trash',
        '🏷️' => 'tag', '🏷' => 'tag', '⬇️' => 'download', '⬇' => 'download',
        '📲' => 'updates', '🔄' => 'status-replace', '↩️' => 'refresh',
        '🔍' => 'search', '🧺' => 'basket', '📝' => 'edit', '🛠' => 'settings',
        '💾' => 'save', '🎨' => 'palette', '🔀' => 'switch', '🔤' => 'font',
        '📑' => 'clipboard', '📍' => 'tag', '🧪' => 'test', '📖' => 'book',
        '🔴' => 'alert', '🟠' => 'alert',
    ];
    return $map[trim($emoji)] ?? null;
}

/**
 * แปลงค่า icon (key / svg:key / emoji) เป็น HTML SVG
 *
 * @param string $icon
 * @param int    $size
 * @param string $class
 * @return string
 */
function ui_icon_html(string $icon, int $size = 16, string $class = 'ui-svg'): string
{
    return ui_nav_icon_html($icon, $size, $class);
}

/**
 * @param string $icon
 * @param int    $size
 * @param string $class
 * @return string
 */
function ui_nav_icon_html(string $icon, int $size = 16, string $class = 'nav-svg'): string
{
    $icon = trim($icon);
    if ($icon === '') {
        return '';
    }

    $key = $icon;
    if (strpos($icon, 'svg:') === 0) {
        $key = substr($icon, 4);
    } elseif (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $icon)) {
        $mapped = ui_icon_key_from_emoji($icon);
        if ($mapped !== null) {
            $key = $mapped;
        } else {
            return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' ui-emoji-fallback" aria-hidden="true">•</span>';
        }
    }

    $paths = ui_icon_paths($key);
    if ($paths === null) {
        return '<span class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . ' ui-emoji-fallback" aria-hidden="true">•</span>';
    }

    $cls = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
    return '<svg class="' . $cls . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}

/**
 * หัวข้อพร้อมไอคอน SVG
 *
 * @param string $icon  icon key
 * @param string $text  ข้อความ
 * @param string $level h1|h2|h3
 * @return string
 */
function ui_heading(string $icon, string $text, string $level = 'h2'): string
{
    $tag = in_array($level, ['h1', 'h2', 'h3'], true) ? $level : 'h2';
    $size = $tag === 'h1' ? 22 : ($tag === 'h3' ? 16 : 18);
    return '<' . $tag . ' class="h-with-icon">'
        . ui_icon_html($icon, $size, 'h-svg')
        . '<span>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>'
        . '</' . $tag . '>';
}

/**
 * ลบ emoji นำหน้าแล้วคืน icon key ที่เหมาะ + ข้อความสะอาด
 *
 * @param string $text
 * @param string $fallbackIcon
 * @return array{0:string,1:string}
 */
function ui_parse_heading(string $text, string $fallbackIcon = 'clipboard'): array
{
    $text = trim($text);
    if (preg_match('/^([\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]+)\s*(.*)$/u', $text, $m)) {
        $key = ui_icon_key_from_emoji($m[1]) ?? $fallbackIcon;
        return [$key, trim($m[2]) !== '' ? trim($m[2]) : $text];
    }
    return [$fallbackIcon, $text];
}

/**
 * URL ระบบสต็อกอะไหล่ (cross-app)
 *
 * @return string
 */
function ui_parts_app_url(): string
{
    if (function_exists('parts_app_base_url')) {
        return parts_app_base_url() . '/index.php';
    }
    if (defined('BASE_PATH')) {
        return BASE_PATH . '/index.php';
    }
    return '/production/parts/index.php';
}

/**
 * URL ระบบทะเบียนเครื่อง (cross-app)
 *
 * @return string
 */
function ui_finishgoogs_app_url(): string
{
    if (defined('BASE_URL')) {
        return rtrim(BASE_URL, '/') . '/index.php';
    }
    if (defined('BASE_PATH')) {
        return str_replace('/parts', '/finishgoogs_ma_update', BASE_PATH) . '/index.php';
    }
    return '/production/finishgoogs_ma_update/index.php';
}

/**
 * base URL ระบบสต็อกอะไหล่ (ไม่มี /index.php ท้าย)
 *
 * @return string
 */
function ui_parts_base_url(): string
{
    return preg_replace('~/index\.php$~', '', ui_parts_app_url());
}

/**
 * base URL ระบบทะเบียนเครื่อง (ไม่มี /index.php ท้าย)
 *
 * @return string
 */
function ui_finishgoogs_base_url(): string
{
    return preg_replace('~/index\.php$~', '', ui_finishgoogs_app_url());
}

/**
 * รายการเมนูระบบสต็อกอะไหล่ (source เดียว ใช้ render ทั้งใน parts เองและกลุ่มข้ามระบบใน finishgoogs)
 *
 * @return array<int, array{file:string,icon:string,label:string}>
 */
function ui_nav_items_parts(): array
{
    // ไม่มีเมนู Dashboard ของ parts แล้ว — ใช้ Dashboard รวมของระบบทะเบียนเครื่องแทน
    // (parts/index.php redirect ไปที่นั่น)
    return [
        ['file' => 'pages/products.php',        'icon' => 'products',       'label' => 'อะไหล่'],
        ['file' => 'pages/stock-in.php',        'icon' => 'stock-in',       'label' => 'รับเข้า'],
        ['file' => 'pages/stock-out.php',       'icon' => 'stock-out-set',  'label' => 'เบิกออกเป็นชุด (Set)'],
        ['file' => 'pages/stock-out-item.php',  'icon' => 'stock-out-item', 'label' => 'เบิกรายชิ้น'],
        ['file' => 'pages/sets.php',            'icon' => 'sets',           'label' => 'จัดการ Set'],
        ['file' => 'pages/history.php',         'icon' => 'history',        'label' => 'ประวัติเบิก'],
        ['file' => 'pages/year-end-summary.php', 'icon' => 'chart',          'label' => 'สรุปยอดสิ้นปี'],
    ];
}

/**
 * รายการเมนูระบบทะเบียนเครื่อง (สำหรับกลุ่มข้ามระบบใน parts)
 *
 * @return array<int, array{file:string,icon:string,label:string}>
 */
function ui_nav_items_finishgoogs(): array
{
    return [
        ['file' => 'index.php',   'icon' => 'dashboard', 'label' => 'Dashboard'],
        ['file' => 'assets.php',  'icon' => 'assets',    'label' => 'ทะเบียนเครื่องผลิตใหม่'],
        ['file' => 'updates.php', 'icon' => 'updates',   'label' => 'อัปเดต FW/HW'],
        ['file' => 'ma.php',      'icon' => 'ma',        'label' => 'บันทึก MA'],
        ['file' => 'parts.php',   'icon' => 'parts',     'label' => 'อะไหล่ใช้ผลิต'],
        ['file' => 'repairs.php', 'icon' => 'repairs',   'label' => 'ประวัติซ่อม'],
        ['file' => 'scan.php',    'icon' => 'scan',      'label' => 'สแกน QR'],
    ];
}

/**
 * ป้ายหัวข้อกลุ่มเมนูใน sidebar
 *
 * @param string $text
 * @return string
 */
function ui_nav_group_label(string $text): string
{
    return '<div class="nav-group-label">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
}

/**
 * กลุ่มเมนูข้ามระบบใน sidebar (ป้ายหัวข้อ + ลิงก์รายเมนู)
 *
 * @param string $label   หัวข้อกลุ่ม
 * @param array  $items   รายการจาก ui_nav_items_*()
 * @param string $baseUrl base URL ของระบบปลายทาง
 * @return string
 */
function ui_sidebar_cross_group(string $label, array $items, string $baseUrl): string
{
    $html = ui_nav_group_label($label);
    foreach ($items as $it) {
        $href = rtrim($baseUrl, '/') . '/' . $it['file'];
        $html .= '<a class="nav-cross" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="nav-ico">' . ui_nav_icon_html($it['icon']) . '</span> '
            . htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
    return $html;
}

/**
 * ลิงก์ข้ามระบบใน sidebar
 *
 * @param string $href
 * @param string $label
 * @return string
 */
function ui_sidebar_cross_link(string $href, string $label): string
{
    return '<a class="sidebar-cross-link" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
        . ui_icon_html('box', 18, 'cross-svg')
        . '<span class="cross-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
        . ui_icon_html('external-link', 14, 'cross-arrow')
        . '</a>';
}

/**
 * ปุ่ม/ลิงก์พร้อมไอคอน SVG
 *
 * @param string $icon
 * @param string $text
 * @param int    $size
 * @return string
 */
function ui_btn_label(string $icon, string $text, int $size = 16): string
{
    return ui_icon_html($icon, $size, 'btn-svg') . '<span>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * หัวข้อ timeline / กลุ่มประวัติ (SVG + ข้อความ)
 *
 * @param string $key production|update|ma|parts|repair|stock|spare|...
 * @return string HTML
 */
function ui_timeline_type_html(string $key): string
{
    static $labels = [
        'production'  => ['assets', 'บันทึกผลิต / QC'],
        'update_fw'   => ['updates', 'อัปเดต Firmware'],
        'update_hw'   => ['parts', 'อัปเดต Hardware'],
        'update'      => ['updates', 'อัปเดต'],
        'ma'          => ['ma', 'เข้า MA'],
        'repair'      => ['repairs', 'งานซ่อม'],
        'stock_in'    => ['stock-in', 'เข้าคลัง'],
        'stock_out'   => ['stock-out-set', 'ออกจากคลัง'],
        'spare_loan'  => ['refresh', 'ถูกยืมเป็นเครื่องสำรอง'],
        'part_out'    => ['parts', 'เบิกอะไหล่ใช้กับเครื่องนี้'],
        'part_in'     => ['refresh', 'คืนอะไหล่'],
    ];
    $pair = $labels[$key] ?? ['clipboard', $key];
    return '<span class="tl-type-label h-with-icon">'
        . ui_icon_html($pair[0], 14, 'tl-svg')
        . '<span>' . htmlspecialchars($pair[1], ENT_QUOTES, 'UTF-8') . '</span></span>';
}

/**
 * แปลง type_key → กลุ่มคอลัมน์ board
 *
 * @param string $typeKey
 * @return string
 */
function ui_timeline_group_key(string $typeKey): string
{
    static $map = [
        'production' => 'production',
        'update_fw' => 'update', 'update_hw' => 'update', 'update' => 'update',
        'ma' => 'ma', 'repair' => 'repair',
        'stock_in' => 'stock', 'stock_out' => 'stock',
        'spare_loan' => 'spare',
        'part_out' => 'parts', 'part_in' => 'parts',
    ];
    return $map[$typeKey] ?? $typeKey;
}

/**
 * ชื่อหัวคอลัมน์กลุ่ม timeline
 *
 * @param string $groupKey
 * @return string HTML
 */
function ui_timeline_group_title_html(string $groupKey): string
{
    static $titles = [
        'production' => ['assets', 'บันทึกผลิต / QC'],
        'update'     => ['updates', 'อัปเดต FW/HW'],
        'ma'         => ['ma', 'เข้า MA'],
        'parts'      => ['parts', 'เบิกอะไหล่ใช้กับเครื่องนี้'],
        'repair'     => ['repairs', 'งานซ่อม'],
        'stock'      => ['box', 'สถานะคลัง'],
        'spare'      => ['refresh', 'เครื่องสำรอง'],
    ];
    $pair = $titles[$groupKey] ?? ['clipboard', $groupKey];
    return '<span class="tl-type-label h-with-icon">'
        . ui_icon_html($pair[0], 14, 'tl-svg')
        . '<span>' . htmlspecialchars($pair[1], ENT_QUOTES, 'UTF-8') . '</span></span>';
}

/**
 * ลำดับกลุ่ม timeline มาตรฐาน
 *
 * @return array<int, string>
 */
function ui_timeline_group_order(): array
{
    return ['production', 'update', 'ma', 'parts', 'repair', 'stock', 'spare'];
}
