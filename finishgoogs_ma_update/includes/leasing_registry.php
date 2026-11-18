<?php
/**
 * includes/leasing_registry.php — อ่านทะเบียนเครื่องและการเบิกเช่าล่าสุดจากระบบเช่า
 *
 * ใช้ใน leasing_registry.php (หน้าหลังบ้านแบบ B: การ์ด KPI + แท็บ)
 * แหล่งข้อมูล: biton_leasing.tbl_product / tbl_rent / tbl_rent_product / tbl_customer
 * อ่านอย่างเดียวทั้งไฟล์ ไม่เขียนกลับไปที่ระบบเช่า
 *
 * ทำไมแยกแท็บ: ทะเบียนมีเกือบ 6,000 เครื่อง ส่วนการเบิกเช่าล่าสุดไล่จาก p_id
 * (tbl_rent_product ไม่มี index บน p_sn — ห้ามสแกน S/N ทั้งตาราง)
 *
 * ตัวอย่าง: leasing_registry_counts() คืนจำนวนต่อ pro_status สำหรับการ์ดสรุป
 */

require_once __DIR__ . '/rent_ma_bridge.php';

// ────────────────────────────────────────────────────────────────────────────────
// constants
// ────────────────────────────────────────────────────────────────────────────────

/** @var int จำนวนแถวต่อหน้าทั้งสองแท็บ */
const LEASING_REGISTRY_PER = 50;

/** @var int ความยาวสูงสุดของคำค้น S/N ตามคอลัมน์ฝั่งเช่า */
const LEASING_REGISTRY_SN_MAX = 20;

// ────────────────────────────────────────────────────────────────────────────────
// helpers
// ────────────────────────────────────────────────────────────────────────────────

/**
 * ป้ายสถานะทะเบียนเครื่อง (pro_status อย่างเดียว)
 *
 * @param string $pro ค่า tbl_product.pro_status
 * @return string
 */
function leasing_registry_status_label(string $pro): string
{
    $map = leasing_registry_status_map();
    $pro = trim($pro);
    if ($pro === '') {
        return '—';
    }
    return $map[$pro] ?? $pro;
}

/**
 * แผนที่สถานะทะเบียนที่รู้จัก — ลำดับนี้ใช้ใน dropdown ด้วย
 *
 * @return array<string,string> pro_status => ป้ายไทย
 */
function leasing_registry_status_map(): array
{
    return [
        'rent'              => 'กำลังเช่า',
        'finished goods'    => 'คลังพร้อมเช่า',
        'MA'                => 'รอซ่อม MA',
        'Asset Retirement'  => 'เสื่อมสภาพ',
        'claim'             => 'เคลม',
        'Lost'              => 'สูญหาย',
        'Awaiting Return'   => 'รอส่งคืน',
        'sale'              => 'ขายขาด',
        'received'          => 'รับคืนแล้ว',
    ];
}

/**
 * รัน SELECT ฝั่งเช่าแล้วเก็บเป็นแถว — fail-soft ไม่ throw
 *
 * @param string               $sql
 * @param string               $types
 * @param array<int,mixed>     $params
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>}
 */
function leasing_registry_fetch(string $sql, string $types = '', array $params = []): array
{
    $q = rent_q_try($sql, $types, $params);
    if (empty($q['ok'])) {
        return ['ok' => false, 'error' => (string) ($q['error'] ?? 'query ระบบเช่าไม่สำเร็จ'), 'rows' => []];
    }
    $rows = [];
    if (!empty($q['result'])) {
        while ($r = $q['result']->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/**
 * ตัดคำค้น S/N ให้ปลอดภัยก่อนใส่ LIKE
 *
 * @param string $q
 * @return string
 */
function leasing_registry_sanitize_sn(string $q): string
{
    $q = trim($q);
    if ($q === '') {
        return '';
    }
    if (mb_strlen($q) > LEASING_REGISTRY_SN_MAX) {
        $q = mb_substr($q, 0, LEASING_REGISTRY_SN_MAX);
    }
    return $q;
}

/**
 * ตรวจว่าค่า status ที่ส่งมาเป็นค่าที่อนุญาต (whitelist จากแผนที่)
 *
 * @param string $status
 * @return string ค่าว่างถ้าไม่ผ่าน
 */
function leasing_registry_sanitize_status(string $status): string
{
    $status = trim($status);
    $map = leasing_registry_status_map();
    return isset($map[$status]) ? $status : '';
}

/**
 * เลขที่สัญญาที่โชว์ในตารางเบิกเช่า — รหัสก่อน แล้วตามด้วย PO ถ้าคนละค่า
 *
 * @param string $code tbl_rent.r_code
 * @param string $po   tbl_rent.r_po
 * @return string
 */
function leasing_registry_contract_label(string $code, string $po): string
{
    $code = trim($code);
    $po = trim($po);
    if ($code !== '' && $po !== '' && strcasecmp($code, $po) !== 0) {
        return $code . ' / ' . $po;
    }
    return $code !== '' ? $code : ($po !== '' ? $po : '—');
}

// ────────────────────────────────────────────────────────────────────────────────
// queries
// ────────────────────────────────────────────────────────────────────────────────

/**
 * จำนวนเครื่องในทะเบียนเช่าแยกตาม pro_status + ยอดรวม
 *
 * @return array{ok:bool, error:string, total:int, by:array<string,int>}
 */
function leasing_registry_counts(): array
{
    $out = ['ok' => false, 'error' => '', 'total' => 0, 'by' => []];
    if (!dbLeasing()) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้ — ' . dbLeasingError();
        return $out;
    }
    $q = leasing_registry_fetch('SELECT pro_status, COUNT(*) AS n FROM tbl_product GROUP BY pro_status');
    if (!$q['ok']) {
        $out['error'] = $q['error'];
        return $out;
    }
    $by = [];
    $total = 0;
    foreach ($q['rows'] as $r) {
        $k = trim((string) ($r['pro_status'] ?? ''));
        $n = (int) ($r['n'] ?? 0);
        $by[$k] = $n;
        $total += $n;
    }
    $out['ok'] = true;
    $out['total'] = $total;
    $out['by'] = $by;
    return $out;
}

/**
 * รายชื่อรุ่นในทะเบียนเช่า สำหรับ dropdown
 *
 * @return array{ok:bool, error:string, names:array<int,string>}
 */
function leasing_registry_models(): array
{
    $out = ['ok' => false, 'error' => '', 'names' => []];
    $q = leasing_registry_fetch(
        'SELECT DISTINCT pro_name FROM tbl_product
         WHERE TRIM(IFNULL(pro_name, "")) <> ""
         ORDER BY pro_name'
    );
    if (!$q['ok']) {
        $out['error'] = $q['error'];
        return $out;
    }
    $names = [];
    foreach ($q['rows'] as $r) {
        $n = trim((string) ($r['pro_name'] ?? ''));
        if ($n !== '') {
            $names[] = $n;
        }
    }
    $out['ok'] = true;
    $out['names'] = $names;
    return $out;
}

/**
 * หน้าทะเบียนเครื่อง — กรอง S/N / รุ่น / สถานะ แล้วแบ่งหน้า
 *
 * @param string $sn     คำค้นรหัสเครื่อง (LIKE)
 * @param string $model  pro_name ตรงตัว ว่าง = ไม่กรอง
 * @param string $status pro_status ตรงตัว ว่าง = ไม่กรอง
 * @param int    $page   หน้าเริ่มที่ 1
 * @param int    $per    จำนวนต่อหน้า
 * @return array{ok:bool, error:string, total:int, page:int, pages:int, rows:array<int,array<string,mixed>>}
 */
function leasing_registry_list(string $sn, string $model, string $status, int $page, int $per): array
{
    $out = ['ok' => false, 'error' => '', 'total' => 0, 'page' => 1, 'pages' => 1, 'rows' => []];
    if (!dbLeasing()) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้ — ' . dbLeasingError();
        return $out;
    }

    $sn = leasing_registry_sanitize_sn($sn);
    $model = trim($model);
    if (mb_strlen($model) > 120) {
        $model = mb_substr($model, 0, 120);
    }
    $status = leasing_registry_sanitize_status($status);
    $per = max(1, min(100, $per));
    $page = max(1, $page);

    $where = ['1=1'];
    $types = '';
    $params = [];
    if ($sn !== '') {
        $where[] = 'pro_sn LIKE ?';
        $types .= 's';
        $params[] = '%' . $sn . '%';
    }
    if ($model !== '') {
        $where[] = 'pro_name = ?';
        $types .= 's';
        $params[] = $model;
    }
    if ($status !== '') {
        $where[] = 'pro_status = ?';
        $types .= 's';
        $params[] = $status;
    }
    $wsql = implode(' AND ', $where);

    $cq = leasing_registry_fetch('SELECT COUNT(*) AS n FROM tbl_product WHERE ' . $wsql, $types, $params);
    if (!$cq['ok']) {
        $out['error'] = $cq['error'];
        return $out;
    }
    $total = isset($cq['rows'][0]) ? (int) ($cq['rows'][0]['n'] ?? 0) : 0;
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per;

    $sql = 'SELECT pro_id, pro_sn, pro_name, pro_status, pro_date, pro_user_add
            FROM tbl_product WHERE ' . $wsql . '
            ORDER BY pro_date DESC, pro_id DESC
            LIMIT ' . (int) $per . ' OFFSET ' . (int) $offset;
    $lq = leasing_registry_fetch($sql, $types, $params);
    if (!$lq['ok']) {
        $out['error'] = $lq['error'];
        return $out;
    }

    $out['ok'] = true;
    $out['total'] = $total;
    $out['page'] = $page;
    $out['pages'] = $pages;
    $out['rows'] = $lq['rows'];
    return $out;
}

/**
 * บรรทัดเบิกเช่าล่าสุด — เรียง p_id DESC (PK) ไม่สแกน p_sn
 *
 * @param int $page
 * @param int $per
 * @return array{ok:bool, error:string, total:int, page:int, pages:int, rows:array<int,array<string,mixed>>}
 */
function leasing_registry_rentals(int $page, int $per): array
{
    $out = ['ok' => false, 'error' => '', 'total' => 0, 'page' => 1, 'pages' => 1, 'rows' => []];
    if (!dbLeasing()) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้ — ' . dbLeasingError();
        return $out;
    }

    $per = max(1, min(100, $per));
    $page = max(1, $page);

    $cq = leasing_registry_fetch('SELECT COUNT(*) AS n FROM tbl_rent_product');
    if (!$cq['ok']) {
        $out['error'] = $cq['error'];
        return $out;
    }
    $total = isset($cq['rows'][0]) ? (int) ($cq['rows'][0]['n'] ?? 0) : 0;
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per;

    $sql = 'SELECT rp.p_id, rp.p_sn, rp.p_product, rp.p_status, rp.p_sitename, rp.p_user_add,
                   r.r_id, r.r_code, r.r_po, r.r_startdate, r.r_enddate, c.cus_name
            FROM tbl_rent_product rp
            LEFT JOIN tbl_rent r ON r.r_id = rp.p_r_id
            LEFT JOIN tbl_customer c ON c.cus_id = rp.p_cus_id
            ORDER BY rp.p_id DESC
            LIMIT ' . (int) $per . ' OFFSET ' . (int) $offset;
    $lq = leasing_registry_fetch($sql);
    if (!$lq['ok']) {
        $out['error'] = $lq['error'];
        return $out;
    }

    $out['ok'] = true;
    $out['total'] = $total;
    $out['page'] = $page;
    $out['pages'] = $pages;
    $out['rows'] = $lq['rows'];
    return $out;
}

/**
 * จับคู่ S/N กับเครื่องในทะเบียนเรา (asset_code / factory_serial) เป็นชุด
 *
 * @param array<int,string> $sns
 * @return array<string,array{id:int, code:string}> key = S/N ที่ normalize แล้ว
 */
function leasing_registry_local_by_sn(array $sns): array
{
    $map = [];
    $seen = [];
    foreach ($sns as $sn) {
        $n = rent_normalize_sn($sn);
        if ($n !== '' && !isset($seen[$n])) {
            $seen[$n] = true;
        }
    }
    $list = array_keys($seen);
    if (!$list) {
        return [];
    }
    foreach (array_chunk($list, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk) * 2);
        $params = array_merge($chunk, $chunk);
        try {
            $rs = qr(
                "SELECT a.id, a.asset_code, a.factory_serial
                 FROM assets a
                 WHERE a.asset_code IN ($ph) OR a.factory_serial IN ($ph)",
                $types,
                $params
            );
            while ($r = $rs->fetch_assoc()) {
                $row = ['id' => (int) $r['id'], 'code' => (string) $r['asset_code']];
                $code = rent_normalize_sn($r['asset_code'] ?? '');
                $fs = rent_normalize_sn($r['factory_serial'] ?? '');
                if ($code !== '') {
                    $map[$code] = $row;
                }
                if ($fs !== '') {
                    $map[$fs] = $row;
                }
            }
        } catch (Throwable $e) {
            error_log('[leasing_registry] ' . $e->getMessage());
        }
    }
    return $map;
}
