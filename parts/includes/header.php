<?php
ob_start();
session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

parts_localhost_bootstrap_session();

if (!isset($_SESSION['profile'])) {
    session_unset();
    session_destroy();
    $loginUrl = parts_is_localhost_request()
        ? 'http://localhost/production/finishgoogs_ma_update/login.php'
        : 'https://bit-online.net/bitlogin/index.php';
    header('Location: ' . $loginUrl);
    exit();
}
$profile = $_SESSION['profile'];
$line_name = $profile->login_name ?? 'User';

require_once __DIR__ . '/../includes/StockService.php';

$db = getDB();
require_once __DIR__ . '/../includes/production_sync.php';
ensure_stock_production_sync_schema($db);

$stock = new StockService($db);

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Stock Management') ?> - ระบบจัดการสต็อก</title>
    <link rel="icon" type="image/png" href="<?= url('/favicon.png') ?>">
    <link rel="stylesheet" href="<?= url('/assets/style.css') ?>">
</head>
<body>
    <div class="sidebar-edge" aria-hidden="true"></div>
    <div class="sidebar-backdrop" id="sidebar-backdrop" aria-hidden="true"></div>
    <nav class="sidebar" id="app-sidebar">
        <div class="sidebar-brand">
            <span class="brand-icon">📦</span>
            <span>Stock ช่าง</span>
        </div>
        <ul class="nav-links">
            <li><a href="<?= url('/index.php') ?>" class="<?= $currentPage === 'index' ? 'active' : '' ?>">📊 Dashboard</a></li>
            <li><a href="<?= url('/pages/products.php') ?>" class="<?= $currentPage === 'products' ? 'active' : '' ?>">📋 อะไหล่</a></li>
            <li><a href="<?= url('/pages/stock-in.php') ?>" class="<?= $currentPage === 'stock-in' ? 'active' : '' ?>">📥 รับเข้า</a></li>
            <li><a href="<?= url('/pages/stock-out.php') ?>" class="<?= $currentPage === 'stock-out' ? 'active' : '' ?>">📤 เบิกออก (Set)</a></li>
            <li><a href="<?= url('/pages/stock-out-item.php') ?>" class="<?= $currentPage === 'stock-out-item' ? 'active' : '' ?>">📤 เบิกรายชิ้น</a></li>
            <li><a href="<?= url('/pages/sets.php') ?>" class="<?= $currentPage === 'sets' ? 'active' : '' ?>">🧩 จัดการ Set</a></li>
            <li><a href="<?= url('/pages/history.php') ?>" class="<?= $currentPage === 'history' ? 'active' : '' ?>">📜 ประวัติเบิก</a></li>
            <!-- <li><a href="<?= url('/pages/webhook-test.php') ?>" class="<?= $currentPage === 'webhook-test' ? 'active' : '' ?>">🧪 Webhook Test</a></li> -->
        </ul>
    </nav>

    <main class="content">
        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>
