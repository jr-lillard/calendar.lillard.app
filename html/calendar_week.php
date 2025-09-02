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
    // Optional holidays overlay
    if (!empty($config['holiday_ics'])) {
        try {
            $hics = fetch_url((string)$config['holiday_ics']);
            $hevents = ics_expand_events_in_range($hics, $weekStart->getTimestamp(), $weekEnd->getTimestamp(), $tz);
            // Tag holidays for styling
            foreach ($hevents as &$hv) { $hv['is_holiday'] = true; }
            unset($hv);
            $events = array_merge($events, $hevents);
        } catch (Throwable $e) { /* ignore holiday fetch errors */ }
    }
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
    // Treat 24 as 0 (midnight)
    $hm = $h % 24;
    $ampm = $hm >= 12 ? 'PM' : 'AM';
    $h12 = $hm % 12; if ($h12 === 0) $h12 = 12;
    if ($hm === 0) $ampm = 'AM';
    return $h12.' '.$ampm;
}

function parse_birthdate_from_description(?string $desc, DateTimeZone $tz): array {
    $desc = (string)$desc;
    if ($desc === '') return ['date' => null, 'year' => null];
    // ISO YYYY-MM-DD near keywords
    if (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{4})-(\d{2})-(\d{2})\b/i', $desc, $m)) {
        $y = (int)$m[2]; $mo = (int)$m[3]; $d = (int)$m[4];
        try { return ['date' => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y,$mo,$d), $tz), 'year' => $y]; } catch (Throwable $e) {}
    }
    // US MM/DD/YYYY near keywords
    if (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{1,2})\/(\d{1,2})\/(\d{4})\b/i', $desc, $m)) {
        $mo = (int)$m[2]; $d = (int)$m[3]; $y = (int)$m[4];
        try { return ['date' => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y,$mo,$d), $tz), 'year' => $y]; } catch (Throwable $e) {}
    }
    // Month name D, YYYY near keywords
    if (preg_match('/\b(born|birth|dob|b\.)[^A-Za-z]{0,10}((Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*)\s+(\d{1,2}),?\s+(\d{4})/i', $desc, $m)) {
        $mon = strtolower($m[2]); $d = (int)$m[4]; $y = (int)$m[5];
        $map = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'sept'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $mo = $map[substr($mon,0,4)] ?? ($map[substr($mon,0,3)] ?? null);
        if ($mo) {
            try { return ['date' => new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y,$mo,$d), $tz), 'year' => $y]; } catch (Throwable $e) {}
        }
    }
    // Fallback: just a year near keywords
    if (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{4})\b/i', $desc, $m)) {
        $y = (int)$m[2];
        if ($y >= 1900 && $y <= 3000) return ['date' => null, 'year' => $y];
    }
    return ['date' => null, 'year' => null];
}

function compute_age_for_day(?DateTimeImmutable $birthDate, ?int $birthYear, DateTimeImmutable $onDay): ?int {
    if ($birthDate instanceof DateTimeImmutable) {
        $y = (int)$onDay->format('Y');
        $m = (int)$onDay->format('n');
        $d = (int)$onDay->format('j');
        $by = (int)$birthDate->format('Y');
        $bm = (int)$birthDate->format('n');
        $bd = (int)$birthDate->format('j');
        $age = $y - $by;
        if ($m < $bm || ($m === $bm && $d < $bd)) $age--;
        return $age;
    }
    if (is_int($birthYear)) {
        return max(0, (int)$onDay->format('Y') - $birthYear);
    }
    return null;
}

function css_colors_from_hex(?string $hex): ?array {
    if (!$hex) return null;
    $hex = strtoupper($hex);
    if (!preg_match('/^#([0-9A-F]{6})$/', $hex, $m)) return null;
    $h = $m[1];
    $r = hexdec(substr($h,0,2));
    $g = hexdec(substr($h,2,2));
    $b = hexdec(substr($h,4,2));
    $bg = sprintf('rgba(%d,%d,%d,0.15)', $r,$g,$b);
    $bd = sprintf('rgba(%d,%d,%d,0.6)', $r,$g,$b);
    return ['bg'=>$bg,'bd'=>$bd];
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
      :root { --hour-height: 56px; --start-hour: 7; --end-hour: 23; }
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
        background-color: #fff;
        background-image: repeating-linear-gradient(
          to bottom,
          rgba(0,0,0,0.12) 0,
          rgba(0,0,0,0.12) 1px,
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
        background-color: #fff;
        background-image: repeating-linear-gradient(
          to bottom,
          rgba(0,0,0,0.12) 0,
          rgba(0,0,0,0.12) 1px,
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
      .event-title { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
      .hour-line { position: absolute; left: 0; right: 0; height: 0; border-top: 1px solid rgba(0,0,0,0.25); }
      .all-day-row { display: flex; flex-direction: column; gap: .25rem; margin-top: .25rem; }
      .all-day-block { background: rgba(25,135,84,0.15); border: 1px solid rgba(25,135,84,0.4); border-radius: .25rem; padding: .25rem .4rem; font-size: .825rem; }
      .all-day-title { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }

      /* Print styles: landscape, start at grid, single page fit */
      @media print {
        /* Target Letter landscape; restore standard margins */
        @page { size: 11in 8.5in; margin: 0.4in; }
        html, body { width: 11in; height: 8.5in; }
        * { box-shadow: none !important; }
        body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        /* Force white backgrounds and remove any background images */
        html, body, .week-grid, .day-card, .axis-content, .day-content, .day-col, .time-axis, .day-body, .card, .card-body {
          background-color: #fff !important; background-image: none !important;
        }
        /* Hide chrome above the grid */
        .navbar, .week-main > .d-flex, .alert { display: none !important; }
        .week-main { padding: 0 !important; width: var(--grid-w) !important; margin: 0 auto !important; }
        .week-scroll { overflow: visible !important; height: auto !important; }
        /* Compute exact fit: page width/height minus margins */
        :root {
          --page-w: 11in; --page-h: 8.5in; --m: 0.4in;
          --grid-w: calc(var(--page-w) - 2 * var(--m));
          --grid-h: calc(var(--page-h) - 2 * var(--m));
          --print-day-header: 1.1in;
          --hour-height: calc((var(--grid-h) - var(--print-day-header)) / (var(--end-hour) - var(--start-hour)));
        }
        .week-grid {
          grid-template-columns: 40px repeat(7, minmax(1px, 1fr));
          width: var(--grid-w) !important;
          height: var(--grid-h) !important;
          page-break-inside: avoid;
        }
        .axis-header, .day-header { height: var(--print-day-header) !important; overflow: hidden; }
        .container-fluid, .week-main, .week-scroll, .week-grid { padding: 0 !important; margin: 0 !important; }
        .card, .day-card { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
        .hour-line { border-top-color: rgba(0,0,0,0.6) !important; }
        .day-col { min-width: 0; }
        /* Remove tinted event backgrounds for print */
        .event-block, .all-day-block { background: #fff !important; border-color: #000 !important; }
        .axis-hour { font-size: 0.65rem; }
        .all-day-block { font-size: .65rem; line-height: 1.1; }
        .all-day-title { font-size: .65rem; line-height: 1.1; }
        .event-block { padding: .16rem .24rem; font-size: .7rem; }
      }
    </style>
  </head>
  <body class="bg-light min-vh-100">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
        <div class="ms-auto d-flex gap-2">
          <a class="btn btn-outline-secondary" href="calendars.php">Back</a>
          <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
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
              <?php $startHour = 7; $endHour = 23; for ($h=$startHour; $h<=$endHour; $h++): ?>
                <div class="hour-line" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)));"></div>
              <?php endfor; ?>
              <?php $startHour = 7; $endHour = 23; for ($h=$startHour; $h<=$endHour; $h++): ?>
                <div class="axis-hour" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)) + 1px);">
                  <?= h(fmt_hour_label($h)) ?>
                </div>
              <?php endfor; ?>
            </div>
          </div>
          <?php
            // Prepare day structures with all-day vs timed events and computed positions
            $startHour = 7; $endHour = 23;
            foreach ($days as $ymd => $evs):
              $d = DateTimeImmutable::createFromFormat('Y-m-d', $ymd, $tz);
              $dayStartTs = $d->getTimestamp();
              $allDay = []; $timed = [];
              foreach ($evs as $ev) {
                $isAll = !empty($ev['all_day']);
                if ($isAll) { $allDay[] = $ev; continue; }
                $st = $ev['start']['ts'] ?? null; $et = $ev['end']['ts'] ?? null;
                if ($st === null) continue;
                // Compute minutes since local midnight using local H:i
                $stDT = (new DateTimeImmutable('@'.$st))->setTimezone($tz);
                $etDT = $et !== null ? (new DateTimeImmutable('@'.$et))->setTimezone($tz) : $stDT->modify('+1 hour');
                $windowStartMin = $startHour * 60; $windowEndMin = $endHour * 60;
                $startMin = (int)$stDT->format('G') * 60 + (int)$stDT->format('i');
                $endMin = (int)$etDT->format('G') * 60 + (int)$etDT->format('i');
                if ($endMin <= $windowStartMin || $startMin >= $windowEndMin) {
                  continue; // completely outside visible window
                }
                $clipStart = max($startMin, $windowStartMin);
                $clipEnd = min(max($endMin, $clipStart + 15), $windowEndMin);
                $timed[] = [
                  'ev' => $ev,
                  'top_min' => ($clipStart - $windowStartMin),
                  'height_min' => max(6, ($clipEnd - $clipStart)),
                  'label_start' => $stDT->format('g:ia'),
                  'label_end' => $etDT->format('g:ia'),
                  'start_min' => $startMin,
                  'end_min' => $endMin,
                ];
              }
              // Compute overlap columns for timed events
              usort($timed, function($a,$b){ return $a['start_min'] <=> $b['start_min'] ?: $a['end_min'] <=> $b['end_min']; });
              $clusterStart = 0; $clusterEnd = -1; $clusterItems = [];
              $finalTimed = [];
              $assignCluster = function(array $cluster) use (&$finalTimed) {
                  if (!$cluster) return;
                  // Column assignment within cluster
                  // active columns: array colIndex => end_min
                  $active = [];
                  $maxCols = 0;
                  foreach ($cluster as $idx => $t) {
                      // free columns whose end <= start
                      foreach ($active as $ci => $eend) { if ($eend <= $t['start_min']) unset($active[$ci]); }
                      // find first free col
                      $col = 0; while (array_key_exists($col, $active)) { $col++; }
                      $t['col'] = $col; $active[$col] = $t['end_min'];
                      $maxCols = max($maxCols, $col+1);
                      $cluster[$idx] = $t;
                      $finalTimed[] = $t; // temp push; we'll update widths next
                  }
                  // Update widths for last pushed items of this cluster
                  $n = count($cluster);
                  for ($i = count($finalTimed)-$n; $i < count($finalTimed); $i++) {
                      if ($i < 0) continue;
                      $finalTimed[$i]['cols'] = $maxCols;
                  }
              };
              foreach ($timed as $t) {
                  if ($t['start_min'] >= $clusterEnd) {
                      // close previous cluster
                      $assignCluster($clusterItems);
                      $clusterItems = []; $clusterEnd = -1;
                  }
                  $clusterItems[] = $t;
                  $clusterEnd = max($clusterEnd, $t['end_min']);
              }
              $assignCluster($clusterItems);
              $timed = $finalTimed;
          ?>
            <div class="day-col">
              <div class="card shadow-sm day-card">
                <div class="card-header bg-white day-header">
                  <div class="fw-semibold mb-1"><?= h($d->format('D M j')) ?></div>
                  <div class="all-day-row">
                    <?php foreach ($allDay as $ev): $clr = css_colors_from_hex($ev['color'] ?? null); ?>
                      <?php 
                        $ageText = '';
                        $isHoliday = !empty($ev['is_holiday']);
                        $summaryLC = strtolower((string)($ev['summary'] ?? ''));
                        if (!$isHoliday && (str_contains($summaryLC, 'birthday') || str_contains($summaryLC, "b-day") || str_contains($summaryLC, "bday"))) {
                          $bd = parse_birthdate_from_description($ev['description'] ?? '', $tz);
                          $age = compute_age_for_day($bd['date'], $bd['year'], $d);
                          if (is_int($age) && $age >= 0) { $ageText = ' · ' . $age . ' yrs'; }
                        }
                      ?>
                      <div class="all-day-block" title="<?= h(($ev['summary'] ?? '') . ($ageText ? ' '.$ageText : '')) ?>" style="<?= $clr ? 'background-color: '.$clr['bg'].'; border-color: '.$clr['bd'].';' : '' ?>">
                        <span class="fw-semibold all-day-title"><?= h($ev['summary'] ?: '(No title)') ?><?= h($ageText) ?></span>
                        <?php if (!empty($ev['location'])): ?>
                          <span class="text-muted">· <?= h($ev['location']) ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="day-body">
                  <div class="day-content">
                    <?php $startHour = 7; $endHour = 23; for ($h=$startHour; $h<=$endHour; $h++): ?>
                      <div class="hour-line" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)));"></div>
                    <?php endfor; ?>
                    <?php foreach ($timed as $t): $ev = $t['ev']; $cols = max(1, (int)($t['cols'] ?? 1)); $col = (int)($t['col'] ?? 0); $clr = css_colors_from_hex($ev['color'] ?? null); ?>
                      <div class="event-block" style="
                        top: calc(<?= (int)$t['top_min'] ?> * var(--hour-height) / 60);
                        height: calc(<?= (int)$t['height_min'] ?> * var(--hour-height) / 60);
                        left: calc((100% / <?= $cols ?>) * <?= $col ?> + 4px);
                        width: calc((100% / <?= $cols ?>) - 8px);
                        <?= $clr ? 'background-color: '.$clr['bg'].'; border-color: '.$clr['bd'].';' : '' ?>
                      " title="<?= h(($ev['summary'] ?? '') . ' — ' . $t['label_start'] . '–' . $t['label_end']) ?>">
                        <div class="small text-muted"><?= h($t['label_start']) ?> – <?= h($t['label_end']) ?></div>
                        <div class="fw-semibold small event-title"><?= h($ev['summary'] ?: '(No title)') ?></div>
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
        const startHour = 7, endHour = 23;
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
