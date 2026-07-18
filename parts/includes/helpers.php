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
    $icons = ['view' => '🔍', 'edit' => '✏️', 'delete' => '🗑️', 'basket' => '🧺'];
    $classes = [
        'view'   => 'btn-icon btn-icon-view',
        'edit'   => 'btn-icon btn-icon-edit',
        'delete' => 'btn-icon btn-icon-delete',
        'basket' => 'btn-icon btn-icon-basket',
    ];
    $icon = $icons[$type] ?? '•';
    $cls = ($small ? 'btn btn-sm ' : 'btn ') . ($classes[$type] ?? 'btn-icon');
    $titleAttr = $title !== '' ? ' title="' . e($title) . '"' : '';

    if ($type === 'delete') {
        return '<button type="submit" class="' . $cls . '"' . $titleAttr . '>' . $icon . '</button>';
    }
    if ($type === 'basket') {
        return '<button type="button" class="' . $cls . '"' . $titleAttr . ' data-sn-basket="' . e($href) . '">' . $icon . '</button>';
    }
    return '<a href="' . e($href) . '" class="' . $cls . '"' . $titleAttr . '>' . $icon . '</a>';
}
