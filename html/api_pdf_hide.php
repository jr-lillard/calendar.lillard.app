<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

try {
  $config = require __DIR__.'/config.php';
  $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_pass'], $config['db_opts'] ?? []);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db']);
  exit;
}

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
} catch (Throwable $e) { /* ignore */ }

$uid = (int)$_SESSION['user_id'];
$action = isset($_POST['action']) ? (string)$_POST['action'] : 'hide';
$calId = isset($_POST['calendar_id']) ? (int)$_POST['calendar_id'] : 0;
$startTs = isset($_POST['start_ts']) ? (int)$_POST['start_ts'] : 0;
$summary = isset($_POST['summary']) ? (string)$_POST['summary'] : '';
if ($calId <= 0 || $startTs <= 0 || $summary === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_request']);
  exit;
}
$sumHash = sha1(strtolower(trim($summary)));

try {
  if ($action === 'unhide') {
    $st = $pdo->prepare('DELETE FROM pdf_hidden_events WHERE user_id=? AND calendar_id=? AND start_ts=? AND summary_hash=?');
    $st->execute([$uid, $calId, $startTs, $sumHash]);
    echo json_encode(['ok'=>true,'hidden'=>false]);
  } else {
    $st = $pdo->prepare('INSERT IGNORE INTO pdf_hidden_events (user_id, calendar_id, start_ts, summary_hash) VALUES (?,?,?,?)');
    $st->execute([$uid, $calId, $startTs, $sumHash]);
    echo json_encode(['ok'=>true,'hidden'=>true]);
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_write']);
}
exit;

