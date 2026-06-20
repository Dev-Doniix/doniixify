<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Env;

final class LastfmController
{
    private const BASE_URL = 'https://ws.audioscrobbler.com/2.0/';
    private const AUTH_URL = 'https://www.last.fm/api/auth/';

    public static function authStart(): void
    {
        Session::requireLogin();
        $apiKey = Env::get('LASTFM_API_KEY', '');
        if ($apiKey === '') {
            http_response_code(500);
            echo 'LASTFM_API_KEY not configured in .env';
            return;
        }
        $callback = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/lastfm/callback';
        $url = self::AUTH_URL . '?api_key=' . urlencode($apiKey) . '&cb=' . urlencode($callback);
        header('Location: ' . $url);
        exit;
    }

    public static function authCallback(): void
    {
        $user = Session::requireLogin();
        header('Content-Type: text/html; charset=UTF-8');
        $token = $_GET['token'] ?? '';
        $apiKey = Env::get('LASTFM_API_KEY', '');
        $apiSecret = Env::get('LASTFM_SECRET', '') ?: Env::get('LASTFM_SHARED_SECRET', '');
        if ($token === '' || $apiKey === '' || $apiSecret === '') {
            echo '<p>Missing token or LASTFM_API_KEY/LASTFM_SECRET. Set them in .env.</p>';
            return;
        }
        $sigParams = ['api_key' => $apiKey, 'method' => 'auth.getSession', 'token' => $token];
        ksort($sigParams);
        $sigStr = '';
        foreach ($sigParams as $k => $v) $sigStr .= $k . $v;
        $sig = md5($sigStr . $apiSecret);
        $url = self::BASE_URL . '?method=auth.getSession&api_key=' . urlencode($apiKey) . '&token=' . urlencode($token) . '&api_sig=' . $sig . '&format=json';
        $body = @file_get_contents($url);
        $data = json_decode($body ?: '{}', true);
        $sessionKey = $data['session']['key'] ?? null;
        $sessionName = $data['session']['name'] ?? null;
        if (!$sessionKey) {
            echo '<p>Last.fm auth failed: ' . htmlspecialchars($data['message'] ?? 'unknown') . '</p>';
            return;
        }
        try {
            Database::execute(
                "CREATE TABLE IF NOT EXISTS lastfm_sessions (
                    user_id INT PRIMARY KEY,
                    session_key VARCHAR(64) NOT NULL,
                    lastfm_name VARCHAR(128),
                    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )"
            );
            Database::execute(
                "INSERT INTO lastfm_sessions (user_id, session_key, lastfm_name) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE session_key = VALUES(session_key), lastfm_name = VALUES(lastfm_name)",
                [(int)$user['id'], $sessionKey, $sessionName]
            );
            echo '<script>window.location.href = "/settings";</script><p>Connected to Last.fm as ' . htmlspecialchars($sessionName) . '. Redirecting…</p>';
        } catch (\Throwable $e) {
            echo '<p>DB error: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    public static function nowPlaying(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        $artist = trim($_POST['artist'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $duration = (int)($_POST['duration'] ?? 0);
        if ($artist === '' || $title === '') { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
        $apiKey = Env::get('LASTFM_API_KEY', '');
        $apiSecret = Env::get('LASTFM_SECRET', '') ?: Env::get('LASTFM_SHARED_SECRET', '');
        try {
            $sess = Database::fetchOne('SELECT session_key FROM lastfm_sessions WHERE user_id = ?', [(int)$user['id']]);
            if (!$sess) { echo json_encode(['error' => 'Not connected']); return; }
            $params = [
                'method' => 'track.updateNowPlaying',
                'api_key' => $apiKey,
                'artist' => $artist,
                'track' => $title,
                'sk' => (string)$sess['session_key'],
            ];
            if ($duration > 0) $params['duration'] = (string)$duration;
            ksort($params);
            $sigStr = '';
            foreach ($params as $k => $v) $sigStr .= $k . $v;
            $sig = md5($sigStr . $apiSecret);
            $params['api_sig'] = $sig;
            $params['format'] = 'json';
            $ch = curl_init(self::BASE_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            echo json_encode(['ok' => $status === 200, 'status' => $status]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'unavailable']);
        }
    }

    public static function scrobble(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        $artist = trim($_POST['artist'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $ts = (int)($_POST['timestamp'] ?? time());
        if ($artist === '' || $title === '') { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
        $apiKey = Env::get('LASTFM_API_KEY', '');
        $apiSecret = Env::get('LASTFM_SECRET', '') ?: Env::get('LASTFM_SHARED_SECRET', '');
        try {
            $sess = Database::fetchOne('SELECT session_key FROM lastfm_sessions WHERE user_id = ?', [(int)$user['id']]);
            if (!$sess) { echo json_encode(['error' => 'Not connected']); return; }
            $sk = (string)$sess['session_key'];
            $params = [
                'method' => 'track.scrobble',
                'api_key' => $apiKey,
                'artist' => $artist,
                'track' => $title,
                'timestamp' => (string)$ts,
                'sk' => $sk,
            ];
            ksort($params);
            $sigStr = '';
            foreach ($params as $k => $v) $sigStr .= $k . $v;
            $sig = md5($sigStr . $apiSecret);
            $params['api_sig'] = $sig;
            $params['format'] = 'json';
            $ch = curl_init(self::BASE_URL);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $resp = json_decode($body ?: '{}', true);
            $accepted = (int)($resp['scrobbles']['@attr']['accepted'] ?? 0);
            echo json_encode(['ok' => $status === 200 && $accepted > 0, 'status' => $status, 'accepted' => $accepted]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'unavailable']);
        }
    }

    public static function status(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        try {
            $sess = Database::fetchOne('SELECT lastfm_name, connected_at FROM lastfm_sessions WHERE user_id = ?', [(int)$user['id']]);
            echo json_encode([
                'connected' => $sess !== null,
                'name' => $sess['lastfm_name'] ?? null,
                'connected_at' => $sess['connected_at'] ?? null,
                'has_credentials' => Env::get('LASTFM_API_KEY', '') !== '' && (Env::get('LASTFM_SECRET', '') !== '' || Env::get('LASTFM_SHARED_SECRET', '') !== ''),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['connected' => false]);
        }
    }

    public static function disconnect(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        try {
            Database::execute('DELETE FROM lastfm_sessions WHERE user_id = ?', [(int)$user['id']]);
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => true]);
        }
    }

    public static function discover(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        $apiKey = Env::get('LASTFM_API_KEY', '');
        if ($apiKey === '') {
            echo json_encode(['trending' => [], 'similar' => [], 'top_artist_tracks' => []]);
            return;
        }
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache/lastfm-discover';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/u' . (int)$user['id'] . '_v3.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached) && $cached !== '') { echo $cached; return; }
        }
        $topArtist = null;
        try {
            $row = Database::fetchOne(
                'SELECT ar.name FROM user_song_plays usp
                 JOIN songs s ON s.id = usp.song_id
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE usp.user_id = ?
                 GROUP BY ar.id, ar.name
                 ORDER BY SUM(usp.play_count) DESC LIMIT 1',
                [(int)$user['id']]
            );
            $topArtist = $row['name'] ?? null;
        } catch (\Throwable $e) {}
        $result = [
            'trending' => self::lfmFetchTopTracks($apiKey, 'chart.getTopTracks', [], 12),
            'similar' => [],
            'top_artist_tracks' => [],
            'top_artist' => null,
            'based_on' => null,
        ];
        if ($topArtist) {
            $result['top_artist'] = $topArtist;
            $result['top_artist_tracks'] = self::lfmFetchTopTracks($apiKey, 'artist.getTopTracks', ['artist' => $topArtist], 8);
            $similarArtist = self::lfmFetchSimilarArtist($apiKey, $topArtist);
            if ($similarArtist !== null) {
                $result['based_on'] = $similarArtist;
                $result['similar'] = self::lfmFetchTopTracks($apiKey, 'artist.getTopTracks', ['artist' => $similarArtist], 8);
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        @file_put_contents($cacheFile, $json);
        echo $json;
    }

    private static function lfmFetchTopTracks(string $apiKey, string $method, array $extra, int $limit): array
    {
        $params = array_merge([
            'method' => $method,
            'api_key' => $apiKey,
            'format' => 'json',
            'limit' => (string)$limit,
        ], $extra);
        $url = self::BASE_URL . '?' . http_build_query($params);
        $body = self::lfmHttpGet($url);
        $data = json_decode($body ?: '{}', true);
        $tracks = $data['tracks']['track'] ?? $data['toptracks']['track'] ?? [];
        if (!is_array($tracks)) return [];
        $out = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $title = (string)($t['name'] ?? '');
            $artist = (string)($t['artist']['name'] ?? $t['artist'] ?? '');
            if ($title === '' || $artist === '') continue;
            $images = $t['image'] ?? [];
            $image = '';
            if (is_array($images)) {
                foreach ($images as $img) {
                    if (is_array($img) && !empty($img['#text']) && in_array(($img['size'] ?? ''), ['extralarge', 'large', 'medium'], true)) {
                        $image = (string)$img['#text']; break;
                    }
                }
            }
            $localId = self::matchLocalSongId($artist, $title);
            if ($image !== '' && preg_match('/2a96cbd8b46e442fc41c2b86b821562f\.png/', $image)) {
                $image = '';
            }
            $out[] = [
                'title' => $title,
                'artist' => $artist,
                'image' => $image,
                'url' => (string)($t['url'] ?? ''),
                'local_id' => $localId,
            ];
        }
        return $out;
    }

    private static function iTunesCoverLookup(string $artist, string $title): string
    {
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache/itunes-covers';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheKey = md5('v2:' . mb_strtolower($artist . '||' . $title));
        $cacheFile = $cacheDir . '/' . $cacheKey . '.txt';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400 * 7) {
            $cached = trim((string)@file_get_contents($cacheFile));
            if ($cached !== '' && $cached !== 'MISS') return $cached;
            if ($cached === 'MISS' && (time() - filemtime($cacheFile)) < 86400) return '';
        }
        $q = rawurlencode($artist . ' ' . $title);
        $url = 'https://itunes.apple.com/search?term=' . $q . '&entity=song&limit=1';
        $body = self::lfmHttpGet($url);
        $data = json_decode($body ?: '{}', true);
        $art = '';
        if (!empty($data['results'][0]['artworkUrl100'])) {
            $art = str_replace('100x100bb', '300x300bb', (string)$data['results'][0]['artworkUrl100']);
        }
        if ($art === '') {
            $art = self::spotifyCoverLookup($artist, $title);
        }
        @file_put_contents($cacheFile, $art !== '' ? $art : 'MISS');
        return $art;
    }

    private static function spotifyCoverLookup(string $artist, string $title): string
    {
        try {
            $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
            if ($token === '') return '';
            $url = 'https://api.spotify.com/v1/search?type=track&limit=1&market=PL&q=' . rawurlencode($artist . ' ' . $title);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                CURLOPT_TIMEOUT => 4,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$body, true);
            return (string)($data['tracks']['items'][0]['album']['images'][0]['url'] ?? '');
        } catch (\Throwable $e) { return ''; }
    }

    public static function queueDownload(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json');
        $artist = trim((string)($_POST['artist'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        if ($artist === '' || $title === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing artist/title']);
            return;
        }
        $existing = self::matchLocalSongId($artist, $title);
        if ($existing) {
            echo json_encode(['ok' => true, 'already_local' => true, 'song_id' => $existing]);
            return;
        }
        $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
        if ($token === '') {
            http_response_code(503);
            echo json_encode(['error' => 'Spotify unavailable']);
            return;
        }
        $searchUrl = 'https://api.spotify.com/v1/search?type=track&limit=1&market=PL&q=' . rawurlencode($artist . ' ' . $title);
        $ch = curl_init($searchUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string)$body, true);
        $item = $data['tracks']['items'][0] ?? null;
        if (!is_array($item) || empty($item['id'])) {
            echo json_encode(['error' => 'Not found on Spotify']);
            return;
        }
        $hint = [
            'user_id' => (int)$user['id'],
            'title' => (string)($item['name'] ?? $title),
            'artist' => (string)($item['artists'][0]['name'] ?? $artist),
            'album' => (string)($item['album']['name'] ?? ''),
            'cover_url' => (string)($item['album']['images'][0]['url'] ?? ''),
        ];
        $target = 'https://open.spotify.com/track/' . (string)$item['id'];
        \Doniixify\Downloader\YoutubeDownloader::queueBackground($target, $hint);
        echo json_encode(['ok' => true, 'queued' => true, 'target' => $target, 'title' => $hint['title'], 'artist' => $hint['artist']]);
    }

    private static function lfmFetchSimilarArtist(string $apiKey, string $artist): ?string
    {
        $params = ['method' => 'artist.getSimilar', 'api_key' => $apiKey, 'artist' => $artist, 'format' => 'json', 'limit' => '1'];
        $body = self::lfmHttpGet(self::BASE_URL . '?' . http_build_query($params));
        $data = json_decode($body ?: '{}', true);
        $artists = $data['similarartists']['artist'] ?? [];
        if (!is_array($artists)) return null;
        foreach ($artists as $a) {
            $name = $a['name'] ?? null;
            if (is_string($name) && $name !== '') return $name;
        }
        return null;
    }

    private static function matchLocalSongId(string $artist, string $title): ?int
    {
        try {
            $row = Database::fetchOne(
                'SELECT s.id FROM songs s
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE LOWER(ar.name) = LOWER(?) AND LOWER(s.title) = LOWER(?)
                 LIMIT 1',
                [$artist, $title]
            );
            return $row ? (int)$row['id'] : null;
        } catch (\Throwable $e) { return null; }
    }

    public static function coverLookup(): void
    {
        Session::requireLoginJson();
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=86400');
        $artist = trim((string)($_GET['artist'] ?? ''));
        $title = trim((string)($_GET['title'] ?? ''));
        if ($artist === '' || $title === '') { echo json_encode(['url' => '']); return; }
        $url = self::iTunesCoverLookup($artist, $title);
        echo json_encode(['url' => $url]);
    }

    private static function lfmHttpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Doniixify/1.0',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }
}
