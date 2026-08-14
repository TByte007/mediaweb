<?php

/**
 * Series / season / episode detection (scan-time + shared helpers).
 */

declare(strict_types=1);

function isSeriesJunkDir(string $base): bool
{
    static $junk = [
        'sample' => 1, 'samples' => 1, 'cd1' => 1, 'cd2' => 1, 'cd3' => 1, 'cd4' => 1,
        'video_ts' => 1, 'audio_ts' => 1, 'subs' => 1, 'subtitles' => 1, 'subtitle' => 1,
        'extras' => 1, 'featurettes' => 1, 'bonus' => 1, 'bonuses' => 1,
        'proof' => 1, 'cover' => 1, 'covers' => 1, 'screens' => 1, 'screenshots' => 1,
    ];
    return isset($junk[strtolower($base)]);
}

function parseSeasonFromDirName(string $base): ?int
{
    // Episode tokens in a dir name are not a season label
    if (preg_match('/s\d{1,2}e\d{1,2}/i', $base)) return null;
    if (preg_match('/(?:^|[.\s_-])(?:season|series|seizoen)[.\s_-]*(\d{1,2})(?:[.\s_-]|$)/i', $base, $m))
        return (int)$m[1];
    if (preg_match('/(?:^|[.\s_-])s(\d{1,2})(?:[.\s_-]|$)/i', $base, $m))
        return (int)$m[1];
    return null;
}

function cleanEpisodeTitle(string $t): string
{
    $t = preg_replace('/\[[^\]]*\]/', ' ', $t);
    // Trailing scene group only (ALLCAPS): .DIMENSION / -KILLERS — not title words (.Grace)
    $t = preg_replace('/[-.][A-Z][A-Z0-9]{1,15}$/', '', $t);
    $kept = [];
    foreach (preg_split('/[.\s_-]+/', $t) as $p) {
        if ($p === '') continue;
        $l = strtolower($p);
        if (mwIsStripNoise($l) || preg_match('/^\d{3,4}p$/', $l)) continue;
        $isAnd = ($p === '&' || $l === 'and');
        $isPartNum = (bool)preg_match('/^\d{1,2}$/', $p);
        if (!preg_match('/[a-z]{2,}/i', $p) && !$isAnd && !$isPartNum) continue;
        // Keep "Part 1 & 2" — lone digits / & only after Part/Pt/Ep or another part token
        if ($isAnd || $isPartNum) {
            if ($kept === []) continue;
            $prev = strtolower($kept[count($kept) - 1]);
            if (!preg_match('/^(part|pt|episode|ep|\d{1,2}|and|&)$/', $prev)) continue;
        }
        $kept[] = $p;
    }
    return $kept === [] ? '' : prettifyFilename(implode(' ', $kept));
}

/** Drop trailing half of sXXeYY&eZZ / sXXeYY-eZZ (keep first episode only). */
function stripDoubleEpisodeTail(string $rest): string
{
    return (string)preg_replace('/^[&._\s-]*e\d{1,2}\b/i', '', $rest);
}

/**
 * Scene SEE / SSEE after a rip tag: bones.924.hdtv-lol → 9,24; ncis.1306.hdtv → 13,6.
 * 19xx/20xx stays a year (not S19/S20).
 * @return array{season: int, episode: int}|null
 */
function parseSceneEpisode(string $code, string $rest): ?array
{
    if (preg_match('/^(?:19|20)\d{2}$/', $code)) return null;
    if (!preg_match('/\b(hdtv|pdtv|webrip|web-?dl|web|proper|repack|internal)\b/i', $rest))
        return null;
    $n = strlen($code);
    $season = $n === 3 ? (int)$code[0] : (int)substr($code, 0, 2);
    $episode = $n === 3 ? (int)substr($code, 1) : (int)substr($code, 2);
    if ($season < 1 || $episode < 1) return null;
    return ['season' => $season, 'episode' => $episode];
}

/** Show name from an episode filename (SxxExx or scene code). */
function parseShowNameFromFilename(string $filename): ?string
{
    $base = pathinfo($filename, PATHINFO_FILENAME);
    if (preg_match('/^(.+?)[.\-_ ]s\d{1,2}e\d{1,2}\b/i', $base, $m)) {
        $s = prettifyFilename($m[1]);
        return $s !== '' ? $s : null;
    }
    if (preg_match('/^(.+?)[.\-_ ](\d{3,4})[.\-_ ](.+)$/i', $base, $m)
        && parseSceneEpisode($m[2], $m[3]) !== null) {
        $s = prettifyFilename($m[1]);
        return $s !== '' ? $s : null;
    }
    return null;
}

function mwLooseShowKey(string $title): string
{
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $title) ?? '');
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    return (string)preg_replace('/\s+(?:19|20)\d{2}$/', '', $s);
}

/** @return array{season: ?int, episode: ?int, episode_title: ?string} */
function parseEpisodeFields(string $filepath): array
{
    $filepath = str_replace('\\', '/', $filepath);
    $dir = dirname($filepath);
    $parent = basename($dir);
    $out = ['season' => null, 'episode' => null, 'episode_title' => null];
    if (isSeriesJunkDir($parent)) return $out;

    $stem = basename($filepath);
    if (preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm|mpe?g|ts|flv)$/i', $stem))
        $stem = (string)preg_replace('/\.[^.]+$/', '', $stem);

    $seasonFromPath = null;
    $d = $dir;
    while ($d !== '/' && $d !== '.' && $d !== '') {
        $b = basename($d);
        if (!isSeriesJunkDir($b)) {
            $s = parseSeasonFromDirName($b);
            if ($s !== null) {
                $seasonFromPath = $s;
                break;
            }
        }
        $next = dirname($d);
        if ($next === $d) break;
        $d = $next;
    }

    if (preg_match('/s(\d{1,2})e(\d{1,2})(.*)$/i', $stem, $m)
        || preg_match('/\bseason\s*(\d{1,2})\s+episode\s*(\d{1,3})\b(.*)$/i', $stem, $m)) {
        $out['season'] = (int)$m[1];
        $out['episode'] = (int)$m[2];
        $rest = cleanEpisodeTitle(stripDoubleEpisodeTail((string)$m[3]));
        if ($rest !== '') $out['episode_title'] = $rest;
        return $out;
    }

    if (preg_match('/(?:^|[\s._-])(?:episode|ep)\s*(\d{1,3})\b(.*)$/i', $stem, $m)) {
        $out['episode'] = (int)$m[1];
        $out['season'] = preg_match('/\bseason\s*(\d{1,2})\b/i', $stem, $sm)
            ? (int)$sm[1]
            : $seasonFromPath;
        $rest = cleanEpisodeTitle((string)$m[2]);
        if ($rest !== '') $out['episode_title'] = $rest;
        return $out;
    }

    if (preg_match('/^(.+?)[.\s_-](\d{3,4})[.\s_-](.+)$/i', $stem, $m)) {
        $scene = parseSceneEpisode($m[2], $m[3]);
        if ($scene !== null) {
            $out['season'] = $scene['season'];
            $out['episode'] = $scene['episode'];
            return $out;
        }
    }

    if ($seasonFromPath !== null) $out['season'] = $seasonFromPath;
    return $out;
}

/** @return array{root_key: string, top: string}|null */
function showRootFromPath(string $filepath): ?array
{
    $filepath = str_replace('\\', '/', $filepath);
    foreach (MW_MEDIA_DIRS as $root) {
        $fs = rtrim(str_replace('\\', '/', $root['fs']), '/');
        if (!str_starts_with($filepath, $fs . '/')) continue;
        $top = explode('/', substr($filepath, strlen($fs) + 1), 2)[0];
        if ($top === '') return null;
        return ['root_key' => $fs . '/' . $top, 'top' => $top];
    }
    return null;
}

/** Path relative to media root, or null if outside. */
function mediaRelPath(string $filepath): ?string
{
    $filepath = str_replace('\\', '/', $filepath);
    foreach (MW_MEDIA_DIRS as $root) {
        $fs = rtrim(str_replace('\\', '/', $root['fs']), '/');
        if (str_starts_with($filepath, $fs . '/'))
            return substr($filepath, strlen($fs) + 1);
    }
    return null;
}

function seriesShowTitle(string $topFolder): string
{
    $s = preg_replace('/\[[^\]]*\]/', ' ', $topFolder);
    $s = preg_replace('/(?:^|[.\s_-])season[.\s_-]*\d{1,2}[.\s_-]*[-–][.\s_-]*\d{1,2}(?=[.\s_-]|$)/i', ' ', $s);
    $s = preg_replace('/\bseason\s*\d{1,2}(?:\s*,\s*\d{1,2})+(?:\s*&\s*\d{1,2})?/i', ' ', $s);
    $s = preg_replace('/\bs\d{1,2}\s*[-–]\s*s?\d{1,2}\b/i', ' ', $s);
    $s = preg_replace('/(?:^|[.\s_-])season[.\s_-]*\d{1,2}(?=[.\s_-]|$)/i', ' ', $s);
    if (!preg_match('/s\d{1,2}e\d{1,2}/i', $s))
        $s = preg_replace('/(?:^|[.\s_-])s\d{1,2}(?=[.\s_-]|$)/i', ' ', $s);
    $s = preg_replace('/\b(deluxe|boxset|box\s*set|extras?\s+in\s+hd|dvd|bluray|blu\s*ray|amzn|nf|hulu|ddp\d*(?:\.\d+)?)\b/i', ' ', $s);
    $s = preg_replace('/\s*&\s*/', ' ', $s);
    $s = preg_replace('/[-.][A-Za-z][A-Za-z0-9]{1,15}$/', '', $s);
    $s = preg_replace('/\s+/', ' ', trim((string)$s, " \t._-+"));
    return prettifyFilename($s !== '' ? $s : $topFolder);
}

function episodePrettyTitle(
    string $filename,
    ?string $dbTitle,
    ?string $filepath,
    ?int $season,
    ?int $episode,
    ?string $episodeTitle,
    ?string $displayName = null
): string {
    if (($dn = trim((string)$displayName)) !== '') return $dn;

    $epName = trim((string)$episodeTitle);
    if ($season === null || $episode === null) {
        if ($epName !== '') return $epName;
        return videoPrettyTitle($filename, $dbTitle, $filepath);
    }
    $code = sprintf('S%02dE%02d', $season, $episode);
    return $epName !== '' ? "$code · $epName" : $code;
}

/** Adjacent episode id in binge order (season, episode). */
function neighborEpisodeId(\SQLite3 $db, int $seriesId, int $season, int $episode, bool $next): ?int
{
    $op = $next ? '>' : '<';
    $ord = $next ? 'ASC' : 'DESC';
    $st = $db->prepare(
        "SELECT id FROM videos
         WHERE is_deleted = 0 AND series_id = :sid AND season IS NOT NULL AND episode IS NOT NULL
           AND (season $op :s OR (season = :s AND episode $op :e))
         ORDER BY season $ord, episode $ord
         LIMIT 1"
    );
    $st->bindValue(':sid', $seriesId, SQLITE3_INTEGER);
    $st->bindValue(':s', $season, SQLITE3_INTEGER);
    $st->bindValue(':e', $episode, SQLITE3_INTEGER);
    $row = $st->execute()->fetchArray(2);
    return $row ? (int)$row[0] : null;
}

/**
 * Re-parse living videos, qualify show roots, upsert series, set series_id.
 *
 * @return array{series: int, linked: int}
 */
function linkSeries(\SQLite3 $db): array
{
    $rs = $db->query('SELECT id, filepath, directory FROM videos WHERE is_deleted = 0');
    $byRoot = [];
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
        $root = showRootFromPath((string)$row['filepath']);
        if ($root === null) continue;
        $key = $root['root_key'];
        if (!isset($byRoot[$key]))
            $byRoot[$key] = ['top' => $root['top'], 'videos' => [], 'season_dirs' => []];

        $fields = parseEpisodeFields((string)$row['filepath']);
        $byRoot[$key]['videos'][] = [
            'id' => (int)$row['id'],
            'season' => $fields['season'],
            'episode' => $fields['episode'],
            'episode_title' => $fields['episode_title'],
        ];

        $dir = str_replace('\\', '/', (string)$row['directory']);
        if (str_starts_with($dir, $key)) {
            foreach (explode('/', trim(substr($dir, strlen($key)), '/')) as $seg) {
                if ($seg !== '' && parseSeasonFromDirName($seg) !== null)
                    $byRoot[$key]['season_dirs'][$seg] = true;
            }
        } elseif (parseSeasonFromDirName($root['top']) !== null) {
            $byRoot[$key]['season_dirs'][$root['top']] = true;
        }
    }

    // Reset parse/link fields; re-apply only for rooted videos below
    $db->exec(
        'UPDATE videos SET series_id = NULL, season = NULL, episode = NULL, episode_title = NULL
         WHERE is_deleted = 0'
    );

    $stmtFields = $db->prepare(
        'UPDATE videos SET season = ?, episode = ?, episode_title = ?, series_id = ?,
         tmdb_id = CASE WHEN ? IS NOT NULL THEN NULL ELSE tmdb_id END WHERE id = ?'
    );
    $stmtSeries = $db->prepare(
        'INSERT INTO series (root_key, title, cover_video_id, updated_at)
         VALUES (?, ?, ?, datetime(\'now\'))
         ON CONFLICT(root_key) DO UPDATE SET
           title = CASE
             WHEN series.tmdb_id IS NOT NULL THEN series.title
             ELSE excluded.title
           END,
           cover_video_id = excluded.cover_video_id,
           updated_at = datetime(\'now\')'
    );
    $stmtGetId = $db->prepare('SELECT id, title FROM series WHERE root_key = ?');

    $seriesCount = 0;
    $linked = 0;
    $keepIds = [];
    $titleToId = [];

    foreach ($byRoot as $key => $info) {
        $withEp = 0;
        $coverId = null;
        foreach ($info['videos'] as $v) {
            if ($v['episode'] === null) continue;
            $withEp++;
            if ($coverId === null && $v['season'] !== null) $coverId = $v['id'];
        }
        $qualify = count($info['season_dirs']) >= 2 || $withEp >= 5;

        $seriesId = null;
        if ($qualify) {
            $showTitle = seriesShowTitle($info['top']);
            $loose = mwLooseShowKey($showTitle);
            $seriesId = $titleToId[$loose] ?? null;
            if ($seriesId === null) {
                if ($coverId === null && $info['videos'] !== [])
                    $coverId = $info['videos'][0]['id'];
                $stmtSeries->reset();
                $stmtSeries->bindValue(1, $key);
                $stmtSeries->bindValue(2, $showTitle);
                $stmtSeries->bindValue(3, $coverId, $coverId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
                $stmtSeries->execute();
                $stmtGetId->reset();
                $stmtGetId->bindValue(1, $key);
                $got = $stmtGetId->execute()->fetchArray(SQLITE3_ASSOC);
                $seriesId = (int)$got['id'];
                $keepIds[] = $seriesId;
                $seriesCount++;
                if ($loose !== '') $titleToId[$loose] = $seriesId;
                $stored = mwLooseShowKey((string)$got['title']);
                if ($stored !== '') $titleToId[$stored] = $seriesId;
            }
        }

        foreach ($info['videos'] as $v) {
            $sid = ($seriesId !== null && $v['season'] !== null && $v['episode'] !== null)
                ? $seriesId : null;
            if ($sid !== null) $linked++;
            $stmtFields->reset();
            $stmtFields->bindValue(1, $v['season'], $v['season'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmtFields->bindValue(2, $v['episode'], $v['episode'] === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmtFields->bindValue(3, $v['episode_title'], $v['episode_title'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmtFields->bindValue(4, $sid, $sid === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmtFields->bindValue(5, $sid, $sid === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmtFields->bindValue(6, $v['id'], SQLITE3_INTEGER);
            $stmtFields->execute();
        }
    }

    // One-off episode torrents (not a pack folder): group by show name.
    $byShow = [];
    $rsFn = $db->query(
        'SELECT id, filename FROM videos
         WHERE is_deleted = 0 AND series_id IS NULL AND season IS NOT NULL AND episode IS NOT NULL'
    );
    while ($row = $rsFn->fetchArray(SQLITE3_ASSOC)) {
        $show = parseShowNameFromFilename((string)$row['filename']);
        if ($show === null) continue;
        $key = mwLooseShowKey($show);
        if ($key === '') continue;
        if (!isset($byShow[$key])) $byShow[$key] = ['title' => $show, 'ids' => []];
        $byShow[$key]['ids'][] = (int)$row['id'];
    }
    $rsFn->finalize();

    $stmtLink = $db->prepare('UPDATE videos SET series_id = ?, tmdb_id = NULL WHERE id = ?');
    foreach ($byShow as $key => $grp) {
        $seriesId = $titleToId[$key] ?? null;
        if ($seriesId === null) {
            $stmtSeries->reset();
            $stmtSeries->bindValue(1, 'loose:' . $key);
            $stmtSeries->bindValue(2, $grp['title']);
            $stmtSeries->bindValue(3, $grp['ids'][0], SQLITE3_INTEGER);
            $stmtSeries->execute();
            $stmtGetId->reset();
            $stmtGetId->bindValue(1, 'loose:' . $key);
            $seriesId = (int)$stmtGetId->execute()->fetchArray(2)[0];
            $keepIds[] = $seriesId;
            $seriesCount++;
        }
        foreach ($grp['ids'] as $vid) {
            $stmtLink->reset();
            $stmtLink->bindValue(1, $seriesId, SQLITE3_INTEGER);
            $stmtLink->bindValue(2, $vid, SQLITE3_INTEGER);
            $stmtLink->execute();
            $linked++;
        }
    }

    if ($keepIds !== []) {
        $in = implode(',', array_map('intval', $keepIds));
        $db->exec("DELETE FROM series_seasons WHERE series_id NOT IN ($in)");
        $db->exec("DELETE FROM series WHERE id NOT IN ($in)");
    } else {
        $db->exec('DELETE FROM series_seasons');
        $db->exec('DELETE FROM series');
    }

    return ['series' => $seriesCount, 'linked' => $linked];
}
