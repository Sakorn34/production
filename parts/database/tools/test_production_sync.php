<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/production_sync.php';
ensure_stock_production_sync_schema(getDB());

echo "schema OK\n";
$pid = production_part_id_by_product_code('P00001');
echo "part for P00001: " . ($pid ?? 'null') . "\n";
