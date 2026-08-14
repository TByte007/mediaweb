<?php
$mwOgTitle = MW_OWNER !== '' ? MW_OWNER . ' · MediaWeb' : 'MediaWeb';
?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($mwOgTitle) ?>">
<meta property="og:site_name" content="MediaWeb">
<?php if (MW_OG_IMAGE !== ''): ?>
<meta property="og:image" content="<?= htmlspecialchars(MW_OG_IMAGE) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= htmlspecialchars(MW_OG_IMAGE) ?>">
<?php endif; ?>
