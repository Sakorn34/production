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
 *   • "ชื่อหน่วยงาน" ไม่ได้อยู่ใน equipment_claim_history เลย (มี site_name แค่ 12
 *     จาก 170 แถว) แต่อยู่ที่ setup_orders.company_name/department ซึ่งมีครบ 509/493
 *     แถว — หน้า claim_history.php ของระบบนั้นรวมสามแหล่ง เราอ่านแหล่งเดียวจึงไม่เจอ
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
            $r['_src'] = 'claim';
            $r['_role'] = (trim((string) $r['new_serial_number']) === $serial) ? 'new' : 'old';
            $out['rows'][] = $r;
        }
        $st->close();

        // แหล่งที่สอง: ใบส่งมอบ Order — 449 จาก 619 S/N มีเฉพาะที่นี่ ไม่มีใน
        // equipment_claim_history เลย และ "ชื่อหน่วยงาน" ก็อยู่ที่นี่ (company_name
        // กับ department) ไม่ได้อยู่ในตารางเคลม · หน้า claim_history.php ของระบบนั้น
        // รวมสองแหล่งนี้เข้าด้วยกัน การอ่านแหล่งเดียวจึงเห็นไม่ครบ
        $st2 = $db->prepare(
            'SELECT ps.id, ps.issue_ref, ps.issue_type, ps.issue_date, ps.serial_number,
                    ps.old_serial_number, ps.po_number, ps.company_name,
                    so.customer_name, so.company_name AS so_company, so.department,
                    so.contact_person, so.contact_phone, so.product, so.sale_type,
                    so.delivery_date, so.warranty_start_date, so.warranty_period,
                    so.status, so.notes, so.created_by_name, so.po_date
             FROM po_order_part_serials ps
             LEFT JOIN setup_orders so ON so.id = ps.order_id
             WHERE ps.serial_number = ? OR ps.old_serial_number = ?
             ORDER BY ps.issue_date DESC, ps.id DESC
             LIMIT 20'
        );
        if ($st2) {
            $st2->bind_param('ss', $serial, $serial);
            $st2->execute();
            $res2 = $st2->get_result();
            while ($r = $res2->fetch_assoc()) {
                $r['_src'] = 'order';
                $r['_role'] = (trim((string) $r['serial_number']) === $serial) ? 'new' : 'old';
                $out['rows'][] = $r;
            }
            $st2->close();
        }

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
    $fromOrder = ((string) ($row['_src'] ?? '')) === 'order';
    if ($isClaim) {
        $label = $role === 'old' ? 'เคลมเปลี่ยนออก' : 'เคลมเปลี่ยนเข้า';
        $cls = 'asset-sales-badge-claim';
    } else {
        $label = $fromOrder ? 'ส่งมอบตาม Order' : 'ขาย';
        $cls = 'asset-sales-badge-sale';
    }
    return '<span class="asset-sales-badge ' . $cls . '">' . h($label) . '</span>';
}

/**
 * ปรับแถวจากสองตารางให้เป็นชุดฟิลด์เดียวกัน
 *
 * equipment_claim_history กับ po_order_part_serials+setup_orders เก็บเรื่องเดียวกัน
 * คนละชื่อคอลัมน์ (claim_number/po_number · customer_name/company_name · site_name/
 * department) การ์ดจึงต้องมีชุดกลางไว้ ไม่ใช่เขียน if แยกสองชุดตลอดทั้งตัววาด
 *
 * @param array<string,mixed> $r
 * @return array<string,string>
 */
function setup_sale_history_norm(array $r): array
{
    $g = function ($k) use ($r) { return trim((string) ($r[$k] ?? '')); };
    $fromOrder = ((string) ($r['_src'] ?? '')) === 'order';
    if ($fromOrder) {
        // ชื่อหน่วยงานอยู่ที่ company_name — customer_name ของตารางนี้เป็นชื่อคนติดต่อ
        $org = $g('so_company') !== '' ? $g('so_company') : $g('company_name');
        return [
            'org'      => $org,
            'person'   => $g('customer_name'),
            'dept'     => $g('department'),
            'ref'      => $g('issue_ref') !== '' ? $g('issue_ref') : $g('po_number'),
            'po'       => $g('po_number'),
            'date'     => $g('issue_date') !== '' ? $g('issue_date') : $g('po_date'),
            'product'  => $g('product'),
            'contact'  => trim($g('contact_person') . ' ' . $g('contact_phone')),
            'delivery' => $g('delivery_date'),
            'warranty' => trim($g('warranty_start_date') . ' ' . $g('warranty_period')),
            'status'   => $g('status'),
            'remark'   => $g('notes'),
            'by'       => $g('created_by_name'),
            'other_sn' => $g('old_serial_number'),
        ];
    }
    $role = (string) ($r['_role'] ?? 'new');
    return [
        'org'      => $g('customer_name'),
        'person'   => '',
        'dept'     => $g('site_name'),
        'ref'      => $g('claim_number'),
        'po'       => $g('po_number'),
        'date'     => $g('claim_date'),
        'product'  => trim(implode(' · ', array_filter([$g('product_name'), $g('product_code')]))),
        'contact'  => '',
        'delivery' => '',
        'warranty' => '',
        'status'   => '',
        'remark'   => $role === 'new' ? $g('new_remark') : $g('old_remark'),
        'by'       => $g('created_by'),
        'other_sn' => $role === 'new' ? $g('old_serial_number') : $g('new_serial_number'),
    ];
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
    $v = setup_sale_history_norm($row);
    $pairs = [
        ['ลูกค้า', $v['org'], (string) ($info['customer'] ?? '')],
        ['PO', $v['po'], (string) ($info['po'] ?? '')],
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
        $v = setup_sale_history_norm($r);

        $out .= '<div class="asset-sales-setup-row">';
        $out .= '<p class="asset-sales-summary">' . setup_sale_history_badge($r);
        if ($v['org'] !== '') {
            $out .= ' <b class="asset-sales-cust">' . h($v['org']) . '</b>';
        }
        if ($v['dept'] !== '') {
            $out .= ' <span class="asset-sales-dept">' . h($v['dept']) . '</span>';
        }
        if ($v['date'] !== '') {
            $out .= ' <span class="asset-sales-sep">·</span> <span class="asset-sales-when">'
                . h(dthai($v['date'])) . '</span>';
        }
        if ($v['ref'] !== '') {
            $out .= ' <span class="asset-sales-sep">·</span> อ้างอิง <b class="asset-sales-setup-id">#'
                . h($v['ref']) . '</b>';
        }
        $out .= '</p>';

        // คู่เครื่องที่เคลมเปลี่ยนกัน — เรื่องที่ฝั่ง stock ตอบไม่ได้
        if ($isClaim && $v['other_sn'] !== '') {
            $verb = $role === 'new' ? 'เปลี่ยนมาจากเครื่อง' : 'ถูกเปลี่ยนเป็นเครื่อง';
            $out .= '<p class="asset-sales-claim-pair">' . h($verb) . ' <b>' . h($v['other_sn']) . '</b></p>';
        }

        $rows = '';
        $add = function ($label, $val) use (&$rows) {
            if (trim((string) $val) !== '') {
                $rows .= stockparts_withdraw_dl_row($label, stockparts_fmt_field((string) $val));
            }
        };
        $add('สินค้า', $v['product']);
        $add('PO', $v['po']);
        // customer_name กับ contact_person มักเป็นคนเดียวกัน — ต่อกันตรง ๆ ได้ "คุณผิว คุณผิว"
        $who = array_values(array_unique(array_filter([$v['person'], $v['contact']], function ($x) {
            return trim($x) !== '';
        })));
        if (count($who) === 2 && mb_stripos($who[1], $who[0]) !== false) {
            $who = [$who[1]];
        }
        $add('ผู้ติดต่อ', implode(' · ', $who));
        $add('เลขสัญญาเช่า', (string) ($r['lease_number'] ?? ''));
        $add('ที่อยู่', (string) ($r['customer_address'] ?? ''));
        $add('วันส่งมอบ', $v['delivery'] !== '' ? dthai($v['delivery']) : '');
        $add('ประกัน', $v['warranty']);
        $add('สถานะ Order', $v['status']);
        $add('หมายเหตุ', $v['remark']);
        $add('ผู้บันทึก', $v['by']);
        if ($rows !== '') {
            $out .= '<dl class="asset-sales-dl asset-sales-dl-inline">' . $rows . '</dl>';
        }

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
