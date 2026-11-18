<?php
/**
 * repair_fractional_qty.php — แก้ใบเบิกเก่าที่ถูกปัด 0.5 เป็น 1 ตอน sync ข้ามฐาน
 *
 * ที่มา: ก่อนแก้บั๊ก จำนวนเบิกทศนิยมใน biton_production.part_movements.qty (เช่น 0.5 ชิ้น/เครื่อง
 * ของ ชุดสายแพร + IDE 40 Pin รุ่น bitvisitor S) ถูก (int) round() เป็น 1 ก่อนบันทึกที่
 * biton_tech_parts.stock_out_items และหักสต็อกเกินจริงเท่าตัว
 *
 * สคริปต์นี้หาแถวที่ "movement มีเศษ แต่ stock_out_items = ค่าที่ถูกปัดขึ้น" แล้วแก้ให้ตรงกับ movement
 * พร้อมคืนส่วนต่างเข้า products.quantity (เฉพาะใบ stock_deducted=1 ที่เคยหักสต็อกจริง)
 * ไม่แตะแถวที่ไม่ตรงรูปแบบนี้ เพื่อกันแก้ข้อมูลที่ถูกต้องอยู่แล้ว
 *
 * Flow: dry-run (ค่าเริ่มต้น แสดงรายการ ไม่เขียน) → ตรวจผล → รันซ้ำด้วย --apply
 *
 * Example (CLI เท่านั้น — สำรองฐาน biton_tech_parts ก่อนใช้ --apply):
 *   php parts/database/tools/repair_fractional_qty.php
 *   php parts/database/tools/repair_fractional_qty.php --apply
 */

// ─ Guard ──────────────────────────────────────────────────────────────────────

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ─ Bootstrap ──────────────────────────────────────────────────────────────────

require_once dirname(__DIR__, 3) . '/shared/app_paths.php';
require_once dirname(__DIR__, 3) . '/shared/qty_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/production_sync.php';

$apply = in_array('--apply', $argv ?? [], true);

// ─ Main ───────────────────────────────────────────────────────────────────────

try {
    $tech = tech_parts_sync_db();
    if (!tech_parts_ensure_decimal_qty_schema($tech)) {
        fwrite(STDERR, "คอลัมน์จำนวนใน biton_tech_parts ยังไม่เป็น DECIMAL — migrate ไม่สำเร็จ (ตรวจสิทธิ์ ALTER) จึงหยุด\n");
        exit(1);
    }
    $prod = production_db();

    // movement เบิกที่มีเศษทศนิยม: id => qty จริง
    $frac = [];
    $q = $prod->query("SELECT id, qty FROM part_movements WHERE direction = 'out' AND qty <> FLOOR(qty)");
    while ($r = $q->fetch()) {
        $frac[(int) $r['id']] = qty_norm($r['qty']);
    }
    echo 'movement เบิกที่มีเศษทศนิยม: ' . count($frac) . " รายการ\n";

    $found = 0;
    $fixed = 0;
    $returned = 0.0;

    foreach (array_chunk(array_keys($frac), 500) as $ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $tech->prepare(
            "SELECT soi.id, soi.stock_out_id, soi.product_id, soi.quantity, soi.part_movement_id,
                    so.doc_no, so.asset_code, so.stock_deducted
             FROM stock_out_items soi
             JOIN stock_out so ON so.id = soi.stock_out_id
             WHERE soi.part_movement_id IN ($ph)"
        );
        $st->execute($ids);

        while ($row = $st->fetch()) {
            $mid = (int) $row['part_movement_id'];
            $real = $frac[$mid];
            $stored = qty_norm($row['quantity']);
            // รูปแบบบั๊กเดิม: ค่าที่เก็บ = round(ค่าจริง) และมากกว่าค่าจริง
            if (qty_cmp($stored, (float) round($real)) !== 0 || qty_cmp($stored, $real) <= 0) {
                continue;
            }
            $found++;
            $excess = qty_norm($stored - $real);
            $deducted = production_stock_out_was_deducted_row($row);
            echo sprintf(
                "#%d %s S/N %s  เก็บ %s → ควรเป็น %s  %s\n",
                (int) $row['id'],
                (string) $row['doc_no'],
                (string) ($row['asset_code'] ?? '-'),
                qty_fmt($stored),
                qty_fmt($real),
                $deducted ? 'คืนสต็อก ' . qty_fmt($excess) : '(ไม่เคยหักสต็อก — แก้เฉพาะใบ)'
            );
            if (!$apply) {
                continue;
            }

            $tech->beginTransaction();
            try {
                $tech->prepare('UPDATE stock_out_items SET quantity = ? WHERE id = ?')
                    ->execute([qty_sql($real), (int) $row['id']]);
                if ($deducted) {
                    $tech->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                        ->execute([qty_sql($excess), (int) $row['product_id']]);
                    $returned += $excess;
                }
                $tech->commit();
                $fixed++;
                error_log('[repair_fractional_qty] item ' . (int) $row['id'] . ' ' . qty_sql($stored) . ' -> ' . qty_sql($real));
            } catch (Throwable $e) {
                if ($tech->inTransaction()) {
                    $tech->rollBack();
                }
                error_log('[repair_fractional_qty] item ' . (int) $row['id'] . ': ' . $e->getMessage());
                echo '  ผิดพลาด (ดู error log)' . "\n";
            }
        }
    }

    echo "\nพบที่ต้องแก้: {$found}";
    echo $apply ? " · แก้แล้ว: {$fixed} · คืนสต็อกรวม: " . qty_fmt($returned) . "\n"
                : " (dry-run — ยังไม่เขียน, ใช้ --apply เพื่อแก้จริง)\n";
} catch (Throwable $e) {
    error_log('[repair_fractional_qty] ' . $e->getMessage());
    fwrite(STDERR, "ผิดพลาด — ดู error log\n");
    exit(1);
}
