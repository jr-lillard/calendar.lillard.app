<?php
declare(strict_types=1);

// Mobile-first Bootstrap login with MySQL (PDO) backend.
// Credentials live in html/config.php (git-ignored). See config.php.example.

session_start();

// Magic links are the only login method
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

// If already signed in, go to dashboard
if (isset($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Magic Link Sign in · Calendar</title>
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

        <div class="card shadow-sm">
          <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <div class="fw-semibold">Sign in with a magic link</div>
                <div class="text-muted small">Passwords are disabled. Magic links are valid for 15 minutes and can be opened on multiple devices.</div>
              </div>
              <a class="btn btn-primary" href="magic_login_request.php">Get magic link</a>
            </div>
          </div>
        </div>

        <p class="text-center text-muted mt-3 mb-0">
          Don’t have an account? <a href="#">Create one</a>
        </p>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script></script>
  </body>
  </html>
