<?php

declare(strict_types=1);

namespace Doniixify;

use Doniixify\Subsonic\Auth;
use Doniixify\Subsonic\Response;

final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $this->normalize($path)] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST ' . $this->normalize($path)] = $handler;
    }

    public function any(string $path, callable $handler): void
    {
        $this->get($path, $handler);
        $this->post($path, $handler);
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = $this->normalize($uri);

        if (($method === 'POST' || $method === 'DELETE' || $method === 'PUT' || $method === 'PATCH')
            && str_starts_with($path, '/api/')
            && !$this->csrfExempt($path)) {
            $hasApiToken = false;
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (preg_match('/Bearer\s+[A-Za-z0-9_-]{16,64}/', $auth)) $hasApiToken = true;
            if (!empty($_GET['token'])) $hasApiToken = true;
            if (!$hasApiToken && \Doniixify\Web\Session::isLogged()) {
                $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
                if (!\Doniixify\Web\Session::validateCsrf($token)) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=UTF-8');
                    echo json_encode(['error' => 'CSRF token mismatch']);
                    return;
                }
            }
        }

        $key = "{$method} {$path}";
        if (isset($this->routes[$key])) {
            ($this->routes[$key])();
            return;
        }

        if (preg_match('#^/stream/(\d+)$#', $path, $m)) {
            \Doniixify\Web\StreamView::stream((int)$m[1]);
            return;
        }
        if (preg_match('#^/cover/(\d+)$#', $path, $m)) {
            \Doniixify\Web\StreamView::cover((int)$m[1]);
            return;
        }
        if ($path === '/debug/covers' && $method === 'GET') {
            \Doniixify\Web\StreamView::debugCovers();
            return;
        }
        if ($path === '/api/favorites/list' && $method === 'GET') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json');
            $rows = \Doniixify\Database::fetchAll(
                "SELECT item_id FROM stars WHERE user_id = ? AND item_type = 'song'",
                [(int)$user['id']]
            ) ?: [];
            echo json_encode(array_map(fn($r) => (int)$r['item_id'], $rows));
            return;
        }
        if (preg_match('#^/api/favorite/(\d+)/toggle$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\FavoriteController::toggle((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/song/(\d+)/redownload$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json');
            $sid = (int)$m[1];
            try {
                $song = \Doniixify\Database::fetchOne(
                    'SELECT s.id, s.title, s.spotify_id, ar.name AS artist_name, al.name AS album_name
                     FROM songs s
                     JOIN artists ar ON ar.id = s.artist_id
                     LEFT JOIN albums al ON al.id = s.album_id
                     WHERE s.id = ?', [$sid]
                );
                if (!$song) { http_response_code(404); echo json_encode(['error' => 'Song not found']); return; }
                $sessionUser = \Doniixify\Web\Session::user();
                $hint = [
                    'user_id' => (int)($sessionUser['id'] ?? 0),
                    'title' => (string)$song['title'],
                    'artist' => (string)$song['artist_name'],
                    'album' => (string)($song['album_name'] ?? ''),
                ];
                $target = !empty($song['spotify_id']) && preg_match('/^[A-Za-z0-9]{22}$/', $song['spotify_id'])
                    ? 'https://open.spotify.com/track/' . $song['spotify_id']
                    : null;
                if ($target === null) {
                    $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
                    if ($token) {
                        $q = urlencode($song['artist_name'] . ' ' . $song['title']);
                        $ch = curl_init('https://api.spotify.com/v1/search?type=track&limit=1&q=' . $q);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
                            CURLOPT_TIMEOUT => 6,
                            CURLOPT_SSL_VERIFYPEER => false,
                        ]);
                        $body = curl_exec($ch);
                        curl_close($ch);
                        $data = json_decode((string)$body, true);
                        $sid2 = $data['tracks']['items'][0]['id'] ?? null;
                        if ($sid2) $target = 'https://open.spotify.com/track/' . $sid2;
                    }
                }
                if ($target === null) { echo json_encode(['error' => 'Spotify match not found']); return; }
                $coverDir = \Doniixify\Env::get('COVER_CACHE_PATH', __DIR__ . '/../storage/covers');
                @unlink($coverDir . '/remote-' . $sid . '.jpg');
                @unlink($coverDir . '/remote-' . $sid . '.miss');
                \Doniixify\Downloader\YoutubeDownloader::queueBackground($target, $hint);
                echo json_encode(['ok' => true, 'queued' => true]);
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        if ($path === '/api/debug/env' && $method === 'GET') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            if (empty($user['is_admin'])) { http_response_code(403); echo json_encode(['error' => 'Admin only']); return; }
            header('Content-Type: application/json');
            $keys = ['LASTFM_API_KEY', 'LASTFM_SECRET', 'SPOTIFY_CLIENT_ID', 'SPOTIFY_CLIENT_SECRET', 'VAPID_PUBLIC_KEY'];
            $out = [];
            foreach ($keys as $k) {
                $v = \Doniixify\Env::get($k, '');
                $out[$k] = $v === '' ? '(MISSING)' : ('(set, ' . strlen($v) . ' chars, starts: ' . substr($v, 0, 4) . '…)');
            }
            $envPath = __DIR__ . '/../.env';
            $out['_env_file'] = is_file($envPath) ? $envPath : '(file not found at ' . $envPath . ')';
            $out['_env_size'] = is_file($envPath) ? filesize($envPath) . ' bytes' : 'n/a';
            $out['_all_loaded_keys'] = \Doniixify\Env::debugDump();
            echo json_encode($out, JSON_PRETTY_PRINT);
            return;
        }
        if (preg_match('#^/api/song/(\d+)/info$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json');
            try {
                $song = \Doniixify\Database::fetchOne(
                    'SELECT s.id, s.title, s.duration, ar.id AS artist_id, ar.name AS artist_name,
                            al.id AS album_id, al.name AS album_name
                     FROM songs s
                     JOIN artists ar ON ar.id = s.artist_id
                     LEFT JOIN albums al ON al.id = s.album_id
                     WHERE s.id = ?', [(int)$m[1]]
                );
                echo json_encode($song ?: ['error' => 'Not found']);
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        if ($path === '/api/covers/repair-missing' && $method === 'POST') {
            \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json');
            try {
                $coverDir = \Doniixify\Env::get('COVER_CACHE_PATH', __DIR__ . '/../storage/covers');
                $count = 0;
                if (is_dir($coverDir)) {
                    foreach (glob($coverDir . '/remote-*.miss') ?: [] as $miss) {
                        @unlink($miss);
                        $count++;
                    }
                }
                echo json_encode(['ok' => true, 'cleared' => $count]);
            } catch (\Throwable $e) {
                echo json_encode(['error' => $e->getMessage()]);
            }
            return;
        }
        if (preg_match('#^/api/admin/users/(\d+)/stats$#', $path, $m) && $method === 'GET') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $uid = (int)$m[1];
            $totals = \Doniixify\Database::fetchOne(
                'SELECT listening_seconds, last_login_at, created_at, username FROM users WHERE id = ?',
                [$uid]
            );
            if (!$totals) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }
            $favsCount = (int)(\Doniixify\Database::fetchOne(
                "SELECT COUNT(*) AS c FROM stars WHERE user_id = ? AND item_type='song'",
                [$uid]
            )['c'] ?? 0);
            $online = \Doniixify\Database::fetchOne(
                'SELECT 1 FROM active_devices WHERE user_id = ? AND last_seen > (NOW() - INTERVAL 30 SECOND) LIMIT 1',
                [$uid]
            );
            $lastDevice = \Doniixify\Database::fetchOne(
                'SELECT ad.last_seen, ad.current_song_id, s.title AS song_title, ar.name AS artist_name
                 FROM active_devices ad
                 LEFT JOIN songs s ON s.id = ad.current_song_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 WHERE ad.user_id = ?
                 ORDER BY ad.last_seen DESC LIMIT 1',
                [$uid]
            );
            $libraryCount = (int)(\Doniixify\Database::fetchOne('SELECT COUNT(*) AS c FROM songs')['c'] ?? 0);
            $playlistCount = (int)(\Doniixify\Database::fetchOne('SELECT COUNT(*) AS c FROM playlists WHERE user_id = ?', [$uid])['c'] ?? 0);
            echo json_encode([
                'username' => $totals['username'],
                'listening_seconds' => (int)($totals['listening_seconds'] ?? 0),
                'listening_hours' => round(((int)($totals['listening_seconds'] ?? 0)) / 3600, 1),
                'songs_in_library' => $libraryCount,
                'favorites' => $favsCount,
                'playlists' => $playlistCount,
                'online' => $online !== null,
                'last_seen' => $lastDevice['last_seen'] ?? null,
                'last_song_title' => $lastDevice['song_title'] ?? null,
                'last_song_artist' => $lastDevice['artist_name'] ?? null,
                'account_created' => $totals['created_at'] ?? null,
                'last_login' => $totals['last_login_at'] ?? null,
                'period_week' => ['hours' => 0, 'songs' => 0, 'top_tracks' => []],
                'period_month' => ['hours' => 0, 'songs' => 0, 'top_tracks' => []],
                'period_year' => ['hours' => 0, 'songs' => 0, 'top_tracks' => []],
                'note' => 'Period stats require play_history table (not yet present)',
            ]);
            return;
        }
        if (preg_match('#^/api/admin/songs/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $songId = (int)$m[1];
            $row = \Doniixify\Database::fetchOne('SELECT id, path FROM songs WHERE id = ?', [$songId]);
            if (!$row) {
                echo json_encode(['ok' => true, 'deleted' => $songId, 'already_gone' => true]);
                return;
            }
            \Doniixify\Scanner\Scanner::purgeSongById($songId);
            echo json_encode(['ok' => true, 'deleted' => $songId, 'path' => $row['path']]);
            return;
        }
        if ($path === '/api/search/recent' && $method === 'GET') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            $rows = \Doniixify\Database::fetchAll(
                'SELECT query, query_type, hit_count, last_searched_at
                 FROM search_history
                 WHERE user_id = ?
                 ORDER BY last_searched_at DESC
                 LIMIT 12',
                [(int)$user['id']]
            );
            echo json_encode($rows ?: []);
            return;
        }
        if ($path === '/api/search/recent/clear' && $method === 'POST') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            \Doniixify\Database::execute('DELETE FROM search_history WHERE user_id = ?', [(int)$user['id']]);
            echo json_encode(['ok' => true]);
            return;
        }
        if ($path === '/api/search/recent/remove' && $method === 'POST') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
            $q = trim((string)($input['query'] ?? ''));
            if ($q === '') { http_response_code(400); echo json_encode(['error' => 'Missing query']); return; }
            \Doniixify\Database::execute('DELETE FROM search_history WHERE user_id = ? AND query = ?', [(int)$user['id'], $q]);
            echo json_encode(['ok' => true]);
            return;
        }
        if ($path === '/api/admin/spotify-debug' && $method === 'GET') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $q = trim((string)($_GET['q'] ?? 'kolorowe sny'));
            $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
            $tokenStatus = $token === '' ? 'EMPTY (klucze nie działają lub brak w .env)' : 'OK (length=' . strlen($token) . ')';
            $items = \Doniixify\Downloader\SpotifyApi::search($q, 10, 'title');
            $preview = array_map(fn($it) => [
                'title' => $it['name'] ?? '',
                'artist' => $it['artists'][0]['name'] ?? '',
                'popularity' => $it['popularity'] ?? null,
                'spotify_id' => $it['song_id'] ?? $it['id'] ?? '',
            ], $items);
            echo json_encode([
                'query' => $q,
                'token_status' => $tokenStatus,
                'env_has_client_id' => \Doniixify\Env::get('SPOTIFY_CLIENT_ID', '') !== '',
                'env_has_client_secret' => \Doniixify\Env::get('SPOTIFY_CLIENT_SECRET', '') !== '',
                'results_count' => count($items),
                'top_10' => $preview,
                'hint' => count($items) === 0
                    ? 'No results. Check storage/download.log for [Spotify] entries.'
                    : 'OK. UI cache: clear browser sessionStorage if old results are shown.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($path === '/api/admin/clear-search-cache' && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $dir = dirname(__DIR__) . '/storage/cache';
            $removed = 0;
            foreach (glob($dir . '/*.json') ?: [] as $f) { @unlink($f); $removed++; }
            try {
                \Doniixify\Database::execute('DELETE FROM search_cache_db');
            } catch (\Throwable $e) {}
            echo json_encode(['ok' => true, 'removed_files' => $removed, 'note' => 'Browser sessionStorage: F12 → Application → Session Storage → clear']);
            return;
        }
        if ($path === '/api/admin/diag-permissions' && $method === 'GET') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); return; }
            header('Content-Type: application/json; charset=UTF-8');

            $musicPath = (string)\Doniixify\Env::get('MUSIC_PATH', '/music');
            $storagePath = dirname(__DIR__) . '/storage';
            $rootPath = dirname(__DIR__);

            $describe = function (string $p) {
                if (!file_exists($p)) return ['path' => $p, 'exists' => false];
                $uid = function_exists('posix_getpwuid') && function_exists('fileowner') ? posix_getpwuid(@fileowner($p)) : null;
                $gid = function_exists('posix_getgrgid') && function_exists('filegroup') ? posix_getgrgid(@filegroup($p)) : null;
                return [
                    'path' => $p,
                    'exists' => true,
                    'is_dir' => is_dir($p),
                    'writable' => is_writable($p),
                    'perms' => substr(sprintf('%o', @fileperms($p)), -4),
                    'owner_uid' => @fileowner($p),
                    'owner_name' => is_array($uid) ? ($uid['name'] ?? '?') : null,
                    'group_gid' => @filegroup($p),
                    'group_name' => is_array($gid) ? ($gid['name'] ?? '?') : null,
                ];
            };

            $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
            $currentUser = $currentUid !== null && function_exists('posix_getpwuid') ? posix_getpwuid($currentUid) : null;
            $currentGid = function_exists('posix_getegid') ? posix_getegid() : null;
            $currentGroup = $currentGid !== null && function_exists('posix_getgrgid') ? posix_getgrgid($currentGid) : null;

            // Test write w różnych lokalizacjach
            $writableTests = [];
            foreach ([
                '/tmp',
                getenv('HOME') ?: '/home/' . (is_array($currentUser) ? $currentUser['name'] : 'unknown'),
                $rootPath,
                $storagePath,
                $musicPath,
            ] as $tryPath) {
                if ($tryPath === '' || $tryPath === '/') continue;
                $testFile = $tryPath . '/.doniix-write-test-' . bin2hex(random_bytes(4));
                $ok = @file_put_contents($testFile, 'ok');
                if ($ok !== false) @unlink($testFile);
                $writableTests[$tryPath] = $ok !== false;
            }

            echo json_encode([
                'php_process' => [
                    'uid' => $currentUid,
                    'user' => is_array($currentUser) ? $currentUser['name'] : '?',
                    'gid' => $currentGid,
                    'group' => is_array($currentGroup) ? $currentGroup['name'] : '?',
                    'home_env' => getenv('HOME'),
                ],
                'paths' => [
                    'root' => $describe($rootPath),
                    'storage' => $describe($storagePath),
                    'music' => $describe($musicPath),
                    'storage_parent' => $describe(dirname($storagePath)),
                ],
                'writable_tests' => $writableTests,
                'recommendation' => null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($path === '/api/admin/diag-spawn' && $method === 'GET') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); return; }
            header('Content-Type: application/json; charset=UTF-8');
            $reflect = new \ReflectionClass(\Doniixify\Downloader\YoutubeDownloader::class);
            $m = $reflect->getMethod('phpBinary');
            $m->setAccessible(true);
            $cliPhp = (string)$m->invoke(null);
            $candidates = [];
            foreach (['/usr/bin/php8.5','/usr/bin/php8.4','/usr/bin/php8.3','/usr/bin/php8.2','/usr/bin/php8.1','/usr/bin/php','/usr/local/bin/php'] as $c) {
                $candidates[$c] = ['exists' => is_file($c), 'executable' => @is_executable($c)];
            }
            $whichPhp = trim((string)@shell_exec('command -v php 2>/dev/null'));
            $whichPhp85 = trim((string)@shell_exec('command -v php8.5 2>/dev/null'));
            // Test próbny spawn
            $worker = realpath(dirname(__DIR__) . '/bin/download-worker.php');
            $testCmd = escapeshellarg($cliPhp) . ' -v 2>&1';
            $cliVersion = @shell_exec($testCmd);
            echo json_encode([
                'cli_php_detected' => $cliPhp,
                'cli_php_is_fpm' => str_contains(basename($cliPhp), 'fpm'),
                'cli_php_version_output' => is_string($cliVersion) ? substr($cliVersion, 0, 500) : null,
                'candidates' => $candidates,
                'which_php' => $whichPhp,
                'which_php85' => $whichPhp85,
                'worker_script' => $worker,
                'worker_exists' => $worker !== false && is_file((string)$worker),
                'php_binary_constant' => PHP_BINARY,
            ], JSON_PRETTY_PRINT);
            return;
        }
        if ($path === '/api/admin/diag-system' && $method === 'GET') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $musicPath = (string)\Doniixify\Env::get('MUSIC_PATH', '/music');
            $ytDlp = \Doniixify\Downloader\YoutubeDownloader::ytDlpBinary();
            $ffmpeg = (string)\Doniixify\Env::get('FFMPEG_BIN', '/usr/bin/ffmpeg');
            $disabled = ini_get('disable_functions') ?: '';
            $tailFile = function (string $path, int $bytes = 4096): string {
                if (!is_file($path)) return '';
                $fh = @fopen($path, 'rb');
                if (!$fh) return '';
                $size = filesize($path);
                $off = max(0, $size - $bytes);
                @fseek($fh, $off);
                $tail = (string)stream_get_contents($fh);
                @fclose($fh);
                return $tail;
            };
            $downloadLogTail = $tailFile(dirname(__DIR__) . '/storage/download.log');
            $importLogTail = $tailFile(dirname(__DIR__) . '/storage/import.log');
            $youtubeLogTail = $tailFile(dirname(__DIR__) . '/storage/youtube_dl.log');
            $jobsDir = dirname(__DIR__) . '/storage/jobs';
            $jobsActiveCount = 0;
            $jobsRecent = [];
            if (is_dir($jobsDir)) {
                $files = glob($jobsDir . '/*.json') ?: [];
                usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
                foreach (array_slice($files, 0, 10) as $jf) {
                    $jd = json_decode((string)@file_get_contents($jf), true);
                    if (!is_array($jd)) continue;
                    $jobsRecent[] = [
                        'pid' => $jd['pid'] ?? null,
                        'artist' => $jd['artist'] ?? '',
                        'title' => $jd['title'] ?? '',
                        'status' => $jd['status'] ?? '',
                        'stage' => $jd['stage'] ?? '',
                        'progress' => $jd['progress'] ?? 0,
                        'error' => $jd['error'] ?? null,
                        'started_at' => $jd['started_at'] ?? null,
                    ];
                    $jStatus = (string)($jd['status'] ?? '');
                    if ($jStatus !== 'done' && $jStatus !== 'failed') $jobsActiveCount++;
                }
            }
            echo json_encode([
                'music_path' => $musicPath,
                'music_path_exists' => is_dir($musicPath),
                'music_path_writable' => is_dir($musicPath) ? is_writable($musicPath) : false,
                'music_path_files' => is_dir($musicPath) ? count(glob($musicPath . '/*') ?: []) : null,
                'yt_dlp_binary' => $ytDlp,
                'yt_dlp_exists' => is_executable($ytDlp) || @shell_exec('command -v ' . escapeshellarg($ytDlp)) !== null,
                'ffmpeg_binary' => $ffmpeg,
                'ffmpeg_exists' => is_executable($ffmpeg),
                'disabled_functions' => $disabled,
                'has_proc_open' => function_exists('proc_open') && !str_contains($disabled, 'proc_open'),
                'has_popen' => function_exists('popen') && !str_contains($disabled, 'popen'),
                'has_exec' => function_exists('exec') && !str_contains($disabled, 'exec'),
                'has_shell_exec' => function_exists('shell_exec'),
                'has_fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
                'has_setsid' => (string)@shell_exec('command -v setsid 2>/dev/null') !== '',
                'has_nohup' => (string)@shell_exec('command -v nohup 2>/dev/null') !== '',
                'download_log_tail' => substr($downloadLogTail, -3000),
                'import_log_tail' => substr($importLogTail, -3000),
                'youtube_log_tail' => substr($youtubeLogTail, -3000),
                'jobs_dir_exists' => is_dir($jobsDir),
                'jobs_active_count' => $jobsActiveCount,
                'jobs_recent' => $jobsRecent,
                'php_sapi' => PHP_SAPI,
                'php_binary' => PHP_BINARY,
                'php_version' => PHP_VERSION,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        if ($path === '/api/admin/refresh-spotify-metadata' && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); return; }
            header('Content-Type: application/json; charset=UTF-8');
            $rows = \Doniixify\Database::fetchAll(
                'SELECT s.id, s.title, s.spotify_id, al.id AS album_id, al.name AS album_name
                 FROM songs s
                 JOIN albums al ON al.id = s.album_id
                 WHERE s.spotify_id IS NOT NULL AND s.spotify_id != "" AND (al.name = "Singles" OR al.name = "" OR al.release_date IS NULL)
                 LIMIT 50'
            );
            $updated = 0;
            $skipped = 0;
            foreach ($rows as $r) {
                $track = \Doniixify\Downloader\SpotifyApi::getTrack((string)$r['spotify_id']);
                if (!is_array($track) || empty($track['album']['name'])) { $skipped++; continue; }
                $newAlbumName = (string)$track['album']['name'];
                $newRelease = substr((string)($track['album']['release_date'] ?? ''), 0, 10);
                $newYear = $newRelease !== '' ? (int)substr($newRelease, 0, 4) : 0;
                if ($newAlbumName === (string)$r['album_name']) { $skipped++; continue; }
                \Doniixify\Database::execute(
                    'UPDATE albums SET name = ?, release_date = ?, year = ? WHERE id = ?',
                    [$newAlbumName, $newRelease ?: null, $newYear ?: null, (int)$r['album_id']]
                );
                $updated++;
            }
            echo json_encode(['ok' => true, 'updated' => $updated, 'skipped' => $skipped, 'checked' => count($rows)]);
            return;
        }
        if ($path === '/api/admin/purge-missing' && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $deleted = \Doniixify\Scanner\Scanner::purgeMissingFiles();
            echo json_encode(['ok' => true, 'deleted' => $deleted]);
            return;
        }
        if ($path === '/api/admin/cleanup-broken' && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $removed = \Doniixify\Scanner\Scanner::purgeBrokenAndJunk();
            echo json_encode(['ok' => true, 'removed' => $removed]);
            return;
        }
        if ($path === '/api/admin/backfill-years' && $method === 'POST') {
            $sessionUser = \Doniixify\Web\Session::requireLoginJson();
            if (empty($sessionUser['is_admin'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Forbidden']);
                return;
            }
            header('Content-Type: application/json; charset=UTF-8');
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $updated = \Doniixify\Scanner\Scanner::backfillAlbumYears($limit);
            echo json_encode(['ok' => true, 'updated' => $updated]);
            return;
        }
        if (preg_match('#^/api/album/(\d+)/songs$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            $rows = \Doniixify\Database::fetchAll(
                'SELECT s.id, s.title, s.duration, ar.id AS artist_id, ar.name AS artist_name
                 FROM songs s
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE s.album_id = ?
                 ORDER BY s.track_number ASC, s.title_sort ASC',
                [(int)$m[1]]
            );
            echo json_encode($rows);
            return;
        }
        if ($path === '/sw.js' && $method === 'GET') {
            $file = dirname(__DIR__) . '/sw.js';
            if (is_file($file)) {
                header('Content-Type: application/javascript; charset=UTF-8');
                header('Service-Worker-Allowed: /');
                header('Cache-Control: no-cache');
                readfile($file);
                return;
            }
        }
        if ($path === '/.well-known/assetlinks.json' && $method === 'GET') {
            $file = dirname(__DIR__) . '/storage/builds/assetlinks.json';
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: public, max-age=600');
            if (is_file($file)) {
                readfile($file);
            } else {
                echo json_encode([]);
            }
            return;
        }
        if ($path === '/api/twa-status' && $method === 'GET') {
            header('Content-Type: application/json; charset=UTF-8');
            $assetlinksFile = dirname(__DIR__) . '/storage/builds/assetlinks.json';
            $apkFile = glob(dirname(__DIR__) . '/storage/builds/*.apk');
            $assetlinks = null;
            if (is_file($assetlinksFile)) {
                $assetlinks = json_decode((string)@file_get_contents($assetlinksFile), true);
            }
            echo json_encode([
                'assetlinks_uploaded' => is_file($assetlinksFile),
                'assetlinks_content' => $assetlinks,
                'assetlinks_url' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'music.leszczynowa5.pl') . '/.well-known/assetlinks.json',
                'apk_uploaded' => !empty($apkFile),
                'apk_size_mb' => !empty($apkFile) ? round(filesize($apkFile[0]) / 1024 / 1024, 2) : null,
                'expected_package_name' => 'pl.music.music.twa',
                'instructions' => [
                    '1. Open assetlinks_url in browser — should return JSON with package_name and sha256_cert_fingerprints',
                    '2. If empty [], you have not uploaded assetlinks.json to storage/builds/ yet',
                    '3. package_name in assetlinks MUST match expected_package_name',
                    '4. After fixing: UNINSTALL the app on phone, then reinstall from /download/android',
                    '5. Android caches DAL verification on first install — reinstall is mandatory',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return;
        }
        $streamBuild = function (string $glob, string $mime, string $fallbackName) {
            $candidates = glob(dirname(__DIR__) . '/storage/builds/' . $glob) ?: [];
            $file = $candidates ? $candidates[0] : null;
            if (!$file || !is_file($file)) {
                http_response_code(404);
                header('Content-Type: text/plain; charset=UTF-8');
                $buildsDir = dirname(__DIR__) . '/storage/builds';
                $dirExists = is_dir($buildsDir) ? 'yes' : 'no';
                $listing = is_dir($buildsDir) ? implode(', ', array_map('basename', glob($buildsDir . '/*') ?: [])) : '(directory missing)';
                echo "Build not found on server.\n\nLooking for pattern: storage/builds/{$glob}\nstorage/builds/ exists: {$dirExists}\nstorage/builds/ contents: {$listing}\n\nAdmin must upload {$fallbackName} via FTP to storage/builds/ on the server.";
                return;
            }
            while (ob_get_level() > 0) @ob_end_clean();
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($file));
            header('Content-Disposition: attachment; filename="' . basename($file) . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Accept-Ranges: bytes');
            $fp = @fopen($file, 'rb');
            if ($fp === false) {
                http_response_code(500);
                echo 'Cannot open file';
                return;
            }
            while (!feof($fp)) {
                echo fread($fp, 1024 * 256);
                @ob_flush();
                @flush();
            }
            @fclose($fp);
        };
        if ($path === '/download/android' && $method === 'GET') {
            $streamBuild('*.apk', 'application/vnd.android.package-archive', 'Doniixify.apk');
            return;
        }
        if ($path === '/download/desktop' && $method === 'GET') {
            $streamBuild('*.exe', 'application/vnd.microsoft.portable-executable', 'Doniixify.exe');
            return;
        }
        if ($path === '/api/lyrics' && $method === 'GET') {
            \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            \Doniixify\Web\Session::start();
            $now = time();
            $windowStart = (int)($_SESSION['lyrics_rl_window_start'] ?? 0);
            if ($now - $windowStart > 3600) {
                $_SESSION['lyrics_rl_window_start'] = $now;
                $_SESSION['lyrics_rl_count'] = 0;
            }
            $_SESSION['lyrics_rl_count'] = (int)($_SESSION['lyrics_rl_count'] ?? 0) + 1;
            if ($_SESSION['lyrics_rl_count'] > 60) {
                http_response_code(429);
                echo json_encode(['error' => 'rate limited']);
                return;
            }
            $artist = (string)($_GET['artist'] ?? '');
            $title = (string)($_GET['title'] ?? '');
            $duration = (int)($_GET['duration'] ?? 0);
            if ($artist === '' || $title === '') { echo json_encode(['error' => 'Missing params']); return; }
            $cacheDir = dirname(__DIR__) . '/storage/cache/lyrics';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            $cacheKey = md5(strtolower($artist . '|' . $title));
            $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
            if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
                echo @file_get_contents($cacheFile);
                return;
            }
            $url = 'https://lrclib.net/api/get?artist_name=' . rawurlencode($artist)
                . '&track_name=' . rawurlencode($title) . '&duration=' . $duration;
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['User-Agent: Doniixify/1.0'], CURLOPT_SSL_VERIFYPEER => false]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $resp = ['synced' => '', 'plain' => '', 'lyrics' => ''];
            if ($status === 200 && is_string($body)) {
                $d = json_decode($body, true);
                if (is_array($d)) {
                    $resp['synced'] = (string)($d['syncedLyrics'] ?? '');
                    $resp['plain'] = (string)($d['plainLyrics'] ?? '');
                    $resp['lyrics'] = $resp['plain'] ?: ($resp['synced'] ? preg_replace('/^\[[\d:.]+\]\s*/m', '', $resp['synced']) : '');
                }
            }
            $json = json_encode($resp);
            @file_put_contents($cacheFile, $json);
            echo $json;
            return;
        }
        if (preg_match('#^/api/lyrics/(\d+)$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\Session::requireLoginJson();
            $sid = (int)$m[1];
            $song = \Doniixify\Database::fetchOne(
                'SELECT s.title, s.duration, ar.name AS artist_name FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE s.id = ?',
                [$sid]
            );
            header('Content-Type: application/json; charset=UTF-8');
            if ($song === null) { http_response_code(404); echo json_encode(['error' => 'Song not found']); return; }
            $cacheDir = dirname(__DIR__) . '/storage/cache/lyrics';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
            $cacheFile = $cacheDir . '/' . $sid . '.txt';
            if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 2592000) {
                echo json_encode(['lyrics' => (string)@file_get_contents($cacheFile)]);
                return;
            }
            $url = 'https://lrclib.net/api/get?'
                . 'artist_name=' . rawurlencode((string)$song['artist_name'])
                . '&track_name=' . rawurlencode((string)$song['title'])
                . '&duration=' . (int)$song['duration'];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['User-Agent: Doniixify/1.0'],
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $lyrics = '';
            $synced = '';
            if ($status === 200 && is_string($body)) {
                $data = json_decode($body, true);
                if (is_array($data)) {
                    $synced = (string)($data['syncedLyrics'] ?? '');
                    $lyrics = (string)($data['plainLyrics'] ?? '');
                    if ($lyrics === '' && $synced !== '') {
                        $lyrics = preg_replace('/^\[[\d:.]+\]\s*/m', '', $synced) ?? '';
                    }
                }
            }
            if ($lyrics === '') {
                $searchUrl = 'https://lrclib.net/api/search?q=' . rawurlencode($song['artist_name'] . ' ' . $song['title']);
                $ch = curl_init($searchUrl);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => ['User-Agent: Doniixify/1.0'], CURLOPT_SSL_VERIFYPEER => false]);
                $body = curl_exec($ch);
                if ($body) {
                    $items = json_decode($body, true);
                    if (is_array($items) && !empty($items[0]['plainLyrics'])) {
                        $lyrics = (string)$items[0]['plainLyrics'];
                    }
                }
                curl_close($ch);
            }
            if ($lyrics !== '') @file_put_contents($cacheFile, $lyrics);
            if ($synced !== '') @file_put_contents($cacheFile . '.synced', $synced);
            echo json_encode([
                'lyrics' => $lyrics ?: 'No lyrics found for this track',
                'synced' => $synced,
            ]);
            return;
        }
        if (preg_match('#^/api/track-play/(\d+)$#', $path, $m) && $method === 'POST') {
            $user = \Doniixify\Web\Session::requireLoginJson();
            header('Content-Type: application/json; charset=UTF-8');
            \Doniixify\Web\Session::start();
            $now = time();
            $tpWindow = (int)($_SESSION['trackplay_rl_window_start'] ?? 0);
            if ($now - $tpWindow > 60) {
                $_SESSION['trackplay_rl_window_start'] = $now;
                $_SESSION['trackplay_rl_count'] = 0;
            }
            $_SESSION['trackplay_rl_count'] = (int)($_SESSION['trackplay_rl_count'] ?? 0) + 1;
            if ($_SESSION['trackplay_rl_count'] > 30) {
                http_response_code(429);
                echo json_encode(['error' => 'rate limited']);
                return;
            }
            $songId = (int)$m[1];
            \Doniixify\Database::execute(
                'INSERT INTO user_song_plays (user_id, song_id, play_count, last_played_at)
                 VALUES (?, ?, 1, NOW())
                 ON DUPLICATE KEY UPDATE play_count = play_count + 1, last_played_at = NOW()',
                [(int)$user['id'], $songId]
            );
            \Doniixify\Database::execute('UPDATE songs SET play_count = play_count + 1 WHERE id = ?', [$songId]);
            echo json_encode(['ok' => true]);
            return;
        }
        if ($path === '/api/smart-queue/extend' && $method === 'POST') {
            \Doniixify\Web\SmartQueueController::extend();
            return;
        }
        if ($path === '/api/version' && $method === 'GET') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            $jsPath = __DIR__ . '/../assets/js/app.js';
            $cssPath = __DIR__ . '/../assets/css/app.css';
            $jsTs = is_file($jsPath) ? (int)filemtime($jsPath) : 0;
            $cssTs = is_file($cssPath) ? (int)filemtime($cssPath) : 0;
            echo json_encode([
                'js' => $jsTs,
                'css' => $cssTs,
                'version' => max($jsTs, $cssTs),
                'now' => time(),
            ]);
            return;
        }
        if ($path === '/api/me/last-played' && $method === 'GET') {
            header('Content-Type: application/json');
            $row = \Doniixify\Database::fetchOne(
                'SELECT s.id, s.title, s.duration, usp.last_played_at, usp.play_count AS user_play_count,
                        ar.id AS artist_id, ar.name AS artist_name,
                        al.id AS album_id, al.name AS album_name, al.cover_id AS album_cover_id
                 FROM user_song_plays usp
                 JOIN songs s ON s.id = usp.song_id
                 JOIN artists ar ON ar.id = s.artist_id
                 JOIN albums al ON al.id = s.album_id
                 WHERE usp.user_id = ?
                 ORDER BY usp.last_played_at DESC
                 LIMIT 1',
                [(int)$user['id']]
            );
            if ($row === null) {
                echo json_encode(null);
            } else {
                echo json_encode($row);
            }
            return;
        }
        if ($path === '/api/me/recently-played' && $method === 'GET') {
            header('Content-Type: application/json');
            $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
            $rows = \Doniixify\Database::fetchAll(
                'SELECT s.id, s.title, s.duration, usp.last_played_at, usp.play_count AS user_play_count,
                        ar.id AS artist_id, ar.name AS artist_name,
                        al.id AS album_id, al.name AS album_name, al.cover_id AS album_cover_id
                 FROM user_song_plays usp
                 JOIN songs s ON s.id = usp.song_id
                 JOIN artists ar ON ar.id = s.artist_id
                 JOIN albums al ON al.id = s.album_id
                 WHERE usp.user_id = ?
                 ORDER BY usp.last_played_at DESC
                 LIMIT ' . $limit,
                [(int)$user['id']]
            );
            echo json_encode($rows);
            return;
        }
        if (preg_match('#^/radio/(\d+)$#', $path, $m)) {
            \Doniixify\Web\RadioController::view((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/smart-queue/(\d+)$#', $path, $m)) {
            \Doniixify\Web\SmartQueueController::build((int)$m[1]);
            return;
        }
        if ($path === '/api/playlists' && $method === 'GET') {
            \Doniixify\Web\PlaylistController::listJson();
            return;
        }
        if ($path === '/api/library/has-track' && $method === 'GET') {
            \Doniixify\Web\LibraryController::hasTrack();
            return;
        }
        if ($path === '/api/playlists/create' && $method === 'POST') {
            \Doniixify\Web\PlaylistController::create();
            return;
        }
        if ($path === '/api/playlists/import' && $method === 'POST') {
            \Doniixify\Web\PlaylistController::import();
            return;
        }
        if (preg_match('#^/api/playlists/(\d+)/add$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\PlaylistController::addSong((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/playlists/(\d+)/songs/(\d+)/remove$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\PlaylistController::removeSong((int)$m[1], (int)$m[2]);
            return;
        }
        if (preg_match('#^/api/playlists/(\d+)/songs$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\PlaylistController::songsJson((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/playlists/(\d+)/import-tracks$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\PlaylistController::importTracks((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/playlists/(\d+)/rename$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\PlaylistController::rename((int)$m[1]);
            return;
        }
        if (preg_match('#^/playlist/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\PlaylistController::delete((int)$m[1]);
            return;
        }
        if (preg_match('#^/playlist/(\d+)$#', $path, $m)) {
            \Doniixify\Web\PlaylistController::view((int)$m[1]);
            return;
        }
        if (preg_match('#^/artist/(\d+)$#', $path, $m)) {
            \Doniixify\Web\Views::artist((int)$m[1]);
            return;
        }
        if (preg_match('#^/discover/(.+)$#', $path, $m)) {
            \Doniixify\Web\Views::discover(urldecode($m[1]));
            return;
        }
        if (preg_match('#^/downloads/([A-Za-z0-9._-]+)$#', $path, $m)) {
            \Doniixify\Web\Session::requireLogin();
            $file = dirname(__DIR__) . '/storage/builds/' . $m[1];
            if (!is_file($file)) {
                http_response_code(404);
                echo 'Build not found. Admin must upload to storage/builds/' . htmlspecialchars($m[1]);
                return;
            }
            $mime = match (strtolower(pathinfo($m[1], PATHINFO_EXTENSION))) {
                'exe' => 'application/vnd.microsoft.portable-executable',
                'apk' => 'application/vnd.android.package-archive',
                'msix' => 'application/msix',
                'dmg' => 'application/x-apple-diskimage',
                default => 'application/octet-stream',
            };
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . filesize($file));
            header('Content-Disposition: attachment; filename="' . $m[1] . '"');
            header('Cache-Control: public, max-age=3600');
            readfile($file);
            return;
        }
        if (preg_match('#^/album/(\d+)$#', $path, $m)) {
            \Doniixify\Web\Views::album((int)$m[1]);
            return;
        }
        if (preg_match('#^/users/(\d+)/password$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\UsersView::changePassword((int)$m[1]);
            return;
        }
        if (preg_match('#^/users/(\d+)/toggle-admin$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\UsersView::toggleAdmin((int)$m[1]);
            return;
        }
        if (preg_match('#^/users/(\d+)/delete$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\UsersView::delete((int)$m[1]);
            return;
        }
        if (preg_match('#^/api/jam/([A-Za-z0-9]{4,8})/state$#', $path, $m) && $method === 'GET') {
            \Doniixify\Web\JamController::state($m[1]);
            return;
        }
        if (preg_match('#^/api/jam/([A-Za-z0-9]{4,8})/sync$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\JamController::sync($m[1]);
            return;
        }
        if (preg_match('#^/api/jam/([A-Za-z0-9]{4,8})/leave$#', $path, $m) && $method === 'POST') {
            \Doniixify\Web\JamController::leave($m[1]);
            return;
        }

        if (str_starts_with($path, '/rest/')) {
            $this->subsonic404();
            return;
        }

        if ($path === '/library' && $method === 'GET') {
            \Doniixify\Web\Views::library();
            return;
        }
        if ($path === '/favorites' && $method === 'GET') {
            \Doniixify\Web\Views::library();
            return;
        }

        http_response_code(404);
        echo '404 Not Found';
    }

    private function normalize(string $path): string
    {
        $path = preg_replace('#\.view$#', '', $path) ?: $path;
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    private function csrfExempt(string $path): bool
    {
        if (str_starts_with($path, '/api/lyrics')) return true;
        if (str_starts_with($path, '/api/cover')) return true;
        if (str_starts_with($path, '/api/logs/client')) return true;
        if (str_starts_with($path, '/api/playback/command')) return true;
        if (str_starts_with($path, '/api/jam/')) return true;
        if (str_starts_with($path, '/api/devices/')) return true;
        if ($path === '/api/me/session') return true;
        if ($path === '/api/lastfm/now-playing') return true;
        if ($path === '/api/lastfm/scrobble') return true;
        return false;
    }

    private function subsonic404(): void
    {
        $params = array_merge($_GET, $_POST);
        $format = $params['f'] ?? 'xml';
        $callback = $params['callback'] ?? null;
        Response::send(
            Response::error(Response::ERR_NOT_FOUND, 'Endpoint not implemented'),
            $format,
            $callback
        );
    }

    public static function requireAuth(): array
    {
        $params = array_merge($_GET, $_POST);
        $user = Auth::authenticate($params);
        if ($user === null) {
            $dir = __DIR__ . '/../storage';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $client = trim((string)($params['c'] ?? '')) ?: '?';
            $username = (string)($params['u'] ?? '?');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
            $path = (string)($_SERVER['REQUEST_URI'] ?? '?');
            @file_put_contents($dir . '/devices.log',
                '[' . date('Y-m-d H:i:s') . "] AUTH FAIL path={$path} client={$client} user={$username} ip={$ip}\n",
                FILE_APPEND | LOCK_EX);
            $format = $params['f'] ?? 'xml';
            $callback = $params['callback'] ?? null;
            Response::send(
                Response::error(Response::ERR_WRONG_CREDENTIALS, 'Wrong username or password'),
                $format,
                $callback
            );
            exit;
        }
        return $user;
    }

    public static function params(): array
    {
        return array_merge($_GET, $_POST);
    }
}
