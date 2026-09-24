<?php
/**
 * includes/leasing_sn_fix.php — แก้หมายเลขเครื่อง (S/N) ที่กรอกผิดไว้ในระบบเช่า
 *
 * ฝั่งระบบเช่า (biton_leasing) เก็บ S/N ไว้ 3 ที่ และผูกกันด้วย "ข้อความ S/N" ไม่ใช่ id:
 *   tbl_product.pro_sn      ทะเบียนเครื่องเช่า (UNIQUE)
 *   tbl_rent_product.p_sn   บรรทัดเครื่องในสัญญาเช่า
 *   tbl_product_ma.ma_sn    ประวัติ MA (มี ma_p_id ชี้เครื่องด้วย)
 * แก้ไม่ครบ = สัญญา/ประวัติหลุดจากเครื่อง จึงต้องแก้พร้อมกันทั้งสามที่เสมอ
 *
 * ตารางฝั่งเช่าเป็น MyISAM — ไม่มี transaction จริง ถ้าขั้นหลังพลาดจึงต้องย้อนชื่อกลับเอง
 * ระบบเช่าไม่มีหน้าแก้ S/N ของตัวเอง (มีแต่เปลี่ยนสถานะ) เครื่องมือนี้จึงอยู่ฝั่งเรา
 */

/** @var int ความยาวสูงสุดของ S/N ตามคอลัมน์ฝั่งเช่า (varchar(20)) */
const LEASING_SN_MAX = 20;

/**
 * เครื่องในระบบเช่าที่ S/N ตรงหรือใกล้เคียงคำค้น
 *
 * @param string $q
 * @param int    $limit
 * @return array{ok:bool, error:string, rows:array<int,array<string,mixed>>}
 */
function leasing_sn_search(string $q, int $limit = 20): array
{
    $out = ['ok' => false, 'error' => '', 'rows' => []];
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้' . (function_exists('dbLeasingError') ? ': ' . dbLeasingError() : '');
        return $out;
    }
    $q = trim($q);
    if ($q === '') {
        $out['ok'] = true;
        return $out;
    }
    $like = '%' . $l->real_escape_string($q) . '%';
    $st = $l->prepare('SELECT pro_id, pro_sn, pro_name, pro_status, pro_date, pro_user_add
                       FROM tbl_product WHERE pro_sn LIKE ? ORDER BY pro_sn LIMIT ' . (int) $limit);
    if (!$st) {
        $out['error'] = 'ค้นหาในระบบเช่าไม่ได้: ' . $l->error;
        return $out;
    }
    $st->bind_param('s', $like);
    $st->execute();
    $res = $st->get_result();
    $sns = [];
    while ($r = $res->fetch_assoc()) {
        $r['contracts'] = 0;
        $r['ma'] = 0;
        $out['rows'][] = $r;
        $sns[] = (string) $r['pro_sn'];
    }
    $st->close();
    if (!$sns) {
        $out['ok'] = true;
        return $out;
    }
    // จำนวนสัญญา/ประวัติ MA ของแต่ละเครื่อง — query รวมครั้งเดียว ไม่วนถามทีละแถว
    $in = implode(',', array_map(function ($v) use ($l) { return "'" . $l->real_escape_string($v) . "'"; }, $sns));
    $counts = ['contracts' => [], 'ma' => []];
    $r = $l->query("SELECT p_sn, COUNT(*) n FROM tbl_rent_product WHERE p_sn IN ($in) GROUP BY p_sn");
    while ($r && ($x = $r->fetch_row())) {
        $counts['contracts'][(string) $x[0]] = (int) $x[1];
    }
    $r = $l->query("SELECT ma_sn, COUNT(*) n FROM tbl_product_ma WHERE ma_sn IN ($in) GROUP BY ma_sn");
    while ($r && ($x = $r->fetch_row())) {
        $counts['ma'][(string) $x[0]] = (int) $x[1];
    }
    foreach ($out['rows'] as &$row) {
        $sn = (string) $row['pro_sn'];
        $row['contracts'] = $counts['contracts'][$sn] ?? 0;
        $row['ma'] = $counts['ma'][$sn] ?? 0;
    }
    unset($row);
    $out['ok'] = true;
    return $out;
}

/**
 * รายละเอียดเครื่อง 1 เครื่องในระบบเช่า + สัญญาที่ผูกอยู่
 *
 * @param string $sn
 * @return array{ok:bool, error:string, product:array<string,mixed>|null, contracts:array<int,array<string,mixed>>, ma:int}
 */
function leasing_sn_detail(string $sn): array
{
    $out = ['ok' => false, 'error' => '', 'product' => null, 'contracts' => [], 'ma' => 0];
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้' . (function_exists('dbLeasingError') ? ': ' . dbLeasingError() : '');
        return $out;
    }
    $sn = trim($sn);
    $st = $l->prepare('SELECT pro_id, pro_sn, pro_name, pro_status, pro_date, pro_user_add, pro_remarks FROM tbl_product WHERE pro_sn = ?');
    $st->bind_param('s', $sn);
    $st->execute();
    $out['product'] = $st->get_result()->fetch_assoc() ?: null;
    $st->close();
    if (!$out['product']) {
        $out['error'] = 'ไม่พบ S/N นี้ในระบบเช่า';
        return $out;
    }
    $st = $l->prepare('SELECT p.p_id, p.p_status, p.p_sitename, p.p_cus_id, p.p_user_add, r.r_code, r.r_startdate, r.r_enddate
                       FROM tbl_rent_product p LEFT JOIN tbl_rent r ON r.r_id = p.p_r_id
                       WHERE p.p_sn = ? ORDER BY p.p_id DESC LIMIT 20');
    $st->bind_param('s', $sn);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $out['contracts'][] = $r;
    }
    $st->close();
    $st = $l->prepare('SELECT COUNT(*) FROM tbl_product_ma WHERE ma_sn = ? OR ma_p_id = ?');
    $pid = (int) $out['product']['pro_id'];
    $st->bind_param('si', $sn, $pid);
    $st->execute();
    $out['ma'] = (int) ($st->get_result()->fetch_row()[0] ?? 0);
    $st->close();
    $out['ok'] = true;
    return $out;
}

/**
 * S/N นี้มีอยู่ในทะเบียนเครื่องของเราไหม (รหัสเครื่อง หรือ S/N โรงงาน)
 *
 * @param string $sn
 * @return array<string,mixed>|null
 */
function leasing_sn_our_asset(string $sn): ?array
{
    $r = qr('SELECT a.id, a.asset_code, a.status, p.name pname FROM assets a JOIN products p ON p.id = a.product_id
             WHERE a.asset_code = ? OR a.factory_serial = ? LIMIT 1', 'ss', [$sn, $sn])->fetch_assoc();
    return $r ?: null;
}

/**
 * แก้ S/N ในระบบเช่าให้ถูกต้อง — ทะเบียนเครื่อง + สัญญา + ประวัติ MA พร้อมกัน
 *
 * @param string $oldSn
 * @param string $newSn
 * @param string $actor
 * @return array{ok:bool, error:string, product:int, contracts:int, ma:int}
 */
function leasing_sn_rename(string $oldSn, string $newSn, string $actor): array
{
    $out = ['ok' => false, 'error' => '', 'product' => 0, 'contracts' => 0, 'ma' => 0];
    $l = function_exists('dbLeasing') ? dbLeasing() : null;
    if (!$l) {
        $out['error'] = 'เชื่อมต่อระบบเช่าไม่ได้' . (function_exists('dbLeasingError') ? ': ' . dbLeasingError() : '');
        return $out;
    }
    $oldSn = trim($oldSn);
    $newSn = trim($newSn);
    if ($oldSn === '' || $newSn === '') {
        $out['error'] = 'ต้องใส่ทั้ง S/N เดิมและ S/N ใหม่';
        return $out;
    }
    if ($oldSn === $newSn) {
        $out['error'] = 'S/N ใหม่ซ้ำกับของเดิม';
        return $out;
    }
    if (mb_strlen($newSn) > LEASING_SN_MAX || !preg_match('/^[A-Za-z0-9._\/-]+$/', $newSn)) {
        $out['error'] = 'S/N ใหม่ต้องเป็นตัวอักษร/ตัวเลข ไม่เกิน ' . LEASING_SN_MAX . ' ตัว และไม่มีช่องว่าง';
        return $out;
    }
    $cur = leasing_sn_detail($oldSn);
    if (!$cur['ok']) {
        $out['error'] = $cur['error'];
        return $out;
    }
    $proId = (int) $cur['product']['pro_id'];
    // pro_sn เป็น UNIQUE — ถ้าใหม่ซ้ำจะได้เครื่องสองตัวปนกัน ต้องกันไว้ก่อน
    $dup = leasing_sn_detail($newSn);
    if ($dup['ok']) {
        $out['error'] = 'S/N ใหม่มีอยู่ในระบบเช่าแล้ว (' . (string) $dup['product']['pro_name'] . ' · สถานะ ' . (string) $dup['product']['pro_status'] . ')';
        return $out;
    }
    if (!leasing_sn_our_asset($newSn)) {
        $out['error'] = 'ไม่มี S/N นี้ในทะเบียนเครื่องของเรา — ตรวจตัวสะกดอีกครั้ง';
        return $out;
    }

    $st = $l->prepare('UPDATE tbl_product SET pro_sn = ? WHERE pro_sn = ?');
    $st->bind_param('ss', $newSn, $oldSn);
    if (!$st->execute()) {
        $out['error'] = 'แก้ทะเบียนเครื่องในระบบเช่าไม่สำเร็จ: ' . $st->error;
        $st->close();
        return $out;
    }
    $out['product'] = $st->affected_rows;
    $st->close();

    // ตารางฝั่งเช่าเป็น MyISAM ไม่มี rollback — ขั้นถัดไปพลาดต้องย้อนชื่อกลับเอง
    $undo = function () use ($l, $oldSn, $newSn) {
        $u = $l->prepare('UPDATE tbl_product SET pro_sn = ? WHERE pro_sn = ?');
        $u->bind_param('ss', $oldSn, $newSn);
        $u->execute();
        $u->close();
    };
    $st = $l->prepare('UPDATE tbl_rent_product SET p_sn = ? WHERE p_sn = ?');
    $st->bind_param('ss', $newSn, $oldSn);
    if (!$st->execute()) {
        $out['error'] = 'แก้บรรทัดเครื่องในสัญญาไม่สำเร็จ: ' . $st->error . ' — ย้อนกลับเป็น S/N เดิมแล้ว';
        $st->close();
        $undo();
        return $out;
    }
    $out['contracts'] = $st->affected_rows;
    $st->close();

    $st = $l->prepare('UPDATE tbl_product_ma SET ma_sn = ? WHERE ma_sn = ? OR ma_p_id = ?');
    $st->bind_param('ssi', $newSn, $oldSn, $proId);
    if (!$st->execute()) {
        $out['error'] = 'แก้ประวัติ MA ไม่สำเร็จ: ' . $st->error . ' — แก้ทะเบียนและสัญญาไปแล้ว ต้องแก้ประวัติ MA เอง';
        $st->close();
        return $out;
    }
    $out['ma'] = $st->affected_rows;
    $st->close();

    if (function_exists('activity_log_write')) {
        activity_log_write([
            'system_key'  => 'production',
            'actor_name'  => $actor,
            'action_key'  => 'leasing_sn_fix',
            'summary'     => 'แก้ S/N ในระบบเช่า ' . $oldSn . ' → ' . $newSn,
            'detail'      => json_encode([
                'pro_id' => $proId, 'old' => $oldSn, 'new' => $newSn,
                'rows' => ['tbl_product' => $out['product'], 'tbl_rent_product' => $out['contracts'], 'tbl_product_ma' => $out['ma']],
            ], JSON_UNESCAPED_UNICODE),
            'entity_type' => 'leasing_product',
            'entity_id'   => (string) $proId,
        ]);
    }
    $out['ok'] = true;
    return $out;
}
