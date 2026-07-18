<?php
/**
 * updates_import.php — นำเข้าประวัติอัปเดต FW/HW จาก CSV
 *
 * ใช้ร่วมกับไฟล์ที่สร้างจาก database/tools/parse_production_history.php
 * คอลัมน์หลัก: asset_code, updated_at, update_type, component_name, old_value, new_value, detail, made_by
 *
 * Flow:
 *   1. อัปโหลด CSV (หรือดาวน์โหลดไฟล์สำเร็จรูป update_logs_from_history.csv)
 *   2. ระบบหา asset_id จาก asset_code แล้ว INSERT update_logs
 *   3. ถ้าเป็น firmware และมี new_value จะอัปเดต assets.current_fw_version ด้วย
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();

$CSV_READY = __DIR__ . '/database/import/update_logs_from_history.csv';
$CSV_MISSING = __DIR__ . '/database/import/update_logs_missing_assets.csv';
$LOG_DIR = __DIR__ . '/database/import_log';

// ─ ดาวน์โหลดไฟล์สำเร็จรูป ───────────────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'ready') {
    if (!is_readable($CSV_READY)) {
        http_response_code(404);
        exit('ไม่พบไฟล์ import — รัน parse_production_history.php ก่อน');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="update_logs_from_history.csv"');
    readfile($CSV_READY);
    exit;
}
if (isset($_GET['download']) && $_GET['download'] === 'missing') {
    if (!is_readable($CSV_MISSING)) {
        http_response_code(404);
        exit('ยังไม่มีรายการ — รัน php database/tools/check_import_missing.php ก่อน');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="update_logs_missing_assets.csv"');
    readfile($CSV_MISSING);
    exit;
}

/**
 * นำเข้าแถว CSV เข้า update_logs
 *
 * @param array<int,array<string,string>> $rows แถวข้อมูล (assoc จากหัวคอลัมน์)
 * @param bool $skipDup ข้ามถ้ามีรายการเดิม (asset + วันที่ + detail)
 * @return array{added:int,skip_dup:int,skip_asset:int,skip_bad:int,log:string}
 */
function import_update_rows(array $rows, $skipDup = true)
{
    global $LOG_DIR;
    if (!is_dir($LOG_DIR)) {
        mkdir($LOG_DIR, 0755, true);
    }

    $added = 0;
    $skipDupN = 0;
    $skipAsset = 0;
    $skipBad = 0;
    $missLog = [];
    $badLog = [];

    // cache asset_code → id
    $assetCache = [];

    // wrap ทั้ง import เป็น 1 transaction — เดิม autocommit ทีละแถว (SELECT+INSERT+UPDATE ต่อแถวไม่ batch)
    // ทำให้ import ไฟล์ใหญ่ช้าและถ้า fail กลางทางเหลือข้อมูลนำเข้าครึ่งเดียวโดยไม่มี rollback
    db()->begin_transaction();

    foreach ($rows as $i => $r) {
        $code = strtoupper(trim($r['asset_code'] ?? ''));
        if ($code === '' || !preg_match('/^[A-Z0-9._\-\/]+$/', $code)) {
            $skipBad++;
            $badLog[] = ['line' => $i + 2, 'reason' => 'asset_code ไม่ถูกต้อง', 'row' => $r];
            continue;
        }

        if (!isset($assetCache[$code])) {
            $row = qr("SELECT id FROM assets WHERE asset_code=?", 's', [$code])->fetch_assoc();
            $assetCache[$code] = $row ? (int)$row['id'] : 0;
        }
        $aid = $assetCache[$code];
        if ($aid <= 0) {
            $skipAsset++;
            $missLog[] = ['asset_code' => $code, 'detail' => $r['detail'] ?? '', 'updated_at' => $r['updated_at'] ?? ''];
            continue;
        }

        $type = strtolower(trim($r['update_type'] ?? 'other'));
        if (!in_array($type, ['firmware', 'hardware', 'other'], true)) {
            $type = 'other';
        }

        $updatedAt = trim($r['updated_at'] ?? '');
        if ($updatedAt === '') {
            $updatedAt = date('Y-m-d H:i:s');
        } elseif (preg_match('/^(\d{2}):(\d{2}):(\d{2})\s*\/\s*(\d{4}-\d{2}-\d{2})/', $updatedAt, $m)) {
            $updatedAt = sprintf('%s-%s-%s %s:%s:%s', $m[4], $m[5], $m[6], $m[1], $m[2], $m[3]);
        }

        $comp = trim($r['component_name'] ?? '') ?: null;
        $old = trim($r['old_value'] ?? '') ?: null;
        $new = trim($r['new_value'] ?? '') ?: null;
        $detail = trim($r['detail'] ?? '') ?: null;
        $madeBy = trim($r['made_by'] ?? '') ?: 'Admin';

        if ($skipDup && $detail !== null) {
            $dup = qr("SELECT id FROM update_logs WHERE asset_id=? AND updated_at=? AND detail=? LIMIT 1",
                'iss', [$aid, $updatedAt, $detail])->fetch_assoc();
            if ($dup) {
                $skipDupN++;
                continue;
            }
        }

        q("INSERT INTO update_logs (asset_id,updated_at,update_type,component_name,old_value,new_value,detail,image1,image2,made_by)
           VALUES (?,?,?,?,?,?,?,NULL,NULL,?)",
          'isssssss', [$aid, $updatedAt, $type, $comp, $old, $new, $detail, $madeBy]);

        if ($type === 'firmware' && $new !== null && $new !== '') {
            q("UPDATE assets SET current_fw_version=? WHERE id=?", 'si', [$new, $aid]);
        }
        if ($type === 'hardware' && $comp !== null && $comp !== '' && $new !== null && $new !== '') {
            q("INSERT INTO asset_components (asset_id,component_name,component_value) VALUES (?,?,?)
               ON DUPLICATE KEY UPDATE component_value=VALUES(component_value)", 'iss', [$aid, $comp, $new]);
        }
        $added++;
    }

    db()->commit();

    $logFile = '';
    if ($missLog || $badLog) {
        $logFile = $LOG_DIR . '/import_updates_' . date('Ymd_His') . '.csv';
        $lf = fopen($logFile, 'w');
        fwrite($lf, "\xEF\xBB\xBF");
        fputcsv($lf, ['kind', 'asset_code', 'updated_at', 'detail', 'reason']);
        foreach ($missLog as $m) {
            fputcsv($lf, ['missing_asset', $m['asset_code'], $m['updated_at'], $m['detail'], 'ไม่พบเครื่องในระบบ']);
        }
        foreach ($badLog as $b) {
            fputcsv($lf, ['bad_row', $b['row']['asset_code'] ?? '', '', '', $b['reason']]);
        }
        fclose($lf);
    }

    return [
        'added' => $added,
        'skip_dup' => $skipDupN,
        'skip_asset' => $skipAsset,
        'skip_bad' => $skipBad,
        'log' => $logFile,
    ];
}

// ─ POST: อัปโหลด CSV ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $skipDup = !empty($_POST['skip_dup']);

    if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash_set('อัปโหลดไฟล์ไม่สำเร็จ — เลือกไฟล์ .csv แล้วลองใหม่', 'err');
        header('Location: ' . BASE_URL . '/updates_import.php');
        exit;
    }

    $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash_set('รองรับเฉพาะไฟล์ .csv', 'err');
        header('Location: ' . BASE_URL . '/updates_import.php');
        exit;
    }

    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    $head = fgetcsv($fh);
    if (!$head) {
        flash_set('ไฟล์ว่างหรืออ่านไม่ได้', 'err');
        header('Location: ' . BASE_URL . '/updates_import.php');
        exit;
    }
    if (isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
    }
    $col = [];
    foreach ($head as $i => $c) {
        $col[strtolower(trim($c))] = $i;
    }
    if (!isset($col['asset_code'])) {
        flash_set('ไฟล์ต้องมีคอลัมน์ asset_code', 'err');
        header('Location: ' . BASE_URL . '/updates_import.php');
        exit;
    }

    $rows = [];
    while (($line = fgetcsv($fh)) !== false) {
        if (!array_filter($line, function ($v) { return trim((string)$v) !== ''; })) {
            continue;
        }
        $row = [];
        foreach ($col as $name => $idx) {
            $row[$name] = isset($line[$idx]) ? trim($line[$idx]) : '';
        }
        $rows[] = $row;
    }
    fclose($fh);

    $res = import_update_rows($rows, $skipDup);
    $msg = 'Import เสร็จ: เพิ่ม ' . $res['added'] . ' รายการ';
    if ($res['skip_dup']) {
        $msg .= ' · ข้ามซ้ำ ' . $res['skip_dup'];
    }
    if ($res['skip_asset']) {
        $msg .= ' · ไม่พบเครื่อง ' . $res['skip_asset'] . ' (ยังไม่ลงทะเบียนในระบบผลิต)';
    }
    if ($res['skip_bad']) {
        $msg .= ' · แถวไม่ถูกต้อง ' . $res['skip_bad'];
    }
    if ($res['log']) {
        $msg .= ' (ดู log ใน database/import_log/)';
    }
    // นำเข้าซ้ำครบแล้ว = สำเร็จ แม้เพิ่ม 0 รายการ (ข้ามซ้ำทั้งหมด)
    if ($res['added'] > 0) {
        $type = 'ok';
    } elseif ($res['skip_dup'] > 0 && $res['skip_bad'] === 0) {
        $type = 'ok';
    } else {
        $type = 'err';
    }
    flash_set($msg, $type);
    header('Location: ' . BASE_URL . '/updates_import.php');
    exit;
}

// ─ หน้าเว็บ ─────────────────────────────────────────────────────────────────
$readyInfo = null;
$missingInfo = null;
if (is_readable($CSV_READY)) {
    $readyInfo = [
        'size' => filesize($CSV_READY),
        'mtime' => filemtime($CSV_READY),
        'lines' => max(0, count(file($CSV_READY)) - 1),
    ];
}
if (is_readable($CSV_MISSING)) {
    $missingInfo = [
        'lines' => max(0, count(file($CSV_MISSING)) - 1),
        'mtime' => filemtime($CSV_MISSING),
    ];
}

page_header('นำเข้าประวัติอัปเดต FW/HW');
?>
<p class="muted" style="margin-bottom:14px">
  นำเข้ารายการอัปเดต FW/HW จากไฟล์ CSV — ใช้กับข้อมูลที่แปลงจากประวัติการผลิตระบบเก่า
  · ผู้บันทึกในไฟล์จะเป็น <b>Admin</b> · วันที่ตามคอลัมน์ <code>updated_at</code>
</p>

<?php if ($readyInfo) { ?>
<div class="card" style="margin-bottom:16px; padding:14px">
  <b>ไฟล์สำเร็จรูปจากประวัติการผลิต</b>
  <div class="muted" style="margin:6px 0">
    <?= number_format($readyInfo['lines']) ?> รายการ ·
    อัปเดต <?= date('d/m/Y H:i', $readyInfo['mtime']) ?> ·
    <?= number_format($readyInfo['size'] / 1024, 1) ?> KB
  </div>
  <a class="btn btn-sm" href="<?= BASE_URL ?>/updates_import.php?download=ready">⬇️ ดาวน์โหลด update_logs_from_history.csv</a>
</div>
<?php } else { ?>
<p class="muted">ยังไม่มีไฟล์สำเร็จรูป — รัน <code>php database/tools/parse_production_history.php --url</code> ก่อน</p>
<?php } ?>

<?php if ($missingInfo) { ?>
<div class="card" style="margin-bottom:16px; padding:14px; border-color:rgba(192,57,43,.35)">
  <b>รายการ S/N ที่ยังไม่มีในทะเบียนเครื่องผลิต</b>
  <div class="muted" style="margin:6px 0">
    <?= number_format($missingInfo['lines']) ?> รหัส · อัปเดต <?= date('d/m/Y H:i', $missingInfo['mtime']) ?>
    · นำเข้า FW/HW ได้หลังลงทะเบียนเครื่องในระบบก่อน
  </div>
  <a class="btn btn-sm btn-line" href="<?= BASE_URL ?>/updates_import.php?download=missing">⬇️ ดาวน์โหลด update_logs_missing_assets.csv</a>
</div>
<?php } ?>

<details style="margin-bottom:16px">
  <summary style="cursor:pointer; font-weight:600; color:var(--primary)">📥 Import CSV เข้าระบบ</summary>
  <form method="post" enctype="multipart/form-data" style="margin-top:12px" class="formgrid">
    <?= csrf_field() ?>
    <label>ไฟล์ CSV</label>
    <input type="file" name="csv" accept=".csv,text/csv" required>
    <label></label>
    <label style="display:flex; align-items:center; gap:8px">
      <input type="checkbox" name="skip_dup" value="1" checked>
      ข้ามรายการซ้ำ (เครื่อง + วันที่ + หมายเหตุเดิม)
    </label>
    <div class="full"><button type="submit">นำเข้าข้อมูล</button></div>
  </form>
</details>

<div class="card" style="padding:14px">
  <b>คอลัมน์ที่รองรับ</b>
  <table class="list" style="margin-top:8px">
    <tr><th>คอลัมน์</th><th>จำเป็น</th><th>คำอธิบาย</th></tr>
    <tr><td><code>asset_code</code></td><td>ใช่</td><td>รหัสเครื่อง (S/N)</td></tr>
    <tr><td><code>updated_at</code></td><td>แนะนำ</td><td>วันที่อัปเดต Y-m-d H:i:s</td></tr>
    <tr><td><code>update_type</code></td><td>แนะนำ</td><td>firmware / hardware / other</td></tr>
    <tr><td><code>component_name</code></td><td>ไม่</td><td>ชื่อชิ้นส่วน (กรณี HW)</td></tr>
    <tr><td><code>old_value</code> / <code>new_value</code></td><td>ไม่</td><td>ค่าเดิม / ค่าใหม่</td></tr>
    <tr><td><code>detail</code></td><td>แนะนำ</td><td>หมายเหตุจากระบบเก่า</td></tr>
    <tr><td><code>made_by</code></td><td>ไม่</td><td>ผู้บันทึก (ค่าเริ่มต้น Admin)</td></tr>
    <tr><td><code>model</code></td><td>ไม่</td><td>ชื่อรุ่นจากระบบ (map จาก S/N ตอนสร้างไฟล์)</td></tr>
  </table>
</div>

<p style="margin-top:14px">
  <a class="btn btn-line btn-sm" href="<?= BASE_URL ?>/updates.php">← กลับหน้าอัปเดต FW/HW</a>
</p>
<?php
page_footer();
