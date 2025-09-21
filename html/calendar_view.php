<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib_auth.php';
if (!isset($_SESSION['user_id'])) { auth_try_device_login(); }
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
require __DIR__.'/lib_ics.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$config = require __DIR__.'/config.php';
if (!empty($config['timezone'])) { @date_default_timezone_set((string)$config['timezone']); }
$pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
$uid = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT id, name, url FROM calendars WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$id, $uid]);
$cal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cal) { http_response_code(404); echo 'Calendar not found'; exit; }

$err = null; $events = [];
// Fetch ICS
$ics = '';
try {
    $ics = fetch_url((string)$cal['url']);
    $events = ics_parse_events($ics);
} catch (Throwable $e) {
    $err = 'Failed to fetch or parse calendar.';
}

// Filter to upcoming events (next 90 days)
$now = time();
$soon = $now + 90*24*3600;
$filtered = array_values(array_filter($events, function($e) use ($now,$soon){
    $ts = $e['start']['ts'] ?? null;
    return $ts !== null && $ts >= $now && $ts <= $soon;
}));

function fetch_url(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'calendar.lillard.app/1.0',
        ]);
        $data = curl_exec($ch);
        if ($data === false) throw new RuntimeException('cURL error: '.curl_error($ch));
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) throw new RuntimeException('HTTP '. $code);
        return (string)$data;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'follow_location' => 1, 'user_agent' => 'calendar.lillard.app/1.0']]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) throw new RuntimeException('HTTP fetch failed');
    return (string)$data;
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($cal['name']) ?> · Calendar</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>.standalone .a2hs-tip{display:none!important}</style>
  </head>
  <body class="bg-light">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
        <div class="ms-auto d-flex gap-2">
          <a class="btn btn-outline-primary" href="calendar_week.php?id=<?= (int)$cal['id'] ?>">Week View</a>
          <a class="btn btn-outline-secondary" href="calendars.php">Back</a>
          <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
      </div>
    </nav>
    <main class="container py-4">
      <h3 class="mb-3"><?= h($cal['name']) ?></h3>
      <p class="text-muted small">Source: <a href="<?= h($cal['url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($cal['url']) ?></a> · Timezone: <?= h((new DateTimeZone(date_default_timezone_get()))->getName()) ?> (<?= h((new DateTimeImmutable('now'))->format('T')) ?>)</p>
      <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php elseif (!$filtered): ?>
        <div class="alert alert-info">No upcoming events in the next 90 days.</div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach ($filtered as $ev): ?>
            <div class="list-group-item">
              <div class="d-flex justify-content-between">
                <div class="fw-semibold">
                  <?= h($ev['summary'] ?: '(No title)') ?>
                </div>
                <div class="text-nowrap text-muted">
                  <?= h(($ev['start']['display'] ?? '') . ($ev['end']['display'] ? ' – '.$ev['end']['display'] : '')) ?>
                </div>
              </div>
              <?php if (!empty($ev['location'])): ?>
                <div class="small text-muted">📍 <?= h($ev['location']) ?></div>
              <?php endif; ?>
              <?php if (!empty($ev['description'])): ?>
                <div class="small mt-1"><?= nl2br(h($ev['description'])) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
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
