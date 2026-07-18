<?php
require __DIR__ . '/../../config.php';
$pdo = dbParts();
$st = $pdo->prepare("SELECT id, doc_no, asset_code, issued_by, created_at, note FROM stock_out WHERE asset_code = ? ORDER BY id");
$st->execute(['BM26071421']);
while ($r = $st->fetch()) {
    echo "{$r['id']} | {$r['doc_no']} | {$r['created_at']} | {$r['issued_by']} | {$r['note']}\n";
}
