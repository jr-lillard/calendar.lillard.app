<?php
declare(strict_types=1);

// Mobile-first Bootstrap login with MySQL (PDO) backend.
// Credentials live in html/config.php (git-ignored). See config.php.example.

session_start();
// Force fresh load of the login shell to avoid stale CDN/browser cache
@header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@header('Pragma: no-cache');
@header('Expires: 0');

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
    <title>Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons for chevron button -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
      /* Layout */
      .login-wrap { max-width: 420px; width: 100%; }
      body { background: #ffffff !important; }
      /* Hide any Add-to-Home-Screen tips when running standalone (PWA/installed) */
      .standalone .a2hs-tip { display: none !important; }

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

      /* Chevron button inside input group: look like a real button */
      .login-chevron-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: .375rem .75rem; /* Bootstrap default btn padding */
        line-height: 1; /* keep icon nicely centered */
      }
      .login-chevron-btn .bi { font-size: 1rem; }
      /* Center both placeholder and typed text */
      .form-control { text-align: center; }
      .form-control::placeholder { text-align: center; opacity: .6; }
      .form-control::-webkit-input-placeholder { text-align: center; opacity: .6; }
      .form-control:-ms-input-placeholder { text-align: center; }
      .form-control:placeholder-shown { text-align: center; }
      .form-control:focus { text-align: center; }
      /* Hide Safari/Keychain autofill UI as much as possible */
      form[autocomplete="off"] input::autofill,
      form[autocomplete="off"] input:-webkit-autofill {
        box-shadow: 0 0 0px 1000px #ffffff inset !important;
        -webkit-text-fill-color: inherit !important;
      }
      .login-chevron-btn:focus { outline: none !important; box-shadow: none !important; }
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
<?php $rnd = bin2hex(random_bytes(4)); $inpId = 'in_'. $rnd; $inpName = 'eml_'. $rnd; ?>
          <!-- Standard input + chevron button (anti‑autofill, JS submit) -->
          <div class="mb-0" id="loginShell" role="presentation">
            <div class="input-group">
              <input
                id="<?php echo h($inpId); ?>"
                name="<?php echo h($inpName); ?>"
                type="text"
                class="form-control"
                inputmode="email"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                autocomplete="off"
                placeholder="what is your email address?"
                aria-label="what is your email address?"
                autofocus
              >
              <button class="btn btn-primary login-chevron-btn" type="button" aria-label="Continue" title="Continue" id="goBtn">
                <i class="bi bi-chevron-right" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      // Detect if running as an installed app (standalone) and toggle a class on <html>
      (function(){
        function setStandaloneClass(){
          var isStandalone = (window.navigator.standalone === true) ||
                             (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
          var root = document.documentElement;
          root.classList.toggle('standalone', !!isStandalone);
          root.classList.toggle('not-standalone', !isStandalone);
        }
        document.addEventListener('DOMContentLoaded', setStandaloneClass);
        try {
          var mm = window.matchMedia && window.matchMedia('(display-mode: standalone)');
          if (mm && typeof mm.addEventListener === 'function') {
            mm.addEventListener('change', setStandaloneClass);
          }
        } catch(_){}
      })();

      // Standard input + JS submit to reduce Safari saved‑login prompts
      (function(){
        document.addEventListener('DOMContentLoaded', function(){
          var inpId = <?php echo json_encode($inpId, JSON_UNESCAPED_SLASHES); ?>;
          var vis = document.getElementById(inpId);
          var go  = document.getElementById('goBtn');
          if (!vis || !go) return;

          // Ensure autofocus takes effect
          try { vis.focus(); vis.selectionStart = vis.value.length; } catch(_){}

          async function submitNow(){
            var val = (vis.value || '').trim();
            if (!val) { vis.focus(); return; }
            try {
              var body = new URLSearchParams();
              body.set('eml', val);
              await fetch('otp_request.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                credentials: 'include',
                body: body.toString()
              });
              window.location.href = 'otp_verify.php';
            } catch (e) {
              window.location.href = 'otp_verify.php';
            }
          }
          go.addEventListener('click', submitNow);
          vis.addEventListener('keydown', function(e){
            if (e.key === 'Enter') { e.preventDefault(); submitNow(); }
          });
        });
      })();
    </script>
  </body>
  </html>
