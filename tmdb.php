<?php

/**
 * Optional TMDB API client for display-title enrichment.
 * Disabled when MW_TMDB_TOKEN is empty. CLI only — never call from Apache.
 */

declare(strict_types=1);

function mwTmdbEnabled(): bool
{
    return defined('MW_TMDB_TOKEN') && MW_TMDB_TOKEN !== '';
}

function mwTmdbMinSecs(): int
{
    return defined('MW_TMDB_MIN_SECS') ? max(0, (int)MW_TMDB_MIN_SECS) : 600;
}

/** Base days ± hash jitter for row id (−jitter…+jitter). */
function mwTmdbRefreshIntervalDays(int $id): int
{
    $days = defined('MW_TMDB_REFRESH_DAYS') ? max(1, (int)MW_TMDB_REFRESH_DAYS) : 7;
    $j = defined('MW_TMDB_REFRESH_JITTER') ? max(0, (int)MW_TMDB_REFRESH_JITTER) : 3;
    if ($j <= 0) return $days;
    $span = 2 * $j + 1;
    return $days + ((int)(($id * 2654435761) % $span) - $j);
}

/** Due for details refresh when tmdb_id is set. */
function mwTmdbMetaDue(array $row, int $id): bool
{
    $stamp = $row['tmdb_refreshed_at'] ?? null;
    if ($stamp === null || $stamp === '') return true;
    $ref = strtotime((string)$stamp);
    if ($ref === false) return true;
    return ((time() - $ref) / 86400.0) >= mwTmdbRefreshIntervalDays($id);
}

/** @return array<string, mixed>|null */
function mwTmdbGet(string $path, array $query = []): ?array
{
    if (!mwTmdbEnabled()) return null;
    $url = 'https://api.themoviedb.org/3/' . ltrim($path, '/');
    if ($query !== []) $url .= '?' . http_build_query($query);

    for ($attempt = 0; $attempt < 4; $attempt++) {
        $retryAfter = null;
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . MW_TMDB_TOKEN,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m))
                    $retryAfter = (int)$m[1];
                return strlen($line);
            },
        ];
        // Windows PHP builds often ship without a CA bundle (curl errno 60).
        if (PHP_OS_FAMILY === 'Windows')
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || !is_string($body)) return null;
        if ($http === 429) {
            sleep(min(30, $retryAfter ?? (1 + $attempt)));
            continue;
        }
        if ($http !== 200) return null;
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }
    return null;
}

function mwTmdbNorm(string $s): string
{
    $s = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $s) ?? '');
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/** True when local parse and TMDB episode name are the same title (allow TMDB spelling). */
function mwTmdbTitlesAgree(string $a, string $b): bool
{
    $na = mwTmdbNorm($a);
    $nb = mwTmdbNorm($b);
    if ($na === '' || $nb === '') return false;
    if ($na === $nb) return true;
    if (str_contains($na, $nb) || str_contains($nb, $na)) return true;
    $ta = array_flip(preg_split('/\s+/', $na, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $tb = preg_split('/\s+/', $nb, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($tb === []) return false;
    $hit = 0;
    foreach ($tb as $t) {
        if (isset($ta[$t])) $hit++;
    }
    return ($hit / count($tb)) >= 0.6 && ($hit / max(1, count($ta))) >= 0.6;
}

/** @return array{0: string, 1: ?int} */
function mwTmdbSplitYear(string $title): array
{
    if (preg_match('/^(.*?)\s*\(((?:19|20)\d{2})\)\s*$/', trim($title), $m))
        return [trim($m[1]), (int)$m[2]];
    return [trim($title), null];
}

function mwTmdbYearFromText(string $s): ?int
{
    if (preg_match('/\(((?:19|20)\d{2})\)/', $s, $m)) return (int)$m[1];
    if (preg_match('/(?:^|[.\s_-])((?:19|20)\d{2})(?:[.\s_-]|$)/', $s, $m)) return (int)$m[1];
    return null;
}

/**
 * @param list<array<string, mixed>> $results
 * @return array<string, mixed>|null
 */
function mwTmdbPickResult(array $results, string $query, ?int $yearHint, string $titleKey, string $dateKey): ?array
{
    if ($results === []) return null;
    $q = mwTmdbNorm($query);
    if ($q === '') return null;

    $best = null;
    $bestScore = -1;
    foreach ($results as $r) {
        if (!is_array($r)) continue;
        $n = mwTmdbNorm((string)($r[$titleKey] ?? ''));
        if ($n === '') continue;
        $score = 0;
        if ($n === $q) $score += 100;
        elseif (str_starts_with($n, $q) || str_starts_with($q, $n)) $score += 60;
        elseif (str_contains($n, $q) || str_contains($q, $n)) $score += 30;
        else continue;

        $date = (string)($r[$dateKey] ?? '');
        $ry = preg_match('/^((?:19|20)\d{2})/', $date, $m) ? (int)$m[1] : null;
        if ($yearHint !== null && $ry === $yearHint) $score += 40;
        elseif ($yearHint !== null && $ry !== null && abs($ry - $yearHint) <= 1) $score += 15;
        $score += min(20, (int)(((float)($r['popularity'] ?? 0)) / 10));

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $r;
        }
    }
    return ($best !== null && $bestScore >= 30) ? $best : null;
}

/**
 * @return array{id: int, name: string, year: ?int}|null
 */
function mwTmdbSearch(string $kind, string $query, ?int $year = null): ?array
{
    [$q, $yFromQ] = mwTmdbSplitYear($query);
    $yearHint = $year ?? $yFromQ;
    $titleKey = $kind === 'movie' ? 'title' : 'name';
    $dateKey = $kind === 'movie' ? 'release_date' : 'first_air_date';
    $yearParam = $kind === 'movie' ? 'year' : 'first_air_date_year';

    $params = ['query' => $q, 'include_adult' => 'false'];
    if ($yearHint !== null) $params[$yearParam] = $yearHint;
    $j = mwTmdbGet("search/$kind", $params);
    if ($j === null) return null;
    $hit = mwTmdbPickResult($j['results'] ?? [], $q, $yearHint, $titleKey, $dateKey);
    if ($hit === null && isset($params[$yearParam])) {
        unset($params[$yearParam]);
        $j = mwTmdbGet("search/$kind", $params);
        if ($j === null) return null;
        $hit = mwTmdbPickResult($j['results'] ?? [], $q, $yearHint, $titleKey, $dateKey);
    }
    if ($hit === null || empty($hit['id'])) return null;
    $date = (string)($hit[$dateKey] ?? '');
    $ry = preg_match('/^((?:19|20)\d{2})/', $date, $m) ? (int)$m[1] : null;
    return [
        'id' => (int)$hit['id'],
        'name' => (string)$hit[$titleKey],
        'year' => $ry,
    ];
}

/** @return array{id: int, name: string, year: ?int}|null */
function mwTmdbSearchTv(string $query, ?int $year = null): ?array
{
    return mwTmdbSearch('tv', $query, $year);
}

/** @return array{id: int, name: string, year: ?int}|null */
function mwTmdbSearchMovie(string $query, ?int $year = null): ?array
{
    return mwTmdbSearch('movie', $query, $year);
}

/** @return array{id: int, name: string, year: ?int, genres: list<array{id: int, name: string}>, type: ?string, vote_average: ?float, poster_path: ?string}|null */
function mwTmdbDetails(string $kind, int $id): ?array
{
    $j = mwTmdbGet("$kind/$id");
    if ($j === null) return null;
    $titleKey = $kind === 'movie' ? 'title' : 'name';
    $dateKey = $kind === 'movie' ? 'release_date' : 'first_air_date';
    $name = trim((string)($j[$titleKey] ?? ''));
    if ($name === '') return null;
    $date = (string)($j[$dateKey] ?? '');
    $year = preg_match('/^((?:19|20)\d{2})/', $date, $m) ? (int)$m[1] : null;
    $genres = [];
    foreach ($j['genres'] ?? [] as $g) {
        if (!is_array($g) || empty($g['id'])) continue;
        $gn = trim((string)($g['name'] ?? ''));
        if ($gn === '') continue;
        $genres[] = ['id' => (int)$g['id'], 'name' => $gn];
    }
    $type = null;
    if ($kind === 'tv') {
        $t = trim((string)($j['type'] ?? ''));
        if ($t !== '') $type = $t;
    }
    $vote = isset($j['vote_average']) && is_numeric($j['vote_average'])
        ? round((float)$j['vote_average'], 1) : null;
    $poster = trim((string)($j['poster_path'] ?? ''));
    return [
        'id' => $id,
        'name' => $name,
        'year' => $year,
        'genres' => $genres,
        'type' => $type,
        'vote_average' => $vote,
        'poster_path' => $poster !== '' ? $poster : null,
    ];
}

/** @param list<array{id: int, name: string}> $genres */
function mwTmdbGenreIdsCsv(array $genres): string
{
    $ids = [];
    foreach ($genres as $g) {
        $id = (int)($g['id'] ?? 0);
        if ($id > 0) $ids[] = $id;
    }
    return implode(',', $ids);
}

/** @param list<array{id: int, name: string}> $genres */
function mwTmdbUpsertGenres(\SQLite3 $db, array $genres): void
{
    if ($genres === []) return;
    $st = $db->prepare('INSERT OR IGNORE INTO genres (id, name) VALUES (?, ?)');
    foreach ($genres as $g) {
        $id = (int)($g['id'] ?? 0);
        $name = trim((string)($g['name'] ?? ''));
        if ($id <= 0 || $name === '') continue;
        $st->reset();
        $st->bindValue(1, $id, SQLITE3_INTEGER);
        $st->bindValue(2, $name);
        $st->execute();
    }
}

/** @return array{name: string}|null */
function mwTmdbTvEpisode(int $tvId, int $season, int $episode): ?array
{
    $j = mwTmdbGet("tv/$tvId/season/$season/episode/$episode");
    if ($j === null) return null;
    $name = trim((string)($j['name'] ?? ''));
    if ($name === '') return null;
    return ['name' => $name];
}

function mwTmdbFormatShowTitle(string $name, ?int $year): string
{
    return $year !== null ? "$name ($year)" : $name;
}

function mwTmdbPathIsAtgm(string $filepath): bool
{
    $rel = mediaRelPath($filepath) ?? $filepath;
    return str_contains(strtolower(str_replace('\\', '/', $rel)), 'atgm');
}
