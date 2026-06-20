#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@set_time_limit(0);
@ignore_user_abort(true);

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

$n = YoutubeDownloader::processPendingQueue();
fwrite(STDOUT, "Processed {$n} pending downloads\n");
exit(0);
