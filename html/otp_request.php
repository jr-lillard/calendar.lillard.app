<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib_auth.php';

$cfg = auth_config();
if (!empty($cfg['timezone'])) { @date_default_timezone_set((string)$cfg['timezone']); }

$pdo = auth_pdo();
$email = '';
$issued = false;
$devCode = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            // Allow domain alias mapping for lookup (e.g., jr@lillard.org -> jr@lillard.dev)
            $lookupEmail = auth_canonicalize_login_email($email);
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$lookupEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $error = 'No account found for that email.';
            } else {
                $ttl = (int)($cfg['otp_ttl_minutes'] ?? 10); // short expiry
                $code = auth_issue_otp($pdo, (int)$user['id'], $ttl);
                // Send code via email (SMTP2GO)
                $mins = max(1, min(60, $ttl));
                $subj = 'Your sign-in code';
                $text = "Your sign-in code is: $code\n\nThis code expires in $mins minutes.\nIf you didn't request this, you can ignore this email.";
                // Build HTML body in a way that avoids brittle escaping
                $htmlCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html  = "<p>Your sign-in code is:</p>";
                $html .= "<p style=\"font-size:20px;\"><strong>{$htmlCode}</strong></p>";
                $html .= "<p>This code expires in " . (int)$mins . " minutes.</p>";
                $html .= "<p>If you didn’t request this, you can ignore this email.</p>";
                $sent = auth_send_email($email, $subj, $text, $html);
                // In dev, still show code inline in case email is not configured.
                $devCode = $code;
                $issued = true;
            }
        } catch (Throwable $e) {
            $error = 'Could not send code. Try again.';
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enter Code · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-white">
    <main class="container d-flex justify-content-center align-items-center min-vh-100">
      <div class="w-100" style="max-width:420px;">
        <?php if ($error): ?>
          <div class="alert alert-danger mb-3"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($issued): ?>
          <form method="post" action="otp_verify.php" class="mb-3">
            <input type="hidden" name="email" value="<?= h($email) ?>">
            <div class="mb-2 text-muted small">We sent a 6‑digit code to <?= h($email) ?>. It expires soon.</div>
            <div class="input-group">
              <input type="text" inputmode="numeric" pattern="\d*" maxlength="6" class="form-control" name="code" placeholder="6‑digit code" required autofocus>
              <button class="btn btn-primary" type="submit" aria-label="Verify" title="Verify">Verify</button>
            </div>
          </form>
          <?php if ($devCode): ?>
            <div class="alert alert-info py-2">Dev code: <strong><?= h($devCode) ?></strong></div>
          <?php endif; ?>
        <?php else: ?>
          <form method="post" class="mb-3">
            <div class="input-group">
              <input type="email" class="form-control" name="email" placeholder="Email address" value="<?= h($email) ?>" required autofocus>
              <button class="btn btn-primary" type="submit">Send code</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </main>
  </body>
 </html>
