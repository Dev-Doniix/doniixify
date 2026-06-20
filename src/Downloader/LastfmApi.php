<?php

declare(strict_types=1);

namespace Doniixify\Downloader;

use Doniixify\Env;

final class LastfmApi
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';

    public static function getSimilarTracks(string $artist, string $title, int $limit = 50): array
    {
        if ($artist === '' || $title === '') return [];
        $data = self::call('track.getSimilar', [
            'artist' => $artist,
            'track' => $title,
            'limit' => max(1, min(200, $limit)),
            'autocorrect' => '1',
        ]);
        if (!is_array($data) || !isset($data['similartracks']['track'])) return [];
        $tracks = $data['similartracks']['track'];
        if (!isset($tracks[0]) && is_array($tracks)) $tracks = [$tracks];

        $out = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $name = (string)($t['name'] ?? '');
            $artistName = (string)($t['artist']['name'] ?? '');
            if ($name === '' || $artistName === '') continue;
            $out[] = [
                'title' => $name,
                'artist' => $artistName,
                'match' => (float)($t['match'] ?? 0),
                'mbid' => (string)($t['mbid'] ?? ''),
                'url' => (string)($t['url'] ?? ''),
            ];
        }
        return $out;
    }

    public static function getSimilarArtists(string $artist, int $limit = 30): array
    {
        if ($artist === '') return [];
        $data = self::call('artist.getSimilar', [
            'artist' => $artist,
            'limit' => max(1, min(100, $limit)),
            'autocorrect' => '1',
        ]);
        if (!is_array($data) || !isset($data['similarartists']['artist'])) return [];
        $artists = $data['similarartists']['artist'];
        if (!isset($artists[0]) && is_array($artists)) $artists = [$artists];

        $out = [];
        foreach ($artists as $a) {
            if (!is_array($a)) continue;
            $name = (string)($a['name'] ?? '');
            if ($name === '') continue;
            $out[] = [
                'name' => $name,
                'match' => (float)($a['match'] ?? 0),
                'mbid' => (string)($a['mbid'] ?? ''),
            ];
        }
        return $out;
    }

    public static function getArtistTopTracks(string $artist, int $limit = 50): array
    {
        if ($artist === '') return [];
        $data = self::call('artist.getTopTracks', [
            'artist' => $artist,
            'limit' => max(1, min(100, $limit)),
            'autocorrect' => '1',
        ]);
        if (!is_array($data) || !isset($data['toptracks']['track'])) return [];
        $tracks = $data['toptracks']['track'];
        if (!isset($tracks[0]) && is_array($tracks)) $tracks = [$tracks];

        $out = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $name = (string)($t['name'] ?? '');
            $artistName = (string)($t['artist']['name'] ?? '');
            if ($name === '' || $artistName === '') continue;
            $out[] = [
                'title' => $name,
                'artist' => $artistName,
                'playcount' => (int)($t['playcount'] ?? 0),
                'listeners' => (int)($t['listeners'] ?? 0),
            ];
        }
        return $out;
    }

    private const FALLBACK_API_KEY = '5aad2665592171e21ea0636980660596';

    public static function isConfigured(): bool
    {
        return self::getApiKey() !== '';
    }

    private static function getApiKey(): string
    {
        $env = (string)Env::get('LASTFM_API_KEY', '');
        if ($env !== '') return $env;
        return self::FALLBACK_API_KEY;
    }

    private static function call(string $method, array $params): ?array
    {
        $apiKey = self::getApiKey();
        if ($apiKey === '') {
            self::log("Last.fm API key missing — skipping {$method}");
            return null;
        }

        $cacheKey = md5($method . json_encode($params));
        $cacheDir = __DIR__ . '/../../storage/cache/lastfm';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        static $rateLimitedUntil = 0;
        if ($rateLimitedUntil > time()) return null;

        $query = array_merge($params, [
            'method' => $method,
            'api_key' => $apiKey,
            'format' => 'json',
        ]);
        $url = self::BASE_URL . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: Doniixify/1.0'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 429 || $status === 503) {
            $rateLimitedUntil = time() + 30;
            self::log("Last.fm rate limited ({$status}) — backing off 30s");
            return null;
        }
        if ($status !== 200 || !is_string($body)) {
            self::log("Last.fm {$method} HTTP {$status}");
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) return null;
        if (isset($data['error'])) {
            self::log("Last.fm {$method} error: " . (string)($data['message'] ?? 'unknown'));
            return null;
        }
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
        return $data;
    }

    private static function log(string $msg): void
    {
        $dir = __DIR__ . '/../../storage';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/lastfm.log', '[' . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND | LOCK_EX);
    }
}
