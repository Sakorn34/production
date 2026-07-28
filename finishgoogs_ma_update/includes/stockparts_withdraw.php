<?php
/**
 * includes/stockparts_withdraw.php — อ่านข้อมูลการเบิกใช้งานขายจาก biton_stockparts
 *
 * วัตถุประสงค์: แสดงบนโปรไฟล์เครื่องว่า S/N ถูกผูกกับรายการเบิก/setup ใด (stock.setup_id → po_order_parts.order_id)
 *
 * Flow ตัวอย่าง:
 *   $info = asset_stockparts_withdraw_info('AA626060436');
 *   echo asset_stockparts_withdraw_card_html($info);
 */

// ─ Helpers ────────────────────────────────────────────────────────────────────

/**
 * ตรวจว่า setup_id จาก stock หมายถึงรายการเบิกใช้งานขายหรือไม่
 *
 * @param mixed $setupId
 * @return bool
 */
function stockparts_setup_id_is_withdraw($setupId): bool
{
    $sid = trim((string) $setupId);
    return $sid !== '' && $sid !== '0';
}

// ─ Main API ───────────────────────────────────────────────────────────────────

/**
 * ดึงข้อมูลการเบิกใช้งานขายของ S/N จาก biton_stockparts
 *
 * @param string $serial หมายเลขเครื่อง (asset_code)
 * @return array<string,mixed>
 */
function asset_stockparts_withdraw_info(string $serial): array
{
    $serial = trim($serial);
    $base = [
        'ok' => false,
        'has_withdraw' => false,
        'serial' => $serial,
        'message' => 'ยังไม่มีข้อมูลการเบิกใช้งานขาย',
    ];
    if ($serial === '') {
        return $base;
    }

    try {
        $db = dbStock();
    } catch (Throwable $e) {
        return array_merge($base, ['message' => 'เชื่อมต่อระบบ stock ไม่ได้']);
    }

    $st = $db->prepare(
        'SELECT serial_number, model, `timestamp`, create_name, setup_id, active
         FROM stock WHERE serial_number = ? LIMIT 1'
    );
    if (!$st) {
        return array_merge($base, ['message' => 'อ่านทะเบียน stock ไม่ได้']);
    }
    $st->bind_param('s', $serial);
    $st->execute();
    $stockRow = $st->get_result()->fetch_assoc();
    if (!$stockRow) {
        return array_merge($base, ['message' => 'ไม่พบ S/N ในทะเบียน stock']);
    }

    $base['ok'] = true;
    $base['stock'] = $stockRow;

    if (!stockparts_setup_id_is_withdraw($stockRow['setup_id'] ?? null)) {
        return array_merge($base, [
            'message' => 'เครื่องนี้ยังไม่ถูกผูกกับรายการเบิกใช้งานขาย',
        ]);
    }

    $orderId = trim((string) $stockRow['setup_id']);
    $st2 = $db->prepare(
        'SELECT id, order_id, part_code, part_name, category_name, quantity, unit,
                order_product_type, order_customer_name, status_product, is_delivered,
                created_at, updated_at
         FROM po_order_parts WHERE order_id = ? ORDER BY part_name'
    );
    if (!$st2) {
        return array_merge($base, [
            'setup_id' => $orderId,
            'message' => 'พบ setup_id แต่อ่านรายละเอียดคำสั่งไม่ได้',
        ]);
    }
    $st2->bind_param('s', $orderId);
    $st2->execute();
    $lines = $st2->get_result()->fetch_all(MYSQLI_ASSOC);

    $model = trim((string) ($stockRow['model'] ?? ''));
    $matchedLine = null;
    foreach ($lines as $line) {
        $partName = trim((string) ($line['part_name'] ?? ''));
        if ($model !== '' && strcasecmp($partName, $model) === 0) {
            $matchedLine = $line;
            break;
        }
    }
    if (!$matchedLine && count($lines) === 1) {
        $matchedLine = $lines[0];
    }

    $summary = $matchedLine ?: ($lines[0] ?? null);
    $hasSetup = stockparts_setup_id_is_withdraw($orderId);

    return [
        'ok' => true,
        'has_withdraw' => $hasSetup,
        'has_order_detail' => $summary !== null,
        'serial' => $serial,
        'stock' => $stockRow,
        'setup_id' => $orderId,
        'order_id' => $orderId,
        'matched_line' => $matchedLine,
        'order_lines' => $lines,
        'summary' => $summary,
        'message' => $summary ? '' : ($hasSetup
            ? 'มี setup_id แต่ไม่พบรายละเอียดใน po_order_parts'
            : 'ยังไม่มีข้อมูลการเบิกใช้งานขาย'),
    ];
}

/**
 * สร้าง HTML card แสดงการเบิกใช้งานขายบนโปรไฟล์เครื่อง
 *
 * @param array<string,mixed> $info จาก asset_stockparts_withdraw_info()
 * @return string
 */
function asset_stockparts_withdraw_card_html(array $info): string
{
    $out = '<div class="asset-sales-card">';
    $out .= '<div class="asset-sales-card-head">' . ui_icon_html('stock-out-set', 16)
        . '<b>การเบิกใช้งานขาย</b></div>';

    if (empty($info['ok'])) {
        $out .= '<p class="muted asset-sales-empty">' . h((string) ($info['message'] ?? 'ไม่มีข้อมูล')) . '</p></div>';
        return $out;
    }

    if (empty($info['has_withdraw'])) {
        $out .= '<p class="muted asset-sales-empty">' . h((string) ($info['message'] ?? 'ยังไม่มีการเบิกใช้งานขาย')) . '</p></div>';
        return $out;
    }

    $stock = $info['stock'] ?? [];
    $ts = $stock['timestamp'] ?? null;
    $setupId = trim((string) ($info['setup_id'] ?? ''));
    $hasDetail = !empty($info['has_order_detail']) && !empty($info['summary']);
    $sum = $hasDetail ? $info['summary'] : null;

    $out .= '<p class="asset-sales-ref">อ้างอิงการเบิกใช้เครื่องจากระบบ stock</p>';
    $out .= '<dl class="asset-sales-dl">';
    $out .= '<dt>Setup ID</dt><dd><b class="asset-sales-setup-id">#' . h($setupId) . '</b></dd>';

    if ($hasDetail) {
        $out .= '<dt>ลูกค้า / โครงการ</dt><dd><b>' . h((string) ($sum['order_customer_name'] ?: '-')) . '</b></dd>';
        $out .= '<dt>ชุดสินค้า</dt><dd>' . h((string) ($sum['order_product_type'] ?: '-')) . '</dd>';
        $out .= '<dt>รายการเครื่อง</dt><dd>' . h((string) ($sum['part_name'] ?: '-'));
        if (!empty($sum['part_code'])) {
            $out .= ' <span class="muted">(' . h((string) $sum['part_code']) . ')</span>';
        }
        $out .= '</dd>';
        $out .= '<dt>สถานะ</dt><dd><span class="asset-sales-badge">' . h((string) ($sum['status_product'] ?: '-')) . '</span></dd>';
    } elseif (!empty($stock['model'])) {
        $out .= '<dt>รุ่น (stock)</dt><dd>' . h((string) $stock['model']) . '</dd>';
    }

    if ($ts) {
        $out .= '<dt>วันเวลาบันทึก stock</dt><dd>' . h(dthai_full($ts)) . '</dd>';
    }
    if (isset($stock['active'])) {
        $out .= '<dt>Active</dt><dd>' . ((int) $stock['active'] === 1 ? 'ใช้งาน' : 'ไม่นับ stock') . '</dd>';
    }
    $out .= '</dl>';

    if (!$hasDetail && !empty($info['message'])) {
        $out .= '<p class="muted asset-sales-empty">' . h((string) $info['message']) . '</p>';
    }

    if ($hasDetail) {
        $others = [];
        $matchedId = (int) ($info['matched_line']['id'] ?? 0);
        foreach ($info['order_lines'] ?? [] as $line) {
            if ((int) ($line['id'] ?? 0) === $matchedId) {
                continue;
            }
            $others[] = $line;
        }
        if ($others) {
            $out .= '<div class="asset-sales-others"><span class="muted">รายการอื่นในชุดเดียวกัน</span><ul>';
            $show = array_slice($others, 0, 5);
            foreach ($show as $line) {
                $out .= '<li>' . h((string) ($line['part_name'] ?? ''));
                if (!empty($line['quantity'])) {
                    $out .= ' · ' . number_format((int) $line['quantity']) . ' ' . h((string) ($line['unit'] ?: 'ชิ้น'));
                }
                $out .= '</li>';
            }
            if (count($others) > 5) {
                $out .= '<li class="muted">… และอีก ' . (count($others) - 5) . ' รายการ</li>';
            }
            $out .= '</ul></div>';
        }
    }

    $out .= '<p class="asset-sales-foot muted"><a href="' . h(BASE_URL . '/share.php?q=' . rawurlencode((string) $info['serial']))
        . '">ดูในทะเบียน stock →</a></p>';
    $out .= '</div>';
    return $out;
}
