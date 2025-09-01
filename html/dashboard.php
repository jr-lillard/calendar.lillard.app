<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
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
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="#">Calendar</a>
        <div class="ms-auto">
          <span class="me-3 text-muted">Signed in as <?= h($name) ?></span>
          <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
      </div>
    </nav>
    <main class="container py-4">
      <div class="alert alert-success">Welcome, <?= h($name) ?>! You are signed in.</div>
      <p class="text-muted">This is a placeholder dashboard. Replace with app content.</p>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body>
  </html>

