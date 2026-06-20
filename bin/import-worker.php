#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@set_time_limit(0);
@ignore_user_abort(true);
@ini_set('memory_limit', '512M');

$root = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($root): void {
    $prefix = 'Doniixify\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

use Doniixify\Env;
use Doniixify\Database;
use Doniixify\Web\PlaylistController;

Env::load($root . '/.env');
Database::bootstrap();

$playlistId = (int)($argv[1] ?? 0);
$userId = (int)($argv[2] ?? 0);
$url = (string)($argv[3] ?? '');
$name = (string)($argv[4] ?? '');

if ($playlistId <= 0 || $userId <= 0 || $url === '') {
    fwrite(STDERR, "Usage: import-worker.php <playlistId> <userId> <url> <name>\n");
    exit(1);
}

$logDir = $root . '/storage';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
@file_put_contents(
    $logDir . '/import.log',
    '[' . date('Y-m-d H:i:s') . "] CLI worker START pid=" . getmypid() . " playlist={$playlistId}\n",
    FILE_APPEND
);

$reflect = new ReflectionClass(PlaylistController::class);
$importMethod = $reflect->getMethod('importPlaylistTracks');
$importMethod->setAccessible(true);
$nameMethod = $reflect->getMethod('fetchAndUpdatePlaylistName');
$nameMethod->setAccessible(true);

try {
    $importMethod->invoke(null, $url, $userId, $playlistId);
    if ($name !== '') {
        $nameMethod->invoke(null, $playlistId, $name, $url);
    }
} catch (\Throwable $e) {
    @file_put_contents(
        $logDir . '/import.log',
        '[' . date('Y-m-d H:i:s') . "] CLI worker ERROR playlist={$playlistId}: " . $e->getMessage() . "\n",
        FILE_APPEND
    );
    exit(1);
}

@file_put_contents(
    $logDir . '/import.log',
    '[' . date('Y-m-d H:i:s') . "] CLI worker DONE playlist={$playlistId}\n",
    FILE_APPEND
);
exit(0);
