<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib_auth.php';

function redirect(string $path): void { header('Location: ' . $path); exit; }

try {
    $pdo = auth_pdo();
    $token = (string)($_GET['token'] ?? '');
    if ($token === '') { redirect('index.php'); }
    $userId = auth_consume_magic_link($pdo, $token);
    if ($userId === null) { echo 'Invalid or expired link.'; exit; }
    $_SESSION['user_id'] = $userId;
    if (PHP_SESSION_ACTIVE === session_status()) { session_regenerate_id(true); }
    // Issue long-lived device token so this device stays logged in.
    auth_set_device_cookie($userId);
    redirect('dashboard.php');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Sign-in failed. Please try again.';
}

