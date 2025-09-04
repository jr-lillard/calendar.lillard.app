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
// Enable on-screen print preview mode with ?print=1
$printMode = isset($_GET['print']) && $_GET['print'] !== '0';
if ($printMode) {
    // Prevent browser caching so preview reflects the latest sizing tweaks
    if (!headers_sent()) {
        header('Cache-Control: no-store, max-age=0, must-revalidate');
        header('Pragma: no-cache');
    }
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
      :root { --hour-height: 56px; --start-hour: 7; --end-hour: 24; --label-offset: 0px; --header-height: auto; --print-safety: -2.50in; --print-width-safety: 0in; }
      /* Allow dynamic tuning of print safety via ?fudge (inches) */
      <?php
        $fudge = null;
        if (isset($_GET['fudge'])) {
            $f = (float)$_GET['fudge'];
            // Allow negative to make the calendar taller; no cap
            $fudge = $f;
        }
        if ($fudge !== null) {
            // Emit a CSS override for the print safety variable
            echo ':root{ --print-safety: '.number_format($fudge, 3).'in; }';
        }
        // Optional width fudge via ?wfudge (inches). Positive reduces width; negative increases.
        if (isset($_GET['wfudge'])) {
            $wf = (float)$_GET['wfudge'];
            echo ':root{ --print-width-safety: '.number_format($wf, 3).'in; }';
        }
      ?>
      html, body { height: 100%; }
      body { display: flex; flex-direction: column; }
      main { flex: 1 1 auto; min-height: 0; }
      .week-main { display: flex; flex-direction: column; min-height: 0; }

      .week-scroll { overflow: auto; flex: 1 1 auto; min-height: 0; }
      /* Grid container (inside print frame) */
      .week-grid {
        display: grid;
        grid-template-columns: 60px repeat(7, minmax(180px, 1fr));
        gap: 0;
        align-items: stretch;
        height: 100%; /* fill the frame */
      }

      /* Frame that carries the printable border on all four sides */
      .print-frame { width: 100%; }
      .time-axis { position: relative; display: grid; grid-template-rows: auto 1fr; }
      .axis-header { background: #fff; border-bottom: 1px solid rgba(0,0,0,0.075); height: var(--header-height); }
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
      .axis-hour { position: absolute; left: 0; right: 6px; text-align: right !important; font-size: 0.75rem; color: #6c757d; transform: translateY(-50%); white-space: nowrap; }
      /* In print preview and print, do not vertically center labels */
      .print-preview .axis-hour { transform: none; }
      @media print { .axis-hour { transform: none; } }

      .day-col { min-width: 180px; }
      .day-card { height: 100%; display: grid; grid-template-rows: auto 1fr; border-radius: 0 !important; }
      .day-header { position: sticky; top: 0; z-index: 1; background: #fff; height: var(--header-height); border-radius: 0 !important; }
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
      .all-day-row { display: flex; flex-direction: column; gap: .25rem; margin-top: .15rem; padding-bottom: .6rem; }
      .all-day-block { background: rgba(25,135,84,0.15); border: 1px solid rgba(25,135,84,0.4); border-radius: .25rem; padding: .25rem .4rem; font-size: .825rem; }
      .all-day-title { white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
      /* Hide explicit hour lines on screen (use background grid); show them for print/preview */
      .axis-content .hour-line, .day-content .hour-line { display: none; }
      .print-preview .axis-content .hour-line, .print-preview .day-content .hour-line { display: block !important; }
      /* Apply width fudge in on‑screen print preview so it mirrors paper */
      .print-preview .print-frame { width: calc(100% - var(--print-width-safety)); margin-left: 0 !important; margin-right: auto !important; border: 2px solid #000; box-sizing: border-box; }
      @media print { .axis-content .hour-line, .day-content .hour-line { display: block !important; } }

      /* Print & on-screen print preview: normal flow; inch-accurate sizing for print */
      @media print {
        @page { size: 11in 8.5in landscape; margin: 0.35in; }
        /* Compute per-hour height from printable page height (Letter landscape) */
        :root {
          /* Printable height = page height (8.5in) - top/bottom margins (0.7in)
             Subtract 2px for outer grid border and a tiny safety fudge to avoid spill */
          /* use global --print-safety (tunable) */
          --print-content-h: calc(8.5in - 0.7in - 2px - var(--print-safety));
          /* Fixed header height in print to keep all day headers equal */
          /* Slightly taller header to allow breathing room below all‑day blocks */
          --header-height: 0.66in;
          --hour-height: calc((var(--print-content-h) - var(--header-height)) / 17);
          --label-offset: 4px;
        }
        /* Eliminate any padding that could push to a second page */
        .container-fluid, .week-main { padding: 0 !important; margin: 0 !important; }
        /* Explicit heights to guarantee a single page */
        .week-grid { height: var(--print-content-h) !important; }
        .day-card { height: 100% !important; }
        .day-body { height: calc(var(--print-content-h) - var(--header-height)) !important; }
        .axis-content, .day-content { height: 100% !important; }
        /* Neutralize sticky/overflow in print to prevent layout drift */
        .day-header, .axis-header { position: static !important; overflow: hidden !important; }
        /* Remove Bootstrap card borders that add extra height in print */
        .card, .day-card, .card-header { border: 0 !important; border-radius: 0 !important; }
        /* Ensure axis header has no extra border in print */
        .axis-header { border: 0 !important; }
        /* Draw a reliable frame border in print; put it on a wrapper so it isn't clipped */
        .print-frame {
          width: calc(100% - var(--print-width-safety)) !important;
          box-sizing: border-box !important;
          border: 2px solid #000 !important;
          position: relative !important;
          margin-left: 0 !important;
          margin-right: auto !important;
          height: var(--print-content-h) !important;
        }
        /* Grid fills the frame in print */
        .week-grid {
          height: 100% !important;
          width: 100% !important;
        }
      }
      /* (intentionally no combined @media; preview rules use .print-preview, print rules use @media print) */
      /* Shared rules for real print and on-screen "print-preview" mode */
      @media print { .print-only { display: block !important; } }
      .print-preview .print-only { display: block !important; }
      /* Remove rounded corners on containers (keep on events) */
      .week-grid, .day-card, .card, .card-header, .day-header { border-radius: 0 !important; }
      /* In on-screen print preview, put labels just below the hour line */
      .print-preview { --label-offset: 4px; }
      /* Keep navbar visible in on-screen preview so Fit buttons are usable */
      .print-preview .week-main > .d-flex, .print-preview .alert { display: none !important; }
      /* Hide navbar and page header only for the real printed page */
      @media print { .navbar, .week-main > .d-flex, .alert { display: none !important; } }
      /* Mirror print sizing in on-screen preview using CSS inches */
      body.print-preview {
        /* mirror print sizing (uses same --print-safety as :root) */
        --print-content-h: calc(8.5in - 0.7in - 2px - var(--print-safety));
        --header-height: 0.60in;
        --hour-height: calc((var(--print-content-h) - var(--header-height)) / 17);
      }
      .print-preview .container-fluid, .print-preview .week-main { padding: 0 !important; margin: 0 !important; }
      .print-preview .print-frame { height: var(--print-content-h) !important; }
      .print-preview .week-grid { height: 100% !important; }
      .print-preview .day-card { height: 100% !important; }
      .print-preview .day-body { height: calc(var(--print-content-h) - var(--header-height)) !important; }
      .print-preview .axis-content, .print-preview .day-content { height: 100% !important; }
      @media print {
        html, body { margin: 0 !important; padding: 0 !important; }
      }
      @media print, screen {
        .print-preview .container-fluid { padding: 0 !important; margin: 0 !important; }
        @media print { .container-fluid { padding: 0 !important; margin: 0 !important; } }
        /* Force white backgrounds and remove any background images */
        .print-preview html, .print-preview body,
        .print-preview .week-grid, .print-preview .day-card, .print-preview .axis-content, .print-preview .day-content,
        .print-preview .day-col, .print-preview .time-axis, .print-preview .day-body, .print-preview .card, .print-preview .card-body {
          background-color: #fff !important; background-image: none !important;
        }
        @media print {
          html, body, .week-grid, .day-card, .axis-content, .day-content, .day-col, .time-axis, .day-body, .card, .card-body {
            background-color: #fff !important; background-image: none !important;
          }
        }
        /* In preview, fix header height so all days match; use same inch-based sizing as print */
        /* (no override of --print-content-h here to avoid inconsistencies) */
        .print-preview .day-header, .print-preview .axis-header { position: static; overflow: hidden; }
        .print-preview .card { box-shadow: none !important; }
        /* Allow normal flow (no fixed positioning) */
        body.print-preview { min-height: auto !important; height: auto !important; }
        .print-preview .week-main, .print-preview .week-scroll { position: static !important; overflow: visible !important; height: auto !important; }
        @media print { .week-main, .week-scroll { position: static !important; overflow: visible !important; height: auto !important; } }
        /* Remove card shadows/borders that can appear as shading at print */
        .print-preview .card, .print-preview .day-card { box-shadow: none !important; }
        @media print { .card, .day-card { box-shadow: none !important; } }
        /* Border around entire grid */
      /* Draw border on the print frame (not the grid) so it encloses headers and columns */
      .print-preview .week-grid { border: 0 !important; box-sizing: border-box !important; position: relative !important; }
      /* Remove any preview pseudo edges */
      .print-preview .week-grid::after { content: none !important; }
      @media print { .week-grid { border: 0 !important; box-sizing: border-box !important; break-inside: avoid; page-break-inside: avoid; } }
      /* Allow manual width adjustment with --print-width-safety (inches) on the frame; grid stays 100% */
      .print-preview .week-grid { width: 100% !important; }
      @media print { .week-grid { width: 100% !important; } }
        /* Vertical separators between days and axis */
        .print-preview .time-axis { border-right: 1px solid #000 !important; }
        .print-preview .week-grid .day-col + .day-col .day-card { border-left: 1px solid #000 !important; }
        @media print {
          .time-axis { border-right: 1px solid #000 !important; }
          .week-grid .day-col + .day-col .day-card { border-left: 1px solid #000 !important; }
        }
        /* Ensure hour lines are visible */
        .print-preview .axis-content .hour-line, .print-preview .day-content .hour-line { display: block !important; }
        @media print { .axis-content .hour-line, .day-content .hour-line { display: block !important; } }
        /* Remove tinted event backgrounds for print */
        .print-preview .event-block, .print-preview .all-day-block { background: #fff !important; border-color: #000 !important; }
        /* Extra spacing below all‑day blocks in preview/print */
        .print-preview .all-day-row { padding-bottom: 0.30in !important; }
        @media print { .all-day-row { padding-bottom: 0.30in !important; } }
        @media print { .event-block, .all-day-block { background: #fff !important; border-color: #000 !important; } }
        /* Right-align time labels; all labels sit just below their hour line */
        .print-preview .axis-hour { font-size: 0.65rem; transform: none !important; left: auto !important; right: 6px !important; text-align: right !important; }
        @media print { .axis-hour { font-size: 0.65rem; transform: none !important; left: auto !important; right: 6px !important; text-align: right !important; } }
        /* No special transform for last label; keep it below the 11 PM line */
        /* Smaller text to avoid clipping */
        .print-preview .all-day-block, .print-preview .all-day-title { font-size: .65rem; line-height: 1.1; }
        .print-preview .event-block { padding: .16rem .24rem; font-size: .7rem; }
        @media print { .all-day-block, .all-day-title { font-size: .65rem; line-height: 1.1; } }
        @media print { .event-block { padding: .16rem .24rem; font-size: .7rem; } }
      }
    </style>
  </head>
  <body class="bg-light min-vh-100<?= $printMode ? ' print-preview' : '' ?>">
    <nav class="navbar navbar-expand navbar-light bg-white border-bottom shadow-sm">
      <div class="container-fluid">
        <a class="navbar-brand fw-semibold" href="dashboard.php">Calendar</a>
        <?php
          // Show a small build tag to verify live version (preview/screen only; hidden in print)
          $build = @trim((string)@shell_exec('git -C '.escapeshellarg(dirname(__DIR__)).' rev-parse --short HEAD 2>/dev/null'));
          if ($build !== ''): ?>
            <span class="badge rounded-pill text-bg-light ms-2 d-none d-sm-inline">Build <?= h($build) ?></span>
        <?php endif; ?>
        <div class="ms-auto d-flex gap-2">
          <a class="btn btn-outline-secondary" href="calendars.php">Back</a>
          <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
          <?php 
            $fudgeParam = $fudge !== null ? $fudge : -2.50; 
            $prevFudge = $fudgeParam - 0.01; 
            $nextFudge = $fudgeParam + 0.01;
            $wfParam = isset($_GET['wfudge']) ? (float)$_GET['wfudge'] : 0.00;
            $prevWF = $wfParam - 0.01; 
            $nextWF = $wfParam + 0.01;
          ?>
          <a class="btn btn-outline-secondary" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($weekStart->format('Y-m-d')) ?>&print=1&fudge=<?= number_format($fudgeParam,2) ?>&wfudge=<?= number_format($wfParam,2) ?>">Preview</a>
          <?php if ($printMode): ?>
            <div class="btn-group me-2" role="group" aria-label="Fit height">
              <a class="btn btn-outline-primary" id="fitMinus" data-fit="-0.01" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($weekStart->format('Y-m-d')) ?>&print=1&fudge=<?= number_format($prevFudge,2) ?>&wfudge=<?= number_format($wfParam,2) ?>" title="Taller (negative)">Fit −</a>
              <a class="btn btn-outline-primary" id="fitPlus" data-fit="+0.01" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($weekStart->format('Y-m-d')) ?>&print=1&fudge=<?= number_format($nextFudge,2) ?>&wfudge=<?= number_format($wfParam,2) ?>" title="Shorter (positive)">Fit +</a>
              <span class="ms-2 align-self-center small text-muted" id="fitValue" aria-live="polite"></span>
            </div>
            <div class="btn-group" role="group" aria-label="Fit width">
              <a class="btn btn-outline-primary" id="fitWMinus" data-fitw="-0.01" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($weekStart->format('Y-m-d')) ?>&print=1&fudge=<?= number_format($fudgeParam,2) ?>&wfudge=<?= number_format($prevWF,2) ?>" title="Wider (negative)">FitW −</a>
              <a class="btn btn-outline-primary" id="fitWPlus" data-fitw="+0.01" href="?id=<?= (int)$cal['id'] ?>&date=<?= h($weekStart->format('Y-m-d')) ?>&print=1&fudge=<?= number_format($fudgeParam,2) ?>&wfudge=<?= number_format($nextWF,2) ?>" title="Narrower (positive)">FitW +</a>
              <span class="ms-2 align-self-center small text-muted" id="fitWValue" aria-live="polite"></span>
            </div>
          <?php endif; ?>
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
        <div class="print-frame">
          <div class="week-grid">
            <div class="time-axis">
            <div class="axis-header"></div>
            <div class="axis-content">
              <?php $startHour = 7; $endHour = 24; for ($h=$startHour; $h<$endHour; $h++): ?>
                <div class="hour-line" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)));"></div>
              <?php endfor; ?>
              <?php $startHour = 7; $endHour = 24; for ($h=$startHour; $h<=$endHour-1; $h++): $isLast = ($h === $endHour-1); ?>
                <div class="axis-hour<?= $isLast ? ' axis-hour-last' : '' ?>" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)) + var(--label-offset));">
                  <?= h(fmt_hour_label($h)) ?>
                </div>
              <?php endfor; ?>
              <!-- bottom boundary line for axis (print-visible) -->
              <div class="hour-line" style="bottom: 0;"></div>
            </div>
            </div>
            <?php
            // Prepare day structures with all-day vs timed events and computed positions
            $startHour = 7; $endHour = 24;
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
                  $windowStartMin = $startHour * 60; $windowEndMin = $endHour * 60; // now 1440 to include 11 PM row fully
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
                  <div class="fw-semibold mb-1 text-center"><?= h($d->format('l')) ?> (<?= h($d->format('n')) ?>/<?= h($d->format('j')) ?>)</div>
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
                    <?php $startHour = 7; $endHour = 24; for ($h=$startHour; $h<$endHour; $h++): ?>
                      <div class="hour-line" style="top: calc((<?= (int)($h - $startHour) ?> * var(--hour-height)));"></div>
                    <?php endfor; ?>
                    <!-- bottom boundary line for 11 PM -->
                    <div class="hour-line" style="bottom: 0;"></div>
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
      </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      (function(){
        const startHour = 7, endHour = 24; // 17 slots
        // Track current print safety ("fudge") in JS for live preview tuning
        let printSafetyIn = (function(){
          const css = getComputedStyle(document.documentElement);
          const v = css.getPropertyValue('--print-safety').trim();
          if (v && v.endsWith('in')) {
            const num = parseFloat(v);
            if (!isNaN(num)) return num;
          }
          return -2.50; // default to larger (taller) calendar
        })();
        // Track current width safety ("wfudge") in inches; positive shrinks width, negative expands
        let printWidthSafetyIn = (function(){
          const css = getComputedStyle(document.documentElement);
          const v = css.getPropertyValue('--print-width-safety').trim();
          if (v && v.endsWith('in')) {
            const num = parseFloat(v);
            if (!isNaN(num)) return num;
          }
          return 0.00;
        })();

        function equalizeHeaders() {
          const headers = Array.from(document.querySelectorAll('.day-header'));
          const axisHeader = document.querySelector('.axis-header');
          if (headers.length === 0) return 0;
          let maxH = 0;
          headers.forEach(h => { h.style.height = ''; maxH = Math.max(maxH, h.offsetHeight); });
          headers.forEach(h => { h.style.height = maxH + 'px'; });
          if (axisHeader) axisHeader.style.height = maxH + 'px';
          return maxH;
        }

        function computeScreenHourHeight() {
          const grid = document.querySelector('.week-grid');
          if (!grid) return;
          const headerH = equalizeHeaders();
          const rect = grid.getBoundingClientRect();
          const viewportH = window.innerHeight || document.documentElement.clientHeight || 800;
          let avail = Math.max(200, Math.floor(viewportH - rect.top - 2));
          // Subtract header height to get hour grid height
          avail = Math.max(120, avail - headerH);
          const hours = Math.max(1, endHour - startHour);
          const perHour = Math.max(20, Math.floor(avail / hours));
          document.documentElement.style.setProperty('--hour-height', perHour + 'px');
        }

        function computePrintPreviewHourHeight() {
          // CSS inch-based variables drive preview sizing; we only update CSS var on user input.
          return;
        }

        function layout() {
          if (document.body.classList.contains('print-preview')) {
            return; // CSS controls sizing in preview/print
          }
          computeScreenHourHeight();
        }

        window.addEventListener('resize', layout);
        document.addEventListener('DOMContentLoaded', layout);
        // Account for font loading/metrics
        setTimeout(layout, 50);
        // Recompute after printing back to screen layout
        window.addEventListener('afterprint', layout);
        const mql = window.matchMedia && window.matchMedia('print');
        if (mql && mql.addEventListener) { mql.addEventListener('change', e => { if (!e.matches) layout(); }); }

        // Live "Fit" controls in preview: adjust --print-safety without reloading
        function updateFudgeDisplay() {
          const el = document.getElementById('fitValue');
          if (el) el.textContent = `Fit: ${printSafetyIn.toFixed(2)}in`;
          const elW = document.getElementById('fitWValue');
          if (elW) elW.textContent = `Width: ${printWidthSafetyIn.toFixed(2)}in`;
        }
        function setFudge(valInches) {
          // Allow negative values to make the calendar taller
          printSafetyIn = +valInches;
          document.documentElement.style.setProperty('--print-safety', printSafetyIn.toFixed(3) + 'in');
          // Also update URL so a subsequent reload preserves the value
          try {
            const url = new URL(window.location.href);
            url.searchParams.set('fudge', printSafetyIn.toFixed(2));
            window.history.replaceState({}, '', url.toString());
          } catch(e) {}
          updateFudgeDisplay();
        }
        function nudgeFudge(delta) { setFudge(printSafetyIn + delta); }
        function setWFudge(valInches) {
          printWidthSafetyIn = +valInches;
          document.documentElement.style.setProperty('--print-width-safety', printWidthSafetyIn.toFixed(3) + 'in');
          try {
            const url = new URL(window.location.href);
            url.searchParams.set('wfudge', printWidthSafetyIn.toFixed(2));
            window.history.replaceState({}, '', url.toString());
          } catch(e) {}
          updateFudgeDisplay();
        }
        function nudgeWFudge(delta) { setWFudge(printWidthSafetyIn + delta); }
        document.addEventListener('DOMContentLoaded', () => {
          if (document.body.classList.contains('print-preview')) {
            // Initialize display with current CSS var value
            updateFudgeDisplay();
            const minus = document.getElementById('fitMinus');
            const plus = document.getElementById('fitPlus');
            if (minus) minus.addEventListener('click', (e) => {
              e.preventDefault();
              const step = e.shiftKey ? 0.05 : 0.01; // hold Shift for bigger step
              nudgeFudge(-step);
            });
            if (plus) plus.addEventListener('click', (e) => {
              e.preventDefault();
              const step = e.shiftKey ? 0.05 : 0.01; // hold Shift for bigger step
              nudgeFudge(+step);
            });
            const wminus = document.getElementById('fitWMinus');
            const wplus = document.getElementById('fitWPlus');
            if (wminus) wminus.addEventListener('click', (e) => {
              e.preventDefault();
              const step = e.shiftKey ? 0.05 : 0.01; // hold Shift for bigger step
              nudgeWFudge(-step);
            });
            if (wplus) wplus.addEventListener('click', (e) => {
              e.preventDefault();
              const step = e.shiftKey ? 0.05 : 0.01; // hold Shift for bigger step
              nudgeWFudge(+step);
            });
          }
        });
      })();
    </script>
  </body>
  </html>
