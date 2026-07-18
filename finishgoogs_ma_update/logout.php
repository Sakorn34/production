<?php
/**
 * logout.php — localhost dev: กลับ login.php | production: ล้าง session แล้วไป SSO
 */
require __DIR__ . '/config.php';
$local = is_localhost_request();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

if ($local) {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

header('Location: ' . SSO_LOGIN_URL);
exit;
