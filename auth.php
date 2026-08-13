<?php

/**
 * Session login + roles (viewer / manager / admin).
 */

declare(strict_types=1);

function mwEnsureUsersTable(\SQLite3 $db): void
{
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('viewer', 'manager', 'admin')),
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
SQL
    );
}

function mwAuthDb(): ?\SQLite3
{
    static $db = null;
    if ($db instanceof \SQLite3) return $db;
    if (!file_exists(MW_DB)) return null;
    $db = new \SQLite3(MW_DB);
    $db->busyTimeout(5000);
    mwEnsureUsersTable($db);
    return $db;
}

function mwAuthStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('mw_sess');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => MW_BASE_URL,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** @return array{id: int, username: string, role: string}|null */
function mwUser(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    mwAuthStart();
    $id = (int)($_SESSION['uid'] ?? 0);
    $row = $id > 0 ? mwAuthDb()?->querySingle('SELECT id, username, role FROM users WHERE id = ' . $id, true) : null;
    $cached = $row ? [
        'id' => (int)$row['id'],
        'username' => (string)$row['username'],
        'role' => (string)$row['role'],
    ] : null;
    return $cached;
}

function mwAllowNet(): bool
{
    if (MW_ALLOW_NETS === []) return false;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (str_starts_with($ip, '::ffff:')) $ip = substr($ip, 7);
    $ipB = inet_pton($ip);
    if ($ipB === false) return false;
    foreach (MW_ALLOW_NETS as $cidr) {
        if (!str_contains($cidr, '/')) $cidr .= str_contains($ip, ':') ? '/128' : '/32';
        [$net, $bits] = explode('/', $cidr, 2);
        $netB = inet_pton($net);
        if ($netB === false || strlen($ipB) !== strlen($netB)) continue;
        $bits = (int)$bits;
        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8) $mask .= chr((0xFF << (8 - $bits % 8)) & 0xFF);
        $mask = str_pad($mask, strlen($ipB), "\x00");
        if (($ipB & $mask) === ($netB & $mask)) return true;
    }
    return false;
}

function mwRoleOk(string $have, string $need): bool
{
    $rank = ['viewer' => 0, 'manager' => 1, 'admin' => 2];
    return ($rank[$have] ?? -1) >= ($rank[$need] ?? 99);
}

/** @return array{id: int, username: string, role: string} */
function mwRequireLogin(): array
{
    $u = mwUser();
    if ($u !== null) return $u;
    if (mwAllowNet()) return ['id' => 0, 'username' => 'Guest', 'role' => 'viewer'];
    header('Location: ' . MW_BASE_URL . 'login.php');
    exit;
}

/** @return array{id: int, username: string, role: string} */
function mwRequireRole(string $need): array
{
    $u = mwRequireLogin();
    if (!mwRoleOk($u['role'], $need)) {
        http_response_code(403);
        exit('Forbidden');
    }
    return $u;
}

function mwCsrfToken(): string
{
    mwAuthStart();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function mwCsrfCheck(?string $token): bool
{
    mwAuthStart();
    $have = $_SESSION['csrf'] ?? '';
    return is_string($token) && $have !== '' && hash_equals($have, $token);
}

function mwLogin(int $userId): void
{
    mwAuthStart();
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
}

function mwLogout(): void
{
    mwAuthStart();
    $p = session_get_cookie_params();
    $_SESSION = [];
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $p['path'],
        'secure' => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
    session_destroy();
}
