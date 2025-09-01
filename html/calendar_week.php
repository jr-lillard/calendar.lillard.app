<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: index.php'); exit; }
require __DIR__.'/lib_ics.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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

$config = require __DIR__.'/config.php';
if (!empty($config['timezone'])) { @date_default_timezone_set((string)$config['timezone']); }
$pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
$uid = (int)$_SESSION['user_id'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT id, name, url FROM calendars WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$id, $uid]);
$cal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cal) { http_response_code(404); echo 'Calendar not found'; exit; }

// Determine week start (Sunday) based on ?date=YYYY-MM-DD
$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('now', $tz);
$dateParam = isset($_GET['date']) ? (string)$_GET['date'] : '';
if ($dateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $base = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz) ?: $today;
} else {
    $base = $today;
}
// Find Sunday of this week
$dow = (int)$base->format('w'); // 0=Sun ... 6=Sat
$weekStart = $base->modify('-'.$dow.' days')->setTime(0,0,0);
$weekEnd = $weekStart->modify('+6 days')->setTime(23,59,59);

// Prev/Next week dates
$prevDate = $weekStart->modify('-7 days')->format('Y-m-d');
$nextDate = $weekStart->modify('+7 days')->format('Y-m-d');

// Fetch and parse ICS
$events = [];
$err = null;
try {
    $ics = fetch_url((string)$cal['url']);
    $events = ics_expand_events_in_range($ics, $weekStart->getTimestamp(), $weekEnd->getTimestamp(), $tz);
} catch (Throwable $e) {
    $err = 'Failed to fetch or parse calendar.';
}

// Bucket events by day (use event start time)
$days = [];
for ($i=0; $i<7; $i++) {
    $d = $weekStart->modify("+{$i} days");
    $days[$d->format('Y-m-d')] = [];
}
foreach ($events as $ev) {
    $ts = $ev['start']['ts'] ?? null;
    if ($ts === null) continue;
    $key = (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d');
    if (!isset($days[$key])) $days[$key] = [];
    $days[$key][] = $ev;
}
foreach ($days as &$lst) {
    usort($lst, function($a,$b){ return ($a['start']['ts'] ?? 0) <=> ($b['start']['ts'] ?? 0); });
}
unset($lst);

function fmt_time(?int $ts, DateTimeZone $tz): string {
    if ($ts === null) return '';
    return (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('g:ia');
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($cal['name']) ?> · Week View</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      :root { --hour-height: 56px; }
      html, body { height: 100%; }
      body { display: flex; flex-direction: column; }
      main { flex: 1 1 auto; min-height: 0; }

      .week-scroll { overflow: auto; }
      .week-grid {
        display: grid;
        grid-template-columns: 60px repeat(7, minmax(180px, 1fr));
        gap: 0;
        align-items: stretch;
        height: 100%;
      }
      .time-axis { position: relative; }
      .axis-content {
        position: relative; height: calc(var(--hour-height) * 24);
        background: repeating-linear-gradient(to bottom,
          rgba(0,0,0,0.06) 0,
          rgba(0,0,0,0.06) 1px,
          transparent 1px,
          transparent var(--hour-height)
        );
      }
      .axis-hour { position: absolute; left: 4px; font-size: 0.75rem; color: #6c757d; transform: translateY(-0.5em); }

      .day-col { min-width: 180px; }
      .day-card { height: 100%; display: grid; grid-template-rows: auto 1fr; }
      .day-header { position: sticky; top: 0; z-index: 1; background: #fff; }
      .day-body { position: relative; height: 100%; }
      .day-content {
        position: relative; height: calc(var(--hour-height) * 24);
        background: repeating-linear-gradient(to bottom,
          rgba(0,0,0,0.06) 0,
          rgba(0,0,0,0.06) 1px,
          transparent 1px,
          transparent var(--hour-height)
        );
      }
      .event-block {
        position: absolute; left: 6px; right: 6px;
        background: rgba(13,110,253,0.15);
        border: 1px solid rgba(13,110,253,0.5);
        border-radius: .25rem;
        padding: .25rem .4rem;
        overflow: hidden;
      }
      .all-day-badge { display: inline-block; margin-right: .25rem; }
    </style>
  </head>
  <body class="bg-light min-vh-100">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
        <div class="ms-auto d-flex gap-2">
          <a class="btn btn-outline-secondary" href="calendars.php">Back</a>
          <a class="btn btn-outline-secondary" href="logout.php">Log out</a>
        </div>
      </div>
    </nav>
    <main class="container-fluid py-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h4 class="mb-0"><?= h($cal['name']) ?> · Week of <?= h($weekStart->format('M j, Y')) ?></h4>
          <div class="text-muted small">Timezone: <?= h($tz->getName()) ?> (<?= h((new DateTimeImmutable('now', $tz))->format('T')) ?>)</div>
        </div>
        <div class="btn-group">
          <a class="btn btn-outline-primary" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($prevDate) ?>">← Previous</a>
          <a class="btn btn-outline-secondary" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($today->format('Y-m-d')) ?>">This Week</a>
          <a class="btn btn-outline-primary" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($nextDate) ?>">Next →</a>
        </div>
      </div>

      <?php if ($err): ?>
        <div class="alert alert-danger"><?= h($err) ?></div>
      <?php endif; ?>

      <div class="week-scroll">
        <div class="week-grid">
          <div class="time-axis">
            <div class="axis-content">
              <?php for ($h=0; $h<24; $h++): ?>
                <div class="axis-hour" style="top: calc(<?= (int)$h ?> * var(--hour-height));">
                  <?= h(str_pad((string)$h, 2, '0', STR_PAD_LEFT)) ?>:00
                </div>
              <?php endfor; ?>
            </div>
          </div>
          <?php
            // Prepare day structures with all-day vs timed events and computed positions
            $hourPx = 56; $pxPerMin = $hourPx/60.0;
            foreach ($days as $ymd => $evs):
              $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
              $dayStartTs = $d->getTimestamp();
              $allDay = []; $timed = [];
              foreach ($evs as $ev) {
                $isAll = !empty($ev['all_day']);
                if ($isAll) { $allDay[] = $ev; continue; }
                $st = $ev['start']['ts'] ?? null; $et = $ev['end']['ts'] ?? null;
                if ($st === null) continue;
                $stLocal = (new DateTimeImmutable('@'.$st))->setTimezone($tz)->getTimestamp();
                $etLocal = $et !== null ? (new DateTimeImmutable('@'.$et))->setTimezone($tz)->getTimestamp() : $stLocal + 3600;
                // Clamp to day bounds
                $startMin = max(0, (int)floor(($stLocal - $dayStartTs)/60));
                $endMin = min(24*60, max($startMin+15, (int)ceil(($etLocal - $dayStartTs)/60)));
                $timed[] = [
                  'ev' => $ev,
                  'top' => (int)round($startMin * $pxPerMin),
                  'height' => (int)max(6, round(($endMin - $startMin) * $pxPerMin)),
                  'label_time' => fmt_time($stLocal, $tz),
                ];
              }
          ?>
            <div class="day-col">
              <div class="card shadow-sm day-card">
                <div class="card-header bg-white day-header">
                  <div class="fw-semibold d-flex flex-wrap align-items-center gap-1">
                    <span><?= h($d->format('D M j')) ?></span>
                    <?php foreach ($allDay as $ev): ?>
                      <span class="badge text-bg-primary all-day-badge"><?= h($ev['summary'] ?: 'All day') ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="day-body">
                  <div class="day-content">
                    <?php foreach ($timed as $t): $ev = $t['ev']; ?>
                      <div class="event-block" style="top: <?= (int)$t['top'] ?>px; height: <?= (int)$t['height'] ?>px;">
                        <div class="small text-muted"><?= h($t['label_time']) ?></div>
                        <div class="fw-semibold small text-truncate"><?= h($ev['summary'] ?: '(No title)') ?></div>
                        <?php if (!empty($ev['location'])): ?>
                          <div class="small text-muted text-truncate"><?= h($ev['location']) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  </body>
  </html>
