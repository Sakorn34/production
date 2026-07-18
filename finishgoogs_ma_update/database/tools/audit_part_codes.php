<?php
/**
 * database/tools/audit_part_codes.php — เปรียบเทียบ parts.stock_code กับ biton_tech_parts.products.code
 *
 * รัน: php database/tools/audit_part_codes.php
 * หรือเปิดผ่านเว็บ (ต้อง login บน localhost)
 */
require dirname(__DIR__, 2) . '/config.php';

$cli = (PHP_SAPI === 'cli');

if (!$cli) {
    require_login();
    header('Content-Type: text/plain; charset=utf-8');
}

ensure_parts_stock_code_schema();

$parts = [];
$res = db()->query('SELECT id, stock_code, part_code, name FROM parts ORDER BY name');
while ($row = $res->fetch_assoc()) {
    $parts[] = $row;
}

$techCodes = [];
$res2 = dbParts()->query('SELECT code, name, quantity FROM products ORDER BY code');
while ($row = $res2->fetch()) {
    $techCodes[(string)$row['code']] = $row;
}

$missing = [];
$matched = 0;
$noStockCode = 0;

foreach ($parts as $p) {
    $code = trim((string)($p['stock_code'] ?? ''));
    if ($code === '') {
        $noStockCode++;
        $missing[] = [
            'id'     => (int)$p['id'],
            'code'   => '',
            'name'   => (string)$p['name'],
            'reason' => 'ยังไม่มี stock_code — รัน import_stock_code_from_csv.php',
        ];
        continue;
    }
    if (isset($techCodes[$code])) {
        $matched++;
        continue;
    }
    $missing[] = [
        'id'     => (int)$p['id'],
        'code'   => $code,
        'name'   => (string)$p['name'],
        'reason' => 'stock_code ไม่พบใน tech_parts.products',
    ];
}

$out = [];
$out[] = '=== Audit stock_code ↔ products.code ===';
$out[] = 'parts ใน production: ' . count($parts);
$out[] = 'products ใน tech_parts: ' . count($techCodes);
$out[] = 'จับคู่ได้: ' . $matched;
$out[] = 'ยังไม่มี stock_code: ' . $noStockCode;
$out[] = 'stock_code ไม่พบใน tech_parts: ' . (count($missing) - $noStockCode);
$out[] = '';

if ($missing) {
    $out[] = '--- รายการที่เบิกไม่ได้ ---';
    foreach (array_slice($missing, 0, 30) as $m) {
        $out[] = sprintf('#%d | stock=%s | name=%s | %s', $m['id'], $m['code'] ?: '-', $m['name'], $m['reason']);
    }
    if (count($missing) > 30) {
        $out[] = '... และอีก ' . (count($missing) - 30) . ' รายการ';
    }
}

echo implode("\n", $out) . "\n";
