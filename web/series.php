<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/layout/helpers.php';
require_once __DIR__ . '/../series.php';

$basePath = MW_BASE_URL;
$search = trim($_GET['q'] ?? '');
$mode = 'series';
$sid = isset($_GET['sid']) && ctype_digit((string)$_GET['sid']) ? (int)$_GET['sid'] : 0;
$season = isset($_GET['season']) && ctype_digit((string)$_GET['season']) ? (int)$_GET['season'] : null;

if (!file_exists(MW_DB)) {
    http_response_code(500);
    require __DIR__ . '/layout/header.php';
    echo "<h1>Database not found</h1><p>Run <code>php scan.php</code> first.</p>";
    require __DIR__ . '/layout/footer.php';
    exit;
}

$db = new SQLite3(MW_DB);
$db->busyTimeout(5000);

$seriesRow = null;
$seasons = [];
$shows = [];
$videos = [];
$total = 0;
$currentPage = 1;
$pages = 1;
$seriesLevel = 'shows';

if ($sid > 0) {
    $seriesRow = $db->querySingle('SELECT id, title FROM series WHERE id = ' . $sid, true);
    if (!$seriesRow) {
        $db->close();
        http_response_code(404);
        die('<h1>Series not found</h1>');
    }
    if ($season !== null) {
        $seriesLevel = 'episodes';
        $limit = 40;
        $where = 'is_deleted = 0 AND series_id = :sid AND season = :season';
        $params = ['sid' => $sid, 'season' => $season];
        if ($search !== '') {
            $where .= ' AND (filename LIKE :q OR episode_title LIKE :q OR title LIKE :q)';
            $params['q'] = "%$search%";
        }
        $countStmt = $db->prepare("SELECT COUNT(*) FROM videos WHERE $where");
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
        $total = (int)$countStmt->execute()->fetchArray(2)[0];
        $pages = max(1, (int)ceil($total / $limit));
        $currentPage = max(1, min((int)($_GET['page'] ?? 1), $pages));

        $stmt = $db->prepare(
            "SELECT id, filename, filepath, video_format, width, height, duration_secs,
                    filesize_bytes, audio_tracks, subtitle_tracks, title, playback_count, needs_fix,
                    season, episode, episode_title
             FROM videos WHERE $where
             ORDER BY episode ASC, filename ASC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', ($currentPage - 1) * $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $videos[] = [
                'id' => (int)$row['id'],
                'filename' => trim((string)$row['filename']),
                'filepath' => (string)$row['filepath'],
                'video_format' => (string)$row['video_format'],
                'width' => (int)$row['width'],
                'height' => (int)$row['height'],
                'duration_secs' => (float)$row['duration_secs'],
                'filesize_bytes' => (int)$row['filesize_bytes'],
                'audio_tracks' => (int)$row['audio_tracks'],
                'subtitle_tracks' => (int)$row['subtitle_tracks'],
                'title' => episodePrettyTitle(
                    (string)$row['filename'], $row['title'], (string)$row['filepath'],
                    $row['season'] !== null ? (int)$row['season'] : null,
                    $row['episode'] !== null ? (int)$row['episode'] : null,
                    $row['episode_title']
                ),
                'playback_count' => (int)$row['playback_count'],
                'needs_fix' => (int)$row['needs_fix'],
            ];
        }
        $result->finalize();
    } else {
        $seriesLevel = 'seasons';
        $stmt = $db->prepare(
            'SELECT season, COUNT(*) AS eps, MIN(id) AS cover_id, SUM(playback_count) AS plays
             FROM videos
             WHERE is_deleted = 0 AND series_id = :sid AND season IS NOT NULL
             GROUP BY season ORDER BY season ASC'
        );
        $stmt->bindValue(':sid', $sid, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $seasons[] = [
                'season' => (int)$row['season'],
                'eps' => (int)$row['eps'],
                'cover_id' => (int)$row['cover_id'],
                'plays' => (int)$row['plays'],
            ];
        }
        $result->finalize();
        $total = count($seasons);
    }
} else {
    $where = '1=1';
    $params = [];
    if ($search !== '') {
        $where = 's.title LIKE :q';
        $params['q'] = "%$search%";
    }
    $stmt = $db->prepare(
        "SELECT s.id, s.title, s.cover_video_id,
                COUNT(v.id) AS eps, COUNT(DISTINCT v.season) AS seasons
         FROM series s
         JOIN videos v ON v.series_id = s.id AND v.is_deleted = 0
         WHERE $where
         GROUP BY s.id
         ORDER BY s.title ASC"
    );
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $shows[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'cover_video_id' => $row['cover_video_id'] !== null ? (int)$row['cover_video_id'] : null,
            'eps' => (int)$row['eps'],
            'seasons' => (int)$row['seasons'],
        ];
    }
    $result->finalize();
    $total = count($shows);
}

$db->close();

if (isset($_GET['partial']) && $seriesLevel === 'episodes') {
    require __DIR__ . '/layout/cards.php';
    exit;
}

require __DIR__ . '/layout/header.php';
?>
<main class="wrap">
<?php if ($seriesLevel === 'shows'): ?>
    <?php if ($search !== ''): ?>
    <div class="info">Found <strong><?= number_format($total) ?></strong> series for "<strong><?= htmlspecialchars($search) ?></strong>"</div>
    <?php endif; ?>
    <?php if (!$shows): ?>
    <div class="empty">No series found. Run <code>php scan.php</code> to detect shows.</div>
    <?php else: ?>
    <div class="grid">
    <?php foreach ($shows as $s): ?>
        <div class="card" data-href="<?= htmlspecialchars($basePath . '?mode=series&sid=' . $s['id']) ?>">
            <div class="thumb">
                <?php if ($s['cover_video_id']): ?>
                <img loading="lazy" src="<?= $basePath ?>getcover.php?id=<?= $s['cover_video_id'] ?>" alt="">
                <?php endif; ?>
                <div class="tags">
                    <span class="tag time"><?= $s['seasons'] ?> season<?= $s['seasons'] != 1 ? 's' : '' ?></span>
                </div>
            </div>
            <div class="card-body">
                <div class="card-title"><?= htmlspecialchars($s['title']) ?></div>
                <div class="card-meta"><?= number_format($s['eps']) ?> episode<?= $s['eps'] != 1 ? 's' : '' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($seriesLevel === 'seasons'): ?>
    <div class="info" style="margin-bottom:12px">
        <a class="back-link" href="<?= htmlspecialchars($basePath . '?mode=series') ?>">&#8592; Series</a>
        <strong style="margin-left:12px"><?= htmlspecialchars((string)$seriesRow['title']) ?></strong>
    </div>
    <?php if (!$seasons): ?>
    <div class="empty">No seasons.</div>
    <?php else: ?>
    <div class="grid">
    <?php foreach ($seasons as $sz): ?>
        <div class="card" data-href="<?= htmlspecialchars($basePath . '?mode=series&sid=' . $sid . '&season=' . $sz['season']) ?>">
            <div class="thumb">
                <img loading="lazy" src="<?= $basePath ?>getcover.php?id=<?= $sz['cover_id'] ?>" alt="">
                <div class="tags">
                    <span class="tag time"><?= $sz['eps'] ?> ep<?= $sz['eps'] != 1 ? 's' : '' ?></span>
                </div>
                <?= $sz['plays'] > 0
                    ? '<div class="play-badge">&#9654; '.number_format($sz['plays']).'</div>'
                    : '' ?>
            </div>
            <div class="card-body">
                <div class="card-title">Season <?= $sz['season'] ?></div>
                <div class="card-meta"><?= $sz['eps'] ?> episode<?= $sz['eps'] != 1 ? 's' : '' ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php else: ?>
    <div class="info" style="margin-bottom:12px">
        <a class="back-link" href="<?= htmlspecialchars($basePath . '?mode=series') ?>">Series</a>
        <span style="color:var(--muted)"> / </span>
        <a class="back-link" href="<?= htmlspecialchars($basePath . '?mode=series&sid=' . $sid) ?>"><?= htmlspecialchars((string)$seriesRow['title']) ?></a>
        <span style="color:var(--muted)"> / </span>
        <strong>Season <?= (int)$season ?></strong>
        <?php if ($search !== ''): ?>
        — <?= number_format($total) ?> match<?= $total != 1 ? 'es' : '' ?>
        <?php endif; ?>
    </div>
    <?php if (!$videos): ?>
    <div class="empty">No episodes.</div>
    <?php else: ?>
    <div class="grid-spacer-top" style="height:0"></div>
    <div class="grid" data-page="<?= (int)$currentPage ?>" data-pages="<?= (int)$pages ?>" data-window="<?= (int)MW_GRID_WINDOW_PAGES ?>">
    <?php require __DIR__ . '/layout/cards.php'; ?>
    </div>
    <div class="grid-more" hidden></div>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php require __DIR__ . '/layout/footer.php'; ?>
