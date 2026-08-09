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

    // Optional llama-server for title enrich (empty URL disables; --no-llm also skips)
    define('MW_LLM_URL', '');                  // e.g. http://10.0.0.6:8192
    define('MW_LLM_MODEL', '');                // required on multi-model routers
    define('MW_LLM_TIMEOUT', 60);
    define('MW_LLM_PARALLEL', 1);              // 1=serial; dense~4; MoE~8

    // Optional TMDB for title enrich (empty token disables; --no-tmdb also skips)
    define('MW_TMDB_TOKEN', '');               // Read Access Token (Bearer), not the API Key
    define('MW_TMDB_MIN_SECS', 600);           // skip clips; 600=10m (UI Series floor); try 900 for 15m

    // Media roots — each entry: [ 'fs' => filesystem path, 'url' => Apache URL prefix ]
    define('MW_MEDIA_DIRS', [
        [ 'fs' => '/path/to/library-videos', 'url' => '/videos_lib/' ],
        [ 'fs' => '/path/to/other-videos', 'url' => '/videos2/' ],
    ]);
}
