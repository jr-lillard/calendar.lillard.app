<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib_auth.php';

// Revoke the persistent device token (if any) so this device won't auto‑login again
auth_revoke_current_device();

// Clear PHP session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', !empty($params['secure']), !empty($params['httponly']));
}
session_destroy();
header('Location: index.php');
exit;
