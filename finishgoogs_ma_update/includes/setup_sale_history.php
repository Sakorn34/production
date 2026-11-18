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
 * URL ค้นหาในหน้า claim_history — ช่อง «เลขที่/PO/Order» ใช้พารามิเตอร์ claim_no
 *
 * @param string $term เลข PO · OPS-xxx · เลข order
 * @return string
 */
function setup_sale_history_search_url(string $term): string
{
    $term = trim($term);
    if ($term === '') {
        return '';
    }
    return SETUP_SALE_HISTORY_URL . '?claim_no=' . rawurlencode($term);
}

/**
 * แถวอ้างอิงในการ์ด — ปุ่มลิงก์ไปค้นหาระบบขายทีละรายการ
 *
 * @param array<int,string> $poList      เลข PO ดิบจาก setup/stock
 * @param array<int,string> $orderRefs   เลข Order / claim / setup_id
 * @return string HTML (ยังไม่ครอบ dd ทั้งก้อน)
 */
function setup_sale_history_ref_facts_html(array $poList, array $orderRefs): string
{
    $bits = [];
    $sep = ' <span class="asset-sales-sep">·</span> ';
    $seenLabels = [];
    $poKeys = [];
    $add = function (string $label, string $searchTerm) use (&$bits, &$seenLabels) {
        $label = trim($label);
        $searchTerm = trim($searchTerm);
        if ($label === '') {
            return;
        }
        $lk = mb_strtolower($label);
        if (isset($seenLabels[$lk])) {
            return;
        }
        $seenLabels[$lk] = true;
        $url = setup_sale_history_search_url($searchTerm);
        if ($url !== '') {
            $bits[] = '<a class="asset-sales-ref-link" href="' . h($url) . '" target="_blank" rel="noopener">'
                . h($label) . '</a>';
        } else {
            $bits[] = h($label);
        }
    };
    foreach ($poList as $po) {
        $po = trim((string) $po);
        if ($po === '' || $po === '-') {
            continue;
        }
        $num = preg_replace('/^po\s*/iu', '', $po);
        if ($num === '') {
            continue;
        }
        $poKeys[mb_strtolower($num)] = true;
        $add('PO ' . $num, $num);
    }
    foreach ($orderRefs as $ref) {
        $ref = trim((string) $ref);
        if ($ref === '' || $ref === '-') {
            continue;
        }
        $key = mb_strtolower(preg_replace('/^(order\s+|#)/iu', '', $ref));
        if (isset($poKeys[$key])) {
            continue;
        }
        // OPS-xxx เป็นป้ายใบส่งมอบ — ใช้เลข order ตัวเลขในแถวเดียวกันค้นหาได้แล้ว ไม่โชว์ซ้ำ
        if (preg_match('/^OPS-/i', $ref)) {
            continue;
        }
        $term = preg_replace('/^(order\s+|#)/iu', '', $ref);
        $label = preg_match('/^order\s+/iu', $ref) ? $ref : $ref;
        if (preg_match('/^\d+$/', $term)) {
            $label = $term;
        }
        $add($label, $term);
    }
    return implode($sep, $bits);
}

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
 * ป้ายประเภทรายการแบบข้อความ — ใช้ทั้ง badge และการ์ดสรุป
 *
 * @param array<string,mixed> $row
 * @return string
 */
function setup_sale_history_kind_label(array $row): string
{
    $isClaim = ((string) ($row['issue_type'] ?? '')) === 'claim';
    $role = (string) ($row['_role'] ?? 'new');
    $fromOrder = ((string) ($row['_src'] ?? '')) === 'order';
    if ($isClaim) {
        return $role === 'old' ? 'เคลมเปลี่ยนออก' : 'เคลมเปลี่ยนเข้า';
    }
    return $fromOrder ? 'ส่งมอบตาม Order' : 'ขาย';
}

/**
 * ป้ายประเภทรายการเป็น HTML badge
 *
 * @param array<string,mixed> $row
 * @return string HTML
 */
function setup_sale_history_badge(array $row): string
{
    $isClaim = ((string) ($row['issue_type'] ?? '')) === 'claim';
    $label = setup_sale_history_kind_label($row);
    $cls = $isClaim ? 'asset-sales-badge-claim' : 'asset-sales-badge-sale';
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
 * เทียบชื่อแบบไม่สนตัวพิมพ์และช่องว่างเกิน
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function setup_sale_history_names_equal(string $a, string $b): bool
{
    $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $a)));
    $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $b)));
    return $a !== '' && $a === $b;
}

/**
 * ชื่อที่น่าจะเป็นบุคคล (คำนำหน้าไทย/อังกฤษ) ไม่ใช่ชื่อหน่วยงานเต็ม ๆ
 *
 * @param string $name
 * @return bool
 */
function setup_sale_history_looks_like_person_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }
    if (preg_match('/^(คุณ|นาย|นาง|นางสาว|ด\.?ช\.?|ด\.?ญ\.?|Mr\.?|Mrs\.?|Ms\.?)\s/iu', $name)) {
        return true;
    }
    if (preg_match('/บริษัท|บจก|บจ\.|จำกัด|มหาชน|ห้างหุ้นส่วน|\bCo\.|\bLtd|\bLimited|Factory|โรงงาน/iu', $name)) {
        return false;
    }
    return mb_strlen($name) <= 40;
}

/**
 * ชื่อในใบเบิก stock กับหน่วยงานจากระบบขาย — เข้ากันได้ (ไม่ถือว่าขัด)
 *
 * เช่น หน่วยงาน "ไทยโคะอิโท …" กับใบเบิก "คุณ…" หรือตรงกับผู้ติดต่อในใบ Order
 *
 * @param string              $org
 * @param string              $stockCustomer
 * @param array<string,string> $norm ผลจาก setup_sale_history_norm()
 * @return bool
 */
function setup_sale_history_customer_stock_complements_org(string $org, string $stockCustomer, array $norm): bool
{
    $org = trim($org);
    $stockCustomer = trim($stockCustomer);
    if ($stockCustomer === '' || $org === '') {
        return true;
    }
    if (setup_sale_history_names_equal($org, $stockCustomer)) {
        return true;
    }
    foreach (['person', 'contact'] as $k) {
        $p = trim((string) ($norm[$k] ?? ''));
        if ($p === '') {
            continue;
        }
        if (setup_sale_history_names_equal($p, $stockCustomer)) {
            return true;
        }
        if (mb_stripos($p, $stockCustomer) !== false || mb_stripos($stockCustomer, $p) !== false) {
            return true;
        }
    }
    if (setup_sale_history_looks_like_person_name($stockCustomer)
        && !setup_sale_history_looks_like_person_name($org)) {
        return true;
    }
    return false;
}

/**
 * ฟิลด์ลูกค้าสำหรับการ์ดเบิกขาย — หัวการ์ดเป็นหน่วยงาน แถว facts เป็นผู้ติดต่อ
 *
 * @param array<int,array<string,mixed>> $setupRows
 * @param array<string,mixed>            $summary ผลจาก stockparts_withdraw_summary_fields()
 * @return array{head:string,contact:string}
 */
function setup_sale_history_card_customer_fields(array $setupRows, array $summary): array
{
    $orgs = [];
    $people = [];
    foreach ($setupRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $v = setup_sale_history_norm($r);
        $o = trim((string) ($v['org'] ?? ''));
        if ($o !== '' && $o !== '-') {
            $orgs[] = $o;
        }
        foreach (['person', 'contact'] as $k) {
            $p = trim((string) ($v[$k] ?? ''));
            if ($p !== '' && $p !== '-') {
                $people[] = $p;
            }
        }
    }
    $uniq = static function (array $vals): array {
        $out = [];
        $seen = [];
        foreach ($vals as $v) {
            $v = trim((string) $v);
            if ($v === '' || $v === '-') {
                continue;
            }
            $k = mb_strtolower($v);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $v;
        }
        return $out;
    };
    $headList = $uniq($orgs);
    $head = $headList ? $headList[0] : '';
    $stock = trim((string) ($summary['customer'] ?? ''));
    if ($head === '' && $stock !== '') {
        return ['head' => $stock, 'contact' => ''];
    }
    if ($stock !== '' && !setup_sale_history_names_equal($stock, $head)) {
        $inPeople = false;
        foreach ($people as $p) {
            if (setup_sale_history_names_equal($p, $stock)) {
                $inPeople = true;
                break;
            }
        }
        $complements = false;
        foreach ($setupRows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $v = setup_sale_history_norm($r);
            if (setup_sale_history_customer_stock_complements_org($head, $stock, $v)) {
                $complements = true;
                break;
            }
        }
        if (!$inPeople && ($complements || setup_sale_history_looks_like_person_name($stock))) {
            $people[] = $stock;
        }
    }
    $contact = implode(' · ', $uniq($people));
    return ['head' => $head, 'contact' => $contact];
}

/**
 * ไซต์งานสำหรับการ์ดเบิกขาย — จาก site_name / department ในระบบขาย
 *
 * ใช้เมื่อ stock_old ไม่มีชื่อไซต์ แทนการขึ้น «ไม่มีบันทึก» ทันที
 *
 * @param array<int,array<string,mixed>> $setupRows
 * @return string ข้อความรวม (ยังไม่ escape HTML)
 */
function setup_sale_history_card_site_line(array $setupRows): string
{
    $sites = [];
    foreach ($setupRows as $r) {
        if (!is_array($r)) {
            continue;
        }
        $v = setup_sale_history_norm($r);
        $dept = trim((string) ($v['dept'] ?? ''));
        if ($dept !== '' && $dept !== '-') {
            $sites[] = $dept;
        }
    }
    $seen = [];
    $uniq = [];
    foreach ($sites as $s) {
        $k = mb_strtolower($s);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $uniq[] = $s;
    }
    return implode(' · ', $uniq);
}

/**
 * เทียบข้อมูลจากระบบขายกับที่การ์ดแสดงอยู่ — คืนเฉพาะจุดที่ไม่ตรงกัน
 *
 * ชื่อลูกค้า: ถ้า stock เป็นผู้ติดต่อหรือชื่อคนต่อหน่วยงาน ไม่ถือว่าขัด (ดู complements)
 *
 * @param array<string,mixed> $row  แถวจาก equipment_claim_history
 * @param array<string,mixed> $info ผลจาก stockparts_withdraw_summary_fields()
 * @return array<int,string>
 */
function setup_sale_history_conflicts(array $row, array $info): array
{
    $diff = [];
    $v = setup_sale_history_norm($row);
    $stockCustomer = (string) ($info['customer'] ?? '');
    $pairs = [
        ['ลูกค้า', $v['org'], $stockCustomer],
        ['PO', $v['po'], (string) ($info['po'] ?? '')],
    ];
    foreach ($pairs as $p) {
        list($label, $fromSetup, $fromStock) = $p;
        $fromSetup = trim($fromSetup);
        $fromStock = trim($fromStock);
        if ($fromSetup === '' || $fromStock === '') {
            continue;
        }
        if ($label === 'ลูกค้า'
            && setup_sale_history_customer_stock_complements_org($fromSetup, $fromStock, $v)) {
            continue;
        }
        if (!setup_sale_history_names_equal($fromSetup, $fromStock)) {
            $diff[] = $label . ': ระบบขายว่า "' . $fromSetup . '" · ทะเบียน stock ว่า "' . $fromStock . '"';
        }
    }
    return $diff;
}

/**
 * ประวัติขาย/เคลม ในรูปขั้นของไทม์ไลน์ — การ์ดเอาไปเรียงรวมกับขั้นอื่นเอง
 *
 * เดิมฟังก์ชันนี้วาดบล็อกของตัวเองพร้อมหัวข้อและกรอบ พอเอาไปต่อท้ายการ์ด
 * ลูกค้า วันที่ และชื่อสินค้าจึงขึ้นซ้ำกับส่วนบนของการ์ดอีกชุด
 *
 * @param string              $serial
 * @param array<string,mixed> $info ผลจาก stockparts_withdraw_summary_fields() (ใช้เทียบค่า)
 * @return array{steps:array<int,array<string,mixed>>,error:string}
 */
function setup_sale_history_steps(string $serial, array $info = []): array
{
    $hist = setup_sale_history_for_serial($serial);
    $out = ['steps' => [], 'error' => ''];
    if (!$hist['ok'] || !$hist['rows']) {
        // ต่อฐานไม่ได้ต้องบอก ไม่ใช่เงียบ — ไม่งั้นผู้ใช้แยกไม่ออกระหว่าง
        // "ไม่มีประวัติขาย" กับ "อ่านประวัติขายไม่ได้" ซึ่งคนละเรื่องกัน
        $out['error'] = $hist['error'];
        return $out;
    }

    foreach ($hist['rows'] as $r) {
        $isClaim = ((string) ($r['issue_type'] ?? '')) === 'claim';
        $role = (string) ($r['_role'] ?? 'new');
        $v = setup_sale_history_norm($r);

        $title = setup_sale_history_badge($r);
        if ($v['ref'] !== '') {
            $title .= ' <b class="asset-sales-setup-id">#' . h($v['ref']) . '</b>';
        }
        if ($v['org'] !== '') {
            $title .= ' <b class="asset-sales-cust">' . h($v['org']) . '</b>';
        }
        if ($v['dept'] !== '') {
            $title .= ' <span class="asset-sales-dept">' . h($v['dept']) . '</span>';
        }

        // บรรทัดรายละเอียด — เอาเฉพาะที่การ์ดส่วนอื่นไม่ได้บอกไว้แล้ว
        // (ชื่อสินค้าอยู่หัวการ์ด ลูกค้ากับวันที่อยู่บรรทัดบนของขั้นนี้)
        $who = array_values(array_unique(array_filter([$v['person'], $v['contact']], function ($x) {
            return trim($x) !== '';
        })));
        if (count($who) === 2 && mb_stripos($who[1], $who[0]) !== false) {
            $who = [$who[1]];
        }
        $bits = [];
        $add = function ($label, $val) use (&$bits) {
            $val = trim((string) $val);
            if ($val !== '' && $val !== '-') {
                $bits[] = $label . ' ' . $val;
            }
        };
        $add('สถานะ', $v['status']);
        $add('ผู้ติดต่อ', implode(' · ', $who));
        $add('PO', $v['po']);
        $add('เลขสัญญาเช่า', (string) ($r['lease_number'] ?? ''));
        $add('วันส่งมอบ', $v['delivery'] !== '' ? dthai($v['delivery']) : '');
        $add('ประกัน', $v['warranty']);
        $add('ผู้บันทึก', $v['by']);
        $add('หมายเหตุ', $v['remark']);

        $extra = '';
        // คู่เครื่องที่เคลมเปลี่ยนกัน — เรื่องที่ฝั่ง stock ตอบไม่ได้
        if ($isClaim && $v['other_sn'] !== '') {
            $verb = $role === 'new' ? 'เปลี่ยนมาจากเครื่อง' : 'ถูกเปลี่ยนเป็นเครื่อง';
            $extra .= '<p class="asset-sales-claim-pair">' . h($verb) . ' <b>' . h($v['other_sn']) . '</b></p>';
        }
        foreach (setup_sale_history_conflicts($r, $info) as $c) {
            $extra .= '<p class="asset-sales-conflict">' . ui_icon_html('alert', 13, 'h-svg') . ' ' . h($c) . '</p>';
        }

        $out['steps'][] = [
            'date'  => $v['date'],
            'where' => 'ระบบขาย',
            'title' => $title,
            'meta'  => $bits ? implode(' · ', array_map('h', $bits)) : '',
            'extra' => $extra,
        ];
    }
    return $out;
}