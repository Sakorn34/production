<?php
/**
 * includes/production_dedupe.php — รวมบันทึกผลิตที่ซ้ำกัน
 *
 * ต้นเหตุ: share_fill_asset_made_by_from_stock() เดิม INSERT บันทึกผลิตแถวใหม่ที่มีแค่ชื่อผู้ผลิต
 * แทนที่จะเติมชื่อลงแถวเดิม หน้าเครื่องจึงมี "บันทึกผลิต / QC" สองอันวันเดียวกัน
 * อันหนึ่งมี checklist (ไม่มีชื่อ) อีกอันมีแค่ชื่อ (ไม่มี checklist)
 *
 * "แถวว่าง" = ไม่มีข้อมูลอะไรนอกจากชื่อผู้ผลิต และมีบันทึกผลิตอีกแถวของเครื่องเดียวกันวันเดียวกัน
 * รวม = ย้ายชื่อผู้ผลิตไปแถวที่มีข้อมูล (ถ้าแถวนั้นยังไม่มีชื่อ) แล้วลบแถวว่าง
 * ไม่แตะแถวที่มีข้อมูลอื่น และไม่แตะเครื่องที่มีบันทึกผลิตแถวเดียว
 */

/** @var string เงื่อนไข "แถวว่าง" (alias p) */
const PRODUCTION_DEDUPE_EMPTY_SQL = "COALESCE(p.checklist,'') IN ('','[]','{}') AND COALESCE(p.fw_version,'')='' AND COALESCE(p.problems_found,'')=''
    AND COALESCE(p.fix,'')='' AND COALESCE(p.lot_label,'')='' AND COALESCE(p.extra_json,'') IN ('','[]','{}') AND COALESCE(p.assembly_by,'')=''";

/**
 * แถวว่างที่ซ้ำ พร้อมแถวคู่ที่จะรับชื่อ
 *
 * @param int $limit 0 = ทั้งหมด
 * @return array<int,array<string,mixed>> id, asset_id, asset_code, recorded_at, made_by, keep_id, keep_made_by
 */
function production_dedupe_rows($limit = 0)
{
    $rows = [];
    $res = qr(
        "SELECT p.id, p.asset_id, a.asset_code, p.recorded_at, p.made_by,
                (SELECT o.id FROM production_records o
                  WHERE o.asset_id = p.asset_id AND o.id <> p.id AND DATE(o.recorded_at) = DATE(p.recorded_at)
                  ORDER BY (COALESCE(o.checklist,'') NOT IN ('','[]','{}')) DESC, o.id ASC LIMIT 1) AS keep_id
         FROM production_records p JOIN assets a ON a.id = p.asset_id
         WHERE " . PRODUCTION_DEDUPE_EMPTY_SQL . "
           AND EXISTS (SELECT 1 FROM production_records o WHERE o.asset_id = p.asset_id AND o.id <> p.id AND DATE(o.recorded_at) = DATE(p.recorded_at))
         ORDER BY p.asset_id, p.id" . ($limit > 0 ? ' LIMIT ' . (int) $limit : '')
    );
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    if ($rows) {
        $keep = array_values(array_unique(array_map('intval', array_column($rows, 'keep_id'))));
        $names = [];
        foreach (array_chunk($keep, 1000) as $chunk) {
            $r2 = qr('SELECT id, made_by FROM production_records WHERE id IN (' . implode(',', $chunk) . ')');
            while ($x = $r2->fetch_row()) {
                $names[(int) $x[0]] = (string) $x[1];
            }
        }
        foreach ($rows as &$r) {
            $r['keep_made_by'] = $names[(int) $r['keep_id']] ?? '';
        }
        unset($r);
    }
    return $rows;
}

/**
 * รวมแถวซ้ำทั้งหมด — ทำเป็นชุดด้วยตารางชั่วคราว (ทีละแถวใช้ ~30 วินาทีต่อ 7,700 แถว เสี่ยงหมดเวลาบน server)
 *
 * แถวคู่เป็นแถวว่างด้วยกัน (ทั้งคู่ไม่มีข้อมูล) — เก็บแถวคู่ไว้ ลบเฉพาะแถวนี้ ไม่ลบหมดทั้งคู่
 *
 * @return array{merged:int, named:int}
 */
function production_dedupe_apply()
{
    @set_time_limit(300);
    $plan = [];
    $deleted = [];
    foreach (production_dedupe_rows() as $r) {
        $id = (int) $r["id"];
        $keep = (int) $r["keep_id"];
        if ($keep <= 0 || isset($deleted[$keep]) || isset($deleted[$id])) {
            continue;
        }
        $deleted[$id] = true;
        $plan[] = [$id, $keep, (string) $r["made_by"]];
    }
    if (!$plan) {
        return ["merged" => 0, "named" => 0];
    }
    db()->query("DROP TEMPORARY TABLE IF EXISTS tmp_production_dedupe");
    db()->query("CREATE TEMPORARY TABLE tmp_production_dedupe (id BIGINT UNSIGNED PRIMARY KEY, keep_id BIGINT UNSIGNED NOT NULL, made_by VARCHAR(255) NULL, KEY (keep_id))");
    foreach (array_chunk($plan, 500) as $chunk) {
        $ph = implode(",", array_fill(0, count($chunk), "(?,?,?)"));
        $params = [];
        foreach ($chunk as $c) { $params[] = $c[0]; $params[] = $c[1]; $params[] = $c[2]; }
        q("INSERT INTO tmp_production_dedupe (id, keep_id, made_by) VALUES $ph", str_repeat("iis", count($chunk)), $params);
    }
    db()->begin_transaction();
    try {
        $u = q("UPDATE production_records k JOIN tmp_production_dedupe t ON t.keep_id = k.id
                SET k.made_by = t.made_by
                WHERE COALESCE(k.made_by, \x27\x27) = \x27\x27 AND COALESCE(t.made_by, \x27\x27) <> \x27\x27");
        $named = $u->affected_rows;
        $d = q("DELETE p FROM production_records p JOIN tmp_production_dedupe t ON t.id = p.id");
        $merged = $d->affected_rows;
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollback();
        error_log("[production_dedupe] " . $e->getMessage());
        return ["merged" => 0, "named" => 0];
    }
    db()->query("DROP TEMPORARY TABLE IF EXISTS tmp_production_dedupe");
    return ["merged" => (int) $merged, "named" => (int) $named];
}
