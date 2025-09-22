<?php
declare(strict_types=1);

/**
 * Authentication helpers: one-time passcodes (OTP), magic-link tokens (legacy),
 * and long-lived device tokens.
 */

function auth_config(): array {
    $cfg = require __DIR__ . '/config.php';
    $cfg['magic'] = $cfg['magic'] ?? [];
    $cfg['magic']['base_url'] = $cfg['magic']['base_url'] ?? '';
    // Default magic link TTL is 15 minutes; hard cap at 15 regardless of config
    $cfg['magic']['token_ttl_minutes'] = (int)($cfg['magic']['token_ttl_minutes'] ?? 15);
    $cfg['magic']['max_uses'] = (int)($cfg['magic']['max_uses'] ?? 10);
    $cfg['magic']['device_cookie_name'] = $cfg['magic']['device_cookie_name'] ?? 'cal_dev';
    $cfg['magic']['device_cookie_days'] = (int)($cfg['magic']['device_cookie_days'] ?? (365*5));
    // SMTP2GO (optional) for sending OTP emails
    $cfg['smtp2go'] = $cfg['smtp2go'] ?? [];
    $cfg['smtp2go']['api_key'] = $cfg['smtp2go']['api_key'] ?? '';
    $cfg['smtp2go']['from_email'] = $cfg['smtp2go']['from_email'] ?? 'calendar@lillard.dev';
    $cfg['smtp2go']['from_name'] = $cfg['smtp2go']['from_name'] ?? 'Family Calendar';
    // OTP expiry (minutes). Code clamps to a short period, default 10.
    $cfg['otp_ttl_minutes'] = (int)($cfg['otp_ttl_minutes'] ?? 10);
    return $cfg;
}

/**
 * Canonicalize a login email for user lookup without changing the destination address.
 * This allows aliases like user@lillard.org to match a user stored as user@lillard.dev.
 */
function auth_canonicalize_login_email(string $email): string {
    $email = trim(strtolower($email));
    if ($email === '' || strpos($email, '@') === false) return $email;
    [$local, $domain] = explode('@', $email, 2);
    $cfg = auth_config();
    $map = (array)($cfg['email_alias_domains'] ?? []);
    if (isset($map[$domain]) && is_string($map[$domain]) && $map[$domain] !== '') {
        $domain = strtolower((string)$map[$domain]);
    }
    return $local . '@' . $domain;
}

function auth_pdo(): PDO {
    $cfg = auth_config();
    $pdo = new PDO($cfg['db_dsn'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_opts'] ?? []);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

function auth_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS magic_tokens (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARBINARY(32) NOT NULL UNIQUE,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        max_uses INT NOT NULL DEFAULT 10,
        uses INT NOT NULL DEFAULT 0,
        last_used_at DATETIME NULL,
        INDEX (user_id), INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code_hash VARBINARY(32) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) NOT NULL DEFAULT 0,
        used_at DATETIME NULL,
        INDEX (user_id), INDEX (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS device_tokens (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        device_hash VARBINARY(32) NOT NULL UNIQUE,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        revoked TINYINT(1) NOT NULL DEFAULT 0,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function auth_random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function auth_hash(string $raw): string {
    return hash('sha256', $raw, true); // binary
}

function auth_set_device_cookie(int $userId): void {
    $cfg = auth_config();
    $pdo = auth_pdo();
    auth_ensure_tables($pdo);

    $raw = auth_random_token(32);
    $hash = auth_hash($raw);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO device_tokens (user_id, device_hash, user_agent, created_at, last_used_at) VALUES (?,?,?,?,?)");
    $stmt->execute([$userId, $hash, $ua, $now, $now]);

    $days = max(1, (int)$cfg['magic']['device_cookie_days']);
    $cookieName = (string)$cfg['magic']['device_cookie_name'];
    $cookieVal = $raw;
    $expire = time() + $days*86400;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie($cookieName, $cookieVal, [
        'expires' => $expire,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function auth_try_device_login(): bool {
    if (isset($_SESSION['user_id'])) return true;
    $cfg = auth_config();
    $cookieName = (string)$cfg['magic']['device_cookie_name'];
    $raw = $_COOKIE[$cookieName] ?? '';
    if ($raw === '') return false;
    try {
        $pdo = auth_pdo();
        auth_ensure_tables($pdo);
        $hash = auth_hash($raw);
        $stmt = $pdo->prepare("SELECT user_id FROM device_tokens WHERE device_hash = ? AND revoked = 0 LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['user_id'] = (int)$row['user_id'];
            if (PHP_SESSION_ACTIVE === session_status()) {
                session_regenerate_id(true);
            }
            // touch last_used
            $pdo->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE device_hash = ?")->execute([$hash]);
            return true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

/**
 * Issue a 6-digit OTP code for the given user. Returns the raw code.
 * TTL is clamped to a short interval (default 10 minutes).
 */
function auth_issue_otp(PDO $pdo, int $userId, int $ttlMinutes = 10): string {
    auth_ensure_tables($pdo);
    $ttl = max(1, min(60, (int)$ttlMinutes)); // 1..60 minutes
    // 6-digit numeric code with leading zeros allowed
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = auth_hash($code);
    $now = new DateTimeImmutable('now');
    $exp = $now->add(new DateInterval('PT' . $ttl . 'M'));
    // Optional: clean up old codes for this user
    $pdo->prepare("DELETE FROM otp_codes WHERE user_id = ? AND (expires_at < NOW() OR used = 1)")->execute([$userId]);
    $stmt = $pdo->prepare("INSERT INTO otp_codes (user_id, code_hash, created_at, expires_at, used) VALUES (?,?,?,?,0)");
    $stmt->execute([$userId, $hash, $now->format('Y-m-d H:i:s'), $exp->format('Y-m-d H:i:s')]);
    return $code;
}

/**
 * Verify a 6-digit OTP for the given user. On success, marks it used and returns true.
 */
function auth_verify_otp(PDO $pdo, int $userId, string $code): bool {
    auth_ensure_tables($pdo);
    $code = preg_replace('/\D+/', '', $code); // digits only
    if ($code === '' || strlen($code) > 6) return false;
    $hash = auth_hash(str_pad($code, 6, '0', STR_PAD_LEFT));
    $stmt = $pdo->prepare("SELECT id, expires_at, used FROM otp_codes WHERE user_id = ? AND code_hash = ? LIMIT 1");
    $stmt->execute([$userId, $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['used'] === 1) return false;
    if (new DateTimeImmutable((string)$row['expires_at']) < new DateTimeImmutable('now')) return false;
    $pdo->prepare("UPDATE otp_codes SET used = 1, used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    return true;
}

/**
 * Send an email via SMTP2GO HTTP API. Returns true on success.
 * Requires config smtp2go.api_key and smtp2go.from_email.
 */
function auth_send_email(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): bool {
    $cfg = auth_config();
    $apiKey = trim((string)($cfg['smtp2go']['api_key'] ?? ''));
    $fromEmail = trim((string)($cfg['smtp2go']['from_email'] ?? ''));
    $fromName = trim((string)($cfg['smtp2go']['from_name'] ?? ''));
    if ($apiKey === '' || $fromEmail === '') {
        return false;
    }
    $payload = [
        // API key will be passed via HTTP header per SMTP2GO v3 docs
        'to' => [ $toEmail ],
        'sender' => $fromEmail,
        'subject' => $subject,
        'text_body' => $textBody,
    ];
    if ($fromName !== '') {
        $payload['from'] = sprintf('%s <%s>', $fromName, $fromEmail);
    }
    if ($htmlBody !== null && $htmlBody !== '') {
        $payload['html_body'] = $htmlBody;
    }
    $json = json_encode($payload);
    if ($json === false) return false;

    $ch = curl_init('https://api.smtp2go.com/v3/email/send');
    if ($ch === false) return false;
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Smtp2go-Api-Key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        // Log transport error
        @auth_log_smtp2go("HTTP transport error: code=$code err=" . ($err ?: 'unknown'));
        return false;
    }
    // SMTP2GO returns JSON with { 'data': { 'succeeded': 1, ... } } on success
    $decoded = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && is_array($decoded)) {
        $succeeded = $decoded['data']['succeeded'] ?? null;
        if ($succeeded === 1) return true;
        // Log non-successful response
        @auth_log_smtp2go('Non-success response: http=' . $code . ' body=' . substr($resp, 0, 1000));
    }
    // Log unexpected / error HTTP codes
    @auth_log_smtp2go('HTTP error or invalid JSON: http=' . $code . ' body=' . substr($resp, 0, 1000));
    return false;
}

/**
 * Append a line to sessions/otp_error.log with SMTP2GO details.
 */
function auth_log_smtp2go(string $line): void {
    $logDir = __DIR__ . '/sessions';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/otp_error.log';
    $stamp = date('c');
    @file_put_contents($logFile, $stamp . ' smtp2go: ' . $line . "\n", FILE_APPEND);
}

function auth_issue_magic_link(PDO $pdo, int $userId): string {
    $cfg = auth_config();
    auth_ensure_tables($pdo);
    $raw = auth_random_token(32);
    $hash = auth_hash($raw);
    $maxUses = (int)$cfg['magic']['max_uses'];
    $ttlMin = (int)$cfg['magic']['token_ttl_minutes'];
    // Enforce a strict 15-minute maximum lifetime
    $ttlMin = min(max(1, $ttlMin), 15);
    $now = new DateTimeImmutable('now');
    $exp = $now->add(new DateInterval('PT' . $ttlMin . 'M'));
    $stmt = $pdo->prepare("INSERT INTO magic_tokens (user_id, token_hash, created_at, expires_at, max_uses, uses) VALUES (?,?,?,?,?,0)");
    $stmt->execute([$userId, $hash, $now->format('Y-m-d H:i:s'), $exp->format('Y-m-d H:i:s'), $maxUses]);
    $base = rtrim((string)$cfg['magic']['base_url'], '/');
    $url = ($base !== '' ? $base : '') . '/magic_login.php?token=' . urlencode($raw);
    return $url;
}

function auth_consume_magic_link(PDO $pdo, string $raw): ?int {
    auth_ensure_tables($pdo);
    $hash = auth_hash($raw);
    $stmt = $pdo->prepare("SELECT id, user_id, created_at, expires_at, max_uses, uses FROM magic_tokens WHERE token_hash = ? LIMIT 1");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if ((int)$row['uses'] >= (int)$row['max_uses']) return null;
    $now = new DateTimeImmutable('now');
    // Hard 15-minute TTL regardless of stored expiry (additional safety)
    try {
        $created = new DateTimeImmutable((string)$row['created_at']);
        if ($created->add(new DateInterval('PT15M')) < $now) {
            return null;
        }
    } catch (Throwable $e) {
        return null;
    }
    if (new DateTimeImmutable((string)$row['expires_at']) < $now) return null;
    $pdo->prepare("UPDATE magic_tokens SET uses = uses+1, last_used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
    return (int)$row['user_id'];
}
