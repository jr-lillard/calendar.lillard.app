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

$uid = (int)$_SESSION['user_id'];
$calId = isset($_POST['calendar_id']) ? (int)$_POST['calendar_id'] : 0;
$startTs = isset($_POST['start_ts']) ? (int)$_POST['start_ts'] : 0;
$summary = isset($_POST['summary']) ? (string)$_POST['summary'] : '';
$which = isset($_POST['which']) ? (string)$_POST['which'] : '';
$value = isset($_POST['value']) ? (int)$_POST['value'] : 0;

if ($calId <= 0 || $startTs <= 0 || $summary === '' || ($which !== 'there' && $which !== 'back')) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_request']);
  exit;
}
$sumHash = sha1(strtolower(trim($summary)));

try {
  // Upsert
  $pdo->beginTransaction();
  $st = $pdo->prepare('INSERT INTO pdf_event_meta (user_id, calendar_id, start_ts, summary_hash, uber_there, uber_back)
                       VALUES (?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE uber_there=VALUES(uber_there), uber_back=VALUES(uber_back)');
  // Fetch existing values first
  $curr = [0,0];
  $sel = $pdo->prepare('SELECT uber_there, uber_back FROM pdf_event_meta WHERE user_id=? AND calendar_id=? AND start_ts=? AND summary_hash=?');
  $sel->execute([$uid, $calId, $startTs, $sumHash]);
  if ($row = $sel->fetch(PDO::FETCH_NUM)) { $curr = [(int)$row[0], (int)$row[1]]; }
  if ($which === 'there') { $curr[0] = $value ? 1 : 0; } else { $curr[1] = $value ? 1 : 0; }
  $st->execute([$uid, $calId, $startTs, $sumHash, $curr[0], $curr[1]]);
  $pdo->commit();
  echo json_encode(['ok'=>true, 'there'=>$curr[0]===1, 'back'=>$curr[1]===1]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'db_write']);
}
exit;

