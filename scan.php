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
require __DIR__ . '/web/layout/helpers.php';
require __DIR__ . '/series.php';
require __DIR__ . '/llm.php';

$scanDirs = array_map(fn($d) => rtrim($d['fs'], '/'), MW_MEDIA_DIRS);
$dbFile         = MW_DB;
$verbose        = false;
$scanOnly       = false;
$forceRescan    = false;
$seriesBackfill = false;
$llmTitles      = false;
$forceLlm       = false;
$dirOverride    = false;

$opts = getopt('', [
    'dir:', 'db:', 'verbose', 'scan-only', 'force-rescan', 'series-backfill',
    'llm-titles', 'force', 'help',
]);

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
    --series-backfill    Re-link series/seasons/episodes from paths only (no tree walk)
    --llm-titles         Polish series.title + videos.name via llama-server (MW_LLM_URL)
    --force              With --llm-titles: overwrite existing names
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

    Series:
        After a normal scan (not --scan-only), link shows/seasons/episodes from
        paths into the series table (see series.php). Use --series-backfill to
        re-run only that pass (no MediaInfo / no filesystem walk).

    --llm-titles:
        Requires MW_LLM_URL (llama-server). Updates series.title and fills videos.name
        (unnamed rows, or all with --force). Heuristics used if LLM is off/down.
        Thinking disabled. Run after --series-backfill when polishing a library.

USAGE;
    echo str_replace('MW_DB', MW_DB, "Default DB: MW_DB\n");
    exit(0);
}

if (!empty($opts['db']))              $dbFile         = $opts['db'];
if (isset($opts['verbose']))          $verbose        = true;
if (isset($opts['scan-only']))        $scanOnly       = true;
if (isset($opts['force-rescan']))     $forceRescan    = true;
if (isset($opts['series-backfill']))  $seriesBackfill = true;
if (isset($opts['llm-titles']))       $llmTitles      = true;
if (isset($opts['force']))            $forceLlm       = true;

$skipMediaTools = $seriesBackfill || $llmTitles;
if (!$skipMediaTools) {
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
    series_id       INTEGER,
    season          INTEGER,
    episode         INTEGER,
    episode_title   TEXT,
    name            TEXT,
    scanned_at      DATETIME NOT NULL DEFAULT (datetime('now')),
    updated_at      DATETIME NOT NULL DEFAULT (datetime('now'))
);
SQL
);

$db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS series (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    root_key        TEXT NOT NULL UNIQUE,
    title           TEXT NOT NULL,
    cover_video_id  INTEGER,
    updated_at      DATETIME NOT NULL DEFAULT (datetime('now'))
);
SQL
);

$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_directory ON videos(directory);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_width   ON videos(width);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_height  ON videos(height);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_playback_count ON videos(playback_count DESC);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_videos_series ON videos(series_id, season, episode);');
$db->exec('CREATE INDEX IF NOT EXISTS idx_series_root ON series(root_key);');

$result = $db->query('PRAGMA table_info(videos);');
$hasNeedsFix = false;
$hasIsDeleted = false;
$hasSeriesId = false;
$hasSeason = false;
$hasEpisode = false;
$hasEpisodeTitle = false;
$hasName = false;
while ($col = $result->fetchArray(2)) {
    if (!empty($col) && isset($col[1])) {
        if ($col[1] === 'needs_fix') $hasNeedsFix = true;
        if ($col[1] === 'is_deleted') $hasIsDeleted = true;
        if ($col[1] === 'series_id') $hasSeriesId = true;
        if ($col[1] === 'season') $hasSeason = true;
        if ($col[1] === 'episode') $hasEpisode = true;
        if ($col[1] === 'episode_title') $hasEpisodeTitle = true;
        if ($col[1] === 'name') $hasName = true;
    }
}
if (!$hasNeedsFix) $db->exec('ALTER TABLE videos ADD COLUMN needs_fix INTEGER DEFAULT 0;');
if (!$hasIsDeleted) $db->exec('ALTER TABLE videos ADD COLUMN is_deleted INTEGER DEFAULT 0;');
if (!$hasSeriesId) $db->exec('ALTER TABLE videos ADD COLUMN series_id INTEGER;');
if (!$hasSeason) $db->exec('ALTER TABLE videos ADD COLUMN season INTEGER;');
if (!$hasEpisode) $db->exec('ALTER TABLE videos ADD COLUMN episode INTEGER;');
if (!$hasEpisodeTitle) $db->exec('ALTER TABLE videos ADD COLUMN episode_title TEXT;');
if (!$hasName) $db->exec('ALTER TABLE videos ADD COLUMN name TEXT;');

if ($seriesBackfill) {
    $db->exec('BEGIN;');
    $stats = linkSeries($db);
    $db->exec('COMMIT;');
    echo "Series backfill complete.\n";
    echo "  Series:   {$stats['series']} shows\n";
    echo "  Linked:   {$stats['linked']} episodes\n";
    echo "  Database: $dbFile\n";
    $db->close();
    exit(0);
}

if ($llmTitles) {
    if (!mwLlmEnabled()) {
        echo "LLM titles skipped: MW_LLM_URL is empty (heuristics only).\n";
        $db->close();
        exit(0);
    }
    if (!mwLlmAvailable()) {
        echo "LLM titles skipped: llama-server not reachable at " . MW_LLM_URL . "\n";
        $db->close();
        exit(0);
    }

    $sysSeries = 'You name TV shows from torrent/release folder names. '
        . 'Reply with ONLY the canonical show title on one line. '
        . 'Expand codes like DS9, SGA, SGU, TNG. '
        . 'Keep the year in parentheses when known, e.g. Title (1993). '
        . 'No quality, codec, or group tags.';
    $sysVideo = 'Using ONLY words from the user message (file, hint, show), output one display title. '
        . 'Never invent words. Never reuse titles from other videos. '
        . 'Never echo field labels (file/hint/show) in the reply. '
        . 'Keep abbreviations as written (Vol stays Vol, not Volume). '
        . 'Tags like KORSUB, HDRip, XviD, 2hd, EVO are NOT titles — ignore them. '
        . 'Rules: '
        . '(1) If hint or file has a real episode name (not just SxxExx / epNN / a release group), '
        . 'output: {show}: {EpisodeName} {SxxExx}. '
        . '(2) If there is only SxxExx (or SxxExx plus a release group like 2hd), output: {show} {SxxExx}. '
        . 'If show is missing, take the show name from the file (words before SxxExx). '
        . '(3) For movies, keep the year in parentheses when known, e.g. Title (1993). '
        . 'Always use parentheses around the year: {Title} (YYYY) — never Title YYYY. '
        . '(4) Do not repeat the episode code. Keep the full episode name — do not truncate. '
        . 'Prefer a best-effort title over SKIP. Reply SKIP only if there are truly no title words.';

    $titleGrounded = static function (string $reply, string $user): bool {
        $tok = static function (string $s): array {
            $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? '');
            $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            // Vol ↔ Volume so "Vol 2" inputs accept "Volume 2" replies and vice versa
            $out = [];
            foreach ($parts as $t) {
                $out[] = $t;
                if ($t === 'vol') $out[] = 'volume';
                if ($t === 'volume') $out[] = 'vol';
            }
            return $out;
        };
        $allow = array_flip($tok($user));
        foreach ($tok($reply) as $t) {
            if (preg_match('/^(s\d{1,2}e\d{1,2}|e\d+|ep\d+|season|episode|file|hint|show)$/', $t)) continue;
            if (strlen($t) <= 1) continue;
            if (!isset($allow[$t])) return false;
        }
        return true;
    };
    $titleEchoesLabel = static function (string $reply): bool {
        return (bool)preg_match('/^(path|heuristic|show|file|hint)\s*:/i', $reply)
            || (bool)preg_match('/^(path|heuristic|file|hint)$/i', $reply);
    };
    $movieTitleFromHint = static function (string $heur): string {
        $h = preg_replace(
            '/\b(korsub|hdrip|bluray|blu-?ray|webrip|web-?dl|hdtv|dvdrip|xvid|x264|x265|hevc|ac3|aac|dts|evo|vain|2hd|rarbg|yify)\b/i',
            ' ',
            $heur
        );
        $h = preg_replace('/\s+/', ' ', trim((string)$h));
        if (preg_match('/^(.*?)(?:\s+)((?:19|20)\d{2})$/', $h, $m))
            return trim($m[1]) . ' (' . $m[2] . ')';
        return $h;
    };
    $showFromFilename = static function (string $fn): ?string {
        $base = pathinfo($fn, PATHINFO_FILENAME);
        if (!preg_match('/^(.+?)[.\-_ ]s\d{1,2}e\d{1,2}\b/i', $base, $m)) return null;
        $s = prettifyFilename($m[1]);
        return $s !== '' ? $s : null;
    };

    // Long LLM waits must not hold an open SELECT cursor (blocks other writers → "database is locked").
    $db->busyTimeout(60000);
    // SQLite result codes: https://sqlite.org/rescode.html (not exposed by PHP's SQLite3 ext)
    if (!defined('SQLITE_BUSY')) define('SQLITE_BUSY', 5);
    if (!defined('SQLITE_LOCKED')) define('SQLITE_LOCKED', 6);
    $dbExec = static function (\SQLite3Stmt $st) use ($db): bool {
        for ($i = 0; $i < 20; $i++) {
            if ($st->execute() !== false) return true;
            $primary = $db->lastErrorCode() & 0xff;
            if ($primary !== SQLITE_BUSY && $primary !== SQLITE_LOCKED) return false;
            usleep(50_000 * min($i + 1, 10));
            $st->reset();
        }
        return false;
    };

    $seriesUpdated = 0;
    $seriesFailed = 0;
    $seriesRows = [];
    $rs = $db->query('SELECT id, root_key, title FROM series ORDER BY id');
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) $seriesRows[] = $row;
    $rs->finalize();
    $stmtSer = $db->prepare('UPDATE series SET title = ?, updated_at = datetime(\'now\') WHERE id = ?');
    foreach ($seriesRows as $row) {
        $top = basename(str_replace('\\', '/', (string)$row['root_key']));
        $user = "Folder: $top\nCurrent title: {$row['title']}";
        $out = mwLlmChat($sysSeries, $user);
        echo "series #{$row['id']} | $top | {$row['title']}  →  "
            . ($out === null ? '(fail)' : $out) . "\n";
        if ($out === null) {
            $seriesFailed++;
            continue;
        }
        $stmtSer->reset();
        $stmtSer->bindValue(1, $out);
        $stmtSer->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
        if (!$dbExec($stmtSer)) {
            echo "  (db locked, series #{$row['id']} not saved)\n";
            $seriesFailed++;
            continue;
        }
        $seriesUpdated++;
    }

    $videoNamed = 0;
    $videoSkipped = 0;
    $videoFailed = 0;
    $sql = 'SELECT v.id, v.filepath, v.filename, v.title, v.series_id, v.season, v.episode,
                   v.episode_title, s.title AS series_title
            FROM videos v
            LEFT JOIN series s ON s.id = v.series_id
            WHERE v.is_deleted = 0';
    if (!$forceLlm) $sql .= ' AND (v.name IS NULL OR v.name = \'\')';
    $sql .= ' ORDER BY v.id';
    $videoRows = [];
    $rv = $db->query($sql);
    while ($row = $rv->fetchArray(SQLITE3_ASSOC)) $videoRows[] = $row;
    $rv->finalize();
    $stmtName = $db->prepare('UPDATE videos SET name = ?, updated_at = datetime(\'now\') WHERE id = ?');
    foreach ($videoRows as $row) {
        $fn = (string)$row['filename'];
        $fp = (string)$row['filepath'];
        $season = $row['season'] !== null ? (int)$row['season'] : null;
        $episode = $row['episode'] !== null ? (int)$row['episode'] : null;
        $heur = episodePrettyTitle(
            $fn, $row['title'], $fp, $season, $episode, $row['episode_title']
        );
        $rel = mediaRelPath($fp) ?? $fp;
        $show = !empty($row['series_title']) ? (string)$row['series_title'] : '-';
        $code = ($season !== null && $episode !== null)
            ? sprintf('S%02dE%02d', $season, $episode) : null;
        // Heuristic is only SxxExx, or SxxExx · releaseGroup → no real episode title
        $epTail = null;
        if ($code !== null && preg_match('/^S\d{2}E\d{2}\s*[·\-–]\s*(\S+)\s*$/u', trim($heur), $m))
            $epTail = $m[1];
        $codeOnly = $code !== null && (
            preg_match('/^S\d{2}E\d{2}$/i', trim($heur))
            || ($epTail !== null && !str_contains($epTail, ' ')
                && preg_match('/^[a-z0-9][a-z0-9._-]{1,20}$/i', $epTail))
        );
        if ($codeOnly && $show === '-') {
            $guess = $showFromFilename($fn);
            if ($guess !== null) $show = $guess;
        }

        if ($codeOnly && $show !== '-') {
            $out = "$show $code";
            echo "video #{$row['id']} | $show | $heur | $rel  →  $out (no-llm)\n";
            $stmtName->reset();
            $stmtName->bindValue(1, $out);
            $stmtName->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
            if ($dbExec($stmtName)) $videoNamed++;
            else {
                echo "  (db locked, not saved)\n";
                $videoFailed++;
            }
            continue;
        }

        $user = "file: $rel\nhint: $heur";
        if ($show !== '-') $user .= "\nshow: $show";
        $out = mwLlmChat($sysVideo, $user);
        if ($out !== null && strcasecmp($out, 'SKIP') !== 0 && $titleEchoesLabel($out)) {
            echo "video #{$row['id']} | $show | $heur | $rel  →  (label-echo: $out)\n";
            $videoFailed++;
            continue;
        }
        if ($out !== null && strcasecmp($out, 'SKIP') === 0) {
            // Dense models over-SKIP; fall back when we still have usable hint text
            if ($show !== '-' && $code !== null) {
                $out = "$show $code";
            } elseif ($code !== null && ($guess = $showFromFilename($fn))) {
                $out = "$guess $code";
            } elseif (preg_match('/[a-z]{2,}/i', $heur) && !preg_match('/^S\d{2}E\d{2}\b/i', trim($heur))) {
                $out = $movieTitleFromHint($heur);
            } else {
                echo "video #{$row['id']} | $show | $heur | $rel  →  SKIP\n";
                $videoSkipped++;
                continue;
            }
        }
        if ($out !== null && !$titleGrounded($out, $user)) {
            if ($show !== '-' && $code !== null) {
                $out = "$show $code";
            } elseif ($code !== null && ($guess = $showFromFilename($fn))) {
                $out = "$guess $code";
            } elseif (preg_match('/[a-z]{2,}/i', $heur) && !preg_match('/^S\d{2}E\d{2}\b/i', trim($heur))) {
                $out = $movieTitleFromHint($heur);
            } else {
                echo "video #{$row['id']} | $show | $heur | $rel  →  (ungrounded)\n";
                $videoFailed++;
                continue;
            }
        }
        // "Show: Ep04 S01E04" — number-only fake episode name
        if ($out !== null && preg_match('/:\s*(ep|e|episode)\s*\d+\s+s\d{1,2}e\d{1,2}\s*$/i', $out)) {
            if (preg_match('/^(.*?)\s+s(\d{1,2})e(\d{1,2})\s*$/i', $out, $m)) {
                $showOnly = trim(preg_replace('/:\s*(ep|e|episode)\s*\d+\s*$/i', '', $m[1]) ?? $m[1]);
                $out = sprintf('%s S%02dE%02d', $showOnly, (int)$m[2], (int)$m[3]);
            }
        }
        // Series replies must keep the episode code
        if ($out !== null && $code !== null && $show !== '-'
            && !preg_match('/S\d{1,2}E\d{1,2}\s*$/i', $out)) {
            $out = rtrim($out) . " $code";
        }
        $recv = $out === null ? '(fail)' : $out;
        echo "video #{$row['id']} | $show | $heur | $rel  →  $recv\n";
        if ($out === null) {
            $videoFailed++;
            continue;
        }
        $stmtName->reset();
        $stmtName->bindValue(1, $out);
        $stmtName->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
        if (!$dbExec($stmtName)) {
            echo "  (db locked, not saved)\n";
            $videoFailed++;
            continue;
        }
        $videoNamed++;
    }

    echo "LLM titles complete.\n";
    echo "  Series updated: $seriesUpdated (failed: $seriesFailed)\n";
    echo "  Videos named:   $videoNamed (skipped: $videoSkipped, failed: $videoFailed)\n";
    echo "  Database:       $dbFile\n";
    $db->close();
    exit(0);
}

$stmtUpsert = $db->prepare(<<<SQL
INSERT INTO videos
  (filepath, filename, directory, filesize_bytes, duration_secs,
   video_format, video_codec, width, height, aspect_ratio, frame_rate,
   video_bitrate, audio_tracks, subtitle_tracks, title, full_info, needs_fix,
   season, episode, episode_title, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
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
  season          = excluded.season,
  episode         = excluded.episode,
  episode_title   = excluded.episode_title,
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
    $ep = parseEpisodeFields($path);

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
    $stmt->bindValue(18, $ep['season'], $ep['season'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(19, $ep['episode'], $ep['episode'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
    $stmt->bindValue(20, $ep['episode_title'], $ep['episode_title'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
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

$seriesStats = ['series' => 0, 'linked' => 0];
if (!$scanOnly) {
    $db->exec('BEGIN;');
    $seriesStats = linkSeries($db);
    $db->exec('COMMIT;');
}

echo "\nScan complete.\n";
echo "  Processed: $processed\n";
echo "  Skipped:   $skipped (already in database)\n";
if ($scanOnly) echo "  Updated:   $updated (needs_fix refreshed)\n";
echo "  Flagged:   $flagged (needs_fix / PTS browser warning)\n";
echo "  Errors:    $errors\n";
if (!$scanOnly) {
    echo "  Series:    {$seriesStats['series']} shows, {$seriesStats['linked']} episodes linked\n";
}
echo "  Database:  $dbFile\n";

$db->close();
