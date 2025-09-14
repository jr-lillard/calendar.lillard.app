<?php
declare(strict_types=1);

function ics_unfold(string $raw): string {
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $out = '';
    $lines = explode("\n", $raw);
    foreach ($lines as $line) {
        if ($line === '') { $out .= "\n"; continue; }
        if (isset($line[0]) && ($line[0] === ' ' || $line[0] === "\t")) {
            $out = rtrim($out, "\n");
            $out .= substr($line, 1);
            $out .= "\n";
        } else {
            $out .= $line . "\n";
        }
    }
    return $out;
}

function ics_parse_events(string $raw): array {
    $raw = ics_unfold($raw);
    $events = [];
    $inEvent = false;
    $current = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strcasecmp($line, 'BEGIN:VEVENT') === 0) {
            $inEvent = true; $current = [];
            continue;
        }
        if (strcasecmp($line, 'END:VEVENT') === 0) {
            if ($inEvent) { $events[] = $current; }
            $inEvent = false; $current = [];
            continue;
        }
        if (!$inEvent) continue;
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $nameParams = substr($line, 0, $pos);
        $value = substr($line, $pos + 1);
        $parts = explode(';', $nameParams);
        $name = strtoupper(array_shift($parts));
        $params = [];
        foreach ($parts as $p) {
            $kv = explode('=', $p, 2);
            $params[strtoupper($kv[0])] = $kv[1] ?? '';
        }
        // Accumulate EXDATE occurrences if repeated
        if ($name === 'EXDATE') {
            if (!isset($current['EXDATE_LINES'])) { $current['EXDATE_LINES'] = []; }
            $current['EXDATE_LINES'][] = $value;
        }
        $current[$name] = ['value' => $value, 'params' => $params];
    }
    // Normalize map
    $norm = [];
    foreach ($events as $ev) {
        $summary = ics_text_unescape($ev['SUMMARY']['value'] ?? '');
        $uid     = $ev['UID']['value'] ?? '';
        $desc = ics_text_unescape($ev['DESCRIPTION']['value'] ?? '');
        $loc = ics_text_unescape($ev['LOCATION']['value'] ?? '');
        $startS = $ev['DTSTART']['value'] ?? '';
        $endS = $ev['DTEND']['value'] ?? '';
        $durationS = $ev['DURATION']['value'] ?? '';
        $startTzid = (string)($ev['DTSTART']['params']['TZID'] ?? '');
        $endTzid = (string)($ev['DTEND']['params']['TZID'] ?? '');
        $start = ics_parse_dt_with_tz($startS, $startTzid);
        $end = ics_parse_dt_with_tz($endS, $endTzid);
        // If DTEND is missing but DURATION is provided, compute the end timestamp
        $durationSecs = null;
        if ($durationS !== '') {
            $durationSecs = ics_parse_duration_to_seconds($durationS);
            if ($durationSecs !== null && ($end['ts'] ?? null) === null && ($start['ts'] ?? null) !== null) {
                $endTs = (int)$start['ts'] + $durationSecs;
                $end = ['ts' => $endTs, 'display' => (new DateTimeImmutable('@'.$endTs))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i')];
            }
        }
        // Optional per‑event color (RFC 7986 COLOR). Accept hex only for safety.
        $color = null;
        if (!empty($ev['COLOR']['value'] ?? '')) {
            $color = ics_sanitize_hex_color((string)$ev['COLOR']['value']);
        }
        // Recurrence
        $rrule = [];
        if (!empty($ev['RRULE']['value'] ?? '')) {
            $rrule = ics_parse_rrule((string)$ev['RRULE']['value']);
        }
        // Exceptions (EXDATE)
        $exdates = [];
        if (!empty($ev['EXDATE_LINES'] ?? [])) {
            foreach ((array)$ev['EXDATE_LINES'] as $line) {
                foreach (explode(',', (string)$line) as $tok) {
                    $tok = trim($tok);
                    if ($tok === '') continue;
                    $ex = ics_parse_dt($tok);
                    if ($ex['ts'] !== null) { $exdates[] = (int)$ex['ts']; }
                }
            }
        } elseif (!empty($ev['EXDATE']['value'] ?? '')) { // single-line case
            foreach (explode(',', (string)$ev['EXDATE']['value']) as $tok) {
                $tok = trim($tok);
                if ($tok === '') continue;
                $ex = ics_parse_dt($tok);
                if ($ex['ts'] !== null) { $exdates[] = (int)$ex['ts']; }
            }
        }
        $norm[] = [
            'summary' => $summary,
            'uid' => $uid,
            'description' => $desc,
            'location' => $loc,
            'start_raw' => $startS,
            'end_raw' => $endS,
            'start' => $start,
            'end' => $end,
            'duration' => $durationSecs,
            'rrule' => $rrule,
            'exdates' => $exdates,
            'color' => $color,
        ];
    }
    // Sort by start
    usort($norm, function($a,$b){ return ($a['start']['ts'] ?? PHP_INT_MAX) <=> ($b['start']['ts'] ?? PHP_INT_MAX); });
    return $norm;
}

/**
 * Unescape iCalendar TEXT per RFC 5545: \n or \N => newline, \, => comma, \; => semicolon, \\ => backslash.
 * Leaves other sequences intact.
 */
function ics_text_unescape(string $s): string {
    if ($s === '') return '';
    // Replace escaped sequences in a safe order (backslash last to avoid double-processing)
    $s = str_replace(["\\n", "\\N"], "\n", $s);
    $s = str_replace(["\\,", "\\;"], [",", ";"], $s);
    $s = str_replace("\\\\", "\\", $s);
    return $s;
}

function ics_parse_dt(string $s): array {
    $s = trim($s);
    if ($s === '') return ['ts' => null, 'display' => ''];
    // Patterns: 20250102T090000Z or 20250102 or 20250102T090000
    try {
        if (str_ends_with($s, 'Z')) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $s, new DateTimeZone('UTC'));
            if ($dt) {
                $local = $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                return ['ts' => $dt->getTimestamp(), 'display' => $local->format('Y-m-d H:i')];
            }
        }
        if (preg_match('/^\d{8}T\d{6}$/', $s)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $s);
            if ($dt) return ['ts' => $dt->getTimestamp(), 'display' => $dt->format('Y-m-d H:i')];
        }
        if (preg_match('/^\d{8}$/', $s)) {
            $dt = DateTimeImmutable::createFromFormat('Ymd', $s);
            if ($dt) return ['ts' => $dt->getTimestamp(), 'display' => $dt->format('Y-m-d')];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return ['ts' => null, 'display' => $s];
}

function ics_parse_dt_with_tz(string $s, ?string $tzid): array {
    $s = trim($s);
    if ($s === '') return ['ts' => null, 'display' => ''];
    // If UTC literal Z, parse as UTC and convert display to local
    if (str_ends_with($s, 'Z')) {
        return ics_parse_dt($s);
    }
    $tzLocal = new DateTimeZone(date_default_timezone_get());
    $tz = $tzLocal;
    if (!empty($tzid)) {
        try { $tz = new DateTimeZone($tzid); } catch (Throwable $e) { $tz = $tzLocal; }
    }
    // Try datetime and date-only
    // Datetime without Z: interpret in tz, but display in local
    if (preg_match('/^\d{8}T\d{6}$/', $s)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd\THis', $s, $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $disp = $dt->setTimezone($tzLocal)->format('Y-m-d H:i');
            return ['ts' => $ts, 'display' => $disp];
        }
    }
    if (preg_match('/^\d{8}$/', $s)) {
        $dt = DateTimeImmutable::createFromFormat('Ymd', $s, $tz);
        if ($dt) {
            $ts = $dt->getTimestamp();
            $disp = $dt->setTimezone($tzLocal)->format('Y-m-d');
            return ['ts' => $ts, 'display' => $disp];
        }
    }
    return ['ts' => null, 'display' => $s];
}

/**
 * Parse an iCalendar DURATION string (e.g., "PT1H30M", "P2DT3H") into seconds.
 * Supports weeks, days, hours, minutes, seconds. Returns null if invalid.
 */
function ics_parse_duration_to_seconds(string $s): ?int {
    $s = trim($s);
    if ($s === '') return null;
    // RFC 5545 duration format: [+-]P[nW][nD][T[nH][nM][nS]]
    if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', $s, $m)) {
        return null;
    }
    $sign = ($m[1] ?? '') === '-' ? -1 : 1;
    $w = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
    $d = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
    $h = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
    $min = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
    $sec = isset($m[6]) && $m[6] !== '' ? (int)$m[6] : 0;
    $total = $w*7*86400 + $d*86400 + $h*3600 + $min*60 + $sec;
    return $sign * $total;
}

function ics_sanitize_hex_color(string $s): ?string {
    $s = trim($s);
    // Allow forms like #RRGGBB, RRGGBB, #RGB, RGB
    if (preg_match('/^#?([0-9A-Fa-f]{6})$/', $s, $m)) {
        return '#'.strtoupper($m[1]);
    }
    if (preg_match('/^#?([0-9A-Fa-f]{3})$/', $s, $m)) {
        // Expand short to long
        $r = strtoupper($m[1][0]); $g = strtoupper($m[1][1]); $b = strtoupper($m[1][2]);
        return '#'.$r.$r.$g.$g.$b.$b;
    }
    return null;
}

function ics_parse_rrule(string $s): array {
    $out = [];
    foreach (explode(';', trim($s)) as $part) {
        if ($part === '') continue;
        $kv = explode('=', $part, 2);
        $k = strtoupper(trim($kv[0] ?? ''));
        $v = trim($kv[1] ?? '');
        if ($k === '') continue;
        if (in_array($k, ['BYDAY','BYMONTHDAY','BYMONTH','BYHOUR','BYMINUTE','BYSECOND'], true)) {
            $out[$k] = array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
        } else {
            $out[$k] = strtoupper($v);
        }
    }
    return $out;
}

function ics_week_start_ts(int $ts, string $wkst, DateTimeZone $tz): int {
    // wkst: two-letter like MO..SU; default MO
    $wk = ['SU'=>0,'MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6];
    $wkst = strtoupper($wkst);
    $wkStartDow = $wk[$wkst] ?? 1;
    $d = (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->setTime(0,0,0);
    $dow = (int)$d->format('w'); // 0=Sun..6=Sat
    $delta = ($dow - $wkStartDow + 7) % 7;
    return $d->modify('-'.$delta.' days')->getTimestamp();
}

function ics_expand_events_in_range(string $raw, int $windowStart, int $windowEnd, ?DateTimeZone $tz = null): array {
    $tz = $tz ?: new DateTimeZone(date_default_timezone_get());
    $base = ics_parse_events($raw);
    $out = [];
    foreach ($base as $e) {
        $startTs = $e['start']['ts'] ?? null;
        if ($startTs === null) continue;
        $endTs = $e['end']['ts'] ?? null;
        $isAllDay = preg_match('/^\d{8}$/', (string)($e['start_raw'] ?? '')) === 1;
        $duration = 0;
        if ($endTs !== null) {
            $duration = max(0, (int)$endTs - (int)$startTs);
        } else if (isset($e['duration']) && $e['duration'] !== null) {
            $duration = max(0, (int)$e['duration']);
        } else {
            $duration = $isAllDay ? 86400 : 3600;
        }

        $rr = $e['rrule'] ?? [];
        if (!$rr) {
            // Non-recurring
            if ($startTs >= $windowStart && $startTs <= $windowEnd) {
                $e['all_day'] = $isAllDay;
                $out[] = $e;
            }
            continue;
        }

        $freq = strtoupper((string)($rr['FREQ'] ?? ''));
        $untilTs = null;
        if (!empty($rr['UNTIL'] ?? '')) {
            $pr = ics_parse_dt((string)$rr['UNTIL']);
            $untilTs = $pr['ts'] ?? null;
        }
        $interval = max(1, (int)($rr['INTERVAL'] ?? 1));
        $exdates = array_map('intval', $e['exdates'] ?? []);
        $exSet = array_flip($exdates);

        if ($freq === 'WEEKLY') {
            $wkst = strtoupper((string)($rr['WKST'] ?? 'MO'));
            $byday = $rr['BYDAY'] ?? [];
            if (!$byday) {
                $byday = [strtoupper((new DateTimeImmutable('@'.$startTs))->setTimezone($tz)->format('D'))];
                // Map short to ICS two-letter
                $map = ['SUN'=>'SU','MON'=>'MO','TUE'=>'TU','WED'=>'WE','THU'=>'TH','FRI'=>'FR','SAT'=>'SA'];
                $byday = [$map[$byday[0]] ?? 'MO'];
            }
            $mapDow = ['SU'=>0,'MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6];
            $byNums = array_values(array_map(fn($d) => $mapDow[strtoupper(substr($d,-2))] ?? null, $byday));
            $byNums = array_values(array_filter($byNums, fn($v)=>$v!==null));

            $anchorWeekStart = ics_week_start_ts($startTs, $wkst, $tz);
            // Iterate each day of window
            for ($dayTs = $windowStart; $dayTs <= $windowEnd; $dayTs += 86400) {
                $dLocal = (new DateTimeImmutable('@'.$dayTs))->setTimezone($tz)->setTime(0,0,0);
                $dow = (int)$dLocal->format('w');
                if (!in_array($dow, $byNums, true)) continue;
                $curWeekStart = ics_week_start_ts($dayTs, $wkst, $tz);
                $weeksDiff = intdiv(($curWeekStart - $anchorWeekStart), 7*86400);
                if ($weeksDiff < 0 || ($weeksDiff % $interval) !== 0) continue;
                // Instance start at base time
                $baseLocal = (new DateTimeImmutable('@'.$startTs))->setTimezone($tz);
                $instStart = $dLocal->setTime((int)$baseLocal->format('H'), (int)$baseLocal->format('i'), (int)$baseLocal->format('s'))->getTimestamp();
                if ($instStart < $startTs) continue; // not before DTSTART
                if ($untilTs !== null && $instStart > $untilTs) continue;
                // Exdates: match exact timestamp; also try date-only match
                $skip = false;
                if (isset($exSet[$instStart])) { $skip = true; }
                if (!$skip) {
                    $instYmd = $dLocal->format('Ymd');
                    foreach ($exdates as $ex) {
                        $exYmd = (new DateTimeImmutable('@'.$ex))->setTimezone($tz)->format('Ymd');
                        if ($exYmd === $instYmd) { $skip = true; break; }
                    }
                }
                if ($skip) continue;

                $inst = $e;
                $inst['start'] = ['ts' => $instStart, 'display' => (new DateTimeImmutable('@'.$instStart))->setTimezone($tz)->format('Y-m-d H:i')];
                $instEnd = $instStart + $duration;
                $inst['end'] = ['ts' => $instEnd, 'display' => (new DateTimeImmutable('@'.$instEnd))->setTimezone($tz)->format('Y-m-d H:i')];
                $inst['all_day'] = $isAllDay;
                $out[] = $inst;
            }
            continue;
        }

        if ($freq === 'DAILY') {
            $baseLocal = (new DateTimeImmutable('@'.$startTs))->setTimezone($tz);
            // Find first occurrence not before windowStart
            $first = max($startTs, $windowStart);
            // Align to interval days from DTSTART
            $daysDiff = intdiv(((int)floor(($first - $startTs)/86400)), 1);
            $offsetDays = ($daysDiff % $interval === 0) ? 0 : ($interval - ($daysDiff % $interval));
            for ($instStart = $startTs + ($daysDiff + $offsetDays)*86400; $instStart <= $windowEnd; $instStart += $interval*86400) {
                if ($instStart < $windowStart) continue;
                if ($untilTs !== null && $instStart > $untilTs) break;
                // Exdate matches
                if (isset($exSet[$instStart])) continue;
                $inst = $e;
                $inst['start'] = ['ts' => $instStart, 'display' => (new DateTimeImmutable('@'.$instStart))->setTimezone($tz)->format('Y-m-d H:i')];
                $instEnd = $instStart + $duration;
                $inst['end'] = ['ts' => $instEnd, 'display' => (new DateTimeImmutable('@'.$instEnd))->setTimezone($tz)->format('Y-m-d H:i')];
                $inst['all_day'] = $isAllDay;
                $out[] = $inst;
            }
            continue;
        }

        if ($freq === 'YEARLY') {
            $baseLocal = (new DateTimeImmutable('@'.$startTs))->setTimezone($tz);
            $startYear = (int)$baseLocal->format('Y');
            $byMonth = array_map('intval', $rr['BYMONTH'] ?? []);
            if (!$byMonth) { $byMonth = [(int)$baseLocal->format('n')]; }
            $byMonthDay = array_map('intval', $rr['BYMONTHDAY'] ?? []);
            if (!$byMonthDay) { $byMonthDay = [(int)$baseLocal->format('j')]; }

            for ($dayTs = $windowStart; $dayTs <= $windowEnd; $dayTs += 86400) {
                $dLocal = (new DateTimeImmutable('@'.$dayTs))->setTimezone($tz)->setTime(0,0,0);
                $m = (int)$dLocal->format('n');
                $dom = (int)$dLocal->format('j');
                if (!in_array($m, $byMonth, true) || !in_array($dom, $byMonthDay, true)) continue;
                $yearsDiff = (int)$dLocal->format('Y') - $startYear;
                if ($yearsDiff < 0 || ($yearsDiff % $interval) !== 0) continue;
                // Instance at base time or midnight for all-day
                $instStart = $isAllDay
                  ? $dLocal->getTimestamp()
                  : $dLocal->setTime((int)$baseLocal->format('H'), (int)$baseLocal->format('i'), (int)$baseLocal->format('s'))->getTimestamp();
                if ($instStart < $startTs) continue;
                if ($untilTs !== null && $instStart > $untilTs) continue;
                // EXDATE day match
                $skip = false;
                $instYmd = $dLocal->format('Ymd');
                foreach ($exdates as $ex) {
                    $exYmd = (new DateTimeImmutable('@'.$ex))->setTimezone($tz)->format('Ymd');
                    if ($exYmd === $instYmd) { $skip = true; break; }
                }
                if ($skip) continue;
                $inst = $e;
                $inst['start'] = ['ts' => $instStart, 'display' => (new DateTimeImmutable('@'.$instStart))->setTimezone($tz)->format('Y-m-d H:i')];
                $instEnd = $isAllDay ? ($instStart + 86400) : ($instStart + $duration);
                $inst['end'] = ['ts' => $instEnd, 'display' => (new DateTimeImmutable('@'.$instEnd))->setTimezone($tz)->format('Y-m-d H:i')];
                $inst['all_day'] = $isAllDay;
                $out[] = $inst;
            }
            continue;
        }

        // For unsupported FREQ, include base only if in range
        if ($startTs >= $windowStart && $startTs <= $windowEnd) {
            $e['all_day'] = $isAllDay;
            $out[] = $e;
        }
    }
    usort($out, fn($a,$b) => ($a['start']['ts'] ?? 0) <=> ($b['start']['ts'] ?? 0));
    return $out;
}
