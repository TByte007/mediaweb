#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$args = array_slice($argv, 1);
$cmd = $args[0] ?? '';
$roleOpt = '';
$positionals = [];
foreach (array_slice($args, 1) as $a) {
    if ($a === '--help') {
        $cmd = 'help';
        break;
    }
    if (str_starts_with($a, '--role=')) {
        $roleOpt = substr($a, 7);
        continue;
    }
    if ($a !== '' && $a[0] !== '-') $positionals[] = $a;
}

if ($cmd === '' || $cmd === 'help' || $cmd === '--help') {
    echo <<<USAGE
Usage: php users.php <command>

Commands:
    add NAME --role=viewer|manager|admin
    passwd NAME
    role NAME ROLE
    list
    del NAME

Password is prompted on stdin (not accepted as a flag).

USAGE;
    exit($cmd === '' ? 1 : 0);
}

$db = new \SQLite3(MW_DB);
$db->busyTimeout(5000);
mwEnsureUsersTable($db);

$roles = ['viewer' => 1, 'manager' => 1, 'admin' => 1];

$load = static function (\SQLite3 $db, string $name): ?array {
    $st = $db->prepare('SELECT id, username, role FROM users WHERE username = :u');
    $st->bindValue(':u', $name, SQLITE3_TEXT);
    $res = $st->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
};

$adminCount = static function (\SQLite3 $db): int {
    return (int)$db->querySingle("SELECT COUNT(*) FROM users WHERE role = 'admin'");
};

$promptPass = static function (string $label): string {
    fwrite(STDERR, $label . ': ');
    $hide = PHP_OS_FAMILY !== 'Windows';
    if ($hide) system('stty -echo 2>/dev/null');
    $line = fgets(STDIN);
    if ($hide) {
        system('stty echo 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    return $line === false ? '' : rtrim($line, "\r\n");
};

$readNewPass = static function () use ($promptPass): string {
    $a = $promptPass('Password');
    $b = $promptPass('Confirm');
    if ($a === '') {
        fwrite(STDERR, "Error: password cannot be empty.\n");
        exit(1);
    }
    if ($a !== $b) {
        fwrite(STDERR, "Error: passwords do not match.\n");
        exit(1);
    }
    return $a;
};

if ($cmd === 'list') {
    $rs = $db->query('SELECT id, username, role, created_at FROM users ORDER BY username COLLATE NOCASE');
    $n = 0;
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
        printf("%-16s %-8s  id=%d  %s\n", $row['username'], $row['role'], (int)$row['id'], $row['created_at']);
        $n++;
    }
    if ($n === 0) echo "(no users)\n";
    exit(0);
}

if ($cmd === 'add') {
    $name = $positionals[0] ?? '';
    $role = $roleOpt !== '' ? $roleOpt : 'viewer';
    if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $name) !== 1) {
        fwrite(STDERR, "Error: name must be 1–32 letters, digits, or underscore.\n");
        exit(1);
    }
    if (!isset($roles[$role])) {
        fwrite(STDERR, "Error: role must be viewer, manager, or admin.\n");
        exit(1);
    }
    if ($load($db, $name)) {
        fwrite(STDERR, "Error: user already exists.\n");
        exit(1);
    }
    $hash = password_hash($readNewPass(), PASSWORD_DEFAULT);
    $st = $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (:u, :p, :r)');
    $st->bindValue(':u', $name, SQLITE3_TEXT);
    $st->bindValue(':p', $hash, SQLITE3_TEXT);
    $st->bindValue(':r', $role, SQLITE3_TEXT);
    $st->execute();
    echo "Added $name ($role)\n";
    exit(0);
}

if ($cmd === 'passwd') {
    $name = $positionals[0] ?? '';
    $row = $name !== '' ? $load($db, $name) : null;
    if (!$row) {
        fwrite(STDERR, "Error: user not found.\n");
        exit(1);
    }
    $hash = password_hash($readNewPass(), PASSWORD_DEFAULT);
    $st = $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
    $st->bindValue(':p', $hash, SQLITE3_TEXT);
    $st->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
    $st->execute();
    echo "Password updated for {$row['username']}\n";
    exit(0);
}

if ($cmd === 'role') {
    $name = $positionals[0] ?? '';
    $role = $positionals[1] ?? '';
    $row = $name !== '' ? $load($db, $name) : null;
    if (!$row) {
        fwrite(STDERR, "Error: user not found.\n");
        exit(1);
    }
    if (!isset($roles[$role])) {
        fwrite(STDERR, "Error: role must be viewer, manager, or admin.\n");
        exit(1);
    }
    if ($row['role'] === 'admin' && $role !== 'admin' && $adminCount($db) < 2) {
        fwrite(STDERR, "Error: cannot demote the last admin.\n");
        exit(1);
    }
    $st = $db->prepare('UPDATE users SET role = :r WHERE id = :id');
    $st->bindValue(':r', $role, SQLITE3_TEXT);
    $st->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
    $st->execute();
    echo "{$row['username']} is now $role\n";
    exit(0);
}

if ($cmd === 'del') {
    $name = $positionals[0] ?? '';
    $row = $name !== '' ? $load($db, $name) : null;
    if (!$row) {
        fwrite(STDERR, "Error: user not found.\n");
        exit(1);
    }
    if ($row['role'] === 'admin' && $adminCount($db) < 2) {
        fwrite(STDERR, "Error: cannot delete the last admin.\n");
        exit(1);
    }
    $st = $db->prepare('DELETE FROM users WHERE id = :id');
    $st->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
    $st->execute();
    echo "Deleted {$row['username']}\n";
    exit(0);
}

fwrite(STDERR, "Error: unknown command. Try php users.php --help\n");
exit(1);
