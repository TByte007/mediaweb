<?php

/**
 * Display-title enrichment after linkSeries():
 * dirs/files → (LLM search terms) → TMDB → id; then LLM/PHP gap-fill.
 * CLI only — never call from Apache page views.
 */

declare(strict_types=1);

const MW_LOG = 0;
const MW_LOG_VERBOSE = 1;

/** Max log level to print. --verbose sets this to MW_LOG_VERBOSE. */
$verbose = MW_LOG;

function mwLog(string $msg, int $level = MW_LOG): void
{
    global $verbose;
    if ($level > $verbose) return;
    echo $msg . "\n";
}

/**
 * @return array{
 *   leftover: int,
 *   tmdb_series: int, tmdb_ep: int, tmdb_movie: int,
 *   llm_series: int, llm_video: int,
 *   php_video: int
 * }
 */
function enrichTitles(\SQLite3 $db, bool $force, bool $useTmdb, bool $useLlm): array
{
    $counts = [
        'leftover' => 0,
        'tmdb_series' => 0, 'tmdb_ep' => 0, 'tmdb_movie' => 0,
        'llm_series' => 0, 'llm_video' => 0, 'php_video' => 0,
    ];

    $db->busyTimeout(60000);
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

    $llmOk = $useLlm && mwLlmEnabled() && mwLlmAvailable();

    $counts['leftover'] = mwLinkLeftoverShows($db, $llmOk);

    if ($useTmdb && mwTmdbEnabled())
        $counts = array_merge($counts, mwEnrichTmdb($db, $force, $llmOk, $dbExec));
    elseif (!$useTmdb)
        mwLog('TMDB layer skipped: --no-tmdb.');
    else
        mwLog('TMDB layer skipped: MW_TMDB_TOKEN is empty.');

    if ($llmOk)
        $counts = array_merge($counts, mwEnrichLlm($db, $force, $dbExec));
    elseif (!$useLlm)
        mwLog('LLM display layer skipped: --no-llm.');
    elseif (!mwLlmEnabled())
        mwLog('LLM display layer skipped: MW_LLM_URL is empty.');
    else
        mwLog('LLM display layer skipped: llama-server not reachable at ' . MW_LLM_URL);

    $counts['php_video'] = mwEnrichPhpFallback($db, $dbExec);
    return $counts;
}

function enrichTitlesPrintSummary(array $c): void
{
    mwLog('Title enrich complete.');
    mwLog("  Leftover attached: {$c['leftover']}");
    mwLog("  TMDB named:        series={$c['tmdb_series']} episodes={$c['tmdb_ep']} movies={$c['tmdb_movie']}");
    mwLog("  LLM named:         series={$c['llm_series']} videos={$c['llm_video']}");
    mwLog("  PHP named:         {$c['php_video']}");
}

/**
 * Last resort: unlinked TV-like files → existing series row (unique key or LLM pick).
 * @return int videos attached
 */
function mwLinkLeftoverShows(\SQLite3 $db, bool $llmOk): int
{
    $tok = static function (string $title): array {
        $out = [];
        foreach (preg_split('/\s+/', mwLooseShowKey($title)) ?: [] as $t) {
            if ($t === '' || $t === 'tv' || $t === 'series' || mwIsStripNoise($t)) continue;
            if (preg_match('/^(?:19|20)\d{2}$/', $t)) continue;
            $out[$t] = $t;
        }
        return array_values($out);
    };

    $catalog = [];
    $rs = $db->query('SELECT id, title FROM series ORDER BY id');
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) {
        $t = $tok((string)$row['title']);
        if ($t === []) continue;
        $catalog[] = ['id' => (int)$row['id'], 'title' => (string)$row['title'], 'tok' => $t];
    }
    $rs->finalize();
    if ($catalog === []) return 0;

    $byRoot = [];
    $rv = $db->query(
        'SELECT id, filepath, filename, season FROM videos
         WHERE is_deleted = 0 AND series_id IS NULL'
    );
    while ($row = $rv->fetchArray(SQLITE3_ASSOC)) {
        $fp = (string)$row['filepath'];
        $fn = (string)$row['filename'];
        if (mwTmdbPathIsAtgm($fp)) continue;
        foreach (explode('/', str_replace('\\', '/', $fp)) as $seg) {
            if (isSeriesJunkDir($seg)) continue 2;
        }
        if ($row['season'] === null && !mwFilenameHasEpisodeCode($fn)) continue;
        $root = showRootFromPath($fp);
        if ($root === null) continue;
        $key = $root['root_key'];
        if (!isset($byRoot[$key]))
            $byRoot[$key] = ['top' => $root['top'], 'ids' => [], 'fns' => []];
        $byRoot[$key]['ids'][] = (int)$row['id'];
        if (count($byRoot[$key]['fns']) < 3) $byRoot[$key]['fns'][] = $fn;
    }
    $rv->finalize();
    if ($byRoot === []) return 0;

    $stmt = $db->prepare('UPDATE videos SET series_id = ?, tmdb_id = NULL WHERE id = ?');
    $sys = 'You match a TV folder to one existing library series. '
        . 'Reply with exactly one line: pick: L3  or  pick: none. '
        . 'Pick only a listed L-number. none if none fit. Nothing else.';

    $linked = 0;
    foreach ($byRoot as $info) {
        $top = $info['top'];
        $qTok = $tok(parseShowNameFromFilename($info['fns'][0]) ?? seriesShowTitle($top));
        $hits = [];
        if ($qTok !== []) {
            $need = min(2, count($qTok));
            $qset = array_flip($qTok);
            foreach ($catalog as $c) {
                $n = 0;
                foreach ($c['tok'] as $t) {
                    if (isset($qset[$t])) $n++;
                }
                if ($n < $need) continue;
                $hits[] = $c + ['overlap' => $n];
            }
            usort($hits, static fn($a, $b) => $b['overlap'] <=> $a['overlap']);
            $hits = array_slice($hits, 0, 8);
        }

        $sid = null;
        $via = 'unique name match';
        $title = $hits[0]['title'] ?? '';
        if (count($hits) === 1) {
            $sid = $hits[0]['id'];
        } elseif (count($hits) >= 2 && $llmOk) {
            $lines = [];
            foreach ($hits as $i => $h) {
                $year = mwTmdbYearFromText($h['title']) ?? 'none';
                $lines[] = 'L' . ($i + 1) . " series_id={$h['id']} title={$h['title']} year=$year";
            }
            $user = "Folder: $top\nSamples:\n- " . implode("\n- ", $info['fns'])
                . "\nCandidates:\n" . implode("\n", $lines);
            $out = mwLlmChat($sys, $user);
            $via = 'LLM pick';
            if (is_string($out) && preg_match('/\bpick:\s*(none|L(\d+))\b/i', $out, $m)
                && strtolower($m[1]) !== 'none') {
                $idx = (int)$m[2] - 1;
                if (isset($hits[$idx])) {
                    $sid = $hits[$idx]['id'];
                    $title = $hits[$idx]['title'];
                }
            }
        }

        if ($sid === null) {
            $n = count($hits);
            if ($n >= 2 && $llmOk)
                mwLog("leftover: could not attach folder \"$top\" (LLM picked none of $n candidates)");
            elseif ($n >= 2)
                mwLog("leftover: could not attach folder \"$top\" ($n candidates, LLM disabled)");
            else
                mwLog("leftover: could not attach folder \"$top\" (no matching series)");
            continue;
        }
        mwLog("leftover: attached folder \"$top\" to series #$sid \"$title\" ($via)", MW_LOG_VERBOSE);
        foreach ($info['ids'] as $vid) {
            $stmt->reset();
            $stmt->bindValue(1, $sid, SQLITE3_INTEGER);
            $stmt->bindValue(2, $vid, SQLITE3_INTEGER);
            $stmt->execute();
        }
        $linked += count($info['ids']);
    }
    return $linked;
}

function mwTitleIsReleaseToken(string $t): bool
{
    return mwIsStripNoise($t) || (bool)preg_match('/^\d{3,4}p$/i', $t);
}

function mwTitleLooksDirty(string $name): bool
{
    foreach (preg_split('/\s+/', $name) ?: [] as $p) {
        if ($p !== '' && mwTitleIsReleaseToken($p)) return true;
    }
    return false;
}

/** Named / code-only episode display; null if not applicable. */
function mwTitleEpisodePhp(string $show, ?string $code, string $epTitle, string $fn): ?string
{
    if ($code === null) return null;
    $releaseOnly = $epTitle !== '' && !str_contains($epTitle, ' ') && mwTitleIsReleaseToken($epTitle);
    $namedEp = $epTitle !== '' && !$releaseOnly;
    $codeOnly = $epTitle === '' || $releaseOnly;
    if ($show === '-') {
        $guess = parseShowNameFromFilename($fn);
        if ($guess !== null) $show = $guess;
    }
    if ($show === '-') return null;
    if ($namedEp) return "$show: $epTitle $code";
    if ($codeOnly) return "$show $code";
    return null;
}

function mwTitleLlmSearchSys(): string
{
    return <<<'SYS'
You prepare a TMDB API search for a media library. You will be given a torrent/release
folder or filename and the current DB display title (often polluted with codecs, group tags,
or duplicate words).

Reply with these four fields (one per line preferred; one line also OK). Nothing else:
kind: tv|movie
query: <short clean search string TMDB should receive — show/movie name only, no codec/season/group>
year: <4-digit premiere year if you know it, or none>
why: <one short sentence: what you stripped and what you are searching for>

You are generating search terms for the TMDB search API — not the final display title.
The library will call TMDB with your query (+ year) and use the match's official name.

Rules:
- Prefer tv for series packs; movie for single films.
- query must NOT include SxxExx, season numbers, resolution, codec, or release group.
- Expand DS9/TNG/SGA/SGU/SG-1 to the full show name when that is clearly the show.
- If the folder is junk and you cannot tell what the title is, still best-effort query.
SYS;
}

/** @return array{kind: string, query: string, year: ?int, why: ?string}|null */
function mwTitleParseLlmSearch(string $text): ?array
{
    $text = trim($text);
    $kind = null;
    $query = null;
    $year = null;
    $why = null;
    if (preg_match('/\bkind:\s*(tv|movie)\b/i', $text, $m)) $kind = strtolower($m[1]);
    if (preg_match('/\bquery:\s*(.+?)(?=\s+\byear:|\s+\bwhy:|$)/is', $text, $m))
        $query = trim($m[1], " \t\"'");
    if (preg_match('/\byear:\s*((?:19|20)\d{2})\b/i', $text, $m)) $year = (int)$m[1];
    if (preg_match('/\bwhy:\s*(.+)$/is', $text, $m)) $why = trim($m[1]);
    if ($kind === null || $query === null || $query === '') return null;
    return compact('kind', 'query', 'year', 'why');
}

/**
 * LLM proposes TMDB search terms from path text, then searches.
 * @return array{id: int, name: string, year: ?int}|null
 */
function mwTitleTmdbSearchFromLlm(string $user, string $expectKind): ?array
{
    $raw = mwLlmChat(mwTitleLlmSearchSys(), $user);
    if (!is_string($raw)) return null;
    $parsed = mwTitleParseLlmSearch($raw);
    if ($parsed === null) return null;
    $kind = $parsed['kind'];
    if ($kind !== $expectKind) return null;
    mwLog(
        '  LLM search terms: kind=' . $kind . ' query="' . $parsed['query'] . '" year='
        . ($parsed['year'] ?? 'none')
        . ($parsed['why'] ? " — {$parsed['why']}" : ''),
        MW_LOG_VERBOSE
    );
    return $kind === 'movie'
        ? mwTmdbSearchMovie($parsed['query'], $parsed['year'])
        : mwTmdbSearchTv($parsed['query'], $parsed['year']);
}

/** @param callable(\SQLite3Stmt): bool $dbExec */
function mwEnrichTmdb(\SQLite3 $db, bool $force, bool $llmOk, callable $dbExec): array
{
    $minSecs = mwTmdbMinSecs();
    $seriesResolved = 0;
    $epNamed = 0;
    $movieNamed = 0;

    $stmtSer = $db->prepare(
        'UPDATE series SET tmdb_id = ?, title = ?, genre_ids = ?, tmdb_type = ?,
         vote_average = ?, poster_path = ?, overview = ?, tmdb_refreshed_at = datetime(\'now\'),
         updated_at = datetime(\'now\') WHERE id = ?'
    );
    $stmtSerIdOnly = $db->prepare(
        'UPDATE series SET tmdb_id = ?, title = ?, updated_at = datetime(\'now\') WHERE id = ?'
    );
    $seriesRows = [];
    $rs = $db->query(
        'SELECT id, root_key, title, tmdb_id, tmdb_refreshed_at FROM series ORDER BY id'
    );
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) $seriesRows[] = $row;
    $rs->finalize();

    foreach ($seriesRows as $row) {
        $id = (int)$row['id'];
        $top = basename(str_replace('\\', '/', (string)$row['root_key']));
        $cachedId = $row['tmdb_id'] !== null ? (int)$row['tmdb_id'] : 0;
        if (str_contains(strtolower($top), 'atgm')
            || str_contains(strtolower((string)$row['root_key']), 'atgm')) {
            mwLog("tmdb series #$id \"$top\": skipped (ATGM path)", MW_LOG_VERBOSE);
            continue;
        }
        if ($cachedId > 0) {
            if (!$force && !mwTmdbMetaDue($row, $id)) {
                mwLog("tmdb series #$id \"{$row['title']}\": cached (TMDB id $cachedId, meta not due)", MW_LOG_VERBOSE);
                continue;
            }
            $hit = mwTmdbDetails('tv', $cachedId);
            if ($hit === null) {
                mwLog("tmdb series #$id \"{$row['title']}\": TMDB details failed for cached id $cachedId");
                continue;
            }
            mwTmdbUpsertGenres($db, $hit['genres']);
            mwTmdbUpsertSeriesSeasons($db, $id, $hit['seasons']);
            $csv = mwTmdbGenreIdsCsv($hit['genres']);
            $newTitle = $force
                ? mwTmdbFormatShowTitle($hit['name'], $hit['year'])
                : (string)$row['title'];
            $stmtSer->reset();
            $stmtSer->bindValue(1, $cachedId, SQLITE3_INTEGER);
            $stmtSer->bindValue(2, $newTitle);
            $stmtSer->bindValue(3, $csv !== '' ? $csv : null, $csv !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtSer->bindValue(4, $hit['type'], $hit['type'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtSer->bindValue(5, $hit['vote_average'], $hit['vote_average'] !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
            $stmtSer->bindValue(6, $hit['poster_path'], $hit['poster_path'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtSer->bindValue(7, $hit['overview'], $hit['overview'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtSer->bindValue(8, $id, SQLITE3_INTEGER);
            if (!$dbExec($stmtSer)) {
                mwLog("tmdb series #$id: database locked");
                continue;
            }
            $extra = $hit['vote_average'] !== null ? " score={$hit['vote_average']}" : '';
            if (!$force && $hit['type']) $extra .= " type={$hit['type']}";
            if ($hit['poster_path']) $extra .= ' poster';
            mwLog($force
                ? "tmdb series #$id \"{$row['title']}\" → \"$newTitle\" (refresh id $cachedId$extra)"
                : "tmdb series #$id \"{$row['title']}\": refreshed meta$extra", MW_LOG_VERBOSE);
            $seriesResolved++;
            continue;
        }

        $hit = null;
        $via = 'folder/title';
        if ($llmOk) {
            $hit = mwTitleTmdbSearchFromLlm(
                "Folder: $top\nCurrent DB title: {$row['title']}\nContext: this is a TV series root folder.",
                'tv'
            );
            if ($hit !== null) $via = 'LLM search terms';
        }
        if ($hit === null)
            $hit = mwTmdbSearchTv((string)$row['title'], mwTmdbYearFromText((string)$row['title']));
        if ($hit === null)
            $hit = mwTmdbSearchTv(prettifyFilename($top), mwTmdbYearFromText($top));
        if ($hit === null) {
            mwLog("tmdb series #$id \"{$row['title']}\": no TMDB match (folder \"$top\")");
            continue;
        }
        $detail = mwTmdbDetails('tv', $hit['id']);
        if ($detail === null) {
            $newTitle = mwTmdbFormatShowTitle($hit['name'], $hit['year']);
            $stmtSerIdOnly->reset();
            $stmtSerIdOnly->bindValue(1, $hit['id'], SQLITE3_INTEGER);
            $stmtSerIdOnly->bindValue(2, $newTitle);
            $stmtSerIdOnly->bindValue(3, $id, SQLITE3_INTEGER);
            if (!$dbExec($stmtSerIdOnly)) {
                mwLog("tmdb series #$id: database locked");
                continue;
            }
            mwLog("tmdb series #$id: details failed, stored \"$newTitle\" (id {$hit['id']}, $via)");
            $seriesResolved++;
            continue;
        }
        mwTmdbUpsertGenres($db, $detail['genres']);
        mwTmdbUpsertSeriesSeasons($db, $id, $detail['seasons']);
        $csv = mwTmdbGenreIdsCsv($detail['genres']);
        $newTitle = mwTmdbFormatShowTitle($detail['name'], $detail['year']);
        $stmtSer->reset();
        $stmtSer->bindValue(1, $detail['id'], SQLITE3_INTEGER);
        $stmtSer->bindValue(2, $newTitle);
        $stmtSer->bindValue(3, $csv !== '' ? $csv : null, $csv !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtSer->bindValue(4, $detail['type'], $detail['type'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtSer->bindValue(5, $detail['vote_average'], $detail['vote_average'] !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
        $stmtSer->bindValue(6, $detail['poster_path'], $detail['poster_path'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtSer->bindValue(7, $detail['overview'], $detail['overview'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtSer->bindValue(8, $id, SQLITE3_INTEGER);
        if (!$dbExec($stmtSer)) {
            mwLog("tmdb series #$id: database locked");
            continue;
        }
        mwLog("tmdb series #$id \"{$row['title']}\" → \"$newTitle\" (id {$detail['id']}, $via)", MW_LOG_VERBOSE);
        $seriesResolved++;
    }

    $stmtEp = $db->prepare(
        'UPDATE videos SET name = ?, vote_average = ?, poster_path = ?, overview = ?,
         tmdb_refreshed_at = datetime(\'now\'), updated_at = datetime(\'now\') WHERE id = ?'
    );
    $sqlEp = 'SELECT v.id, v.filepath, v.duration_secs, v.season, v.episode,
                     v.episode_title, v.name, v.tmdb_refreshed_at,
                     s.title AS series_title, s.tmdb_id
              FROM videos v
              JOIN series s ON s.id = v.series_id
              WHERE v.is_deleted = 0
                AND v.season IS NOT NULL AND v.episode IS NOT NULL
                AND s.tmdb_id IS NOT NULL
              ORDER BY v.id';
    $epRows = [];
    $rv = $db->query($sqlEp);
    while ($row = $rv->fetchArray(SQLITE3_ASSOC)) $epRows[] = $row;
    $rv->finalize();

    foreach ($epRows as $row) {
        $id = (int)$row['id'];
        $fp = (string)$row['filepath'];
        $dur = $row['duration_secs'] !== null ? (int)$row['duration_secs'] : 0;
        if ($dur < $minSecs) {
            mwLog("tmdb episode #$id: skipped (duration {$dur}s below minimum {$minSecs}s)", MW_LOG_VERBOSE);
            continue;
        }
        if (mwTmdbPathIsAtgm($fp)) {
            mwLog("tmdb episode #$id: skipped (ATGM path)", MW_LOG_VERBOSE);
            continue;
        }
        if (!$force && str_contains((string)$row['name'], ': ') && !mwTmdbMetaDue($row, $id)) {
            mwLog("tmdb episode #$id: cached (already named, meta not due)", MW_LOG_VERBOSE);
            continue;
        }
        $season = (int)$row['season'];
        $episode = (int)$row['episode'];
        $show = (string)$row['series_title'];
        $code = sprintf('S%02dE%02d', $season, $episode);
        $localEp = trim((string)($row['episode_title'] ?? ''));
        if ($localEp !== '' && mwTitleIsReleaseToken($localEp)) $localEp = '';
        $ep = mwTmdbTvEpisode((int)$row['tmdb_id'], $season, $episode);
        if ($ep !== null) {
            $epName = $ep['name'];
            $via = 'TMDB';
        } elseif ($localEp !== '') {
            $epName = $localEp;
            $via = 'filename';
        } else {
            mwLog("tmdb episode #$id $show $code: TMDB has no episode for show id {$row['tmdb_id']}");
            continue;
        }
        $out = "$show: $epName $code";
        $vote = ($ep !== null) ? ($ep['vote_average'] ?? null) : null;
        $still = ($ep !== null) ? ($ep['still_path'] ?? null) : null;
        $overview = ($ep !== null) ? ($ep['overview'] ?? null) : null;
        $stmtEp->reset();
        $stmtEp->bindValue(1, $out);
        $stmtEp->bindValue(2, $vote, $vote !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
        $stmtEp->bindValue(3, $still, $still !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtEp->bindValue(4, $overview, $overview !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtEp->bindValue(5, $id, SQLITE3_INTEGER);
        if (!$dbExec($stmtEp)) {
            mwLog("tmdb episode #$id: database locked");
            continue;
        }
        $extra = $vote !== null ? " score=$vote" : '';
        $extra .= $still !== null ? ' still' : ' no still';
        mwLog("tmdb episode #$id $show $code → $out ($via$extra)", MW_LOG_VERBOSE);
        $epNamed++;
    }

    $stmtMov = $db->prepare(
        'UPDATE videos SET name = ?, tmdb_id = ?, genre_ids = ?,
         vote_average = ?, poster_path = ?, overview = ?, tmdb_refreshed_at = datetime(\'now\'),
         updated_at = datetime(\'now\') WHERE id = ?'
    );
    $stmtMovIdOnly = $db->prepare(
        'UPDATE videos SET name = ?, tmdb_id = ?, updated_at = datetime(\'now\') WHERE id = ?'
    );
    $sqlMov = 'SELECT id, filepath, filename, title, duration_secs, tmdb_id, name,
                      tmdb_refreshed_at, season
               FROM videos WHERE is_deleted = 0 AND series_id IS NULL ORDER BY id';
    $movRows = [];
    $rm = $db->query($sqlMov);
    while ($row = $rm->fetchArray(SQLITE3_ASSOC)) $movRows[] = $row;
    $rm->finalize();

    foreach ($movRows as $row) {
        $id = (int)$row['id'];
        $fp = (string)$row['filepath'];
        $fn = (string)$row['filename'];
        $dur = $row['duration_secs'] !== null ? (int)$row['duration_secs'] : 0;
        $cachedId = $row['tmdb_id'] !== null ? (int)$row['tmdb_id'] : 0;
        if ($dur < $minSecs) {
            mwLog("tmdb movie #$id: skipped (duration {$dur}s below minimum {$minSecs}s)", MW_LOG_VERBOSE);
            continue;
        }
        if (mwTmdbPathIsAtgm($fp)) {
            mwLog("tmdb movie #$id: skipped (ATGM path)", MW_LOG_VERBOSE);
            continue;
        }
        foreach (explode('/', str_replace('\\', '/', $fp)) as $seg) {
            if (!isSeriesJunkDir($seg)) continue;
            mwLog("tmdb movie #$id \"$fn\": skipped (extras/junk folder)", MW_LOG_VERBOSE);
            continue 2;
        }
        if ($row['season'] !== null || mwFilenameHasEpisodeCode($fn)) {
            mwLog("tmdb movie #$id \"$fn\": skipped (looks like a TV episode, not series-linked)", MW_LOG_VERBOSE);
            continue;
        }
        if ($cachedId > 0) {
            $name = (string)$row['name'];
            $needName = $name === ''
                || mwTitleLooksDirty($name)
                || !preg_match('/\((?:19|20)\d{2}\)\s*$/', $name);
            if (!$force && !mwTmdbMetaDue($row, $id) && !$needName) {
                mwLog("tmdb movie #$id: cached (TMDB id $cachedId, meta not due)", MW_LOG_VERBOSE);
                continue;
            }
            $hit = mwTmdbDetails('movie', $cachedId);
            if ($hit === null) {
                mwLog("tmdb movie #$id: TMDB details failed for cached id $cachedId");
                continue;
            }
            mwTmdbUpsertGenres($db, $hit['genres']);
            $csv = mwTmdbGenreIdsCsv($hit['genres']);
            $out = mwTmdbFormatShowTitle($hit['name'], $hit['year']);
            $stmtMov->reset();
            $stmtMov->bindValue(1, $out);
            $stmtMov->bindValue(2, $cachedId, SQLITE3_INTEGER);
            $stmtMov->bindValue(3, $csv !== '' ? $csv : null, $csv !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtMov->bindValue(4, $hit['vote_average'], $hit['vote_average'] !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
            $stmtMov->bindValue(5, $hit['poster_path'], $hit['poster_path'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtMov->bindValue(6, $hit['overview'], $hit['overview'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmtMov->bindValue(7, $id, SQLITE3_INTEGER);
            if (!$dbExec($stmtMov)) {
                mwLog("tmdb movie #$id: database locked");
                continue;
            }
            $extra = $hit['vote_average'] !== null ? " score={$hit['vote_average']}" : '';
            if ($hit['poster_path']) $extra .= ' poster';
            mwLog("tmdb movie #$id: \"$out\" (refresh id $cachedId$extra)", MW_LOG_VERBOSE);
            $movieNamed++;
            continue;
        }

        $heur = videoPrettyTitle($fn, $row['title'], $fp);
        $hit = null;
        $via = 'filename hint';
        if ($llmOk) {
            $hit = mwTitleTmdbSearchFromLlm(
                "Folder/file display hint: $heur\nFilename: $fn\nContext: this is a movie (not a TV series pack).",
                'movie'
            );
            if ($hit !== null) $via = 'LLM search terms';
        }
        if ($hit === null)
            $hit = mwTmdbSearchMovie($heur, mwTmdbYearFromText($heur) ?? mwTmdbYearFromText($fn));
        if ($hit === null) {
            mwLog("tmdb movie #$id: no TMDB match for \"$heur\"");
            continue;
        }
        $detail = mwTmdbDetails('movie', $hit['id']);
        if ($detail === null) {
            $out = mwTmdbFormatShowTitle($hit['name'], $hit['year']);
            $stmtMovIdOnly->reset();
            $stmtMovIdOnly->bindValue(1, $out);
            $stmtMovIdOnly->bindValue(2, $hit['id'], SQLITE3_INTEGER);
            $stmtMovIdOnly->bindValue(3, $id, SQLITE3_INTEGER);
            if (!$dbExec($stmtMovIdOnly)) {
                mwLog("tmdb movie #$id: database locked");
                continue;
            }
            mwLog("tmdb movie #$id: details failed, stored \"$out\" (id {$hit['id']}, $via)");
            $movieNamed++;
            continue;
        }
        mwTmdbUpsertGenres($db, $detail['genres']);
        $csv = mwTmdbGenreIdsCsv($detail['genres']);
        $out = mwTmdbFormatShowTitle($detail['name'], $detail['year']);
        $stmtMov->reset();
        $stmtMov->bindValue(1, $out);
        $stmtMov->bindValue(2, $detail['id'], SQLITE3_INTEGER);
        $stmtMov->bindValue(3, $csv !== '' ? $csv : null, $csv !== '' ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtMov->bindValue(4, $detail['vote_average'], $detail['vote_average'] !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
        $stmtMov->bindValue(5, $detail['poster_path'], $detail['poster_path'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtMov->bindValue(6, $detail['overview'], $detail['overview'] !== null ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmtMov->bindValue(7, $id, SQLITE3_INTEGER);
        if (!$dbExec($stmtMov)) {
            mwLog("tmdb movie #$id: database locked");
            continue;
        }
        $extra = $detail['vote_average'] !== null ? " score={$detail['vote_average']}" : '';
        if ($detail['poster_path']) $extra .= ' poster';
        mwLog("tmdb movie #$id \"$heur\" → \"$out\" (id {$detail['id']}, $via$extra)", MW_LOG_VERBOSE);
        $movieNamed++;
    }

    return [
        'tmdb_series' => $seriesResolved,
        'tmdb_ep' => $epNamed,
        'tmdb_movie' => $movieNamed,
    ];
}

/** @param callable(\SQLite3Stmt): bool $dbExec */
function mwEnrichLlm(\SQLite3 $db, bool $force, callable $dbExec): array
{
    $sysSeries = 'You output canonical TV show display titles for a media library. '
        . 'Reply with exactly one line: {Show Name} (YYYY) '
        . 'YYYY = original first-air / premiere year of the series (not a season, not a rip). '
        . 'Rules: '
        . '- Expand folder codes: DS9→Star Trek: Deep Space Nine, TNG→Star Trek: The Next Generation, '
        . 'SGA→Stargate Atlantis, SGU→Stargate Universe, SG-1 stays Stargate SG-1. '
        . '- Keep the fullest recognizable show name from Folder/Current title '
        . '(e.g. Mayday: Air Crash Investigation — not Mayday alone; Black Files: Declassified when present). '
        . '- Prefer a 19xx/20xx year found in the folder when it is clearly the show year. '
        . '- Otherwise use the standard premiere year you know for that show. '
        . '- Keep a year already present in Current title. '
        . '- Strip quality/codec/group/season pack junk only. '
        . '- Never reply without (YYYY).';
    $groups = implode(', ', array_keys(mwStripLists()['group']));
    $sysVideo = 'Using ONLY words from the user message (file, hint, show), output one display title. '
        . 'Never invent words. Never reuse titles from other videos. '
        . 'Never echo field labels (file/hint/show) in the reply. '
        . 'Keep abbreviations as written (Vol stays Vol, not Volume). '
        . "Tags like KORSUB, HDRip, XviD, and release groups ($groups) are NOT titles — ignore them. "
        . 'Rules: '
        . '(1) If hint or file has a real episode name (not just SxxExx / epNN / a release group), '
        . 'output: {show}: {EpisodeName} {SxxExx}. '
        . '(2) If there is only SxxExx (or SxxExx plus a release group), output: {show} {SxxExx}. '
        . 'If show is missing, take the show name from the file (words before SxxExx). '
        . '(3) For movies, keep the year in parentheses when known, e.g. Title (1993). '
        . 'Always use parentheses around the year: {Title} (YYYY) — never Title YYYY. '
        . '(4) Do not repeat the episode code. Keep the full episode name — do not truncate. '
        . 'Prefer a best-effort title over SKIP. Reply SKIP only if there are truly no title words.';

    $titleGrounded = static function (string $reply, string $user): bool {
        $tok = static function (string $s): array {
            $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? '');
            $parts = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
        $kept = [];
        foreach (preg_split('/\s+/', trim($heur)) ?: [] as $p) {
            if ($p === '' || mwIsStripNoise($p) || preg_match('/^\d{3,4}p$/i', $p)) continue;
            $kept[] = $p;
        }
        $h = implode(' ', $kept);
        if (preg_match('/^(.*?)(?:\s+)((?:19|20)\d{2})$/', $h, $m))
            return trim($m[1]) . ' (' . $m[2] . ')';
        return $h;
    };

    // Gap-fill only: never overwrite TMDB-backed series titles
    $seriesUpdated = 0;
    $seriesRows = [];
    $rs = $db->query(
        'SELECT id, root_key, title FROM series
         WHERE tmdb_id IS NULL
         ORDER BY id'
    );
    while ($row = $rs->fetchArray(SQLITE3_ASSOC)) $seriesRows[] = $row;
    $rs->finalize();
    $stmtSer = $db->prepare('UPDATE series SET title = ?, updated_at = datetime(\'now\') WHERE id = ?');
    foreach ($seriesRows as $row) {
        $title = (string)$row['title'];
        if (!$force && preg_match('/\((?:19|20)\d{2}\)\s*$/', $title)) continue;

        $top = basename(str_replace('\\', '/', (string)$row['root_key']));
        $user = "Folder: $top\nCurrent title: $title";
        $out = mwLlmChat($sysSeries, $user);
        if ($out !== null && !preg_match('/\((?:19|20)\d{2}\)\s*$/', $out)) {
            $retry = mwLlmChat($sysSeries, $user . "\nReminder: your reply must end with (YYYY).");
            if ($retry !== null) $out = $retry;
        }
        if ($out === null) {
            mwLog("llm series #{$row['id']} \"$title\": LLM returned no title (folder \"$top\")");
            continue;
        }
        $stmtSer->reset();
        $stmtSer->bindValue(1, $out);
        $stmtSer->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
        if (!$dbExec($stmtSer)) {
            mwLog("llm series #{$row['id']}: database locked");
            continue;
        }
        mwLog("llm series #{$row['id']} \"$title\" → \"$out\" (folder \"$top\")", MW_LOG_VERBOSE);
        $seriesUpdated++;
    }

    $videoNamed = 0;
    $sql = 'SELECT v.id, v.filepath, v.filename, v.title, v.season, v.episode,
                   v.episode_title, s.title AS series_title
            FROM videos v
            LEFT JOIN series s ON s.id = v.series_id
            WHERE v.is_deleted = 0 AND (v.name IS NULL OR v.name = \'\')
            ORDER BY v.id';
    $videoRows = [];
    $rv = $db->query($sql);
    while ($row = $rv->fetchArray(SQLITE3_ASSOC)) $videoRows[] = $row;
    $rv->finalize();
    $stmtName = $db->prepare('UPDATE videos SET name = ?, updated_at = datetime(\'now\') WHERE id = ?');

    $jobs = [];
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
        $epTitle = trim((string)($row['episode_title'] ?? ''));
        if ($epTitle === '' && $code !== null
            && preg_match('/^S\d{2}E\d{2}\s*[·\-–]\s*(.+)$/u', trim($heur), $m))
            $epTitle = trim($m[1]);

        $phpOut = mwTitleEpisodePhp($show, $code, $epTitle, $fn);
        if ($phpOut !== null) {
            $stmtName->reset();
            $stmtName->bindValue(1, $phpOut);
            $stmtName->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
            if (!$dbExec($stmtName)) {
                mwLog("llm video #{$row['id']}: database locked");
                continue;
            }
            mwLog("llm video #{$row['id']}: named \"$phpOut\" (PHP)", MW_LOG_VERBOSE);
            $videoNamed++;
            continue;
        }

        $user = "file: $rel\nhint: $heur";
        if ($show !== '-') $user .= "\nshow: $show";
        $jobs[] = [
            'id' => (int)$row['id'],
            'fn' => $fn,
            'heur' => $heur,
            'show' => $show,
            'code' => $code,
            'user' => $user,
        ];
    }

    $fallbackTitle = static function (
        string $show,
        ?string $code,
        string $fn,
        string $heur
    ) use ($movieTitleFromHint): ?string {
        if ($show !== '-' && $code !== null) return "$show $code";
        if ($code !== null && ($guess = parseShowNameFromFilename($fn))) return "$guess $code";
        if (preg_match('/[a-z]{2,}/i', $heur) && !preg_match('/^S\d{2}E\d{2}\b/i', trim($heur)))
            return $movieTitleFromHint($heur);
        return null;
    };

    if ($jobs !== []) {
        $total = count($jobs);
        $parallel = mwLlmParallel();
        mwLog("LLM: naming $total videos (parallel=$parallel)");
        flush();
        $done = 0;
        foreach (array_chunk($jobs, $parallel) as $chunk) {
            $outs = mwLlmChatMany($sysVideo, array_column($chunk, 'user'));
            foreach ($chunk as $j => $job) {
                $id = $job['id'];
                $show = $job['show'];
                $code = $job['code'];
                $heur = $job['heur'];
                $user = $job['user'];
                $out = $outs[$j] ?? null;

                if ($out !== null && strcasecmp($out, 'SKIP') !== 0 && $titleEchoesLabel($out)) {
                    mwLog("llm video #$id: LLM echoed field labels (hint: $heur)");
                    continue;
                }
                if ($out !== null && strcasecmp($out, 'SKIP') === 0)
                    $out = $fallbackTitle($show, $code, $job['fn'], $heur);
                if ($out !== null && !$titleGrounded($out, $user))
                    $out = $fallbackTitle($show, $code, $job['fn'], $heur);
                if ($out !== null && preg_match('/:\s*(ep|e|episode)\s*\d+\s+s\d{1,2}e\d{1,2}\s*$/i', $out)
                    && preg_match('/^(.*?)\s+s(\d{1,2})e(\d{1,2})\s*$/i', $out, $m)) {
                    $showOnly = trim(preg_replace('/:\s*(ep|e|episode)\s*\d+\s*$/i', '', $m[1]) ?? $m[1]);
                    $out = sprintf('%s S%02dE%02d', $showOnly, (int)$m[2], (int)$m[3]);
                }
                if ($out !== null && $code !== null && $show !== '-'
                    && !preg_match('/S\d{1,2}E\d{1,2}\s*$/i', $out)) {
                    $out = rtrim($out) . " $code";
                }
                if ($out === null) {
                    mwLog("llm video #$id: no title (hint: $heur)");
                    continue;
                }
                $stmtName->reset();
                $stmtName->bindValue(1, $out);
                $stmtName->bindValue(2, $id, SQLITE3_INTEGER);
                if (!$dbExec($stmtName)) {
                    mwLog("llm video #$id: database locked");
                    continue;
                }
                mwLog("llm video #$id: named \"$out\" (LLM) hint=\"$heur\"", MW_LOG_VERBOSE);
                $videoNamed++;
            }
            $done += count($chunk);
            mwLog("LLM video progress: $done/$total", MW_LOG_VERBOSE);
            flush();
        }
    }

    return ['llm_series' => $seriesUpdated, 'llm_video' => $videoNamed];
}

/** @param callable(\SQLite3Stmt): bool $dbExec */
function mwEnrichPhpFallback(\SQLite3 $db, callable $dbExec): int
{
    $sql = 'SELECT v.id, v.filepath, v.filename, v.title, v.season, v.episode,
                   v.episode_title, s.title AS series_title
            FROM videos v
            LEFT JOIN series s ON s.id = v.series_id
            WHERE v.is_deleted = 0 AND (v.name IS NULL OR v.name = \'\')
            ORDER BY v.id';
    $rows = [];
    $rv = $db->query($sql);
    while ($row = $rv->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
    $rv->finalize();
    if ($rows === []) return 0;

    $stmt = $db->prepare('UPDATE videos SET name = ?, updated_at = datetime(\'now\') WHERE id = ?');
    $named = 0;
    foreach ($rows as $row) {
        $fn = (string)$row['filename'];
        $fp = (string)$row['filepath'];
        $season = $row['season'] !== null ? (int)$row['season'] : null;
        $episode = $row['episode'] !== null ? (int)$row['episode'] : null;
        $show = !empty($row['series_title']) ? (string)$row['series_title'] : '-';
        $code = ($season !== null && $episode !== null)
            ? sprintf('S%02dE%02d', $season, $episode) : null;
        $out = mwTitleEpisodePhp($show, $code, trim((string)($row['episode_title'] ?? '')), $fn);
        if ($out === null) {
            $out = videoPrettyTitle($fn, $row['title'], $fp);
            if ($out === '' || $out === $fn) $out = null;
        }
        if ($out === null) {
            mwLog("php video #{$row['id']}: no title from filename", MW_LOG_VERBOSE);
            continue;
        }
        $stmt->reset();
        $stmt->bindValue(1, $out);
        $stmt->bindValue(2, (int)$row['id'], SQLITE3_INTEGER);
        if (!$dbExec($stmt)) {
            mwLog("php video #{$row['id']}: database locked");
            continue;
        }
        mwLog("php video #{$row['id']}: named \"$out\" from filename", MW_LOG_VERBOSE);
        $named++;
    }
    return $named;
}
