<?php

/**
 * Sidecar subtitles: discover next to a video, convert to UTF-8 WebVTT.
 */

declare(strict_types=1);

/** @return list<array{path: string, lang: string, label: string, default: bool}> */
function findSidecarSubs(string $videoPath): array
{
    $videoPath = str_replace('\\', '/', $videoPath);
    $dir = dirname($videoPath);
    $dirReal = realpath($dir);
    if ($dirReal === false) return [];
    $dirReal = str_replace('\\', '/', $dirReal);

    $videoExt = ['mkv' => 1, 'mp4' => 1, 'avi' => 1, 'mov' => 1, 'webm' => 1, 'wmv' => 1, 'flv' => 1, 'm4v' => 1];
    $subExt = ['srt' => 1, 'vtt' => 1, 'sub' => 1];
    $sidecars = [];
    $childDirs = [];
    $videoCount = 0;

    $h = @opendir($dir);
    if (!$h) return [];
    while (($e = readdir($h)) !== false) {
        if ($e === '.' || $e === '..') continue;
        $full = $dir . '/' . $e;
        if (is_dir($full)) {
            if (subIsChildDir($e)) $childDirs[] = $full;
            continue;
        }
        if (!is_file($full)) continue;
        $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
        if (isset($videoExt[$ext])) $videoCount++;
        elseif (isset($subExt[$ext])) $sidecars[] = $full;
    }
    closedir($h);

    foreach ($childDirs as $cd) {
        $ch = @opendir($cd);
        if (!$ch) continue;
        while (($e = readdir($ch)) !== false) {
            if ($e === '.' || $e === '..') continue;
            $ext = strtolower(pathinfo($e, PATHINFO_EXTENSION));
            if (!isset($subExt[$ext])) continue;
            $full = $cd . '/' . $e;
            if (is_file($full)) $sidecars[] = $full;
        }
        closedir($ch);
    }

    $videoStem = strtolower((string)pathinfo($videoPath, PATHINFO_FILENAME));
    $videoEp = subEpKey(basename($videoPath));
    $matched = [];

    foreach ($sidecars as $path) {
        $real = realpath($path);
        if ($real === false) continue;
        $real = str_replace('\\', '/', $real);
        if (!str_starts_with($real, $dirReal . '/')) continue;
        $rel = substr($real, strlen($dirReal) + 1);
        if (substr_count($rel, '/') > 1) continue;

        $stem = strtolower((string)pathinfo($real, PATHINFO_FILENAME));
        $rank = null;
        if ($stem === $videoStem) $rank = 0;
        elseif ($videoEp !== null && subEpKey(basename($real)) === $videoEp) $rank = 1;
        elseif ($videoEp === null && $videoCount === 1) $rank = 2;
        if ($rank === null) continue;

        $base = (string)pathinfo($real, PATHINFO_FILENAME);
        $cvetni = preg_match('/цветни/ui', $base) === 1;
        $peek = @file_get_contents($real, false, null, 0, 8192);
        $text = is_string($peek) ? subDecodeBytes($peek) : '';
        $bg = preg_match('/\p{Cyrillic}/u', $text) === 1
            || preg_match('/(?:^|[._-])(?:bg|bul|bulgarian)(?:[._-]|$)/i', $base) === 1;
        $matched[$real] = [
            'path' => $real,
            'rank' => $rank,
            'cvetni' => $cvetni,
            'lang' => $bg ? 'bg' : 'und',
            'label' => $bg ? ('Български' . ($cvetni ? ' (цветни)' : '')) : 'Subtitles',
        ];
    }

    $tracks = array_values($matched);
    usort($tracks, static function (array $a, array $b): int {
        if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
        if ($a['cvetni'] !== $b['cvetni']) return (int)$a['cvetni'] <=> (int)$b['cvetni'];
        return $a['path'] <=> $b['path'];
    });

    $plain = $anyBg = null;
    foreach ($tracks as $i => $t) {
        if ($t['lang'] !== 'bg') continue;
        $anyBg ??= $i;
        if (!$t['cvetni']) {
            $plain = $i;
            break;
        }
    }
    $def = $plain ?? $anyBg ?? 0;

    $out = [];
    foreach ($tracks as $i => $t) {
        $out[] = [
            'path' => $t['path'],
            'lang' => $t['lang'],
            'label' => $t['label'],
            'default' => $i === $def,
        ];
    }
    return $out;
}

function sidecarToVtt(string $path, float $fps = 0.0): string
{
    $bytes = @file_get_contents($path);
    if ($bytes === false || $bytes === '') return '';
    $text = str_replace(["\r\n", "\r"], "\n", subDecodeBytes($bytes));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'vtt') {
        if (!str_starts_with(ltrim($text), 'WEBVTT'))
            $text = "WEBVTT\n\n" . ltrim($text);
        return $text;
    }
    if ($ext === 'sub') return subMicrodvdToVtt($text, $fps);
    return subSrtToVtt($text);
}

function subIsChildDir(string $name): bool
{
    $l = strtolower($name);
    return $l === 'subs' || $l === 'subtitle' || $l === 'subtitles'
        || str_contains($l, 'subsunacs');
}

function subEpKey(string $name): ?string
{
    return preg_match('/s(\d{1,2})e(\d{1,2})/i', $name, $m) ? (int)$m[1] . 'x' . (int)$m[2] : null;
}

function subDecodeBytes(string $bytes): string
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) $bytes = substr($bytes, 3);
    $utfOk = @iconv('UTF-8', 'UTF-8', $bytes) !== false;
    if ($utfOk && preg_match('/\p{Cyrillic}/u', $bytes) === 1) return $bytes;
    $cp = @iconv('CP1251', 'UTF-8//IGNORE', $bytes);
    if (is_string($cp) && preg_match('/\p{Cyrillic}/u', $cp) === 1) return $cp;
    if ($utfOk) return $bytes;
    return is_string($cp) ? $cp : $bytes;
}

function subSrtToVtt(string $text): string
{
    $pad = static fn(string $h, string $mm, string $s, string $ms): string
        => str_pad($h, 2, '0', STR_PAD_LEFT) . ":$mm:$s." . str_pad($ms, 3, '0');
    $out = ['WEBVTT', ''];
    foreach (preg_split("/\n{2,}/", trim($text)) as $block) {
        $lines = explode("\n", $block);
        if ($lines !== [] && preg_match('/^\d+$/', trim($lines[0]))) array_shift($lines);
        if ($lines === [] || !preg_match(
            '/^(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})\s*-->\s*(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})(.*)$/',
            trim($lines[0]),
            $m
        )) continue;
        array_shift($lines);
        $out[] = $pad($m[1], $m[2], $m[3], $m[4]) . ' --> ' . $pad($m[5], $m[6], $m[7], $m[8]) . $m[9];
        foreach ($lines as $l) $out[] = (string)preg_replace('/<\/?font\b[^>]*>/i', '', $l);
        $out[] = '';
    }
    return implode("\n", $out);
}

function subVttTime(float $secs): string
{
    $ms = (int)round($secs * 1000);
    $h = intdiv($ms, 3_600_000);
    $ms %= 3_600_000;
    $m = intdiv($ms, 60_000);
    $ms %= 60_000;
    $s = intdiv($ms, 1000);
    return sprintf('%02d:%02d:%02d.%03d', $h, $m, $s, $ms % 1000);
}

function subMicrodvdToVtt(string $text, float $fps): string
{
    $useFps = $fps > 1.0 ? $fps : 23.976;
    $out = ['WEBVTT', ''];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^\{(\d+)\}\{(\d+)\}(.*)$/', $line, $m)) continue;
        $start = (int)$m[1];
        $end = (int)$m[2];
        $body = $m[3];
        if ($start === 1 && $end === 1 && is_numeric($body)) {
            if ((float)$body > 1.0) $useFps = (float)$body;
            continue;
        }
        $out[] = subVttTime($start / $useFps) . ' --> ' . subVttTime($end / $useFps);
        $out[] = str_replace('|', "\n", $body);
        $out[] = '';
    }
    return implode("\n", $out);
}
