#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$opts = getopt('', ['db:', 'format:', 'name:', 'limit:', 'offset:', 'count', 'columns:', 'help']);
$dbFile  = MW_DB;
$format  = null;
$search  = null;
$limit   = 50;
$offset  = 0;
$columns = 'default';

if (!empty($opts['help'])) {
    echo <<<USAGE
Usage: php list.php [options]

Options:
    --db=FILE       Database file (default: ./media.db)
    --format=CODEC  Filter by video format (AVC, HEVC, VP9, etc.)
    --name=PATTERN  Filter by filename (partial match, case-insensitive)
    --limit=N       Max results (default: 50)
    --offset=N      Skip N results
    --count         Only show total matching count
    --columns=C     Comma-separated columns or "all" (default: compact view)
    --help          Show this help

Examples:
    php list.php --format=HEVC
    php list.php --name="guardian" --limit=20
    php list.php --format=AVC --name="season" --columns=filename,width,height,duration_secs
    php list.php --count --format=HEVC
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

// Count total matching (excluding soft-deleted)
$where = ["is_deleted = 0"];
$binds = [];
if ($format) { $where[] = "video_format = ?"; $binds[] = $format; }
if ($search) { $where[] = "filename LIKE ?"; $binds[] = "%$search%"; }

$whereClause = "WHERE " . implode(" AND ", $where);

$countStmt = $db->prepare("SELECT COUNT(*) FROM videos $whereClause");
foreach ($binds as $i => $v) { $countStmt->bindValue($i + 1, $v); }
$countResult = $countStmt->execute();
$total = (int)$countResult->fetchArray()[0];

$countOnly = !empty($opts['count']);
if ($countOnly) {
    echo "$total\n";
    exit(0);
}

if ($total === 0) {
    echo "No videos found.\n";
    exit(0);
}

if ($limit <= 0) $limit = PHP_INT_MAX;

// Build query
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
$bindIdx = 1;
foreach ($binds as $v) { $stmt->bindValue($bindIdx++, $v); }
$stmt->bindValue($bindIdx++, $limit);
$stmt->bindValue($bindIdx++, $offset);

$result = $stmt->execute();

$fmtTime = function(float|int $secs): string {
    $h = intdiv((int)$secs, 3600);
    $m = intdiv((int)$secs % 3600, 60);
    $s = (int)$secs % 60;
    if ($h > 0) return sprintf('%dh %02dm %02ds', $h, $m, $s);
    return sprintf('%dm %02ds', $m, $s);
};

$fmtSize = function(int $bytes): string {
    if ($bytes < 1_048_576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
    return round($bytes / 1_073_741_824, 1) . ' GB';
};

echo "$total videos found (showing up to $limit from offset $offset)\n";

// NUM mode = 2 (numeric keys only)
while ($row = $result->fetchArray(2)) {
    // Map columns by index when using default list
    if ($columns === 'default' || $columns === 'compact') {
        [, $filename, $dir, $videoFormat, $width, $height, $duration, $size, $audioTracks, $needsFix] = $row;
        $time = $fmtTime((float)$duration);
        $sz = $fmtSize((int)$size);
        $res = "$width x $height";
        $audioTag = $audioTracks ? " audio:$audioTracks" : '';
        $fixTag = (int)$needsFix ? '[!]' : '';
        echo sprintf("%s [$videoFormat] %s  %-9s  %s  %3s%s%s\n", $fixTag, $filename, $sz, $res, $time, $audioTag, '');
    } else {
        // Generic tabular output
        $vals = [];
        foreach ($row as $v) {
            if ($v === null) $vals[] = '';
            else $vals[] = (string)$v;
        }
        echo implode("\t", $vals) . "\n";
    }
}

$db->close();
