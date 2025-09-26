<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib_auth.php';

$nocache = function(){
    @header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    @header('Pragma: no-cache');
    @header('Expires: 0');
};
$nocache();
$cfg = auth_config();
if (!empty($cfg['timezone'])) { @date_default_timezone_set((string)$cfg['timezone']); }

$pdo = auth_pdo();
$email = '';
$issued = false;
$devCode = null; // no longer displayed in UI
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Accept either 'email' or a neutral field name 'eml' from the login form
    $email = trim((string)($_POST['email'] ?? $_POST['eml'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        // Always attempt to issue an OTP and proceed to the verify screen; never dead-end here.
        try {
            // Allow domain alias mapping for lookup (e.g., jr@lillard.org -> jr@lillard.dev)
            $lookupEmail = auth_canonicalize_login_email($email);
            // Find or create the user so the OTP flow never dead-ends on the login screen
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$lookupEmail]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                // Create a minimal user record with this email. The current schema requires a non-null
                // password_hash column, so generate a random throwaway hash. This account will authenticate
                // via OTP/device tokens only; the password value is never used.
                try {
                    $rand = bin2hex(random_bytes(16));
                    $ph = password_hash($rand, PASSWORD_DEFAULT);
                    $ins = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?,?, NOW())');
                    $ins->execute([$lookupEmail, $ph]);
                } catch (Throwable $e) {
                    try {
                        $rand = bin2hex(random_bytes(16));
                        $ph = password_hash($rand, PASSWORD_DEFAULT);
                        $ins = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?,?)');
                        $ins->execute([$lookupEmail, $ph]);
                    } catch (Throwable $e2) {
                        // If user creation fails, still surface a generic error below
                        throw $e2;
                    }
                }
                // Re-select
                $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$lookupEmail]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if ($user) {
                $ttl = (int)($cfg['otp_ttl_minutes'] ?? 10); // short expiry
                $code = auth_issue_otp($pdo, (int)$user['id'], $ttl);
                // Try to send code via email (SMTP2GO), but don't block the UX if it fails.
                $mins = max(1, min(60, $ttl));
                $subj = 'Your sign-in code';
                $text = "Your sign-in code is: $code\n\nThis code expires in $mins minutes.\nIf you didn't request this, you can ignore this email.";
                $htmlCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html  = "<p>Your sign-in code is:</p>";
                $html .= "<p style=\"font-size:20px;\"><strong>{$htmlCode}</strong></p>";
                $html .= "<p>This code expires in " . (int)$mins . " minutes.</p>";
                $html .= "<p>If you didn’t request this, you can ignore this email.</p>";
                try {
                    $sent = auth_send_email($email, $subj, $text, $html);
                    if (!$sent) {
                        // Lightweight server-side log for diagnostics; do not block UX
                        @file_put_contents(__DIR__ . '/sessions/otp_error.log', date('c') . " send failed for $email\n", FILE_APPEND);
                    }
                } catch (Throwable $mx) {
                    @file_put_contents(__DIR__ . '/sessions/otp_error.log', date('c') . " exception sending to $email: " . $mx->getMessage() . "\n", FILE_APPEND);
                }
                // Do not surface the code in the UI. Leave $devCode unused to avoid leaking.
                $devCode = null;
                $issued = true;
            } else {
                // Could not find or create user
                $error = 'Could not send code. Try again.';
            }
        } catch (Throwable $e) {
            // Log and proceed to show a friendly error. We do not expose internal details.
            @file_put_contents(__DIR__ . '/sessions/otp_error.log', date('c') . " fatal in otp_request: " . $e->getMessage() . "\n", FILE_APPEND);
            $error = 'Could not send code. Try again.';
        }
    }
}

// If this endpoint is reached via GET, do not render an alternate legacy form.
// Redirect back to the primary login so the flow is consistent.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
      /* Match login look */
      .verify-wrap { max-width: 420px; width: 100%; }
      body { background: #ffffff !important; }
      .code-input::placeholder { text-align: center; }
      .code-input { text-align: center; font-size: 1.25rem; }
      .btn-icon { display: flex; align-items: center; justify-content: center; min-width: 3rem; }
      .btn-icon .bi { font-size: 1rem; }
      .flat-panel *, .form-control, .btn { box-shadow: none !important; }
      .form-control:focus, .btn:focus { box-shadow: none !important; outline: none !important; }
      .form-control { border-radius: 0 !important; }
    </style>
  </head>
  <body class="bg-white">
    <main class="container d-flex justify-content-center align-items-center min-vh-100">
      <div class="verify-wrap flat-panel">
        <?php if ($error): ?>
          <div class="alert alert-danger mb-3"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="otp_verify.php" class="mb-0" autocomplete="off" novalidate>
          <input type="hidden" name="email" value="<?= h($email) ?>">
          <div class="input-group">
            <input type="text"
                   inputmode="numeric"
                   pattern="\d*"
                   maxlength="6"
                   class="form-control code-input"
                   name="code"
                   placeholder="enter the code sent to your email"
                   autocomplete="one-time-code"
                   required
                   autofocus>
            <button class="btn btn-primary btn-icon" type="submit" aria-label="Verify">
              <i class="bi bi-chevron-right"></i>
            </button>
          </div>
        </form>
      </div>
    </main>
  </body>
 </html>
