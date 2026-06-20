<?php

declare(strict_types=1);

namespace Doniixify\Cache;

final class SearchCache
{
    private const TTL_FRESH = 86400;
    private const TTL_NEGATIVE = 60;

    private static function dir(): string
    {
        $dir = __DIR__ . '/../../storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir;
    }

    private const CACHE_VERSION = 'v7-db-versioned';

    private static function versionedKey(string $key): string
    {
        return md5(self::CACHE_VERSION . ':' . $key);
    }

    private static function path(string $key): string
    {
        return self::dir() . '/' . self::versionedKey($key) . '.json';
    }

    public static function get(string $key): ?array
    {
        $path = self::path($key);
        if (is_file($path)) {
            $body = @file_get_contents($path);
            if ($body !== false && $body !== '') {
                $data = json_decode($body, true);
                if (is_array($data)) return $data;
            }
        }
        try {
            $row = \Doniixify\Database::fetchOne(
                'SELECT items_json, is_empty FROM search_cache_db WHERE cache_key = ?',
                [self::versionedKey($key)]
            );
            if ($row !== null) {
                if ((int)$row['is_empty'] === 1) return [];
                $data = json_decode((string)$row['items_json'], true);
                if (is_array($data)) {
                    @file_put_contents($path, (string)$row['items_json'], LOCK_EX);
                    return $data;
                }
            }
        } catch (\Throwable $e) {}
        return null;
    }

    public static function set(string $key, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $tmp = self::path($key) . '.tmp';
        $ok = @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, self::path($key));
        try {
            \Doniixify\Database::execute(
                'INSERT INTO search_cache_db (cache_key, items_json, is_empty, created_at) VALUES (?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE items_json = VALUES(items_json), is_empty = VALUES(is_empty), created_at = NOW()',
                [self::versionedKey($key), $json, empty($data) ? 1 : 0]
            );
        } catch (\Throwable $e) {}
        return $ok !== false;
    }

    public static function setNegative(string $key): void
    {
        self::set($key, []);
        @touch(self::path($key), time() - (self::TTL_FRESH - self::TTL_NEGATIVE));
    }

    public static function age(string $key): int
    {
        $path = self::path($key);
        if (!is_file($path)) return PHP_INT_MAX;
        return time() - filemtime($path);
    }

    public static function isFresh(string $key): bool
    {
        return self::age($key) < self::TTL_FRESH;
    }

    public static function isStale(string $key): bool
    {
        return !self::isFresh($key);
    }

    public static function merge(string $key, array $newItems, callable $itemKeyFn): array
    {
        $existing = self::get($key) ?? [];
        $existingKeys = [];
        foreach ($existing as $item) {
            $k = $itemKeyFn($item);
            if ($k !== '') $existingKeys[$k] = true;
        }
        $added = 0;
        foreach ($newItems as $item) {
            $k = $itemKeyFn($item);
            if ($k === '' || isset($existingKeys[$k])) continue;
            $existing[] = $item;
            $existingKeys[$k] = true;
            $added++;
        }
        if ($added > 0) {
            self::set($key, $existing);
        }
        return ['merged' => $existing, 'added' => $added];
    }

    public static function clear(string $key): bool
    {
        $path = self::path($key);
        return is_file($path) ? @unlink($path) : true;
    }
}
