<?php
require_once __DIR__ . '/parts_bootstrap.php';
ob_start();
$ubUser = ui_userbox_identity();
$showName = $ubUser['name'];
$fgBase = ui_finishgoogs_base_url();
$settingsActive = false;
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
    <link rel="stylesheet" href="<?= e($fgBase) ?>/assets/sidebar.css?v=<?= @filemtime(dirname(__DIR__, 2) . '/finishgoogs_ma_update/assets/sidebar.css') ?: time() ?>">
</head>
<body>
<div class="app nav-collapsed">
    <aside class="sidebar" id="app-sidebar">
        <div class="sidebar-head">
            <div class="brand sidebar-brand">
                <?php if ($brandLogo): ?>
                    <img src="<?= e($brandLogo) ?>" alt="<?= e($partsAppName) ?>" class="brand-logo">
                    <span class="brand-text"><?= e($partsAppName) ?></span>
                <?php else: ?>
                    <?= ui_nav_icon_html('box', 20, 'brand-icon') ?>
                    <span class="brand-text"><?= e($partsAppName) ?></span>
                <?php endif; ?>
            </div>
            <button type="button" class="sidebar-pin-btn" aria-label="ขยายเมนู" aria-expanded="false">
                <?= ui_icon_html('chevron-right', 16, 'pin-chevron') ?>
            </button>
        </div>
        <div class="sidebar-search" data-smart-search="<?= e($fgBase) ?>/smart_search.php">
            <div class="sidebar-search-inner">
                <span class="sidebar-search-icon"><?= ui_icon_html('search', 16) ?></span>
                <input type="search" class="sidebar-search-input" placeholder="ค้นหา S/N, MA, อัปเดต..." autocomplete="off" aria-label="ค้นหาอัจฉริยะ">
            </div>
            <?php // ตอนหุบเมนู ช่องค้นหาเต็มไม่มีที่ยืน — ปุ่มนี้แทนที่ ?>
            <button type="button" class="sidebar-search-mini" aria-label="ค้นหา" title="ค้นหา"><?= ui_icon_html('search', 16) ?></button>
        </div>
        <div class="sidebar-nav">
            <div class="nav-links-cross nav-links-cross-top">
                <?= ui_sidebar_cross_group('ทะเบียนเครื่อง', ui_nav_items_finishgoogs(), $fgBase) ?>
            </div>
            <ul class="nav-links">
                <li><?= ui_nav_group_label('สต็อกอะไหล่') ?></li>
                <?php foreach ($partsNav as $item): ?>
                <li>
                    <a href="<?= e($item['href']) ?>" class="<?= $currentPage === $item['file'] ? 'active' : '' ?>">
                        <?= ui_nav_icon_html($item['icon']) ?>
                        <span class="nav-text"><?= e($item['label']) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="sidebar-foot">
            <button type="button" class="userbox-trigger" aria-expanded="false" aria-haspopup="true">
                <div class="ub-avatar"><?= e(mb_substr(trim($showName), 0, 1)) ?></div>
                <div class="ub-info">
                    <div class="ub-name"><?= e($showName) ?></div>
                    <div class="muted"><?= e($ubUser['sub']) ?></div>
                </div>
                <span class="userbox-chevron" aria-hidden="true">▾</span>
            </button>
            <div class="userbox-popover" hidden>
                <div class="userbox-popover-head">
                    <div class="ub-avatar"><?= e(mb_substr(trim($showName), 0, 1)) ?></div>
                    <div class="ub-info">
                        <div class="ub-name"><?= e($showName) ?></div>
                        <div class="muted"><?= e($ubUser['sub']) ?></div>
                    </div>
                </div>
                <a href="<?= e($fgBase . '/profile.php') ?>"><?= ui_icon_html('user', 16) ?> โปรไฟล์</a>
                <a href="<?= e(ui_settings_admin_url()) ?>"><?= ui_icon_html('settings', 16) ?> ตั้งค่าระบบ</a>
                <div class="userbox-popover-divider"></div>
                <a href="<?= e($fgBase . '/logout.php') ?>" class="userbox-pop-danger"><?= ui_icon_html('logout', 16) ?> ออกจากระบบ</a>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop nav-backdrop" id="sidebar-backdrop" hidden aria-hidden="true"></div>

    <main class="content">