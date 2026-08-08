#!/usr/bin/env php
<?php

/**
 * MediaWeb Video Scanner
 *
 * Scans video directories, stores MediaInfo in SQLite.
 *
 * needs_fix (browser warning, after PTS tests — not codec-only):
 *   Candidates: .avi, or MPEG-4 Part 2 / Xvid / DivX (not H.264 / V_MPEG4/ISO/AVC).
 *   Broken if short -c copy probe fails OR ≥10% sampled frames have pts N/A
 *   OR ≥10% packet DTS regressions.
 *   Desktop players (VLC/WMP) invent PTS and play fine; browsers use avbridge.
 */

declare(strict_types=1);

require __DIR__ . '/config.php';

$scanDirs = array_map(fn($d) => rtrim($d['fs'], '/'), MW_MEDIA_DIRS);
$dbFile      = MW_DB;
$verbose     = false;
$scanOnly    = false;
$forceRescan = false;
$dirOverride = false;

$opts = getopt('', ['dir:', 'db:', 'verbose', 'scan-only', 'force-rescan', 'help']);

if (isset($opts['help'])) {
    $dirList = implode("\n", array_map(fn($d) => "    - {$d['fs']}  ({$d['url']})", MW_MEDIA_DIRS));
    echo <<<USAGE
Usage: php scan.php [options]

Options:
    --dir=DIR            Comma-separated directories to scan (default: MW_MEDIA_DIRS)
    --db=FILE            SQLite database file (default: MW_DB)
    --verbose            Show progress as files are processed
    --scan-only          Refresh needs_fix (PTS probe + MPEG-4/Xvid in any container)
    --force-rescan       Re-run metadata extract on files already in the database
    --help               Show this help

Scans for: mkv, mp4, avi, mov, webm, wmv, flv, m4v

Configured directories (MW_MEDIA_DIRS):
$dirList

Behavior:
    Default:
        Incremental scan — new files get MediaInfo + needs_fix detect; known files skipped.
        Missing files marked is_deleted=1.

    needs_fix (PTS / browser warning):
        Candidates: .avi, or MPEG-4 Part 2 / Xvid / DivX in any container
        (not H.264 — ignore V_MPEG4/ISO/AVC).
        Test: short -c copy probe fails, OR ≥10% frames pts N/A, OR ≥10% packet DTS
        go backwards (non-monotonic DTS; probe remux may still exit 0) → needs_fix=1.
        Codec alone does not set the flag.

    --scan-only:
        Re-check candidates above; UPDATE needs_fix from the PTS tests.

USAGE;
    echo str_replace('MW_DB', MW_DB, "Default DB: MW_DB\n");
    exit(0);
}

if (!empty($opts['db']))              $dbFile      = $opts['db'];
if (isset($opts['verbose']))          $verbose     = true;
if (isset($opts['scan-only']))        $scanOnly    = true;
if (isset($opts['force-rescan']))     $forceRescan = true;

if (!file_exists(MW_FFMPEG)) {
    echo "Error: ffmpeg not found at " . MW_FFMPEG . "\n";
    exit(1);
}

if (!empty($opts['dir'])) {
    $scanDirs = array_map(fn($d) => rtrim(trim($d), '/'), explode(',', $opts['dir']));
    $dirOverride = true;
}

$scanDirs = array_values(array_filter($scanDirs, fn($d) => is_dir($d)));
if (empty($scanDirs)) {
    echo "Error: no valid scan directories configured/found.\n";
    exit(1);
}

exec('which mediainfo', $_, $code);
if ($code !== 0) {
    echo "Error: mediainfo is not installed or not in PATH\n";
    exit(1);
}

try {
    $db = new \SQLite3($dbFile);
} catch (\Exception $e) {
    echo "Error opening database `$dbFile`: {$e->getMessage()}\n";
    exit(1);
}

$db->exec('PRAGMA journal_mode = WAL;');
$db->exec('PRAGMA foreign_keys = ON;');
$db->busyTimeout(10000);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS videos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    filepath        TEXT NOT NULL UNIQUE,
    filename        TEXT NOT NULL,
    directory       TEXT NOT NULL,
    filesize_bytes  INTEGER NOT NULL,
    duration_secs   INTEGER,
    video_format    TEXT,
    video_codec     TEXT,
    width           INTEGER,
    height          INTEGER,
    aspect_ratio    TEXT,
    frame_rate      TEXT,
    video_bitrate   INTEGER,
    audio_tracks    INTEGER DEFAULT 0,
    subtitle_tracks INTEGER DEFAULT 0,
    title           TEXT,
    full_info       TEXT,
    playback_count  INTEGER DEFAULT 0,
    needs_fix       INTEGER DEFAULT 0,
    is_deleted      INTEGER DEFAULT 0,
    scanned_at      DATETIME NOT NULL DEFAULT (datetime('now')),
    updated_at      DATETIME NOT NULL DEFAULT (datetime('now'))
);
SQL
);

$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_directory ON videos(directory);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_width   ON videos(width);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_height  ON videos(height);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_playback_count ON videos(playback_count DESC);');

$result = $db->query('PRAGMA table_info(videos);');
$hasNeedsFix = false;
$hasIsDeleted = false;
while ($col = $result->fetchArray(2)) {
    if (!empty($col) && isset($col[1])) {
        if ($col[1] === 'needs_fix') $hasNeedsFix = true;
        if ($col[1] === 'is_deleted') $hasIsDeleted = true;
    }
}
if (!$hasNeedsFix) $db->exec('ALTER TABLE videos ADD COLUMN needs_fix INTEGER DEFAULT 0;');
if (!$hasIsDeleted) $db->exec('ALTER TABLE videos ADD COLUMN is_deleted INTEGER DEFAULT 0;');

$stmtUpsert = $db->prepare(<<<SQL
INSERT INTO videos
  (filepath, filename, directory, filesize_bytes, duration_secs,
   video_format, video_codec, width, height, aspect_ratio, frame_rate,
   video_bitrate, audio_tracks, subtitle_tracks, title, full_info, needs_fix, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
ON CONFLICT(filepath) DO UPDATE SET
  filename        = excluded.filename,
  directory       = excluded.directory,
  filesize_bytes  = excluded.filesize_bytes,
  duration_secs   = excluded.duration_secs,
  video_format    = excluded.video_format,
  video_codec     = excluded.video_codec,
  width           = excluded.width,
  height          = excluded.height,
  aspect_ratio    = excluded.aspect_ratio,
  frame_rate      = excluded.frame_rate,
  video_bitrate   = excluded.video_bitrate,
  audio_tracks    = excluded.audio_tracks,
  subtitle_tracks = excluded.subtitle_tracks,
  title           = excluded.title,
  full_info       = excluded.full_info,
  needs_fix       = excluded.needs_fix,
  is_deleted      = 0,
  updated_at      = datetime('now')
SQL
);

$stmtFlag = $db->prepare('UPDATE videos SET needs_fix = ?, is_deleted = 0, updated_at = datetime(\'now\') WHERE filepath = ?');
$stmtMeta = $db->prepare('SELECT video_format, video_codec, needs_fix FROM videos WHERE filepath = ?');

$knownFiles = [];
if (!$forceRescan) {
    $rs = $db->query('SELECT filepath FROM videos WHERE is_deleted = 0');
    while ($row = $rs->fetchArray(2)) {
        $knownFiles[$row[0]] = true;
    }
}

$scannedPaths = [];
$extensions = ['mkv', 'mp4', 'avi', 'mov', 'webm', 'wmv', 'flv', 'm4v'];

function safeWalk(string $dir, array &$files, array $extensions, bool $verbose): void
{
    $handle = @opendir($dir);
    if (!$handle) {
        if ($verbose) echo "  [SKIP] Can't read dir: $dir\n";
        return;
    }
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $dir . '/' . $entry;
        if (is_dir($fullPath)) {
            safeWalk($fullPath, $files, $extensions, $verbose);
        } elseif (is_file($fullPath) && is_readable($fullPath)) {
            $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, $extensions, true)) {
                $files[] = $fullPath;
            }
        }
    }
    closedir($handle);
}

function mediainfoJson(string $filepath): ?array
{
    $cmd = 'mediainfo --Output=JSON ' . escapeshellarg($filepath) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    if ($output === null || $output === '') return null;
    $json = json_decode($output, true);
    if (!$json || !isset($json['media']['track'])) return null;
    $json['_raw'] = $output;
    return $json;
}

/** True if short -c copy probe fails — usually missing/bad PTS. */
function remuxProbeFails(string $filepath): bool
{
    $testFile = sys_get_temp_dir() . '/mediaweb_probe_' . getmypid() . '.mkv';
    $cmd = sprintf(
        '%s -nostdin -y -t 5 -i %s -c copy %s 2>&1',
        escapeshellarg(MW_FFMPEG),
        escapeshellarg($filepath),
        escapeshellarg($testFile)
    );
    exec($cmd, $_, $code);
    @unlink($testFile);
    return $code !== 0;
}

/**
 * MPEG-4 Part 2 / Xvid / DivX (not H.264).
 * Note: Matroska H.264 is often CodecID V_MPEG4/ISO/AVC — that is AVC, not Part 2.
 */
function isLegacyMpeg4Codec(?string $format, ?string $codec): bool
{
    $format = strtoupper((string)$format);
    $codec  = strtoupper((string)$codec);
    if ($format === 'AVC' || str_contains($codec, '/AVC') || $codec === 'AVC') return false;
    if ($format !== '' && str_contains($format, 'MPEG-4')) return true; // MPEG-4 Visual
    if ($codec === '') return false;
    if (preg_match('/^(XVID|DIVX|DX50|FMP4|MP4V)/', $codec)) return true;
    if (str_contains($codec, 'MPEG4') && str_contains($codec, 'ASP')) return true;
    return false;
}

/** Fraction of sampled video frames with pts_time N/A (missing timestamps). */
function ptsMissingRatio(string $filepath, int $sampleFrames = 50): float
{
    if (!file_exists(MW_FFPROBE)) return 0.0;
    $cmd = sprintf(
        '%s -v quiet -show_entries frame=pts_time -of csv=p=0 -select_streams v %s 2>&1',
        escapeshellarg(MW_FFPROBE),
        escapeshellarg($filepath)
    );
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!$proc) return 0.0;

    $na = 0;
    $n = 0;
    while (!feof($pipes[1]) && $n < $sampleFrames) {
        $line = trim(fgets($pipes[1]));
        if ($line === '') continue;
        $n++;
        if ($line === 'N/A') $na++;
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_terminate($proc, 9);
    proc_close($proc);

    return $n > 0 ? ($na / $n) : 0.0;
}

/**
 * Fraction of sampled video packets whose DTS goes backwards.
 * Catches files that still have PTS values (so ptsMissingRatio=0)
 * and a short -c copy probe exits 0 but emit Non-monotonic DTS — browsers choke.
 * Decode-order PTS “regressions” from B-frames are normal; DTS must stay monotonic.
 */
function dtsRegressionRatio(string $filepath, int $samplePackets = 80): float
{
    if (!file_exists(MW_FFPROBE)) return 0.0;
    $cmd = sprintf(
        '%s -v error -select_streams v:0 -show_entries packet=dts_time -of csv=p=0 -read_intervals %%+#%d %s 2>&1',
        escapeshellarg(MW_FFPROBE),
        $samplePackets,
        escapeshellarg($filepath)
    );
    $out = [];
    exec($cmd, $out);
    $prev = null;
    $n = 0;
    $reg = 0;
    foreach ($out as $line) {
        $line = trim($line);
        if ($line === '' || $line === 'N/A') continue;
        $t = (float)$line;
        $n++;
        if ($prev !== null && $t + 1e-4 < $prev) $reg++;
        $prev = $t;
    }
    return $n > 0 ? ($reg / $n) : 0.0;
}

/**
 * needs_fix after a real PTS/DTS test (not codec-only).
 * Candidates: .avi, or legacy MPEG-4 Part 2 / Xvid / DivX in any container.
 * Broken if short -c copy probe fails, ≥10% frames pts N/A, or ≥10% packet DTS regressions.
 */
function detectNeedsFix(string $filepath, ?string $format = null, ?string $codec = null): bool
{
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    if ($ext !== 'avi' && !isLegacyMpeg4Codec($format, $codec)) return false;
    if (remuxProbeFails($filepath)) return true;
    if (ptsMissingRatio($filepath) >= 0.10) return true;
    return dtsRegressionRatio($filepath) >= 0.10;
}

function tracksByType(array $json, string $type): array
{
    return array_values(array_filter(
        $json['media']['track'],
        fn($t) => ($t['@type'] ?? '') === $type
    ));
}

function upsertVideo(\SQLite3Stmt $stmt, string $path, array $json, int $needsFix): void
{
    $general = tracksByType($json, 'General')[0] ?? [];
    $video   = tracksByType($json, 'Video')[0] ?? [];
    $audioN  = count(tracksByType($json, 'Audio'));
    $subN    = count(tracksByType($json, 'Text'));

    $stmt->reset();
    $stmt->bindValue(1, $path);
    $stmt->bindValue(2, basename($path));
    $stmt->bindValue(3, dirname($path));
    $stmt->bindValue(4, filesize($path) ?: 0);
    $stmt->bindValue(5, $general['Duration'] ?? null);
    $stmt->bindValue(6, $video['Format'] ?? null);
    $stmt->bindValue(7, $video['CodecID'] ?? ($video['Format_Profile'] ?? null));
    $stmt->bindValue(8, $video['Width'] ?? null);
    $stmt->bindValue(9, $video['Height'] ?? null);
    $stmt->bindValue(10, $video['DisplayAspectRatio_Simplified'] ?? null);
    $stmt->bindValue(11, $video['FrameRate'] ?? null);
    $stmt->bindValue(12, $video['BitRate'] ?? null);
    $stmt->bindValue(13, $audioN);
    $stmt->bindValue(14, $subN);
    $stmt->bindValue(15, $general['Title'] ?? null);
    $stmt->bindValue(16, $json['_raw'] ?? null);
    $stmt->bindValue(17, $needsFix);
    $stmt->execute();
}

$files = [];
foreach ($scanDirs as $scanDir) {
    safeWalk($scanDir, $files, $extensions, $verbose);
}

echo 'Found ' . count($files) . " video files to scan\n";

$db->exec('BEGIN;');

$processed = 0;
$skipped   = 0;
$errors    = 0;
$flagged   = 0;
$updated   = 0;

foreach ($files as $filepath) {
    $processed++;
    $scannedPaths[] = $filepath;
    $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    $isAvi = ($ext === 'avi');
    $known = isset($knownFiles[$filepath]);

    // --scan-only: refresh needs_fix for AVIs + legacy MPEG-4/Xvid in any container.
    if ($scanOnly) {
        $format = null;
        $codec = null;
        if ($known) {
            $stmtMeta->reset();
            $stmtMeta->bindValue(1, $filepath);
            $meta = $stmtMeta->execute()->fetchArray(2);
            if ($meta) {
                $format = $meta[0];
                $codec = $meta[1];
            }
            if (!$isAvi && !isLegacyMpeg4Codec($format, $codec)) {
                // Drop stale needs_fix from older codec-only marking (e.g. H.264 as V_MPEG4/ISO/AVC).
                if (!empty($meta[2])) {
                    $stmtFlag->reset();
                    $stmtFlag->bindValue(1, 0);
                    $stmtFlag->bindValue(2, $filepath);
                    $stmtFlag->execute();
                    $updated++;
                    if ($verbose) echo "  [CLR]  $filepath (not a PTS candidate)\n";
                } else {
                    $skipped++;
                }
                continue;
            }
        } elseif (!$isAvi) {
            // Unknown non-AVI: need mediainfo to know if it's legacy MPEG-4.
            $json = mediainfoJson($filepath);
            if (!$json) { $errors++; continue; }
            $video = tracksByType($json, 'Video')[0] ?? [];
            $format = $video['Format'] ?? null;
            $codec = $video['CodecID'] ?? ($video['Format_Profile'] ?? null);
            if (!isLegacyMpeg4Codec($format, $codec)) {
                upsertVideo($stmtUpsert, $filepath, $json, 0);
                continue;
            }
            $fails = detectNeedsFix($filepath, $format, $codec);
            if ($fails) {
                $flagged++;
                if ($verbose) echo "  [FLAG] $filepath\n";
            } elseif ($verbose) {
                echo "  [OK]   $filepath\n";
            }
            upsertVideo($stmtUpsert, $filepath, $json, $fails ? 1 : 0);
            if ($processed % 200 === 0) { $db->exec('COMMIT;'); $db->exec('BEGIN;'); }
            continue;
        }

        $fails = detectNeedsFix($filepath, $format, $codec);
        if ($fails) {
            $flagged++;
            if ($verbose) echo "  [FLAG] $filepath\n";
        } elseif ($verbose) {
            echo "  [OK]   $filepath\n";
        }
        if ($known) {
            $stmtFlag->reset();
            $stmtFlag->bindValue(1, $fails ? 1 : 0);
            $stmtFlag->bindValue(2, $filepath);
            $stmtFlag->execute();
            $updated++;
        } else {
            $json = mediainfoJson($filepath);
            if (!$json) { $errors++; continue; }
            upsertVideo($stmtUpsert, $filepath, $json, $fails ? 1 : 0);
        }
        if ($processed % 200 === 0) { $db->exec('COMMIT;'); $db->exec('BEGIN;'); }
        continue;
    }

    if ($known && !$forceRescan) {
        $skipped++;
        if ($verbose) echo "  [SKIP] $filepath\n";
        continue;
    }

    $json = mediainfoJson($filepath);
    if (!$json) {
        if ($verbose) echo "  [FAIL] No info: $filepath\n";
        $errors++;
        continue;
    }

    $video = tracksByType($json, 'Video')[0] ?? [];
    $format = $video['Format'] ?? null;
    $codec = $video['CodecID'] ?? ($video['Format_Profile'] ?? null);
    $needsFix = detectNeedsFix($filepath, $format, $codec) ? 1 : 0;
    if ($needsFix) {
        $flagged++;
        if ($verbose) echo "  [FLAG] $filepath\n";
    }

    if ($verbose) echo "  [$processed/" . count($files) . "] $filepath\n";
    upsertVideo($stmtUpsert, $filepath, $json, $needsFix);

    if ($processed % 500 === 0) {
        $db->exec('COMMIT;');
        $db->exec('BEGIN;');
    }
}

$db->exec('COMMIT;');

// Soft-delete rows for files that disappeared (full tree walk only).
if (!$dirOverride && !empty($knownFiles) && !$scanOnly) {
    $deletedSet = array_diff_key($knownFiles, array_flip($scannedPaths));
    if (!empty($deletedSet)) {
        $db->exec('BEGIN;');
        $stmtDel = $db->prepare('UPDATE videos SET is_deleted = 1 WHERE filepath = ?');
        foreach (array_keys($deletedSet) as $p) {
            $stmtDel->reset();
            $stmtDel->bindValue(1, $p);
            $stmtDel->execute();
        }
        $db->exec('COMMIT;');
        echo '  Deleted: ' . count($deletedSet) . "\n";
    }
}

echo "\nScan complete.\n";
echo "  Processed: $processed\n";
echo "  Skipped:   $skipped (already in database)\n";
if ($scanOnly) echo "  Updated:   $updated (needs_fix refreshed)\n";
echo "  Flagged:   $flagged (needs_fix / PTS browser warning)\n";
echo "  Errors:    $errors\n";
echo "  Database:  $dbFile\n";

$db->close();
