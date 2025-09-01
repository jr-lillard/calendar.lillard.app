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
        $current[$name] = ['value' => $value, 'params' => $params];
    }
    // Normalize map
    $norm = [];
    foreach ($events as $ev) {
        $summary = $ev['SUMMARY']['value'] ?? '';
        $desc = $ev['DESCRIPTION']['value'] ?? '';
        $loc = $ev['LOCATION']['value'] ?? '';
        $startS = $ev['DTSTART']['value'] ?? '';
        $endS = $ev['DTEND']['value'] ?? '';
        $start = ics_parse_dt($startS);
        $end = ics_parse_dt($endS);
        $norm[] = [
            'summary' => $summary,
            'description' => $desc,
            'location' => $loc,
            'start_raw' => $startS,
            'end_raw' => $endS,
            'start' => $start,
            'end' => $end,
        ];
    }
    // Sort by start
    usort($norm, function($a,$b){ return ($a['start']['ts'] ?? PHP_INT_MAX) <=> ($b['start']['ts'] ?? PHP_INT_MAX); });
    return $norm;
}

function ics_parse_dt(string $s): array {
    $s = trim($s);
    if ($s === '') return ['ts' => null, 'display' => ''];
    // Patterns: 20250102T090000Z or 20250102 or 20250102T090000
    try {
        if (str_ends_with($s, 'Z')) {
            $dt = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $s, new DateTimeZone('UTC'));
            if ($dt) return ['ts' => $dt->getTimestamp(), 'display' => $dt->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i') . ' UTC'];
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

