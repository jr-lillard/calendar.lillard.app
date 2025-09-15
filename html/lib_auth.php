<?php
declare(strict_types=1);

/**
 * Authentication helpers: magic-link tokens and long-lived device tokens.
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
    return $cfg;
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
