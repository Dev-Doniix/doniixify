#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@set_time_limit(0);

$root = dirname(__DIR__);

$rules = [
    [$root . '/storage/cache/spotify-radio/*.json', 7 * 86400],
    [$root . '/storage/cache/spotify-tracks/*.json', 30 * 86400],
    [$root . '/storage/cache/lyrics/*.json', 90 * 86400],
    [$root . '/storage/cache/lyrics/*.txt', 90 * 86400],
    [$root . '/storage/cache/lyrics/*.txt.synced', 90 * 86400],
    [$root . '/storage/cache/lastfm/*.json', 30 * 86400],
    [$root . '/storage/jobs/*.json', 7 * 86400],
];

$now = time();
$totalDeleted = 0;
$totalBytes = 0;

foreach ($rules as [$pattern, $maxAge]) {
    $cutoff = $now - $maxAge;
    foreach (glob($pattern) ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false || $mtime >= $cutoff) continue;
        $size = (int)@filesize($file);
        if (@unlink($file)) {
            $totalDeleted++;
            $totalBytes += $size;
        }
    }
}

fwrite(STDOUT, sprintf("Cleanup done: deleted %d files (%.2f MB)\n", $totalDeleted, $totalBytes / 1024 / 1024));
exit(0);
