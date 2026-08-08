<?php

declare(strict_types=1);

require_once __DIR__ . '/layout/helpers.php';
require_once __DIR__ . '/../config.php';

$id = (int)$_GET['view'];

if (!file_exists(MW_DB)) { http_response_code(500); die('DB missing'); }

$db = new SQLite3(MW_DB);
$db->busyTimeout(5000);
$row = $db->querySingle("SELECT id, filename, filepath, video_format, width, height, duration_secs,
        filesize_bytes, audio_tracks, subtitle_tracks, title, playback_count, needs_fix, is_deleted
        FROM videos WHERE id = $id", true);
$db->close();

if (!$row) { http_response_code(404); die('<h1>Not found</h1>'); }

$isDeleted = isset($row['is_deleted']) && $row['is_deleted'] == 1;
if ($isDeleted) {
    http_response_code(410);
    die('<h1>Deleted</h1><p>This video was removed from storage but is kept in the database.</p>');
}

$v = [
    'id'               => (int)$row['id'],
    'filename'         => (string)$row['filename'],
    'filepath'         => (string)$row['filepath'],
    'video_format'     => (string)$row['video_format'],
    'width'            => (int)$row['width'],
    'height'           => (int)$row['height'],
    'duration_secs'    => (float)$row['duration_secs'],
    'filesize_bytes'   => (int)$row['filesize_bytes'],
    'audio_tracks'     => (int)$row['audio_tracks'],
    'subtitle_tracks'  => (int)$row['subtitle_tracks'],
    'title'            => cleanTitle($row['title']) ?: basename($row['filename']),
    'playback_count'   => (int)$row['playback_count'],
    'needs_fix'        => isset($row['needs_fix']) ? (int)$row['needs_fix'] : 0,
];
$filepath   = $row['filepath'];
$videoUrl   = null;
foreach (array_merge(MW_FIXABLE_DIRS, MW_ACTIVE_DIRS) as $dir) {
    $fsRoot = rtrim($dir['fs'], '/');
    if (str_starts_with($filepath, $fsRoot)) {
        $videoUrl = $dir['url'] . ltrim(str_replace($fsRoot . '/', '', $filepath), '/');
        break;
    }
}
if ($videoUrl === null && str_starts_with($filepath, MW_ROOT . '/web')) {
    $videoUrl = MW_BASE_URL . basename($filepath);
}
if ($videoUrl === null) {
    http_response_code(404);
    die('<h1>Not found</h1><p>Video path not mapped to a public URL.</p>');
}

$basePath = MW_BASE_URL;
$playerPref = strtolower((string)($_GET['player'] ?? 'auto'));
if (!in_array($playerPref, ['auto', 'movi', 'avbridge'], true)) $playerPref = 'auto';
$useAvbridge = match ($playerPref) {
    'avbridge' => true,
    'movi' => false,
    default => ($v['needs_fix'] === 1),
};
// Browsers can't HW-decode MPEG-4 Part 2; skip movi's "Try Software Decoding" prompt.
$moviSw = !$useAvbridge && str_contains(strtoupper($v['video_format']), 'MPEG-4');
$avbridgeBase = MW_BASE_URL . 'vendor/avbridge/';
$avbridgeLibav = $avbridgeBase . 'vendor/libav';
$videoUrlEsc = htmlspecialchars($videoUrl);
$search = '';

require __DIR__ . '/layout/header.php';
?>

<main class="wrap video-grid">
<div>
<div class="player-wrap" id="player-wrap">
<?php if ($useAvbridge): ?>
    <avbridge-player id="player" src="<?= $videoUrlEsc ?>" playsinline fit="contain" preferstrategy="fallback">
      <a slot="top-left" href="<?= MW_BASE_URL ?>" style="color:#fff;text-decoration:none;font-size:13px">&#8592; Library</a>
    </avbridge-player>
<?php else: ?>
    <movi-player id="player" src="<?= $videoUrlEsc ?>" controls<?= $moviSw ? ' sw' : '' ?>></movi-player>
<?php endif; ?>
</div>
<div class="info-panel" style="margin-top:16px">
    <h1><?= htmlspecialchars($v['title']) ?></h1>
    <table class="info-table">
        <tr><td>Codec</td><td><?= htmlspecialchars($v['video_format']) ?></td></tr>
        <tr><td>Resolution</td><td><?= $v['width'] ?>×<?= $v['height'] ?></td></tr>
        <tr><td>Duration</td><td><?= fmtDuration($v['duration_secs']) ?></td></tr>
        <tr><td>Size</td><td><?= fmtSize($v['filesize_bytes']) ?></td></tr>
        <tr><td>Audio</td><td><?= $v['audio_tracks'] ?> track<?= $v['audio_tracks'] != 1 ? 's' : '' ?></td></tr>
        <tr><td>Subtitles</td><td><?= $v['subtitle_tracks'] ?> track<?= $v['subtitle_tracks'] != 1 ? 's' : '' ?></td></tr>
        <tr><td>Plays</td><td><?= number_format($v['playback_count']) ?></td></tr>
        <tr><td>Player</td><td><?= $useAvbridge ? 'avbridge' : 'movi' ?><?= $playerPref !== 'auto' ? ' (forced)' : ($v['needs_fix'] ? ' (needs_fix)' : '') ?></td></tr>
    </table>
    <a class="back-link" href="<?= MW_BASE_URL ?>">&#8592; Back to library</a>
</div>
</div>
</main>

<?php if ($useAvbridge): ?>
<script>
globalThis.AVBRIDGE_LIBAV_BASE = <?= json_encode($avbridgeLibav, JSON_UNESCAPED_SLASHES) ?>;
</script>
<script type="module" src="<?= htmlspecialchars($avbridgeBase) ?>dist/player-browser.js"></script>
<?php else: ?>
<script type="module" src="https://moviplayer.com/dist/element.js"></script>
<?php endif; ?>

<script type="module">
<?php if ($useAvbridge): ?>
import { AvbridgePlayerElement } from <?= json_encode($avbridgeBase . 'dist/player-browser.js', JSON_UNESCAPED_SLASHES) ?>;
<?php endif; ?>
const player = document.getElementById('player');
const videoId = <?= (int)$v['id'] ?>;

let incrementing = false;
function triggerPlayCount() {
    if (incrementing) return;
    incrementing = true;
    fetch('<?= MW_BASE_URL ?>increment-play.php?id=' + videoId, { method: 'POST' }).catch(() => {});
}

player.addEventListener('playing', triggerPlayCount, { once: true });
player.addEventListener('play', triggerPlayCount, { once: true });

function start(e) {
<?php if ($useAvbridge): ?>
    if (AvbridgePlayerElement.isPlayerChromeEvent(e)) return;
<?php endif; ?>
    if (!player.paused) return;
    const p = player.play?.();
    if (p && typeof p.then === 'function') {
        p.then(() => {
            player.removeEventListener('click', start);
            player.removeEventListener('pointerdown', start);
        }).catch((e) => console.warn('[player] play()', e));
    }
}
player.addEventListener('click', start);
player.addEventListener('pointerdown', start);
<?php if ($useAvbridge): ?>
player.addEventListener('error', (e) => console.warn('[avbridge] error', e));
<?php endif; ?>
</script>

<?php if ($v['needs_fix']): ?>
<div style="margin:16px 0;padding:10px 14px;background:#2a221a;border-left:3px solid #e6a817;border-radius:4px;font-size:13px;line-height:1.5;color:#e0d0b0;">
    <strong>⚠ Playback note:</strong> Flagged for bad/missing PTS. This page uses
    <strong>avbridge</strong> (libav.js software decode + Range streaming) instead of movi-player —
    closer to how VLC invents timestamps, but CPU-heavy and not guaranteed smooth.<br>
    If it still jitters, <a href="<?= $videoUrlEsc ?>" download style="color:#e6a817;text-decoration:underline;">download the file</a> and open it in <strong>VLC</strong>.
</div>
<?php endif; ?>

<?php require __DIR__ . '/layout/footer.php'; ?>
