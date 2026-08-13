<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (isset($_GET['logout'])) {
    mwLogout();
    header('Location: ' . MW_BASE_URL . (mwAllowNet() ? '' : 'login.php'));
    exit;
}

if (mwUser() !== null) {
    header('Location: ' . MW_BASE_URL);
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!mwCsrfCheck($_POST['csrf'] ?? null)) {
        $error = 'Session expired. Try again.';
    } else {
        $name = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $dbh = mwAuthDb();
        $row = false;
        if ($dbh !== null && $name !== '') {
            $st = $dbh->prepare('SELECT id, password_hash FROM users WHERE username = :u');
            $st->bindValue(':u', $name, SQLITE3_TEXT);
            $row = $st->execute()->fetchArray(SQLITE3_ASSOC);
        }
        if ($row && password_verify($pass, (string)$row['password_hash'])) {
            mwLogin((int)$row['id']);
            header('Location: ' . MW_BASE_URL);
            exit;
        }
        $error = 'Invalid username or password.';
    }
}

$empty = (int)(mwAuthDb()?->querySingle('SELECT COUNT(*) FROM users') ?? 0) === 0;
$csrf = mwCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MediaWeb - Sign in</title>
<style>
:root {
    --bg: #0b0d14;
    --bg2: #131625;
    --accent: #5e72e4;
    --muted: #8795b0;
    --text: #e4e9f2;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}
.logo {
    width: fit-content; margin: 0 auto 28px; text-align: left;
    font-size: 32px; font-weight: 700; color: var(--accent); line-height: 1.1;
}
.logo span { color: var(--text); }
.logo .logo-owner {
    display: block; margin-bottom: 6px;
    font-size: 13px; font-weight: 600; letter-spacing: 0.14em;
    text-transform: uppercase; color: var(--muted);
}
.login-card {
    max-width: 360px; margin: 18vh auto 0; padding: 28px;
    background: var(--bg2); border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.04);
}
label { display: block; font-size: 13px; color: var(--muted); margin-bottom: 6px; }
input[type=text], input[type=password] {
    width: 100%; height: 44px; padding: 0 14px; margin-bottom: 14px;
    border-radius: 10px; border: 1px solid rgba(255,255,255,0.06);
    background: rgba(255,255,255,0.03); color: var(--text);
    font-size: 16px; outline: none;
}
input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(94,114,228,0.18); }
button {
    width: 100%; height: 44px; margin-top: 4px; border: 0; border-radius: 10px;
    background: var(--accent); color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
}
button:hover { filter: brightness(1.08); }
.err { color: #f0c14d; font-size: 13px; margin-bottom: 14px; }
.hint { color: var(--muted); font-size: 13px; line-height: 1.45; margin-bottom: 14px; }
</style>
</head>
<body>
<form class="login-card" method="post" action="">
    <div class="logo"><?php if (MW_OWNER !== ''): ?><span class="logo-owner"><?= htmlspecialchars(MW_OWNER) ?></span><?php endif; ?>Media<span>Web</span></div>
<?php if ($empty): ?>
    <p class="hint">No users yet. On the server: <code>php users.php add NAME --role=admin</code></p>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <p class="err"><?= htmlspecialchars($error) ?></p>
<?php endif; ?>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
    <label for="username">Username</label>
    <input id="username" name="username" type="text" autocomplete="username" required autofocus>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <button type="submit">Sign in</button>
</form>
</body>
</html>
