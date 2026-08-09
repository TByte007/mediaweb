<?php foreach ($videos as $v): ?>
<div class="card" data-id="<?= (int)$v['id'] ?>" data-page="<?= (int)$currentPage ?>">
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
