<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Downloader\SpotifyApi;
use Doniixify\Downloader\YoutubeDownloader;
use Doniixify\Downloader\LastfmApi;

final class SmartQueueController
{
    private static int $lastSpawnedDownloads = 0;

    public static function extend(): void
    {
        $user = Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');
        $uid = (int)$user['id'];

        $logFile = __DIR__ . '/../../storage/autodl.log';
        if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0775, true);
        $logExtend = function(string $msg) use ($logFile): void {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [extend] {$msg}\n", FILE_APPEND | LOCK_EX);
        };

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $seedIds = array_values(array_unique(array_filter(array_map('intval', $input['seeds'] ?? []))));
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $input['exclude'] ?? []))));
        $logExtend("user={$uid} seeds=" . implode(',', $seedIds) . " excludes=" . count($excludeIds));
        if (empty($seedIds)) {
            $logExtend("ABORT: no seeds");
            echo json_encode([]);
            return;
        }
        $seedIds = array_slice($seedIds, 0, 5);

        $placeholders = implode(',', array_fill(0, count($seedIds), '?'));
        $seedRows = Database::fetchAll(
            'SELECT s.id, s.spotify_id, s.title, s.artist_id, ar.name AS artist_name
             FROM songs s JOIN artists ar ON ar.id = s.artist_id
             WHERE s.id IN (' . $placeholders . ')',
            $seedIds
        );
        $seedSpotifyIds = [];
        $firstSeedArtist = '';
        $firstSeedTitle = '';
        foreach ($seedRows as $sr) {
            $sid = (string)($sr['spotify_id'] ?? '');
            if ($sid !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $sid)) {
                $seedSpotifyIds[] = $sid;
            }
            if ($firstSeedArtist === '') {
                $firstSeedArtist = (string)($sr['artist_name'] ?? '');
                $firstSeedTitle = (string)($sr['title'] ?? '');
            }
        }

        self::$lastSpawnedDownloads = 0;
        $excludeId = !empty($excludeIds) ? (int)$excludeIds[0] : 0;
        $recs = [];
        if (!empty($seedSpotifyIds)) {
            $firstSeed = array_shift($seedSpotifyIds);
            $recs = self::fetchSpotifyRecommendations($firstSeed, $excludeId, $uid, $seedSpotifyIds, 30);
            $logExtend("spotify_rec firstSeed={$firstSeed} returned=" . count($recs));
        } else {
            $logExtend("no spotify_id seeds available — skipping Spotify rec");
        }
        if (empty($recs) && $firstSeedArtist !== '' && $firstSeedTitle !== '') {
            $lfmConfigured = LastfmApi::isConfigured() ? '1' : '0';
            $logExtend("trying LFM fallback artist='{$firstSeedArtist}' title='{$firstSeedTitle}' lfm_configured={$lfmConfigured}");
            $recs = self::fetchLastfmRecommendations($firstSeedArtist, $firstSeedTitle, $excludeId, $uid, 50);
            $logExtend("lfm_rec returned=" . count($recs) . " spawned_so_far=" . self::$lastSpawnedDownloads);
        }
        if (empty($recs)) {
            $logExtend("ABORT: empty recs after Spotify+LFM (will return [] to client)");
            echo json_encode([]);
            return;
        }

        $excludeSet = array_flip($excludeIds);
        $out = [];
        foreach ($recs as $rec) {
            $id = (int)($rec['id'] ?? 0);
            if ($id <= 0 || isset($excludeSet[$id])) continue;
            $out[] = $rec;
            if (count($out) >= 25) break;
        }

        if (count($out) < 12 && !empty($seedRows)) {
            $haveIds = array_flip(array_merge(array_column($out, 'id'), $excludeIds));
            $haveIds[0] = true;
            $primaryArtistId = 0;
            foreach ($seedIds as $sid) {
                foreach ($seedRows as $sr) {
                    if ((int)$sr['id'] === (int)$sid) {
                        $primaryArtistId = (int)($sr['artist_id'] ?? 0);
                        break 2;
                    }
                }
            }
            if ($primaryArtistId > 0) {
                $fallback = Database::fetchAll(
                    'SELECT s.id, s.title, s.artist_id, s.play_count, s.spotify_id, ar.name AS artist_name
                     FROM songs s
                     JOIN artists ar ON ar.id = s.artist_id
                     WHERE s.id NOT IN (' . implode(',', array_keys($haveIds)) . ')
                           AND s.artist_id = ?
                           AND s.downloaded_by = ?
                     ORDER BY s.play_count DESC, RAND() LIMIT 10',
                    [$primaryArtistId, $uid]
                );
                foreach ($fallback as $r) {
                    $out[] = $r;
                    if (count($out) >= 20) break;
                }
            }
        }

        header('X-Spotify-Spawned: ' . self::$lastSpawnedDownloads);
        echo json_encode($out);
    }

    public static function build(int $songId): void
    {
        $user = Session::requireLogin();
        header('Content-Type: application/json; charset=UTF-8');

        $logFile = __DIR__ . '/../../storage/autodl.log';
        if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0775, true);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] build called for songId={$songId} user={$user['id']}\n", FILE_APPEND | LOCK_EX);

        $seed = Database::fetchOne(
            'SELECT s.id, s.title, s.artist_id, s.album_id, s.spotify_id, al.genre, al.year, ar.name AS artist_name
             FROM songs s JOIN albums al ON al.id = s.album_id JOIN artists ar ON ar.id = s.artist_id
             WHERE s.id = ?',
            [$songId]
        );
        if ($seed === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Song not found']);
            return;
        }

        $uid = (int)$user['id'];

        $seedArtistId = (int)$seed['artist_id'];

        $playlistTracks = self::fetchTracks(
            'JOIN playlist_songs ps ON ps.song_id = s.id
             JOIN playlists pl ON pl.id = ps.playlist_id
             WHERE pl.user_id = ? AND s.id != ?
                   AND ps.playlist_id IN (
                       SELECT ps2.playlist_id FROM playlist_songs ps2
                       JOIN playlists pl2 ON pl2.id = ps2.playlist_id
                       WHERE ps2.song_id = ? AND pl2.user_id = ?
                   )
             ORDER BY RAND() LIMIT 40',
            [$uid, $songId, $songId, $uid]
        );
        $artistTracks = self::fetchTracks(
            'WHERE s.artist_id = ? AND s.id != ? AND s.downloaded_by = ? ORDER BY s.play_count DESC, RAND() LIMIT 20',
            [$seedArtistId, $songId, $uid]
        );
        $albumTracks = self::fetchTracks(
            'WHERE s.album_id = ? AND s.id != ? AND s.downloaded_by = ? ORDER BY s.track_number LIMIT 20',
            [$seed['album_id'], $songId, $uid]
        );
        $genreTracks = [];
        if (!empty($seed['genre'])) {
            $genreTracks = self::fetchTracks(
                'JOIN albums al ON al.id = s.album_id WHERE al.genre = ? AND s.id != ? AND s.downloaded_by = ? ORDER BY s.play_count DESC, RAND() LIMIT 40',
                [$seed['genre'], $songId, $uid]
            );
        }
        $eraTracks = [];
        if (!empty($seed['year'])) {
            $yearLow = (int)$seed['year'] - 3;
            $yearHigh = (int)$seed['year'] + 3;
            $eraTracks = self::fetchTracks(
                'JOIN albums al ON al.id = s.album_id WHERE al.year BETWEEN ? AND ? AND s.id != ? AND s.downloaded_by = ? ORDER BY s.play_count DESC, RAND() LIMIT 30',
                [$yearLow, $yearHigh, $songId, $uid]
            );
        }
        $favoriteTracks = self::fetchTracks(
            'JOIN stars st ON st.item_id = s.id AND st.item_type = "song"
             WHERE st.user_id = ? AND s.id != ?
             ORDER BY RAND() LIMIT 30',
            [$uid, $songId]
        );
        $popularTracks = self::fetchTracks(
            'WHERE s.id != ? AND s.downloaded_by = ? AND s.play_count > 0 ORDER BY s.play_count DESC, RAND() LIMIT 30',
            [$songId, $uid]
        );

        $spotifyRecTracks = [];
        $seedSpotifyId = (string)($seed['spotify_id'] ?? '');
        if ($seedSpotifyId === '' || !preg_match('/^[A-Za-z0-9]{22}$/', $seedSpotifyId)) {
            // Brak spotify_id w lokalnej bazie → spróbuj lookup po artist+title
            $seedSpotifyId = self::lookupSpotifyTrackId((string)$seed['artist_name'], (string)$seed['title']);
            if ($seedSpotifyId !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $seedSpotifyId)) {
                try {
                    Database::execute('UPDATE songs SET spotify_id = ? WHERE id = ?', [$seedSpotifyId, $songId]);
                } catch (\Throwable $e) {}
            }
        }
        if ($seedSpotifyId !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $seedSpotifyId)) {
            $spotifyRecTracks = self::fetchSpotifyRecommendations($seedSpotifyId, $songId, $uid);
        }
        if (empty($spotifyRecTracks)) {
            $spotifyRecTracks = self::fetchLastfmRecommendations((string)$seed['artist_name'], (string)$seed['title'], $songId, $uid, 30);
        }

        $sources = [
            ['name' => 'spotify_rec', 'weight' => 4.5, 'rows' => $spotifyRecTracks],
            ['name' => 'playlist',    'weight' => 5.0, 'rows' => $playlistTracks],
            ['name' => 'artist',      'weight' => 4.0, 'rows' => $artistTracks],
            ['name' => 'album',       'weight' => 3.0, 'rows' => $albumTracks],
            ['name' => 'genre',       'weight' => 3.0, 'rows' => $genreTracks],
            ['name' => 'fav',         'weight' => 1.0, 'rows' => $favoriteTracks],
            ['name' => 'era',         'weight' => 1.0, 'rows' => $eraTracks],
            ['name' => 'popular',     'weight' => 0.5, 'rows' => $popularTracks],
        ];

        $candidates = [];
        foreach ($sources as $src) {
            foreach ($src['rows'] as $row) {
                $id = (int)$row['id'];
                if ($id === (int)$songId) continue;
                if (!isset($candidates[$id])) {
                    $candidates[$id] = [
                        'id' => $id,
                        'title' => $row['title'],
                        'artist_name' => $row['artist_name'],
                        'artist_id' => (int)($row['artist_id'] ?? 0),
                        'play_count' => (int)($row['play_count'] ?? 0),
                        'score' => 0.0,
                        'sources' => [],
                    ];
                }
                $candidates[$id]['score'] += $src['weight'];
                $candidates[$id]['sources'][] = $src['name'];
            }
        }

        $candidatesByCid = $candidates;
        usort($candidates, function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return $b['play_count'] <=> $a['play_count'];
        });

        $queue = [];
        $seenIds = [(int)$songId];
        $lastArtistRun = ['id' => 0, 'count' => 0];
        $seedArtistUsed = 0;
        $deferred = [];

        $tryAppend = function ($cand) use (&$queue, &$seenIds, &$lastArtistRun, &$seedArtistUsed, $seedArtistId) {
            $aid = (int)$cand['artist_id'];
            if ($aid > 0 && $aid === $lastArtistRun['id'] && $lastArtistRun['count'] >= 2) return false;
            if ($aid > 0 && $aid === $seedArtistId && $seedArtistUsed >= 8) return false;
            $queue[] = ['id' => $cand['id'], 'title' => $cand['title'], 'artist_name' => $cand['artist_name']];
            $seenIds[] = $cand['id'];
            if ($aid > 0 && $aid === $lastArtistRun['id']) {
                $lastArtistRun['count']++;
            } else {
                $lastArtistRun = ['id' => $aid, 'count' => 1];
            }
            if ($aid === $seedArtistId) $seedArtistUsed++;
            return true;
        };

        foreach ($candidates as $cand) {
            if (count($queue) >= 50) break;
            if (in_array($cand['id'], $seenIds, true)) continue;
            if (!$tryAppend($cand)) $deferred[] = $cand;
        }

        foreach ($deferred as $cand) {
            if (count($queue) >= 50) break;
            if (in_array($cand['id'], $seenIds, true)) continue;
            $tryAppend($cand);
        }

        if (count($queue) < 50) {
            $needed = 50 - count($queue);
            if (empty($seenIds)) $seenIds = [0];
            $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
            $params = $seenIds;
            $params[] = $uid;
            $randomFill = Database::fetchAll(
                "SELECT s.id, s.title, ar.name AS artist_name
                 FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE s.id NOT IN ($placeholders) AND s.downloaded_by = ?
                 ORDER BY RAND() LIMIT $needed",
                $params
            );
            foreach ($randomFill as $r) {
                $queue[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'artist_name' => $r['artist_name']];
                $seenIds[] = (int)$r['id'];
            }
        }

        @file_put_contents($logFile,
            '[' . date('Y-m-d H:i:s') . "] queue built for songId={$songId} candidates=" . count($candidates)
            . " final=" . count($queue) . "\n", FILE_APPEND | LOCK_EX);
        foreach ($queue as $pos => $q) {
            $cid = (int)$q['id'];
            $srcInfo = isset($candidatesByCid[$cid])
                ? ('score=' . $candidatesByCid[$cid]['score'] . ' src=' . implode(',', $candidatesByCid[$cid]['sources']))
                : 'src=random-fill';
            @file_put_contents($logFile,
                "  [" . ($pos + 1) . "] {$q['artist_name']} — {$q['title']} ($srcInfo)\n", FILE_APPEND | LOCK_EX);
        }

        if (self::$lastSpawnedDownloads > 0) {
            header('X-Spotify-Spawned: ' . self::$lastSpawnedDownloads);
        }
        echo json_encode($queue);

        $artistName = (string)($seed['artist_name'] ?? '');
        $userId = (int)$user['id'];
        $artistId = (int)$seed['artist_id'];
        $localCount = count($queue);
        if ($artistName !== '') {
            register_shutdown_function(function () use ($artistName, $userId, $artistId, $localCount) {
                if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
                try { self::autoDownloadSimilar($artistName, $userId, $artistId, $localCount); } catch (\Throwable $e) {}
            });
        }
    }

    private static function autoDownloadSimilar(string $artistName, int $userId, int $artistId = 0, int $localCount = 0): void
    {
        $logFile = __DIR__ . '/../../storage/autodl.log';
        if (!is_dir(dirname($logFile))) @mkdir(dirname($logFile), 0775, true);
        $log = function (string $msg) use ($logFile) {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        };

        $lockFile = sys_get_temp_dir() . '/doniix_autodl_' . md5($artistName . '|' . $userId);
        if (is_file($lockFile) && (time() - filemtime($lockFile)) < 30) {
            $log("Lock held — skip");
            return;
        }
        @touch($lockFile);

        $log("=== autoDownload artist=\"{$artistName}\" user={$userId} localQueue={$localCount} ===");

        $last = Database::fetchOne(
            'SELECT MAX(created_at) AS last_at FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             WHERE ar.name = ? AND s.created_at > (NOW() - INTERVAL 10 MINUTE)',
            [$artistName]
        );
        $sameArtistCooldown = ($last !== null && !empty($last['last_at']));

        $existingTitles = self::existingTitlesIndex();
        $downloaded = 0;
        $sameArtistBudget = $sameArtistCooldown ? 0 : 3;
        $similarBudget = $localCount < 20 ? 8 : 4;

        if ($sameArtistBudget > 0) {
            $log("Fetching top tracks for seed artist \"{$artistName}\"");
            $tracks = SpotifyApi::search($artistName, 15);
            foreach ($tracks as $t) {
                if ($downloaded >= $sameArtistBudget) break;
                if (self::queueIfNew($t, $artistName, $existingTitles, $log)) $downloaded++;
            }
            $log("Queued {$downloaded} from seed artist");
        } else {
            $log("Cooldown active for seed artist, skipping same-artist downloads");
        }

        $relatedArtists = self::fetchSimilarArtists($artistName, $log);
        if (empty($relatedArtists)) {
            $log("No similar artists found, finishing");
            return;
        }
        $log("Got " . count($relatedArtists) . " similar artists");

        $downloadedFromSimilar = 0;
        foreach ($relatedArtists as $similarName) {
            if ($downloadedFromSimilar >= $similarBudget) break;
            $log("Looking up tracks for similar artist \"{$similarName}\"");
            $tracks = SpotifyApi::search($similarName, 8);
            $perArtist = 0;
            foreach ($tracks as $t) {
                if ($downloadedFromSimilar >= $similarBudget) break;
                if ($perArtist >= 2) break;
                if (self::queueIfNew($t, $similarName, $existingTitles, $log)) {
                    $downloadedFromSimilar++;
                    $perArtist++;
                }
            }
        }
        $log("Total queued: seed={$downloaded} similar={$downloadedFromSimilar}");
    }

    private static function existingTitlesIndex(): array
    {
        $rows = Database::fetchAll('SELECT LOWER(s.title) AS t, LOWER(ar.name) AS a FROM songs s JOIN artists ar ON ar.id = s.artist_id');
        $idx = [];
        foreach ($rows as $r) $idx[$r['a'] . '||' . $r['t']] = true;
        return $idx;
    }

    private static function queueIfNew(array $t, string $defaultArtist, array &$existingTitles, callable $log): bool
    {
        $title = trim((string)($t['name'] ?? ''));
        $sid = (string)($t['id'] ?? '');
        $url = (string)($t['external_urls']['spotify'] ?? '');
        if ($url === '' && $sid !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $sid)) {
            $url = 'https://open.spotify.com/track/' . $sid;
        }
        if ($url === '' && str_starts_with($sid, 'itunes:')) {
            $url = $sid;
        }
        if ($url === '' && str_starts_with($sid, 'deezer:')) {
            $url = $sid;
        }
        if ($title === '' || $url === '') return false;

        $artists = $t['artists'] ?? [];
        $artistFromTrack = is_array($artists) && !empty($artists[0]['name']) ? (string)$artists[0]['name'] : $defaultArtist;
        $album = (string)($t['album']['name'] ?? 'Singles');

        $key = mb_strtolower($artistFromTrack) . '||' . mb_strtolower($title);
        if (isset($existingTitles[$key])) {
            $log("Skip (have): {$artistFromTrack} — {$title}");
            return false;
        }
        $existingTitles[$key] = true;

        $hint = ['title' => $title, 'artist' => $artistFromTrack, 'album' => $album];
        if (!empty($t['duration_ms'])) $hint['duration_ms'] = (int)$t['duration_ms'];
        if (!empty($t['album']['images'][0]['url'])) $hint['cover_url'] = (string)$t['album']['images'][0]['url'];

        YoutubeDownloader::queueBackground($url, $hint);
        $log("Queued: {$artistFromTrack} — {$title}");
        return true;
    }

    private static function fetchSimilarArtists(string $artistName, callable $log): array
    {
        $token = SpotifyApi::getAccessToken();
        if ($token !== '') {
            $searchUrl = 'https://api.spotify.com/v1/search?q=' . urlencode($artistName) . '&type=artist&limit=1';
            [$body, $status] = self::spotifyRequest($searchUrl, $token);
            if ($status === 200 && $body !== '') {
                $data = json_decode($body, true);
                $artistId = $data['artists']['items'][0]['id'] ?? '';
                if (is_string($artistId) && $artistId !== '') {
                    [$rBody, $rStatus] = self::spotifyRequest('https://api.spotify.com/v1/artists/' . $artistId . '/related-artists', $token);
                    if ($rStatus === 200 && $rBody !== '') {
                        $rData = json_decode($rBody, true);
                        $names = [];
                        foreach (($rData['artists'] ?? []) as $a) {
                            if (!empty($a['name'])) $names[] = (string)$a['name'];
                        }
                        if (!empty($names)) {
                            $log("Spotify related-artists: " . implode(', ', array_slice($names, 0, 6)));
                            return array_slice($names, 0, 6);
                        }
                    } else {
                        $log("Spotify related-artists HTTP {$rStatus}");
                    }
                }
            }
        }

        $url = 'https://api.deezer.com/search/artist?q=' . urlencode($artistName) . '&limit=1';
        [$body, $status] = self::spotifyRequest($url, null);
        if ($status === 200 && $body !== '') {
            $data = json_decode($body, true);
            $artistId = $data['data'][0]['id'] ?? null;
            if ($artistId !== null) {
                [$rBody, $rStatus] = self::spotifyRequest('https://api.deezer.com/artist/' . (int)$artistId . '/related?limit=10', null);
                if ($rStatus === 200 && $rBody !== '') {
                    $rData = json_decode($rBody, true);
                    $names = [];
                    foreach (($rData['data'] ?? []) as $a) {
                        if (!empty($a['name'])) $names[] = (string)$a['name'];
                    }
                    if (!empty($names)) {
                        $log("Deezer related: " . implode(', ', array_slice($names, 0, 6)));
                        return array_slice($names, 0, 6);
                    }
                }
            }
        }

        $log("No similar artist source returned anything");
        return [];
    }

    private static function spotifyRequest(string $url, ?string $token): array
    {
        if (!function_exists('curl_init')) {
            return ['', 0];
        }
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$body === false ? '' : (string)$body, $status];
    }

    private static function fetchTracks(string $clause, array $params): array
    {
        $sql = 'SELECT s.id, s.title, s.artist_id, s.play_count, ar.name AS artist_name FROM songs s
                JOIN artists ar ON ar.id = s.artist_id ';
        if (!str_starts_with($clause, 'JOIN') && !str_starts_with($clause, 'WHERE')) {
            $clause = 'WHERE ' . $clause;
        }
        return Database::fetchAll($sql . $clause, $params);
    }

    private static function lookupSpotifyTrackId(string $artist, string $title): string
    {
        if ($artist === '' || $title === '') return '';
        $token = SpotifyApi::getAccessToken();
        if ($token === '') return '';
        $q = trim($artist . ' ' . $title);
        $url = 'https://api.spotify.com/v1/search?'
            . 'q=' . rawurlencode($q)
            . '&type=track'
            . '&limit=5'
            . '&market=PL';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200 || !is_string($body)) return '';
        $data = json_decode($body, true);
        $tracks = is_array($data) ? ($data['tracks']['items'] ?? []) : [];
        if (!is_array($tracks) || empty($tracks)) return '';
        $artistLc = mb_strtolower($artist);
        $titleLc = mb_strtolower($title);
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $tTitle = mb_strtolower((string)($t['name'] ?? ''));
            $tArtist = mb_strtolower((string)($t['artists'][0]['name'] ?? ''));
            if (str_contains($tTitle, $titleLc) && str_contains($tArtist, $artistLc)) {
                return (string)($t['id'] ?? '');
            }
        }
        return (string)($tracks[0]['id'] ?? '');
    }

    private static function fetchSpotifyRecommendations(string $seedSpotifyId, int $excludeSongId, int $userId = 0, array $extraSeeds = [], int $limit = 20): array
    {
        $token = SpotifyApi::getAccessToken();
        if ($token === '') return [];

        $allSeeds = array_unique(array_filter(array_merge([$seedSpotifyId], $extraSeeds), function ($s) {
            return is_string($s) && preg_match('/^[A-Za-z0-9]{22}$/', $s);
        }));
        $allSeeds = array_slice($allSeeds, 0, 5);
        if (empty($allSeeds)) return [];
        $limit = max(5, min(100, $limit));

        $url = 'https://api.spotify.com/v1/recommendations?'
            . 'seed_tracks=' . rawurlencode(implode(',', $allSeeds))
            . '&limit=' . $limit
            . '&market=PL';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($status !== 200 || !is_string($body)) return [];

        $data = json_decode($body, true);
        $tracks = is_array($data) ? ($data['tracks'] ?? []) : [];
        if (!is_array($tracks) || empty($tracks)) return [];

        $byId = [];
        foreach ($tracks as $t) {
            if (!is_array($t) || empty($t['id'])) continue;
            $byId[(string)$t['id']] = $t;
        }
        $sids = array_keys($byId);
        if (empty($sids)) return [];

        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $params = $sids;
        $params[] = $excludeSongId;
        $params[] = $userId;
        $rows = Database::fetchAll(
            'SELECT s.id, s.title, s.artist_id, s.play_count, s.spotify_id, ar.name AS artist_name
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             WHERE s.spotify_id IN (' . $placeholders . ') AND s.id != ? AND s.downloaded_by = ?',
            $params
        );

        $haveSpotifyIds = [];
        foreach ($rows as $r) $haveSpotifyIds[(string)$r['spotify_id']] = true;

        // Auto-spawn yt-dlp download dla niepobranych rekomendacji
        $logFile = __DIR__ . '/../../storage/autodl.log';
        $spawned = 0;
        foreach ($sids as $sid) {
            if (isset($haveSpotifyIds[$sid])) continue;
            $t = $byId[$sid];
            $artistName = '';
            if (!empty($t['artists'][0]['name'])) $artistName = (string)$t['artists'][0]['name'];
            $title = (string)($t['name'] ?? '');
            $album = (string)($t['album']['name'] ?? '');
            $coverUrl = '';
            if (!empty($t['album']['images'][0]['url'])) $coverUrl = (string)$t['album']['images'][0]['url'];
            $releaseDate = (string)($t['album']['release_date'] ?? '');
            if ($title === '' || $artistName === '') continue;

            $existsForUser = Database::fetchOne(
                'SELECT 1 FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE LOWER(s.title) = LOWER(?) AND LOWER(ar.name) = LOWER(?) AND s.downloaded_by = ? LIMIT 1',
                [$title, $artistName, $userId]
            );
            if ($existsForUser !== null) continue;

            $spotifyUrl = 'https://open.spotify.com/track/' . $sid;
            $hint = [
                'title' => $title,
                'artist' => $artistName,
                'album' => $album !== '' ? $album : 'Singles',
                'cover_url' => $coverUrl,
                'song_id' => $sid,
                'spotify_id' => $sid,
                'duration_ms' => (int)($t['duration_ms'] ?? 0),
                'release_date' => $releaseDate !== '' ? substr($releaseDate, 0, 10) : '',
                'user_id' => $userId,
            ];
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [spotify-radio] queue {$artistName} - {$title} (spotify={$sid})\n", FILE_APPEND | LOCK_EX);

            try {
                YoutubeDownloader::queueBackground($spotifyUrl, $hint);
                $spawned++;
            } catch (\Throwable $e) {
                @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [spotify-radio] queue ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            }
        }

        if ($spawned > 0) {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [spotify-radio] spawned {$spawned} downloads from seed={$seedSpotifyId}\n", FILE_APPEND | LOCK_EX);
        }
        self::$lastSpawnedDownloads += $spawned;
        return $rows;
    }

    private static function fetchLastfmRecommendations(string $seedArtist, string $seedTitle, int $excludeSongId, int $userId = 0, int $limit = 30): array
    {
        $logFile = __DIR__ . '/../../storage/autodl.log';
        $lfmLog = function(string $msg) use ($logFile): void {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [lfm-rec] {$msg}\n", FILE_APPEND | LOCK_EX);
        };
        if ($seedArtist === '' || $seedTitle === '') {
            $lfmLog("ABORT: empty artist or title");
            return [];
        }
        $similar = LastfmApi::getSimilarTracks($seedArtist, $seedTitle, $limit);
        $lfmLog("LFM raw similar for '{$seedArtist} - {$seedTitle}' count=" . count($similar));
        if (empty($similar)) return [];

        $similar = array_values(array_filter($similar, fn($s) => ((float)($s['match'] ?? 0)) >= 0.4));
        $lfmLog("after match>=0.4 filter count=" . count($similar));
        if (empty($similar)) return [];

        $titles = [];
        $artists = [];
        foreach ($similar as $s) {
            $titles[mb_strtolower((string)$s['title'])] = true;
            $artists[mb_strtolower((string)$s['artist'])] = true;
        }
        $titles = array_keys($titles);
        $artists = array_keys($artists);

        $haveMap = [];
        if (!empty($titles) && !empty($artists)) {
            $titlePh = implode(',', array_fill(0, count($titles), '?'));
            $artistPh = implode(',', array_fill(0, count($artists), '?'));
            $params = array_merge([$excludeSongId, $userId], $titles, $artists);
            $rows = Database::fetchAll(
                'SELECT s.id, s.title, s.artist_id, s.play_count, s.spotify_id, ar.name AS artist_name
                 FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE s.id != ? AND s.downloaded_by = ?
                       AND LOWER(s.title) IN (' . $titlePh . ')
                       AND LOWER(ar.name) IN (' . $artistPh . ')',
                $params
            );
            foreach ($rows as $r) {
                $k = mb_strtolower((string)$r['artist_name']) . '||' . mb_strtolower((string)$r['title']);
                $haveMap[$k] = $r;
            }
        }
        $lfmLog("DB lookup found in user library count=" . count($haveMap));

        $spawned = 0;
        foreach ($similar as $s) {
            $key = mb_strtolower((string)$s['artist']) . '||' . mb_strtolower((string)$s['title']);
            if (isset($haveMap[$key])) continue;
            $artistName = (string)$s['artist'];
            $title = (string)$s['title'];
            if ($artistName === '' || $title === '') continue;

            $spotifyUrl = 'https://open.spotify.com/search/' . rawurlencode($artistName . ' ' . $title);
            $hint = [
                'title' => $title,
                'artist' => $artistName,
                'album' => 'Singles',
                'user_id' => $userId,
            ];
            try {
                YoutubeDownloader::queueBackground($spotifyUrl, $hint);
                $spawned++;
            } catch (\Throwable $e) {
                @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [lfm-radio] queue ERROR: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            }
        }
        if ($spawned > 0) {
            @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [lfm-radio] spawned {$spawned} downloads from seed={$seedArtist} - {$seedTitle}\n", FILE_APPEND | LOCK_EX);
        }
        self::$lastSpawnedDownloads += $spawned;
        return array_values($haveMap);
    }
}
