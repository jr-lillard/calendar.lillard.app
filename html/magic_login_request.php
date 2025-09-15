<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/lib_auth.php';

$cfg = auth_config();
if (!empty($cfg['timezone'])) { @date_default_timezone_set((string)$cfg['timezone']); }

$pdo = auth_pdo();
$identifier = '';
$link = null;
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    if ($identifier === '') {
        $error = 'Please enter your email or username.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? OR username = ? LIMIT 1');
            $stmt->execute([$identifier, $identifier]);
            $u = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $link = auth_issue_magic_link($pdo, (int)$u['id']);
                // In production you would email $link to $u['email'].
            } else {
                $error = 'Account not found.';
            }
        } catch (Throwable $e) {
            $error = 'Could not issue magic link. Try again.';
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
    <title>Magic Link · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <div class="container py-4">
      <div class="mx-auto" style="max-width: 600px">
        <h1 class="h4 mb-3">Get a magic sign-in link</h1>
        <p class="text-muted">Enter your email or username. We’ll generate a link that signs you in and keeps this device logged in.</p>

        <?php if ($error): ?>
          <div class="alert alert-danger"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" class="card card-body shadow-sm mb-3">
          <div class="mb-3">
            <label for="identifier" class="form-label">Email or Username</label>
            <input class="form-control" id="identifier" name="identifier" value="<?= h($identifier) ?>" required>
          </div>
          <button class="btn btn-primary" type="submit">Send magic link</button>
          <a class="btn btn-link" href="index.php">Back to sign in</a>
        </form>

        <?php if ($link): ?>
          <div class="alert alert-info">
            <div class="fw-semibold mb-1">Magic link (dev):</div>
            <div><a href="<?= h($link) ?>"><?= h($link) ?></a></div>
            <div class="small text-muted mt-1">In production this would be emailed to you.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </body>
</html>

