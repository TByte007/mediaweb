<?php

declare(strict_types=1);

// increment-play.php - AJAX endpoint to increment playback counter

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
mwRequireLogin();

header('Content-Type: text/plain');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ok'); }

$dbFile = MW_DB;
if (!file_exists($dbFile)) exit('ok');

// Rate limit: allow max 1 play per IP per minute per video (simple cookie/token approach)
$cookieName = 'mw_play_' . $id;
if (isset($_COOKIE[$cookieName])) {
    exit('ok'); // already counted in this session
}

$db = new SQLite3($dbFile);
$db->busyTimeout(5000);
$db->exec("UPDATE videos SET playback_count = playback_count + 1 WHERE id = $id");
$db->close();

// Set cookie for 60 seconds
setcookie($cookieName, '1', time() + 60, MW_BASE_URL);
exit('ok');
