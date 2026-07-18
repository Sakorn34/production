<?php
require dirname(__DIR__, 2) . '/config.php';
$pid = (int)($argv[1] ?? 1);
$qty = (float)($argv[2] ?? 1);
$code = part_resolve_code($pid);
echo "part_id=$pid stock_code=$code qty=$qty\n";
$before = tech_parts_qty_by_part_id($pid);
echo "qty before: " . ($before === null ? 'null' : $before) . "\n";
$out = tech_parts_stock_out_by_part_id($pid, $qty, 'QC test', 'Tom');
var_export($out);
echo "\n";
if (!empty($out['ok'])) {
    $after = tech_parts_qty_by_part_id($pid);
    echo "qty after: $after\n";
    tech_parts_stock_in_by_part_id($pid, $qty, 'QC test rollback', 'Tom');
    echo "rolled back\n";
}
