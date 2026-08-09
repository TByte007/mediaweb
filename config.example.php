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
    define('MW_OWNER', 'YourNick');            // Shown above the MediaWeb logo (empty to hide)

    // Optional llama-server for php scan.php --llm-titles (empty URL disables)
    define('MW_LLM_URL', '');                  // e.g. http://10.0.0.6:8192
    define('MW_LLM_MODEL', '');                // required on multi-model routers
    define('MW_LLM_TIMEOUT', 60);

    // Media roots — each entry: [ 'fs' => filesystem path, 'url' => Apache URL prefix ]
    define('MW_MEDIA_DIRS', [
        [ 'fs' => '/path/to/library-videos', 'url' => '/videos_lib/' ],
        [ 'fs' => '/path/to/other-videos', 'url' => '/videos2/' ],
    ]);
}
