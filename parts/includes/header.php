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

$partsNav = [
    ['file' => 'index',           'icon' => 'dashboard',      'label' => 'Dashboard',           'href' => url('/index.php')],
    ['file' => 'products',        'icon' => 'products',       'label' => 'อะไหล่',              'href' => url('/pages/products.php')],
    ['file' => 'stock-in',        'icon' => 'stock-in',       'label' => 'รับเข้า',             'href' => url('/pages/stock-in.php')],
    ['file' => 'stock-out',       'icon' => 'stock-out-set',  'label' => 'เบิกออก (Set)',       'href' => url('/pages/stock-out.php')],
    ['file' => 'stock-out-item',  'icon' => 'stock-out-item', 'label' => 'เบิกรายชิ้น',         'href' => url('/pages/stock-out-item.php')],
    ['file' => 'sets',            'icon' => 'sets',           'label' => 'จัดการ Set',          'href' => url('/pages/sets.php')],
    ['file' => 'history',         'icon' => 'history',        'label' => 'ประวัติเบิก',         'href' => url('/pages/history.php')],
    ['file' => 'year-end-summary', 'icon' => 'chart',          'label' => 'สรุปยอดสิ้นปี',       'href' => url('/pages/year-end-summary.php')],
];
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
    <nav class="sidebar" id="app-sidebar">
        <div class="sidebar-brand">
            <?php if ($brandLogo): ?>
                <img src="<?= e($brandLogo) ?>" alt="<?= e($partsAppName) ?>" class="brand-logo">
            <?php else: ?>
                <?= ui_nav_icon_html('box', 20, 'brand-icon') ?>
                <span><?= e($partsAppName) ?></span>
            <?php endif; ?>
        </div>
        <ul class="nav-links">
            <?php foreach ($partsNav as $item): ?>
            <li>
                <a href="<?= e($item['href']) ?>" class="<?= $currentPage === $item['file'] ? 'active' : '' ?>">
                    <?= ui_nav_icon_html($item['icon']) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?= ui_sidebar_cross_link(ui_finishgoogs_app_url(), 'ไปที่ระบบทะเบียนเครื่อง') ?>
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
