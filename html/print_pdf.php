<?php
declare(strict_types=1);

// Turn notices into exceptions so we don't silently emit broken output
set_error_handler(function(int $severity, string $message, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function(Throwable $e): void {
    // Last‑ditch: return plain text so we can see the cause instead of a blank tab
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    // Log a brief message to sessions/pdf_error.log (if writable)
    $log = __DIR__ . '/../sessions/pdf_error.log';
    $msg = '['.date('c').'] print_pdf error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine()."\n";
    @file_put_contents($log, $msg, FILE_APPEND);
    echo "PDF generation failed: ".$e->getMessage();
    exit;
});

// Proactively invalidate OPcache for this script and FPDF to avoid running stale bytecode
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
    @opcache_invalidate(__DIR__ . '/lib/fpdf.php', true);
}

// Minimal early debug/ping path (before sessions/DB) to diagnose blank output
if (isset($_GET['ping'])) {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo "OK: print_pdf.php reachable\n";
    echo "PHP: ".PHP_VERSION."\n";
    echo "GET: ".json_encode($_GET)."\n";
    exit;
}

// Minimal PDF self-test that bypasses DB/ICS and just draws a basic page.
// Useful to isolate FPDF/runtime issues from calendar/data issues.
if (isset($_GET['test'])) {
    require __DIR__.'/lib/fpdf.php';
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(__FILE__, true);
        @opcache_invalidate(__DIR__ . '/lib/fpdf.php', true);
    }
    if (function_exists('ob_get_level')) { while (ob_get_level() > 0) { @ob_end_clean(); } }
    // Bare-minimum PDF: one blank page
    $pdf = new FPDF('L','in','Letter');
    $pdf->SetMargins(0.40, 0.40, 0.40);
    $pdf->AddPage();
    $mode = (isset($_GET['download']) && $_GET['download'] !== '0') ? 'D' : 'I';
    $pdf->Output($mode, 'PDF_Self_Test.pdf');
    exit;
}

// Minimal access log to aid debugging blank tabs on some browsers
// Try logging under docroot, else /tmp
$__logfile = __DIR__.'/pdf_access.log';
if (!@file_put_contents($__logfile, '')) { $__logfile = '/tmp/calendar_pdf_access.log'; }
@file_put_contents($__logfile, sprintf(
    "%s\t%s\t%s\tcookies=%s\n",
    date('c'),
    $_SERVER['REMOTE_ADDR'] ?? '-',
    (isset($_GET['ping']) ? 'ping' : (isset($_GET['debug']) ? 'debug' : 'pdf')),
    isset($_SERVER['HTTP_COOKIE']) ? 'yes' : 'no'
), FILE_APPEND);

session_start();
// Allow debug mode without an active session so we can see diagnostics
$isDebug = isset($_GET['debug']) && $_GET['debug'] !== '0';
if (!isset($_SESSION['user_id']) && !$isDebug) { header('Location: index.php'); exit; }

require __DIR__.'/lib_ics.php';
require __DIR__.'/lib/fpdf.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Convert UTF‑8 text to a codepage FPDF can render with core fonts.
// Prefer Windows‑1252 transliteration and fall back to utf8_decode.
function pdf_txt(string $s): string {
    $to = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
    if ($to === false) { $to = utf8_decode($s); }
    return $to;
}

// Replace Unicode punctuation with ASCII-safe equivalents before feeding to FPDF
function pdf_sanitize_punct(string $s): string {
    $map = [
        "\xE2\x80\x93" => '-', // en dash –
        "\xE2\x80\x94" => '-', // em dash —
        "\xE2\x80\x98" => "'", // ‘
        "\xE2\x80\x99" => "'", // ’
        "\xE2\x80\x9C" => '"', // “
        "\xE2\x80\x9D" => '"', // ”
        "\xE2\x80\xA2" => '-', // •
        "\xE2\x80\xA6" => '...', // …
    ];
    return strtr($s, $map);
}

// Remove unknown-age placeholders (e.g., "??" or "?? yrs") from summaries
function pdf_strip_unknown_age(string $s): string {
    // Strip patterns like " - ??", "– ??", "(??)", "?? yrs"
    $s = preg_replace('/\s*[\-\xE2\x80\x93\xE2\x80\x94]\s*\?\?\s*(yrs?|years?)?/i', '', $s);
    $s = preg_replace('/\s*[\(\[]\s*\?\?\s*(yrs?|years?)?\s*[\)\]]/i', '', $s);
    return (string)$s;
}

// Derive a clean anniversary title in the form "Name + Name" from a summary
// by stripping common words like "anniversary"/"wedding" and normalizing
// connectors ("and", "&") to a plus sign. Keep ASCII-safe output for core fonts.
function pdf_derive_anniv_names(string $summary): string {
    $orig = $summary;
    $s = trim($summary);
    // Remove possessive anniversary (e.g., "John & Jane's Anniversary")
    $s = preg_replace("/\b['’]s\s+anniversary\b/i", '', $s);
    // Remove generic words
    $s = preg_replace("/\b(wedding|marriage|anniversary|anniv)\b/iu", '', $s);
    // Remove common separators
    $s = preg_replace("/[\-–—:|]/u", ' ', $s);
    // Normalize connectors to '+'
    $s = preg_replace("/\s*&\s*/u", ' + ', $s);
    $s = preg_replace("/\s+and\s+/iu", ' + ', $s);
    // Collapse spaces and trim punctuation
    $s = preg_replace("/\s+/u", ' ', $s);
    $s = trim($s, " \t\n\r\0\x0B-–—:|,.");
    if ($s === '') return trim($orig);
    return $s;
}

// Birthdate parsing & age computation (PDF-side), mirrors web view
function pdf_parse_birthdate_from_description(?string $desc, DateTimeZone $tz): array {
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

function pdf_compute_age_for_day(?DateTimeImmutable $birthDate, ?int $birthYear, DateTimeImmutable $onDay): ?int {
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

// Wrap a UTF‑8 string into at most $maxLines lines that each fit $maxWidth (inches) using the
// current FPDF font. If text exceeds the space, the last line is ellipsized.
function pdf_wrap_to_lines(FPDF $pdf, string $utf8, float $maxWidth, int $maxLines): string {
    $maxLines = max(1, $maxLines);
    $text = pdf_txt($utf8);
    // Fast path: fits on one line
    if ($pdf->GetStringWidth($text) <= $maxWidth || $maxLines === 1) {
        // If single-line and still too long, ellipsize
        if ($pdf->GetStringWidth($text) > $maxWidth) {
            $ellipsis = '...';
            while ($text !== '' && $pdf->GetStringWidth($text.$ellipsis) > $maxWidth) {
                $text = substr($text, 0, -1);
            }
            return $text.$ellipsis;
        }
        return $text;
    }
    // Word-wrap up to maxLines
    $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $lines = [];
    $current = '';
    foreach ($words as $tok) {
        $try = ($current === '') ? $tok : ($current.$tok);
        if ($pdf->GetStringWidth($try) <= $maxWidth) {
            $current = $try;
        } else {
            if ($current === '') { // a single long token: hard cut
                $tmp = $tok;
                while ($tmp !== '' && $pdf->GetStringWidth($tmp) > $maxWidth) {
                    $tmp = substr($tmp, 0, -1);
                }
                $lines[] = $tmp;
                $current = '';
            } else {
                $lines[] = rtrim($current);
                $current = ltrim($tok);
            }
            if (count($lines) >= $maxLines - 1) {
                break;
            }
        }
    }
    if ($current !== '' && count($lines) < $maxLines) {
        $lines[] = rtrim($current);
    }
    // If original text didn’t fit in maxLines, ellipsize the last line
    $origTooLong = ($pdf->GetStringWidth($text) > $maxWidth * $maxLines);
    if ($origTooLong && !empty($lines)) {
        $last = $lines[count($lines)-1];
        $ellipsis = '...';
        while ($last !== '' && $pdf->GetStringWidth($last.$ellipsis) > $maxWidth) {
            $last = substr($last, 0, -1);
        }
        $lines[count($lines)-1] = $last.$ellipsis;
    }
    return implode("\n", $lines);
}

function fetch_url_pdf(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
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
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 1, 'user_agent' => 'calendar.lillard.app/1.0']]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) throw new RuntimeException('HTTP fetch failed');
    return (string)$data;
}

$config = require __DIR__.'/config.php';
if (!empty($config['timezone'])) { @date_default_timezone_set((string)$config['timezone']); }

$pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
$uid = (int)($_SESSION['user_id'] ?? 0);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT id, name, url FROM calendars WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$id, $uid]);
$cal = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cal) { http_response_code(404); echo 'Calendar not found'; exit; }

// Date handling (Sunday week)
$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('now', $tz);
$dateParam = isset($_GET['date']) ? (string)$_GET['date'] : '';
if ($dateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
    $base = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $tz) ?: $today;
} else {
    $base = $today;
}
$dow = (int)$base->format('w'); // 0=Sun..6=Sat
$weekStart = $base->modify('-'.$dow.' days')->setTime(0,0,0);
$weekEnd   = $weekStart->modify('+6 days')->setTime(23,59,59);

// Load events (primary + optional holidays)
try {
    $ics = fetch_url_pdf((string)$cal['url']);
    $events = ics_expand_events_in_range($ics, $weekStart->getTimestamp(), $weekEnd->getTimestamp(), $tz);
    if (!empty($config['holiday_ics'])) {
        try {
            $hics = fetch_url_pdf((string)$config['holiday_ics']);
            $hevents = ics_expand_events_in_range($hics, $weekStart->getTimestamp(), $weekEnd->getTimestamp(), $tz);
            foreach ($hevents as &$hv) { $hv['is_holiday'] = true; $hv['all_day'] = true; }
            unset($hv);
            $events = array_merge($events, $hevents);
        } catch (Throwable $e) { /* ignore */ }
    }
} catch (Throwable $e) {
    $events = [];
}

// Optional: per-user hidden-from-PDF events (by start timestamp + summary hash)
// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pdf_hidden_events (
      id BIGINT PRIMARY KEY AUTO_INCREMENT,
      user_id INT NOT NULL,
      calendar_id INT NOT NULL,
      start_ts INT NOT NULL,
      summary_hash CHAR(40) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_hide (user_id, calendar_id, start_ts, summary_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore table creation errors */ }

// Load hidden set for this user/calendar in the visible week
$hidden = [];
try {
    $q = $pdo->prepare('SELECT start_ts, summary_hash FROM pdf_hidden_events WHERE user_id=? AND calendar_id=? AND start_ts BETWEEN ? AND ?');
    $q->execute([$uid, $id, $weekStart->getTimestamp(), $weekEnd->getTimestamp()]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hidden[(int)$row['start_ts'].'#'.(string)$row['summary_hash']] = true;
    }
} catch (Throwable $e) { /* ignore */ }

// Filter events against hidden set
if ($hidden) {
    $filtered = [];
    foreach ($events as $ev) {
        $st = (int)($ev['start']['ts'] ?? 0);
        $sum = trim((string)($ev['summary'] ?? ''));
        $sh  = sha1(strtolower($sum));
        if (isset($hidden[$st.'#'.$sh])) { continue; }
        $filtered[] = $ev;
    }
    $events = $filtered;
}

// Optional: per-user PDF event meta (Uber There/Back per instance)
// Ensure table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pdf_event_meta (
      id BIGINT PRIMARY KEY AUTO_INCREMENT,
      user_id INT NOT NULL,
      calendar_id INT NOT NULL,
      start_ts INT NOT NULL,
      summary_hash CHAR(40) NOT NULL,
      uber_there TINYINT(1) NOT NULL DEFAULT 0,
      uber_back  TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_meta (user_id, calendar_id, start_ts, summary_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Throwable $e) { /* ignore */ }

// Load meta for this week into a global map accessible during drawing
$GLOBALS['__pdf_meta'] = [];
try {
    $q = $pdo->prepare('SELECT start_ts, summary_hash, uber_there, uber_back FROM pdf_event_meta WHERE user_id=? AND calendar_id=? AND start_ts BETWEEN ? AND ?');
    $q->execute([$uid, $id, $weekStart->getTimestamp(), $weekEnd->getTimestamp()]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $GLOBALS['__pdf_meta'][(int)$row['start_ts'].'#'.(string)$row['summary_hash']] = [
            'there' => (int)$row['uber_there'] === 1,
            'back'  => (int)$row['uber_back'] === 1,
        ];
    }
} catch (Throwable $e) { /* ignore meta load */ }

// Optional debug mode to quickly verify server-side values instead of a blank viewer
if ($isDebug) {
    if (!headers_sent()) { header('Content-Type: text/plain; charset=UTF-8'); }
    echo "Calendar: ".$cal['name']."\n";
    echo "Week: ".$weekStart->format('Y-m-d')." .. ".$weekEnd->format('Y-m-d')."\n";
    echo "Timezone: ".date_default_timezone_get()."\n";
    $cAll = 0; $cTimed = 0;
    foreach ($events as $ev) { if (!empty($ev['all_day'])) $cAll++; else $cTimed++; }
    echo "Events: total=".count($events)." allDay=${cAll} timed=${cTimed}\n";
    echo "UserID: ".$uid."\n";
    echo "FPDF present: ".(class_exists('FPDF') ? 'yes' : 'no')."\n";
    exit;
}

// Bucket events per day
$days = [];
for ($i=0; $i<7; $i++) {
    $d = $weekStart->modify("+{$i} days");
    $key = $d->format('Y-m-d');
    $days[$key] = [ 'date' => $d, 'all' => [], 'timed' => [] ];
}
foreach ($events as $ev) {
    $start = $ev['start']['ts'] ?? null;
    if ($start === null) continue;
    $k = (new DateTimeImmutable('@'.$start))->setTimezone($tz)->format('Y-m-d');
    if (!isset($days[$k])) continue;
    if (!empty($ev['all_day'])) $days[$k]['all'][] = $ev; else $days[$k]['timed'][] = $ev;
}
foreach ($days as &$dref) {
    usort($dref['timed'], fn($a,$b) => ($a['start']['ts'] ?? 0) <=> ($b['start']['ts'] ?? 0));
}
unset($dref);

// ----- PDF drawing with FPDF -----
$pdf = new FPDF('L', 'in', 'Letter');
// Store margins locally instead of reading FPDF's protected properties later
$margin = 0.40; // inches on all sides
$pdf->SetMargins($margin, $margin, $margin);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Dimensions
$pageW = $pdf->GetPageWidth() - 2*$margin; // width inside margins
$pageH = $pdf->GetPageHeight() - 2*$margin; // height inside margins
$originX = $margin; $originY = $margin;

// Layout constants (base)
$axisW   = 0.80;            // time axis width
$topGap  = 0.08;            // gap above 7 AM line
$rows    = 17;              // 7AM..11PM
$dayW    = ($pageW - $axisW) / 7.0;

// Dynamically size the header based on all‑day content, but keep the grid readable
$baseHeaderMin = 0.60;      // minimum header height
$headerTitleTop = 0.10;     // where the weekday title starts
$headerContentTopAbs = $originY + 0.32; // below the weekday title (kept constant)
$headerBottomPad = 0.02;    // minimal breathing room above 7AM grid (reduce extra space)
$minRowH = 0.24;            // do not let hour rows get smaller than this

// Compute the maximum header we can use while staying on one page
$headerMax = max($baseHeaderMin, $pageH - $topGap - ($rows * $minRowH));

// Estimate required header per day from all‑day badges
$pdf->SetFont('Helvetica', '', 9);
$padX = 0.04; $padY = 0.04; $lineH = 0.12; $badgeGap = 0.04; $maxLines = 2; // limit to 2 lines per all‑day badge
$bxWidth = max(0.10, $dayW - 0.16) - 2*$padX; // inner text width for a badge
$neededHeader = $baseHeaderMin;
for ($d=0; $d<7; $d++) {
    $date = $weekStart->modify("+{$d} days");
    $key  = $date->format('Y-m-d');
    $yAll = $headerContentTopAbs; // absolute y where badges begin
    $sum  = 0.0;
    if (!empty($days[$key]['all'])) {
        foreach ($days[$key]['all'] as $ae) {
            $txt = pdf_strip_unknown_age(pdf_sanitize_punct((string)($ae['summary'] ?? '')));
            $desc = (string)($ae['description'] ?? '');
            // Birthday/age detection
            $bdinfo = pdf_parse_birthdate_from_description($desc, $tz);
            $age = pdf_compute_age_for_day($bdinfo['date'], $bdinfo['year'], $date);
            $isBirthday = is_int($age) && $age >= 0;
            // Anniversary detection (flexible patterns)
            $annivYears = null;
            $anniSource = (string)$desc . ' ' . $txt;
            if (preg_match('/\bmarried\b[^\d]{0,20}(\d{4})\b/i', $anniSource, $mAnn)) {
                $baseYear = (int)$mAnn[1];
                $annivYears = max(0, ((int)$date->format('Y')) - $baseYear);
            } elseif (preg_match('/\bmarried\s+on\s+[A-Za-z]{3,9}\s+\d{1,2},\s*(\d{4})\b/i', $anniSource, $mAnn)) {
                $baseYear = (int)$mAnn[1];
                $annivYears = max(0, ((int)$date->format('Y')) - $baseYear);
            }

            if ($isBirthday || $annivYears !== null) {
                // Reserve two lines total (title single line + years/age second line)
                $needLines = 2;
            } else {
                $txtW = $pdf->GetStringWidth(pdf_txt($txt));
                $needLines = 1 + (int)floor($txtW / max(0.01, $bxWidth));
                $needLines = min($maxLines, max(1, $needLines));
            }
            $needH = $padY + ($needLines * $lineH) + $padY;
            $sum += $needH + $badgeGap;
        }
        if ($sum > 0) { $sum -= $badgeGap; } // no gap after last badge
    }
    // Total absolute header needed from origin: title + badges + bottom pad
    $absNeeded = ($headerContentTopAbs - $originY) + $sum + $headerBottomPad;
    $neededHeader = max($neededHeader, $absNeeded);
}

// Apply header height within the safe cap
$headerH = min($headerMax, max($baseHeaderMin, $neededHeader));

// With header fixed, compute grid sizes
$gridH   = $pageH - $headerH - $topGap; // height of hour grid
$rowH    = $gridH / $rows;

// Styles
// Outer frame stays solid black; internal grid lines will be lighter/thinner
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(0.02);

// Outer frame
$pdf->Rect($originX, $originY, $pageW, $pageH);

// Day/axis separators
// Top header line (keep strong)
$pdf->Line($originX, $originY, $originX + $pageW, $originY);
// Axis right edge (lightened so labels remain prominent)
$pdf->SetDrawColor(160,160,160); $pdf->SetLineWidth(0.008);
$pdf->Line($originX + $axisW, $originY, $originX + $axisW, $originY + $pageH);
// Day separators (internal grid: lighter/thinner)
for ($i=0; $i<=7; $i++) {
    $x = $originX + $axisW + $i*$dayW;
    // Avoid double-drawing the far right frame: it's already drawn by the outer frame
    if ($i === 7) { continue; }
    $pdf->Line($x, $originY, $x, $originY + $pageH);
}
// Restore default for next sections
$pdf->SetDrawColor(0,0,0); $pdf->SetLineWidth(0.02);

// Horizontal hour lines (including bottom boundary)
// Draw lighter/thinner, and extend fully across the time axis so lines appear above labels
$gridTop = $originY + $headerH + $topGap;
$pdf->SetDrawColor(160,160,160); $pdf->SetLineWidth(0.008);
for ($i=0; $i<=$rows; $i++) {
    $y = $gridTop + $i*$rowH;
    $pdf->Line($originX, $y, $originX + $pageW, $y);
}
// Restore for subsequent shapes
$pdf->SetDrawColor(0,0,0); $pdf->SetLineWidth(0.02);

// Time labels (right-aligned, placed just below each hour line)
$pdf->SetFont('Helvetica', '', 10);
for ($i=0; $i<$rows; $i++) {
    $h = 7 + $i;
    $label = date('g A', mktime($h % 24, 0, 0));
    $yText = $gridTop + $i*$rowH + 0.02; // small offset below line
    $pdf->SetXY($originX, $yText);
    $pdf->Cell($axisW - 0.05, 0.16, pdf_txt($label), 0, 0, 'R');
}

// Day headers (centered: "Sunday (8/31)") and all‑day blocks
for ($d=0; $d<7; $d++) {
    $x = $originX + $axisW + $d*$dayW;
    $date = $weekStart->modify("+{$d} days");
    $dowName = $date->format('l');
    $mmdd = $date->format('n/j');
    $title = $dowName.' ('.$mmdd.')';
    // Slightly smaller weekday title per request
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetXY($x, $originY + 0.10);
    $pdf->Cell($dayW, 0.18, pdf_txt($title), 0, 0, 'C');

    // All-day events stacked (kept within header bounds)
    $headerContentTop = $originY + 0.32;               // below the weekday title
    $headerContentBottom = $originY + $headerH; // use full header; avoid extra bottom padding
    $yAll = $headerContentTop;
    // Make all‑day text a bit smaller
    $pdf->SetFont('Helvetica', '', 8);
    if (!empty($days[$date->format('Y-m-d')]['all'])) {
        $allList = $days[$date->format('Y-m-d')]['all'];
        $totalAll = count($allList);
        foreach ($allList as $idxAll => $ae) {
            $summaryRaw = pdf_sanitize_punct((string)($ae['summary'] ?? ''));
            $desc = (string)($ae['description'] ?? '');
            $bdinfo = pdf_parse_birthdate_from_description($desc, $tz);
            $age = pdf_compute_age_for_day($bdinfo['date'], $bdinfo['year'], $date);
            $isBirthday = is_int($age) && $age >= 0;
            // Anniversary: detect marriage year in description or summary (flexible patterns)
            $annivYears = null;
            $anniSource = (string)$desc . ' ' . $summaryRaw;
            if (preg_match('/\bmarried\b[^\d]{0,20}(\d{4})\b/i', $anniSource, $mAnn)) {
                $baseYear = (int)$mAnn[1];
                $annivYears = max(0, ((int)$date->format('Y')) - $baseYear);
            } elseif (preg_match('/\bmarried\s+on\s+[A-Za-z]{3,9}\s+\d{1,2},\s*(\d{4})\b/i', $anniSource, $mAnn)) {
                $baseYear = (int)$mAnn[1];
                $annivYears = max(0, ((int)$date->format('Y')) - $baseYear);
            }

            // Layout for a single all‑day badge
            $padX = 0.04; $padY = 0.04;
            $bx   = $x + 0.08;                                 // a hair more inset so we never touch the day edge
            $bw   = max(0.10, $dayW - 0.16);                   // inner width

            // Estimate required height based on text width (single line) and allow up to two lines
            // Line height tuned to smaller font
            $lineH = 0.11;
            $maxLines = 2;
            // For birthdays we force two centered lines: summary (1 line, ellipsized) + age on second line
            if ($isBirthday) {
                $needLines = 2;
            } elseif ($annivYears !== null) {
                // Anniversary also forces two lines: summary (1 line, ellipsized) + (NN years)
                $needLines = 2;
            } else {
                $txtCP = pdf_txt($summaryRaw);
                $needLines = 1;
                if ($pdf->GetStringWidth($txtCP) > ($bw - 2*$padX)) {
                    $needLines = min($maxLines, 1 + (int)floor($pdf->GetStringWidth($txtCP) / max(0.01, ($bw - 2*$padX))));
                }
            }
            $needH = $padY + ($needLines * $lineH) + $padY;    // text + vertical padding

            // Clamp to remaining space in the header so we never spill into the grid
            $remain = $headerContentBottom - $yAll;
            $minH = $padY + $lineH; // at least one line with padding
            if ($remain < $minH) {
                // Not enough room for another full badge. If there are remaining items,
                // draw a compact "+N more" pill that fits, then stop.
                $remainingCount = $totalAll - $idxAll;
                if ($remainingCount > 0 && $remain > 0.10) {
                    $moreTxt = "+$remainingCount more";
                    $bh = min(max(0.14, $remain), 0.22);
                    // Black & white: solid white fill, black border
                    $pdf->SetDrawColor(0,0,0);
                    $pdf->SetFillColor(255,255,255);
                    $pdf->Rect($bx, $yAll, $bw, $bh, 'DF');
                    $pdf->SetXY($bx + $padX, $yAll + ($bh/2) - 0.06);
                    $pdf->SetFont('Helvetica', '', 9);
                    $pdf->Cell($bw - 2*$padX, 0.12, pdf_txt($moreTxt), 0, 0, 'C');
                }
                break;
            }
            $bh = min(max($minH, $needH), $remain);

            // Draw badge box (black border, solid white fill)
            $pdf->SetDrawColor(0,0,0);
            $pdf->SetFillColor(255,255,255);
            // Match timed-event border thickness
            $pdf->SetLineWidth(0.008);
            $pdf->Rect($bx, $yAll, $bw, $bh, 'DF');
            // Restore default line width for subsequent grid lines
            $pdf->SetLineWidth(0.02);

            // Text (centered). Birthdays/anniversaries: exactly 2 lines (title single line + years/age).
            $pdf->SetXY($bx + $padX, $yAll + $padY);
            if ($isBirthday) {
                $titleLines = pdf_wrap_to_lines($pdf, $summaryRaw, ($bw - 2*$padX), 1); // single-line title
                $yearsLine = sprintf('%d years old', (int)$age);
                $content = $titleLines."\n".$yearsLine;
                $pdf->MultiCell($bw - 2*$padX, $lineH, pdf_txt($content), 0, 'C');
            } elseif ($annivYears !== null) {
                // Anniversary: first line is a clean names line (e.g., "Jennifer + Randy"),
                // second line shows "(NN years)". Keep names to a single line to hold total lines to 2.
                $names = pdf_derive_anniv_names($summaryRaw);
                $titleLines = pdf_wrap_to_lines($pdf, $names, ($bw - 2*$padX), 1);
                $yearsLine = '(' . (int)$annivYears . ' years)';
                $content = $titleLines."\n".$yearsLine;
                $pdf->MultiCell($bw - 2*$padX, $lineH, pdf_txt($content), 0, 'C');
            } else {
                $wrapped = pdf_wrap_to_lines(
                    $pdf,
                    $summaryRaw,
                    ($bw - 2*$padX),
                    min($maxLines, max(1, (int)floor(max(0.0, ($bh - 2*$padY)) / $lineH)))
                );
                $pdf->MultiCell($bw - 2*$padX, $lineH, pdf_txt($wrapped), 0, 'C');
            }

            // Advance to the next badge position with small gap
            $yAll += $bh + 0.04;
            if ($yAll >= $headerContentBottom) { break; }
        }
    }
}

// Timed events drawing per day with simple overlap layout (clustered)
function intervals_overlap(int $a1,int $a2,int $b1,int $b2): bool { return $a1 < $b2 && $b1 < $a2; }

for ($d=0; $d<7; $d++) {
    $date = $weekStart->modify("+{$d} days");
    $key = $date->format('Y-m-d');
    $timed = $days[$key]['timed'] ?? [];
    if (!$timed) continue;
    // Build interval list in minutes since 7:00
    $items = [];
    foreach ($timed as $idx => $e) {
        $st = (int)($e['start']['ts'] ?? 0);
        $en = (int)($e['end']['ts'] ?? ($st + 3600));
        $ls = (new DateTimeImmutable('@'.$st))->setTimezone($tz);
        $le = (new DateTimeImmutable('@'.$en))->setTimezone($tz);
        $startMin = ((int)$ls->format('G'))*60 + ((int)$ls->format('i')) - 7*60;
        $endMin   = ((int)$le->format('G'))*60 + ((int)$le->format('i')) - 7*60;
        $startMin = max(0, min($rows*60, $startMin));
        $endMin   = max($startMin+5, min($rows*60, $endMin)); // ensure at least 5 min height
        $items[] = [
            'idx' => $idx,
            'startMin' => $startMin,
            'endMin' => $endMin,
            'ev' => $e,
        ];
    }
    // Build overlap clusters via union-find
    $n = count($items);
    $parent = range(0, $n-1);
    $find = function($x) use (&$parent, &$find) { return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x])); };
    $union = function($a,$b) use (&$parent, $find) { $ra=$find($a); $rb=$find($b); if ($ra!==$rb) $parent[$rb]=$ra; };
    for ($i=0; $i<$n; $i++) {
        for ($j=$i+1; $j<$n; $j++) {
            if (intervals_overlap($items[$i]['startMin'],$items[$i]['endMin'],$items[$j]['startMin'],$items[$j]['endMin'])) {
                $union($i,$j);
            }
        }
    }
    // Group indices by root
    $clusters = [];
    for ($i=0; $i<$n; $i++) { $r = $find($i); $clusters[$r][] = $i; }

    $xLeft = $originX + $axisW + $d*$dayW;
    // Center clusters within the day: use equal side padding on both sides
    $sidePad = 0.06;                  // equal left/right outer padding
    $usableW = max(0.10, $dayW - 2*$sidePad);
    foreach ($clusters as $clIdxs) {
        // Determine a baseline event (longest duration) and draw it full width;
        // draw overlapping companions slightly indented and on top so they appear above.
        usort($clIdxs, fn($a,$b)=> ($items[$a]['endMin'] - $items[$a]['startMin']) <=> ($items[$b]['endMin'] - $items[$b]['startMin']));
        $baselineIdx = end($clIdxs); // longest duration
        // Compute common geometry
        $innerPad = 0.02;              // inner per-event horizontal padding
        $indent   = 0.08;              // indent for overlaid events (inches, applied on both sides)

        // Helper to draw one event box with given x/width
        $drawEvent = function(array $eItem, array $ev, float $bx, float $bw) use ($rows, $gridTop, $gridH, $rowH, $pdf, $tz) {
            $topFrac = $eItem['startMin'] / ($rows*60);
            $htFrac  = max(5/($rows*60), ($eItem['endMin'] - $eItem['startMin']) / ($rows*60));
            $by = $gridTop + $topFrac * $gridH;
            $bh = $htFrac * $gridH;
            // Nudge away from hour lines
            $hourNudge = min(0.080, $rowH * 0.45);
            $startsOnHour = ($eItem['startMin'] % 60) === 0;
            $endsOnHour   = ($eItem['endMin'] % 60) === 0;
            if ($startsOnHour) { $by += $hourNudge; $bh -= $hourNudge; }
            if ($endsOnHour)   { $bh -= $hourNudge; }
            if ($bh < 0.10) { $bh = 0.10; }
            // Box
            $pdf->SetDrawColor(0,0,0);
            $pdf->SetLineWidth(0.008);
            $pdf->SetFillColor(255,255,255);
            $pdf->Rect($bx, $by, max(0.02, $bw), $bh, 'DF');
            // Text
            $sTs = (int)($ev['start']['ts'] ?? 0); $eTs = (int)($ev['end']['ts'] ?? $sTs);
            $sLblFull = (new DateTimeImmutable('@'.$sTs))->setTimezone($tz)->format('g:ia');
            $eLblFull = (new DateTimeImmutable('@'.$eTs))->setTimezone($tz)->format('g:ia');
            $sLbl = preg_replace('/:00(AM|PM|am|pm)$/', '$1', $sLblFull);
            $eLbl = preg_replace('/:00(AM|PM|am|pm)$/', '$1', $eLblFull);
            $timeLine = $sLbl.' - '.$eLbl;
            $summary  = pdf_sanitize_punct((string)($ev['summary'] ?? ''));
            $pdfUber = '';
            if (!isset($GLOBALS['__pdf_meta'])) { $GLOBALS['__pdf_meta'] = []; }
            $metaKey = $sTs.'#'.sha1(strtolower(trim((string)($ev['summary'] ?? ''))));
            if (isset($GLOBALS['__pdf_meta'][$metaKey])) {
                $m = $GLOBALS['__pdf_meta'][$metaKey];
                $there = !empty($m['there']); $back  = !empty($m['back']);
                if ($there && $back) { $pdfUber = 'Uber There and Back'; }
                elseif ($there)     { $pdfUber = 'Uber There'; }
                elseif ($back)      { $pdfUber = 'Uber Back'; }
            }
            // Decide lines by duration, but ensure short (30m) events still show title
            $pdf->SetFont('Helvetica','',6);
            $pdf->SetXY($bx + 0.025, $by + 0.030 + ($startsOnHour ? 0.006 : 0));
            $availableH = max(0.0, $bh - 0.050); // account for top/bottom padding
            $durMin = max(0, $eItem['endMin'] - $eItem['startMin']);
            // Target line count by duration
            $targetLines = 1;
            if ($durMin >= 60) { $targetLines = 3; }
            elseif ($durMin >= 30) { $targetLines = 2; }
            // Start with a base line height; shrink slightly to fit target lines if needed
            $lineH = 0.11;
            if ($targetLines > 1) {
                $needH = $targetLines * $lineH + 0.00;
                if ($needH > $availableH && $availableH > 0.0) {
                    // shrink line height but not below 0.085in
                    $lineH = max(0.085, ($availableH - 0.00) / $targetLines);
                }
            }
            $maxLines = (int)floor($availableH / $lineH);
            // keep at least target lines if there is any space at all
            if ($availableH > 0.0) {
                $maxLines = max(min($targetLines, 3), min($maxLines, 3));
            }
            // Build text: time always first line; include title and Uber per line budget
            $out = pdf_txt($timeLine);
            if ($maxLines >= 2) {
                $out .= "\n".pdf_txt($summary);
            }
            if ($maxLines >= 3 && $pdfUber !== '') {
                $out .= "\n".pdf_txt($pdfUber);
            }
            $pdf->MultiCell(max(0.02, $bw - 0.08), $lineH, $out, 0, 'L');
            $pdf->SetLineWidth(0.02);
        };

        // Draw baseline full width first
        $baseItem = $items[$baselineIdx];
        $baseEv   = $baseItem['ev'];
        $baseBx   = $xLeft + $sidePad + $innerPad;
        $baseBw   = max(0.02, $usableW - 2*$innerPad);
        $drawEvent($baseItem, $baseEv, $baseBx, $baseBw);

        // Draw the rest with a slight indent so they appear on top of the baseline
        foreach ($clIdxs as $ii) {
            if ($ii === $baselineIdx) continue;
            $e = $items[$ii]; $ev = $e['ev'];
            // indent both left and right for overlapping events
            $bx = $xLeft + $sidePad + $indent + $innerPad;
            $bw = max(0.02, $usableW - 2*$innerPad - (2*$indent));
            $drawEvent($e, $ev, $bx, $bw);
        }
    }
}

$file = sprintf('%s_Week_%s.pdf', preg_replace('/[^A-Za-z0-9]+/', '_', (string)$cal['name']), $weekStart->format('Y-m-d'));
// Ensure no stray output corrupts the PDF (avoid blank window)
if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
}
// Let FPDF emit appropriate headers and content
// Some browsers show a blank tab for inline PDFs; default to download.
// Default to inline viewing while we iterate, allow ?download=1 to force download
$mode = (isset($_GET['download']) && $_GET['download'] !== '0') ? 'D' : 'I';
$pdf->Output($mode, $file);
exit;
