<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/remember_login.php';
secureSessionStart();
try {
    require_once __DIR__ . '/../config/database.php';
    revokeRememberLogin($pdo);
} catch (Throwable $e) {
    error_log('Could not revoke remember-login token: ' . $e->getMessage());
    clearRememberLoginCookie();
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header("Location: ../index.php");
exit;
?>
