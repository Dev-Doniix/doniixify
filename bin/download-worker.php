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
use Doniixify\Downloader\YoutubeDownloader;

Env::load($root . '/.env');

$url = (string)($argv[1] ?? '');
$hintJson = (string)($argv[2] ?? '{}');

if ($url === '') {
    fwrite(STDERR, "Usage: download-worker.php <spotify_url> [hint_json]\n");
    exit(1);
}

$hint = json_decode($hintJson, true);
if (!is_array($hint) || empty($hint)) $hint = null;

$logDir = $root . '/storage';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
@file_put_contents(
    $logDir . '/download.log',
    '[' . date('Y-m-d H:i:s') . "] worker START pid=" . getmypid() . " url={$url}\n",
    FILE_APPEND
);

try {
    $result = YoutubeDownloader::download($url, $hint);
    @file_put_contents(
        $logDir . '/download.log',
        '[' . date('Y-m-d H:i:s') . "] worker DONE pid=" . getmypid() . " ok=" . ($result['ok'] ? '1' : '0') . "\n",
        FILE_APPEND
    );
    try { YoutubeDownloader::processPendingQueue(); } catch (\Throwable $_) {}
    exit($result['ok'] ? 0 : 1);
} catch (\Throwable $e) {
    @file_put_contents(
        $logDir . '/download.log',
        '[' . date('Y-m-d H:i:s') . "] worker ERROR pid=" . getmypid() . ": " . $e->getMessage() . "\n",
        FILE_APPEND
    );
    try { YoutubeDownloader::processPendingQueue(); } catch (\Throwable $_) {}
    exit(1);
}
