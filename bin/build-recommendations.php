<?php

declare(strict_types=1);

/**
 * Build/refresh collaborative-filtering similarity matrix.
 * Run via cron: 0 5 * * * php bin/build-recommendations.php
 * Or manually after significant new listening data.
 */

require_once __DIR__ . '/../src/Env.php';
\Doniixify\Env::load(__DIR__ . '/..');
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Cache/RecommendationEngine.php';

$start = microtime(true);
$logFile = __DIR__ . '/../storage/logs/recommendations.log';
$log = function (string $msg) use ($logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
};

try {
    $log('Building song similarity matrix…');
    $maxPairs = (int)(getenv('REC_MAX_PAIRS') ?: 50);
    $minCoListens = (int)(getenv('REC_MIN_CO_LISTENS') ?: 2);
    $stats = \Doniixify\Cache\RecommendationEngine::buildSimilarityMatrix($maxPairs, $minCoListens);
    $log('Done. Users: ' . $stats['users_processed'] . ', pairs: ' . $stats['pairs_inserted'] . ', elapsed: ' . $stats['elapsed_sec'] . 's');
} catch (\Throwable $e) {
    $log('ERROR: ' . $e->getMessage());
    exit(1);
}
