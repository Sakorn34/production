<?php
/**
 * pages/vendor-import.php — นำเข้าผู้จำหน่าย/ลิงก์สั่งซื้อจากไฟล์ Excel (.xlsx)
 *
 * คอลัมน์ที่รองรับจาก stockpartDB.xlsx:
 *   ID (รหัส Pxxxxx), Dealer → supplier, Link → purchase_link
 *
 * Flow:
 *   1. อัปโหลด .xlsx หรือใช้ปุ่ม import จาก path เริ่มต้น (localhost)
 *   2. แสดง preview จำนวนแถวที่จะอัปเดต
 *   3. ยืนยันแล้ว UPDATE products
 */

$pageTitle = 'นำเข้าผู้จำหน่าย';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/xlsx_reader.php';
require_once __DIR__ . '/../includes/vendor_import.php';

$defaultXlsx = 'D:/AppServ/www/_ฐานข้อมูลเก่า/stockpartDB.xlsx';
$result = null;
$preview = null;
$error = null;
$importToken = null;

/**
 * เก็บไฟล์ upload ชั่วคราวสำหรับขั้นยืนยัน
 *
 * @param string $tmpPath
 * @return string token
 */
function vendor_import_stage_upload(string $tmpPath): string
{
    $dir = sys_get_temp_dir() . '/parts_vendor_import';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $token = bin2hex(random_bytes(16));
    $dest = $dir . '/' . $token . '.xlsx';
    if (!copy($tmpPath, $dest)) {
        throw new RuntimeException('ไม่สามารถเตรียมไฟล์ชั่วคราวได้');
    }
    $_SESSION['vendor_import_staged'] = [
        'token' => $token,
        'only_empty' => !empty($_POST['only_empty']),
        'expires' => time() + 900,
    ];
    return $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $onlyEmpty = !empty($_POST['only_empty']);
    $confirm = !empty($_POST['confirm']);

    try {
        $tmpPath = null;
        if ($confirm && !empty($_POST['import_token'])) {
            $token = (string) $_POST['import_token'];
            $staged = $_SESSION['vendor_import_staged'] ?? null;
            if (
                !is_array($staged)
                || ($staged['token'] ?? '') !== $token
                || (int) ($staged['expires'] ?? 0) < time()
            ) {
                throw new RuntimeException('เซสชันนำเข้าหมดอายุ — อัปโหลดไฟล์ใหม่');
            }
            if ($token === 'default') {
                $tmpPath = (string) ($staged['default_path'] ?? $defaultXlsx);
                if (!is_readable($tmpPath)) {
                    throw new RuntimeException('ไม่พบไฟล์เริ่มต้น');
                }
            } else {
                $safeToken = preg_replace('/[^a-f0-9]/', '', $token);
                $stagedPath = sys_get_temp_dir() . '/parts_vendor_import/' . $safeToken . '.xlsx';
                if (!is_readable($stagedPath)) {
                    throw new RuntimeException('ไม่พบไฟล์ชั่วคราว — อัปโหลดใหม่');
                }
                $tmpPath = $stagedPath;
            }
            $onlyEmpty = !empty($staged['only_empty']);
        } elseif (!empty($_FILES['xlsx']['tmp_name']) && is_uploaded_file($_FILES['xlsx']['tmp_name'])) {
            $ext = strtolower(pathinfo((string) $_FILES['xlsx']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                throw new RuntimeException('รองรับเฉพาะไฟล์ .xlsx');
            }
            if ((int) ($_FILES['xlsx']['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('ไฟล์ใหญ่เกิน 5 MB');
            }
            $tmpPath = $_FILES['xlsx']['tmp_name'];
        } elseif (!empty($_POST['use_default']) && is_readable($defaultXlsx)) {
            $tmpPath = $defaultXlsx;
        } else {
            throw new RuntimeException('กรุณาเลือกไฟล์ .xlsx หรือใช้ไฟล์เริ่มต้น');
        }

        $rows = xlsx_read_rows($tmpPath, 0);
        $preview = vendor_import_build_updates($rows, $db);

        if ($confirm) {
            $result = vendor_import_apply($preview['updates'], $db, $onlyEmpty);
            unset($_SESSION['vendor_import_staged']);
            if (!empty($_POST['import_token']) && $_POST['import_token'] !== 'default') {
                $stagedPath = sys_get_temp_dir() . '/parts_vendor_import/' . preg_replace('/[^a-f0-9]/', '', (string) $_POST['import_token']) . '.xlsx';
                if (is_file($stagedPath)) {
                    @unlink($stagedPath);
                }
            }
            flash(
                'success',
                'นำเข้าเรียบร้อย — อัปเดต ' . (int) $result['updated'] . ' รายการ'
                . ($result['skipped_existing'] > 0 ? ' · ข้าม ' . (int) $result['skipped_existing'] . ' รายการที่มีค่าอยู่แล้ว' : '')
            );
            redirect(url('/pages/products.php'));
        }

        if ($tmpPath !== $defaultXlsx) {
            $importToken = vendor_import_stage_upload($tmpPath);
        } else {
            $_SESSION['vendor_import_staged'] = [
                'token' => 'default',
                'only_empty' => $onlyEmpty,
                'expires' => time() + 900,
                'default_path' => $defaultXlsx,
            ];
            $importToken = 'default';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <?= ui_heading('stock-in', 'นำเข้าผู้จำหน่าย / ลิงก์สั่งซื้อ', 'h1') ?>
        <p>จากไฟล์ Excel — match ด้วยคอลัมน์ <b>ID</b> (รหัส Pxxxxx) · Dealer → ผู้จำหน่าย · Link → ลิงก์สั่งซื้อ</p>
    </div>
    <div class="parts-page-actions">
        <a href="<?= url('/pages/products.php') ?>" class="btn btn-outline">← กลับรายการอะไหล่</a>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-error"><?= e($error) ?></div>
<?php endif; ?>

<div class="card" style="max-width:720px">
    <h2 class="card-title">อัปโหลดไฟล์</h2>
    <form method="POST" enctype="multipart/form-data" class="form-stack">
        <div class="form-group">
            <label for="xlsx">ไฟล์ .xlsx</label>
            <input type="file" name="xlsx" id="xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
            <p class="form-hint muted">คอลัมน์ที่ใช้: ID, Dealer, Link (ชื่อ sheet แรก)</p>
        </div>
        <label class="checkbox-inline">
            <input type="checkbox" name="only_empty" value="1">
            อัปเดตเฉพาะช่องว่าง (ไม่ทับ supplier/link ที่มีอยู่)
        </label>
        <div class="form-actions" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
            <button type="submit" class="btn btn-outline">ดูตัวอย่างก่อนนำเข้า</button>
            <?php if (is_readable($defaultXlsx)): ?>
            <button type="submit" name="use_default" value="1" class="btn btn-primary">ใช้ไฟล์ stockpartDB.xlsx (เครื่อง dev)</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($preview && !$result): ?>
<div class="card" style="max-width:720px;margin-top:16px">
    <h2 class="card-title">ตัวอย่างก่อนนำเข้า</h2>
    <ul class="plain-list">
        <li>พร้อมอัปเดต: <b><?= number_format(count($preview['updates'])) ?></b> รายการ</li>
        <li>ข้าม (ไม่มี Dealer/Link): <?= number_format((int) $preview['skip_empty']) ?></li>
        <li>ข้าม (รหัสไม่ถูกต้อง): <?= number_format((int) $preview['skip_bad_code']) ?></li>
        <li>ไม่พบในระบบ: <?= number_format(count($preview['missing'])) ?></li>
    </ul>
    <?php if ($preview['updates'] !== []): ?>
    <div class="table-wrap" style="margin-top:12px;max-height:280px;overflow:auto">
        <table>
            <thead>
                <tr><th>รหัส</th><th>ผู้จำหน่าย</th><th>ลิงก์</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($preview['updates'], 0, 20) as $u): ?>
                <tr>
                    <td><?= e((string) $u['code']) ?></td>
                    <td><?= e((string) ($u['supplier'] ?? '-')) ?></td>
                    <td style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= e((string) ($u['purchase_link'] ?? '-')) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($preview['updates']) > 20): ?>
    <p class="muted" style="margin-top:8px">… และอีก <?= number_format(count($preview['updates']) - 20) ?> รายการ</p>
    <?php endif; ?>
    <form method="POST" style="margin-top:16px">
        <input type="hidden" name="confirm" value="1">
        <input type="hidden" name="import_token" value="<?= e((string) $importToken) ?>">
        <?php if (!empty($_POST['only_empty']) || (!empty($_SESSION['vendor_import_staged']['only_empty']))): ?>
        <input type="hidden" name="only_empty" value="1">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">ยืนยันนำเข้า <?= number_format(count($preview['updates'])) ?> รายการ</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card muted" style="max-width:720px;margin-top:16px;font-size:13px">
    <p><b>CLI (สำหรับ dev):</b></p>
    <pre style="white-space:pre-wrap;margin:0">php parts/database/tools/import_vendor_from_xlsx.php --dry-run
php parts/database/tools/import_vendor_from_xlsx.php</pre>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
