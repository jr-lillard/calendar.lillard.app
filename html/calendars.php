<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$config = require __DIR__.'/config.php';
$pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
$uid = (int)$_SESSION['user_id'];
$error = null; $info = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    if ($name === '' || $url === '') {
        $error = 'Name and URL are required.';
    } else {
        $p = parse_url($url);
        if (!$p || !in_array(($p['scheme'] ?? ''), ['http','https'], true)) {
            $error = 'URL must start with http:// or https://';
        } else {
            $stmt = $pdo->prepare('INSERT INTO calendars (user_id, name, url) VALUES (?,?,?)');
            $stmt->execute([$uid, $name, $url]);
            header('Location: calendars.php?added=1');
            exit;
        }
    }
}

if (($_GET['delete'] ?? '') !== '') {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare('DELETE FROM calendars WHERE id=? AND user_id=?');
    $stmt->execute([$id, $uid]);
    header('Location: calendars.php?deleted=1');
    exit;
}

$res = $pdo->prepare('SELECT id, name, url, created_at FROM calendars WHERE user_id=? ORDER BY created_at DESC, id DESC');
$res->execute([$uid]);
$cals = $res->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendars · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
        <div class="ms-auto">
          <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
      </div>
    </nav>
    <main class="container py-4">
      <div class="row g-4">
        <div class="col-12 col-lg-5">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Add Calendar URL</h5>
              <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
              <?php elseif (isset($_GET['added'])): ?>
                <div class="alert alert-success">Calendar added.</div>
              <?php elseif (isset($_GET['deleted'])): ?>
                <div class="alert alert-warning">Calendar deleted.</div>
              <?php endif; ?>
              <form method="post" class="needs-validation" novalidate>
                <div class="mb-3">
                  <label class="form-label" for="name">Name</label>
                  <input class="form-control" id="name" name="name" required placeholder="Work, Personal, ...">
                </div>
                <div class="mb-3">
                  <label class="form-label" for="url">Calendar URL (ICS)</label>
                  <input class="form-control" id="url" name="url" required placeholder="https://example.com/calendar.ics" inputmode="url">
                </div>
                <button class="btn btn-primary" type="submit">Add</button>
              </form>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-7">
          <div class="card shadow-sm">
            <div class="card-body">
              <h5 class="card-title">Your Calendars</h5>
              <?php if (!$cals): ?>
                <p class="text-muted">No calendars yet. Add one using the form.</p>
              <?php else: ?>
                <div class="list-group">
                  <?php foreach ($cals as $cal): ?>
                    <div class="list-group-item d-flex align-items-center justify-content-between">
                      <div>
                        <div class="fw-semibold"><?= h($cal['name']) ?></div>
                        <div class="small text-muted text-truncate" style="max-width: 50ch;"><?= h($cal['url']) ?></div>
                      </div>
                      <div class="ms-3 d-flex gap-2">
                        <a class="btn btn-sm btn-primary" href="calendar_view.php?id=<?= (int)$cal['id'] ?>">View</a>
                        <a class="btn btn-sm btn-outline-danger" href="?delete=<?= (int)$cal['id'] ?>" onclick="return confirm('Delete this calendar?');">Delete</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body>
  </html>

