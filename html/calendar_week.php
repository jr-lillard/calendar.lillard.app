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
function fmt_hour_label(int $h): string {
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
    return $h12.' '.$ampm;
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
      :root { --hour-height: 56px; --start-hour: 6; --end-hour: 24; }
      html, body { height: 100%; }
      body { display: flex; flex-direction: column; }
      main { flex: 1 1 auto; min-height: 0; }
      .week-main { display: flex; flex-direction: column; min-height: 0; }

      .week-scroll { overflow: auto; flex: 1 1 auto; min-height: 0; }
      .week-grid {
        display: grid;
        grid-template-columns: 60px repeat(7, minmax(180px, 1fr));
        gap: 0;
        align-items: stretch;
        height: 100%;
      }
      .time-axis { position: relative; display: grid; grid-template-rows: auto 1fr; }
      .axis-header { background: #fff; border-bottom: 1px solid rgba(0,0,0,0.075); }
      .axis-content {
        position: relative; height: calc(var(--hour-height) * (var(--end-hour) - var(--start-hour)));
        background: repeating-linear-gradient(to bottom,
          rgba(0,0,0,0.06) 0,
          rgba(0,0,0,0.06) 1px,
          transparent 1px,
          transparent var(--hour-height)
        );
      }
      .axis-hour { position: absolute; left: 4px; font-size: 0.75rem; color: #6c757d; transform: translateY(-50%); white-space: nowrap; }

      .day-col { min-width: 180px; }
      .day-card { height: 100%; display: grid; grid-template-rows: auto 1fr; }
      .day-header { position: sticky; top: 0; z-index: 1; background: #fff; }
      .day-body { position: relative; height: 100%; }
      .day-content {
        position: relative; height: calc(var(--hour-height) * (var(--end-hour) - var(--start-hour)));
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
      .all-day-row { display: flex; flex-direction: column; gap: .25rem; margin-top: .25rem; }
      .all-day-block { background: rgba(25,135,84,0.15); border: 1px solid rgba(25,135,84,0.4); border-radius: .25rem; padding: .25rem .4rem; font-size: .825rem; }
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
    <main class="container-fluid py-4 week-main">
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
            <div class="axis-header"></div>
            <div class="axis-content">
              <?php $startHour = 6; $endHour = 24; for ($h=$startHour; $h<=$endHour; $h++): ?>
                <div class="axis-hour" style="top: calc(<?= (int)($h - $startHour) ?> * var(--hour-height));">
                  <?= h(fmt_hour_label($h)) ?>
                </div>
              <?php endfor; ?>
            </div>
          </div>
          <?php
            // Prepare day structures with all-day vs timed events and computed positions
            $startHour = 6; $endHour = 24;
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
                // Clamp to visible window (6:00–24:00)
                $windowStartMin = $startHour * 60; $windowEndMin = $endHour * 60;
                $startMin = (int)floor(($stLocal - $dayStartTs)/60);
                $endMin = (int)ceil(($etLocal - $dayStartTs)/60);
                if ($endMin <= $windowStartMin || $startMin >= $windowEndMin) {
                  continue; // completely outside visible window
                }
                $clipStart = max($startMin, $windowStartMin);
                $clipEnd = min(max($endMin, $clipStart + 15), $windowEndMin);
                $timed[] = [
                  'ev' => $ev,
                  'top_min' => ($clipStart - $windowStartMin),
                  'height_min' => max(6, ($clipEnd - $clipStart)),
                  'label_start' => fmt_time($stLocal, $tz),
                  'label_end' => fmt_time($etLocal, $tz),
                ];
              }
          ?>
            <div class="day-col">
              <div class="card shadow-sm day-card">
                <div class="card-header bg-white day-header">
                  <div class="fw-semibold mb-1"><?= h($d->format('D M j')) ?></div>
                  <div class="all-day-row">
                    <?php foreach ($allDay as $ev): ?>
                      <div class="all-day-block">
                        <span class="me-1">All day</span>
                        <span class="fw-semibold"><?= h($ev['summary'] ?: '(No title)') ?></span>
                        <?php if (!empty($ev['location'])): ?>
                          <span class="text-muted">· <?= h($ev['location']) ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="day-body">
                  <div class="day-content">
                    <?php foreach ($timed as $t): $ev = $t['ev']; ?>
                      <div class="event-block" style="top: calc(<?= (int)$t['top_min'] ?> * var(--hour-height) / 60); height: calc(<?= (int)$t['height_min'] ?> * var(--hour-height) / 60);">
                        <div class="small text-muted"><?= h($t['label_start']) ?> – <?= h($t['label_end']) ?></div>
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
    <script>
      (function(){
        const startHour = 6, endHour = 24;
        function layout() {
          const headers = Array.from(document.querySelectorAll('.day-header'));
          const axisHeader = document.querySelector('.axis-header');
          const scroll = document.querySelector('.week-scroll');
          if (!scroll || headers.length === 0) return;
          // Equalize header heights across columns
          let maxH = 0;
          headers.forEach(h => { h.style.height = ''; maxH = Math.max(maxH, h.offsetHeight); });
          headers.forEach(h => { h.style.height = maxH + 'px'; });
          if (axisHeader) axisHeader.style.height = maxH + 'px';
          // Compute hour height to fill remaining space
          const avail = scroll.clientHeight - maxH; // px for hours grid
          const hours = Math.max(1, endHour - startHour);
          const perHour = Math.max(24, Math.floor(avail / hours));
          document.documentElement.style.setProperty('--hour-height', perHour + 'px');
        }
        window.addEventListener('resize', layout);
        document.addEventListener('DOMContentLoaded', layout);
        // Also run once after a tick to account for fonts
        setTimeout(layout, 50);
      })();
    </script>
  </body>
  </html>
