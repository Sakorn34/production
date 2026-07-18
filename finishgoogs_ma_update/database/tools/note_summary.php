<?php
require __DIR__ . '/../../config.php';
$pdo = dbParts();
echo "notes summary:\n";
foreach ($pdo->query("SELECT note, COUNT(*) c FROM stock_out GROUP BY note ORDER BY c DESC") as $r) {
    echo "  {$r['note']} => {$r['c']}\n";
}
