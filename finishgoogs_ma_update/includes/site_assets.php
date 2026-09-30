<?php
/**
 * site_assets.php — ทะเบียนเครื่องตามไซต์งาน (30 ก.ย. 2569)
 *
 * ชื่อไซต์งานอยู่กระจาย 3 ที่ และเป็นข้อความอิสระทั้งหมด สะกดไม่ตรงกันข้ามระบบ
 * (ตรงกันเป๊ะแค่ 13 จาก 1,096 ชื่อ) จึงไม่รวมชื่อให้อัตโนมัติ — เก็บแยกตามแหล่งที่มา
 * แล้วให้คนค้นด้วยคำบางส่วนเอา เช่น "ซิลลิค" จะเจอทั้ง 3 แบบที่เขียนไว้ต่างกัน
 *
 *   lease → tbl_rent_product.p_sitename   ไซต์ที่ระบบเช่าบันทึกไว้ในสัญญา
 *   old   → stock_old.setup_id            ไซต์จากทะเบียน stock ของระบบเดิม
 *   setup → setup_orders.company_name     ลูกค้า/หน่วยงานที่สั่งงาน (ขายขาด/แบบเช่า)
 *
 * อ่านอย่างเดียวทั้งไฟล์ ไม่เขียนกลับไปที่ระบบไหน
 */

require_once __DIR__ . '/rent_ma_bridge.php';

/** แหล่งที่มาของชื่อไซต์งาน */
function site_assets_sources(): array
{
    return [
        'lease' => ['th' => 'ระบบเช่า',      'sub' => 'ไซต์ในสัญญาเช่า'],
        'old'   => ['th' => 'ทะเบียนเก่า',   'sub' => 'ทะเบียน stock ของระบบเดิม'],
        'setup' => ['th' => 'ใบสั่งงาน',     'sub' => 'ลูกค้าในระบบ setup'],
    ];
}

function site_assets_source_label(string $src): string
{
    $m = site_assets_sources();
    return isset($m[$src]) ? $m[$src]['th'] : $src;
}

/**
 * ค้นชื่อไซต์งานจากทุกแหล่ง
 *
 * @param string $q   คำค้นบางส่วน
 * @param int    $cap จำนวนชื่อสูงสุดต่อแหล่ง
 * @return array<int,array{src:string,site:string,n:int}> เรียงจากเครื่องเยอะไปน้อย
 */
function site_assets_search(string $q, int $cap = 40): array
{
    $q = trim($q);
    if ($q === '') {
        return [];
    }
    $out = [];

    $l = dbLeasing();
    if ($l) {
        $like = '%' . $l->real_escape_string($q) . '%';
        $r = @$l->query(
            "SELECT TRIM(p_sitename) k, COUNT(DISTINCT p_sn) n FROM tbl_rent_product
             WHERE p_sitename LIKE '$like' AND TRIM(IFNULL(p_sitename,'')) <> ''
             GROUP BY k ORDER BY n DESC LIMIT " . (int) $cap
        );
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'lease', 'site' => (string) $x[0], 'n' => (int) $x[1]];
        }
    }

    try {
        $s = dbStock();
    } catch (Throwable $e) {
        $s = null;
    }
    if ($s) {
        $like = '%' . $s->real_escape_string($q) . '%';
        $r = @$s->query(
            "SELECT TRIM(setup_id) k, COUNT(*) n FROM stock_old
             WHERE setup_id LIKE '$like' AND TRIM(IFNULL(setup_id,'')) <> ''
             GROUP BY k ORDER BY n DESC LIMIT " . (int) $cap
        );
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'old', 'site' => (string) $x[0], 'n' => (int) $x[1]];
        }
    }

    $u = function_exists('dbSetup') ? dbSetup() : null;
    if ($u) {
        $like = '%' . $u->real_escape_string($q) . '%';
        $r = @$u->query(
            "SELECT TRIM(IFNULL(NULLIF(TRIM(so.company_name),''), so.customer_name)) k,
                    COUNT(DISTINCT ps.serial_number) n
             FROM po_order_part_serials ps JOIN setup_orders so ON so.id = ps.order_id
             WHERE so.company_name LIKE '$like' OR so.customer_name LIKE '$like'
             GROUP BY k ORDER BY n DESC LIMIT " . (int) $cap
        );
        while ($r && ($x = $r->fetch_row())) {
            if (trim((string) $x[0]) !== '') {
                $out[] = ['src' => 'setup', 'site' => (string) $x[0], 'n' => (int) $x[1]];
            }
        }
    }

    usort($out, function ($a, $b) {
        return [$b['n'], $a['site']] <=> [$a['n'], $b['site']];
    });
    return $out;
}

/**
 * ไซต์ที่มีเครื่องมากที่สุดของแต่ละแหล่ง — ไว้เป็นจุดเริ่มตอนยังไม่ได้ค้นอะไร
 *
 * @return array<int,array{src:string,site:string,n:int}>
 */
function site_assets_top(int $perSource = 8): array
{
    $out = [];
    $perSource = max(1, min(30, $perSource));

    $l = dbLeasing();
    if ($l) {
        $r = @$l->query("SELECT TRIM(p_sitename) k, COUNT(DISTINCT p_sn) n FROM tbl_rent_product
                         WHERE TRIM(IFNULL(p_sitename,'')) <> ''
                         GROUP BY k ORDER BY n DESC LIMIT $perSource");
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'lease', 'site' => (string) $x[0], 'n' => (int) $x[1]];
        }
    }
    try {
        $s = dbStock();
    } catch (Throwable $e) {
        $s = null;
    }
    if ($s) {
        $r = @$s->query("SELECT TRIM(setup_id) k, COUNT(*) n FROM stock_old
                         WHERE TRIM(IFNULL(setup_id,'')) <> ''
                         GROUP BY k ORDER BY n DESC LIMIT $perSource");
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'old', 'site' => (string) $x[0], 'n' => (int) $x[1]];
        }
    }
    $u = function_exists('dbSetup') ? dbSetup() : null;
    if ($u) {
        $r = @$u->query("SELECT TRIM(IFNULL(NULLIF(TRIM(so.company_name),''), so.customer_name)) k,
                                COUNT(DISTINCT ps.serial_number) n
                         FROM po_order_part_serials ps JOIN setup_orders so ON so.id = ps.order_id
                         GROUP BY k ORDER BY n DESC LIMIT $perSource");
        while ($r && ($x = $r->fetch_row())) {
            if (trim((string) $x[0]) !== '') {
                $out[] = ['src' => 'setup', 'site' => (string) $x[0], 'n' => (int) $x[1]];
            }
        }
    }
    return $out;
}

/**
 * เครื่องทั้งหมดที่ไซต์งานหนึ่ง (ชื่อตรงเป๊ะ ในแหล่งเดียว)
 *
 * @return array<int,array<string,mixed>> sn · model · note · date
 */
function site_assets_at(string $src, string $site, int $limit = 300): array
{
    $site = trim($site);
    if ($site === '') {
        return [];
    }
    $rows = [];

    if ($src === 'lease') {
        $l = dbLeasing();
        if (!$l) {
            return [];
        }
        $esc = $l->real_escape_string($site);
        // แถวล่าสุดของแต่ละ S/N — สัญญาเดิมที่จบแล้วยังอยู่ในตาราง
        $r = @$l->query(
            "SELECT rp.p_sn, rp.p_product, rp.p_status, rp.p_date_received, c.cus_name
             FROM tbl_rent_product rp
             LEFT JOIN tbl_customer c ON c.cus_id = rp.p_cus_id
             WHERE TRIM(rp.p_sitename) = '$esc'
             ORDER BY CASE WHEN rp.p_status = 'active' THEN 0 ELSE 1 END, rp.p_id DESC
             LIMIT " . (int) ($limit * 3)
        );
        $seen = [];
        while ($r && ($x = $r->fetch_assoc())) {
            $sn = rent_normalize_sn($x['p_sn'] ?? '');
            if ($sn === '' || isset($seen[$sn])) {
                continue;
            }
            $seen[$sn] = true;
            $rows[] = [
                'sn' => $sn,
                'model' => trim((string) $x['p_product']),
                'note' => trim((string) $x['cus_name']),
                'tag' => trim((string) $x['p_status']) === 'active' ? 'สัญญายังเดินอยู่' : 'สัญญาจบแล้ว',
                'date' => substr(trim((string) $x['p_date_received']), 0, 10),
            ];
        }
        return array_slice($rows, 0, $limit);
    }

    if ($src === 'old') {
        try {
            $s = dbStock();
        } catch (Throwable $e) {
            return [];
        }
        $esc = $s->real_escape_string($site);
        $r = @$s->query(
            "SELECT serial_number, model, timestamp, security_company, create_name
             FROM stock_old WHERE TRIM(setup_id) = '$esc'
             ORDER BY timestamp ASC, serial_number ASC LIMIT " . (int) $limit
        );
        while ($r && ($x = $r->fetch_assoc())) {
            $guard = trim((string) $x['security_company']);
            $by = trim((string) $x['create_name']);
            $rows[] = [
                'sn' => trim((string) $x['serial_number']),
                'model' => trim((string) $x['model']),
                'note' => $guard !== '' ? 'รปภ. ' . $guard : '',
                'tag' => $by !== '' ? 'บันทึกโดย ' . $by : '',
                'date' => substr(trim((string) $x['timestamp']), 0, 10),
            ];
        }
        return $rows;
    }

    if ($src === 'setup') {
        $u = function_exists('dbSetup') ? dbSetup() : null;
        if (!$u) {
            return [];
        }
        $esc = $u->real_escape_string($site);
        $r = @$u->query(
            "SELECT ps.serial_number, ps.issue_ref, ps.issue_date, ps.issue_type,
                    so.sale_type, so.product, so.po_number
             FROM po_order_part_serials ps JOIN setup_orders so ON so.id = ps.order_id
             WHERE TRIM(IFNULL(NULLIF(TRIM(so.company_name),''), so.customer_name)) = '$esc'
             ORDER BY ps.issue_date ASC, ps.id ASC LIMIT " . (int) $limit
        );
        $seen = [];
        while ($r && ($x = $r->fetch_assoc())) {
            $sn = trim((string) $x['serial_number']);
            if ($sn === '' || isset($seen[strtoupper($sn)])) {
                continue;
            }
            $seen[strtoupper($sn)] = true;
            $ref = trim((string) $x['issue_ref']);
            $po = trim((string) $x['po_number']);
            $rows[] = [
                'sn' => $sn,
                'model' => trim((string) $x['product']),
                'note' => trim(implode(' · ', array_filter([$ref, $po !== '' ? 'PO ' . $po : '']))),
                'tag' => trim((string) $x['sale_type']) !== '' ? trim((string) $x['sale_type'])
                    : (trim((string) $x['issue_type']) === 'claim' ? 'เคลม' : ''),
                'date' => substr(trim((string) $x['issue_date']), 0, 10),
            ];
        }
        return $rows;
    }

    return [];
}

/**
 * เติมข้อมูลจากทะเบียนของเราให้แต่ละ S/N (รุ่นจริง · สถานะ · ลิงก์)
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function site_assets_attach_ours(array $rows): array
{
    $codes = [];
    foreach ($rows as $r) {
        if (trim((string) $r['sn']) !== '') {
            $codes[] = (string) $r['sn'];
        }
    }
    $codes = array_values(array_unique($codes));
    $mine = [];
    foreach (array_chunk($codes, 300) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $res = qr(
            "SELECT a.id, a.asset_code, a.status, p.name pname
             FROM assets a JOIN products p ON p.id = a.product_id
             WHERE a.asset_code IN ($ph)",
            str_repeat('s', count($chunk)),
            $chunk
        );
        while ($x = $res->fetch_assoc()) {
            $mine[strtoupper((string) $x['asset_code'])] = $x;
        }
    }
    foreach ($rows as $i => $r) {
        $hit = $mine[strtoupper((string) $r['sn'])] ?? null;
        $rows[$i]['asset_id'] = $hit ? (int) $hit['id'] : 0;
        $rows[$i]['status'] = $hit ? (string) $hit['status'] : '';
        $rows[$i]['pname'] = $hit ? (string) $hit['pname'] : (string) $r['model'];
    }
    return $rows;
}

/**
 * ไซต์งานที่เครื่องหนึ่งเคยอยู่ — ใช้ทำการ์ดในหน้าโปรไฟล์เครื่อง
 *
 * @return array<int,array{src:string,site:string}>
 */
function site_assets_of_serial(string $sn): array
{
    $sn = trim($sn);
    if ($sn === '') {
        return [];
    }
    $out = [];

    $l = dbLeasing();
    if ($l) {
        $esc = $l->real_escape_string($sn);
        $r = @$l->query("SELECT DISTINCT TRIM(p_sitename) k FROM tbl_rent_product
                         WHERE p_sn = '$esc' AND TRIM(IFNULL(p_sitename,'')) <> ''");
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'lease', 'site' => (string) $x[0]];
        }
    }

    try {
        $s = dbStock();
    } catch (Throwable $e) {
        $s = null;
    }
    if ($s) {
        $esc = $s->real_escape_string($sn);
        $r = @$s->query("SELECT DISTINCT TRIM(setup_id) k FROM stock_old
                         WHERE serial_number COLLATE utf8mb4_general_ci = '$esc'
                           AND TRIM(IFNULL(setup_id,'')) <> ''");
        while ($r && ($x = $r->fetch_row())) {
            $out[] = ['src' => 'old', 'site' => (string) $x[0]];
        }
    }

    $u = function_exists('dbSetup') ? dbSetup() : null;
    if ($u) {
        $esc = $u->real_escape_string($sn);
        $r = @$u->query(
            "SELECT DISTINCT TRIM(IFNULL(NULLIF(TRIM(so.company_name),''), so.customer_name)) k
             FROM po_order_part_serials ps JOIN setup_orders so ON so.id = ps.order_id
             WHERE ps.serial_number = '$esc'"
        );
        while ($r && ($x = $r->fetch_row())) {
            if (trim((string) $x[0]) !== '') {
                $out[] = ['src' => 'setup', 'site' => (string) $x[0]];
            }
        }
    }

    return $out;
}

/**
 * บล็อก "เครื่องอื่นที่ไซต์งานเดียวกัน" — ใส่ไว้ท้ายกรอบการเบิกเช่า/การเบิกใช้งานขาย
 *
 * อยู่ในกรอบเดียวกับข้อมูลสัญญา เพราะเป็นเรื่องเดียวกัน คือเครื่องชุดนี้ไปอยู่ที่ไหน
 * กรอบนั้นแคบและเลื่อนได้อยู่แล้ว ตารางจึงเอาแค่ รหัส · รุ่น · สถานะ
 * ส่วนชื่อลูกค้ากับวันที่ไปดูต่อได้ที่หน้าไซต์งาน
 *
 * @param string $sn      รหัสเครื่อง
 * @param int    $preview จำนวนแถวที่โชว์
 */
function site_assets_inline_html(string $sn, int $preview = 5): string
{
    $sites = site_assets_of_serial($sn);
    if (!$sites) {
        return '';
    }
    $B = BASE_URL;
    $blocks = '';
    foreach ($sites as $st) {
        $all = site_assets_at($st['src'], $st['site'], 200);
        // ตัดตัวเองออก เหลือเฉพาะ "เครื่องอื่น" ที่ไซต์เดียวกัน
        $others = [];
        foreach ($all as $r) {
            if (strtoupper((string) $r['sn']) !== strtoupper($sn)) {
                $others[] = $r;
            }
        }
        if (!$others) {
            continue;
        }
        $shown = site_assets_attach_ours(array_slice($others, 0, $preview));
        $url = $B . '/site_assets.php?src=' . rawurlencode($st['src']) . '&site=' . rawurlencode($st['site']);

        $blocks .= '<div class="asset-site-block">';
        $blocks .= '<div class="asset-site-block-head">'
            . '<span class="asset-site-src">' . h(site_assets_source_label($st['src'])) . '</span> '
            . '<b>' . h($st['site']) . '</b> '
            . '<span class="muted">· อีก ' . number_format(count($others)) . ' เครื่อง</span></div>';
        $blocks .= '<table class="asset-sales-lines"><tbody>';
        foreach ($shown as $r) {
            $blocks .= '<tr><td>' . ($r['asset_id'] > 0
                    ? '<a href="' . $B . '/asset.php?id=' . (int) $r['asset_id'] . '"><b>' . h($r['sn']) . '</b></a>'
                    : '<b>' . h($r['sn']) . '</b>')
                . '</td>';
            $blocks .= '<td>' . h($r['pname'] !== '' ? $r['pname'] : '-') . '</td>';
            $blocks .= '<td>' . ($r['status'] !== ''
                    ? '<span class="badge" style="' . h(status_badge_style($r['status'])) . '">'
                      . h(status_palette_entry($r['status'])['th']) . '</span>'
                    : '<span class="muted">ไม่มีในทะเบียนเรา</span>') . '</td></tr>';
        }
        $blocks .= '</tbody></table>';
        if (count($others) > $preview) {
            $blocks .= '<a class="asset-site-more" href="' . h($url) . '">ดูทั้งหมด '
                . number_format(count($others)) . ' เครื่อง ›</a>';
        } else {
            $blocks .= '<a class="asset-site-more" href="' . h($url) . '">เปิดหน้าไซต์งานนี้ ›</a>';
        }
        $blocks .= '</div>';
    }
    if ($blocks === '') {
        return '';
    }

    return '<div class="asset-site-inline">'
        . '<div class="asset-sales-section-title">เครื่องอื่นที่ไซต์งานเดียวกัน</div>'
        . $blocks . '</div>';
}
