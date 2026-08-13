<main class="wrap">

<?php if ($search || $len === 'clip' || $len === 'all'): ?>
<div class="info">Found <strong><?= number_format($total) ?></strong> result<?= $total != 1 ? 's' : '' ?><?php
    if ($search) echo ' for "<strong>' . htmlspecialchars($search) . '</strong>"';
    if ($len === 'clip') echo ' in <strong>Clips</strong>';
    elseif ($len === 'all') echo ' in <strong>All</strong>';
?></div>
<?php endif; ?>

<?php if (empty($videos)): ?>
<div class="empty">No videos found.</div>
<?php else: ?>

<div class="grid-spacer-top" style="height:0"></div>
<div class="grid" data-page="<?= (int)$currentPage ?>" data-pages="<?= (int)$pages ?>" data-window="<?= (int)MW_GRID_WINDOW_PAGES ?>">
<?php require __DIR__ . '/cards.php'; ?>
</div>
<div class="grid-more" hidden></div>

<?php endif; ?>
</main>
