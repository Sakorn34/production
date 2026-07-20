<?php
/**
 * cron/_bootstrap.php — bootstrap CLI สำหรับ LINE notification worker/scheduled
 */
$GLOBALS['line_notify_cli'] = true;
define('LINE_NOTIFY_CLI', true);

$root = dirname(__DIR__);
require $root . '/config.php';
require_once dirname($root) . '/shared/line_notify_core.php';
require_once dirname($root) . '/shared/line_flex_templates.php';
line_notify_ensure_schema();
