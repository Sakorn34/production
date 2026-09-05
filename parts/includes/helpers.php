<?php

function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = $path === '' ? '' : (strpos($path, '/') === 0 ? $path : '/' . $path);
    return BASE_PATH . $path;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function generateDocNo(PDO $db): string
{
    $prefix = 'OUT-' . date('Ymd') . '-';
    $inTx = $db->inTransaction();
    $sql = 'SELECT doc_no FROM stock_out WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1'
        . ($inTx ? ' FOR UPDATE' : '');
    $stmt = $db->prepare($sql);
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $seq = 1;
    if ($last && preg_match('/-(\d{4})$/', (string) $last, $m)) {
        $seq = (int) $m[1] + 1;
    }
    return $prefix . str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
}

function generateProductCode(PDO $db): string
{
    $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)) FROM products WHERE code LIKE 'P%'");
    $max = (int) $stmt->fetchColumn();
    return 'P' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function generateSetCode(PDO $db): string
{
    $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(code, 2) AS UNSIGNED)) FROM sets WHERE code LIKE 'S%'");
    $max = (int) $stmt->fetchColumn();
    return 'S' . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function formatDate(?string $datetime): string
{
    if (!function_exists('dt_display_full')) {
        require_once dirname(__DIR__, 2) . '/shared/datetime_helpers.php';
    }
    return dt_display_full($datetime);
}

function formatNumber($num): string
{
    return number_format($num ?? 0);
}

function formatCurrency($amount): string
{
    if ($amount === null || $amount === '') {
        return '-';
    }
    return '฿' . number_format((float) $amount, 2);
}

function ensureProductColumns(PDO $db): void
{
    $columns = [
        'purchase_link' => 'VARCHAR(500) DEFAULT NULL',
        'supplier' => 'VARCHAR(200) DEFAULT NULL',
        'price' => 'DECIMAL(10,2) DEFAULT 0.00',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ];

    foreach ($columns as $column => $definition) {
        $stmt = $db->query("SHOW COLUMNS FROM products LIKE " . $db->quote($column));
        if ($stmt && !$stmt->fetch()) {
            $db->exec("ALTER TABLE products ADD COLUMN {$column} {$definition}");
        }
    }
}

/**
 * อ่านสถานะการใช้งานจากแถว products (ค่าเก่าที่ไม่มีคอลัมน์ถือว่าใช้งานอยู่)
 *
 * @param array<string,mixed> $row
 * @return bool
 */
function product_is_active(array $row): bool
{
    if (!array_key_exists('is_active', $row)) {
        return true;
    }
    return (int) $row['is_active'] === 1;
}

/**
 * ป้ายข้อความสถานะการใช้งานอะไหล่
 *
 * @param bool $active
 * @return string
 */
function product_usage_label(bool $active): string
{
    return $active ? 'ใช้งานอยู่' : 'ยกเลิกใช้งาน';
}

/**
 * HTML badge สถานะการใช้งานอะไหล่
 *
 * @param bool $active
 * @return string
 */
function product_usage_badge(bool $active): string
{
    $cls = $active ? 'badge badge-success badge-active' : 'badge badge-warning badge-inactive';
    return '<span class="' . $cls . '">' . e(product_usage_label($active)) . '</span>';
}

/**
 * cache รายการ metadata อะไหล่จาก production (key = stock_code)
 *
 * @return array<string,array{name:string,part_code:string,icon_path:string}>
 */
function &parts_prod_label_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * โหลด name / part_code / icon จาก production.parts ตาม stock_code (products.code)
 *
 * @param array<int,string> $stockCodes รหัส Pxxxxx จาก biton_tech_parts
 * @return array<string,array{name:string,part_code:string,icon_path:string}>
 */
function production_part_labels_by_stock_codes(array $stockCodes): array
{
    $cache = &parts_prod_label_cache();
    $stockCodes = array_values(array_unique(array_filter(array_map(function ($c) {
        return trim((string) $c);
    }, $stockCodes))));
    if ($stockCodes === []) {
        return [];
    }

    $missing = [];
    foreach ($stockCodes as $code) {
        if (!isset($cache[$code])) {
            $missing[] = $code;
        }
    }

    if ($missing !== []) {
        require_once __DIR__ . '/production_sync.php';
        try {
            $prod = production_db();
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $st = $prod->prepare(
                "SELECT stock_code, name, part_code, icon_path FROM parts WHERE stock_code IN ($ph)"
            );
            $st->execute($missing);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $sc = trim((string) ($r['stock_code'] ?? ''));
                if ($sc === '') {
                    continue;
                }
                $cache[$sc] = [
                    'name' => trim((string) ($r['name'] ?? '')),
                    'part_code' => trim((string) ($r['part_code'] ?? '')),
                    'icon_path' => trim((string) ($r['icon_path'] ?? '')),
                ];
            }
            foreach ($missing as $code) {
                if (!isset($cache[$code])) {
                    $cache[$code] = ['name' => '', 'part_code' => '', 'icon_path' => ''];
                }
            }
        } catch (Throwable $e) {
            error_log('[production_part_labels_by_stock_codes] ' . $e->getMessage());
        }
    }

    $out = [];
    foreach ($stockCodes as $code) {
        if (isset($cache[$code])) {
            $out[$code] = $cache[$code];
        }
    }
    return $out;
}

/**
 * ชื่อแสดงผลหลักของอะไหล่ — ใช้ production.name ก่อน แล้ว fallback ชื่อใน tech_parts
 *
 * @param array<string,mixed> $productRow แถว products (code, name)
 * @param array<string,array{name:string,part_code:string,icon_path?:string}> $prodLabels
 * @return string
 */
function part_product_display_name(array $productRow, array $prodLabels): string
{
    $code = trim((string) ($productRow['code'] ?? ''));
    $fallback = trim((string) ($productRow['name'] ?? ''));
    if ($code !== '' && isset($prodLabels[$code])) {
        $name = trim((string) ($prodLabels[$code]['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    return $fallback;
}

/**
 * บรรทัดรองใต้ชื่อ — ใช้ production.part_code ก่อน แล้ว fallback รหัสสต็อก Pxxxxx
 *
 * @param array<string,mixed> $productRow
 * @param array<string,array{name:string,part_code:string,icon_path?:string}> $prodLabels
 * @return string
 */
function part_product_display_sub(array $productRow, array $prodLabels): string
{
    $code = trim((string) ($productRow['code'] ?? ''));
    if ($code !== '' && isset($prodLabels[$code])) {
        $partCode = trim((string) ($prodLabels[$code]['part_code'] ?? ''));
        if ($partCode !== '') {
            return $partCode;
        }
    }
    return $code;
}

/**
 * ค่า Code Part ดิบจาก production (ไม่ fallback เป็นรหัสสต็อก)
 *
 * @param array<string,mixed> $product
 * @return string
 */
function parts_product_part_code_raw(array $product): string
{
    $code = trim((string) ($product['code'] ?? ''));
    if ($code === '') {
        return '';
    }
    $labels = production_part_labels_by_stock_codes([$code]);
    return trim((string) ($labels[$code]['part_code'] ?? ''));
}

/**
 * ชื่อแสดงผลจากรหัส + ชื่อ fallback
 *
 * @param string $code
 * @param string $fallbackName
 * @param array<string,array{name:string,part_code:string,icon_path?:string}>|null $prodLabels
 * @return string
 */
function parts_label_for_code(string $code, string $fallbackName, ?array $prodLabels = null): string
{
    if ($prodLabels === null) {
        $prodLabels = production_part_labels_by_stock_codes([$code]);
    }
    return part_product_display_name(['code' => $code, 'name' => $fallbackName], $prodLabels);
}

/**
 * เติม display_name / display_sub ให้แถว product เดียว
 *
 * @param array<string,mixed> $product
 * @return array<string,mixed>
 */
function parts_enrich_product(array $product): array
{
    $labels = production_part_labels_by_stock_codes([(string) ($product['code'] ?? '')]);
    $product['display_name'] = part_product_display_name($product, $labels);
    $product['display_sub'] = part_product_display_sub($product, $labels);
    return $product;
}

/**
 * เติม display_name / display_sub ให้ทุกแถวในรายการ products
 *
 * @param array<int,array<string,mixed>> $products
 * @return array<int,array<string,mixed>>
 */
function parts_enrich_products(array $products): array
{
    if ($products === []) {
        return $products;
    }
    $labels = production_part_labels_by_stock_codes(array_column($products, 'code'));
    foreach ($products as &$p) {
        $p['display_name'] = part_product_display_name($p, $labels);
        $p['display_sub'] = part_product_display_sub($p, $labels);
    }
    unset($p);
    return $products;
}

/**
 * คืนชื่อแสดงผลของ product (ต้อง enrich มาก่อนหรือโหลดจาก production อัตโนมัติ)
 *
 * @param array<string,mixed> $product
 * @return string
 */
function parts_display_name(array $product): string
{
    if (!empty($product['display_name'])) {
        return (string) $product['display_name'];
    }
    return part_product_display_name($product, production_part_labels_by_stock_codes([(string) ($product['code'] ?? '')]));
}

/**
 * HTML ชื่ออะไหล่ + part_code รอง (ถ้ามี)
 *
 * @param array<string,mixed> $product
 * @param bool $withSub แสดงบรรทัด part_code ใต้ชื่อ
 * @return string
 */
function parts_product_name_html(array $product, bool $withSub = true): string
{
    $name = parts_display_name($product);
    $html = e($name);
    if (!$withSub) {
        return $html;
    }
    $sub = (string) ($product['display_sub'] ?? '');
    if ($sub === '') {
        $sub = part_product_display_sub($product, production_part_labels_by_stock_codes([(string) ($product['code'] ?? '')]));
    }
    $stockCode = trim((string) ($product['code'] ?? ''));
    if ($sub !== '' && $sub !== $stockCode) {
        $html .= '<div class="muted" style="font-size:11px;margin-top:2px">' . e($sub) . '</div>';
    }
    return $html;
}

/**
 * ข้อความสำหรับ option / รายการย่อ: [P001] ชื่อ
 *
 * @param array<string,mixed> $product
 * @return string
 */
function parts_format_product_option(array $product): string
{
    $code = trim((string) ($product['code'] ?? ''));
    return '[' . $code . '] ' . parts_display_name($product);
}

/**
 * combobox เลือกอะไหล่ — ค้นหาและเลือกในช่องเดียว พร้อมรูป/ชื่อ/คงเหลือ
 *
 * @param string $inputName ชื่อ field ที่ส่ง (เช่น product_id)
 * @param array<int,array<string,mixed>> $products จาก parts_enrich_products()
 * @param array<string,string> $partIcons map รหัส → icon path
 * @param array{id?:string,label?:string,placeholder?:string,required?:bool,autofocus?:bool,selected?:int,disableZeroQty?:bool} $opts
 * @return string HTML
 */
function parts_product_picker_html(string $inputName, array $products, array $partIcons, array $opts = []): string
{
    $pickerId = $opts['id'] ?? preg_replace('/[^a-z0-9_-]/i', '-', $inputName);
    $label = $opts['label'] ?? 'เลือกอะไหล่';
    $placeholder = $opts['placeholder'] ?? 'พิมพ์ชื่อหรือรหัสอะไหล่เพื่อค้นหา…';
    $required = !array_key_exists('required', $opts) || (bool) $opts['required'];
    $selectedId = (int) ($opts['selected'] ?? 0);
    $disableZeroQty = !array_key_exists('disableZeroQty', $opts) || (bool) $opts['disableZeroQty'];

    $items = [];
    foreach ($products as $p) {
        $code = trim((string) ($p['code'] ?? ''));
        $items[] = [
            'id'       => (int) ($p['id'] ?? 0),
            'code'     => $code,
            'name'     => parts_display_name($p),
            'label'    => parts_format_product_option($p),
            'qty'      => (int) ($p['quantity'] ?? 0),
            'unit'     => (string) ($p['unit'] ?? 'ชิ้น'),
            'icon'     => parts_upload_img_url($partIcons[$code] ?? '') ?? '',
            'disabled' => $disableZeroQty && (int) ($p['quantity'] ?? 0) <= 0,
        ];
    }

    $json = htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    $inputId = $pickerId . '-input';

    ob_start(); ?>
    <div class="form-group parts-product-picker-wrap">
        <label for="<?= e($inputId) ?>"><?= e($label) ?></label>
        <div class="parts-product-picker" id="<?= e($pickerId) ?>" data-product-picker data-products="<?= $json ?>"<?= $selectedId ? ' data-selected="' . $selectedId . '"' : '' ?>>
            <input type="hidden" name="<?= e($inputName) ?>" value="<?= $selectedId ?: '' ?>"<?= $required ? ' required' : '' ?>>
            <div class="parts-product-picker-control">
                <div class="parts-product-picker-thumb is-empty" aria-hidden="true"></div>
                <input type="text" id="<?= e($inputId) ?>" class="parts-product-picker-input" placeholder="<?= e($placeholder) ?>" autocomplete="off"<?= !empty($opts['autofocus']) ? ' data-autofocus' : '' ?>>
                <button type="button" class="parts-product-picker-clear" hidden aria-label="ล้างการเลือก">&times;</button>
            </div>
            <ul class="parts-product-picker-list" role="listbox" hidden></ul>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * ข้อความ [รหัส] ชื่อ จาก code + fallback (ไม่มีแถว product เต็ม)
 *
 * @param string $code
 * @param string $fallbackName
 * @param array<string,array{name:string,part_code:string,icon_path?:string}>|null $prodLabels
 * @return string
 */
function parts_format_product_line(string $code, string $fallbackName, ?array $prodLabels = null): string
{
    return '[' . $code . '] ' . parts_label_for_code($code, $fallbackName, $prodLabels);
}

/**
 * โหลด map รหัสอะไหล่ → icon_path จากตาราง parts ฝั่ง production
 *
 * @param array<int,array<string,mixed>> $products แถว products (ต้องมี key code)
 * @return array<string,string>
 */
function parts_product_icon_map(array $products): array
{
    if (!$products) {
        return [];
    }
    $codes = array_values(array_unique(array_filter(array_map(function ($r) {
        return trim((string) ($r['code'] ?? ''));
    }, $products))));
    if (!$codes) {
        return [];
    }
    $labels = production_part_labels_by_stock_codes($codes);
    $icons = [];
    foreach ($labels as $code => $meta) {
        if (!empty($meta['icon_path'])) {
            $icons[$code] = $meta['icon_path'];
        }
    }
    return $icons;
}

/**
 * สร้าง URL รูปจาก icon_path ของ production (รองรับ legacy + encode ชื่อไฟล์)
 *
 * @param string|null $path relative path เช่น Parts_Images/foo bar.png หรือ parts/2026/x.png
 * @return string|null
 */
function parts_upload_img_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if (!function_exists('ui_finishgoogs_base_url')) {
        require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';
    }
    $base = rtrim(str_replace('\\', '/', ui_finishgoogs_base_url()), '/');
    $normalized = str_replace('\\', '/', $path);
    $legacyFolders = [
        'Update_Images/', 'Parts_Images/', 'Menu Product_Images/', 'model appsheet_Images/',
        'model_Images/', 'NamePart_Images/', 'Sub Menu_Images/', 'Sub Product_Images/', 'Thumbnail_Images/',
    ];
    foreach ($legacyFolders as $lf) {
        if (strpos($normalized, $lf) === 0) {
            return $base . '/uploads/legacy/' . implode('/', array_map('rawurlencode', explode('/', $normalized)));
        }
    }
    return $base . '/uploads/' . implode('/', array_map('rawurlencode', explode('/', ltrim($normalized, '/'))));
}

/**
 * แสดงรูปอะไหล่จาก path ใต้ uploads ของ production (ไม่มีรูปคืนค่าว่าง)
 *
 * @param string|null $path  relative path เช่น parts/2026/01/x.png
 * @param string      $alt
 * @param string      $class
 * @return string HTML
 */
function parts_img_tag(?string $path, string $alt = '', string $class = 'parts-thumb'): string
{
    $url = parts_upload_img_url($path);
    if ($url === null) {
        return '';
    }
    return '<img src="' . e($url) . '" alt="' . e($alt) . '" class="' . e($class) . '" loading="lazy"'
        . ' onerror="this.hidden=true">';
}

/**
 * ตรวจว่าอะไหล่มีราคาที่แสดงได้ (มากกว่า 0)
 *
 * @param array<string,mixed> $product
 * @return bool
 */
function product_has_price(array $product): bool
{
    if (!isset($product['price']) || $product['price'] === '' || $product['price'] === null) {
        return false;
    }
    return (float) $product['price'] > 0;
}

/**
 * คอลัมน์ที่ sort ได้ในตารางรายการอะไหล่ (key => ป้ายหัวคอลัมน์)
 *
 * @return array<string,string>
 */
function parts_products_sort_columns(): array
{
    return [
        'code'      => 'รหัส',
        'name'      => 'ชื่อ',
        'status'    => 'สถานะ',
        'price'     => 'ราคา',
        // key 'link' คงไว้เพื่อไม่ให้ลิงก์/บุ๊กมาร์กเดิม (?sort=link) เสีย — เรียงตามผู้จำหน่ายเป็นหลักอยู่แล้ว
        'link'      => 'ผู้จำหน่าย',
        'quantity'  => 'คงเหลือ',
        'unit'      => 'หน่วย',
        'min_stock' => 'ขั้นต่ำ',
    ];
}

/**
 * อ่าน sort/dir จาก query string ของหน้ารายการอะไหล่
 *
 * @return array{sort:string,dir:string}
 */
function parts_products_sort_state(): array
{
    $columns = parts_products_sort_columns();
    $sort = isset($_GET['sort']) ? (string) $_GET['sort'] : 'name';
    if (!isset($columns[$sort])) {
        $sort = 'name';
    }
    $dir = isset($_GET['dir']) ? strtolower((string) $_GET['dir']) : 'asc';
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = 'asc';
    }
    return ['sort' => $sort, 'dir' => $dir];
}

/**
 * URL หน้ารายการอะไหล่ พร้อมพารามิเตอร์ sort ปัจจุบัน
 *
 * @param array{sort:string,dir:string}|null $sortState
 * @return string
 */
function parts_products_page_url(?array $sortState = null): string
{
    if ($sortState === null) {
        $sortState = parts_products_sort_state();
    }
    if ($sortState['sort'] === 'name' && $sortState['dir'] === 'asc') {
        return url('/pages/products.php');
    }
    return url('/pages/products.php?' . http_build_query([
        'sort' => $sortState['sort'],
        'dir'  => $sortState['dir'],
    ]));
}

/**
 * URL สลับทิศทาง sort เมื่อกดหัวคอลัมน์
 *
 * @param string $column
 * @param array{sort:string,dir:string} $sortState
 * @return string
 */
function parts_products_sort_href(string $column, array $sortState): string
{
    $columns = parts_products_sort_columns();
    if (!isset($columns[$column])) {
        return parts_products_page_url();
    }
    $dir = 'asc';
    if ($sortState['sort'] === $column) {
        $dir = $sortState['dir'] === 'asc' ? 'desc' : 'asc';
    }
    return parts_products_page_url(['sort' => $column, 'dir' => $dir]);
}

/**
 * HTML หัวคอลัมน์ที่กด sort ได้
 *
 * @param string $label
 * @param string $column
 * @param array{sort:string,dir:string} $sortState
 * @param string|null $extraClass
 * @return string
 */
function parts_products_sort_th(string $label, string $column, array $sortState, ?string $extraClass = null, ?string $pri = null): string
{
    $isActive = $sortState['sort'] === $column;
    $classes = ['sortable-th'];
    if ($extraClass !== null && $extraClass !== '') {
        $classes[] = $extraClass;
    }
    if ($isActive) {
        $classes[] = 'is-sorted';
        $classes[] = 'is-sorted-' . $sortState['dir'];
    }
    $ariaSort = 'none';
    if ($isActive) {
        $ariaSort = $sortState['dir'] === 'asc' ? 'ascending' : 'descending';
    }
    $href = parts_products_sort_href($column, $sortState);
    $arrow = '<span class="sort-indicator" aria-hidden="true"></span>';
    // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css) — ต้องตรงกับ td ของคอลัมน์เดียวกัน
    $priAttr = ($pri !== null && $pri !== '') ? ' data-pri="' . e($pri) . '"' : '';
    return '<th class="' . e(implode(' ', $classes)) . '" aria-sort="' . e($ariaSort) . '"' . $priAttr . '>'
        . '<a class="sortable-th-link" href="' . e($href) . '">'
        . e($label) . ($isActive ? $arrow : '')
        . '</a></th>';
}

/**
 * คีย์สำหรับ sort คอลัมน์ลิงก์ (ผู้จำหน่าย + URL)
 *
 * @param array<string,mixed> $product
 * @return string
 */
function parts_product_link_sort_key(array $product): string
{
    $link = trim((string) ($product['purchase_link'] ?? ''));
    $supplier = trim((string) ($product['supplier'] ?? ''));
    if ($link === '' && $supplier === '') {
        return '';
    }
    if ($supplier !== '') {
        return $supplier . ' ' . $link;
    }
    return $link;
}

/**
 * เปรียบเทียบอะไหล่สองรายการตามคอลัมน์ sort
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 * @param string $sort
 * @return int
 */
function parts_compare_products(array $a, array $b, string $sort): int
{
    switch ($sort) {
        case 'code':
            return strnatcasecmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
        case 'name':
            return strcasecmp(parts_display_name($a), parts_display_name($b));
        case 'status':
            $va = product_is_active($a) ? 1 : 0;
            $vb = product_is_active($b) ? 1 : 0;
            return $va <=> $vb;
        case 'price':
            $pa = product_has_price($a) ? (float) $a['price'] : -1.0;
            $pb = product_has_price($b) ? (float) $b['price'] : -1.0;
            return $pa <=> $pb;
        case 'link':
            return strcasecmp(parts_product_link_sort_key($a), parts_product_link_sort_key($b));
        case 'quantity':
            return ((int) ($a['quantity'] ?? 0)) <=> ((int) ($b['quantity'] ?? 0));
        case 'unit':
            return strcasecmp((string) ($a['unit'] ?? ''), (string) ($b['unit'] ?? ''));
        case 'min_stock':
            return ((int) ($a['min_stock'] ?? 0)) <=> ((int) ($b['min_stock'] ?? 0));
        default:
            return 0;
    }
}

/**
 * เรียงรายการอะไหล่ตามคอลัมน์และทิศทาง
 *
 * @param array<int,array<string,mixed>> $products
 * @param string $sort
 * @param string $dir asc|desc
 * @return array<int,array<string,mixed>>
 */
function parts_sort_products(array $products, string $sort, string $dir): array
{
    $columns = parts_products_sort_columns();
    if (!isset($columns[$sort])) {
        $sort = 'name';
    }
    if ($dir !== 'asc' && $dir !== 'desc') {
        $dir = 'asc';
    }
    $mult = $dir === 'desc' ? -1 : 1;
    usort($products, static function (array $a, array $b) use ($sort, $mult): int {
        return parts_compare_products($a, $b, $sort) * $mult;
    });
    return $products;
}

/**
 * บันทึกรูปอะไหล่ลง uploads/parts ของ finishgoogs (validate MIME + ขนาด)
 *
 * @param string $field ชื่อ field ใน $_FILES
 * @return string|null relative path เช่น parts/2026/07/xxx.png
 */
function parts_save_product_image(string $field): ?string
{
    if (empty($_FILES[$field]['tmp_name']) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if ((int) ($_FILES[$field]['size'] ?? 0) > 5 * 1024 * 1024) {
        return null;
    }
    $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo((string) $_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $exts, true)) {
        return null;
    }
    if (!@getimagesize($_FILES[$field]['tmp_name'])) {
        return null;
    }
    $subdir = 'parts/' . date('Y/m');
    $dir = dirname(__DIR__, 2) . '/finishgoogs_ma_update/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $name = date('His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return $subdir . '/' . $name;
}

/**
 * คอลัมน์รูปในตาราง — แสดง thumbnail หรือปุ่มเพิ่มรูป
 *
 * @param string|null       $iconPath
 * @param array<string,mixed> $product
 * @return string HTML
 */
function parts_product_img_cell(?string $iconPath, array $product, string $returnTo = ''): string
{
    $name = parts_display_name($product);
    $returnAttr = $returnTo !== '' ? ' data-product-return-to="' . e($returnTo) . '"' : '';
    if ($iconPath !== '') {
        $html = parts_img_tag($iconPath, $name);
        $html .= '<button type="button" class="btn-cell-mini btn-cell-img" title="เปลี่ยนรูป"'
            . ' data-open-modal="product-icon-modal"'
            . ' data-fill-modal="product-icon-modal"'
            . ' data-product-id="' . (int) ($product['id'] ?? 0) . '"'
            . ' data-product-name="' . e($name) . '"' . $returnAttr . '>'
            . ui_icon_html('edit', 12, 'btn-svg') . '</button>';
        return '<div class="col-img-wrap">' . $html . '</div>';
    }
    return '<button type="button" class="btn btn-sm btn-outline btn-cell-action"'
        . ' data-open-modal="product-icon-modal"'
        . ' data-fill-modal="product-icon-modal"'
        . ' data-product-id="' . (int) ($product['id'] ?? 0) . '"'
        . ' data-product-name="' . e($name) . '"' . $returnAttr . '>'
        . ui_icon_html('plus', 14, 'btn-svg') . ' รูป</button>';
}

/**
 * คอลัมน์ราคา — แสดงราคาหรือขีดเมื่อยังไม่กำหนด (แก้ไขผ่านปุ่มในคอลัมน์จัดการ)
 *
 * @param array<string,mixed> $product
 * @return string HTML
 */
function parts_product_price_cell(array $product, string $returnTo = ''): string
{
    if (product_has_price($product)) {
        return '<span class="price-value">' . formatCurrency($product['price']) . '</span>';
    }
    return '<span class="text-muted">-</span>';
}

function ensureStockInColumns(PDO $db): void
{
    $stmt = $db->query("SHOW COLUMNS FROM stock_in LIKE 'received_by'");
    if ($stmt && !$stmt->fetch()) {
        $db->exec("ALTER TABLE stock_in ADD COLUMN received_by VARCHAR(100) DEFAULT NULL");
    }
}

function getStockOutNoteOptions(): array
{
    return ['เบิกผลิต', 'เบิกงานซ่อม', 'เบิกงานทดสอบ', 'MA', 'นับใหม่'];
}

function validateStockOutNote(?string $note): string
{
    $note = trim($note ?? '');
    if (!in_array($note, getStockOutNoteOptions(), true)) {
        throw new InvalidArgumentException('กรุณาเลือกประเภทการเบิก');
    }
    return $note;
}

/**
 * บันทึก activity log ของงานสต็อกด้วยชื่อเดิมของหน้าที่เคยทำงานนั้น
 *
 * งานรับเข้า/เบิกออกย้ายมารวมที่ products.php แล้ว ถ้าปล่อยให้ shutdown hook
 * (activity_log_register_post_shutdown) บันทึกเอง ทุกอย่างจะกลายเป็น "จัดการอะไหล่"
 * แยกไม่ออกว่าเป็นการรับเข้าหรือเบิกออก จึงบันทึกเองด้วย label เดิมของแต่ละงาน
 * — activity_log_write() ตั้ง $GLOBALS['activity_log_written'] ให้ shutdown hook ข้ามเอง
 *
 * @param string $legacyScript ชื่อไฟล์เดิม เช่น stock-in.php (ใช้เปิด label ใน activity_log_script_label)
 * @return void
 */
function parts_log_stock_action(string $legacyScript): void
{
    if (!function_exists('activity_log_write')) {
        return;
    }
    $actor = $GLOBALS['line_name'] ?? '';
    activity_log_write([
        'system_key' => 'parts',
        'actor_name' => (string) $actor,
        'action_key' => 'post:' . preg_replace('/\.php$/', '', $legacyScript),
        'summary'    => activity_log_script_label($legacyScript),
        'detail'     => function_exists('activity_log_sanitize_post_detail')
            ? activity_log_sanitize_post_detail()
            : '',
    ]);
}

/**
 * สร้างปุ่ม action แบบ icon (ดู / แก้ไข / ลบ)
 *
 * @param string $type view|edit|delete
 * @param string $href URL สำหรับ view/edit
 * @param string $title tooltip
 * @param bool   $small ใช้ขนาดเล็กในตาราง
 * @return string HTML
 */
function actionIcon(string $type, string $href = '', string $title = '', bool $small = true): string
{
    if (!function_exists('ui_icon_html')) {
        require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';
    }
    $icons = ['view' => 'search', 'edit' => 'edit', 'delete' => 'trash', 'basket' => 'basket'];
    $classes = [
        'view'   => 'btn-icon btn-icon-view',
        'edit'   => 'btn-icon btn-icon-edit',
        'delete' => 'btn-icon btn-icon-delete',
        'basket' => 'btn-icon btn-icon-basket',
    ];
    $iconHtml = ui_icon_html($icons[$type] ?? 'clipboard', 16, 'btn-svg');
    $cls = ($small ? 'btn btn-sm ' : 'btn ') . ($classes[$type] ?? 'btn-icon');
    $titleAttr = $title !== '' ? ' title="' . e($title) . '"' : '';

    if ($type === 'delete') {
        return '<button type="submit" class="' . $cls . '"' . $titleAttr . '>' . $iconHtml . '</button>';
    }
    if ($type === 'basket') {
        return '<button type="button" class="' . $cls . '"' . $titleAttr . ' data-sn-basket="' . e($href) . '">' . $iconHtml . '</button>';
    }
    return '<a href="' . e($href) . '" class="' . $cls . '"' . $titleAttr . '>' . $iconHtml . '</a>';
}

/**
 * ตรวจว่า URL ย้อนกลับอยู่ในแอป Parts และปลอดภัย
 *
 * @param string $url
 * @return bool
 */
function parts_back_url_is_allowed(string $url): bool
{
    $url = trim($url);
    if ($url === '' || preg_match('#^(javascript|data):#i', $url)) {
        return false;
    }
    $base = BASE_PATH;
    if ($url[0] === '/' && strpos($url, $base) === 0) {
        return true;
    }
    $refHost = parse_url($url, PHP_URL_HOST);
    $reqHost = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($refHost !== '' && $reqHost !== '' && strcasecmp($refHost, $reqHost) === 0) {
        $refPath = parse_url($url, PHP_URL_PATH) ?: '';
        return $refPath !== '' && strpos($refPath, $base) === 0;
    }
    return false;
}

/**
 * ตรวจว่าเป็นหน้าเมนูงานหลักใน sidebar Parts
 *
 * @param string|null $cur ชื่อไฟล์ไม่รวม .php
 * @return bool
 */
function parts_is_menu_page(?string $cur = null): bool
{
    $cur = $cur ?: basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '.php');
    if ($cur === 'history' && isset($_GET['id'])) {
        return false;
    }
    // ไม่มี 'index' แล้ว — Dashboard ของ parts ถูกแทนด้วย Dashboard รวม (index.php เป็น redirect)
    // stock-in / stock-out / stock-out-item เป็น redirect แล้ว ไม่เคย render HTML จึงไม่ต้องมีที่นี่
    // ?tab= ไม่นับ — ทุกแท็บของ history ยังเป็นหน้าเมนู ไม่ต้องมีปุ่มย้อนกลับ
    static $menus = [
        'products', 'history', 'sets', 'year-end-summary',
    ];
    return in_array($cur, $menus, true);
}

/**
 * URL หน้าเมนูงานตามโมดูลของหน้าปัจจุบันในแอป Parts
 *
 * @return string
 */
function parts_page_back_url_default(): string
{
    $cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '.php');
    switch ($cur) {
        case 'product-detail':
            return url('/pages/products.php');
        case 'history':
            return url('/pages/history.php');
        default:
            // index.php เป็น redirect ไป Dashboard รวมแล้ว — หน้าแรกในแอปนี้คือรายการอะไหล่
            return url('/pages/products.php');
    }
}

/**
 * คำนวณ URL ปลายทางเมื่อกดย้อนกลับ — ไปหน้าเมนูงานของโมดูลนั้น
 *
 * @param string $override URL ที่หน้าเรียกส่งมาเอง
 * @return string
 */
function parts_page_back_url(string $override = ''): string
{
    if ($override !== '' && parts_back_url_is_allowed($override)) {
        return $override;
    }
    return parts_page_back_url_default();
}
