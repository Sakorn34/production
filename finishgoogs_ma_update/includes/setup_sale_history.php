<?php
/**
 * includes/setup_sale_history.php — ประวัติขาย/เคลมจากระบบ setupsystem
 * ─────────────────────────────────────────────────────────────────────────────
 * การ์ด "การเบิกใช้งานขาย" บนหน้าเครื่องประกอบขึ้นจากฐาน biton_stockparts ล้วน ๆ
 * (stock · stock_movements · po_order_parts · stock_old) ซึ่งครอบไม่หมด:
 *
 *   • 31 จาก 170 รายการขาย/เคลม ไม่มีแถวใน stock เลย — การ์ดจึงขึ้นว่า
 *     "ยังไม่มีข้อมูลการเบิกใช้งานขาย" ทั้งที่ขายไปแล้วและมีใบอ้างอิงครบ
 *   • รายการเคลม 17 รายการรู้แค่จากข้อความใน stock_movements.notes ซึ่งบอกไม่ได้
 *     ว่าเครื่องนี้ถูกเปลี่ยนมาจากตัวไหน หรือถูกเปลี่ยนออกไปเป็นตัวไหน
 *
 * biton_setup.equipment_claim_history เป็นทะเบียนตั้งต้นของเรื่องนี้ (ระบบ
 * setupsystem เป็นคนบันทึก) เราอ่านอย่างเดียวเพื่อเติมให้การ์ดครบ ไม่เขียนกลับ
 * และไม่ย้ายข้อมูลมา — รายละเอียดเต็มยังอยู่ที่ระบบนั้น
 * ─────────────────────────────────────────────────────────────────────────────
 */

/** URL หน้าประวัติขายของระบบ setupsystem */
const SETUP_SALE_HISTORY_URL = 'https://bit-online.net/setupsystem/claim_history.php';

/**
 * ประวัติขาย/เคลมของ S/N หนึ่งตัว
 *
 * คืนทั้งรายการที่ S/N นี้เป็น "ตัวใหม่" (ขาย/เคลมเข้ามา) และที่เป็น "ตัวเก่า"
 * (ถูกเคลมเปลี่ยนออกไป) เพราะทั้งสองฝั่งเป็นเรื่องเดียวกันของเครื่องเครื่องนี้
 *
 * @param string $serial
 * @return array{ok:bool,rows:array<int,array<string,mixed>>,error:string}
 */
function setup_sale_history_for_serial(string $serial): array
{
    $serial = trim($serial);
    $out = ['ok' => false, 'rows' => [], 'error' => ''];
    if ($serial === '') {
        return $out;
    }
    $db = function_exists('dbSetup') ? dbSetup() : null;
    if (!$db) {
        $out['error'] = function_exists('dbSetupError') ? dbSetupError() : 'เชื่อมต่อฐาน setup ไม่ได้';
        return $out;
    }
    try {
        $st = $db->prepare(
            'SELECT id, issue_type, claim_number, claim_date, product_code, product_name,
                    old_serial_number, new_serial_number, old_remark, new_remark,
                    customer_name, customer_address, site_name, po_number, lease_number,
                    tax_id, created_by, created_at
             FROM equipment_claim_history
             WHERE old_serial_number = ? OR new_serial_number = ?
             ORDER BY claim_date DESC, id DESC
             LIMIT 20'
        );
        if (!$st) {
            $out['error'] = 'อ่าน equipment_claim_history ไม่ได้';
            return $out;
        }
        $st->bind_param('ss', $serial, $serial);
        $st->execute();
        $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            // มุมมองจากเครื่องนี้: เป็นตัวที่รับเข้ามา หรือเป็นตัวที่ถูกเปลี่ยนออก
            $r['_role'] = (trim((string) $r['new_serial_number']) === $serial) ? 'new' : 'old';
            $out['rows'][] = $r;
        }
        $st->close();
        $out['ok'] = true;
    } catch (Throwable $e) {
        error_log('[setup_sale_history] ' . $e->getMessage());
        $out['error'] = 'อ่านประวัติขายไม่ได้';
    }
    return $out;
}

/**
 * ป้ายประเภทรายการ
 *
 * @param array<string,mixed> $row
 * @return string HTML
 */
function setup_sale_history_badge(array $row): string
{
    $isClaim = ((string) ($row['issue_type'] ?? '')) === 'claim';
    $role = (string) ($row['_role'] ?? 'new');
    if ($isClaim) {
        $label = $role === 'old' ? 'เคลมเปลี่ยนออก' : 'เคลมเปลี่ยนเข้า';
        $cls = 'asset-sales-badge-claim';
    } else {
        $label = 'ขาย';
        $cls = 'asset-sales-badge-sale';
    }
    return '<span class="asset-sales-badge ' . $cls . '">' . h($label) . '</span>';
}

/**
 * เทียบข้อมูลจากระบบขายกับที่การ์ดแสดงอยู่ — คืนเฉพาะจุดที่ไม่ตรงกัน
 *
 * ไม่ตัดสินว่าฝั่งไหนถูก แค่บอกว่ามีสองค่า เพราะคนที่รู้ว่าอันไหนจริงคือผู้ใช้
 *
 * @param array<string,mixed> $row  แถวจาก equipment_claim_history
 * @param array<string,mixed> $info ผลจาก asset_stockparts_withdraw_info()
 * @return array<int,string>
 */
function setup_sale_history_conflicts(array $row, array $info): array
{
    $diff = [];
    $pairs = [
        ['ลูกค้า', (string) ($row['customer_name'] ?? ''), (string) ($info['customer'] ?? '')],
        ['PO', (string) ($row['po_number'] ?? ''), (string) ($info['po'] ?? '')],
    ];
    foreach ($pairs as $p) {
        list($label, $fromSetup, $fromStock) = $p;
        $fromSetup = trim($fromSetup);
        $fromStock = trim($fromStock);
        if ($fromSetup === '' || $fromStock === '') {
            continue;
        }
        if (mb_strtolower($fromSetup) !== mb_strtolower($fromStock)) {
            $diff[] = $label . ': ระบบขายว่า "' . $fromSetup . '" · ทะเบียน stock ว่า "' . $fromStock . '"';
        }
    }
    return $diff;
}

/**
 * บล็อกประวัติขาย/เคลม สำหรับต่อท้ายการ์ด "การเบิกใช้งานขาย"
 *
 * @param string              $serial
 * @param array<string,mixed> $info ผลจาก asset_stockparts_withdraw_info() (ใช้เทียบค่า)
 * @return string HTML ('' ถ้าไม่มีอะไรจะแสดง)
 */
function setup_sale_history_html(string $serial, array $info = []): string
{
    $hist = setup_sale_history_for_serial($serial);
    if (!$hist['ok'] || !$hist['rows']) {
        // ต่อฐานไม่ได้ต้องบอก ไม่ใช่เงียบ — ไม่งั้นผู้ใช้แยกไม่ออกระหว่าง
        // "ไม่มีประวัติขาย" กับ "อ่านประวัติขายไม่ได้" ซึ่งคนละเรื่องกัน
        if ($hist['error'] !== '') {
            return '<div class="asset-sales-block"><div class="asset-sales-block-title">ประวัติขาย/เคลม</div>'
                . '<p class="muted">' . h($hist['error']) . '</p></div>';
        }
        return '';
    }

    $out = '<div class="asset-sales-block asset-sales-setup-block">';
    $out .= '<div class="asset-sales-block-title">ประวัติขาย/เคลม <span class="muted">(ระบบ setup)</span></div>';

    foreach ($hist['rows'] as $r) {
        $isClaim = ((string) ($r['issue_type'] ?? '')) === 'claim';
        $role = (string) ($r['_role'] ?? 'new');
        $out .= '<div class="asset-sales-setup-row">';
        $out .= '<p class="asset-sales-summary">' . setup_sale_history_badge($r);
        $cust = trim((string) ($r['customer_name'] ?? ''));
        if ($cust !== '') {
            $out .= ' <b class="asset-sales-cust">' . h($cust) . '</b>';
        }
        $when = trim((string) ($r['claim_date'] ?? ''));
        if ($when !== '') {
            $out .= ' <span class="asset-sales-sep">·</span> <span class="asset-sales-when">'
                . h(dthai($when)) . '</span>';
        }
        $ref = trim((string) ($r['claim_number'] ?? ''));
        if ($ref !== '') {
            $out .= ' <span class="asset-sales-sep">·</span> อ้างอิง <b class="asset-sales-setup-id">#'
                . h($ref) . '</b>';
        }
        $out .= '</p>';

        // คู่เครื่องที่เคลมเปลี่ยนกัน — เรื่องที่ฝั่ง stock ตอบไม่ได้
        if ($isClaim) {
            $other = $role === 'new'
                ? trim((string) ($r['old_serial_number'] ?? ''))
                : trim((string) ($r['new_serial_number'] ?? ''));
            if ($other !== '') {
                $verb = $role === 'new' ? 'เปลี่ยนมาจากเครื่อง' : 'ถูกเปลี่ยนเป็นเครื่อง';
                $out .= '<p class="asset-sales-claim-pair">' . h($verb) . ' <b>' . h($other) . '</b></p>';
            }
        }

        $rows = '';
        $rows .= stockparts_withdraw_dl_row('สินค้า', stockparts_fmt_field(
            trim(implode(' · ', array_filter([
                (string) ($r['product_name'] ?? ''),
                (string) ($r['product_code'] ?? ''),
            ])))
        ));
        $rows .= stockparts_withdraw_dl_row('PO', stockparts_fmt_field((string) ($r['po_number'] ?? '')));
        if (trim((string) ($r['lease_number'] ?? '')) !== '') {
            $rows .= stockparts_withdraw_dl_row('เลขสัญญาเช่า', stockparts_fmt_field((string) $r['lease_number']));
        }
        if (trim((string) ($r['site_name'] ?? '')) !== '') {
            $rows .= stockparts_withdraw_dl_row('ไซต์งาน', stockparts_fmt_field((string) $r['site_name']));
        }
        if (trim((string) ($r['customer_address'] ?? '')) !== '') {
            $rows .= stockparts_withdraw_dl_row('ที่อยู่', stockparts_fmt_field((string) $r['customer_address']));
        }
        $remark = $role === 'new' ? (string) ($r['new_remark'] ?? '') : (string) ($r['old_remark'] ?? '');
        if (trim($remark) !== '') {
            $rows .= stockparts_withdraw_dl_row('หมายเหตุ', stockparts_fmt_field($remark));
        }
        $rows .= stockparts_withdraw_dl_row('ผู้บันทึก', stockparts_fmt_field((string) ($r['created_by'] ?? '')));
        $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">' . $rows . '</dl>';

        foreach (setup_sale_history_conflicts($r, $info) as $c) {
            $out .= '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' ' . h($c) . '</p>';
        }
        $out .= '</div>';
    }

    $out .= '<p class="asset-sales-foot muted"><a href="'
        . h(SETUP_SALE_HISTORY_URL . '?serial=' . rawurlencode($serial))
        . '" target="_blank" rel="noopener">ดูในระบบขาย ↗</a></p>';
    return $out . '</div>';
}
