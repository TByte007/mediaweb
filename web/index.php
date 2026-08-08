<?php

/**
 * MediaWeb - index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/layout/helpers.php';
require_once __DIR__ . '/../config.php';

$dbFile = MW_DB;

// --- Video player view ---
if (!empty($_GET['view']) && ctype_digit($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    require __DIR__ . '/view.php';
    exit;
}

// --- Search / library view ---
require __DIR__ . '/library.php';
