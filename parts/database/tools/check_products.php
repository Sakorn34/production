<?php
require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
$db = getDB();
$total = (int) $db->query('SELECT COUNT(*) FROM products')->fetchColumn();
$withSupplier = (int) $db->query("SELECT COUNT(*) FROM products WHERE supplier IS NOT NULL AND supplier <> ''")->fetchColumn();
$withLink = (int) $db->query("SELECT COUNT(*) FROM products WHERE purchase_link IS NOT NULL AND purchase_link <> ''")->fetchColumn();
echo "products: $total\n";
echo "with supplier: $withSupplier\n";
echo "with link: $withLink\n";
$stmt = $db->query("SELECT code, supplier, purchase_link FROM products WHERE supplier IS NOT NULL AND supplier <> '' LIMIT 5");
foreach ($stmt as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
