<?php
/**
 * database/tools/probe_sale_sn_map.php — ตรวจ map S/N รายการในใบขาย (CLI)
 *
 * ใช้: php database/tools/probe_sale_sn_map.php <asset_code>
 */
require dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/stockparts_withdraw.php';

$sn = trim((string) ($argv[1] ?? ''));
if ($sn === '') {
    fwrite(STDERR, "usage: php probe_sale_sn_map.php <serial>\n");
    exit(1);
}

$info = asset_stockparts_withdraw_info($sn);
echo "ok=" . ($info['ok'] ? '1' : '0') . " order=" . ($info['order_id'] ?? '') . "\n";
echo "message=" . ($info['message'] ?? '') . "\n";
$map = $info['line_serial_map'] ?? [];
echo "lines=" . count($info['order_lines'] ?? []) . " map_keys=" . count($map) . "\n";
$orderId = trim((string) ($info['order_id'] ?? ''));
if ($orderId !== '') {
    echo "--- po_order_part_serials (stock db) order_id=$orderId ---\n";
    $db = dbStock();
  $st = $db->prepare('SELECT part_id, serial_number FROM po_order_part_serials WHERE order_id = ? ORDER BY part_id, id');
    if ($st) {
        $st->bind_param('s', $orderId);
        $st->execute();
        $res = $st->get_result();
        $n = 0;
        while ($row = $res->fetch_assoc()) {
            $n++;
            echo '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
        }
        if ($n === 0) {
            echo "  (none)\n";
        }
    }
    $setup = function_exists('dbSetup') ? dbSetup() : null;
    if ($setup) {
        echo "--- po_order_part_serials (setup db) ---\n";
        $st2 = $setup->prepare('SELECT part_id, serial_number FROM po_order_part_serials WHERE order_id = ? ORDER BY part_id, id');
        if ($st2) {
            $st2->bind_param('s', $orderId);
            $st2->execute();
            $res2 = $st2->get_result();
            $n2 = 0;
            while ($row = $res2->fetch_assoc()) {
                $n2++;
                echo '  ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
            }
            if ($n2 === 0) {
                echo "  (none)\n";
            }
        }
    }
    $refs = stockparts_order_reference_variants($orderId);
    echo "--- stock_movements registration refs=" . implode(',', $refs) . " ---\n";
    $ph = implode(',', array_fill(0, count($refs), '?'));
    if ($ph !== '') {
        $types = str_repeat('s', count($refs));
        $st3 = $db->prepare("SELECT id, notes FROM stock_movements WHERE reference_no IN ($ph) AND notes LIKE '%ลงทะเบียนอุปกรณ์%' ORDER BY id");
        if ($st3) {
            $st3->bind_param($types, ...$refs);
            $st3->execute();
            $res3 = $st3->get_result();
            while ($row = $res3->fetch_assoc()) {
                echo '  #' . $row['id'] . ' ' . substr((string) $row['notes'], 0, 120) . "\n";
            }
        }
    }
}

foreach ($info['order_lines'] ?? [] as $ln) {
    $id = (int) ($ln['id'] ?? 0);
    $qty = (int) ($ln['quantity'] ?? 0);
    $name = (string) ($ln['part_name'] ?? '');
    $sns = stockparts_line_serial_list($map[$id] ?? null);
    echo sprintf(
        "  line %d qty=%d name=%s sn_count=%d sns=%s\n",
        $id,
        $qty,
        $name,
        count($sns),
        $sns ? implode(', ', $sns) : '—'
    );
}
