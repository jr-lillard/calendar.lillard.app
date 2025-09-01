<?php
declare(strict_types=1);

// Generates a PDF of the weekly view via wkhtmltopdf if available.
// Usage: export_pdf.php?id=123&date=YYYY-MM-DD

// Basic input sanitization
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
  ? (string)$_GET['date']
  : '';
if ($id <= 0) {
  http_response_code(400);
  echo 'Missing or invalid calendar id';
  exit;
}

// Build absolute URL to week view with print hint
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
$qs = http_build_query(array_filter(['id' => $id, 'date' => $date]));
$url = sprintf('%s://%s%s/calendar_week.php?%s', $scheme, $host, $basePath, $qs);

// Locate wkhtmltopdf
$bin = trim((string)@shell_exec('command -v wkhtmltopdf 2>/dev/null'));
if ($bin === '') {
  http_response_code(501);
  header('Content-Type: text/plain; charset=utf-8');
  echo "wkhtmltopdf is not installed on the server.\n";
  echo "Install on Ubuntu: sudo apt-get install wkhtmltopdf\n";
  echo "Then reload this page to export PDF.";
  exit;
}

// Render PDF to stdout
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="calendar-week.pdf"');
$cmd = sprintf('%s --print-media-type --encoding utf-8 --orientation Landscape --page-size Letter --margin-top 8mm --margin-bottom 8mm --margin-left 8mm --margin-right 8mm %s -',
  escapeshellarg($bin),
  escapeshellarg($url)
);
$proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($proc)) {
  http_response_code(500);
  echo 'Failed to spawn wkhtmltopdf';
  exit;
}
// Stream PDF to client
fpassthru($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
$exitCode = proc_close($proc);
if ($exitCode !== 0) {
  // Send minimal error details
  error_log('wkhtmltopdf failed: '.$stderr);
}

