<main class="wrap">

<?php if ($search || $len !== ''): ?>
<div class="info">Found <strong><?= number_format($total) ?></strong> result<?= $total != 1 ? 's' : '' ?><?php
    if ($search) echo ' for "<strong>' . htmlspecialchars($search) . '</strong>"';
    if ($len !== '') echo ' in <strong>' . $lenFilters[$len][1] . '</strong>';
?></div>
<?php endif; ?>

<?php if (empty($videos)): ?>
<div class="empty">No videos found.</div>
<?php else: ?>

<div class="grid">
<?php foreach ($videos as $v): ?>
<div class="card" data-id="<?= (int)$v['id'] ?>">
    <div class="thumb">
        <img loading="lazy" src="<?= $basePath ?>getcover.php?id=<?= $v['id'] ?>" alt="">
        <div class="tags">
            <?= !empty($v['needs_fix']) ? '<span class="tag" style="color:#e6a817">[!]</span>' : '' ?>
            <?= $v['width'] ? '<span class="tag">'.$v['width'].'x'.$v['height'].'</span>' : '' ?>
            <span class="tag time"><?= fmtDuration($v['duration_secs']) ?></span>
        </div>
        <?= $v['playback_count'] > 0
            ? '<div class="play-badge">&#9654; '.number_format($v['playback_count']).'</div>'
            : '' ?>
    </div>
    <div class="card-body">
        <div class="card-title"><?= htmlspecialchars($v['title']) ?></div>
        <div class="card-meta">
            <?= $v['video_format'] ?> &middot; <?= fmtSize($v['filesize_bytes']) ?>
            <?= $v['audio_tracks'] ? '&middot; audio '.$v['audio_tracks'] : '' ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="pag">
<?php
    $start = max(1, $currentPage - 2);
    $end = min($pages, $currentPage + 2);
    if ($start > 1) {
        echo '<a href="' . pageUrl(['page' => 1]) . '">1</a>';
        if ($start > 2) echo '<span class="dot">…</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $cls = $p === $currentPage ? 'active' : '';
        echo '<a class="'.$cls.'" href="'.pageUrl(['page' => $p]).'">'.$p.'</a>';
    }
    if ($end < $pages) {
        if ($end < $pages - 1) echo '<span class="dot">…</span>';
        echo '<a href="'.pageUrl(['page' => $pages]).'">'.$pages.'</a>';
    }
?>
</nav>
<?php endif; ?>

<?php endif; ?>
</main>
