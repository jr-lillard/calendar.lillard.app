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
    <title>Login · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      /* Layout */
      .login-wrap { max-width: 420px; width: 100%; }
      body { background: #ffffff !important; }

      /* Pure flat look: no card, no borders, no shadows anywhere */
      .flat-panel { background: #ffffff !important; }
      .flat-panel *,
      .form-control,
      .btn { box-shadow: none !important; }
      .form-control:focus,
      .btn:focus { box-shadow: none !important; outline: none !important; }

      /* Remove any default borders/radius that might suggest a card */
      .flat-panel,
      .form-control { border-radius: 0 !important; }

      /* Bigger chevron button that clearly dominates the control */
      .login-chevron-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 !important;
        min-width: 3.5rem !important; /* wide button */
        height: 3rem !important;      /* tall button */
        line-height: 1 !important;
        border: 0; box-shadow: none !important;
      }
      .login-chevron-btn svg {
        width: 2.2rem;  /* enlarge the chevron itself */
        height: 2.2rem;
        display: block;
      }
      /* Ensure input height matches button height for visual balance */
      .input-group-lg > .form-control,
      .input-group-lg > .btn { height: 3rem; }
    </style>
  </head>
  <body class="bg-white">
    <main class="container d-flex justify-content-center align-items-center min-vh-100">
      <div class="login-wrap">
        <?php if (!$dbReady): ?>
          <div class="alert alert-warning" role="alert">
            Missing or invalid configuration. Add <code>html/config.php</code> based on <code>config.php.example</code>.
          </div>
        <?php endif; ?>

        <div class="flat-panel p-4">
          <form method="post" action="magic_login_request.php" class="mb-0">
            <div class="input-group input-group-lg">
              <input type="email" class="form-control" id="identifier" name="identifier" placeholder="Email address" required>
              <button class="btn btn-primary login-chevron-btn" type="submit" aria-label="Login" title="Login">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">
                  <path d="M6.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 1 1-.708-.708L12.293 8 6.646 2.354a.5.5 0 0 1 0-.708z"/>
                </svg>
              </button>
            </div>
          </form>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script></script>
  </body>
  </html>
