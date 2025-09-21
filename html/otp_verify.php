<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib_auth.php';

$cfg = auth_config();
if (!empty($cfg['timezone'])) { @date_default_timezone_set((string)$cfg['timezone']); }

$pdo = auth_pdo();
$email = trim((string)($_POST['email'] ?? ''));
$code = trim((string)($_POST['code'] ?? ''));
$error = null;

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = 'Invalid request.';
} else if ($code === '' || strlen(preg_replace('/\D+/', '', $code)) < 6) {
    $error = 'Enter the 6‑digit code.';
} else {
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $error = 'Account not found.';
        } else {
            $uid = (int)$user['id'];
            if (auth_verify_otp($pdo, $uid, $code)) {
                $_SESSION['user_id'] = $uid;
                session_regenerate_id(true);
                auth_set_device_cookie($uid); // keep this device logged in
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid or expired code.';
            }
        }
    } catch (Throwable $e) {
        $error = 'Could not verify code. Try again.';
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Code · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-white">
    <main class="container d-flex justify-content-center align-items-center min-vh-100">
      <div class="w-100" style="max-width:420px;">
        <?php if ($error): ?>
          <div class="alert alert-danger mb-3"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="otp_verify.php" class="mb-3">
          <input type="hidden" name="email" value="<?= h($email) ?>">
          <div class="input-group">
            <input type="text" inputmode="numeric" pattern="\d*" maxlength="6" class="form-control" name="code" placeholder="6‑digit code" required autofocus>
            <button class="btn btn-primary" type="submit">Verify</button>
          </div>
        </form>
        <form method="post" action="otp_request.php">
          <input type="hidden" name="email" value="<?= h($email) ?>">
          <button class="btn btn-link p-0">Resend code</button>
        </form>
      </div>
    </main>
  </body>
 </html>

