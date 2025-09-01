<?php
declare(strict_types=1);

// Simple mobile-first Bootstrap login template.
// NOTE: This page provides UI only. Hook up real auth on server.

$submitted = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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

        <?php if ($submitted): ?>
          <div class="alert alert-info" role="alert">
            Submitted login for <strong><?= $email ? h($email) : 'user' ?></strong>. Replace with real authentication.
          </div>
        <?php endif; ?>

        <div class="card shadow-sm">
          <div class="card-body p-4">
            <form method="post" novalidate class="needs-validation">
              <div class="mb-3">
                <label for="email" class="form-label">Email</label>
                <input
                  type="email"
                  class="form-control"
                  id="email"
                  name="email"
                  placeholder="you@example.com"
                  value="<?= h($email) ?>"
                  required
                  autocomplete="email"
                  inputmode="email"
                >
                <div class="invalid-feedback">Please enter a valid email.</div>
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

