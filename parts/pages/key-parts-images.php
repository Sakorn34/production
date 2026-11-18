<?php
/**
 * pages/key-parts-images.php — จัดการรูปอะไหล่สั่งต่างประเทศ
 *
 * อัปโหลด/ลบรูปการ์ดของ 3 อะไหล่คีย์ เก็บที่ finishgoogs uploads/kpo/{key}.{ext}
 * ไม่ทับตอน deploy · หน้าสรุปอ่านรูปชุดนี้ก่อน inventory
 *
 * Flow: bootstrap → ตรวจ PIN หลังบ้าน → POST อัปโหลด/ลบ (CSRF) → แสดงการ์ด 3 รายการ
 * เข้าได้จาก settings.php เท่านั้น (ไม่มีในเมนู parts)
 */

$pageTitle = 'รูปอะไหล่ต่างประเทศ';
require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/key_parts_order.php';
key_parts_order_require_settings_unlock();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals((string) $_SESSION['csrf'], $sent)) {
        flash('error', 'โทเคนไม่ถูกต้อง กรุณาลองใหม่');
        redirect(url('/pages/key-parts-images.php'));
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    $key = trim((string) ($_POST['key'] ?? ''));
    if (!key_parts_order_is_catalog_key($key)) {
        flash('error', 'ไม่พบรายการอะไหล่');
        redirect(url('/pages/key-parts-images.php'));
    }

    $label = $key;
    foreach (key_parts_order_catalog_def() as $def) {
        if ((string) $def['key'] === $key) {
            $label = (string) $def['label'];
            break;
        }
    }

    if ($action === 'upload') {
        $err = key_parts_order_save_uploaded_image($key, 'image');
        if ($err !== '') {
            flash('error', $err);
        } else {
            flash('success', 'บันทึกรูป — ' . $label);
        }
    } elseif ($action === 'delete') {
        $n = key_parts_order_delete_uploaded_image($key);
        if ($n > 0) {
            flash('success', 'ลบรูปแล้ว — ' . $label);
        } else {
            flash('error', 'ยังไม่มีรูปให้ลบ');
        }
    } else {
        flash('error', 'คำสั่งไม่ถูกต้อง');
    }
    redirect(url('/pages/key-parts-images.php'));
}

$csrf = (string) $_SESSION['csrf'];
$items = key_parts_order_resolve_items();

require_once __DIR__ . '/../includes/header.php';
$back = '<a class="btn btn-outline btn-with-icon" href="' . e(ui_settings_admin_url()) . '">'
    . ui_icon_html('settings', 16, 'btn-svg') . ' กลับหลังบ้าน</a>';
parts_page_header(
    'camera',
    'รูปอะไหล่ต่างประเทศ',
    'อัปโหลดหรือลบรูปการ์ดของ 3 อะไหล่สั่งต่างประเทศ · JPG / PNG / GIF / WEBP ไม่เกิน 5MB',
    $back
);
?>

<div class="pc-page kpo-page">
    <p class="muted kpo-meta-line">รูปที่อัปที่นี่แสดงบนหน้าสรุปทันที และเก็บในโฟลเดอร์ uploads ของระบบหลัก จึงไม่ถูกทับตอนอัปโหลด patch</p>
    <div class="kpo-cards kpo-image-cards">
        <?php foreach ($items as $it):
            $k = (string) $it['key'];
            $hasUpload = key_parts_order_uploaded_image_path($k) !== null;
            $imgUrl = (string) ($it['image_url'] ?? '');
            $fromInv = !$hasUpload && $imgUrl !== '';
            ?>
        <article class="kpo-card kpo-image-card">
            <div class="kpo-card-head">
                <?php if ($imgUrl !== ''): ?>
                <span class="pc-thumb kpo-thumb kpo-thumb-lg"><img src="<?= e($imgUrl) ?>" alt="<?= e((string) $it['label']) ?>"></span>
                <?php else: ?>
                <span class="pc-thumb kpo-thumb kpo-thumb-lg kpo-thumb-empty" aria-hidden="true"><?= e(mb_substr((string) $it['label'], 0, 1)) ?></span>
                <?php endif; ?>
                <div class="kpo-card-meta">
                    <div class="kpo-card-title"><?= e((string) $it['label']) ?></div>
                    <div class="kpo-card-rev"><?= e((string) $it['rev_label']) ?></div>
                    <div class="kpo-card-rev">
                        <?php if ($hasUpload): ?>
                        <span class="kpo-flow-out">รูปจากหลังบ้าน</span>
                        <?php elseif ($fromInv): ?>
                        <span class="muted">ยังใช้อยู่รูปจาก inventory</span>
                        <?php else: ?>
                        <span class="text-warn">ยังไม่มีรูป</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <form class="kpo-image-form" method="post" enctype="multipart/form-data" action="<?= e(url('/pages/key-parts-images.php')) ?>">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="key" value="<?= e($k) ?>">
                <label class="kpo-image-file">
                    <span class="pc-tool-label">เลือกไฟล์รูป</span>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
                </label>
                <button type="submit" class="btn btn-primary btn-sm btn-with-icon">
                    <?= ui_icon_html('upload', 14, 'btn-svg') ?> อัปโหลด
                </button>
            </form>

            <?php if ($hasUpload): ?>
            <form class="kpo-image-form kpo-image-delete" method="post" action="<?= e(url('/pages/key-parts-images.php')) ?>"
                  <?= parts_confirm_attrs('ลบรูป ' . (string) $it['label'] . ' ?', 'ลบรูป', [], 'การ์ดจะกลับไปใช้ตัวอักษรย่อหรือรูปจาก inventory') ?>>
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="key" value="<?= e($k) ?>">
                <button type="submit" class="btn btn-outline btn-sm btn-with-icon">
                    <?= ui_icon_html('trash', 14, 'btn-svg') ?> ลบรูป
                </button>
            </form>
            <?php endif; ?>
        </article>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
