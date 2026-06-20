<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Scanner\Scanner;

final class StreamView
{
    public static function stream(int $songId): void
    {
        if (!Session::isLogged()) {
            http_response_code(401);
            echo 'Unauthorized';
            return;
        }

        $song = Database::fetchOne('SELECT path, content_type, size FROM songs WHERE id = ?', [$songId]);
        if ($song === null) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        if (!is_file($song['path'])) {
            self::cleanupMissingSong($songId, (string)$song['path']);
            http_response_code(404);
            echo 'Not found';
            return;
        }

        self::disableCompression();
        self::sendFile($song['path'], $song['content_type']);
    }

    private static function cleanupMissingSong(int $songId, string $path): void
    {
        try {
            Database::execute('DELETE FROM songs WHERE id = ?', [$songId]);
            Database::pdo()->exec('UPDATE albums a SET song_count = (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id), duration = (SELECT COALESCE(SUM(duration),0) FROM songs s WHERE s.album_id = a.id)');
            Database::pdo()->exec('UPDATE artists ar SET song_count = (SELECT COUNT(*) FROM songs s WHERE s.artist_id = ar.id), album_count = (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id)');
            Database::pdo()->exec('DELETE FROM albums WHERE song_count = 0');
            Database::pdo()->exec('DELETE FROM artists WHERE song_count = 0');
            $log = __DIR__ . '/../../storage/import.log';
            @file_put_contents($log, '[' . date('Y-m-d H:i:s') . '] [stream] Error with song ' . $songId . ': file missing — auto-removed from library (' . $path . ")\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    public static function debugCovers(): void
    {
        if (!Session::isLogged()) { http_response_code(401); echo 'Unauthorized'; return; }
        header('Content-Type: application/json; charset=UTF-8');
        $cacheDir = \Doniixify\Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        $sample = Database::fetchOne(
            'SELECT s.id, s.title, s.path, ar.name AS artist_name FROM songs s JOIN artists ar ON ar.id = s.artist_id ORDER BY s.id DESC LIMIT 1'
        );
        $info = [
            'cache_dir' => $cacheDir,
            'cache_dir_exists' => is_dir($cacheDir),
            'cache_dir_writable' => is_dir($cacheDir) && is_writable($cacheDir),
            'cache_dir_owner' => is_dir($cacheDir) ? (function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($cacheDir))['name'] ?? fileowner($cacheDir)) : fileowner($cacheDir)) : null,
            'cache_dir_perms' => is_dir($cacheDir) ? substr(sprintf('%o', fileperms($cacheDir)), -4) : null,
            'php_user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()) : 'unknown',
            'curl_available' => function_exists('curl_init'),
            'file_count' => is_dir($cacheDir) ? count(glob($cacheDir . '/*') ?: []) : 0,
            'songs_in_library' => (int)Database::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
            'sample_song' => $sample,
        ];
        if ($sample !== null) {
            $title = self::stripJunk((string)$sample['title']);
            $artist = self::stripJunk((string)$sample['artist_name']);
            $info['itunes_lookup'] = [
                'cleaned_title' => $title,
                'cleaned_artist' => $artist,
                'itunes_with_artist' => $artist !== '' ? self::lookupItunesCover($title, $artist) : null,
                'itunes_title_only' => self::lookupItunesCover($title, ''),
                'deezer_with_artist' => $artist !== '' ? self::lookupDeezerCover($title, $artist) : null,
            ];
            $info['local_cover_for_sample'] = Scanner::coverPathFor($sample['path']);
            $info['cover_url_to_test'] = '/cover/' . $sample['id'];
        }
        $info['write_test'] = self::writeTest($cacheDir);
        echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function disableCompression(): void
    {
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Encoding: identity');
        header('X-Accel-Buffering: no');
    }

    private static function writeTest(string $dir): array
    {
        if (!is_dir($dir)) return ['result' => 'dir does not exist'];
        $f = $dir . '/.write-test-' . uniqid();
        $ok = @file_put_contents($f, 'test');
        if ($ok === false) return ['result' => 'write failed', 'error' => error_get_last()['message'] ?? null];
        @unlink($f);
        return ['result' => 'ok'];
    }

    public static function cover(int $songId): void
    {
        if (!Session::isLogged()) {
            $token = (string)($_GET['token'] ?? '');
            $valid = $token !== ''
                && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)
                && Database::fetchOne('SELECT 1 FROM users WHERE api_token = ? LIMIT 1', [$token]) !== null;
            if (!$valid) {
                http_response_code(401);
                return;
            }
        }

        $song = Database::fetchOne(
            'SELECT s.path, s.title, ar.name AS artist_name FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE s.id = ?',
            [$songId]
        );
        if ($song === null) {
            // Sygnalizujemy frontend custom header że song nie istnieje + 410 Gone (frontend usuwa tr).
            http_response_code(410);
            header('X-Song-Missing: 1');
            header('Cache-Control: no-store');
            return;
        }

        self::disableCompression();

        $cacheDir = \Doniixify\Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        $remoteCover = $cacheDir . '/remote-' . $songId . '.jpg';
        $missMarker = $cacheDir . '/remote-' . $songId . '.miss';
        $missAge = is_file($missMarker) ? time() - filemtime($missMarker) : PHP_INT_MAX;
        $forceRefresh = isset($_GET['refresh']);
        $isDebug = isset($_GET['debug']);

        if ($isDebug) {
            $localPath = $cacheDir . '/' . md5($song['path']);
            $localExists = is_file($localPath);
            $localSize = $localExists ? filesize($localPath) : null;
            $localHead = $localExists ? bin2hex(substr((string)@file_get_contents($localPath, false, null, 0, 8), 0, 8)) : null;
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'song_id' => $songId,
                'title' => $song['title'],
                'artist' => $song['artist_name'],
                'cache_dir' => $cacheDir,
                'cache_dir_writable' => is_dir($cacheDir) && is_writable($cacheDir),
                'local_cover_md5_path' => $localPath,
                'local_cover_exists' => $localExists,
                'local_cover_size' => $localSize,
                'local_cover_head_hex' => $localHead,
                'local_cover_resolved' => Scanner::coverPathFor($song['path']),
                'remote_cover_path' => $remoteCover,
                'remote_cover_exists' => is_file($remoteCover),
                'remote_cover_size' => is_file($remoteCover) ? filesize($remoteCover) : null,
                'miss_marker_exists' => is_file($missMarker),
                'miss_age_seconds' => is_file($missMarker) ? $missAge : null,
                'cleaned_title' => self::stripJunk((string)$song['title']),
                'cleaned_artist' => self::stripJunk((string)($song['artist_name'] ?? '')),
                'itunes_test' => self::lookupItunesCover(self::stripJunk((string)$song['title']), self::stripJunk((string)($song['artist_name'] ?? ''))),
                'deezer_test' => self::lookupDeezerCover(self::stripJunk((string)$song['title']), self::stripJunk((string)($song['artist_name'] ?? ''))),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return;
        }

        $cover = Scanner::coverPathFor($song['path']);
        if ($cover !== null) {
            $etag = '"' . md5($cover['path'] . filemtime($cover['path']) . filesize($cover['path'])) . '"';
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                http_response_code(304);
                header('Cache-Control: public, max-age=2592000, immutable');
                header('ETag: ' . $etag);
                return;
            }
            header('Content-Type: ' . $cover['mime']);
            header('Cache-Control: public, max-age=2592000, immutable');
            header('ETag: ' . $etag);
            header('Content-Length: ' . filesize($cover['path']));
            readfile($cover['path']);
            return;
        }

        $shouldFetch = !is_file($remoteCover) && ($missAge > 3600 || $forceRefresh);
        if ($shouldFetch) {
            if ($forceRefresh) @unlink($missMarker);
            $fetched = self::fetchRemoteCover((string)$song['title'], (string)($song['artist_name'] ?? ''), $cacheDir, $songId);
            if ($fetched !== null) {
                $remoteCover = $fetched;
                @unlink($missMarker);
            } else {
                @touch($missMarker);
            }
        }
        if (is_file($remoteCover) && filesize($remoteCover) > 100) {
            $etag = '"' . md5($remoteCover . filemtime($remoteCover) . filesize($remoteCover)) . '"';
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
                http_response_code(304);
                header('Cache-Control: public, max-age=2592000, immutable');
                header('ETag: ' . $etag);
                return;
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: public, max-age=2592000, immutable');
            header('ETag: ' . $etag);
            header('Content-Length: ' . filesize($remoteCover));
            readfile($remoteCover);
            return;
        }

        http_response_code(404);
        header('Content-Type: text/plain');
        header('Cache-Control: no-store');
        header('X-No-Cover: 1');
        echo 'No cover available';
    }

    private static function fetchRemoteCover(string $title, string $artist, string $cacheDir, int $songId): ?string
    {
        if ($title === '') return null;
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        if (!is_writable($cacheDir)) { error_log("Doniixify: cover cache dir not writable: {$cacheDir}"); return null; }

        $cleanTitle = self::stripJunk($title);
        $cleanArtist = self::stripJunk($artist);

        $url = null;
        if ($cleanArtist !== '') $url = self::lookupItunesCover($cleanTitle, $cleanArtist);
        if ($url === null && $cleanArtist !== '') $url = self::lookupDeezerCover($cleanTitle, $cleanArtist);
        if ($url === null && $cleanArtist !== '') $url = self::lookupSpotifyCover($cleanTitle, $cleanArtist);
        if ($url === null && $cleanArtist !== '') $url = self::lookupItunesCoverPlain($cleanTitle, $cleanArtist);
        if ($url === null && $cleanArtist !== '') $url = self::lookupDeezerCover($title, $artist);
        if ($url === null) $url = self::lookupItunesCover($cleanTitle, '');
        if ($url === null) return null;

        $data = self::httpGet($url, 8);
        if ($data === null || strlen($data) < 1000) return null;

        $target = $cacheDir . '/remote-' . $songId . '.jpg';
        @file_put_contents($target, $data);
        return is_file($target) ? $target : null;
    }

    private static function stripJunk(string $s): string
    {
        $s = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', '', $s);
        $s = preg_replace('/\b(official|video|music|lyric|lyrics|audio|hd|hq|feat\.?|ft\.?)\b/iu', '', $s);
        $s = preg_replace('/\?+/u', '', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    private static function lookupItunesCover(string $title, string $artist): ?string
    {
        $term = urlencode($artist . ' ' . $title);
        $url = "https://itunes.apple.com/search?media=music&entity=song&limit=1&term={$term}";
        $body = self::httpGet($url, 5);
        if ($body === null) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['results'][0]['artworkUrl100'])) return null;
        $art = (string)$data['results'][0]['artworkUrl100'];
        return str_replace('100x100bb', '600x600bb', $art);
    }

    private static function lookupItunesCoverPlain(string $title, string $artist): ?string
    {
        $term = urlencode($title . ' ' . $artist);
        $url = "https://itunes.apple.com/search?media=music&entity=song&limit=5&term={$term}";
        $body = self::httpGet($url, 5);
        if ($body === null) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['results'])) return null;
        foreach ($data['results'] as $r) {
            if (!empty($r['artworkUrl100'])) {
                return str_replace('100x100bb', '600x600bb', (string)$r['artworkUrl100']);
            }
        }
        return null;
    }

    private static function lookupDeezerCover(string $title, string $artist): ?string
    {
        $q = urlencode('artist:"' . $artist . '" track:"' . $title . '"');
        $url = "https://api.deezer.com/search?q={$q}&limit=1";
        $body = self::httpGet($url, 5);
        if ($body === null) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data'][0]['album']['cover_xl'])) return null;
        return (string)$data['data'][0]['album']['cover_xl'];
    }

    private static function lookupSpotifyCover(string $title, string $artist): ?string
    {
        try {
            if (!class_exists('\\Doniixify\\Downloader\\SpotifyApi')) return null;
            $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
            if (!$token) return null;
            $q = urlencode($artist . ' ' . $title);
            $url = "https://api.spotify.com/v1/search?type=track&limit=1&q={$q}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            $data = json_decode((string)$body, true);
            return (string)($data['tracks']['items'][0]['album']['images'][0]['url'] ?? '') ?: null;
        } catch (\Throwable $e) { return null; }
    }

    private static function httpGet(string $url, int $timeout = 5): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT => 'Doniixify/0.1',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($status === 200 && is_string($body)) ? $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'header' => "User-Agent: Doniixify/0.1\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }

    private static function sendPlaceholder(string $label): void
    {
        $letter = strtoupper(mb_substr(trim($label), 0, 1, 'UTF-8') ?: '?');
        $letter = htmlspecialchars($letter, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $hash = crc32($label);
        $h1 = ($hash & 0xFF);
        $h2 = (($hash >> 8) & 0xFF);
        $hue1 = $h1 % 360;
        $hue2 = ($hue1 + 40 + ($h2 % 60)) % 360;

        $svg = <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200" viewBox="0 0 200 200">
<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
<stop offset="0%" stop-color="hsl({$hue1},55%,40%)"/>
<stop offset="100%" stop-color="hsl({$hue2},65%,25%)"/>
</linearGradient></defs>
<rect width="200" height="200" fill="url(#g)"/>
<text x="100" y="125" font-family="Inter,system-ui,sans-serif" font-size="100" font-weight="800" fill="rgba(255,255,255,0.85)" text-anchor="middle">{$letter}</text>
</svg>
SVG;
        header('Content-Type: image/svg+xml; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $svg;
    }

    private static function sendFile(string $path, string $mime): void
    {
        $size = filesize($path);
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            http_response_code(500);
            return;
        }

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=3600');

        $range = $_SERVER['HTTP_RANGE'] ?? null;
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] === '' ? 0 : (int)$m[1];
            $end = $m[2] === '' ? $size - 1 : (int)$m[2];
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */{$size}");
                fclose($fp);
                return;
            }
            $end = min($end, $size - 1);
            $length = $end - $start + 1;

            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header('Content-Length: ' . $length);

            fseek($fp, $start);
            $chunkSize = 262144;
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $read = fread($fp, min($chunkSize, $remaining));
                if ($read === false || $read === '') break;
                echo $read;
                $remaining -= strlen($read);
            }
            fclose($fp);
            return;
        }

        header('Content-Length: ' . $size);
        fpassthru($fp);
        fclose($fp);
    }
}
