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

// Minimal access log to aid debugging blank tabs on some browsers
// Try /var/tmp first (writable across reboots), then /tmp
$__logfile = '/var/tmp/calendar_pdf_access.log';
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
$pdf->SetMargins(0.40, 0.40, 0.40);
$pdf->SetAutoPageBreak(false);
$pdf->AddPage();

// Dimensions
$pageW = $pdf->GetPageWidth() - $pdf->lMargin - $pdf->rMargin; // inside margins
$pageH = $pdf->GetPageHeight() - $pdf->tMargin - $pdf->bMargin;
$originX = $pdf->lMargin; $originY = $pdf->tMargin;

// Layout constants
$axisW   = 0.80;            // time axis width
$headerH = 0.60;            // day header + all‑day area
$topGap  = 0.08;            // gap above 7 AM line
$rows    = 17;              // 7AM..11PM
$gridH   = $pageH - $headerH - $topGap; // height of hour grid
$rowH    = $gridH / $rows;
$dayW    = ($pageW - $axisW) / 7.0;

// Styles
$pdf->SetDrawColor(0,0,0);
$pdf->SetLineWidth(0.02);

// Outer frame
$pdf->Rect($originX, $originY, $pageW, $pageH);

// Day/axis separators
// Top header line
$pdf->Line($originX, $originY, $originX + $pageW, $originY);
// Axis right edge and left border already covered by frame; draw axis vertical separator
$pdf->Line($originX + $axisW, $originY, $originX + $axisW, $originY + $pageH);
// Day separators
for ($i=0; $i<=7; $i++) {
    $x = $originX + $axisW + $i*$dayW;
    $pdf->Line($x, $originY, $x, $originY + $pageH);
}

// Horizontal hour lines (including bottom boundary)
$gridTop = $originY + $headerH + $topGap;
for ($i=0; $i<=$rows; $i++) {
    $y = $gridTop + $i*$rowH;
    $pdf->Line($originX + $axisW, $y, $originX + $pageW, $y);
}

// Time labels (right-aligned, placed just below each hour line)
$pdf->SetFont('Helvetica', '', 10);
for ($i=0; $i<$rows; $i++) {
    $h = 7 + $i;
    $label = date('g A', mktime($h % 24, 0, 0));
    $yText = $gridTop + $i*$rowH + 0.02; // small offset below line
    $pdf->SetXY($originX, $yText);
    $pdf->Cell($axisW - 0.05, 0.16, $label, 0, 0, 'R');
}
// 11 PM label near bottom line
$pdf->SetXY($originX, $gridTop + $rows*$rowH - 0.18);
$pdf->Cell($axisW - 0.05, 0.16, '11 PM', 0, 0, 'R');

// Day headers (centered: "Sunday (8/31)") and all‑day blocks
for ($d=0; $d<7; $d++) {
    $x = $originX + $axisW + $d*$dayW;
    $date = $weekStart->modify("+{$d} days");
    $dowName = $date->format('l');
    $mmdd = $date->format('n/j');
    $title = $dowName.' ('.$mmdd.')';
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetXY($x, $originY + 0.10);
    $pdf->Cell($dayW, 0.18, $title, 0, 0, 'C');

    // All-day events stacked
    $yAll = $originY + 0.32;
    $pdf->SetFont('Helvetica', '', 9);
    if (!empty($days[$date->format('Y-m-d')]['all'])) {
        foreach ($days[$date->format('Y-m-d')]['all'] as $ae) {
            $txt = (string)($ae['summary'] ?? '');
            // Optional: append age for birthdays if detected
            $age = null;
            $desc = (string)($ae['description'] ?? '');
            $bd = null; $by = null;
            // Parse birth year using same heuristic as screen view
            if ($desc !== '') {
                if (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{4})-(\d{2})-(\d{2})\b/i', $desc, $m)) {
                    $by = (int)$m[2]; $bm=(int)$m[3]; $bd=(int)$m[4];
                } elseif (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{1,2})\/(\d{1,2})\/(\d{4})\b/i', $desc, $m)) {
                    $by = (int)$m[4]; $bm=(int)$m[2]; $bd=(int)$m[3];
                } elseif (preg_match('/\b(born|birth|dob|b\.)[^\d]{0,10}(\d{4})\b/i', $desc, $m)) {
                    $by = (int)$m[2];
                }
                if ($by) {
                    $yy=(int)$date->format('Y'); $mm=(int)$date->format('n'); $dd=(int)$date->format('j');
                    $age = $yy - $by;
                    if (isset($bm,$bd) && ($mm < $bm || ($mm===$bm && $dd < $bd))) $age--;
                }
            }
            if ($age !== null) $txt .= ' · '.$age.' yrs';
            // Draw box
            $pad = 0.04; $bh = 0.20; $bw = $dayW - 0.12; $bx = $x + 0.06; $byy = $yAll;
            $pdf->SetDrawColor(0, 100, 0); $pdf->SetFillColor(220, 245, 230);
            $pdf->Rect($bx, $byy, $bw, $bh, 'D');
            $pdf->SetXY($bx + $pad, $byy + 0.04);
            $pdf->Cell($bw - 2*$pad, 0.12, $txt);
            $yAll += $bh + 0.05;
            if ($yAll > $originY + $headerH - 0.05) break; // avoid overflow
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
    $usableW = $dayW - 0.12; // padding 0.06 on both sides
    foreach ($clusters as $clIdxs) {
        // Order by start
        usort($clIdxs, fn($a,$b)=> $items[$a]['startMin'] <=> $items[$b]['startMin']);
        // Assign columns greedily
        $cols = []; // each: lastEnd
        $assign = [];
        foreach ($clIdxs as $ii) {
            $placed = false;
            for ($c=0; $c<count($cols); $c++) {
                if ($cols[$c] <= $items[$ii]['startMin']) { $cols[$c] = $items[$ii]['endMin']; $assign[$ii] = $c; $placed=true; break; }
            }
            if (!$placed) { $cols[] = $items[$ii]['endMin']; $assign[$ii] = count($cols)-1; }
        }
        $colCount = max(1, count($cols));
        $colW = $usableW / $colCount;
        // Draw each in cluster
        foreach ($clIdxs as $ii) {
            $e = $items[$ii]; $ev = $e['ev']; $colIdx = $assign[$ii];
            $bx = $xLeft + 0.06 + $colIdx * $colW;
            $topFrac = $e['startMin'] / ($rows*60);
            $htFrac  = max(5/($rows*60), ($e['endMin'] - $e['startMin']) / ($rows*60));
            $by = $gridTop + $topFrac * $gridH;
            $bh = $htFrac * $gridH;
            // Color (if any)
            $pdf->SetDrawColor(13,110,253); $pdf->SetFillColor(220,235,255);
            if (!empty($ev['color'])) {
                $hex = strtoupper((string)$ev['color']);
                if (preg_match('/^#([0-9A-F]{6})$/', $hex, $m)) {
                    $r = hexdec(substr($m[1],0,2)); $g = hexdec(substr($m[1],2,2)); $b = hexdec(substr($m[1],4,2));
                    $pdf->SetDrawColor($r, $g, $b);
                    // light fill
                    $pdf->SetFillColor(min(255, $r+100), min(255,$g+100), min(255,$b+100));
                }
            }
            $pdf->Rect($bx, $by, $colW - 0.06, $bh, 'D');
            // Text: time range + title
            $sTs = (int)($ev['start']['ts'] ?? 0); $eTs = (int)($ev['end']['ts'] ?? $sTs);
            $sLbl = (new DateTimeImmutable('@'.$sTs))->setTimezone($tz)->format('g:ia');
            $eLbl = (new DateTimeImmutable('@'.$eTs))->setTimezone($tz)->format('g:ia');
            $label = $sLbl.' – '.$eLbl.'  '.(string)($ev['summary'] ?? '');
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetXY($bx + 0.03, $by + 0.03);
            // Use a clipped cell area
            $pdf->MultiCell($colW - 0.12, 0.16, $label, 0, 'L');
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
$mode = (isset($_GET['inline']) && $_GET['inline'] !== '0') ? 'I' : 'D';
$pdf->Output($mode, $file);
exit;
