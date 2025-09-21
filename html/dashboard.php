<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib_auth.php';
if (!isset($_SESSION['user_id'])) { auth_try_device_login(); }
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$name = $_SESSION['user_username'] ?? $_SESSION['user_email'] ?? 'User';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.standalone .a2hs-tip{display:none!important}</style>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="#">Calendar</a>
        <div class="ms-auto d-flex align-items-center gap-2">
          <span class="me-2 text-muted">Signed in as <?= h($name) ?></span>
          <a class="btn btn-outline-primary" href="calendars.php">Calendars</a>
          <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
      </div>
    </nav>
    <main class="container py-4">
      <div class="alert alert-success">Welcome, <?= h($name) ?>! You are signed in.</div>
      <p class="text-muted">Start by adding a calendar URL on the Calendars page.</p>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
          if (mm && typeof mm.addEventListener === 'function') mm.addEventListener('change', setStandaloneClass);
        } catch(_){}
      })();
    </script>
  </body>
  </html>
