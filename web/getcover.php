<?php

/**
 * Extract and cache a non-black cover frame from a video.
 * Falls back to existing cover images in video directories.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=604800');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$dbFile = MW_DB;
if (!file_exists($dbFile)) {
    http_response_code(500);
    exit;
}

$db = new SQLite3($dbFile);
$db->busyTimeout(5000);
$row = $db->querySingle("SELECT filepath, duration_secs FROM videos WHERE id = $id", true);
$db->close();

if (!$row || empty($row['filepath']) || !file_exists($row['filepath'])) {
    http_response_code(404);
    exit;
}

$filepath = $row['filepath'];
$coverDir = __DIR__ . '/covers';
$cacheKey = hash('xxh64', $filepath);
$cachePath = $coverDir . '/' . $cacheKey . '.jpg';
$dir = dirname($filepath);

if (file_exists($cachePath) && filemtime($cachePath) >= filemtime($filepath)) {
    readfile($cachePath);
    exit;
}

foreach (['cover.jpg', 'cover.png', 'folder.jpg', 'thumb.jpg', 'folder.png'] as $name) {
    $existing = $dir . '/' . $name;
    if (file_exists($existing) && filesize($existing) >= 1024) {
        if (!file_exists($cachePath) || filemtime($cachePath) < filemtime($existing)) {
            if (!@copy($existing, $cachePath)) {
                readfile($existing);
                exit;
            }
        }
        readfile($cachePath);
        exit;
    }
}

function isAcceptableCover(string $imgPath): bool
{
    $img = @imagecreatefromjpeg($imgPath);
    if (!$img) return false;
    $w = imagesx($img);
    $h = imagesy($img);

    $sampleCount = min($w * $h, 400);
    $totalBrightness = 0;
    $samples = 0;

    for ($y = 5; $y < $h - 5; $y += max(1, (int)($h / 15))) {
        for ($x = 5; $x < $w - 5; $x += max(1, (int)($w / 15))) {
            if ($samples >= $sampleCount) goto done;
            $color = imagecolorat($img, $x, $y);
            $r = ($color >> 16) & 0xFF;
            $g = ($color >> 8) & 0xFF;
            $b = $color & 0xFF;
            $totalBrightness += (int)(0.299 * $r + 0.587 * $g + 0.114 * $b);
            $samples++;
        }
    }
done:
    imagedestroy($img);
    return $samples > 0 && ($totalBrightness / $samples) > 25;
}

function extractFrame(string $videoPath, float $timeSecs, string $tmpName): bool
{
    $cmd = sprintf(
        '%s -y -nostdin -noautorotate -ss %.2f -i %s -vframes 1 -vf scale=480:-1,format=yuvj420p -q:v 2 -update 1 %s 2>/dev/null',
        escapeshellarg(MW_FFMPEG),
        $timeSecs,
        escapeshellarg($videoPath),
        escapeshellarg($tmpName)
    );
    exec($cmd, $out, $ret);
    if ($ret !== 0 || !file_exists($tmpName) || filesize($tmpName) < 2048) {
        @unlink($tmpName);
        return false;
    }
    if (!isAcceptableCover($tmpName)) {
        @unlink($tmpName);
        return false;
    }
    return true;
}

$duration = isset($row['duration_secs']) && $row['duration_secs'] > 0
    ? (float)$row['duration_secs'] : null;

$times = [1.5];
if ($duration !== null && $duration > 5) {
    $times = [];
    $n = min(12, max(5, (int)($duration / 20)));
    for ($i = 0; $i < $n; $i++) {
        $t = ($i + 0.5) * ($duration / $n);
        if ($t > 0.5) $times[] = round($t, 2);
    }
}

$tmpFile = $coverDir . '/' . $cacheKey . '.tmp.jpg';

foreach ($times as $t) {
    @unlink($tmpFile);
    if (extractFrame($filepath, $t, $tmpFile)) {
        @unlink($cachePath);
        if (!@rename($tmpFile, $cachePath)) {
            @copy($tmpFile, $cachePath);
            @unlink($tmpFile);
        }
        readfile($cachePath);
        exit;
    }
    @unlink($tmpFile);
}

@unlink($tmpFile);
$cmd = sprintf(
    '%s -y -nostdin -noautorotate -ss 1 -i %s -vframes 1 -vf scale=480:-1,format=yuvj420p -q:v 3 -update 1 %s 2>/dev/null',
    escapeshellarg(MW_FFMPEG),
    escapeshellarg($filepath),
    escapeshellarg($tmpFile)
);
exec($cmd, $out, $ret);

if ($ret === 0 && file_exists($tmpFile) && filesize($tmpFile) >= 2048) {
    @unlink($cachePath);
    if (!@rename($tmpFile, $cachePath)) {
        @copy($tmpFile, $cachePath);
        @unlink($tmpFile);
    }
    readfile($cachePath);
    exit;
}

@unlink($tmpFile);
http_response_code(503);
exit;
