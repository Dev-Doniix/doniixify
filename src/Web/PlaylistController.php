<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Downloader\YoutubeDownloader;
use Doniixify\Scanner\Scanner;

final class PlaylistController
{
    public static function listJson(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $rows = Database::fetchAll(
            'SELECT id, name, song_count FROM playlists WHERE user_id = ? ORDER BY name',
            [$user['id']]
        );
        echo json_encode($rows);
    }

    public static function create(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');
        $songId = isset($input['song_id']) ? (int)$input['song_id'] : null;

        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Playlist name cannot be empty']);
            return;
        }

        Database::execute(
            'INSERT INTO playlists (user_id, name, created_at, changed_at) VALUES (?, ?, NOW(), NOW())',
            [$user['id'], $name]
        );
        $playlistId = (int)Database::lastInsertId();

        if ($songId !== null) {
            self::addSongToPlaylist($playlistId, $songId);
        }

        echo json_encode(['id' => $playlistId, 'name' => $name]);
    }

    public static function rename(int $playlistId): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        if ($owner === null || (int)$owner['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid name']);
            return;
        }
        Database::execute('UPDATE playlists SET name = ?, changed_at = NOW() WHERE id = ?', [$name, $playlistId]);
        echo json_encode(['ok' => true, 'name' => $name]);
    }

    public static function songsJson(int $playlistId): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        if ($owner === null || (int)$owner['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
        $rows = Database::fetchAll(
            'SELECT s.id, s.title, ar.name AS artist_name
             FROM playlist_songs ps
             JOIN songs s ON s.id = ps.song_id
             JOIN artists ar ON ar.id = s.artist_id
             WHERE ps.playlist_id = ? ORDER BY ps.position',
            [$playlistId]
        );
        echo json_encode($rows);
    }

    public static function addSong(int $playlistId): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        if ($owner === null || (int)$owner['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $songId = (int)($input['song_id'] ?? 0);
        if ($songId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing song']);
            return;
        }

        self::addSongToPlaylist($playlistId, $songId);
        echo json_encode(['ok' => true]);
    }

    public static function removeSong(int $playlistId, int $songId): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        if ($owner === null || (int)$owner['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
        Database::execute('DELETE FROM playlist_songs WHERE playlist_id = ? AND song_id = ?', [$playlistId, $songId]);
        Database::execute(
            'UPDATE playlists SET song_count = (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = ?),
             duration = (SELECT COALESCE(SUM(s.duration),0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = ?),
             changed_at = NOW() WHERE id = ?',
            [$playlistId, $playlistId, $playlistId]
        );
        echo json_encode(['ok' => true]);
    }

    public static function import(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $url = trim((string)($input['url'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));

        $source = self::detectSource($url);
        if ($source === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported URL. Only Spotify URLs (playlist / album / artist / track) are accepted.']);
            return;
        }
        if ($name === '') {
            $fetched = self::fetchSpotifyNameFast($url);
            $name = $fetched !== '' ? $fetched : (ucfirst($source) . ' import · ' . date('Y-m-d H:i'));
        }
        if (mb_strlen($name) > 200) {
            $name = mb_substr($name, 0, 200);
        }

        $spotifyId = '';
        if (preg_match('#spotify\.com/(?:playlist|album|artist|track)/([A-Za-z0-9]+)#i', $url, $sm)) {
            $spotifyId = $sm[1];
        }
        $existing = $spotifyId !== ''
            ? Database::fetchOne(
                'SELECT id, name FROM playlists WHERE user_id = ? AND comment LIKE ? ORDER BY id DESC LIMIT 1',
                [$user['id'], '%' . $spotifyId . '%']
            )
            : Database::fetchOne(
                'SELECT id, name FROM playlists WHERE user_id = ? AND comment LIKE ? ORDER BY id DESC LIMIT 1',
                [$user['id'], '%' . $url . '%']
            );
        if ($existing !== null) {
            self::log("import dedupe: playlist={$existing['id']} already exists for user={$user['id']} url={$url}");
            echo json_encode([
                'id' => (int)$existing['id'],
                'name' => $existing['name'],
                'source' => $source,
                'queued' => false,
                'existing' => true,
            ]);
            return;
        }

        Database::execute(
            'INSERT INTO playlists (user_id, name, comment, import_status, import_updated_at, created_at, changed_at) VALUES (?, ?, ?, ?, NOW(), NOW(), NOW())',
            [$user['id'], $name, "Imported from {$source}\n{$url}", 'pending']
        );
        $playlistId = (int)Database::lastInsertId();

        $importUserId = (int)$user['id'];
        self::log("import queued playlist={$playlistId}");
        // Najpierw spawnujemy CLI worker — FPM uwalnia się natychmiast.
        self::spawnImportWorker($playlistId, $importUserId, $url, $name);

        $response = json_encode([
            'id' => $playlistId,
            'name' => $name,
            'source' => $source,
            'queued' => true,
        ]);
        header('Content-Length: ' . strlen((string)$response));
        echo $response;
    }

    private static function spawnImportWorker(int $playlistId, int $userId, string $url, string $name): void
    {
        $worker = realpath(__DIR__ . '/../../bin/import-worker.php');
        if ($worker === false || !is_file($worker)) {
            self::log("spawnImportWorker FAIL: worker not found at " . __DIR__ . '/../../bin/import-worker.php');
            self::scheduleFallbackShutdown($playlistId, $userId, $url, $name);
            return;
        }
        $php = self::detectPhpBinary();
        $cmd = escapeshellarg($php) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg((string)$playlistId) . ' '
            . escapeshellarg((string)$userId) . ' '
            . escapeshellarg($url) . ' '
            . escapeshellarg($name);

        if (PHP_OS_FAMILY === 'Windows') {
            $winCmd = 'start /B "" ' . $cmd . ' > NUL 2>&1';
            @pclose(@popen($winCmd, 'r'));
            self::log("spawned worker (Win) playlist={$playlistId} php={$php}");
            return;
        }

        $hasSetsid = false;
        $whichSetsid = @shell_exec('command -v setsid 2>/dev/null');
        if (is_string($whichSetsid) && trim($whichSetsid) !== '') $hasSetsid = true;
        $prefix = $hasSetsid ? 'setsid -f ' : 'nohup ';
        $finalCmd = $prefix . $cmd . ' </dev/null >/dev/null 2>&1 &';

        $spawned = false;
        if (function_exists('proc_open') && !str_contains((string)ini_get('disable_functions'), 'proc_open')) {
            $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
            $proc = @proc_open(['/bin/sh', '-c', $finalCmd], $descriptors, $pipes);
            if (is_resource($proc)) { @proc_close($proc); $spawned = true; }
        }
        if (!$spawned && function_exists('popen')) {
            $fp = @popen($finalCmd, 'r');
            if ($fp !== false) { @pclose($fp); $spawned = true; }
        }
        if (!$spawned && function_exists('exec')) {
            @exec($finalCmd);
            $spawned = true;
        }
        self::log("spawned worker playlist={$playlistId} php={$php} setsid=" . ($hasSetsid ? '1' : '0') . " ok=" . ($spawned ? '1' : '0'));
    }

    private static function detectPhpBinary(): string
    {
        return YoutubeDownloader::phpBinary();
    }

    private static function scheduleFallbackShutdown(int $playlistId, int $userId, string $url, string $name): void
    {
        register_shutdown_function(function () use ($url, $userId, $playlistId, $name) {
            ignore_user_abort(true);
            @set_time_limit(300);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                self::importPlaylistTracks($url, $userId, $playlistId);
                self::fetchAndUpdatePlaylistName($playlistId, $name, $url);
            } catch (\Throwable $e) {
                self::log("fallback shutdown error playlist={$playlistId}: " . $e->getMessage());
            }
        });
    }

    private static function importPlaylistTracks(string $url, int $userId, int $playlistId): void
    {
        self::log("import START playlist={$playlistId} url={$url}");
        self::setImportStatus($playlistId, 'fetching');

        $tracks = self::fetchSpotifyTracks($url);
        self::log("import fetched tracks count=" . count($tracks) . " playlist={$playlistId}");
        if (empty($tracks)) {
            self::log("import FAIL no tracks playlist={$playlistId}");
            self::setImportStatus($playlistId, 'no_tracks');
            return;
        }

        self::cacheTracks($playlistId, $tracks);
        self::setImportStatus($playlistId, 'linking');
        self::linkExistingToPlaylist($playlistId, $tracks);
        self::updatePlaylistCounts($playlistId);

        $row = Database::fetchOne('SELECT COUNT(*) AS c FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
        $linkedCount = (int)($row['c'] ?? 0);
        $totalCount = count($tracks);
        self::log("import linked existing playlist={$playlistId} linked={$linkedCount}/{$totalCount}");

        if ($linkedCount >= $totalCount) {
            self::log("import all in library playlist={$playlistId} skipping downloads");
            self::setImportStatus($playlistId, 'complete');
            return;
        }

        $missing = self::triggerMissingDownloads($tracks, $userId, $playlistId);
        self::log("import triggered " . count($missing) . " downloads playlist={$playlistId}");
        if (empty($missing)) {
            self::setImportStatus($playlistId, 'complete');
            return;
        }

        // Downloady idą w tle (każdy yt-dlp w osobnym procesie).
        // Po pobraniu YoutubeDownloader::download() linkuje track do playlisty (hint['playlist_id']).
        // Krótki monitoring tylko żeby wczesne wyniki zostały zlinkowane — bez 45-sekundowego loopa.
        self::setImportStatus($playlistId, 'downloading');
        $startTime = time();
        $totalTracks = count($tracks);
        $lastLinkedCount = 0;
        while (time() - $startTime < 90) {
            sleep(6);
            self::linkExistingToPlaylist($playlistId, $tracks);
            self::updatePlaylistCounts($playlistId);
            $row = Database::fetchOne('SELECT COUNT(*) AS c FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
            $linkedNow = (int)($row['c'] ?? 0);
            self::log("import progress playlist={$playlistId} linked={$linkedNow}/{$totalTracks}");
            if ($linkedNow >= $totalTracks) {
                $lastLinkedCount = $linkedNow;
                break;
            }
            $lastLinkedCount = $linkedNow;
        }
        self::setImportStatus($playlistId, $lastLinkedCount >= $totalTracks ? 'complete' : 'partial');
        self::log("import worker END playlist={$playlistId} linked={$lastLinkedCount}/{$totalTracks}");
    }

    private static function cacheTracks(int $playlistId, array $tracks): void
    {
        try {
            Database::execute(
                'UPDATE playlists SET import_cache_json = ?, import_updated_at = NOW() WHERE id = ?',
                [json_encode($tracks, JSON_UNESCAPED_UNICODE), $playlistId]
            );
        } catch (\Throwable $e) { self::log("cacheTracks err: " . $e->getMessage()); }
    }

    private static function setImportStatus(int $playlistId, string $status): void
    {
        try {
            Database::execute(
                'UPDATE playlists SET import_status = ?, import_updated_at = NOW() WHERE id = ?',
                [$status, $playlistId]
            );
        } catch (\Throwable $e) {}
    }

    private static function linkExistingToPlaylist(int $playlistId, array $tracks): void
    {
        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        $uid = (int)($owner['user_id'] ?? 0);
        $have = $uid > 0
            ? Database::fetchAll('SELECT s.id, LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE s.downloaded_by = ?', [$uid])
            : Database::fetchAll('SELECT s.id, LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s JOIN artists ar ON ar.id = s.artist_id');
        $exactMap = [];
        $normMap = [];
        $slugMap = [];
        $titleSlugMap = [];
        foreach ($have as $h) {
            $artist = (string)$h['a'];
            $title = (string)$h['t'];
            $id = (int)$h['id'];
            $exactMap[$artist . '||' . $title] = $id;
            $normMap[self::normalizeArtist($artist) . '||' . self::normalizeTitle($title)] = $id;
            $slugMap[self::slugify($artist) . '||' . self::slugify($title)] = $id;
            $titleSlug = self::slugify($title);
            if (mb_strlen($titleSlug) >= 6) {
                $titleSlugMap[$titleSlug] = $id;
            }
        }

        $existingRows = Database::fetchAll('SELECT song_id FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
        $existingSet = [];
        foreach ($existingRows as $r) $existingSet[(int)$r['song_id']] = true;

        $row = Database::fetchOne('SELECT COALESCE(MAX(position), 0) AS p FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
        $position = (int)($row['p'] ?? 0) + 1;

        $missed = [];
        foreach ($tracks as $t) {
            $artist = (string)($t['artist'] ?? '');
            $title = (string)($t['title'] ?? '');
            $songId = $exactMap[mb_strtolower($artist) . '||' . mb_strtolower($title)] ?? null;
            if ($songId === null) {
                $songId = $normMap[self::normalizeArtist($artist) . '||' . self::normalizeTitle($title)] ?? null;
            }
            if ($songId === null) {
                $songId = $slugMap[self::slugify($artist) . '||' . self::slugify($title)] ?? null;
            }
            if ($songId === null) {
                $titleSlug = self::slugify($title);
                if (mb_strlen($titleSlug) >= 6 && isset($titleSlugMap[$titleSlug])) {
                    $songId = $titleSlugMap[$titleSlug];
                }
            }
            if ($songId === null) {
                $missed[] = $artist . ' — ' . $title;
                continue;
            }
            if (isset($existingSet[$songId])) continue;
            try {
                Database::execute(
                    'INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)',
                    [$playlistId, $songId, $position]
                );
                $existingSet[$songId] = true;
                $position++;
            } catch (\Throwable $e) {}
        }
        if (!empty($missed)) {
            self::log("link MISSED playlist={$playlistId} count=" . count($missed) . " tracks: " . implode(' | ', array_slice($missed, 0, 20)));
        }
    }

    private static function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $map = [
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ñ'=>'n','ç'=>'c','ß'=>'ss',
            '’'=>"'", '‘'=>"'", '"'=>'"', '"'=>'"',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('#\(feat\.?[^)]+\)|\[feat\.?[^\]]+\]#iu', '', $s) ?? $s;
        $s = preg_replace('#[^a-z0-9]+#', '', $s) ?? $s;
        return $s;
    }

    private static function normalizeTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('#\s*\(feat\.[^)]+\)#iu', '', $t) ?? $t;
        $t = preg_replace('#\s*\[feat\.[^\]]+\]#iu', '', $t) ?? $t;
        $t = preg_replace('#\s*-\s*(remaster(ed)?|live|acoustic|demo|extended|radio edit|single version|album version|deluxe|bonus track).*$#iu', '', $t) ?? $t;
        $t = preg_replace('#\s*\((remaster(ed)?|live|acoustic|demo|extended|radio edit|single version|album version|deluxe|bonus track)[^)]*\)#iu', '', $t) ?? $t;
        $t = preg_replace('#\s+#u', ' ', $t) ?? $t;
        return trim($t);
    }

    private static function normalizeArtist(string $artist): string
    {
        $a = mb_strtolower(trim($artist));
        foreach ([',', ' & ', ' ft ', ' ft. ', ' feat ', ' feat. ', ' x '] as $sep) {
            $pos = mb_strpos($a, $sep);
            if ($pos !== false) {
                $a = trim(mb_substr($a, 0, $pos));
                break;
            }
        }
        return $a;
    }

    private static function triggerMissingDownloads(array $tracks, int $userId, int $playlistId = 0): array
    {
        $lockDir = dirname(__DIR__, 2) . '/storage/locks';
        if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
        $lockFile = $lockDir . '/triggerdl.lock';
        $lockHandle = @fopen($lockFile, 'c');
        $hasLock = false;
        if ($lockHandle) {
            $hasLock = @flock($lockHandle, LOCK_EX);
        }

        try {
            $have = Database::fetchAll(
                'SELECT LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE s.downloaded_by = ?',
                [$userId]
            );
            $haveSet = [];
            foreach ($have as $h) $haveSet[$h['a'] . '||' . $h['t']] = true;

        $activeSet = [];
        foreach (\Doniixify\Downloader\JobTracker::all(300) as $j) {
            $jStatus = (string)($j['status'] ?? '');
            if ($jStatus === 'done') continue;
            if ($jStatus === 'failed') {
                $finishedAt = (string)($j['finished_at'] ?? '');
                if ($finishedAt !== '' && (time() - strtotime($finishedAt)) < 1800) {
                    $jKey = mb_strtolower((string)($j['artist'] ?? '')) . '||' . mb_strtolower((string)($j['title'] ?? ''));
                    if ($jKey !== '||') $activeSet[$jKey] = true;
                }
                continue;
            }
            $jKey = mb_strtolower((string)($j['artist'] ?? '')) . '||' . mb_strtolower((string)($j['title'] ?? ''));
            if ($jKey !== '||') $activeSet[$jKey] = true;
        }

        $missing = [];
        $spawned = 0;
        $skipped = 0;
        $alreadyActive = 0;
        foreach ($tracks as $t) {
            $title = (string)($t['title'] ?? '');
            $artist = (string)($t['artist'] ?? '');
            if ($title === '' || $artist === '') { $skipped++; continue; }

            $key = mb_strtolower($artist) . '||' . mb_strtolower($title);
            if (isset($haveSet[$key])) continue;
            if (isset($activeSet[$key])) { $alreadyActive++; $missing[] = $t; continue; }

            $tUrl = (string)($t['url'] ?? '');
            $spotifyId = (string)($t['spotify_id'] ?? $t['song_id'] ?? '');
            if ($spotifyId === '' && preg_match('#/track/([A-Za-z0-9]{22})#', $tUrl, $m)) {
                $spotifyId = $m[1];
            }
            if ($tUrl === '' && $spotifyId !== '') {
                $tUrl = 'https://open.spotify.com/track/' . $spotifyId;
            }
            if ($tUrl === '') {
                // Ostatni resort: użyj search URL który YoutubeDownloader rozpozna i sam zrobi resolve.
                $tUrl = 'https://open.spotify.com/search/' . rawurlencode($artist . ' ' . $title);
            }

            $hint = [
                'title' => $title,
                'artist' => $artist,
                'album' => (string)($t['album'] ?? ''),
                'user_id' => $userId,
            ];
            if ($playlistId > 0) $hint['playlist_id'] = $playlistId;
            if ($spotifyId !== '') {
                $hint['song_id'] = $spotifyId;
                $hint['spotify_id'] = $spotifyId;
            }
            if (!empty($t['cover_url'])) $hint['cover_url'] = (string)$t['cover_url'];
            if (!empty($t['release_date'])) $hint['release_date'] = substr((string)$t['release_date'], 0, 10);
            if (!empty($t['duration_ms'])) $hint['duration_ms'] = (int)$t['duration_ms'];

            try {
                YoutubeDownloader::queueBackground($tUrl, $hint);
                $spawned++;
            } catch (\Throwable $e) {
                self::log("queueBackground FAIL {$artist} - {$title}: " . $e->getMessage());
            }
            $missing[] = $t;
        }
            self::log("triggerMissingDownloads: spawned={$spawned} alreadyActive={$alreadyActive} skipped={$skipped} missing_total=" . count($missing) . " library_have=" . count($haveSet));
            return $missing;
        } finally {
            if ($hasLock && $lockHandle) {
                @flock($lockHandle, LOCK_UN);
            }
            if ($lockHandle) {
                @fclose($lockHandle);
            }
        }
    }

    private static function updatePlaylistCounts(int $playlistId): void
    {
        Database::execute(
            'UPDATE playlists SET song_count = (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = ?),
             duration = (SELECT COALESCE(SUM(s.duration),0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = ?),
             changed_at = NOW() WHERE id = ?',
            [$playlistId, $playlistId, $playlistId]
        );
    }

    private static function fetchAndUpdatePlaylistName(int $playlistId, string $current, string $url): void
    {
        if (!str_starts_with($current, 'Spotify import') && !str_starts_with($current, 'Album import')) return;
        $fetched = self::fetchSpotifyName($url);
        if ($fetched !== '' && $fetched !== $current) {
            $name = mb_substr($fetched, 0, 200);
            Database::execute('UPDATE playlists SET name = ? WHERE id = ?', [$name, $playlistId]);
        }
    }

    private static function downloadMissingTracks(string $playlistUrl, int $userId): void
    {
        $tracks = self::fetchSpotifyTracks($playlistUrl);
        self::log("downloadMissingTracks: fetched " . count($tracks) . " tracks from " . $playlistUrl);
        if (empty($tracks)) return;

        $have = Database::fetchAll(
            'SELECT LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s
             JOIN artists ar ON ar.id = s.artist_id'
        );
        $haveSet = [];
        foreach ($have as $h) $haveSet[$h['a'] . '||' . $h['t']] = true;

        $sent = 0;
        $skipped = 0;
        foreach ($tracks as $t) {
            $title = (string)($t['title'] ?? '');
            $artist = (string)($t['artist'] ?? '');
            if ($title === '' || $artist === '') { $skipped++; continue; }
            $key = mb_strtolower($artist) . '||' . mb_strtolower($title);
            if (isset($haveSet[$key])) { $skipped++; continue; }
            $tUrl = (string)($t['url'] ?? '');
            $spotifyId = (string)($t['spotify_id'] ?? $t['song_id'] ?? '');
            if ($spotifyId === '' && preg_match('#/track/([A-Za-z0-9]{22})#', $tUrl, $m)) $spotifyId = $m[1];
            if ($tUrl === '' && $spotifyId !== '') $tUrl = 'https://open.spotify.com/track/' . $spotifyId;
            if ($tUrl === '') $tUrl = 'https://open.spotify.com/search/' . rawurlencode($artist . ' ' . $title);

            $hint = ['title' => $title, 'artist' => $artist, 'album' => (string)($t['album'] ?? '')];
            if ($spotifyId !== '') { $hint['song_id'] = $spotifyId; $hint['spotify_id'] = $spotifyId; }
            if (!empty($t['cover_url'])) $hint['cover_url'] = (string)$t['cover_url'];
            if (!empty($t['release_date'])) $hint['release_date'] = substr((string)$t['release_date'], 0, 10);

            try {
                YoutubeDownloader::queueBackground($tUrl, $hint);
                $sent++;
            } catch (\Throwable $e) {
                self::log("downloadMissingTracks queueBg FAIL {$artist} - {$title}: " . $e->getMessage());
            }
            if ($sent % 5 === 0) usleep(500000);
        }
        self::log("downloadMissingTracks: sent={$sent} skipped(have)={$skipped}");
    }

    private static function fetchSpotifyName(string $url): string
    {
        $oembed = 'https://open.spotify.com/oembed?url=' . urlencode($url);
        [$body, $status] = self::httpRequest('GET', $oembed, '');
        if ($status >= 200 && $status < 300 && $body !== '') {
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['title'])) {
                return trim((string)$data['title']);
            }
        }
        return '';
    }

    private static function fetchSpotifySingleTrack(string $url): array
    {
        $oembed = 'https://open.spotify.com/oembed?url=' . urlencode($url);
        [$body, $status] = self::httpRequest('GET', $oembed, '');
        if ($status >= 200 && $status < 300 && $body !== '') {
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['title'])) {
                $title = trim((string)$data['title']);
                $artist = '';
                if (preg_match('#^(.+?)\s+(?:by|·|-)\s+(.+)$#iu', $title, $m)) {
                    $title = trim($m[1]);
                    $artist = trim($m[2]);
                }
                return [['title' => $title, 'artist' => $artist, 'url' => $url]];
            }
        }
        return [];
    }

    private static function fetchSpotifyNameFast(string $url): string
    {
        if (!function_exists('curl_init')) return '';
        $oembed = 'https://open.spotify.com/oembed?url=' . urlencode($url);
        $ch = curl_init($oembed);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status >= 200 && $status < 300 && is_string($body)) {
            $data = json_decode($body, true);
            if (is_array($data) && !empty($data['title'])) {
                return trim((string)$data['title']);
            }
        }
        return '';
    }

    public static function importTracks(int $playlistId): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $pl = Database::fetchOne('SELECT user_id, comment, import_cache_json, import_status, import_updated_at FROM playlists WHERE id = ?', [$playlistId]);
        if ($pl === null || (int)$pl['user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied']);
            return;
        }
        $status = (string)($pl['import_status'] ?? '');

        $tracks = [];
        if (!empty($pl['import_cache_json'])) {
            $cached = json_decode((string)$pl['import_cache_json'], true);
            if (is_array($cached)) $tracks = $cached;
        }
        if (empty($tracks) && in_array($status, ['', 'pending', 'no_tracks', 'fetching'], true)) {
            if (preg_match('#(https?://[^\s]+)#i', (string)$pl['comment'], $m)) {
                $live = self::fetchSpotifyTracks($m[1]);
                if (!empty($live)) {
                    $tracks = $live;
                    self::cacheTracks($playlistId, $tracks);
                    self::setImportStatus($playlistId, 'linking');
                    self::linkExistingToPlaylist($playlistId, $tracks);
                    self::updatePlaylistCounts($playlistId);
                    self::triggerMissingDownloads($tracks, (int)$pl['user_id'], $playlistId);
                    self::setImportStatus($playlistId, 'downloading');
                    self::log("importTracks fallback sync recovered " . count($tracks) . " tracks + triggered downloads playlist={$playlistId}");
                }
            }
        }

        if (in_array($status, ['pending', 'fetching', 'linking', 'downloading'], true) && !empty($tracks)) {
            self::linkExistingToPlaylist($playlistId, $tracks);
            self::updatePlaylistCounts($playlistId);
            $haveSongs = Database::fetchOne('SELECT COUNT(*) AS c FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
            $linkedNow = (int)($haveSongs['c'] ?? 0);
            if ($linkedNow >= count($tracks)) {
                self::setImportStatus($playlistId, 'complete');
                $status = 'complete';
            } else {
                self::triggerMissingDownloads($tracks, (int)$pl['user_id'], $playlistId);
                \Doniixify\Downloader\YoutubeDownloader::processPendingQueue();
                self::setImportStatus($playlistId, 'downloading');
                $status = 'downloading';
            }
        }
        if (empty($tracks)) {
            $errorMsg = null;
            if (preg_match('#spotify\.com/playlist/(37i9[A-Za-z0-9]+)#i', (string)$pl['comment'])) {
                $errorMsg = 'Spotify editorial playlists (Today\'s Top Hits, RapCaviar etc.) are not accessible via API since November 2024. Use a personal playlist instead.';
                self::setImportStatus($playlistId, 'failed_editorial');
                $status = 'failed_editorial';
            } else {
                $updatedAt = (string)($pl['import_updated_at'] ?? '');
                $ageSec = $updatedAt !== '' ? (time() - strtotime($updatedAt)) : 0;
                if ($ageSec > 120 && in_array($status, ['', 'pending', 'fetching'], true)) {
                    $errorMsg = 'Could not fetch tracks from Spotify. The URL may be private, expired or invalid. Check that the playlist is public.';
                    self::setImportStatus($playlistId, 'failed_fetch');
                    $status = 'failed_fetch';
                }
            }
            echo json_encode([
                'tracks' => [],
                'have' => 0,
                'missing' => 0,
                'total' => 0,
                'status' => $status !== '' ? $status : 'pending',
                'error' => $errorMsg,
            ]);
            return;
        }

        $have = Database::fetchAll(
            'SELECT LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s
             JOIN artists ar ON ar.id = s.artist_id'
        );
        $haveSet = [];
        foreach ($have as $h) $haveSet[$h['a'] . '||' . $h['t']] = true;

        $jobByKey = [];
        foreach (\Doniixify\Downloader\JobTracker::all(200) as $j) {
            $jk = mb_strtolower((string)($j['artist'] ?? '')) . '||' . mb_strtolower((string)($j['title'] ?? ''));
            if ($jk === '||') continue;
            $jstatus = (string)($j['status'] ?? '');
            if ($jstatus === 'done') continue;
            if (isset($jobByKey[$jk])) continue;
            $jobByKey[$jk] = $j;
        }

        $result = [];
        $missingCount = 0;
        foreach ($tracks as $t) {
            $title = (string)($t['title'] ?? '');
            $artist = (string)($t['artist'] ?? '');
            $key = mb_strtolower($artist) . '||' . mb_strtolower($title);
            $exists = isset($haveSet[$key]);
            if (!$exists) $missingCount++;
            $entry = [
                'title' => $title,
                'artist' => $artist,
                'url' => $t['url'] ?? '',
                'have' => $exists,
                'key' => $key,
            ];
            if (!$exists && isset($jobByKey[$key])) {
                $job = $jobByKey[$key];
                $entry['progress'] = (int)($job['progress'] ?? 0);
                $entry['stage'] = (string)($job['stage'] ?? 'queued');
                $entry['job_status'] = (string)($job['status'] ?? 'starting');
            }
            $result[] = $entry;
        }
        echo json_encode([
            'tracks' => $result,
            'have' => count($result) - $missingCount,
            'missing' => $missingCount,
            'total' => count($result),
            'status' => $status !== '' ? $status : 'complete',
        ]);
    }

    private static function fetchSpotifyTracks(string $url): array
    {
        if (preg_match('#spotify\.com/track/([A-Za-z0-9]+)#i', $url, $tm)) {
            $single = self::fetchSpotifySingleTrack($url);
            if (!empty($single)) {
                self::log("single track fetched: " . $single[0]['artist'] . " - " . $single[0]['title']);
                return $single;
            }
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $embed = self::scrapeSpotifyEmbed($url);
            if (!empty($embed)) {
                self::log("embed ok attempt={$attempt} tracks=" . count($embed));
                return $embed;
            }
        }
        $openApi = self::fetchSpotifyOpenApi($url);
        if (!empty($openApi)) {
            self::log("openapi ok tracks=" . count($openApi));
            return $openApi;
        }
        return [];
    }

    private static function fetchSpotifyOpenApi(string $url): array
    {
        if (!preg_match('#spotify\.com/(playlist|album)/([A-Za-z0-9]+)#i', $url, $m)) return [];
        $kind = strtolower($m[1]);
        $id = $m[2];

        $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
        if ($token === '') {
            self::log("openapi: no Spotify token (check SPOTIFY_CLIENT_ID / SPOTIFY_CLIENT_SECRET in .env)");
            return [];
        }
        self::log("openapi token OK");

        $out = [];
        $offset = 0;
        $limit = $kind === 'playlist' ? 100 : 50;
        $albumMeta = null;
        if ($kind === 'album') {
            [$albumBody, $albumStatus] = self::httpRequestWithAuth('GET', "https://api.spotify.com/v1/albums/{$id}", $token);
            if ($albumStatus === 200) {
                $albumMeta = json_decode($albumBody, true);
            }
        }
        for ($page = 0; $page < 5; $page++) {
            $apiUrl = $kind === 'playlist'
                ? "https://api.spotify.com/v1/playlists/{$id}/tracks?limit={$limit}&offset={$offset}&fields=items(track(name,artists(name),id,duration_ms,album(name,images,release_date))),next"
                : "https://api.spotify.com/v1/albums/{$id}/tracks?limit={$limit}&offset={$offset}";
            [$body, $status] = self::httpRequestWithAuth('GET', $apiUrl, $token);
            self::log("openapi {$kind}/{$id} offset={$offset} status={$status} bytes=" . strlen((string)$body));
            if ($status !== 200) break;
            $data = json_decode($body, true);
            if (!is_array($data) || empty($data['items'])) break;
            foreach ($data['items'] as $it) {
                $track = $kind === 'playlist' ? ($it['track'] ?? null) : $it;
                if (!is_array($track)) continue;
                $title = (string)($track['name'] ?? '');
                $artists = $track['artists'] ?? [];
                $artist = (is_array($artists) && !empty($artists)) ? (string)($artists[0]['name'] ?? '') : '';
                $tid = (string)($track['id'] ?? '');
                $tUrl = $tid !== '' ? "https://open.spotify.com/track/{$tid}" : '';
                if ($title === '') continue;

                $albumName = '';
                $coverUrl = '';
                $releaseDate = '';
                $durationMs = (int)($track['duration_ms'] ?? 0);
                if ($kind === 'playlist') {
                    $alb = $track['album'] ?? null;
                    if (is_array($alb)) {
                        $albumName = (string)($alb['name'] ?? '');
                        $releaseDate = (string)($alb['release_date'] ?? '');
                        $imgs = $alb['images'] ?? [];
                        if (is_array($imgs) && !empty($imgs)) {
                            $coverUrl = (string)($imgs[0]['url'] ?? '');
                        }
                    }
                } elseif ($albumMeta !== null) {
                    $albumName = (string)($albumMeta['name'] ?? '');
                    $releaseDate = (string)($albumMeta['release_date'] ?? '');
                    $imgs = $albumMeta['images'] ?? [];
                    if (is_array($imgs) && !empty($imgs)) {
                        $coverUrl = (string)($imgs[0]['url'] ?? '');
                    }
                }

                $out[] = [
                    'title' => $title,
                    'artist' => $artist,
                    'url' => $tUrl,
                    'spotify_id' => $tid,
                    'album' => $albumName,
                    'cover_url' => $coverUrl,
                    'release_date' => $releaseDate,
                    'duration_ms' => $durationMs,
                ];
            }
            if (empty($data['next'])) break;
            $offset += $limit;
        }
        return $out;
    }

    private static function httpRequestWithAuth(string $method, string $url, string $token): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ],
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$resp === false ? '' : $resp, $status];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return [$resp === false ? '' : $resp, $status];
    }

    private static function scrapeSpotifyEmbed(string $url): array
    {
        if (!preg_match('#spotify\.com/(playlist|album)/([A-Za-z0-9]+)#i', $url, $m)) return [];
        $kind = $m[1];
        $id = $m[2];
        $embedUrl = "https://open.spotify.com/embed/{$kind}/{$id}";
        [$html, $status] = self::httpRequest('GET', $embedUrl, '');
        self::log("scrape embed status={$status} bytes=" . strlen((string)$html));
        if ($status < 200 || $status >= 300 || $html === '') return [];
        if (!preg_match('#<script id="__NEXT_DATA__"[^>]*>(.*?)</script>#s', $html, $mm)) {
            self::log("scrape embed __NEXT_DATA__ not found");
            return [];
        }
        $data = json_decode($mm[1], true);
        if (!is_array($data)) {
            self::log("scrape embed json decode failed");
            return [];
        }

        $tracks = self::findTrackArray($data);
        if (empty($tracks)) {
            self::log("scrape embed no tracks found in JSON");
            return [];
        }

        $out = [];
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $title = (string)($t['title'] ?? $t['name'] ?? '');
            $artist = '';
            if (!empty($t['subtitle'])) $artist = (string)$t['subtitle'];
            elseif (!empty($t['artists']) && is_array($t['artists'])) {
                $first = $t['artists'][0] ?? null;
                $artist = is_array($first) ? (string)($first['name'] ?? '') : (string)$first;
            }
            $uri = (string)($t['uri'] ?? '');
            $tid = '';
            if (preg_match('#spotify:track:([A-Za-z0-9]+)#', $uri, $um)) $tid = $um[1];
            $tUrl = $tid !== '' ? "https://open.spotify.com/track/{$tid}" : '';
            if ($title === '') continue;
            $out[] = ['title' => $title, 'artist' => $artist, 'url' => $tUrl];
        }
        self::log("scrape embed parsed " . count($out) . " tracks");
        return $out;
    }

    private static function findTrackArray(array $data): array
    {
        $paths = [
            ['props','pageProps','state','data','entity','trackList'],
            ['props','pageProps','state','data','entity','tracks'],
            ['props','pageProps','state','data','entity','items'],
            ['props','pageProps','entity','trackList'],
            ['props','pageProps','tracks'],
            ['props','pageProps','data','entity','trackList'],
        ];
        foreach ($paths as $path) {
            $cur = $data;
            $ok = true;
            foreach ($path as $key) {
                if (!is_array($cur) || !isset($cur[$key])) { $ok = false; break; }
                $cur = $cur[$key];
            }
            if ($ok && is_array($cur) && !empty($cur)) {
                $first = reset($cur);
                if (is_array($first) && (isset($first['title']) || isset($first['name']) || isset($first['uri']))) {
                    return $cur;
                }
            }
        }
        $found = [];
        self::scanForTracks($data, $found, 0);
        return $found;
    }

    private static function scanForTracks($node, array &$found, int $depth): void
    {
        if ($depth > 8 || !is_array($node) || !empty($found)) return;
        if (isset($node[0]) && is_array($node[0]) && (isset($node[0]['title']) || isset($node[0]['name'])) && (isset($node[0]['uri']) || isset($node[0]['subtitle']))) {
            $found = $node;
            return;
        }
        foreach ($node as $v) {
            if (is_array($v)) self::scanForTracks($v, $found, $depth + 1);
            if (!empty($found)) return;
        }
    }

    private static function log(string $msg): void
    {
        try {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/import.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    private static function detectSource(string $url): ?string
    {
        if ($url === '') return null;
        if (preg_match('#^https?://(open\.)?spotify\.com/(playlist|album|artist|track)/#i', $url)) return 'spotify';
        return null;
    }

    private static function httpRequest(string $method, string $url, string $body): array
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: text/html,application/json,*/*',
                    'Accept-Language: en-US,en;q=0.9',
                ],
                CURLOPT_USERAGENT => $ua,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => false,
            ];
            if ($body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return [$resp === false ? '' : $resp, $status, $err];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => "Content-Type: application/json\r\nUser-Agent: {$ua}\r\n",
            'content' => $body, 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return [$resp === false ? '' : $resp, $status, $resp === false ? 'fopen failed' : ''];
    }

    private static function httpPost(string $url, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $resp = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return [$resp === false ? '' : $resp, $status, $err];
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        return [$resp === false ? '' : $resp, $status, $resp === false ? 'fopen failed' : ''];
    }

    private static function addSongToPlaylist(int $playlistId, int $songId): void
    {
        $exists = Database::fetchOne(
            'SELECT 1 FROM playlist_songs WHERE playlist_id = ? AND song_id = ?',
            [$playlistId, $songId]
        );
        if ($exists !== null) return;

        $pos = (int)Database::fetchOne(
            'SELECT COALESCE(MAX(position), 0) + 1 AS p FROM playlist_songs WHERE playlist_id = ?',
            [$playlistId]
        )['p'];

        Database::execute(
            'INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)',
            [$playlistId, $songId, $pos]
        );
        Database::execute(
            'UPDATE playlists SET song_count = (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = ?),
             duration = (SELECT COALESCE(SUM(s.duration),0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = ?),
             changed_at = NOW() WHERE id = ?',
            [$playlistId, $playlistId, $playlistId]
        );
    }

    public static function delete(int $playlistId): void
    {
        $user = Session::requireLogin();
        $owner = Database::fetchOne('SELECT user_id FROM playlists WHERE id = ?', [$playlistId]);
        if ($owner !== null && (int)$owner['user_id'] === (int)$user['id']) {
            Database::execute('DELETE FROM playlists WHERE id = ?', [$playlistId]);
        }
        header('Location: /');
    }

    public static function view(int $playlistId): void
    {
        $user = Session::requireLogin();
        $pl = Database::fetchOne('SELECT * FROM playlists WHERE id = ?', [$playlistId]);
        if ($pl === null || (int)$pl['user_id'] !== (int)$user['id']) {
            http_response_code(404);
            Layout::render('/', '<div class="empty-state"><h2>Not found</h2><p>This playlist does not exist.</p></div>');
            return;
        }

        $songs = Database::fetchAll(
            'SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name
             FROM playlist_songs ps
             JOIN songs s ON s.id = ps.song_id
             JOIN artists ar ON ar.id = s.artist_id
             JOIN albums al ON al.id = s.album_id
             WHERE ps.playlist_id = ?
             ORDER BY ps.position',
            [$playlistId]
        );

        $importStatus = (string)($pl['import_status'] ?? '');
        // Auto-recovery: jeśli "downloading" lub "fetching" > 5 min bez aktualizacji, oznacz jako stale
        if (in_array($importStatus, ['fetching', 'linking', 'downloading'], true)) {
            $importUpdated = (string)($pl['import_updated_at'] ?? '');
            if ($importUpdated !== '' && (time() - strtotime($importUpdated)) > 300) {
                Database::execute(
                    'UPDATE playlists SET import_status = ?, import_updated_at = NOW() WHERE id = ?',
                    ['stale', $playlistId]
                );
                $importStatus = 'stale';
            }
        }
        $importing = $importStatus !== '' && !in_array($importStatus, ['complete', 'no_tracks', 'stale', 'partial'], true);
        $cached = [];
        if (!empty($pl['import_cache_json'])) {
            $decoded = json_decode((string)$pl['import_cache_json'], true);
            if (is_array($decoded)) $cached = $decoded;
        }
        $pendingTracks = [];
        if ($importing && !empty($cached)) {
            $haveKeys = [];
            foreach ($songs as $s) {
                $haveKeys[mb_strtolower((string)$s['artist_name']) . '||' . mb_strtolower((string)$s['title'])] = true;
            }
            foreach ($cached as $t) {
                $key = mb_strtolower((string)($t['artist'] ?? '')) . '||' . mb_strtolower((string)($t['title'] ?? ''));
                if (!isset($haveKeys[$key])) $pendingTracks[] = $t;
            }
        }
        $totalCached = count($cached);

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($pl['name']) ?></h1>
                <div class="page-subtitle"><?= count($songs) ?> track<?= count($songs) === 1 ? '' : 's' ?> · playlist</div>
            </div>
            <div class="playlist-filter-wrap">
                <input type="text" id="playlist-filter" class="playlist-filter" placeholder="Find in playlist" autocomplete="off">
            </div>
        </header>
        <?php if (!empty($songs)): ?>
        <div class="actions-bar" data-playlist-id="<?= $playlistId ?>">
            <button class="action-play" id="playlist-play-all" data-playlist-id="<?= $playlistId ?>" title="Play / Pause"><span id="playlist-play-icon"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg></span></button>
            <button class="action-btn action-shuffle" id="playlist-shuffle" title="Shuffle"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/></svg></button>
            <div class="action-more-wrap">
                <button class="action-btn" id="playlist-more" title="More"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg></button>
                <div class="action-more-menu" id="playlist-more-menu">
                    <div class="ctx-item" data-pl-act="queue"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15V6"/><path d="M18.5 18a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/><path d="M12 12H3"/><path d="M16 6H3"/><path d="M12 18H3"/></svg>Add playlist to queue</div>
                    <div class="ctx-item" data-pl-act="jam"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/></svg>Start Jam from playlist</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($importing): ?>
            <div class="import-banner" data-importing="1" data-playlist-id="<?= $playlistId ?>" data-status="<?= htmlspecialchars($importStatus) ?>" data-have="<?= count($songs) ?>">
                <div class="import-banner-spinner"></div>
                <div class="import-banner-text">
                    <div class="import-banner-title">Importing playlist · <?= htmlspecialchars(ucfirst($importStatus)) ?></div>
                    <div class="import-banner-sub"><?= count($songs) ?> of <?= $totalCached ?: '?' ?> downloaded · <?= count($pendingTracks) ?> pending · refreshes automatically</div>
                </div>
            </div>
        <?php endif; ?>
        <?php
        if (empty($songs) && empty($pendingTracks) && !$importing) {
            ?><div class="empty-state"><div class="icon"><?= Icons::svg('list', 32) ?></div><h2>Empty playlist</h2><p>Add tracks via the right-click context menu on any song.</p></div><?php
        } elseif (empty($songs) && empty($pendingTracks) && $importing) {
            ?><div class="empty-state"><div class="icon"><?= Icons::svg('list', 32) ?></div><h2>Fetching tracks…</h2><p>Spotify metadata is being read. The list will appear in seconds.</p></div><?php
        } else {
            echo '<div data-playlist-view="1" data-playlist-id="' . $playlistId . '">';
            if (!empty($songs)) Views::renderSongsTablePublic($songs);
            if (!empty($pendingTracks)) {
                echo '<div class="pending-tracks-section"><div class="pending-tracks-label">Downloading · <span data-pending-count>' . count($pendingTracks) . '</span></div>';
                foreach ($pendingTracks as $t) {
                    $pTitle = (string)($t['title'] ?? '');
                    $pArtist = (string)($t['artist'] ?? '');
                    $pKey = mb_strtolower($pArtist) . '||' . mb_strtolower($pTitle);
                    echo '<div class="pending-track" data-key="' . htmlspecialchars($pKey, ENT_QUOTES) . '" data-search="' . htmlspecialchars(mb_strtolower($pTitle . ' ' . $pArtist), ENT_QUOTES) . '">';
                    echo '<div class="pending-track-spinner"></div>';
                    echo '<div class="pending-track-meta">';
                    echo '<div class="pending-track-title">' . htmlspecialchars($pTitle) . '</div>';
                    echo '<div class="pending-track-artist">' . htmlspecialchars($pArtist) . '</div>';
                    echo '<div class="pending-track-stage" data-stage>queued</div>';
                    echo '</div>';
                    echo '<div class="pending-track-progress"><div class="pending-track-progress-bar" data-progress style="width:0%"></div></div>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
        <script>
        (function () {
            const filter = document.getElementById('playlist-filter');
            if (!filter) return;
            const apply = () => {
                const q = filter.value.trim().toLowerCase();
                document.querySelectorAll('[data-playlist-view] tr[data-title]').forEach(tr => {
                    const t = (tr.dataset.title || '').toLowerCase();
                    const a = (tr.dataset.artist || '').toLowerCase();
                    const al = (tr.dataset.album || '').toLowerCase();
                    tr.style.display = (!q || t.includes(q) || a.includes(q) || al.includes(q)) ? '' : 'none';
                });
                document.querySelectorAll('[data-playlist-view] .pending-track').forEach(el => {
                    const s = el.dataset.search || '';
                    el.style.display = (!q || s.includes(q)) ? '' : 'none';
                });
            };
            filter.addEventListener('input', apply);
            filter.addEventListener('keydown', e => { if (e.key === 'Escape') { filter.value = ''; apply(); filter.blur(); } });
        })();
        </script>
        <?php
        Layout::render('/playlist/' . $playlistId, ob_get_clean());
    }
}
