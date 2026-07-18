<?php
/**
 * login.php — localhost dev: login เป็น Tom | production: ส่งต่อ SSO
 */
require __DIR__ . '/config.php';

if (is_localhost_request()) {
    $_SESSION['profile'] = (object)[
        'login_name'   => 'Tom',
        'display_name' => 'Tom',
    ];
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

header('Location: ' . SSO_LOGIN_URL);
exit;
