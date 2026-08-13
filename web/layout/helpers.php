<?php

require_once dirname(__DIR__, 2) . '/strip-lists.php';

function fmtDuration(float $secs): string
{
    $h = intdiv((int)$secs, 3600);
    $m = intdiv((int)$secs % 3600, 60);
    if ($h > 0) return sprintf('%dh %02dm', $h, $m);
    return sprintf('%dm', $m) . ($m < 10 || intval($secs) % 60 > 0 ? sprintf(' %02ds', intdiv((int)$secs, 1) % 60) : '');
}

function fmtSize(int $bytes): string
{
    if ($bytes < 1_048_576) return round($bytes / 1024) . ' KB';
    if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
    return round($bytes / 1_073_741_824, 1) . ' GB';
}

/** MediaInfo bitrate is bits/sec. */
function fmtBitrate(?int $bps): string
{
    if ($bps === null || $bps <= 0) return '—';
    if ($bps < 1_000_000) return round($bps / 1000) . ' kb/s';
    return round($bps / 1_000_000, 1) . ' Mb/s';
}

function fmtFps(?string $fps): string
{
    if ($fps === null || $fps === '' || (float)$fps <= 0) return '—';
    return rtrim(rtrim(sprintf('%.3f', (float)$fps), '0'), '.') . ' fps';
}

function fmtAspect(int $w, int $h): string
{
    if ($w < 1 || $h < 1) return '—';
    $a = $w;
    $b = $h;
    while ($b !== 0) {
        $t = $b;
        $b = $a % $b;
        $a = $t;
    }
    return intdiv($w, $a) . ':' . intdiv($h, $a);
}

/** @return list<array{id: int, name: string}> */
function mwGenresFromCsv(\SQLite3 $db, ?string $csv): array
{
    if ($csv === null || $csv === '') return [];
    $ids = [];
    foreach (explode(',', $csv) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) $ids[] = (int)$p;
    }
    if ($ids === []) return [];
    $byId = [];
    $rs = $db->query('SELECT id, name FROM genres WHERE id IN (' . implode(',', $ids) . ')');
    while ($row = $rs->fetchArray(SQLITE3_ASSOC))
        $byId[(int)$row['id']] = (string)$row['name'];
    $out = [];
    foreach ($ids as $id) {
        if (isset($byId[$id])) $out[] = ['id' => $id, 'name' => $byId[$id]];
    }
    return $out;
}

function cleanTitle(?string $t): string
{
    if (!$t) return '';
    $t = preg_replace('/\*[^*]+\*/', '', $t); // strip *tags*
    return trim($t, " ._-");
}

function isJunkTitleDir(string $base): bool
{
    static $junk = [
        'sample' => 1, 'samples' => 1, 'cd1' => 1, 'cd2' => 1, 'cd3' => 1, 'cd4' => 1,
        'video_ts' => 1, 'audio_ts' => 1, 'subs' => 1, 'subtitles' => 1, 'subtitle' => 1,
        'proof' => 1, 'cover' => 1, 'covers' => 1, 'screens' => 1, 'screenshots' => 1,
    ];
    if (isset($junk[strtolower($base)])) return true;
    // Season packs: …/Show Boxset/Season 3/ep.avi
    return (bool)preg_match('/^(season|series|seizoen)\s*\d{1,2}$/i', $base)
        || (bool)preg_match('/^s\d{1,2}$/i', $base);
}

/** Scene shortnames like undead-tawp.avi — not real titles. */
function filenameIsCryptic(string $filename): bool
{
    $base = basename($filename);
    if (preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm|mpe?g|ts|flv)$/i', $base))
        $base = preg_replace('/\.[^.]+$/', '', $base);
    if ($base === '') return true;
    // Readable / episodic names keep the filename
    if (str_contains($base, ' ')) return false;
    if (preg_match('/s\d{1,2}e\d{1,2}/i', $base)) return false;
    if (preg_match('/(^|[._-])(ep|e|episode)\d+/i', $base)) return false;
    if (preg_match('/(^|[._-])(19|20)\d{2}([._-]|$)/', $base)) return false;
    // Compact group-abbrev tokens: undead-tawp, deadp2-din, otv-dcixuo-tvxvid
    if (strlen($base) <= 24 && preg_match('/^[a-z0-9]+(-[a-z0-9]+)+$/i', $base)) return true;
    return strlen($base) <= 12;
}

/**
 * Immediate parent folder below a media root, skipping junk dirs (Sample, Season 3, …).
 * Null when the file sits on a scan root (or path is outside roots).
 */
function titleFolderFromPath(string $filepath): ?string
{
    $filepath = str_replace('\\', '/', $filepath);
    $dir = dirname($filepath);
    while ($dir !== '/' && $dir !== '.' && $dir !== '') {
        $dir = rtrim($dir, '/');
        $under = false;
        foreach (MW_MEDIA_DIRS as $root) {
            $fs = rtrim(str_replace('\\', '/', $root['fs']), '/');
            if ($dir === $fs) return null;
            if (str_starts_with($dir, $fs . '/')) {
                $under = true;
                break;
            }
        }
        if (!$under) return null;
        $base = basename($dir);
        if ($base !== '' && !isJunkTitleDir($base)) return $base;
        $dir = dirname($dir);
    }
    return null;
}

/** Dot/underscore release names → "Team America World Police 2004" */
function prettifyFilename(string $filename): string
{
    $base = basename($filename);
    if (preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm|mpe?g|ts|flv)$/i', $base))
        $base = preg_replace('/\.[^.]+$/', '', $base);

    // " - " first, then dots/underscores/spaces. Keep SG-1, 1-10, etc.
    $parts = preg_split('/\s+-\s+|[.\s_]+/', (string)$base);
    $kept = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '-') continue;
        $l = strtolower($p);
        if (mwIsStripNoise($l)) continue;
        if (preg_match('/^\d{3,4}p$/', $l)) continue; // 720p / 1080p
        // XviD-UNDEAD / AAC-ANUBIS (codec-group glued with hyphen)
        if (preg_match('/^([^-]+)-(.+)$/', $l, $m) && mwIsStripNoise($m[1])) continue;
        $kept[] = $p;
    }
    $base = implode(' ', $kept);
    $base = preg_replace('/\s+/', ' ', trim((string)$base));
    if ($base === '') return basename($filename);

    static $small = [
        'a' => 1, 'an' => 1, 'the' => 1, 'and' => 1, 'but' => 1, 'or' => 1,
        'for' => 1, 'nor' => 1, 'on' => 1, 'at' => 1, 'to' => 1, 'from' => 1,
        'by' => 1, 'of' => 1, 'in' => 1, 'as' => 1, 'vs' => 1, 'via' => 1,
    ];
    $words = explode(' ', $base);
    foreach ($words as $i => &$w) {
        if (preg_match('/^(ep|e)(\d+)$/i', $w, $m)) {
            $w = 'Ep' . $m[2];
            continue;
        }
        if (preg_match('/^s(\d{1,2})e(\d{1,2})$/i', $w, $m)) {
            $w = 'S' . str_pad($m[1], 2, '0', STR_PAD_LEFT)
                . 'E' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
            continue;
        }
        // SG-1 / X-Files — keep the hyphen, don't title-case into Sg 1 / X-files
        if (preg_match('/^([A-Za-z]{1,5})-(\d+)$/', $w, $m)) {
            $w = strtoupper($m[1]) . '-' . $m[2];
            continue;
        }
        if (preg_match('/^([A-Za-z])-([A-Za-z].*)$/', $w, $m)) {
            $w = strtoupper($m[1]) . '-' . strtoupper($m[2][0]) . strtolower(substr($m[2], 1));
            continue;
        }
        // Already-acronym token (SG, NASA) — don't title-case to Sg
        if (preg_match('/^[A-Z]{2,}$/', $w)) continue;
        // Letter-only show codes (digit codes like DS9 handled below)
        static $showCodes = ['tng' => 1, 'tos' => 1, 'voy' => 1, 'ent' => 1];
        $lower = strtolower($w);
        if (isset($showCodes[$lower])) {
            $w = strtoupper($w);
            continue;
        }
        // DS9 / SGA / SGU / SG1
        if (preg_match('/^[A-Za-z]{1,4}\d{1,3}$/i', $w)
            && !preg_match('/^(s|e|ep)\d+$/i', $w)) {
            $w = strtoupper($w);
            continue;
        }
        if ($i > 0 && isset($small[$lower])) {
            $w = $lower;
            continue;
        }
        $w = strtoupper($lower[0] ?? '') . substr($lower, 1);
    }
    unset($w);
    return implode(' ', $words);
}

/**
 * Prefer a real metadata title; else parent folder when the filename is a
 * cryptic scene shortname; else prettify the filename.
 * $displayName (videos.name) wins when set.
 */
function videoPrettyTitle(
    string $filename,
    ?string $dbTitle = null,
    ?string $filepath = null,
    ?string $displayName = null
): string {
    if (($dn = trim((string)$displayName)) !== '') return $dn;
    $cleaned = cleanTitle($dbTitle);
    if ($cleaned !== '' && substr_count($cleaned, '.') < 2
        && !preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm)$/i', $cleaned)) {
        return $cleaned;
    }
    if ($filepath && filenameIsCryptic($filename)) {
        $folder = titleFolderFromPath($filepath);
        if ($folder !== null) return prettifyFilename($folder);
    }
    return prettifyFilename($cleaned !== '' ? $cleaned : $filename);
}

function pageUrl(array $extra = []): string
{
    global $basePath;
    $params = array_merge($_GET, $extra);
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') unset($params[$k]);
    }
    if ($params === []) return $basePath;
    return $basePath . '?' . http_build_query($params);
}

/**
 * Genres present in the library (or series-browse only).
 * @return list<array{id: int, name: string}>
 */
function mwGenresForFilter(\SQLite3 $db, bool $seriesOnly = false): array
{
    $sql = $seriesOnly
        ? "SELECT g.id, g.name FROM genres g
           WHERE EXISTS (
               SELECT 1 FROM series s
               JOIN videos v ON v.series_id = s.id AND v.is_deleted = 0
               WHERE (',' || IFNULL(s.genre_ids,'') || ',') LIKE '%,' || g.id || ',%'
           )
           ORDER BY g.name COLLATE NOCASE"
        : "SELECT g.id, g.name FROM genres g
           WHERE EXISTS (
               SELECT 1 FROM videos v
               LEFT JOIN series s ON s.id = v.series_id
               WHERE v.is_deleted = 0
                 AND (
                   (v.series_id IS NOT NULL AND (',' || IFNULL(s.genre_ids,'') || ',') LIKE '%,' || g.id || ',%')
                   OR (v.series_id IS NULL AND (',' || IFNULL(v.genre_ids,'') || ',') LIKE '%,' || g.id || ',%')
                 )
           )
           ORDER BY g.name COLLATE NOCASE";
    $out = [];
    $rs = $db->query($sql);
    while ($row = $rs->fetchArray(SQLITE3_ASSOC))
        $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    return $out;
}
