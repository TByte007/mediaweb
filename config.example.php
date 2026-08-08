<?php

/**
 * MediaWeb shared configuration
 *
 * Copy to config.php and edit for your machine:
 *   cp config.example.php config.php
 *
 * Loaded by scan.php, list.php, and the web app.
 * Safe to include multiple times (define guards).
 */

if (!defined('MW_ROOT')) {
    define('MW_ROOT', __DIR__);
    define('MW_DB',         MW_ROOT . '/media.db');
    define('MW_FFMPEG',     '/usr/local/bin/ffmpeg');
    define('MW_FFPROBE',    '/usr/local/bin/ffprobe');

    // Web
    define('MW_BASE_URL', '/mweb/');           // Apache maps /mweb/ -> web/

    // Storage tiers
    // Each entry: [ 'fs' => filesystem path, 'url' => Apache URL prefix ]
    // Fixable: optional packed-B-frame AVI→MKV remux (--fix-broken-avi). Does not clear needs_fix.
    define('MW_FIXABLE_DIRS', [
        [ 'fs' => '/path/to/fixable-videos', 'url' => '/videos_fixable/' ],
    ]);
    // Active: managed by torrent client or otherwise read-only.
    define('MW_ACTIVE_DIRS', [
        [ 'fs' => '/path/to/active-videos', 'url' => '/videos_active/' ],
    ]);
}
