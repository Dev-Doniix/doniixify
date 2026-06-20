<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Cache\SearchCache;
use Doniixify\Downloader\JobTracker;
use Doniixify\Downloader\SpotifyApi;
use Doniixify\Downloader\YoutubeDownloader;
use Doniixify\Scanner\Scanner;

final class SpotifyController
{
    public static function notifyScan(): void
    {
        Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['queued' => true]);
    }

    public static function downloadsStatus(): void
    {
        Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $jobs = JobTracker::all(50);
        $active = [];
        $recent = [];
        foreach ($jobs as $j) {
            $status = (string)($j['status'] ?? 'unknown');
            if ($status === 'starting' || $status === 'downloading') {
                $active[] = $j;
            } else {
                $recent[] = $j;
            }
        }
        echo json_encode(['active' => $active, 'recent' => array_slice($recent, 0, 20)]);
    }

    public static function search(): void
    {
        $user = Session::requireLogin();
        $debug = !empty($_GET['debug']);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            echo json_encode($debug ? ['debug' => 'query too short', 'items' => []] : []);
            return;
        }

        $type = (($_GET['t'] ?? 'title') === 'artist') ? 'artist' : 'title';
        $limit = 50;

        $token = SpotifyApi::getAccessToken();
        if ($token === '') {
            http_response_code(503);
            echo json_encode(['error' => 'Spotify API unavailable']);
            return;
        }

        $items = self::directSpotifySearch($q, $token, $limit);
        $normalized = array_values(array_map([self::class, 'normalizeItem'], $items));

        if ($debug) {
            echo json_encode([
                'query' => $q,
                'type' => $type,
                'token_ok' => $token !== '',
                'raw_count' => count($items),
                'normalized_count' => count($normalized),
                'items' => $normalized,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode($normalized);
        }
        self::recordHistory((int)($user['id'] ?? 0), $q, $type);
    }

    private static function recordHistory(int $userId, string $query, string $type): void
    {
        if ($userId <= 0 || $query === '') return;
        try {
            \Doniixify\Database::execute(
                'INSERT INTO search_history (user_id, query, query_type, hit_count, last_searched_at)
                 VALUES (?, ?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_searched_at = NOW()',
                [$userId, mb_substr($query, 0, 255), $type]
            );
        } catch (\Throwable $e) {}
    }

    private static function scheduleBackgroundRefresh(string $q, string $type, int $limit, string $cacheKey): void
    {
        register_shutdown_function(function () use ($q, $type, $limit, $cacheKey) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                $fresh = SpotifyApi::search($q, $limit, $type);
                if (empty($fresh)) return;
                $normalized = array_values(array_map([self::class, 'normalizeItem'], $fresh));
                SearchCache::merge($cacheKey, $normalized, function ($it) {
                    if (!is_array($it)) return '';
                    $u = (string)($it['url'] ?? '');
                    if ($u !== '') return 'u:' . $u;
                    $name = mb_strtolower((string)($it['name'] ?? ''));
                    $artist = '';
                    if (!empty($it['artists'][0]['name'])) $artist = mb_strtolower((string)$it['artists'][0]['name']);
                    return 'a:' . $artist . '||' . $name;
                });
            } catch (\Throwable $e) {}
        });
    }

    public static function cover(): void
    {
        Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $id = trim($_GET['id'] ?? '');
        if ($id === '' || !preg_match('/^[A-Za-z0-9]{8,32}$/', $id)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid id']);
            return;
        }

        $cached = \Doniixify\Database::fetchOne('SELECT cover_url, not_found FROM spotify_cover_cache WHERE spotify_id = ?', [$id]);
        if ($cached !== null) {
            if ((int)$cached['not_found'] === 1) {
                echo json_encode(['url' => null]);
                return;
            }
            echo json_encode(['url' => $cached['cover_url']]);
            return;
        }

        $coverUrl = self::fetchOEmbedCover($id);
        if ($coverUrl === null) {
            \Doniixify\Database::execute(
                'INSERT IGNORE INTO spotify_cover_cache (spotify_id, not_found) VALUES (?, 1)',
                [$id]
            );
            echo json_encode(['url' => null]);
            return;
        }
        \Doniixify\Database::execute(
            'INSERT IGNORE INTO spotify_cover_cache (spotify_id, cover_url) VALUES (?, ?)',
            [$id, $coverUrl]
        );
        echo json_encode(['url' => $coverUrl]);
    }

    private static function fetchOEmbedCover(string $id): ?string
    {
        $url = 'https://open.spotify.com/oembed?url=' . urlencode('https://open.spotify.com/track/' . $id);
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200 && is_string($body)) {
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['thumbnail_url'])) {
                return (string)$data['thumbnail_url'];
            }
        }
        return null;
    }

    private static function normalizeItem(array $item): array
    {
        $out = $item;
        $cover = $item['cover_url'] ?? $item['cover'] ?? $item['image'] ?? $item['thumbnail'] ?? $item['album_cover'] ?? $item['album_image'] ?? null;
        if (!$cover && isset($item['album']) && is_array($item['album'])) {
            $cover = $item['album']['cover_url'] ?? $item['album']['image'] ?? null;
            if (!$cover && !empty($item['album']['images']) && is_array($item['album']['images'])) {
                $cover = $item['album']['images'][0]['url'] ?? null;
            }
        }
        if (!$cover && !empty($item['images']) && is_array($item['images'])) {
            $cover = $item['images'][0]['url'] ?? null;
        }
        $out['cover_url'] = $cover;

        $name = $item['name'] ?? $item['title'] ?? $item['track_name'] ?? $item['song_name'] ?? null;
        $out['name'] = $name ?: 'Untitled';

        $artists = $item['artists'] ?? $item['artist'] ?? [];
        if (!is_array($artists)) $artists = [$artists];
        $out['artists'] = array_values(array_filter(array_map(function ($a) {
            if (is_string($a)) return $a;
            if (is_array($a)) return $a['name'] ?? null;
            if (is_object($a) && isset($a->name)) return $a->name;
            return null;
        }, $artists)));

        $sid = $item['song_id'] ?? $item['id'] ?? $item['spotify_id'] ?? $item['track_id'] ?? null;
        $out['song_id'] = $sid;

        $url = $item['url'] ?? $item['spotify_url'] ?? $item['external_url'] ?? null;
        if (!$url && $sid) {
            $url = 'https://open.spotify.com/track/' . $sid;
        }
        $out['url'] = $url;

        $duration = $item['duration'] ?? null;
        if (!$duration && isset($item['duration_ms'])) {
            $duration = (int)$item['duration_ms'] / 1000;
        }
        $out['duration'] = $duration ? (int)$duration : 0;

        if (!empty($item['artist_picture'])) {
            $out['artist_picture'] = (string)$item['artist_picture'];
        }

        if (isset($item['album']) && is_array($item['album'])) {
            $albumOut = $out['album'] ?? [];
            if (!is_array($albumOut)) $albumOut = [];
            if (isset($item['album']['id'])) $albumOut['id'] = (int)$item['album']['id'];
            if (isset($item['album']['release_date'])) $albumOut['release_date'] = (string)$item['album']['release_date'];
            if (isset($item['album']['record_type'])) $albumOut['record_type'] = (string)$item['album']['record_type'];
            if (isset($item['album']['name']) && !isset($albumOut['name'])) $albumOut['name'] = (string)$item['album']['name'];
            if (isset($item['album']['images']) && !isset($albumOut['images'])) $albumOut['images'] = $item['album']['images'];
            $out['album'] = $albumOut;
        }

        return $out;
    }

    public static function download(): void
    {
        $user = Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $songUrl = $input['url'] ?? $_POST['url'] ?? null;
        $songId = $input['song_id'] ?? $_POST['song_id'] ?? null;
        $hintTitle = trim((string)($input['title'] ?? ''));
        $hintArtist = trim((string)($input['artist'] ?? ''));
        $hintAlbum = trim((string)($input['album'] ?? ''));

        $target = null;
        if (is_string($songUrl) && $songUrl !== '') {
            $target = $songUrl;
        } elseif (is_string($songId) && preg_match('/^[A-Za-z0-9]{22}$/', $songId)) {
            $target = 'https://open.spotify.com/track/' . $songId;
        }

        if ($target === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing URL or song_id']);
            return;
        }

        $hint = ['user_id' => (int)($user['id'] ?? 0)];
        if ($hintTitle !== '') $hint['title'] = $hintTitle;
        if ($hintArtist !== '') $hint['artist'] = $hintArtist;
        if ($hintAlbum !== '') $hint['album'] = $hintAlbum;

        YoutubeDownloader::queueBackground($target, $hint);
        echo json_encode([
            'status' => 'queued',
            'target' => $target,
            'note' => 'Doniixify is processing in background — track appears after scan',
        ]);
    }

    private static function directSpotifySearch(string $q, string $token, int $limit): array
    {
        // Spotify dev mode quota czasem odrzuca limity > 10. Multi-tier retry od najwyższego do bezpiecznego.
        $requested = (int)max(1, min(50, $limit));
        $tries = [$requested, 30, 20, 10, 5];
        $tries = array_values(array_unique($tries));

        $tracks = [];
        $lastStatus = 0;
        $lastBody = '';
        $usedLimit = 0;
        foreach ($tries as $effLimit) {
            $url = 'https://api.spotify.com/v1/search?'
                . 'q=' . rawurlencode($q)
                . '&type=track'
                . '&limit=' . $effLimit
                . '&market=PL';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastStatus = $status;
            $lastBody = is_string($body) ? $body : '';

            if ($status === 200 && is_string($body) && $body !== '') {
                $data = json_decode($body, true);
                $candidates = is_array($data) ? ($data['tracks']['items'] ?? []) : [];
                if (is_array($candidates) && !empty($candidates)) {
                    $tracks = $candidates;
                    $usedLimit = $effLimit;
                    break;
                }
            }
        }

        if (!empty($_GET['debug'])) {
            header('X-Debug-Spotify-Used-Limit: ' . $usedLimit);
            header('X-Debug-Spotify-Last-HTTP: ' . $lastStatus);
        }
        if (empty($tracks)) return [];

        $out = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $name = (string)($t['name'] ?? '');
            $sid = (string)($t['id'] ?? '');
            if ($name === '' || $sid === '') continue;

            $artists = [];
            foreach ((array)($t['artists'] ?? []) as $a) {
                if (is_array($a) && !empty($a['name'])) {
                    $artists[] = ['name' => (string)$a['name'], 'id' => (string)($a['id'] ?? '')];
                }
            }

            $album = $t['album'] ?? [];
            $images = is_array($album) && is_array($album['images'] ?? null) ? $album['images'] : [];
            $cover = !empty($images[0]['url']) ? (string)$images[0]['url'] : '';

            $out[] = [
                'name' => $name,
                'song_id' => $sid,
                'id' => $sid,
                'url' => 'https://open.spotify.com/track/' . $sid,
                'artists' => $artists,
                'duration_ms' => (int)($t['duration_ms'] ?? 0),
                'popularity' => (int)($t['popularity'] ?? 0),
                'album' => [
                    'id' => is_array($album) ? (string)($album['id'] ?? '') : '',
                    'name' => is_array($album) ? (string)($album['name'] ?? '') : '',
                    'release_date' => is_array($album) ? (string)($album['release_date'] ?? '') : '',
                    'images' => $images,
                ],
                'cover_url' => $cover,
            ];
        }
        return $out;
    }
}
