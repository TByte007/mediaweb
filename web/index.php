<?php

/**
 * MediaWeb - index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// --- Video player view ---
if (!empty($_GET['view']) && ctype_digit($_GET['view'])) {
    require __DIR__ . '/view.php';
    exit;
}

// --- Series browse (show → season → episodes) ---
if (($_GET['mode'] ?? '') === 'series') {
    require __DIR__ . '/series.php';
    exit;
}

// --- Search / library view ---
require __DIR__ . '/library.php';
