<?php

declare(strict_types=1);

namespace Doniixify\Downloader;

final class JobTracker
{
    private static function dir(): string
    {
        $d = __DIR__ . '/../../storage/jobs';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    private static function path(int $pid): string
    {
        return self::dir() . '/' . $pid . '.json';
    }

    public static function start(int $pid, string $url, string $title = '', string $artist = '', string $album = ''): void
    {
        self::write($pid, [
            'pid' => $pid,
            'url' => $url,
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'status' => 'starting',
            'progress' => 0,
            'stage' => 'init',
            'started_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'error' => null,
        ]);
    }

    public static function update(int $pid, array $patch): void
    {
        $data = self::read($pid) ?? [];
        if (empty($data)) {
            $data = ['pid' => $pid, 'started_at' => date('Y-m-d H:i:s')];
        }
        $data = array_merge($data, $patch);
        $data['updated_at'] = date('Y-m-d H:i:s');
        self::write($pid, $data);
    }

    public static function complete(int $pid, bool $success, ?string $error = null): void
    {
        self::update($pid, [
            'status' => $success ? 'done' : 'failed',
            'progress' => $success ? 100 : (self::read($pid)['progress'] ?? 0),
            'stage' => $success ? 'complete' : 'failed',
            'error' => $error,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function read(int $pid): ?array
    {
        $f = self::path($pid);
        if (!is_file($f)) return null;
        $body = @file_get_contents($f);
        if ($body === false || $body === '') return null;
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    public static function write(int $pid, array $data): void
    {
        @file_put_contents(self::path($pid), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function clear(): int
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        $removed = 0;
        foreach ($files as $f) {
            $body = @file_get_contents($f);
            if ($body === false) { @unlink($f); $removed++; continue; }
            $data = json_decode($body, true);
            if (!is_array($data)) { @unlink($f); $removed++; continue; }
            $st = (string)($data['status'] ?? '');
            if ($st === 'done' || $st === 'failed') {
                if (@unlink($f)) $removed++;
            }
        }
        return $removed;
    }

    public static function all(int $limit = 50): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        $files = array_slice($files, 0, $limit);
        $jobs = [];
        foreach ($files as $f) {
            $body = @file_get_contents($f);
            if ($body === false || $body === '') continue;
            $d = json_decode($body, true);
            if (is_array($d)) $jobs[] = $d;
        }
        return $jobs;
    }

    public static function cleanup(int $olderThanSeconds = 86400): void
    {
        $cutoff = time() - $olderThanSeconds;
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) @unlink($f);
        }
    }
}
