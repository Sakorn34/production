<?php
ob_start();
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/main_theme.php';
require_once dirname(__DIR__, 2) . '/shared/ui_icons.php';

parts_localhost_bootstrap_session();

if (!isset($_SESSION['profile'])) {
    session_unset();
    session_destroy();
    $loginUrl = parts_is_localhost_request()
        ? 'http://localhost/production/finishgoogs_ma_update/login.php'
        : app_sso_parts_login_url();
    header('Location: ' . $loginUrl);
    exit();
}
$profile = $_SESSION['profile'];
$line_name = $profile->login_name ?? 'User';

require_once dirname(__DIR__, 2) . '/shared/activity_log_core.php';
require_once dirname(__DIR__, 2) . '/shared/line_notify_core.php';
require_once dirname(__DIR__, 2) . '/shared/line_flex_templates.php';
activity_log_register_post_shutdown('parts', $line_name);

require_once __DIR__ . '/../includes/StockService.php';

$db = getDB();
$GLOBALS['line_notify_stock_db'] = $db;
require_once __DIR__ . '/../includes/production_sync.php';
ensure_stock_production_sync_schema($db);

$stock = new StockService($db);

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$flash = getFlash();
$fontCfg = main_font_config();
$brandLogo = main_brand_logo_url();
$partsAppName = trim((string) main_setting('app_name', '')) ?: 'Stock ช่าง';

// เมนูของ parts มาจาก source กลางเดียวกับกลุ่มข้ามระบบใน finishgoogs (shared/ui_icons.php)
$partsNav = array_map(function ($it) {
    return [
        'file'  => basename($it['file'], '.php'),
        'icon'  => $it['icon'],
        'label' => $it['label'],
        'href'  => url('/' . $it['file']),
    ];
}, ui_nav_items_parts());
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Stock Management') ?> - <?= e($partsAppName) ?></title>
    <link rel="icon" type="image/png" href="<?= url('/favicon.png') ?>">
    <?php if (!empty($fontCfg['google'])): ?>
    <link rel="stylesheet" href="<?= e($fontCfg['google']) ?>">
    <?php endif; ?>
    <?php if (!empty($fontCfg['custom_file'])):
        $fontUrl = main_upload_url($fontCfg['custom_file']);
        $ext = strtolower(pathinfo($fontCfg['custom_file'], PATHINFO_EXTENSION));
        $fmt = $ext === 'woff2' ? 'woff2' : ($ext === 'woff' ? 'woff' : ($ext === 'otf' ? 'opentype' : 'truetype'));
    ?>
    <style>@font-face{font-family:'AppCustomFont';src:url('<?= e($fontUrl) ?>') format('<?= e($fmt) ?>');font-display:swap;font-weight:400;font-style:normal;}</style>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= url('/assets/style.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?: 3 ?>">
    <style id="theme-vars"><?= parts_theme_css_block() ?></style>
    <link rel="stylesheet" href="<?= url('/assets/theme-v2.css') ?>?v=<?= @filemtime(__DIR__ . '/../assets/theme-v2.css') ?: time() ?>">
</head>
<body>
    <div class="sidebar-edge" aria-hidden="true"></div>
    <div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>
    <button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="เปิด/ปิดเมนู" aria-controls="app-sidebar">
        <?= ui_icon_html('menu', 18, 'toggle-svg') ?>
    </button>
    <nav class="sidebar" id="app-sidebar">
        <div class="sidebar-brand">
            <?php if ($brandLogo): ?>
                <img src="<?= e($brandLogo) ?>" alt="<?= e($partsAppName) ?>" class="brand-logo">
            <?php else: ?>
                <?= ui_nav_icon_html('box', 20, 'brand-icon') ?>
                <span><?= e($partsAppName) ?></span>
            <?php endif; ?>
        </div>
        <?php /* กลุ่ม "ทะเบียนเครื่อง" อยู่ลำดับแรกเสมอทั้ง 2 แอป — ผู้ใช้จำตำแหน่งเมนูที่เดิมได้ */ ?>
        <div class="nav-links-cross nav-links-cross-top">
            <?= ui_sidebar_cross_group('ทะเบียนเครื่อง', ui_nav_items_finishgoogs(), ui_finishgoogs_base_url()) ?>
        </div>
        <ul class="nav-links">
            <li><?= ui_nav_group_label('สต็อกอะไหล่') ?></li>
            <?php foreach ($partsNav as $item): ?>
            <li>
                <a href="<?= e($item['href']) ?>" class="<?= $currentPage === $item['file'] ? 'active' : '' ?>">
                    <?= ui_nav_icon_html($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="userbox">
            <div class="ub-row">
                <div class="ub-avatar"><?= e(mb_substr(trim($line_name), 0, 1)) ?></div>
                <div class="ub-info">
                    <div class="ub-name"><?= e($line_name) ?></div>
                    <div class="muted">SSO</div>
                </div>
                <?= ui_userbox_settings_link() ?>
            </div>
            <div class="ub-links">
                <a href="<?= e(ui_finishgoogs_base_url() . '/profile.php') ?>">โปรไฟล์</a>
                ·
                <a href="<?= e(ui_finishgoogs_base_url() . '/logout.php') ?>">ออกจากระบบ</a>
            </div>
        </div>
    </nav>

    <main class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>
        <?php
        $partsShowBack = $partsShowBack ?? !parts_is_menu_page($currentPage);
        if ($partsShowBack):
            $partsBackHref = parts_page_back_url($partsBackUrl ?? '');
        ?>
        <div class="pagehead">
            <a href="<?= e($partsBackHref) ?>" class="btn btn-outline btn-sm backbtn">← ย้อนกลับ</a>
        </div>
        <?php endif; ?>
