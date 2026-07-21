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
    if (!$datetime) {
        return '-';
    }
    return date('d/m/Y H:i', strtotime($datetime));
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
        'price' => 'DECIMAL(10,2) DEFAULT 0.00',
    ];

    foreach ($columns as $column => $definition) {
        $stmt = $db->query("SHOW COLUMNS FROM products LIKE " . $db->quote($column));
        if ($stmt && !$stmt->fetch()) {
            $db->exec("ALTER TABLE products ADD COLUMN {$column} {$definition}");
        }
    }
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
    return ['เบิกผลิต', 'เบิกงานซ่อม', 'เบิกงาน Test'];
}

function validateStockOutNote(?string $note): string
{
    $note = trim($note ?? '');
    if (!in_array($note, getStockOutNoteOptions(), true)) {
        throw new InvalidArgumentException('กรุณาเลือกหมายเหตุ');
    }
    return $note;
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
    static $menus = [
        'products', 'stock-in', 'stock-out', 'stock-out-item', 'sets', 'history', 'year-end-summary',
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
