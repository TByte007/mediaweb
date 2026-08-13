<?php

/**
 * Sidecar subtitles as UTF-8 WebVTT: ?id= video id, ?n= track index (default 0).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../subs.php';

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$n = isset($_GET['n']) && ctype_digit((string)$_GET['n']) ? (int)$_GET['n'] : 0;

if ($id <= 0) { http_response_code(400); exit; }
if (!file_exists(MW_DB)) { http_response_code(500); exit; }

$db = new SQLite3(MW_DB);
$db->busyTimeout(5000);
$row = $db->querySingle(
    'SELECT filepath, frame_rate, is_deleted FROM videos WHERE id = ' . $id,
    true
);
$db->close();

if (!$row || !empty($row['is_deleted'])) { http_response_code(404); exit; }

$tracks = findSidecarSubs((string)$row['filepath']);
if (!isset($tracks[$n])) { http_response_code(404); exit; }

$vtt = sidecarToVtt($tracks[$n]['path'], (float)($row['frame_rate'] ?? 0));
if ($vtt === '') { http_response_code(404); exit; }

header('Content-Type: text/vtt; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $vtt;
