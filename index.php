<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Doniixify\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Doniixify\Env;
use Doniixify\Installer;
use Doniixify\Migrator;
use Doniixify\Router;
use Doniixify\Controllers\SystemController;
use Doniixify\Web\LoginView;
use Doniixify\Web\ScanView;
use Doniixify\Web\Session;
use Doniixify\Web\SettingsView;
use Doniixify\Web\Views;

Env::load(__DIR__ . '/.env');

if (Env::bool('APP_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

set_exception_handler(function (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    if (Env::bool('APP_DEBUG')) {
        echo "Exception: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
    } else {
        echo "Internal server error.";
    }
});

if (Installer::isNeeded()) {
    Installer::run();
    exit;
}

Migrator::runPending();

if (mt_rand(1, 50) === 1) {
    register_shutdown_function(function () {
        try {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            \Doniixify\Downloader\YoutubeDownloader::processPendingQueue();
        } catch (\Throwable $e) {}
    });
}

// Bootstrap: auto-create storage folders + diagnostyka błędów do bootstrap.log
(function () {
    $root = __DIR__;
    $flagFile = $root . '/storage/.bootstrap-done';
    if (is_file($flagFile)) return;

    $musicPath = (string)Env::get('MUSIC_PATH', $root . '/storage/music');
    $folders = [
        $root . '/storage',
        $root . '/storage/covers',
        $root . '/storage/cache',
        $root . '/storage/icons',
        $root . '/storage/converter/tmp',
        $root . '/storage/tmp-dl',
        dirname($musicPath),
        $musicPath,
    ];

    $errors = [];
    foreach ($folders as $f) {
        if ($f === '' || $f === '/') continue;
        if (is_dir($f)) {
            if (!is_writable($f)) @chmod($f, 0775);
            continue;
        }
        $err = null;
        set_error_handler(function ($_, $msg) use (&$err) { $err = $msg; }, E_WARNING | E_NOTICE);
        $ok = mkdir($f, 0775, true);
        restore_error_handler();
        if (!$ok && !is_dir($f)) {
            $errors[] = "{$f} parent=" . dirname($f) . " parent_writable=" . (is_writable(dirname($f)) ? '1' : '0') . " err=" . ($err ?? 'unknown');
        } else {
            @chmod($f, 0775);
        }
    }

    if (is_dir($root . '/storage') && is_writable($root . '/storage')) {
        if (!empty($errors)) {
            @file_put_contents(
                $root . '/storage/bootstrap.log',
                '[' . date('Y-m-d H:i:s') . "] " . implode(' || ', $errors) . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
        // Flag tylko gdy MUSIC_PATH faktycznie istnieje i jest writable
        if (is_dir($musicPath) && is_writable($musicPath)) {
            @file_put_contents($flagFile, date('Y-m-d H:i:s'));
        }
    }
})();

$router = new Router();

// === Subsonic API ===
use Doniixify\Subsonic\Library;

$router->any('/rest/ping', [SystemController::class, 'ping']);
$router->any('/rest/getLicense', [SystemController::class, 'getLicense']);
$router->any('/rest/getOpenSubsonicExtensions', [SystemController::class, 'getOpenSubsonicExtensions']);
$router->any('/rest/getUser', [Library::class, 'getUser']);
$router->any('/rest/getMusicFolders', [Library::class, 'getMusicFolders']);
$router->any('/rest/getGenres', [Library::class, 'getGenres']);
$router->any('/rest/getIndexes', [Library::class, 'getIndexes']);
$router->any('/rest/getArtists', [Library::class, 'getArtists']);
$router->any('/rest/getArtist', [Library::class, 'getArtist']);
$router->any('/rest/getAlbum', [Library::class, 'getAlbum']);
$router->any('/rest/getSong', [Library::class, 'getSong']);
$router->any('/rest/getMusicDirectory', [Library::class, 'getMusicDirectory']);
$router->any('/rest/getAlbumList', [Library::class, 'getAlbumList']);
$router->any('/rest/getAlbumList2', [Library::class, 'getAlbumList2']);
$router->any('/rest/getRandomSongs', [Library::class, 'getRandomSongs']);
$router->any('/rest/getSongsByGenre', [Library::class, 'getSongsByGenre']);
$router->any('/rest/search2', [Library::class, 'search2']);
$router->any('/rest/search3', [Library::class, 'search3']);
$router->any('/rest/getStarred', [Library::class, 'getStarred']);
$router->any('/rest/getStarred2', [Library::class, 'getStarred2']);
$router->any('/rest/star', [Library::class, 'star']);
$router->any('/rest/unstar', [Library::class, 'unstar']);
$router->any('/rest/scrobble', [Library::class, 'scrobble']);
$router->any('/rest/getPlaylists', [Library::class, 'getPlaylists']);
$router->any('/rest/getPlaylist', [Library::class, 'getPlaylist']);
$router->any('/rest/stream', [Library::class, 'stream']);
$router->any('/rest/download', [Library::class, 'download']);
$router->any('/rest/getCoverArt', [Library::class, 'getCoverArt']);
$router->any('/rest/getLyrics', [Library::class, 'getLyrics']);
$router->any('/rest/getArtistInfo2', [Library::class, 'getArtistInfo2']);
$router->any('/rest/getAlbumInfo2', [Library::class, 'getAlbumInfo2']);
$router->any('/rest/getLicense', [Library::class, 'getLicense']);
$router->any('/rest/createPlaylist', [Library::class, 'createPlaylist']);
$router->any('/rest/updatePlaylist', [Library::class, 'updatePlaylist']);
$router->any('/rest/deletePlaylist', [Library::class, 'deletePlaylist']);
$router->any('/rest/getNowPlaying', [Library::class, 'getNowPlaying']);
$router->any('/rest/setRating', [Library::class, 'setRating']);
$router->any('/rest/getTopSongs', [Library::class, 'getTopSongs']);
$router->any('/rest/getSimilarSongs2', [Library::class, 'getSimilarSongs2']);
$router->any('/rest/getSimilarSongs', [Library::class, 'getSimilarSongs2']);
$router->any('/rest/savePlayQueue', [Library::class, 'savePlayQueue']);
$router->any('/rest/getPlayQueue', [Library::class, 'getPlayQueue']);
$router->any('/rest/getBookmarks', [Library::class, 'getBookmarks']);
$router->any('/rest/createBookmark', [Library::class, 'createBookmark']);
$router->any('/rest/deleteBookmark', [Library::class, 'deleteBookmark']);
$router->any('/rest/getAlbumList', [Library::class, 'getAlbumList']);
$router->any('/rest/getAlbumList2', [Library::class, 'getAlbumList2']);
$router->any('/rest/getRandomSongs', [Library::class, 'getRandomSongs']);
$router->any('/rest/getSongsByGenre', [Library::class, 'getSongsByGenre']);
$router->any('/rest/search2', [Library::class, 'search2']);
$router->any('/rest/search3', [Library::class, 'search3']);
$router->any('/rest/getStarred', [Library::class, 'getStarred']);
$router->any('/rest/getStarred2', [Library::class, 'getStarred2']);
$router->any('/rest/getMusicDirectory', [Library::class, 'getMusicDirectory']);
$router->any('/rest/getArtist', [Library::class, 'getArtist']);
$router->any('/rest/getAlbum', [Library::class, 'getAlbum']);
$router->any('/rest/getSong', [Library::class, 'getSong']);

// === WEB UI ===
$router->any('/login', function () {
    if (Session::isLogged()) {
        header('Location: /');
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        $user = Session::login($u, $p);
        if ($user !== null) {
            header('Location: /');
            exit;
        }
        LoginView::render('Invalid username or password.');
        return;
    }
    LoginView::render();
});

$router->get('/logout', function () {
    Session::logout();
    header('Location: /login');
    exit;
});

$router->get('/', [Views::class, 'home']);
$router->get('/search', [Views::class, 'search']);
$router->get('/favorites', [Views::class, 'libraryLiked']);
$router->get('/library', [Views::class, 'library']);
$router->get('/library/liked', [Views::class, 'libraryLiked']);
$router->get('/users', function () { header('Location: /settings'); exit; });
$router->post('/scan', [ScanView::class, 'run']);
$router->get('/settings', [SettingsView::class, 'index']);
$router->post('/settings/password', [SettingsView::class, 'changePassword']);
$router->post('/settings/clear-covers', [SettingsView::class, 'clearCovers']);
$router->post('/settings/refresh-durations', [SettingsView::class, 'refreshDurations']);

$router->get('/api/spotify/search', [\Doniixify\Web\SpotifyController::class, 'search']);
$router->get('/api/spotify/cover', [\Doniixify\Web\SpotifyController::class, 'cover']);
$router->post('/api/spotify/download', [\Doniixify\Web\SpotifyController::class, 'download']);
$router->post('/api/spotify/notify-scan', [\Doniixify\Web\SpotifyController::class, 'notifyScan']);
$router->get('/api/downloads/status', [\Doniixify\Web\SpotifyController::class, 'downloadsStatus']);

$router->get('/api/logs/errors', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $dir = __DIR__ . '/storage';
    $sources = [];
    foreach ((glob($dir . '/*.log') ?: []) as $path) {
        $name = basename($path, '.log');
        $sources[$name] = $path;
    }
    $maxSize = 10 * 1024 * 1024;
    foreach ($sources as $path) {
        if (is_file($path) && filesize($path) > $maxSize) {
            @rename($path, $path . '.1');
            @touch($path);
        }
    }
    $items = [];
    $cutoff = date('Y-m-d H:i:s', time() - 86400);
    $tailSize = 1024 * 1024;
    foreach ($sources as $src => $path) {
        if (!is_file($path)) continue;
        $size = filesize($path);
        $offset = max(0, $size - $tailSize);
        $fp = @fopen($path, 'r');
        if (!$fp) continue;
        @fseek($fp, $offset);
        $body = stream_get_contents($fp, $tailSize) ?: '';
        @fclose($fp);
        foreach (explode("\n", $body) as $line) {
            if (!preg_match('/\b(FAIL|ERROR|Failed|killed|exception)\b|HTTP\s+[45]\d\d/i', $line)) continue;
            if (str_contains($line, 'writeTags FAIL')) continue;
            if (str_contains($line, '--ignore-errors') || str_contains($line, '--no-abort-on-error') || str_contains($line, 'yt-dlp run:')) continue;
            if (preg_match('/^\[([\d\- :]+)\]\s+(.+)$/', $line, $m)) {
                $ts = trim($m[1]);
                if (strlen($ts) >= 19 && $ts < $cutoff) continue;
                $items[] = ['source' => $src, 'ts' => $ts, 'message' => trim($m[2])];
            }
        }
    }
    usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
    echo json_encode(['errors' => array_slice($items, 0, 100)]);
});

$router->get('/api/me/last-played', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $uid = (int)$user['id'];
    $row = \Doniixify\Database::fetchOne(
        'SELECT s.id, s.title, s.duration, usp.last_played_at,
                ar.id AS artist_id, ar.name AS artist_name,
                al.id AS album_id, al.name AS album_name
         FROM user_song_plays usp
         JOIN songs s ON s.id = usp.song_id
         JOIN artists ar ON ar.id = s.artist_id
         LEFT JOIN albums al ON al.id = s.album_id
         WHERE usp.user_id = ? AND s.downloaded_by = ?
         ORDER BY usp.last_played_at DESC
         LIMIT 1',
        [$uid, $uid]
    );
    if (!$row) {
        $row = \Doniixify\Database::fetchOne(
            'SELECT s.id, s.title, s.duration, ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN albums al ON al.id = s.album_id
             WHERE s.downloaded_by = ?
             ORDER BY s.play_count DESC, s.id DESC
             LIMIT 1',
            [$uid]
        );
    }
    echo json_encode($row ?: null);
});

$router->post('/api/playback/command', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $token = (string)($_GET['token'] ?? '');
    if ($token === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+([A-Za-z0-9_-]{16,})/i', $auth, $m)) $token = $m[1];
    }
    $user = null;
    if ($token !== '' && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
        $user = \Doniixify\Database::fetchOne('SELECT id FROM users WHERE api_token = ? LIMIT 1', [$token]);
    }
    if ($user === null) {
        if (!\Doniixify\Web\Session::isLogged()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }
        $user = \Doniixify\Web\Session::user();
    }
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $cmd = strtolower(trim((string)($body['cmd'] ?? '')));
    $allowed = ['play', 'pause', 'toggle', 'next', 'prev', 'like', 'volume_up', 'volume_down'];
    if (!in_array($cmd, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid command', 'allowed' => $allowed]);
        return;
    }
    \Doniixify\Database::execute(
        'INSERT INTO playback_commands (user_id, command) VALUES (?, ?)',
        [(int)$user['id'], $cmd]
    );
    echo json_encode(['ok' => true]);
});

$router->get('/api/me/token', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $user = \Doniixify\Web\Session::user();
    $row = \Doniixify\Database::fetchOne('SELECT api_token FROM users WHERE id = ?', [(int)$user['id']]);
    $token = $row['api_token'] ?? null;
    if (!$token) {
        $token = bin2hex(random_bytes(24));
        \Doniixify\Database::execute('UPDATE users SET api_token = ? WHERE id = ?', [$token, (int)$user['id']]);
    }
    echo json_encode(['ok' => true, 'token' => $token]);
});

$router->post('/api/me/token/rotate', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $user = \Doniixify\Web\Session::user();
    $token = bin2hex(random_bytes(24));
    \Doniixify\Database::execute('UPDATE users SET api_token = ? WHERE id = ?', [$token, (int)$user['id']]);
    echo json_encode(['ok' => true, 'token' => $token]);
});

$router->post('/api/me/token/revoke', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $user = \Doniixify\Web\Session::user();
    \Doniixify\Database::execute('UPDATE users SET api_token = NULL WHERE id = ?', [(int)$user['id']]);
    echo json_encode(['ok' => true]);
});

$router->get('/api/now-playing', function () {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    $token = (string)($_GET['token'] ?? '');
    if ($token === '') {
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+([A-Za-z0-9_-]{16,})/i', $auth, $m)) $token = $m[1];
    }
    $userRow = null;
    if ($token !== '' && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token)) {
        $userRow = \Doniixify\Database::fetchOne('SELECT id FROM users WHERE api_token = ? LIMIT 1', [$token]);
    }
    if ($userRow === null) {
        if (!\Doniixify\Web\Session::isLogged()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized — pass ?token= or login']);
            return;
        }
        $userRow = \Doniixify\Web\Session::user();
    }
    $user = $userRow;

    $row = \Doniixify\Database::fetchOne(
        'SELECT ad.current_song_id, ad.current_position, ad.is_playing, ad.playing_since,
                TIMESTAMPDIFF(SECOND, ad.position_updated_at, NOW()) AS pos_age_s,
                s.title, s.duration, s.album_id, ar.name AS artist, al.name AS album
         FROM active_devices ad
         LEFT JOIN songs s ON s.id = ad.current_song_id
         LEFT JOIN artists ar ON ar.id = s.artist_id
         LEFT JOIN albums al ON al.id = s.album_id
         WHERE ad.user_id = ? AND ad.last_seen > NOW() - INTERVAL 30 SECOND
         ORDER BY ad.is_playing DESC, ad.last_seen DESC
         LIMIT 1',
        [(int)$user['id']]
    );
    if (!$row || empty($row['current_song_id'])) {
        echo json_encode(['playing' => false, 'song' => null]);
        return;
    }
    $position = (float)($row['current_position'] ?? 0);
    if (!empty($row['is_playing']) && $row['pos_age_s'] !== null) {
        $position += (float)$row['pos_age_s'];
    }
    $duration = (int)($row['duration'] ?? 0);
    $startsAt = !empty($row['is_playing']) ? (time() - (int)$position) : null;
    $endsAt = ($startsAt !== null && $duration > 0) ? ($startsAt + $duration) : null;
    $base = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $coverSuffix = $token !== '' ? '?token=' . urlencode($token) : '';
    echo json_encode([
        'playing' => !empty($row['is_playing']),
        'song' => [
            'id' => (int)$row['current_song_id'],
            'title' => (string)($row['title'] ?? 'Unknown'),
            'artist' => (string)($row['artist'] ?? ''),
            'album' => (string)($row['album'] ?? ''),
            'duration' => $duration,
            'position' => (int)$position,
            'cover_url' => $base . '/cover/' . (int)$row['current_song_id'] . $coverSuffix,
            'stream_url' => $base . '/stream/' . (int)$row['current_song_id'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ],
    ]);
});

$router->post('/api/downloads/clear', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $removed = \Doniixify\Downloader\JobTracker::clear();
    echo json_encode(['ok' => true, 'removed' => $removed]);
});

$router->post('/api/logs/clear', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    $dir = __DIR__ . '/storage';
    $cleared = [];
    foreach ((glob($dir . '/*.log') ?: []) as $path) {
        if (@file_put_contents($path, '') !== false) $cleared[] = basename($path);
    }
    foreach ((glob($dir . '/*.log.*') ?: []) as $path) {
        if (@unlink($path)) $cleared[] = basename($path);
    }
    echo json_encode(['ok' => true, 'cleared' => $cleared]);
});

$router->get('/api/artist-image', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: public, max-age=86400');
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') { echo json_encode(['url' => null]); return; }
    $cacheDir = __DIR__ . '/storage/cache/artist-img';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/' . md5(mb_strtolower($name)) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400 * 30) {
        echo @file_get_contents($cacheFile);
        return;
    }
    $url = null;
    try {
        $api = 'https://api.deezer.com/search/artist?limit=1&q=' . rawurlencode($name);
        $ch = curl_init($api);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'Doniixify/0.1',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($st === 200 && is_string($body)) {
            $j = json_decode($body, true);
            if (is_array($j) && !empty($j['data'][0])) {
                $a = $j['data'][0];
                $url = $a['picture_xl'] ?? $a['picture_big'] ?? $a['picture_medium'] ?? $a['picture'] ?? null;
            }
        }
    } catch (\Throwable $_) {}
    $out = json_encode(['url' => $url]);
    @file_put_contents($cacheFile, $out);
    echo $out;
});

$router->post('/api/logs/client', function () {
    header('Content-Type: application/json; charset=UTF-8');
    $raw = @file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) { echo json_encode(['ok' => false]); return; }
    $kind = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($data['kind'] ?? 'client'));
    if ($kind === '') $kind = 'client';
    $message = mb_substr((string)($data['message'] ?? ''), 0, 800);
    $context = mb_substr((string)($data['context'] ?? ''), 0, 200);
    $dir = __DIR__ . '/storage';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $line = '[' . date('Y-m-d H:i:s') . '] [client/' . $kind . '] ' . $message;
    if ($context !== '') $line .= ' (' . $context . ')';
    @file_put_contents($dir . '/import.log', $line . "\n", FILE_APPEND);
    echo json_encode(['ok' => true]);
});

$router->get('/api/app-config', function () {
    header('Content-Type: application/json; charset=UTF-8');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=300');
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'music.leszczynowa5.pl');
    $base = $proto . '://' . $host;
    echo json_encode([
        'name' => Env::get('APP_NAME', 'Doniixify'),
        'short_name' => Env::get('APP_NAME', 'Doniixify'),
        'version' => Env::get('APP_VERSION', '0.1.0'),
        'url' => $base . '/',
        'manifest_url' => $base . '/manifest.json',
        'icon_192_url' => $base . '/app/icons/icon-192.png',
        'icon_512_url' => $base . '/app/icons/icon-512.png',
        'icon_512_maskable_url' => $base . '/app/icons/icon-512-maskable.png',
        'theme_color' => '#0a0a0d',
        'background_color' => '#0a0a0d',
        'package_id' => 'pl.' . str_replace(['.', '-'], ['_', '_'], explode('.', $host)[0] ?? 'doniixify') . '.music.twa',
        'desktop' => [
            'window_width' => 1400,
            'window_height' => 900,
            'title' => Env::get('APP_NAME', 'Doniixify'),
        ],
        'android' => [
            'orientation' => 'any',
            'display_mode' => 'standalone',
            'status_bar_color' => '#0A0A0D',
            'splash_screen_color' => '#0A0A0D',
        ],
        'discord_client_id' => Env::get('DISCORD_CLIENT_ID', '1514072315493744840'),
        'discord_large_image' => Env::get('DISCORD_LARGE_IMAGE', 'doniixify_logo'),
    ], JSON_UNESCAPED_SLASHES);
});

$router->get('/icon-192.png', function () { \Doniixify\Web\IconView::render(192); });
$router->get('/icon-512.png', function () { \Doniixify\Web\IconView::render(512); });
$router->get('/icon-512-maskable.png', function () { \Doniixify\Web\IconView::render(512, true); });
$router->get('/api/icon/192', function () { \Doniixify\Web\IconView::render(192); });
$router->get('/api/icon/512', function () { \Doniixify\Web\IconView::render(512); });
$router->get('/api/icon/512m', function () { \Doniixify\Web\IconView::render(512, true); });
$router->get('/favicon.ico', function () { \Doniixify\Web\IconView::render(128); });
$router->get('/api/favicon', function () { \Doniixify\Web\IconView::render(128); });
$router->get('/manifest.json', function () {
    header('Content-Type: application/manifest+json; charset=UTF-8');
    readfile(__DIR__ . '/manifest.json');
});
$router->get('/sw.js', function () {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Service-Worker-Allowed: /');
    readfile(__DIR__ . '/sw.js');
});


$router->get('/api/lyrics', [\Doniixify\Web\LyricsController::class, 'get']);
$router->post('/api/lyrics/clear', [\Doniixify\Web\LyricsController::class, 'clearCache']);

$router->get('/api/lastfm/auth', [\Doniixify\Web\LastfmController::class, 'authStart']);
$router->get('/api/lastfm/callback', [\Doniixify\Web\LastfmController::class, 'authCallback']);
$router->post('/api/lastfm/scrobble', [\Doniixify\Web\LastfmController::class, 'scrobble']);
$router->post('/api/lastfm/now-playing', [\Doniixify\Web\LastfmController::class, 'nowPlaying']);
$router->get('/api/lastfm/status', [\Doniixify\Web\LastfmController::class, 'status']);
$router->post('/api/lastfm/disconnect', [\Doniixify\Web\LastfmController::class, 'disconnect']);
$router->get('/api/lastfm/discover', [\Doniixify\Web\LastfmController::class, 'discover']);
$router->post('/api/lastfm/queue-download', [\Doniixify\Web\LastfmController::class, 'queueDownload']);
$router->get('/api/lastfm/cover-lookup', [\Doniixify\Web\LastfmController::class, 'coverLookup']);

$router->get('/api/lyrics/translate', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=86400');
    $text = trim($_GET['text'] ?? '');
    $target = preg_replace('/[^a-z\-]/i', '', $_GET['target'] ?? 'en') ?: 'en';
    if ($text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing text']);
        return;
    }
    $maxLen = 1500;
    if (mb_strlen($text) > $maxLen) $text = mb_substr($text, 0, $maxLen);
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=auto&tl=' . urlencode($target) . '&dt=t&q=' . urlencode($text);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) {
            http_response_code(502);
            echo json_encode(['error' => 'translate upstream']);
            return;
        }
        $data = json_decode($body, true);
        $segments = $data[0] ?? [];
        $out = '';
        foreach ($segments as $seg) {
            if (is_array($seg) && isset($seg[0])) $out .= $seg[0];
        }
        echo json_encode(['translation' => $out, 'detected' => $data[2] ?? null]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/tags/songs', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $tag = strtolower(trim($_GET['tag'] ?? ''));
    if ($tag === '') { http_response_code(400); echo json_encode(['error' => 'Missing tag']); return; }
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT song_id FROM song_tags WHERE user_id = ? AND tag = ?',
            [(int)$user['id'], $tag]
        ) ?: [];
        echo json_encode(['song_ids' => array_map(fn($r) => (int)$r['song_id'], $rows)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/tags/list', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $songId = (int)($_GET['song_id'] ?? 0);
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS song_tags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                song_id INT NOT NULL,
                tag VARCHAR(80) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_tag (user_id, song_id, tag),
                INDEX idx_user_tag (user_id, tag),
                INDEX idx_song (song_id)
            )"
        );
        if ($songId > 0) {
            $rows = \Doniixify\Database::fetchAll('SELECT tag FROM song_tags WHERE user_id = ? AND song_id = ? ORDER BY tag', [$uid, $songId]) ?: [];
            echo json_encode(['tags' => array_column($rows, 'tag')]);
            return;
        }
        $rows = \Doniixify\Database::fetchAll(
            'SELECT tag, COUNT(*) AS n FROM song_tags WHERE user_id = ? GROUP BY tag ORDER BY n DESC LIMIT 100',
            [$uid]
        ) ?: [];
        echo json_encode(['tags' => $rows]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/tags/auto-detect', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $songs = \Doniixify\Database::fetchAll('SELECT id, title, file_path FROM songs LIMIT 5000') ?: [];
        $genreKeywords = [
            'rock', 'pop', 'jazz', 'blues', 'metal', 'punk', 'classical',
            'hiphop', 'rap', 'edm', 'house', 'techno', 'trance', 'dubstep',
            'indie', 'folk', 'country', 'reggae', 'soul', 'funk', 'disco',
            'ambient', 'lo-fi', 'lofi', 'chill', 'electronic', 'dance',
        ];
        $added = 0;
        foreach ($songs as $s) {
            $text = strtolower(($s['title'] ?? '') . ' ' . basename($s['file_path'] ?? ''));
            foreach ($genreKeywords as $kw) {
                if (str_contains($text, $kw)) {
                    \Doniixify\Database::execute(
                        'INSERT IGNORE INTO song_tags (user_id, song_id, tag) VALUES (?, ?, ?)',
                        [$uid, (int)$s['id'], $kw === 'lofi' ? 'lo-fi' : $kw]
                    );
                    $added++;
                }
            }
            if (preg_match('/\b(19|20)\d{2}\b/', $text, $m)) {
                $year = $m[0];
                $decade = substr($year, 0, 3) . '0s';
                \Doniixify\Database::execute(
                    'INSERT IGNORE INTO song_tags (user_id, song_id, tag) VALUES (?, ?, ?)',
                    [$uid, (int)$s['id'], $decade]
                );
            }
        }
        echo json_encode(['ok' => true, 'added' => $added]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/tags/bulk-add', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    $songIds = $data['song_ids'] ?? [];
    $tag = strtolower(trim((string)($data['tag'] ?? '')));
    $tag = preg_replace('/[^a-z0-9 \-]/i', '', $tag);
    if (!is_array($songIds) || empty($songIds) || $tag === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing song_ids or tag']);
        return;
    }
    try {
        $added = 0;
        foreach ($songIds as $sid) {
            $sid = (int)$sid;
            if ($sid <= 0) continue;
            try {
                \Doniixify\Database::execute(
                    'INSERT IGNORE INTO song_tags (user_id, song_id, tag) VALUES (?, ?, ?)',
                    [(int)$user['id'], $sid, $tag]
                );
                $added++;
            } catch (\Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'added' => $added, 'tag' => $tag]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/tags/add', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $songId = (int)($_POST['song_id'] ?? 0);
    $tag = strtolower(trim($_POST['tag'] ?? ''));
    $tag = preg_replace('/[^a-z0-9 \-]/i', '', $tag);
    if ($songId <= 0 || $tag === '' || mb_strlen($tag) > 80) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid song_id or tag']);
        return;
    }
    try {
        \Doniixify\Database::execute(
            'INSERT IGNORE INTO song_tags (user_id, song_id, tag) VALUES (?, ?, ?)',
            [(int)$user['id'], $songId, $tag]
        );
        echo json_encode(['ok' => true, 'tag' => $tag]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/tags/remove', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $songId = (int)($_POST['song_id'] ?? 0);
    $tag = strtolower(trim($_POST['tag'] ?? ''));
    if ($songId <= 0 || $tag === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid']);
        return;
    }
    try {
        \Doniixify\Database::execute(
            'DELETE FROM song_tags WHERE user_id = ? AND song_id = ? AND tag = ?',
            [(int)$user['id'], $songId, $tag]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => true]);
    }
});

$router->get('/api/smart-rules/list', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS smart_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                rules_json TEXT NOT NULL,
                refresh_days INT DEFAULT 7,
                last_run TIMESTAMP NULL,
                playlist_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            )"
        );
        $rows = \Doniixify\Database::fetchAll(
            'SELECT id, name, rules_json, refresh_days, last_run, playlist_id FROM smart_rules WHERE user_id = ? ORDER BY id DESC',
            [(int)$user['id']]
        ) ?: [];
        echo json_encode(['rules' => $rows]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/smart-rules/save', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    $name = trim((string)($data['name'] ?? ''));
    $rules = $data['rules'] ?? null;
    $refresh = max(1, min(30, (int)($data['refresh_days'] ?? 7)));
    if ($name === '' || !is_array($rules)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing name or rules']);
        return;
    }
    try {
        \Doniixify\Database::execute(
            'INSERT INTO smart_rules (user_id, name, rules_json, refresh_days) VALUES (?, ?, ?, ?)',
            [(int)$user['id'], $name, json_encode($rules), $refresh]
        );
        echo json_encode(['ok' => true, 'id' => (int)\Doniixify\Database::lastInsertId()]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/smart-rules/refresh-all', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT id, name, rules_json, refresh_days, last_run, playlist_id FROM smart_rules WHERE user_id = ?
             AND (last_run IS NULL OR last_run < DATE_SUB(NOW(), INTERVAL refresh_days DAY))',
            [$uid]
        ) ?: [];
        $refreshed = 0;
        foreach ($rows as $r) {
            $rules = json_decode($r['rules_json'], true);
            if (!is_array($rules)) continue;
            $name = $r['name'];
            $where = ['1=1'];
            $args = [];
            if (!empty($rules['min_plays'])) { $where[] = 's.play_count >= ?'; $args[] = (int)$rules['min_plays']; }
            if (!empty($rules['min_duration'])) { $where[] = 's.duration >= ?'; $args[] = (int)$rules['min_duration']; }
            if (!empty($rules['liked_only'])) {
                $where[] = "s.id IN (SELECT item_id FROM stars WHERE user_id = ? AND item_type = 'song')";
                $args[] = $uid;
            }
            if (!empty($rules['tag'])) {
                $where[] = 's.id IN (SELECT song_id FROM song_tags WHERE user_id = ? AND tag = ?)';
                $args[] = $uid;
                $args[] = strtolower(trim((string)$rules['tag']));
            }
            $limit = max(1, min(100, (int)($rules['limit'] ?? 30)));
            $songs = \Doniixify\Database::fetchAll(
                'SELECT s.id FROM songs s WHERE ' . implode(' AND ', $where) . ' ORDER BY RAND() LIMIT ' . $limit,
                $args
            ) ?: [];
            if (count($songs) < 1) continue;
            $plId = (int)$r['playlist_id'];
            if (!$plId) {
                \Doniixify\Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $name]);
                $plId = (int)\Doniixify\Database::lastInsertId();
                \Doniixify\Database::execute('UPDATE smart_rules SET playlist_id = ? WHERE id = ?', [$plId, (int)$r['id']]);
            } else {
                \Doniixify\Database::execute('DELETE FROM playlist_songs WHERE playlist_id = ?', [$plId]);
            }
            $pos = 1;
            foreach ($songs as $s) {
                \Doniixify\Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$plId, (int)$s['id'], $pos++]);
            }
            \Doniixify\Database::execute('UPDATE smart_rules SET last_run = NOW() WHERE id = ?', [(int)$r['id']]);
            $refreshed++;
        }
        echo json_encode(['ok' => true, 'refreshed' => $refreshed]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/smart-playlist/preview', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $raw = file_get_contents('php://input') ?: '{}';
    $rules = json_decode($raw, true);
    if (!is_array($rules)) { http_response_code(400); echo json_encode(['error' => 'Invalid']); return; }
    $where = ['1=1'];
    $args = [];
    if (!empty($rules['artist_id'])) { $where[] = 's.artist_id = ?'; $args[] = (int)$rules['artist_id']; }
    if (!empty($rules['min_plays'])) { $where[] = 's.play_count >= ?'; $args[] = (int)$rules['min_plays']; }
    if (!empty($rules['max_plays'])) { $where[] = 's.play_count <= ?'; $args[] = (int)$rules['max_plays']; }
    if (!empty($rules['min_duration'])) { $where[] = 's.duration >= ?'; $args[] = (int)$rules['min_duration']; }
    if (!empty($rules['max_duration'])) { $where[] = 's.duration <= ?'; $args[] = (int)$rules['max_duration']; }
    if (!empty($rules['liked_only'])) {
        $where[] = "s.id IN (SELECT item_id FROM stars WHERE user_id = ? AND item_type = 'song')";
        $args[] = $uid;
    }
    if (!empty($rules['tag'])) {
        $where[] = 's.id IN (SELECT song_id FROM song_tags WHERE user_id = ? AND tag = ?)';
        $args[] = $uid;
        $args[] = strtolower(trim((string)$rules['tag']));
    }
    $limit = max(1, min(100, (int)($rules['limit'] ?? 30)));
    $orderBy = match ($rules['sort'] ?? 'random') {
        'plays_desc' => 's.play_count DESC',
        'recent' => 's.added_at DESC',
        'title' => 's.title ASC',
        default => 'RAND()',
    };
    try {
        $songs = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist_name FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit,
            $args
        ) ?: [];
        $total = \Doniixify\Database::fetchOne(
            'SELECT COUNT(*) AS n FROM songs s WHERE ' . implode(' AND ', $where),
            $args
        );
        echo json_encode([
            'songs' => $songs,
            'matching_count' => (int)($total['n'] ?? 0),
            'preview_count' => count($songs),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/smart-playlist/generate', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $raw = file_get_contents('php://input') ?: '{}';
    $rules = json_decode($raw, true);
    if (!is_array($rules)) { http_response_code(400); echo json_encode(['error' => 'Invalid rules']); return; }
    $name = trim((string)($rules['name'] ?? 'Smart playlist'));
    $where = ['1=1'];
    $args = [];
    if (!empty($rules['artist_id'])) { $where[] = 's.artist_id = ?'; $args[] = (int)$rules['artist_id']; }
    if (!empty($rules['min_plays'])) { $where[] = 's.play_count >= ?'; $args[] = (int)$rules['min_plays']; }
    if (!empty($rules['max_plays'])) { $where[] = 's.play_count <= ?'; $args[] = (int)$rules['max_plays']; }
    if (!empty($rules['min_duration'])) { $where[] = 's.duration >= ?'; $args[] = (int)$rules['min_duration']; }
    if (!empty($rules['max_duration'])) { $where[] = 's.duration <= ?'; $args[] = (int)$rules['max_duration']; }
    if (!empty($rules['liked_only'])) {
        $where[] = "s.id IN (SELECT item_id FROM stars WHERE user_id = ? AND item_type = 'song')";
        $args[] = $uid;
    }
    if (!empty($rules['tag'])) {
        $where[] = 's.id IN (SELECT song_id FROM song_tags WHERE user_id = ? AND tag = ?)';
        $args[] = $uid;
        $args[] = strtolower(trim((string)$rules['tag']));
    }
    $limit = max(1, min(100, (int)($rules['limit'] ?? 30)));
    $orderBy = match ($rules['sort'] ?? 'random') {
        'plays_desc' => 's.play_count DESC',
        'recent' => 's.added_at DESC',
        'title' => 's.title ASC',
        default => 'RAND()',
    };
    try {
        $songs = \Doniixify\Database::fetchAll(
            'SELECT s.id FROM songs s WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit,
            $args
        ) ?: [];
        if (count($songs) < 1) { echo json_encode(['error' => 'No matching songs']); return; }
        \Doniixify\Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $name]);
        $plId = (int)\Doniixify\Database::lastInsertId();
        $pos = 1;
        foreach ($songs as $s) {
            \Doniixify\Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$plId, (int)$s['id'], $pos++]);
        }
        echo json_encode(['playlist_id' => $plId, 'count' => count($songs), 'name' => $name]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/vibe-check', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $energy = (int)($_POST['energy'] ?? 3);
    $mood = (string)($_POST['mood'] ?? 'happy');
    $tempo = (string)($_POST['tempo'] ?? 'medium');
    $minDur = $tempo === 'fast' ? 0 : ($tempo === 'slow' ? 180 : 0);
    $tagMap = [
        'happy' => ['pop', 'indie pop', 'feel good', 'happy'],
        'sad' => ['sad', 'acoustic', 'ballad', 'melancholy'],
        'angry' => ['metal', 'rock', 'punk', 'aggressive'],
        'chill' => ['chill', 'lo-fi', 'ambient', 'jazz'],
        'romantic' => ['romantic', 'love', 'soul', 'r&b'],
        'energetic' => ['edm', 'electronic', 'dance', 'hiphop'],
    ];
    $candidateTags = $tagMap[$mood] ?? ['pop'];
    try {
        $songs = \Doniixify\Database::fetchAll(
            'SELECT DISTINCT s.id FROM songs s
             LEFT JOIN song_tags st ON st.song_id = s.id AND st.user_id = ? AND st.tag IN (' . implode(',', array_fill(0, count($candidateTags), '?')) . ')
             WHERE (st.id IS NOT NULL OR s.play_count > ?)
               AND s.duration >= ?
             ORDER BY (st.id IS NOT NULL) DESC, RAND()
             LIMIT 25',
            array_merge([$uid], $candidateTags, [$energy >= 4 ? 0 : 5, $minDur])
        ) ?: [];
        if (count($songs) < 1) {
            $songs = \Doniixify\Database::fetchAll('SELECT id FROM songs ORDER BY RAND() LIMIT 25') ?: [];
        }
        $name = 'Vibe: ' . ucfirst($mood) . ' · ' . date('M j H:i');
        \Doniixify\Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $name]);
        $plId = (int)\Doniixify\Database::lastInsertId();
        $pos = 1;
        foreach ($songs as $s) {
            \Doniixify\Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$plId, (int)$s['id'], $pos++]);
        }
        echo json_encode(['ok' => true, 'playlist_id' => $plId, 'name' => $name, 'count' => count($songs)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/todays-mood', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $hour = (int)date('G');
    $mood = $hour < 7 ? 'late_night' : ($hour < 11 ? 'morning' : ($hour < 14 ? 'noon' : ($hour < 18 ? 'afternoon' : ($hour < 22 ? 'evening' : 'night'))));
    $artistFilter = '';
    try {
        $favArtists = \Doniixify\Database::fetchAll(
            'SELECT ar.id, COUNT(*) AS plays
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
               AND HOUR(usp.last_played_at) BETWEEN ? AND ?
             GROUP BY ar.id ORDER BY plays DESC LIMIT 10',
            [$uid, max(0, $hour - 2), min(23, $hour + 2)]
        ) ?: [];
        $ids = array_map(fn($r) => (int)$r['id'], $favArtists);
        if (!empty($ids)) {
            $picks = \Doniixify\Database::fetchAll(
                'SELECT s.id, s.title, ar.name AS artist_name, ar.id AS artist_id
                 FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE ar.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY RAND() LIMIT 20',
                $ids
            ) ?: [];
            echo json_encode(['mood' => $mood, 'hour' => $hour, 'items' => $picks]);
            return;
        }
        echo json_encode(['mood' => $mood, 'hour' => $hour, 'items' => []]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/discover-weekly/generate', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS discover_weekly (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                playlist_id INT NOT NULL,
                generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                week_start DATE NOT NULL,
                UNIQUE KEY uniq_week (user_id, week_start),
                INDEX idx_user (user_id)
            )"
        );
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $existing = \Doniixify\Database::fetchOne(
            'SELECT playlist_id FROM discover_weekly WHERE user_id = ? AND week_start = ?',
            [$uid, $weekStart]
        );
        if ($existing) {
            echo json_encode(['playlist_id' => (int)$existing['playlist_id'], 'cached' => true]);
            return;
        }
        $topArtists = \Doniixify\Database::fetchAll(
            'SELECT ar.id FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
             GROUP BY ar.id ORDER BY COUNT(*) DESC LIMIT 5',
            [$uid]
        ) ?: [];
        $artistIds = array_map(fn($r) => (int)$r['id'], $topArtists);
        if (!$artistIds) {
            echo json_encode(['error' => 'Not enough listening history']);
            return;
        }
        $heardRows = \Doniixify\Database::fetchAll('SELECT song_id FROM user_song_plays WHERE user_id = ?', [$uid]) ?: [];
        $heardIds = array_map(fn($r) => (int)$r['song_id'], $heardRows);
        $excludeSql = !empty($heardIds) ? 'AND s.id NOT IN (' . implode(',', array_fill(0, count($heardIds), '?')) . ')' : '';
        $picks = [];
        foreach ($artistIds as $artistId) {
            $rows = \Doniixify\Database::fetchAll(
                'SELECT s.id FROM songs s WHERE s.artist_id = ? ' . $excludeSql . ' ORDER BY RAND() LIMIT 6',
                array_merge([$artistId], $heardIds)
            ) ?: [];
            foreach ($rows as $r) $picks[] = (int)$r['id'];
        }
        $picks = array_unique($picks);
        shuffle($picks);
        $picks = array_slice($picks, 0, 30);
        if (count($picks) < 5) {
            echo json_encode(['error' => 'Not enough unheard tracks']);
            return;
        }
        $name = 'Discover Weekly · ' . date('M j', strtotime($weekStart));
        \Doniixify\Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $name]);
        $plId = (int)\Doniixify\Database::lastInsertId();
        $pos = 1;
        foreach ($picks as $songId) {
            \Doniixify\Database::execute(
                'INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)',
                [$plId, $songId, $pos++]
            );
        }
        \Doniixify\Database::execute(
            'INSERT INTO discover_weekly (user_id, playlist_id, week_start) VALUES (?, ?, ?)',
            [$uid, $plId, $weekStart]
        );
        echo json_encode([
            'playlist_id' => $plId,
            'cached' => false,
            'song_count' => count($picks),
            'name' => $name,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/profile-visibility', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $row = \Doniixify\Database::fetchOne('SELECT public_profile FROM user_prefs WHERE user_id = ?', [(int)$user['id']]);
        echo json_encode(['public' => $row ? (bool)$row['public_profile'] : false]);
    } catch (\Throwable $e) {
        echo json_encode(['public' => false]);
    }
});

$router->post('/api/me/profile-visibility', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $val = !empty($_POST['public']) ? 1 : 0;
    try {
        $cols = \Doniixify\Database::fetchAll("SHOW COLUMNS FROM user_prefs LIKE 'public_profile'") ?: [];
        if (empty($cols)) {
            \Doniixify\Database::execute('ALTER TABLE user_prefs ADD COLUMN public_profile TINYINT(1) DEFAULT 0');
        }
    } catch (\Throwable $e) {}
    try {
        \Doniixify\Database::execute(
            "INSERT INTO user_prefs (user_id, public_profile) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE public_profile = VALUES(public_profile)",
            [(int)$user['id'], $val]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/public-profile', function () {
    header('Content-Type: application/json');
    $username = trim((string)($_GET['username'] ?? ''));
    if ($username === '') { http_response_code(400); echo json_encode(['error' => 'Missing username']); return; }
    try {
        $u = \Doniixify\Database::fetchOne('SELECT id, username FROM users WHERE username = ?', [$username]);
        if (!$u) { http_response_code(404); echo json_encode(['error' => 'User not found']); return; }
        $pref = \Doniixify\Database::fetchOne('SELECT public_profile FROM user_prefs WHERE user_id = ?', [(int)$u['id']]);
        if (!$pref || empty($pref['public_profile'])) {
            echo json_encode(['username' => $username, 'private' => true]);
            return;
        }
        $uid = (int)$u['id'];
        $topArtists = \Doniixify\Database::fetchAll(
            'SELECT ar.name, COUNT(*) AS plays FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? GROUP BY ar.id, ar.name ORDER BY plays DESC LIMIT 5', [$uid]
        ) ?: [];
        $recent = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.name AS artist_name FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? ORDER BY usp.last_played_at DESC LIMIT 8', [$uid]
        ) ?: [];
        $totalSec = (int)(\Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(s.duration * usp.play_count),0) AS sec
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id WHERE usp.user_id = ?', [$uid]
        )['sec'] ?? 0);
        echo json_encode([
            'username' => $username,
            'private' => false,
            'top_artists' => $topArtists,
            'recent' => $recent,
            'total_hours' => round($totalSec / 3600, 1),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/highlights', function () {
    \Doniixify\Web\Session::requireLogin();
    $content = '<div class="page-content"><header class="page-header"><h1 class="page-title">Lyrics highlights</h1><div class="page-subtitle">Lines you saved</div></header>' .
        '<div id="highlights-body" style="margin-top:24px">Loading…</div>' .
        '<script>' .
        '(async function(){' .
            'const body = document.getElementById("highlights-body");' .
            'try {' .
                'const r = await fetch("/api/lyrics/highlights");' .
                'const d = await r.json();' .
                'if (!d || !d.highlights || !d.highlights.length) { body.innerHTML = "<div style=\"padding:40px;text-align:center;color:#888\">Long-press a lyric line in fullscreen lyrics to save it here.</div>"; return; }' .
                'const esc = (s) => String(s ?? "").replace(/[&<>\"\\\']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\\\'":"&#39;"}[c]));' .
                'body.innerHTML = d.highlights.map(h => "<a href=\"#\" data-id=\"" + h.song_id + "\" style=\"display:block;padding:18px;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:12px;margin-bottom:12px;text-decoration:none;color:inherit\"><div style=\"font-style:italic;font-size:17px;color:#fff;font-weight:600;line-height:1.5\">“" + esc(h.line) + "”</div><div style=\"margin-top:10px;font-size:12px;color:var(--text-muted)\">" + esc(h.title) + " — " + esc(h.artist_name) + " · " + (h.created_at || "").slice(0, 10) + "</div></a>").join("");' .
                'body.querySelectorAll("[data-id]").forEach(a => a.addEventListener("click", (e) => {' .
                    'e.preventDefault();' .
                    'const id = a.dataset.id;' .
                    'const row = document.querySelector("tr[data-song-id=\\\"" + id + "\\\"]");' .
                    'if (window.doniixify && window.doniixify.loadSong) window.doniixify.loadSong(Number(id), row?.dataset.title || "", row?.dataset.artist || "", true);' .
                '}));' .
            '} catch(e) { body.textContent = "Error"; }' .
        '})();' .
        '</script></div>';
    \Doniixify\Web\Layout::render('/highlights', $content);
});

$router->get('/wrap', function () {
    \Doniixify\Web\Session::requireLogin();
    $year = (int)($_GET['year'] ?? date('Y'));
    $content = '<div class="page-content"><header class="page-header"><h1 class="page-title">Your ' . $year . ' Wrap</h1><div class="page-subtitle">Year in music</div></header>' .
        '<div id="wrap-body" style="margin-top:24px">Loading…</div>' .
        '<script>' .
        '(async function(){' .
            'const body = document.getElementById("wrap-body");' .
            'try {' .
                'const r = await fetch("/api/me/wrap?year=' . $year . '");' .
                'const d = await r.json();' .
                'if (!d || d.error) { body.textContent = "No data."; return; }' .
                'const esc = (s) => String(s ?? "").replace(/[&<>\"\\\']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\\\'":"&#39;"}[c]));' .
                'let html = "";' .
                'html += "<div style=\"display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:32px\">";' .
                'html += "<div style=\"padding:24px;background:linear-gradient(135deg,rgba(var(--accent-rgb,30,215,96),0.25),rgba(var(--accent-rgb,30,215,96),0.05));border-radius:14px;border:1px solid rgba(var(--accent-rgb,30,215,96),0.3)\"><div style=\"font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px\">Total time</div><div style=\"font-size:36px;font-weight:900;color:#fff\">" + d.total_hours + "<span style=\"font-size:18px;color:var(--text-muted);margin-left:4px\">hours</span></div></div>";' .
                'html += "<div style=\"padding:24px;background:rgba(255,255,255,0.03);border-radius:14px;border:1px solid var(--border)\"><div style=\"font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px\">Songs played</div><div style=\"font-size:36px;font-weight:900;color:#fff\">" + d.total_plays + "</div></div>";' .
                'html += "<div style=\"padding:24px;background:rgba(255,255,255,0.03);border-radius:14px;border:1px solid var(--border)\"><div style=\"font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px\">Unique tracks</div><div style=\"font-size:36px;font-weight:900;color:#fff\">" + d.unique_tracks + "</div></div>";' .
                'html += "</div>";' .
                'if (d.top_song) {' .
                    'html += "<div style=\"margin-bottom:32px\"><h2 style=\"font-size:18px;font-weight:800;margin:0 0 12px\">Your #1 song</h2><a href=\"#\" data-w=\"" + d.top_song.id + "\" style=\"display:flex;gap:16px;align-items:center;padding:20px;background:rgba(255,255,255,0.03);border-radius:14px;border:1px solid var(--border);text-decoration:none;color:inherit\"><img src=\"/cover/" + d.top_song.id + "\" alt=\"\" style=\"width:100px;height:100px;border-radius:8px;object-fit:cover\" onerror=\"this.style.opacity=0.3\"><div><div style=\"font-size:22px;font-weight:800;color:#fff\">" + esc(d.top_song.title) + "</div><div style=\"font-size:15px;color:var(--text-secondary);margin-top:4px\">" + esc(d.top_song.artist_name) + "</div><div style=\"margin-top:8px;color:rgb(var(--accent-rgb,30,215,96));font-weight:700\">" + d.top_song.play_count + " plays</div></div></a></div>";' .
                '}' .
                'if ((d.top_artists || []).length) {' .
                    'html += "<div><h2 style=\"font-size:18px;font-weight:800;margin:0 0 12px\">Top 5 artists</h2>";' .
                    'd.top_artists.forEach((a, i) => {' .
                        'html += "<a href=\"/artist/" + a.id + "\" style=\"display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit\"><span><span style=\"display:inline-block;width:24px;color:rgb(var(--accent-rgb,30,215,96));font-weight:800\">" + (i+1) + "</span>" + esc(a.name) + "</span><span style=\"color:var(--text-muted)\">" + a.plays + " plays</span></a>";' .
                    '});' .
                    'html += "</div>";' .
                '}' .
                'body.innerHTML = html;' .
                'body.querySelectorAll("[data-w]").forEach(a => a.addEventListener("click", (e) => {' .
                    'e.preventDefault();' .
                    'const id = a.dataset.w;' .
                    'const row = document.querySelector("tr[data-song-id=\\\"" + id + "\\\"]");' .
                    'if (window.doniixify && window.doniixify.loadSong) window.doniixify.loadSong(Number(id), row?.dataset.title || "", row?.dataset.artist || "", true);' .
                '}));' .
                'const shareBtn = document.createElement("button");' .
                'shareBtn.textContent = "📸 Download share image";' .
                'shareBtn.className = "btn";' .
                'shareBtn.style.cssText = "background:rgb(var(--accent-rgb,30,215,96));color:#000;border:0;font-weight:700;margin-top:24px;padding:12px 24px";' .
                'shareBtn.addEventListener("click", async () => { if (window.__shareWrapImage) await window.__shareWrapImage(d); });' .
                'body.appendChild(shareBtn);' .
            '} catch(e) { body.textContent = "Error"; }' .
        '})();' .
        '</script></div>';
    \Doniixify\Web\Layout::render('/wrap', $content);
});

$router->get('/api/discover', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $heard = \Doniixify\Database::fetchAll(
            'SELECT song_id FROM user_song_plays WHERE user_id = ?',
            [$uid]
        ) ?: [];
        $heardIds = array_map(fn($r) => (int)$r['song_id'], $heard);
        $excludeSql = !empty($heardIds)
            ? 'AND s.id NOT IN (' . implode(',', array_fill(0, count($heardIds), '?')) . ')'
            : '';
        $args = !empty($heardIds) ? $heardIds : [];
        $trending = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist_name, ar.id AS artist_id,
                    COUNT(DISTINCT usp.user_id) AS unique_listeners, s.play_count
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN user_song_plays usp ON usp.song_id = s.id AND usp.last_played_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             WHERE 1=1 ' . $excludeSql . '
             GROUP BY s.id, s.title, s.duration, ar.name, ar.id, s.play_count
             ORDER BY unique_listeners DESC, s.play_count DESC
             LIMIT 10',
            $args
        ) ?: [];
        $topArtist = \Doniixify\Database::fetchOne(
            'SELECT ar.id, ar.name, COUNT(*) AS plays
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? GROUP BY ar.id, ar.name ORDER BY plays DESC LIMIT 1',
            [$uid]
        );
        $moreLike = [];
        if ($topArtist) {
            $moreLike = \Doniixify\Database::fetchAll(
                'SELECT s.id, s.title, s.duration, ar.name AS artist_name, ar.id AS artist_id
                 FROM songs s
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE ar.id = ? ' . str_replace('NOT IN', 'NOT IN', $excludeSql) . '
                 ORDER BY s.play_count DESC LIMIT 8',
                array_merge([(int)$topArtist['id']], $args)
            ) ?: [];
        }
        $randomGems = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist_name, ar.id AS artist_id
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             WHERE s.play_count > 0 ' . $excludeSql . '
             ORDER BY RAND() LIMIT 8',
            $args
        ) ?: [];
        echo json_encode([
            'trending' => $trending,
            'more_like' => $moreLike,
            'top_artist_name' => $topArtist['name'] ?? null,
            'random_gems' => $randomGems,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/me/session', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS listening_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ended_at TIMESTAMP NULL,
                track_count INT DEFAULT 0,
                INDEX idx_user_start (user_id, started_at)
            )"
        );
        $uid = (int)$user['id'];
        if ($action === 'start') {
            \Doniixify\Database::execute('UPDATE listening_sessions SET ended_at = NOW() WHERE user_id = ? AND ended_at IS NULL', [$uid]);
            \Doniixify\Database::execute('INSERT INTO listening_sessions (user_id) VALUES (?)', [$uid]);
            echo json_encode(['ok' => true, 'session_id' => (int)\Doniixify\Database::lastInsertId()]);
        } elseif ($action === 'end') {
            \Doniixify\Database::execute('UPDATE listening_sessions SET ended_at = NOW() WHERE user_id = ? AND ended_at IS NULL', [$uid]);
            echo json_encode(['ok' => true]);
        } elseif ($action === 'tick') {
            \Doniixify\Database::execute('UPDATE listening_sessions SET track_count = track_count + 1 WHERE user_id = ? AND ended_at IS NULL', [$uid]);
            echo json_encode(['ok' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/sessions', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT started_at, ended_at, track_count,
                    TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW())) AS duration_min
             FROM listening_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 30',
            [(int)$user['id']]
        ) ?: [];
        echo json_encode(['sessions' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['sessions' => []]);
    }
});

$router->get('/api/me/top-week', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.name AS artist_name, COUNT(*) AS plays
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? AND usp.last_played_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY s.id, s.title, ar.name
             ORDER BY plays DESC
             LIMIT 10',
            [$uid]
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/recently-played', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $limit = max(1, min(20, (int)($_GET['limit'] ?? 8)));
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name,
                    usp.last_played_at, usp.play_count
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN albums al ON al.id = s.album_id
             WHERE usp.user_id = ?
             ORDER BY usp.last_played_at DESC
             LIMIT ' . $limit,
            [$uid]
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/goal', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS user_prefs (
                user_id INT PRIMARY KEY,
                weekly_goal_min INT DEFAULT 300,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )"
        );
        $pref = \Doniixify\Database::fetchOne('SELECT weekly_goal_min FROM user_prefs WHERE user_id = ?', [$uid]);
        $goalMin = (int)($pref['weekly_goal_min'] ?? 300);
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $row = \Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(s.duration), 0) AS sec
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             WHERE usp.user_id = ? AND usp.last_played_at >= ?',
            [$uid, $weekStart]
        );
        $listenedSec = (int)($row['sec'] ?? 0);
        $listenedMin = (int)round($listenedSec / 60);
        $pct = $goalMin > 0 ? min(100, round($listenedMin / $goalMin * 100, 1)) : 0;
        echo json_encode([
            'weekly_goal_min' => $goalMin,
            'week_min' => $listenedMin,
            'percent' => $pct,
            'reached' => $listenedMin >= $goalMin,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/me/goal', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $min = max(0, min(10080, (int)($_POST['minutes'] ?? 0)));
    try {
        \Doniixify\Database::execute(
            "INSERT INTO user_prefs (user_id, weekly_goal_min) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE weekly_goal_min = VALUES(weekly_goal_min)",
            [(int)$user['id'], $min]
        );
        echo json_encode(['ok' => true, 'weekly_goal_min' => $min]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/track-skip', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_POST['song_id'] ?? 0);
    $playedSec = max(0, (int)($_POST['played_sec'] ?? 0));
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS track_skips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                song_id INT NOT NULL,
                played_sec INT DEFAULT 0,
                skipped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_song (user_id, song_id)
            )"
        );
        \Doniixify\Database::execute(
            'INSERT INTO track_skips (user_id, song_id, played_sec) VALUES (?, ?, ?)',
            [(int)$user['id'], $sid, $playedSec]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/skip-ratio', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $skipped = (int)(\Doniixify\Database::fetchOne('SELECT COUNT(*) AS n FROM track_skips WHERE user_id = ? AND skipped_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [$uid])['n'] ?? 0);
        $played = (int)(\Doniixify\Database::fetchOne('SELECT COUNT(*) AS n FROM user_song_plays WHERE user_id = ? AND last_played_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', [$uid])['n'] ?? 0);
        $total = $skipped + $played;
        $ratio = $total > 0 ? round($skipped / $total * 100, 1) : 0;
        $mostSkipped = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.name AS artist_name, COUNT(*) AS skip_count
             FROM track_skips ts JOIN songs s ON s.id = ts.song_id JOIN artists ar ON ar.id = s.artist_id
             WHERE ts.user_id = ? GROUP BY s.id, s.title, ar.name ORDER BY skip_count DESC LIMIT 5',
            [$uid]
        ) ?: [];
        echo json_encode(['skipped' => $skipped, 'played' => $played, 'ratio_pct' => $ratio, 'most_skipped' => $mostSkipped]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/events/live', function () {
    $user = \Doniixify\Web\Session::requireLogin();
    @set_time_limit(0);
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level()) ob_end_flush();
    @ob_implicit_flush(true);
    $start = time();
    $lastHash = '';
    while (time() - $start < 60) {
        try {
            $rows = \Doniixify\Database::fetchAll(
                'SELECT ad.user_id, ad.device_id, ad.name AS device_name, ad.is_playing, ad.song_id, ad.position,
                        s.title, ar.name AS artist
                 FROM active_devices ad
                 LEFT JOIN songs s ON s.id = ad.song_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 WHERE ad.user_id = ? AND ad.last_seen > DATE_SUB(NOW(), INTERVAL 90 SECOND)
                 ORDER BY ad.last_seen DESC LIMIT 50',
                [(int)$user['id']]
            ) ?: [];
            $payload = json_encode(['devices' => $rows, 'ts' => time()]);
            $hash = md5($payload);
            if ($hash !== $lastHash) {
                echo "data: $payload\n\n";
                @flush();
                $lastHash = $hash;
            } else {
                echo ": ping\n\n";
                @flush();
            }
        } catch (\Throwable $e) {
            echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
            @flush();
            break;
        }
        sleep(3);
        if (connection_aborted()) break;
    }
});

$router->get('/api/recommendations/for-song', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $user = \Doniixify\Web\Session::user();
    $songId = (int)($_GET['song_id'] ?? 0);
    if ($songId <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        $items = \Doniixify\Cache\RecommendationEngine::recommendForSong($songId, 20, $user ? (int)$user['id'] : null);
        echo json_encode(['items' => $items]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/recommendations/for-me', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $items = \Doniixify\Cache\RecommendationEngine::recommendForUser((int)$user['id'], 25);
        echo json_encode(['items' => $items]);
    } catch (\Throwable $e) {
        echo json_encode(['items' => []]);
    }
});

$router->post('/api/recommendations/rebuild', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    if (empty($user['is_admin'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin only']);
        return;
    }
    try {
        $stats = \Doniixify\Cache\RecommendationEngine::buildSimilarityMatrix();
        echo json_encode(['ok' => true, 'stats' => $stats]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/recently-added', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.id AS artist_id, ar.name AS artist_name
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             ORDER BY s.created_at DESC LIMIT 10'
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['items' => []]);
    }
});

$router->get('/api/me/random-pool', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.id AS artist_id, ar.name AS artist_name
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             WHERE s.duration > 30
             ORDER BY RAND() LIMIT 10'
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['items' => []]);
    }
});

$router->get('/api/me/recently-liked', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            "SELECT s.id, s.title, ar.id AS artist_id, ar.name AS artist_name, st.starred_at
             FROM stars st
             JOIN songs s ON s.id = st.item_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE st.user_id = ? AND st.item_type = 'song'
             ORDER BY st.starred_at DESC LIMIT 10",
            [(int)$user['id']]
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['items' => []]);
    }
});

$router->get('/api/me/forgotten-gems', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, ar.name AS artist_name, ar.id AS artist_id, usp.play_count, usp.last_played_at,
                    DATEDIFF(NOW(), usp.last_played_at) AS days_ago
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
               AND usp.last_played_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
               AND usp.play_count >= 3
             ORDER BY usp.play_count DESC, usp.last_played_at ASC
             LIMIT 12',
            [$uid]
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/hidden-gems', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            "SELECT s.id, s.title, ar.name AS artist_name, ar.id AS artist_id,
                    COALESCE(usp.play_count, 0) AS play_count
             FROM stars st
             JOIN songs s ON s.id = st.item_id
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN user_song_plays usp ON usp.song_id = s.id AND usp.user_id = ?
             WHERE st.user_id = ? AND st.item_type = 'song'
               AND COALESCE(usp.play_count, 0) <= 2
             ORDER BY st.starred_at DESC
             LIMIT 12",
            [$uid, $uid]
        ) ?: [];
        echo json_encode(['items' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['items' => []]);
    }
});

$router->get('/api/surprise', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $row = \Doniixify\Database::fetchOne(
            'SELECT s.id, s.title, ar.name AS artist_name, ar.id AS artist_id
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             WHERE s.duration > 30
               AND s.id NOT IN (SELECT song_id FROM user_song_plays WHERE user_id = ? AND last_played_at > DATE_SUB(NOW(), INTERVAL 7 DAY))
             ORDER BY RAND() LIMIT 1',
            [$uid]
        );
        if (!$row) {
            $row = \Doniixify\Database::fetchOne('SELECT s.id, s.title, ar.name AS artist_name, ar.id AS artist_id FROM songs s JOIN artists ar ON ar.id = s.artist_id ORDER BY RAND() LIMIT 1');
        }
        if (!$row) { echo json_encode(['error' => 'No songs']); return; }
        echo json_encode($row);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/on-this-day', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            "SELECT s.id, s.title, ar.name AS artist_name, usp.last_played_at, YEAR(usp.last_played_at) AS year_played
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
               AND DAYOFYEAR(usp.last_played_at) = DAYOFYEAR(CURDATE())
               AND YEAR(usp.last_played_at) < YEAR(CURDATE())
             ORDER BY usp.last_played_at DESC
             LIMIT 8",
            [$uid]
        ) ?: [];
        echo json_encode(['items' => $rows, 'date' => date('M j')]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/top-genres', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT st.tag, COUNT(DISTINCT st.song_id) AS tracks,
                    SUM(COALESCE(usp.play_count, 0)) AS total_plays
             FROM song_tags st
             LEFT JOIN user_song_plays usp ON usp.song_id = st.song_id AND usp.user_id = st.user_id
             WHERE st.user_id = ?
             GROUP BY st.tag
             ORDER BY total_plays DESC, tracks DESC
             LIMIT 15',
            [$uid]
        ) ?: [];
        $totalPlays = array_sum(array_column($rows, 'total_plays'));
        echo json_encode([
            'genres' => array_map(fn($r) => [
                'tag' => $r['tag'],
                'tracks' => (int)$r['tracks'],
                'plays' => (int)$r['total_plays'],
                'pct' => $totalPlays > 0 ? round((int)$r['total_plays'] / $totalPlays * 100, 1) : 0,
            ], $rows),
            'total_plays' => (int)$totalPlays,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/wrap', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $year = (int)($_GET['year'] ?? date('Y'));
    $start = $year . '-01-01';
    $end = $year . '-12-31 23:59:59';
    try {
        $total = \Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(s.duration * usp.play_count),0) AS sec, COUNT(DISTINCT s.id) AS unique_tracks, SUM(usp.play_count) AS total_plays
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             WHERE usp.user_id = ? AND usp.last_played_at BETWEEN ? AND ?',
            [$uid, $start, $end]
        );
        $topArtists = \Doniixify\Database::fetchAll(
            'SELECT ar.id, ar.name, SUM(usp.play_count) AS plays
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? AND usp.last_played_at BETWEEN ? AND ?
             GROUP BY ar.id, ar.name ORDER BY plays DESC LIMIT 5',
            [$uid, $start, $end]
        ) ?: [];
        $topSong = \Doniixify\Database::fetchOne(
            'SELECT s.id, s.title, ar.name AS artist_name, usp.play_count
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ? AND usp.last_played_at BETWEEN ? AND ?
             ORDER BY usp.play_count DESC LIMIT 1',
            [$uid, $start, $end]
        );
        $totalMin = (int)round(((int)($total['sec'] ?? 0)) / 60);
        $totalHr = round($totalMin / 60, 1);
        echo json_encode([
            'year' => $year,
            'total_minutes' => $totalMin,
            'total_hours' => $totalHr,
            'total_plays' => (int)($total['total_plays'] ?? 0),
            'unique_tracks' => (int)($total['unique_tracks'] ?? 0),
            'top_artists' => $topArtists,
            'top_song' => $topSong,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/calendar', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $year = (int)($_GET['year'] ?? date('Y'));
    $start = $year . '-01-01';
    $end = $year . '-12-31';
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT DATE(usp.last_played_at) AS day, COUNT(*) AS plays
             FROM user_song_plays usp WHERE usp.user_id = ?
               AND usp.last_played_at BETWEEN ? AND ?
             GROUP BY day',
            [$uid, $start, $end . ' 23:59:59']
        ) ?: [];
        $byDay = [];
        $max = 0;
        foreach ($rows as $r) {
            $byDay[$r['day']] = (int)$r['plays'];
            if ((int)$r['plays'] > $max) $max = (int)$r['plays'];
        }
        echo json_encode(['year' => $year, 'by_day' => $byDay, 'max' => $max, 'active_days' => count($byDay)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/hour-heatmap', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $days = max(7, min(365, (int)($_GET['days'] ?? 30)));
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT DAYOFWEEK(usp.last_played_at) AS dow, HOUR(usp.last_played_at) AS hr, COUNT(*) AS plays
             FROM user_song_plays usp
             WHERE usp.user_id = ? AND usp.last_played_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY dow, hr',
            [$uid, $days]
        ) ?: [];
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;
        foreach ($rows as $r) {
            $dow = (int)$r['dow'] - 1;
            $hr = (int)$r['hr'];
            $plays = (int)$r['plays'];
            if ($dow >= 0 && $dow < 7 && $hr >= 0 && $hr < 24) {
                $grid[$dow][$hr] = $plays;
                if ($plays > $max) $max = $plays;
            }
        }
        echo json_encode(['grid' => $grid, 'max' => $max, 'days' => $days]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/streak', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT DATE(last_played_at) AS day
             FROM user_song_plays
             WHERE user_id = ?
               AND last_played_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
             GROUP BY DATE(last_played_at)
             ORDER BY day DESC',
            [$uid]
        ) ?: [];
        $days = array_column($rows, 'day');
        $streak = 0;
        $today = date('Y-m-d');
        $cursor = $today;
        foreach ($days as $d) {
            if ($d === $cursor) {
                $streak++;
                $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
            } elseif ($d === date('Y-m-d', strtotime($cursor . ' -1 day'))) {
                $cursor = date('Y-m-d', strtotime($cursor . ' -1 day'));
                continue;
            } else {
                break;
            }
        }
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekRow = \Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(s.duration), 0) AS sec, COUNT(*) AS plays
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             WHERE usp.user_id = ? AND usp.last_played_at >= ?',
            [$uid, $weekStart]
        );
        echo json_encode([
            'current_streak' => $streak,
            'longest_streak_30d' => count($days),
            'week_minutes' => (int)round(((int)($weekRow['sec'] ?? 0)) / 60),
            'week_plays' => (int)($weekRow['plays'] ?? 0),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/stats/timeseries', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    $days = max(7, min(90, (int)($_GET['days'] ?? 30)));
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT DATE(usp.last_played_at) AS day, SUM(s.duration) AS seconds, COUNT(*) AS plays
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             WHERE usp.user_id = ?
               AND usp.last_played_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(usp.last_played_at)
             ORDER BY day ASC',
            [$uid, $days]
        ) ?: [];
        $byDay = [];
        foreach ($rows as $r) $byDay[$r['day']] = ['seconds' => (int)$r['seconds'], 'plays' => (int)$r['plays']];
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' days'));
            $series[] = [
                'day' => $d,
                'seconds' => $byDay[$d]['seconds'] ?? 0,
                'plays' => $byDay[$d]['plays'] ?? 0,
            ];
        }
        echo json_encode(['series' => $series, 'days' => $days]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/me/stats', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    try {
        $topArtists = \Doniixify\Database::fetchAll(
            'SELECT ar.id, ar.name, COUNT(*) AS plays
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
             GROUP BY ar.id, ar.name
             ORDER BY plays DESC
             LIMIT 10',
            [$uid]
        ) ?: [];
        $topSongs = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist_name, usp.play_count
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
             ORDER BY usp.play_count DESC
             LIMIT 10',
            [$uid]
        ) ?: [];
        $totalPlays = (int)(\Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(play_count),0) AS n FROM user_song_plays WHERE user_id = ?',
            [$uid]
        )['n'] ?? 0);
        $totalSeconds = (int)(\Doniixify\Database::fetchOne(
            'SELECT COALESCE(SUM(s.duration * usp.play_count),0) AS sec
             FROM user_song_plays usp JOIN songs s ON s.id = usp.song_id
             WHERE usp.user_id = ?',
            [$uid]
        )['sec'] ?? 0);
        $recentArtists = \Doniixify\Database::fetchAll(
            'SELECT DISTINCT ar.id, ar.name, MAX(usp.last_played_at) AS last_at
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE usp.user_id = ?
             GROUP BY ar.id, ar.name
             ORDER BY last_at DESC
             LIMIT 8',
            [$uid]
        ) ?: [];
        echo json_encode([
            'top_artists' => $topArtists,
            'top_songs' => $topSongs,
            'total_plays' => $totalPlays,
            'total_seconds' => $totalSeconds,
            'recent_artists' => $recentArtists,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/playlist-cover', function () {
    \Doniixify\Web\PlaylistCollage::render((int)($_GET['id'] ?? 0));
});

$router->get('/api/export/history.csv', function () {
    $user = \Doniixify\Web\Session::requireLogin();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="doniixify-history.csv"');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT s.title, ar.name AS artist, al.name AS album, usp.play_count, usp.last_played_at, s.duration
             FROM user_song_plays usp
             JOIN songs s ON s.id = usp.song_id
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN albums al ON al.id = s.album_id
             WHERE usp.user_id = ? ORDER BY usp.last_played_at DESC',
            [$uid]
        ) ?: [];
        $out = fopen('php://output', 'w');
        fputcsv($out, ['title', 'artist', 'album', 'play_count', 'last_played_at', 'duration_sec']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['title'], $r['artist'], $r['album'], $r['play_count'], $r['last_played_at'], $r['duration']]);
        }
        fclose($out);
    } catch (\Throwable $e) {
        echo 'Error: ' . $e->getMessage();
    }
});

$router->get('/api/export/liked.json', function () {
    $user = \Doniixify\Web\Session::requireLogin();
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="doniixify-liked.json"');
    $uid = (int)$user['id'];
    try {
        $rows = \Doniixify\Database::fetchAll(
            "SELECT s.id, s.title, ar.name AS artist_name, al.name AS album_name, s.duration, st.starred_at AS liked_at
             FROM stars st
             JOIN songs s ON s.id = st.item_id
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN albums al ON al.id = s.album_id
             WHERE st.user_id = ? AND st.item_type = 'song'
             ORDER BY st.starred_at DESC",
            [$uid]
        ) ?: [];
        echo json_encode([
            'user' => $user['username'],
            'exported_at' => date('c'),
            'count' => count($rows),
            'tracks' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/backup', function () {
    $user = \Doniixify\Web\Session::requireLogin();
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="doniixify-backup-' . date('Y-m-d') . '.json"');
    $uid = (int)$user['id'];
    try {
        $liked = \Doniixify\Database::fetchAll(
            "SELECT s.title, ar.name AS artist FROM stars st
             JOIN songs s ON s.id = st.item_id JOIN artists ar ON ar.id = s.artist_id
             WHERE st.user_id = ? AND st.item_type = 'song'",
            [$uid]
        ) ?: [];
        $playlists = \Doniixify\Database::fetchAll('SELECT id, name, created_at FROM playlists WHERE owner_id = ?', [$uid]) ?: [];
        foreach ($playlists as &$pl) {
            $songs = \Doniixify\Database::fetchAll(
                'SELECT s.title, ar.name AS artist FROM playlist_songs ps
                 JOIN songs s ON s.id = ps.song_id JOIN artists ar ON ar.id = s.artist_id
                 WHERE ps.playlist_id = ? ORDER BY ps.position',
                [(int)$pl['id']]
            ) ?: [];
            $pl['songs'] = $songs;
            unset($pl['id']);
        }
        unset($pl);
        $tags = [];
        try {
            $tagRows = \Doniixify\Database::fetchAll(
                'SELECT s.title, ar.name AS artist, st.tag FROM song_tags st
                 JOIN songs s ON s.id = st.song_id JOIN artists ar ON ar.id = s.artist_id
                 WHERE st.user_id = ?',
                [$uid]
            ) ?: [];
            $tags = $tagRows;
        } catch (\Throwable $e) {}
        $prefs = [];
        try {
            $prefRow = \Doniixify\Database::fetchOne('SELECT weekly_goal_min FROM user_prefs WHERE user_id = ?', [$uid]);
            if ($prefRow) $prefs = $prefRow;
        } catch (\Throwable $e) {}
        echo json_encode([
            'version' => 1,
            'user' => $user['username'],
            'exported_at' => date('c'),
            'liked' => $liked,
            'playlists' => $playlists,
            'tags' => $tags,
            'prefs' => $prefs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/restore', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $uid = (int)$user['id'];
    if (empty($_FILES['backup']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No backup file']);
        return;
    }
    $raw = @file_get_contents($_FILES['backup']['tmp_name']);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || !isset($data['version'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid backup format']);
        return;
    }
    $stats = ['liked' => 0, 'playlists' => 0, 'tags' => 0];
    try {
        $findSong = function (string $title, string $artist) {
            return \Doniixify\Database::fetchOne(
                'SELECT s.id FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE LOWER(TRIM(s.title)) = LOWER(TRIM(?)) AND LOWER(TRIM(ar.name)) = LOWER(TRIM(?))
                 LIMIT 1',
                [$title, $artist]
            );
        };
        foreach (($data['liked'] ?? []) as $item) {
            $row = $findSong($item['title'] ?? '', $item['artist'] ?? '');
            if ($row) {
                \Doniixify\Database::execute(
                    "INSERT IGNORE INTO stars (user_id, item_id, item_type) VALUES (?, ?, 'song')",
                    [$uid, (int)$row['id']]
                );
                $stats['liked']++;
            }
        }
        foreach (($data['playlists'] ?? []) as $pl) {
            \Doniixify\Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $pl['name'] ?? 'Imported']);
            $newPlId = (int)\Doniixify\Database::lastInsertId();
            $pos = 1;
            foreach (($pl['songs'] ?? []) as $song) {
                $row = $findSong($song['title'] ?? '', $song['artist'] ?? '');
                if ($row) {
                    \Doniixify\Database::execute(
                        'INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)',
                        [$newPlId, (int)$row['id'], $pos++]
                    );
                }
            }
            $stats['playlists']++;
        }
        foreach (($data['tags'] ?? []) as $t) {
            $row = $findSong($t['title'] ?? '', $t['artist'] ?? '');
            if ($row && !empty($t['tag'])) {
                \Doniixify\Database::execute(
                    'INSERT IGNORE INTO song_tags (user_id, song_id, tag) VALUES (?, ?, ?)',
                    [$uid, (int)$row['id'], strtolower(trim($t['tag']))]
                );
                $stats['tags']++;
            }
        }
        if (!empty($data['prefs']['weekly_goal_min'])) {
            \Doniixify\Database::execute(
                "INSERT INTO user_prefs (user_id, weekly_goal_min) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE weekly_goal_min = VALUES(weekly_goal_min)",
                [$uid, (int)$data['prefs']['weekly_goal_min']]
            );
        }
        echo json_encode(['ok' => true, 'stats' => $stats]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/export/lrc', function () {
    \Doniixify\Web\Session::requireLogin();
    $artist = trim($_GET['artist'] ?? '');
    $title = trim($_GET['title'] ?? '');
    if ($artist === '' || $title === '') { http_response_code(400); echo 'Missing'; return; }
    $key = md5(mb_strtolower($artist) . '|' . mb_strtolower($title));
    try {
        $row = \Doniixify\Database::fetchOne('SELECT synced_lyrics, plain_lyrics FROM lyrics_cache WHERE key_hash = ?', [$key]);
        if (!$row) { http_response_code(404); echo 'No lyrics cached.'; return; }
        $content = $row['synced_lyrics'] ?: $row['plain_lyrics'] ?: '';
        if ($content === '') { http_response_code(404); echo 'Empty.'; return; }
        $filename = preg_replace('/[^a-zA-Z0-9 \-]/', '', $artist . ' - ' . $title) . '.lrc';
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $content;
    } catch (\Throwable $e) { http_response_code(500); echo 'Error'; }
});

$router->post('/api/lyrics/highlight', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_POST['song_id'] ?? 0);
    $line = trim((string)($_POST['line'] ?? ''));
    if ($sid <= 0 || $line === '') { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS lyrics_highlights (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                song_id INT NOT NULL,
                line VARCHAR(500) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_song (song_id)
            )"
        );
        \Doniixify\Database::execute(
            'INSERT INTO lyrics_highlights (user_id, song_id, line) VALUES (?, ?, ?)',
            [(int)$user['id'], $sid, mb_substr($line, 0, 500)]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/lyrics/highlights', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            'SELECT lh.line, lh.created_at, s.id AS song_id, s.title, ar.name AS artist_name
             FROM lyrics_highlights lh JOIN songs s ON s.id = lh.song_id
             JOIN artists ar ON ar.id = s.artist_id WHERE lh.user_id = ? ORDER BY lh.created_at DESC LIMIT 100',
            [(int)$user['id']]
        ) ?: [];
        echo json_encode(['highlights' => $rows]);
    } catch (\Throwable $e) {
        echo json_encode(['highlights' => []]);
    }
});

$router->get('/api/song/volume', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_GET['song_id'] ?? 0);
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS song_volumes (
                user_id INT NOT NULL, song_id INT NOT NULL, gain_db FLOAT NOT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, song_id)
            )"
        );
        $row = \Doniixify\Database::fetchOne('SELECT gain_db FROM song_volumes WHERE user_id = ? AND song_id = ?', [(int)$user['id'], $sid]);
        echo json_encode(['gain_db' => $row ? (float)$row['gain_db'] : 0]);
    } catch (\Throwable $e) {
        echo json_encode(['gain_db' => 0]);
    }
});

$router->post('/api/song/volume', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_POST['song_id'] ?? 0);
    $gain = max(-12, min(12, (float)($_POST['gain_db'] ?? 0)));
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        if (abs($gain) < 0.1) {
            \Doniixify\Database::execute('DELETE FROM song_volumes WHERE user_id = ? AND song_id = ?', [(int)$user['id'], $sid]);
        } else {
            \Doniixify\Database::execute(
                'INSERT INTO song_volumes (user_id, song_id, gain_db) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE gain_db = VALUES(gain_db)',
                [(int)$user['id'], $sid, $gain]
            );
        }
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/song/rating', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_GET['song_id'] ?? 0);
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS song_ratings (
                user_id INT NOT NULL,
                song_id INT NOT NULL,
                rating TINYINT NOT NULL,
                note TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, song_id),
                INDEX idx_user_rating (user_id, rating)
            )"
        );
        $row = \Doniixify\Database::fetchOne('SELECT rating, note FROM song_ratings WHERE user_id = ? AND song_id = ?', [(int)$user['id'], $sid]);
        echo json_encode([
            'rating' => $row ? (int)$row['rating'] : 0,
            'note' => $row['note'] ?? '',
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/song/rating', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_POST['song_id'] ?? 0);
    $rating = max(0, min(5, (int)($_POST['rating'] ?? 0)));
    $note = isset($_POST['note']) ? trim((string)$_POST['note']) : null;
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        if ($rating === 0 && $note === null) {
            \Doniixify\Database::execute('DELETE FROM song_ratings WHERE user_id = ? AND song_id = ?', [(int)$user['id'], $sid]);
        } else {
            \Doniixify\Database::execute(
                "INSERT INTO song_ratings (user_id, song_id, rating, note) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating), note = COALESCE(VALUES(note), note)",
                [(int)$user['id'], $sid, $rating, $note]
            );
        }
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/library/health', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $songs = \Doniixify\Database::fetchAll('SELECT id, title, file_path, duration FROM songs ORDER BY id DESC LIMIT 2000') ?: [];
        $brokenFiles = [];
        $zeroDuration = [];
        $missingCovers = [];
        $coverDir = __DIR__ . '/storage/covers';
        $checked = 0;
        foreach ($songs as $s) {
            $checked++;
            if (!empty($s['file_path']) && !is_file($s['file_path'])) {
                $brokenFiles[] = ['id' => (int)$s['id'], 'title' => $s['title'], 'path' => $s['file_path']];
            }
            if ((int)$s['duration'] <= 0) {
                $zeroDuration[] = ['id' => (int)$s['id'], 'title' => $s['title']];
            }
            $hasCover = false;
            foreach (['jpg','png','webp'] as $ext) {
                if (is_file($coverDir . '/' . $s['id'] . '.' . $ext)) { $hasCover = true; break; }
            }
            if (!$hasCover) {
                $missingCovers[] = ['id' => (int)$s['id'], 'title' => $s['title']];
            }
        }
        echo json_encode([
            'checked' => $checked,
            'broken_files' => array_slice($brokenFiles, 0, 50),
            'broken_count' => count($brokenFiles),
            'zero_duration' => array_slice($zeroDuration, 0, 50),
            'zero_duration_count' => count($zeroDuration),
            'missing_covers' => array_slice($missingCovers, 0, 50),
            'missing_covers_count' => count($missingCovers),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/library/cleanup', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $kind = $_POST['kind'] ?? '';
    if (!in_array($kind, ['broken', 'zero_duration'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid kind']);
        return;
    }
    try {
        $songs = \Doniixify\Database::fetchAll('SELECT id, file_path, duration FROM songs') ?: [];
        $toDelete = [];
        foreach ($songs as $s) {
            if ($kind === 'broken' && !empty($s['file_path']) && !is_file($s['file_path'])) {
                $toDelete[] = (int)$s['id'];
            } elseif ($kind === 'zero_duration' && (int)$s['duration'] <= 0) {
                $toDelete[] = (int)$s['id'];
            }
        }
        if (count($toDelete) > 500) {
            echo json_encode(['error' => 'Too many to delete in one go (' . count($toDelete) . '). Manual cleanup required.']);
            return;
        }
        $deleted = 0;
        foreach ($toDelete as $sid) {
            \Doniixify\Database::execute('DELETE FROM songs WHERE id = ?', [$sid]);
            \Doniixify\Database::execute('DELETE FROM playlist_songs WHERE song_id = ?', [$sid]);
            \Doniixify\Database::execute("DELETE FROM stars WHERE item_id = ? AND item_type = 'song'", [$sid]);
            $deleted++;
        }
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/library/storage', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $dirs = [
            'music' => __DIR__ . '/storage/music',
            'covers' => __DIR__ . '/storage/covers',
            'cache' => __DIR__ . '/storage/cache',
            'logs' => __DIR__ . '/storage/logs',
            'jobs' => __DIR__ . '/storage/jobs',
            'queue' => __DIR__ . '/storage/queue',
        ];
        $out = [];
        $total = 0;
        foreach ($dirs as $name => $path) {
            if (!is_dir($path)) { $out[$name] = ['bytes' => 0, 'files' => 0]; continue; }
            $bytes = 0;
            $files = 0;
            $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iter as $f) {
                if ($f->isFile()) { $bytes += $f->getSize(); $files++; }
            }
            $out[$name] = ['bytes' => $bytes, 'files' => $files];
            $total += $bytes;
        }
        $out['_total'] = $total;
        $df = @disk_free_space(__DIR__);
        $dt = @disk_total_space(__DIR__);
        $out['_disk_free'] = $df ? (int)$df : null;
        $out['_disk_total'] = $dt ? (int)$dt : null;
        echo json_encode($out);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/duplicates', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    try {
        $rows = \Doniixify\Database::fetchAll(
            "SELECT
                LOWER(TRIM(s.title)) AS norm_title,
                LOWER(TRIM(ar.name)) AS norm_artist,
                COUNT(*) AS dup_count,
                GROUP_CONCAT(s.id ORDER BY s.id) AS ids,
                GROUP_CONCAT(s.title SEPARATOR '||') AS titles,
                GROUP_CONCAT(ar.name SEPARATOR '||') AS artists,
                GROUP_CONCAT(IFNULL(s.duration,0) ORDER BY s.id) AS durations
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             GROUP BY norm_title, norm_artist
             HAVING dup_count > 1
             ORDER BY dup_count DESC
             LIMIT 100"
        ) ?: [];
        $groups = array_map(function ($r) {
            $ids = explode(',', $r['ids']);
            $titles = explode('||', $r['titles']);
            $durations = explode(',', $r['durations']);
            $items = [];
            foreach ($ids as $i => $id) {
                $items[] = [
                    'id' => (int)$id,
                    'title' => $titles[$i] ?? '',
                    'duration' => (int)($durations[$i] ?? 0),
                ];
            }
            return [
                'norm_title' => $r['norm_title'],
                'norm_artist' => $r['norm_artist'],
                'count' => (int)$r['dup_count'],
                'items' => $items,
            ];
        }, $rows);
        echo json_encode(['groups' => $groups, 'total' => count($groups)]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/song/loudness', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_GET['song_id'] ?? 0);
    if ($sid <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing song_id']); return; }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS song_loudness (
                song_id INT PRIMARY KEY,
                rms_db FLOAT,
                peak_db FLOAT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )"
        );
        $row = \Doniixify\Database::fetchOne('SELECT rms_db, peak_db FROM song_loudness WHERE song_id = ?', [$sid]);
        if ($row) {
            echo json_encode(['rms_db' => (float)$row['rms_db'], 'peak_db' => (float)$row['peak_db'], 'cached' => true]);
        } else {
            echo json_encode(['cached' => false]);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/song/loudness', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $sid = (int)($_POST['song_id'] ?? 0);
    $rms = isset($_POST['rms_db']) ? (float)$_POST['rms_db'] : null;
    $peak = isset($_POST['peak_db']) ? (float)$_POST['peak_db'] : null;
    if ($sid <= 0 || $rms === null) { http_response_code(400); echo json_encode(['error' => 'Invalid']); return; }
    try {
        \Doniixify\Database::execute(
            "INSERT INTO song_loudness (song_id, rms_db, peak_db) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE rms_db = VALUES(rms_db), peak_db = VALUES(peak_db), updated_at = CURRENT_TIMESTAMP",
            [$sid, $rms, $peak]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/artist-bio', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=86400');
    $name = trim((string)($_GET['name'] ?? ''));
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Missing name']); return; }
    $apiKey = \Doniixify\Env::get('LASTFM_API_KEY', '');
    if ($apiKey === '') {
        echo json_encode(['error' => 'LASTFM_API_KEY not set']);
        return;
    }
    try {
        $cacheDir = __DIR__ . '/storage/cache/artist-bio';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/' . md5(strtolower($name)) . '.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - 7 * 86400) {
            echo @file_get_contents($cacheFile);
            return;
        }
        $url = 'https://ws.audioscrobbler.com/2.0/?method=artist.getinfo&artist=' . urlencode($name) . '&api_key=' . urlencode($apiKey) . '&format=json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($body ?: '{}', true);
        $bio = $data['artist']['bio']['summary'] ?? null;
        $tags = $data['artist']['tags']['tag'] ?? [];
        $cleanBio = $bio ? preg_replace('/<a[^>]*>.*?<\/a>/', '', $bio) : null;
        $result = [
            'name' => $name,
            'bio' => $cleanBio ? trim(strip_tags($cleanBio)) : null,
            'tags' => array_map(fn($t) => $t['name'] ?? '', is_array($tags) ? $tags : []),
            'url' => $data['artist']['url'] ?? null,
        ];
        $json = json_encode($result);
        @file_put_contents($cacheFile, $json);
        echo $json;
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/cover-hash', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    header('Cache-Control: public, max-age=86400');
    $songId = (int)($_GET['id'] ?? 0);
    if ($songId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid id']);
        return;
    }
    $cacheDir = __DIR__ . '/storage/cache/blurhash';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/' . $songId . '.txt';
    if (is_file($cacheFile)) {
        echo json_encode(['hash' => trim((string)@file_get_contents($cacheFile))]);
        return;
    }
    $coverDir = __DIR__ . '/storage/covers';
    $coverPaths = [
        $coverDir . '/' . $songId . '.jpg',
        $coverDir . '/' . $songId . '.png',
        $coverDir . '/' . $songId . '.webp',
    ];
    $coverPath = null;
    foreach ($coverPaths as $p) {
        if (is_file($p)) { $coverPath = $p; break; }
    }
    if (!$coverPath) {
        echo json_encode(['hash' => null]);
        return;
    }
    $hash = \Doniixify\Cache\BlurHash::encodeCover($coverPath, 4, 3);
    if ($hash) @file_put_contents($cacheFile, $hash);
    echo json_encode(['hash' => $hash]);
});

$router->post('/api/playlists/share', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $plId = (int)($_POST['playlist_id'] ?? 0);
    if ($plId <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        $owner = \Doniixify\Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$plId]);
        if (!$owner || (int)$owner['owner_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not your playlist']);
            return;
        }
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS playlist_shares (
                token VARCHAR(32) PRIMARY KEY,
                playlist_id INT NOT NULL,
                user_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_playlist (playlist_id)
            )"
        );
        $existing = \Doniixify\Database::fetchOne('SELECT token FROM playlist_shares WHERE playlist_id = ?', [$plId]);
        if ($existing) {
            $token = $existing['token'];
        } else {
            $token = bin2hex(random_bytes(12));
            \Doniixify\Database::execute(
                'INSERT INTO playlist_shares (token, playlist_id, user_id) VALUES (?, ?, ?)',
                [$token, $plId, (int)$user['id']]
            );
        }
        echo json_encode([
            'ok' => true,
            'token' => $token,
            'url' => (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/share/' . $token,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/api/share-info', function () {
    header('Content-Type: application/json');
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') { http_response_code(400); echo json_encode(['error' => 'Missing token']); return; }
    try {
        $row = \Doniixify\Database::fetchOne(
            'SELECT ps.playlist_id, p.name, p.created_at, u.username AS owner_name
             FROM playlist_shares ps
             JOIN playlists p ON p.id = ps.playlist_id
             JOIN users u ON u.id = ps.user_id
             WHERE ps.token = ?',
            [$token]
        );
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Invalid token']); return; }
        $songs = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist_name
             FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE ps.playlist_id = ? ORDER BY ps.position',
            [(int)$row['playlist_id']]
        ) ?: [];
        echo json_encode([
            'playlist_id' => (int)$row['playlist_id'],
            'name' => $row['name'],
            'owner' => $row['owner_name'],
            'song_count' => count($songs),
            'total_duration' => array_sum(array_column($songs, 'duration')),
            'songs' => array_slice($songs, 0, 50),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->get('/share', function () {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(404);
        echo 'Share token missing. Use /share?token=XXX';
        return;
    }
    \Doniixify\Web\Session::requireLogin();
    $content = '<div class="page-content"><header class="page-header"><h1 class="page-title">Shared playlist</h1></header>' .
        '<div id="share-body" style="margin-top:24px">Loading…</div>' .
        '<script>' .
        '(async function(){' .
            'const body = document.getElementById("share-body");' .
            'const token = "' . htmlspecialchars($token, ENT_QUOTES) . '";' .
            'try {' .
                'const r = await fetch("/api/share-info?token=" + encodeURIComponent(token));' .
                'const d = await r.json();' .
                'if (!d || d.error) { body.textContent = "Invalid or expired share link."; return; }' .
                'const esc = (s) => String(s ?? "").replace(/[&<>\"\\\']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\\\'":"&#39;"}[c]));' .
                'const fmt = (sec) => Math.floor(sec/60) + "m " + (sec%60) + "s";' .
                'let html = "<div style=\"margin-bottom:24px\"><h2 style=\"font-size:28px;font-weight:900;margin:0 0 6px\">" + esc(d.name) + "</h2><div style=\"color:var(--text-muted);font-size:13px\">by " + esc(d.owner) + " · " + d.song_count + " tracks · " + fmt(d.total_duration) + "</div></div>";' .
                'html += "<div style=\"display:flex;flex-direction:column;gap:6px\">" + d.songs.map((s,i) => "<a href=\"#\" data-id=\"" + s.id + "\" style=\"display:flex;padding:8px 12px;background:rgba(255,255,255,0.03);border-radius:6px;text-decoration:none;color:inherit\"><span style=\"width:24px;color:var(--text-muted)\">" + (i+1) + "</span><span style=\"flex:1\">" + esc(s.title) + " — <span style=\"color:var(--text-muted)\">" + esc(s.artist_name) + "</span></span></a>").join("") + "</div>";' .
                'body.innerHTML = html;' .
                'body.querySelectorAll("[data-id]").forEach(a => a.addEventListener("click", (e) => {' .
                    'e.preventDefault();' .
                    'if (window.doniixify && window.doniixify.loadSong) window.doniixify.loadSong(Number(a.dataset.id), "", "", true);' .
                '}));' .
            '} catch(e) { body.textContent = "Error loading share."; }' .
        '})();' .
        '</script></div>';
    \Doniixify\Web\Layout::render('/share', $content);
});

$router->get('/api/playlists/analytics', function () {
    \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $plId = (int)($_GET['playlist_id'] ?? 0);
    if ($plId <= 0) { http_response_code(400); echo json_encode(['error' => 'Missing']); return; }
    try {
        $songs = \Doniixify\Database::fetchAll(
            'SELECT s.id, s.title, s.duration, ar.name AS artist FROM playlist_songs ps
             JOIN songs s ON s.id = ps.song_id JOIN artists ar ON ar.id = s.artist_id
             WHERE ps.playlist_id = ?',
            [$plId]
        ) ?: [];
        if (!$songs) { echo json_encode(['error' => 'Empty playlist']); return; }
        $totalDur = array_sum(array_column($songs, 'duration'));
        $longest = null;
        $shortest = null;
        $artists = [];
        foreach ($songs as $s) {
            if ($longest === null || $s['duration'] > $longest['duration']) $longest = $s;
            if ($shortest === null || $s['duration'] < $shortest['duration']) $shortest = $s;
            $artists[$s['artist']] = ($artists[$s['artist']] ?? 0) + 1;
        }
        arsort($artists);
        $topArtists = array_slice(array_map(fn($name, $count) => ['name' => $name, 'count' => $count], array_keys($artists), $artists), 0, 5);
        echo json_encode([
            'count' => count($songs),
            'total_duration_sec' => $totalDur,
            'avg_duration_sec' => count($songs) > 0 ? round($totalDur / count($songs)) : 0,
            'longest' => $longest,
            'shortest' => $shortest,
            'unique_artists' => count($artists),
            'top_artists' => $topArtists,
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/playlists/upload-cover', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $plId = (int)($_POST['playlist_id'] ?? 0);
    if ($plId <= 0 || empty($_FILES['cover']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid']);
        return;
    }
    $pl = \Doniixify\Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$plId]);
    if (!$pl || (int)$pl['owner_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Not your playlist']);
        return;
    }
    $size = (int)($_FILES['cover']['size'] ?? 0);
    if ($size > 5 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'Max 5MB']);
        return;
    }
    $info = @getimagesize($_FILES['cover']['tmp_name']);
    if (!$info) {
        http_response_code(415);
        echo json_encode(['error' => 'Not an image']);
        return;
    }
    $dir = __DIR__ . '/storage/cache/playlist-covers';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $destPath = $dir . '/' . $plId . '.jpg';
    try {
        $raw = file_get_contents($_FILES['cover']['tmp_name']);
        $im = imagecreatefromstring($raw);
        if (!$im) throw new \RuntimeException('Decode failed');
        $w = imagesx($im);
        $h = imagesy($im);
        $side = min($w, $h);
        $sx = ($w - $side) / 2;
        $sy = ($h - $side) / 2;
        $target = imagecreatetruecolor(600, 600);
        imagecopyresampled($target, $im, 0, 0, (int)$sx, (int)$sy, 600, 600, $side, $side);
        imagejpeg($target, $destPath, 88);
        imagedestroy($im);
        imagedestroy($target);
        \Doniixify\Database::execute('UPDATE playlists SET updated_at = NOW() WHERE id = ?', [$plId]);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/playlists/reorder', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    $plId = (int)($data['playlist_id'] ?? 0);
    $order = $data['order'] ?? [];
    if ($plId <= 0 || !is_array($order) || !$order) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing playlist_id or order']);
        return;
    }
    try {
        $pl = \Doniixify\Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$plId]);
        if (!$pl || (int)$pl['owner_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not your playlist']);
            return;
        }
        $pos = 1;
        foreach ($order as $songId) {
            $sid = (int)$songId;
            if ($sid <= 0) continue;
            \Doniixify\Database::execute(
                'UPDATE playlist_songs SET position = ? WHERE playlist_id = ? AND song_id = ?',
                [$pos++, $plId, $sid]
            );
        }
        echo json_encode(['ok' => true, 'reordered' => $pos - 1]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/upload', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    if (empty($_FILES['file']['tmp_name']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        return;
    }
    $tmp = $_FILES['file']['tmp_name'];
    $originalName = $_FILES['file']['name'] ?? 'upload';
    $size = (int)($_FILES['file']['size'] ?? 0);
    if ($size <= 0 || $size > 200 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['error' => 'File too large (max 200MB)']);
        return;
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['mp3','m4a','flac','ogg','opus','wav','webm','aac'];
    if (!in_array($ext, $allowed, true)) {
        http_response_code(415);
        echo json_encode(['error' => 'Unsupported format: ' . $ext]);
        return;
    }
    $musicDir = __DIR__ . '/storage/music/uploads';
    if (!is_dir($musicDir)) @mkdir($musicDir, 0775, true);
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    if ($base === '') $base = 'upload';
    $destName = $base . '_' . time() . '.' . $ext;
    $destPath = $musicDir . '/' . $destName;
    if (!@move_uploaded_file($tmp, $destPath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not save file']);
        return;
    }
    $rawTitle = pathinfo($originalName, PATHINFO_FILENAME);
    $title = trim($rawTitle) ?: 'Untitled';
    $artistName = 'Unknown Artist';
    if (strpos($rawTitle, ' - ') !== false) {
        [$maybeArtist, $maybeTitle] = explode(' - ', $rawTitle, 2);
        $artistName = trim($maybeArtist) ?: $artistName;
        $title = trim($maybeTitle) ?: $title;
    }
    try {
        $artistRow = \Doniixify\Database::fetchOne('SELECT id FROM artists WHERE name = ? LIMIT 1', [$artistName]);
        if ($artistRow) {
            $artistId = (int)$artistRow['id'];
        } else {
            \Doniixify\Database::execute('INSERT INTO artists (name) VALUES (?)', [$artistName]);
            $artistId = (int)\Doniixify\Database::lastInsertId();
        }
        \Doniixify\Database::execute(
            'INSERT INTO songs (title, artist_id, file_path, downloaded_by, added_at) VALUES (?, ?, ?, ?, NOW())',
            [$title, $artistId, $destPath, (int)$user['id']]
        );
        $songId = (int)\Doniixify\Database::lastInsertId();
        echo json_encode([
            'song_id' => $songId,
            'title' => $title,
            'artist' => $artistName,
            'path' => $destPath,
        ]);
    } catch (\Throwable $e) {
        @unlink($destPath);
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/push/subscribe', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['endpoint'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid subscription']);
        return;
    }
    try {
        \Doniixify\Database::execute(
            "CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                p256dh VARCHAR(255),
                auth VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_endpoint (endpoint(255)),
                INDEX idx_user (user_id)
            )"
        );
        $keys = $data['keys'] ?? [];
        \Doniixify\Database::execute(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth)",
            [(int)$user['id'], (string)$data['endpoint'], (string)($keys['p256dh'] ?? ''), (string)($keys['auth'] ?? '')]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
});

$router->post('/api/push/unsubscribe', function () {
    $user = \Doniixify\Web\Session::requireLoginJson();
    header('Content-Type: application/json');
    $raw = file_get_contents('php://input') ?: '{}';
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['endpoint'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing endpoint']);
        return;
    }
    try {
        \Doniixify\Database::execute(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?',
            [(int)$user['id'], (string)$data['endpoint']]
        );
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => true]);
    }
});

$router->post('/api/devices/heartbeat', [\Doniixify\Web\DevicesController::class, 'heartbeat']);
$router->get('/api/devices/list', [\Doniixify\Web\DevicesController::class, 'listDevices']);
$router->post('/api/devices/remove', [\Doniixify\Web\DevicesController::class, 'remove']);
$router->post('/api/devices/transfer', [\Doniixify\Web\DevicesController::class, 'transfer']);
$router->post('/api/devices/control', [\Doniixify\Web\DevicesController::class, 'control']);
$router->get('/api/devices/diagnostics', [\Doniixify\Web\DevicesController::class, 'diagnostics']);
$router->get('/api/diag/log', [\Doniixify\Web\DevicesController::class, 'logSnapshot']);

$router->any('/migrate', function () {
    $user = \Doniixify\Web\Session::user();
    if ($user === null || empty($user['is_admin'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>Migrate</title><body style="font-family:system-ui;padding:40px;background:#000;color:#e7e9ea">'
           . '<h1>403 — admin login required</h1>'
           . '<p>Log in as admin first, then visit <a href="/migrate" style="color:#1d9bf0">/migrate</a> again.</p>'
           . '<p><a href="/login" style="color:#1d9bf0">→ login</a></p></body>';
        return;
    }
    $report = \Doniixify\Migrator::run();
    $status = \Doniixify\Migrator::status();
    header('Content-Type: text/html; charset=UTF-8');
    $color = $report['ok'] ? '#00ba7c' : '#f4212e';
    echo '<!doctype html><meta charset="utf-8"><title>Migrate</title>'
       . '<body style="font-family:system-ui;padding:40px;background:#000;color:#e7e9ea;max-width:800px;margin:0 auto">'
       . '<h1 style="color:' . $color . '">Migrations ' . ($report['ok'] ? 'OK' : 'FAILED') . '</h1>';
    if (!empty($report['applied'])) {
        echo '<h2 style="color:#00ba7c">Just applied (' . count($report['applied']) . ')</h2><ul>';
        foreach ($report['applied'] as $n) echo '<li><code>' . htmlspecialchars($n) . '</code></li>';
        echo '</ul>';
    } else {
        echo '<p style="color:#71767b">No new migrations to apply.</p>';
    }
    if (!empty($report['errors'])) {
        echo '<h2 style="color:#f4212e">Errors</h2><ul>';
        foreach ($report['errors'] as $e) echo '<li><pre style="background:#16181c;padding:12px;border-radius:8px">' . htmlspecialchars($e) . '</pre></li>';
        echo '</ul>';
    }
    echo '<h2>Files on disk (' . count($status['files']) . ')</h2><ul>';
    foreach ($status['files'] as $f) echo '<li><code>' . htmlspecialchars($f) . '</code></li>';
    echo '</ul>';
    echo '<h2>Applied in DB (' . count($status['applied_in_db']) . ')</h2><ul>';
    foreach ($status['applied_in_db'] as $r) echo '<li><code>' . htmlspecialchars($r['name']) . '</code> <span style="color:#71767b">' . htmlspecialchars($r['executed_at']) . '</span></li>';
    echo '</ul>';
    echo '<p style="margin-top:32px"><a href="/" style="color:#1d9bf0">← back</a> · <a href="/api/diag/log" style="color:#1d9bf0">diag log</a></p>';
    echo '</body>';
});

$router->any('/db-reset', function () {
    $user = \Doniixify\Web\Session::user();
    $skipAuth = \Doniixify\Installer::isNeeded();
    if (!$skipAuth && ($user === null || empty($user['is_admin']))) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>DB reset</title><body style="font-family:system-ui;padding:40px;background:#000;color:#e7e9ea">'
           . '<h1>403 — admin login required</h1>'
           . '<p><a href="/login" style="color:#1d9bf0">→ login</a></p></body>';
        return;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Content-Type: text/html; charset=UTF-8');
    if ($method === 'POST' && ($_POST['confirm'] ?? '') === 'DROP-ALL') {
        $report = \Doniixify\Migrator::reset();
        $color = $report['ok'] ? '#00ba7c' : '#f4212e';
        echo '<!doctype html><meta charset="utf-8"><title>DB reset</title>'
           . '<body style="font-family:system-ui;padding:40px;background:#000;color:#e7e9ea;max-width:800px;margin:0 auto">'
           . '<h1 style="color:' . $color . '">Database ' . ($report['ok'] ? 'reset OK' : 'reset FAILED') . '</h1>';
        echo '<h2>Dropped tables (' . count($report['dropped']) . ')</h2><ul>';
        foreach ($report['dropped'] as $t) echo '<li><code>' . htmlspecialchars($t) . '</code></li>';
        echo '</ul>';
        if (!empty($report['errors'])) {
            echo '<h2 style="color:#f4212e">Errors</h2><ul>';
            foreach ($report['errors'] as $e) echo '<li><pre style="background:#16181c;padding:12px;border-radius:8px">' . htmlspecialchars($e) . '</pre></li>';
            echo '</ul>';
        }
        if ($report['migration_report']) {
            $mr = $report['migration_report'];
            $mcolor = $mr['ok'] ? '#00ba7c' : '#f4212e';
            echo '<h2 style="color:' . $mcolor . '">Migrations re-run: ' . ($mr['ok'] ? 'OK' : 'FAILED') . '</h2>';
            echo '<p>Applied: ' . count($mr['applied']) . '</p><ul>';
            foreach ($mr['applied'] as $n) echo '<li><code>' . htmlspecialchars($n) . '</code></li>';
            echo '</ul>';
            if (!empty($mr['errors'])) {
                echo '<h3 style="color:#f4212e">Migration errors</h3><ul>';
                foreach ($mr['errors'] as $e) echo '<li><pre style="background:#16181c;padding:12px;border-radius:8px">' . htmlspecialchars($e) . '</pre></li>';
                echo '</ul>';
            }
        }
        echo '<p style="margin-top:32px;padding:16px;background:rgba(0,186,124,0.1);border:1px solid rgba(0,186,124,0.3);border-radius:8px">'
           . 'Next step: visit <a href="/" style="color:#00ba7c;font-weight:700">home</a> to run the installer (create admin account).</p>';
        echo '</body>';
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><title>DB reset</title>'
       . '<body style="font-family:system-ui;padding:40px;background:#000;color:#e7e9ea;max-width:600px;margin:0 auto">'
       . '<h1 style="color:#f4212e">Reset database</h1>'
       . '<div style="padding:20px;background:rgba(244,33,46,0.1);border:1px solid rgba(244,33,46,0.3);border-radius:8px;margin-bottom:24px">'
       . '<strong>This will DELETE EVERYTHING:</strong>'
       . '<ul><li>All users (you will need to re-create admin)</li>'
       . '<li>All scanned songs, artists, albums</li>'
       . '<li>All playlists and favorites</li>'
       . '<li>All devices, jam sessions, lyrics cache</li>'
       . '<li>Migrations will be re-run from scratch</li></ul>'
       . '<p>Music files on disk are NOT touched — you can re-scan after reset.</p>'
       . '</div>'
       . '<form method="POST" style="display:flex;flex-direction:column;gap:16px">'
       . '<label>Type <code style="background:#16181c;padding:4px 8px;border-radius:4px">DROP-ALL</code> to confirm:</label>'
       . '<input type="text" name="confirm" autocomplete="off" required style="padding:12px;background:#000;border:1px solid #2f3336;border-radius:8px;color:#e7e9ea;font-family:monospace;font-size:14px">'
       . '<button type="submit" style="padding:14px;background:#f4212e;color:#fff;border:0;border-radius:8px;font-weight:700;cursor:pointer">Reset database</button>'
       . '<a href="/" style="color:#71767b;text-align:center;text-decoration:none">Cancel</a>'
       . '</form></body>';
});

$router->post('/api/jam/create', [\Doniixify\Web\JamController::class, 'create']);
$router->post('/api/jam/join', [\Doniixify\Web\JamController::class, 'join']);

$router->get('/users', [\Doniixify\Web\UsersView::class, 'index']);
$router->post('/users/create', [\Doniixify\Web\UsersView::class, 'create']);

$router->dispatch();
