<?php

if (!function_exists('fmtDuration')) {
    function fmtDuration(float $secs): string
    {
        $h = intdiv((int)$secs, 3600);
        $m = intdiv((int)$secs % 3600, 60);
        if ($h > 0) return sprintf('%dh %02dm', $h, $m);
        return sprintf('%dm', $m) . ($m < 10 || intval($secs) % 60 > 0 ? sprintf(' %02ds', intdiv((int)$secs, 1) % 60) : '');
    }
}

if (!function_exists('fmtSize')) {
    function fmtSize(int $bytes): string
    {
        if ($bytes < 1_048_576) return round($bytes / 1024) . ' KB';
        if ($bytes < 1_073_741_824) return round($bytes / 1_048_576, 1) . ' MB';
        return round($bytes / 1_073_741_824, 1) . ' GB';
    }
}

if (!function_exists('cleanTitle')) {
    function cleanTitle(?string $t): string
    {
        if (!$t) return '';
        $t = preg_replace('/\*[^*]+\*/', '', $t); // strip *tags*
        return trim($t, " ._-");
    }
}

if (!function_exists('pageUrl')) {
    function pageUrl(array $extra = []): string
    {
        global $basePath;
        $params = array_merge($_GET, $extra);
        return $basePath . '?' . http_build_query($params);
    }
}
