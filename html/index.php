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
    <!-- Calendar navbar at the top -->
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
      </div>
    </nav>

    <main class="container py-5 d-flex justify-content-center">
      <div class="login-card">
        <?php if (!$dbReady): ?>
          <div class="alert alert-warning" role="alert">
            Missing or invalid configuration. Add <code>html/config.php</code> based on <code>config.php.example</code>.
          </div>
        <?php endif; ?>

        <div class="card shadow-sm">
          <div class="card-body p-4">
            <h1 class="h5 mb-3">Sign in</h1>
            <form method="post" action="magic_login_request.php" class="mb-0">
              <div class="mb-3">
                <label for="identifier" class="form-label">Email address</label>
                <input type="email" class="form-control" id="identifier" name="identifier" placeholder="you@example.com" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">Continue</button>
            </form>
            <div class="text-muted small mt-2">Magic links are the only way to sign in. Links are valid for 15 minutes and can be opened on multiple devices.</div>
          </div>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script></script>
  </body>
  </html>
