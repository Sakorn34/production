<?php
/**
 * parts/includes/parts_bootstrap.php — bootstrap สำหรับแอป parts (ไม่ output HTML)
 *
 * โหลด session, auth, DB, StockService และตัวแปร layout ที่ header ใช้
 * ใช้ก่อน export/download หรือ redirect ที่ต้องไม่มี HTML ปนใน response
 *
 * Flow:
 *   require parts_bootstrap.php → ทำงาน business logic → exit หรือ require header.php
 */

if (defined('PARTS_BOOTSTRAP_LOADED')) {
    return;
}

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ui_parts.php';
require_once __DIR__ . '/main_theme.php';
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
require_once dirname(__DIR__, 2) . '/shared/error_messages.php';
activity_log_register_post_shutdown('parts', $line_name);
activity_log_register_page_view_shutdown('parts', $line_name);

require_once __DIR__ . '/StockService.php';

$db = getDB();
$GLOBALS['line_notify_stock_db'] = $db;
require_once __DIR__ . '/production_sync.php';
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

define('PARTS_BOOTSTRAP_LOADED', true);
