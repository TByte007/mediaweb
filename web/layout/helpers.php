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

if (!function_exists('prettifyFilename')) {
    /** Dot/underscore filenames → "Destroyed in Seconds Ep11" */
    function prettifyFilename(string $filename): string
    {
        $base = basename($filename);
        if (preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm|mpe?g|ts|flv)$/i', $base))
            $base = preg_replace('/\.[^.]+$/', '', $base);
        $base = preg_replace('/[._]+/', ' ', $base);
        $base = preg_replace('/\s+/', ' ', trim((string)$base));
        if ($base === '') return basename($filename);

        static $small = [
            'a' => 1, 'an' => 1, 'the' => 1, 'and' => 1, 'but' => 1, 'or' => 1,
            'for' => 1, 'nor' => 1, 'on' => 1, 'at' => 1, 'to' => 1, 'from' => 1,
            'by' => 1, 'of' => 1, 'in' => 1, 'as' => 1, 'vs' => 1, 'via' => 1,
        ];
        $words = explode(' ', $base);
        foreach ($words as $i => &$w) {
            if (preg_match('/^(ep|e)(\d+)$/i', $w, $m)) {
                $w = 'Ep' . $m[2];
                continue;
            }
            if (preg_match('/^s(\d{1,2})e(\d{1,2})$/i', $w, $m)) {
                $w = 'S' . str_pad($m[1], 2, '0', STR_PAD_LEFT)
                    . 'E' . str_pad($m[2], 2, '0', STR_PAD_LEFT);
                continue;
            }
            $lower = strtolower($w);
            if ($i > 0 && isset($small[$lower])) {
                $w = $lower;
                continue;
            }
            $w = strtoupper($lower[0] ?? '') . substr($lower, 1);
        }
        unset($w);
        return implode(' ', $words);
    }
}

if (!function_exists('videoPrettyTitle')) {
    /** Prefer a real metadata title; otherwise prettify the filename. */
    function videoPrettyTitle(string $filename, ?string $dbTitle = null): string
    {
        $cleaned = cleanTitle($dbTitle);
        if ($cleaned !== '' && substr_count($cleaned, '.') < 2
            && !preg_match('/\.(mkv|avi|mp4|mov|wmv|m4v|webm)$/i', $cleaned)) {
            return $cleaned;
        }
        return prettifyFilename($cleaned !== '' ? $cleaned : $filename);
    }
}

if (!function_exists('pageUrl')) {
    function pageUrl(array $extra = []): string
    {
        global $basePath;
        $params = array_merge($_GET, $extra);
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') unset($params[$k]);
        }
        if ($params === []) return $basePath;
        return $basePath . '?' . http_build_query($params);
    }
}
