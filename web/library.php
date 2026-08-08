<?php

declare(strict_types=1);

require_once __DIR__ . '/layout/helpers.php';
require_once __DIR__ . '/../config.php';

$dbFile = MW_DB;
$basePath = MW_BASE_URL;

$search = trim($_GET['q'] ?? '');
$len = $_GET['len'] ?? '';
$lenFilters = [
    'movie'  => 'duration_secs >= 3600',
    'series' => 'duration_secs >= 600 AND duration_secs < 3600',
    'clip'   => 'duration_secs > 0 AND duration_secs < 600',
];
if (!isset($lenFilters[$len])) $len = '';
$showLenFilters = true;

$limit = 36;
$offset = (int)(($_GET['page'] ?? 1) - 1) * $limit;

if (!file_exists($dbFile)) {
    http_response_code(500);
    require __DIR__ . '/layout/header.php';
    echo "<h1>Database not found</h1><p>Run <code>php scan.php</code> first.</p>";
    require __DIR__ . '/layout/footer.php';
    exit;
}

$db = new SQLite3($dbFile);
$db->busyTimeout(5000);

// Build query (hide soft-deleted files)
$where = ["is_deleted = 0"];
$params = [];

if ($search) {
    $where[] = "filename LIKE :q OR title LIKE :q";
    $params['q'] = "%$search%";
}
if ($len !== '') $where[] = $lenFilters[$len];

$whereClause = "WHERE " . implode(" AND ", $where);
$orderBy = $search ? "ORDER BY title ASC" : "ORDER BY playback_count DESC, id DESC";

$sql = "SELECT id, filename, filepath, video_format, width, height, duration_secs,
               filesize_bytes, audio_tracks, subtitle_tracks, title, playback_count, needs_fix
        FROM videos $whereClause $orderBy
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
if ($params) foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
$stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
$result = $stmt->execute();

$videos = [];
while ($row = $result->fetchArray(2)) {
    $videos[] = [
        'id'               => (int)$row[0],
        'filename'         => trim($row[1]),
        'filepath'         => $row[2],
        'video_format'     => $row[3],
        'width'            => (int)($row[4] ?? 0),
        'height'           => (int)($row[5] ?? 0),
        'duration_secs'    => (float)($row[6] ?? 0),
        'filesize_bytes'   => (int)($row[7] ?? 0),
        'audio_tracks'     => (int)($row[8] ?? 0),
        'subtitle_tracks'  => (int)($row[9] ?? 0),
        'title'            => videoPrettyTitle((string)$row[1], $row[10] ?? null),
        'playback_count'   => (int)($row[11] ?? 0),
        'needs_fix'        => (int)($row[12] ?? 0),
    ];
}
$result->finalize();

$countRow = $db->prepare("SELECT COUNT(*) FROM videos $whereClause");
if ($params) foreach ($params as $k => $v) $countRow->bindValue($k, $v);
$total = (int)$countRow->execute()->fetchArray(2)[0];
$db->close();

$pages = max(1, (int)ceil($total / $limit));
$currentPage = max(1, min((int)($_GET['page'] ?? 1), $pages));

require __DIR__ . '/layout/header.php';
require __DIR__ . '/layout/grid.php';
require __DIR__ . '/layout/footer.php';
