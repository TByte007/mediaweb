<?php

/**
 * Tokens stripped from titles: quality/codec, edition flags, release groups.
 *
 * Optional strip-lists.local.php (gitignored) merges extras:
 *   return ['quality' => [...], 'edition' => [...], 'group' => [...]];
 */

declare(strict_types=1);

/** @return array{quality: array<string, int>, edition: array<string, int>, group: array<string, int>} */
function mwStripLists(): array
{
    static $lists = null;
    if ($lists !== null) return $lists;

    $raw = [
        'quality' => [
            'korsub', 'hdrip', 'bluray', 'blu-ray', 'webrip', 'webdl', 'web-dl',
            'hdtv', 'pdtv', 'dvdrip', 'bdrip', 'brrip', 'dsrip', 'dvdscr', 'remux',
            'xvid', 'divx', 'x264', 'x265', 'h264', 'h265', 'hevc', 'avc',
            'aac', 'ac3', 'dts', 'mp3', 'eac3', 'truehd', 'atmos', 'dd5', 'ddp',
            'ntsc', 'pal', 'sdc', 'web',
        ],
        'edition' => [
            'unrated', 'internal', 'proper', 'repack', 'limited', 'extended',
            'retail', 'complete', 'dual', 'multi', 'subbed', 'dubbed',
            'readnfo', 'nfo', 'scene',
            'netflix', 'amazon', 'amzn', 'hulu',
        ],
        'group' => [
            '2hd', 'evo', 'vain', 'rarbg', 'yify', 'threesixtyp', 'killers',
            'lol', 'afg', 'fov', 'sva',
        ],
    ];

    $local = __DIR__ . '/strip-lists.local.php';
    if (is_file($local)) {
        $extra = include $local;
        if (is_array($extra)) {
            foreach ($raw as $k => $_) {
                if (!empty($extra[$k]) && is_array($extra[$k]))
                    $raw[$k] = array_merge($raw[$k], $extra[$k]);
            }
        }
    }

    $lists = [];
    foreach ($raw as $k => $arr) {
        $m = [];
        foreach ($arr as $t) {
            $t = strtolower(trim((string)$t));
            if ($t !== '') $m[$t] = 1;
        }
        $lists[$k] = $m;
    }
    return $lists;
}

function mwIsStripNoise(string $t): bool
{
    static $all = null;
    if ($all === null) {
        $l = mwStripLists();
        $all = $l['quality'] + $l['edition'] + $l['group'];
    }
    return isset($all[strtolower($t)]);
}
