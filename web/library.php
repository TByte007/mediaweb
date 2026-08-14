<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/layout/helpers.php';

mwRequireLogin();

$dbFile = MW_DB;
$basePath = MW_BASE_URL;

$search = trim($_GET['q'] ?? '');
$genre = isset($_GET['genre']) && ctype_digit((string)$_GET['genre']) ? (int)$_GET['genre'] : 0;
// Movies: standalone ≥50m; Clips: standalone <50m. Unknown len → Movies.
$len = $_GET['len'] ?? '';
if ($len !== 'all' && $len !== 'clip') $len = 'movie';

$limit = 40; // multiple of 5-column grid
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

$genreFilters = mwGenresForFilter($db);
if ($genre > 0 && !in_array($genre, array_column($genreFilters, 'id'), true))
    $genre = 0;

// Build query (hide soft-deleted files)
$where = ['v.is_deleted = 0'];
$params = [];

if ($search) {
    $where[] = '(v.filename LIKE :q OR v.title LIKE :q OR v.directory LIKE :q OR v.name LIKE :q)';
    $params['q'] = "%$search%";
}
if ($len === 'clip') $where[] = 'v.series_id IS NULL AND v.duration_secs < 3000';
elseif ($len === 'movie') $where[] = 'v.series_id IS NULL AND v.duration_secs >= 3000';
if ($genre > 0) {
    $where[] = "(
        (v.series_id IS NOT NULL AND (',' || IFNULL(s.genre_ids,'') || ',') LIKE :genre_like)
        OR (v.series_id IS NULL AND (',' || IFNULL(v.genre_ids,'') || ',') LIKE :genre_like)
    )";
    $params['genre_like'] = '%,'.$genre.',%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);
$orderBy = $search ? 'ORDER BY v.title ASC' : 'ORDER BY v.playback_count DESC, v.id DESC';
$from = 'FROM videos v LEFT JOIN series s ON s.id = v.series_id';

$sql = "SELECT v.id, v.filename, v.filepath, v.video_format, v.width, v.height, v.duration_secs,
               v.filesize_bytes, v.audio_tracks, v.subtitle_tracks, v.title, v.playback_count, v.needs_fix, v.name
        $from $whereClause $orderBy
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
        'title'            => videoPrettyTitle((string)$row[1], $row[10] ?? null, (string)$row[2], $row[13] ?? null),
        'playback_count'   => (int)($row[11] ?? 0),
        'needs_fix'        => (int)($row[12] ?? 0),
    ];
}
$result->finalize();

$countRow = $db->prepare("SELECT COUNT(*) $from $whereClause");
if ($params) foreach ($params as $k => $v) $countRow->bindValue($k, $v);
$total = (int)$countRow->execute()->fetchArray(2)[0];
$db->close();

$pages = max(1, (int)ceil($total / $limit));
$currentPage = max(1, min((int)($_GET['page'] ?? 1), $pages));

if (isset($_GET['partial'])) {
    require __DIR__ . '/layout/cards.php';
    exit;
}

require __DIR__ . '/layout/header.php';
require __DIR__ . '/layout/grid.php';
require __DIR__ . '/layout/footer.php';
