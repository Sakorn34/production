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
    $stmt = $db->prepare("SELECT COUNT(*) FROM stock_out WHERE doc_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $seq = (int) $stmt->fetchColumn() + 1;
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
