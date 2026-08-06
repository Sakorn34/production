<?php
require_once __DIR__ . '/parts_bootstrap.php';
ob_start();
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
        <?php $ubUser = ui_userbox_identity(); // ใช้ตรรกะเดียวกับ sidebar ฝั่ง production ?>
        <div class="userbox">
            <div class="ub-row">
                <div class="ub-avatar"><?= e(mb_substr(trim($ubUser['name']), 0, 1)) ?></div>
                <div class="ub-info">
                    <div class="ub-name"><?= e($ubUser['name']) ?></div>
                    <div class="muted"><?= e($ubUser['sub']) ?></div>
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
            <div class="alert alert-<?= e($flash['type']) ?>" id="parts-flash-alert" role="status">
                <?= e($flash['message']) ?>
            </div>
            <script>
            (function(){
                var el = document.getElementById('parts-flash-alert');
                if (!el) return;
                var duration = <?= $flash['type'] === 'error' ? 5200 : 3600 ?>;
                setTimeout(function(){
                    el.classList.add('alert-fade-out');
                    setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 420);
                }, duration);
            })();
            </script>
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
