<?php
/**
 * login.php — เลิกใช้ login ภายในแล้ว ส่งต่อไป SSO
 */
require __DIR__ . '/config.php';
header('Location: ' . SSO_LOGIN_URL);
exit;
