<?php
require __DIR__ . '/../../config.php';
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "parts_app_base_url: " . (function_exists('parts_app_base_url') ? parts_app_base_url() : 'MISSING') . "\n";

try {
    $r = db()->query("SHOW TABLES LIKE 'bom_items'");
    echo "bom_items table: " . ($r && $r->num_rows ? 'yes' : 'NO') . "\n";
} catch (Throwable $e) {
    echo "bom check err: " . $e->getMessage() . "\n";
}

try {
    $sql = "SELECT a.id, a.asset_code,
                   (SELECT COUNT(*) FROM part_movements pm WHERE pm.ref_asset_id=a.id AND pm.direction='out') parts_out_cnt,
                   (SELECT COUNT(*) FROM bom_items b WHERE b.product_id=a.product_id) bom_cnt,
                   (SELECT COUNT(DISTINCT pm.part_id) FROM part_movements pm
                    INNER JOIN bom_items b ON b.part_id=pm.part_id
                    WHERE pm.ref_asset_id=a.id AND pm.direction='out' AND b.product_id=a.product_id) bom_out_cnt
            FROM assets a JOIN products p ON p.id=a.product_id LIMIT 1";
    $row = $r = db()->query($sql);
    if (!$row) {
        echo "SQL error: " . db()->error . "\n";
    } else {
        print_r($row->fetch_assoc());
    }
} catch (Throwable $e) {
    echo "ERR: " . $e->getMessage() . "\n";
}
