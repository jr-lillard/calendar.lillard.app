<?php
declare(strict_types=1);

// Mobile-first Bootstrap login with MySQL (PDO) backend.
// Credentials live in html/config.php (git-ignored). See config.php.example.

session_start();

$submitted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$identifier = isset($_POST['identifier'])
  ? trim((string)$_POST['identifier'])
  : (isset($_POST['email']) ? trim((string)$_POST['email']) : '');
$loginError = null;
$loginOk = false;
$dbReady = false;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$configPath = __DIR__ . '/config.php';
$config = null;
$pdo = null;
if (file_exists($configPath)) {
    $config = require $configPath;
    if (!empty($config['timezone'])) {
        @date_default_timezone_set((string)$config['timezone']);
    }
    try {
        $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
        $dbReady = true;
    } catch (Throwable $e) {
        $loginError = 'Database connection failed. Please try again later.';
    }
}

if ($submitted && $dbReady) {
    $password = (string)($_POST['password'] ?? '');
    try {
        $stmt = $pdo->prepare('SELECT id, email, username, password_hash FROM users WHERE email = ? OR username = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, (string)$user['password_hash'])) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_email'] = (string)$user['email'];
            $_SESSION['user_username'] = isset($user['username']) ? (string)$user['username'] : null;
            if (PHP_SESSION_ACTIVE === session_status()) {
                session_regenerate_id(true);
            }
            header('Location: dashboard.php');
            exit;
        } else {
            $loginError = 'Invalid email or password.';
        }
    } catch (Throwable $e) {
        $loginError = 'Login failed. Please try again.';
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      .login-card { max-width: 420px; width: 100%; }
      .brand { font-weight: 600; }
    </style>
  </head>
  <body class="bg-light">
    <div class="container min-vh-100 d-flex align-items-center justify-content-center py-4">
      <div class="login-card">
        <div class="text-center mb-4">
          <div class="brand h3 mb-1">Calendar</div>
          <div class="text-muted">Sign in to continue</div>
        </div>

        <?php if (!$dbReady): ?>
          <div class="alert alert-warning" role="alert">
            Missing or invalid configuration. Add <code>html/config.php</code> based on <code>config.php.example</code>.
          </div>
        <?php endif; ?>

        <?php if ($submitted && $loginError): ?>
          <div class="alert alert-danger" role="alert">
            <?= h($loginError) ?>
          </div>
        <?php endif; ?>

        <div class="card shadow-sm">
          <div class="card-body p-4">
            <?php if (isset($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; } ?>
            <form method="post" novalidate class="needs-validation" <?= $dbReady ? '' : 'aria-disabled="true"' ?> >
              <div class="mb-3">
                <label for="identifier" class="form-label">Email or Username</label>
                <input
                  type="text"
                  class="form-control"
                  id="identifier"
                  name="identifier"
                  placeholder="you@example.com or username"
                  value="<?= h($identifier) ?>"
                  required
                  autocomplete="username"
                  inputmode="text"
                >
                <div class="invalid-feedback">Please enter your email or username.</div>
              </div>

              <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input
                  type="password"
                  class="form-control"
                  id="password"
                  name="password"
                  placeholder="••••••••"
                  required
                  autocomplete="current-password"
                >
                <div class="invalid-feedback">Password is required.</div>
              </div>

              <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="1" id="remember" name="remember">
                  <label class="form-check-label" for="remember">Remember me</label>
                </div>
                <a href="#" class="small">Forgot password?</a>
              </div>

              <div class="d-grid gap-2">
                <button class="btn btn-primary btn-lg" type="submit">Sign In</button>
              </div>
            </form>
          </div>
        </div>

        <p class="text-center text-muted mt-3 mb-0">
          Don’t have an account? <a href="#">Create one</a>
        </p>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      (() => {
        'use strict';
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
          form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
              event.preventDefault();
              event.stopPropagation();
            }
            form.classList.add('was-validated');
          }, false);
        });
      })();
    </script>
  </body>
  </html>
