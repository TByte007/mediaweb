#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$opts = getopt('', ['db:', 'format:', 'name:', 'limit:', 'offset:', 'count', 'columns:', 'stats', 'stats-full', 'help']);
$dbFile  = MW_DB;
$format  = null;
$search  = null;
$limit   = 50;
$offset  = 0;
$columns = 'default';

if (isset($opts['help'])) {
    echo <<<USAGE
Usage: php list.php [options]

Options:
    --db=FILE       Database file (default: ./media.db)
    --format=CODEC  Filter by video format (AVC, HEVC, VP9, etc.)
    --name=PATTERN  Filter by filename (partial match, case-insensitive)
    --limit=N       Max results (default: 50)
    --offset=N      Skip N results
    --count         Only show total matching count
    --stats         Compact library stats (respects --format / --name)
    --stats-full    Verbose stats (bands, tops, oddities, …)
    --columns=C     Comma-separated columns or "all" (default: compact view)
    --help          Show this help

Examples:
    php list.php --format=HEVC
    php list.php --name="guardian" --limit=20
    php list.php --format=AVC --name="season" --columns=filename,width,height,duration_secs
    php list.php --count --format=HEVC
    php list.php --stats
    php list.php --stats-full
USAGE;
    exit(0);
}

if (!empty($opts['db']))      $dbFile  = $opts['db'];
if (!empty($opts['format']))  $format  = $opts['format'];
if (!empty($opts['name']))    $search  = $opts['name'];
if (!empty($opts['limit']))   $limit   = (int)$opts['limit'];
if (!empty($opts['offset']))  $offset  = (int)$opts['offset'];
if (!empty($opts['columns'])) $columns = $opts['columns'];

if (!file_exists($dbFile)) {
    echo "Error: database not found: $dbFile\n";
    echo "Run php scan.php first.\n";
    exit(1);
}

$db = new \SQLite3($dbFile);
$db->busyTimeout(5000);

$fmtTime = function(float|int $secs): string {
    $secs = (int)round((float)$secs);
    if ($secs < 0) $secs = 0;
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    $s = $secs % 60;
    if ($d > 0) return sprintf('%dd %dh %02dm', $d, $h, $m);
    if ($h > 0) return sprintf('%dh %02dm %02ds', $h, $m, $s);
    return sprintf('%dm %02ds', $m, $s);
};

$fmtSize = function(int $bytes): string {
    if ($bytes < 0) $bytes = 0;
    if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
    if ($bytes < 1_099_511_627_776) return round($bytes / 1_073_741_824, 2) . ' GB';
    return round($bytes / 1_099_511_627_776, 2) . ' TB';
};

$where = ['is_deleted = 0'];
$binds = [];
if ($format) { $where[] = 'video_format = ?'; $binds[] = $format; }
if ($search) { $where[] = 'filename LIKE ?'; $binds[] = "%$search%"; }
$whereClause = 'WHERE ' . implode(' AND ', $where);

$bindAll = function(\SQLite3Stmt $stmt, array $extra = []) use ($binds): void {
    $i = 1;
    foreach ($binds as $v) $stmt->bindValue($i++, $v);
    foreach ($extra as $v) $stmt->bindValue($i++, $v);
};

$statsFull = isset($opts['stats-full']);
$doStats = isset($opts['stats']) || $statsFull;

if (isset($opts['count']) || !$doStats) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM videos $whereClause");
    $bindAll($countStmt);
    $total = (int)$countStmt->execute()->fetchArray()[0];
    if (isset($opts['count'])) {
        echo "$total\n";
        exit(0);
    }
} else {
    $total = 0;
}

if ($doStats) {
    $fmtNum = static fn(int|float $n, int $d = 0): string => number_format((float)$n, $d);
    $pct = static function(int $part, int $whole): string {
        return $whole <= 0 ? '—' : sprintf('%.1f%%', 100.0 * $part / $whole);
    };
    $scalar = function(string $sql, array $extra = []) use ($db, $bindAll): mixed {
        $stmt = $db->prepare($sql);
        $bindAll($stmt, $extra);
        $row = $stmt->execute()->fetchArray(2);
        return $row === false ? null : $row[0];
    };
    $rows = function(string $sql, array $extra = []) use ($db, $bindAll): array {
        $stmt = $db->prepare($sql);
        $bindAll($stmt, $extra);
        $out = [];
        $r = $stmt->execute();
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
        return $out;
    };
    $section = static function(string $t): void { echo "\n=== $t ===\n"; };
    $line = static function(string $label, string $value): void { echo str_pad($label, 28) . $value . "\n"; };
    $bar = static function(int $n, int $max, int $width = 20): string {
        if ($max <= 0) return str_repeat('·', $width);
        $filled = min($width, max($n > 0 ? 1 : 0, (int)round($width * $n / $max)));
        return str_repeat('█', $filled) . str_repeat('·', $width - $filled);
    };
    $printBuckets = function(array $buckets, int $n, int $labelW = 18, bool $withBytes = false) use (
        $rows, $whereClause, $fmtNum, $pct, $bar, $fmtSize
    ): void {
        $items = [];
        foreach ($buckets as [$label, $cond]) {
            $row = $rows(
                "SELECT COUNT(*) AS c, COALESCE(SUM(filesize_bytes),0) AS bytes
                 FROM videos $whereClause AND ($cond)"
            )[0];
            $items[] = [$label, (int)$row['c'], (int)$row['bytes']];
        }
        $max = max(array_column($items, 1) ?: [0]);
        foreach ($items as [$label, $c, $b]) {
            $extra = $withBytes ? sprintf('  %10s', $fmtSize($b)) : '';
            echo sprintf(
                "  %-" . $labelW . "s %6s  %6s%s  %s\n",
                $label, $fmtNum($c), $pct($c, $n), $extra, $bar($c, $max)
            );
        }
    };

    $note = [];
    if ($format) $note[] = "format=$format";
    if ($search) $note[] = "name~*$search*";
    echo 'MediaWeb library stats' . ($statsFull ? ' (full)' : '')
        . ($note ? '  [' . implode(', ', $note) . ']' : '') . "\n";
    echo "Database: $dbFile\n";

    $agg = $rows("SELECT
        COUNT(*) AS n,
        COALESCE(SUM(filesize_bytes), 0) AS bytes,
        COALESCE(SUM(duration_secs), 0) AS secs,
        COALESCE(AVG(filesize_bytes), 0) AS avg_bytes,
        COALESCE(AVG(duration_secs), 0) AS avg_secs,
        COALESCE(MIN(filesize_bytes), 0) AS min_bytes,
        COALESCE(MAX(filesize_bytes), 0) AS max_bytes,
        COALESCE(MIN(NULLIF(duration_secs, 0)), 0) AS min_secs,
        COALESCE(MAX(duration_secs), 0) AS max_secs,
        COALESCE(SUM(playback_count), 0) AS plays,
        COALESCE(SUM(CASE WHEN playback_count > 0 THEN 1 ELSE 0 END), 0) AS played,
        COALESCE(SUM(needs_fix), 0) AS needs_fix,
        COALESCE(SUM(CASE WHEN series_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS in_series,
        COALESCE(SUM(CASE WHEN name IS NOT NULL AND name != '' THEN 1 ELSE 0 END), 0) AS named,
        COALESCE(SUM(CASE WHEN tmdb_id IS NOT NULL THEN 1 ELSE 0 END), 0) AS with_tmdb,
        COALESCE(SUM(CASE WHEN audio_tracks > 0 THEN 1 ELSE 0 END), 0) AS with_audio,
        COALESCE(SUM(CASE WHEN subtitle_tracks > 0 THEN 1 ELSE 0 END), 0) AS with_subs,
        COALESCE(SUM(audio_tracks), 0) AS audio_sum,
        COALESCE(SUM(subtitle_tracks), 0) AS sub_sum,
        COALESCE(AVG(NULLIF(video_bitrate, 0)), 0) AS avg_bitrate,
        COALESCE(MAX(video_bitrate), 0) AS max_bitrate,
        MIN(scanned_at) AS first_scan,
        MAX(scanned_at) AS last_scan,
        MAX(updated_at) AS last_update
        FROM videos $whereClause")[0];

    $n = (int)$agg['n'];
    $bytes = (int)$agg['bytes'];
    $secs = (int)$agg['secs'];
    $plays = (int)$agg['plays'];
    $played = (int)$agg['played'];
    $needsFix = (int)$agg['needs_fix'];
    $inSeries = (int)$agg['in_series'];
    $named = (int)$agg['named'];
    $withTmdb = (int)$agg['with_tmdb'];

    $section('Totals');
    $line('Videos', $fmtNum($n));
    $line('Soft-deleted (all)', $fmtNum((int)$db->querySingle('SELECT COUNT(*) FROM videos WHERE is_deleted = 1')));
    $line('Total size', $fmtSize($bytes));
    $line('Total duration', $fmtTime($secs));
    $line('Avg file size', $fmtSize((int)round((float)$agg['avg_bytes'])));
    $line('Avg duration', $fmtTime((float)$agg['avg_secs']));
    if ($statsFull) {
        $line('Smallest / largest', $fmtSize((int)$agg['min_bytes']) . ' / ' . $fmtSize((int)$agg['max_bytes']));
        $line('Shortest / longest', $fmtTime((float)$agg['min_secs']) . ' / ' . $fmtTime((float)$agg['max_secs']));
        $line('Distinct directories', $fmtNum((int)$scalar("SELECT COUNT(DISTINCT directory) FROM videos $whereClause")));
        $line('First / last scan', $agg['first_scan'] . ' / ' . $agg['last_scan']);
        $line('Last updated', (string)$agg['last_update']);
    }

    $section('Length (UI filters)');
    $printBuckets([
        ['Movies  (≥1h)', 'duration_secs >= 3600'],
        ['Series  (10–60m)', 'duration_secs >= 600 AND duration_secs < 3600'],
        ['Clips   (<10m)', 'duration_secs > 0 AND duration_secs < 600'],
        ['Unknown (0/null)', 'duration_secs IS NULL OR duration_secs <= 0'],
    ], $n);

    if ($statsFull) {
        $section('Duration bands');
        $printBuckets([
            ['< 1 min', 'duration_secs > 0 AND duration_secs < 60'],
            ['1–10 min', 'duration_secs >= 60 AND duration_secs < 600'],
            ['10–30 min', 'duration_secs >= 600 AND duration_secs < 1800'],
            ['30–60 min', 'duration_secs >= 1800 AND duration_secs < 3600'],
            ['1–2 h', 'duration_secs >= 3600 AND duration_secs < 7200'],
            ['2–3 h', 'duration_secs >= 7200 AND duration_secs < 10800'],
            ['≥ 3 h', 'duration_secs >= 10800'],
        ], $n, 10);

        $section('File size bands');
        $printBuckets([
            ['< 100 MB', 'filesize_bytes < 104857600'],
            ['100–500 MB', 'filesize_bytes >= 104857600 AND filesize_bytes < 524288000'],
            ['500 MB–1 GB', 'filesize_bytes >= 524288000 AND filesize_bytes < 1073741824'],
            ['1–2 GB', 'filesize_bytes >= 1073741824 AND filesize_bytes < 2147483648'],
            ['2–5 GB', 'filesize_bytes >= 2147483648 AND filesize_bytes < 5368709120'],
            ['5–10 GB', 'filesize_bytes >= 5368709120 AND filesize_bytes < 10737418240'],
            ['≥ 10 GB', 'filesize_bytes >= 10737418240'],
        ], $n, 12, true);
    }

    $section('Resolution');
    $printBuckets([
        ['4K / UHD  (≥2160)', 'height >= 2160'],
        ['1440p', 'height >= 1440 AND height < 2160'],
        ['1080p', 'height >= 1080 AND height < 1440'],
        ['720p', 'height >= 720 AND height < 1080'],
        ['SD         (<720)', 'height > 0 AND height < 720'],
        ['Unknown', 'height IS NULL OR height <= 0'],
    ], $n);
    if ($statsFull) {
        $topRes = $rows("SELECT width || 'x' || height AS res, COUNT(*) AS c
            FROM videos $whereClause AND width > 0 AND height > 0
            GROUP BY width, height ORDER BY c DESC LIMIT 12");
        if ($topRes) {
            echo "  Top resolutions:\n";
            $rmax = (int)$topRes[0]['c'];
            foreach ($topRes as $row) {
                $c = (int)$row['c'];
                echo sprintf("    %-12s %6s  %6s  %s\n", $row['res'], $fmtNum($c), $pct($c, $n), $bar($c, $rmax, 16));
            }
        }
    }

    $section('Codec / format');
    $fmts = $rows("SELECT COALESCE(NULLIF(video_format,''), '(none)') AS k, COUNT(*) AS c,
        COALESCE(SUM(filesize_bytes),0) AS bytes, COALESCE(SUM(duration_secs),0) AS secs
        FROM videos $whereClause GROUP BY k ORDER BY c DESC");
    $fmax = $fmts ? (int)$fmts[0]['c'] : 0;
    foreach ($fmts as $row) {
        $c = (int)$row['c'];
        echo sprintf("  %-18s %6s  %6s  %10s  %10s  %s\n",
            $row['k'], $fmtNum($c), $pct($c, $n), $fmtSize((int)$row['bytes']), $fmtTime((int)$row['secs']), $bar($c, $fmax, 16));
    }

    if ($statsFull) {
        echo "  Top codecs:\n";
        foreach ($rows("SELECT COALESCE(NULLIF(video_codec,''), '(none)') AS k, COUNT(*) AS c
            FROM videos $whereClause GROUP BY k ORDER BY c DESC LIMIT 15") as $row) {
            echo sprintf("    %-28s %6s  %6s\n", $row['k'], $fmtNum((int)$row['c']), $pct((int)$row['c'], $n));
        }

        $section('Container (extension)');
        $extMap = [];
        $stmt = $db->prepare("SELECT filename, filesize_bytes FROM videos $whereClause");
        $bindAll($stmt);
        $extRes = $stmt->execute();
        while ($er = $extRes->fetchArray(2)) {
            $fn = (string)$er[0];
            $dot = strrpos($fn, '.');
            $ext = ($dot === false || $dot === strlen($fn) - 1) ? '(none)' : strtolower(substr($fn, $dot + 1));
            if (!isset($extMap[$ext])) $extMap[$ext] = [0, 0];
            $extMap[$ext][0]++;
            $extMap[$ext][1] += (int)$er[1];
        }
        uasort($extMap, static fn($a, $b) => $b[0] <=> $a[0]);
        $emax = $extMap ? max(array_column($extMap, 0)) : 0;
        $i = 0;
        foreach ($extMap as $ext => [$c, $b]) {
            if ($i++ >= 20) break;
            echo sprintf("  .%-10s %6s  %6s  %10s  %s\n",
                $ext, $fmtNum($c), $pct($c, $n), $fmtSize($b), $bar($c, $emax, 16));
        }
    }

    $section('Series');
    $seriesTotal = (int)$db->querySingle('SELECT COUNT(*) FROM series');
    $seriesTmdb = (int)$db->querySingle('SELECT COUNT(*) FROM series WHERE tmdb_id IS NOT NULL');
    $seriesTouched = (int)$scalar("SELECT COUNT(DISTINCT series_id) FROM videos $whereClause AND series_id IS NOT NULL");
    $seasonRows = (int)$scalar(
        "SELECT COUNT(*) FROM (
            SELECT series_id, season FROM videos $whereClause
            AND series_id IS NOT NULL AND season IS NOT NULL
            GROUP BY series_id, season
        )"
    );
    $epTagged = (int)$scalar(
        "SELECT COUNT(*) FROM videos $whereClause AND series_id IS NOT NULL AND season IS NOT NULL AND episode IS NOT NULL"
    );
    $line('Series rows', $fmtNum($seriesTotal) . " (with TMDB: {$fmtNum($seriesTmdb)})");
    $line('Series with videos', $fmtNum($seriesTouched));
    $line('Season groups', $fmtNum($seasonRows));
    $line('Videos in a series', $fmtNum($inSeries) . '  ' . $pct($inSeries, $n));
    $line('Standalone videos', $fmtNum($n - $inSeries) . '  ' . $pct($n - $inSeries, $n));
    $line('SxxExx tagged', $fmtNum($epTagged));

    if ($statsFull) {
        $line('With episode_title', $fmtNum((int)$scalar(
            "SELECT COUNT(*) FROM videos $whereClause AND episode_title IS NOT NULL AND episode_title != ''"
        )));
        $topShows = $rows(
            "SELECT s.title AS title, COUNT(*) AS c, COUNT(DISTINCT v.season) AS seasons,
                COALESCE(SUM(v.duration_secs),0) AS secs, COALESCE(SUM(v.filesize_bytes),0) AS bytes
             FROM videos v JOIN series s ON s.id = v.series_id
             $whereClause AND v.series_id IS NOT NULL
             GROUP BY s.id ORDER BY c DESC LIMIT 15"
        );
        if ($topShows) {
            echo "  Largest shows:\n";
            foreach ($topShows as $row) {
                echo sprintf("    %-40s %4s eps  %2s seasons  %10s  %10s\n",
                    mb_strimwidth((string)$row['title'], 0, 40, '…'),
                    $fmtNum((int)$row['c']), $fmtNum((int)$row['seasons']),
                    $fmtTime((int)$row['secs']), $fmtSize((int)$row['bytes']));
            }
        }
    }

    $section('Titles / TMDB');
    $line('With display name', $fmtNum($named) . '  ' . $pct($named, $n));
    $line('Missing name', $fmtNum($n - $named) . '  ' . $pct($n - $named, $n));
    $line('Video tmdb_id set', $fmtNum($withTmdb) . '  ' . $pct($withTmdb, $n));
    $line('Series tmdb_id set', $fmtNum($seriesTmdb) . ' / ' . $fmtNum($seriesTotal));

    $section('Playback');
    $line('Total plays', $fmtNum($plays));
    $line('Played (≥1)', $fmtNum($played) . '  ' . $pct($played, $n));
    $line('Never played', $fmtNum($n - $played) . '  ' . $pct($n - $played, $n));

    if ($statsFull) {
        if ($played > 0) $line('Avg plays (played)', $fmtNum($plays / $played, 2));
        $printBuckets([
            ['plays 0', 'playback_count = 0'],
            ['plays 1', 'playback_count = 1'],
            ['plays 2–4', 'playback_count BETWEEN 2 AND 4'],
            ['plays 5–9', 'playback_count BETWEEN 5 AND 9'],
            ['plays ≥10', 'playback_count >= 10'],
        ], $n, 10);
        $topPlayed = $rows(
            "SELECT COALESCE(NULLIF(name,''), filename) AS title, playback_count AS c
             FROM videos $whereClause AND playback_count > 0
             ORDER BY playback_count DESC, id DESC LIMIT 10"
        );
        if ($topPlayed) {
            echo "  Most played:\n";
            foreach ($topPlayed as $row) {
                echo sprintf("    %4sx  %s\n", $fmtNum((int)$row['c']), mb_strimwidth((string)$row['title'], 0, 70, '…'));
            }
        }

        $section('Audio / subs / bitrate');
        $line('With audio track(s)', $fmtNum((int)$agg['with_audio']) . '  ' . $pct((int)$agg['with_audio'], $n));
        $line('With subtitles', $fmtNum((int)$agg['with_subs']) . '  ' . $pct((int)$agg['with_subs'], $n));
        $line('Total audio tracks', $fmtNum((int)$agg['audio_sum']));
        $line('Total subtitle tracks', $fmtNum((int)$agg['sub_sum']));
        $line('Multi-audio (≥2)', $fmtNum((int)$scalar("SELECT COUNT(*) FROM videos $whereClause AND audio_tracks >= 2")));
        $line('Multi-sub (≥2)', $fmtNum((int)$scalar("SELECT COUNT(*) FROM videos $whereClause AND subtitle_tracks >= 2")));
        $avgBr = (float)$agg['avg_bitrate'];
        $maxBr = (int)$agg['max_bitrate'];
        $line('Avg video bitrate', $avgBr > 0 ? $fmtNum($avgBr / 1000, 0) . ' kbps' : '—');
        $line('Max video bitrate', $maxBr > 0 ? $fmtNum($maxBr / 1000, 0) . ' kbps' : '—');
    }

    $section('Browser playback');
    $line('needs_fix [=avbridge]', $fmtNum($needsFix) . '  ' . $pct($needsFix, $n));
    $line('native player OK', $fmtNum($n - $needsFix) . '  ' . $pct($n - $needsFix, $n));

    if ($statsFull) {
        $fixByFmt = $rows(
            "SELECT COALESCE(NULLIF(video_format,''), '(none)') AS k, COUNT(*) AS c
             FROM videos $whereClause AND needs_fix = 1 GROUP BY k ORDER BY c DESC"
        );
        if ($fixByFmt) {
            echo "  needs_fix by format:\n";
            foreach ($fixByFmt as $row) {
                echo sprintf("    %-18s %6s\n", $row['k'], $fmtNum((int)$row['c']));
            }
        }

        $section('Aspect ratio / frame rate');
        foreach ($rows(
            "SELECT COALESCE(NULLIF(aspect_ratio,''), '(none)') AS k, COUNT(*) AS c
             FROM videos $whereClause GROUP BY k ORDER BY c DESC LIMIT 12"
        ) as $row) {
            echo sprintf("  %-12s %6s  %6s\n", $row['k'], $fmtNum((int)$row['c']), $pct((int)$row['c'], $n));
        }
        echo "  Frame rates:\n";
        foreach ($rows(
            "SELECT COALESCE(NULLIF(frame_rate,''), '(none)') AS k, COUNT(*) AS c
             FROM videos $whereClause GROUP BY k ORDER BY c DESC LIMIT 12"
        ) as $row) {
            echo sprintf("    %-12s %6s  %6s\n", $row['k'], $fmtNum((int)$row['c']), $pct((int)$row['c'], $n));
        }
    }

    $section('Media roots');
    foreach (MW_MEDIA_DIRS as $dir) {
        $fs = rtrim(str_replace('\\', '/', (string)$dir['fs']), '/');
        $like = $fs . '/%';
        $root = "(replace(filepath, char(92), '/') LIKE ? OR replace(filepath, char(92), '/') = ?)";
        $row = $rows(
            "SELECT COUNT(*) AS c, COALESCE(SUM(filesize_bytes),0) AS bytes, COALESCE(SUM(duration_secs),0) AS secs
             FROM videos $whereClause AND $root",
            [$like, $fs]
        )[0];
        echo sprintf("  %-36s %6s  %10s  %10s  %s\n",
            $fs, $fmtNum((int)$row['c']), $fmtSize((int)$row['bytes']), $fmtTime((int)$row['secs']), (string)$dir['url']);
    }

    if ($statsFull) {
        $section('Oddities');
        $odd = $rows("SELECT
            COALESCE(SUM(CASE WHEN filesize_bytes <= 0 THEN 1 ELSE 0 END),0) AS zero_size,
            COALESCE(SUM(CASE WHEN duration_secs IS NULL OR duration_secs <= 0 THEN 1 ELSE 0 END),0) AS no_dur,
            COALESCE(SUM(CASE WHEN width IS NULL OR width <= 0 OR height IS NULL OR height <= 0 THEN 1 ELSE 0 END),0) AS no_wh,
            COALESCE(SUM(CASE WHEN video_format IS NULL OR video_format = '' THEN 1 ELSE 0 END),0) AS no_fmt
            FROM videos $whereClause")[0];
        $line('Zero filesize', $fmtNum((int)$odd['zero_size']));
        $line('Missing duration', $fmtNum((int)$odd['no_dur']));
        $line('Missing WxH', $fmtNum((int)$odd['no_wh']));
        $line('Missing format', $fmtNum((int)$odd['no_fmt']));
        $line('Duplicate filenames', $fmtNum((int)$scalar(
            "SELECT COUNT(*) FROM (
                SELECT filename FROM videos $whereClause GROUP BY filename HAVING COUNT(*) > 1
             )"
        )) . ' names');

        $section('Density');
        if ($bytes > 0 && $secs > 0) {
            $line('Hours / GB', $fmtNum(($secs / 3600.0) / max($bytes / 1_073_741_824, 0.001), 3));
            $line('MB / minute', $fmtNum(($bytes / 1_048_576) / max($secs / 60.0, 0.001), 2));
            $line('Avg Mbps (size/dur)', $fmtNum(($bytes * 8 / 1_000_000) / $secs, 2));
        } else {
            echo "  (insufficient size/duration data)\n";
        }
    }

    echo "\n";
    $db->close();
    exit(0);
}

if ($total === 0) {
    echo "No videos found.\n";
    exit(0);
}

if ($limit <= 0) $limit = PHP_INT_MAX;

$columnsList = 'all';
if ($columns === 'default' || $columns === 'compact') {
    $columnsList = 'id, filename, directory, video_format, width, height, duration_secs, filesize_bytes, audio_tracks, needs_fix';
} elseif ($columns !== 'all') {
    $cols = array_map('trim', explode(',', $columns));
    $allowed = [
        'id', 'filepath', 'filename', 'directory', 'filesize_bytes',
        'duration_secs', 'video_format', 'video_codec', 'width', 'height',
        'aspect_ratio', 'frame_rate', 'video_bitrate', 'audio_tracks',
        'subtitle_tracks', 'title', 'scanned_at', 'updated_at', 'needs_fix'
    ];
    foreach ($cols as $c) {
        if (!in_array($c, $allowed, true)) {
            echo "Error: unknown column: $c\n";
            exit(1);
        }
    }
    $columnsList = implode(', ', $cols);
}

$sql = "SELECT $columnsList FROM videos $whereClause ORDER BY filename LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$bindAll($stmt, [$limit, $offset]);
$result = $stmt->execute();

echo "$total videos found (showing up to $limit from offset $offset)\n";

while ($row = $result->fetchArray(2)) {
    if ($columns === 'default' || $columns === 'compact') {
        [, $filename, , $videoFormat, $width, $height, $duration, $size, $audioTracks, $needsFix] = $row;
        $audioTag = $audioTracks ? " audio:$audioTracks" : '';
        $fixTag = (int)$needsFix ? '[!]' : '';
        echo sprintf(
            "%s [$videoFormat] %s  %-9s  %s  %3s%s\n",
            $fixTag, $filename, $fmtSize((int)$size), "$width x $height", $fmtTime((float)$duration), $audioTag
        );
    } else {
        $vals = [];
        foreach ($row as $v) $vals[] = $v === null ? '' : (string)$v;
        echo implode("\t", $vals) . "\n";
    }
}

$db->close();
