<?php
/**
 * sync_asset_withdrawals.php — สร้าง stock_out ใน Stock ช่างจาก part_movements ที่ยังไม่ผูก
 *
 * ใช้เมื่อมีการเบิกใน production แล้วแต่ยังไม่ sync ไป biton_tech_parts
 * ตั้ง created_at ของ stock_out ให้ตรง moved_at ใน production
 *
 * Usage: php sync_asset_withdrawals.php [asset_id] [--dry-run]
 */
require __DIR__ . '/../../config.php';
require_once dirname(__DIR__, 3) . '/parts/includes/helpers.php';

$assetId = (int)($argv[1] ?? 0);
$dryRun = in_array('--dry-run', $argv, true);

if ($assetId <= 0) {
    echo "Usage: php sync_asset_withdrawals.php <asset_id> [--dry-run]\n";
    exit(1);
}

$asset = qr('SELECT id, asset_code, produced_at FROM assets WHERE id=?', 'i', [$assetId])->fetch_assoc();
if (!$asset) {
    echo "Asset #{$assetId} not found\n";
    exit(1);
}

$assetCode = trim((string)$asset['asset_code']);
echo "=== Sync withdrawals for asset #{$assetId} S/N {$assetCode} ===\n";
if ($dryRun) {
    echo "(DRY RUN — ไม่บันทึก)\n";
}

$res = qr("SELECT pm.id, pm.moved_at, pm.qty, pm.made_by, pm.remark, pm.tech_stock_out_id, pm.part_id,
                  pt.stock_code, pt.part_code, pt.name AS part_name
           FROM part_movements pm
           JOIN parts pt ON pt.id = pm.part_id
           WHERE pm.ref_asset_id = ? AND pm.direction = 'out'
           ORDER BY pm.moved_at, pm.id", 'i', [$assetId]);

$pdo = dbParts();
ensure_stock_production_sync_schema($pdo);

/**
 * สร้างเลขเอกสาร OUT-YYYYMMDD-NNNN ตามวันที่ของ movement
 */
function docNoForDate(PDO $db, string $movedAt): string
{
    $date = date('Ymd', strtotime($movedAt));
    $prefix = 'OUT-' . $date . '-';
    $st = $db->prepare('SELECT doc_no FROM stock_out WHERE doc_no LIKE ? ORDER BY doc_no DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = $st->fetchColumn();
    $seq = 1;
    if ($last && preg_match('/-(\d{4})$/', (string)$last, $m)) {
        $seq = (int)$m[1] + 1;
    }
    return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

$ok = 0;
$skip = 0;
$fail = 0;

while ($m = $res->fetch_assoc()) {
    $mid = (int)$m['id'];
    if (!empty($m['tech_stock_out_id'])) {
        echo "SKIP mid={$mid} already linked out={$m['tech_stock_out_id']}\n";
        $skip++;
        continue;
    }

    $code = trim((string)($m['stock_code'] ?? ''));
    if ($code === '') {
        $code = trim((string)($m['part_code'] ?? ''));
    }
    if ($code === '') {
        echo "FAIL mid={$mid} no stock_code\n";
        $fail++;
        continue;
    }

    $qty = tech_parts_qty_to_int((float)$m['qty']);
    if ($qty <= 0) {
        echo "FAIL mid={$mid} invalid qty\n";
        $fail++;
        continue;
    }

    $product = tech_parts_product_by_code($code);
    if (!$product) {
        echo "FAIL mid={$mid} product not found: {$code}\n";
        $fail++;
        continue;
    }

    $stockQty = (int)$product['quantity'];
    if ($stockQty < $qty) {
        echo "FAIL mid={$mid} {$code} insufficient stock (need {$qty}, have {$stockQty})\n";
        $fail++;
        continue;
    }

    $movedAt = $m['moved_at'];
    $purpose = trim((string)($m['remark'] ?? ''));
    if ($purpose === '' || $purpose === 'เบิกใช้') {
        $purpose = 'เบิกผลิต';
    }
    $issuedBy = trim((string)($m['made_by'] ?? '')) ?: 'finishgoogs';
    $docNo = docNoForDate($pdo, $movedAt);

    echo "CREATE mid={$mid} {$code} x{$qty} doc={$docNo} date={$movedAt} by={$issuedBy}\n";

    if ($dryRun) {
        $ok++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare('INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code, created_at) VALUES (?, NULL, ?, ?, ?, ?)');
        $st->execute([$docNo, $purpose, $issuedBy, $assetCode !== '' ? $assetCode : null, $movedAt]);
        $outId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)');
        $st->execute([$outId, (int)$product['id'], $qty]);
        $itemId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?');
        $st->execute([$qty, (int)$product['id']]);

        $pdo->commit();

        production_link_stock_out($pdo, $outId, $mid, $assetCode !== '' ? $assetCode : null);
        production_link_stock_out_item($pdo, $itemId, $mid);

        echo "  -> stock_out_id={$outId}\n";
        $ok++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "  ERROR: {$e->getMessage()}\n";
        $fail++;
    }
}

echo "\nDone: ok={$ok} skip={$skip} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
