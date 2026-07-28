<?php
require_once dirname(__DIR__) . '/shared/app_paths.php';
require_once app_parts_secrets_path();
ini_set('error_log', app_error_log_path());
date_default_timezone_set('Asia/Bangkok');
