<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Downloader\SpotifyApi;
use Doniixify\Downloader\YoutubeDownloader;
use Doniixify\Downloader\LastfmApi;

final class RadioController
{
    public static function view(int $songId): void
    {
        $user = Session::requireLogin();
        $seed = Database::fetchOne(
            'SELECT s.id, s.title, s.duration, s.spotify_id,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             LEFT JOIN albums al ON al.id = s.album_id
             WHERE s.id = ?',
            [$songId]
        );
        if ($seed === null) {
            http_response_code(404);
            Layout::render('/', '<div class="empty-state"><h2>Not found</h2><p>This song does not exist.</p></div>');
            return;
        }

        $logFile = __DIR__ . '/../../storage/radio.log';
        try {
            [$similar, $pending] = self::buildSimilarTracks($songId, $seed, (int)$user['id']);
        } catch (\Throwable $e) {
            @file_put_contents(
                $logFile,
                '[' . date('Y-m-d H:i:s') . "] view songId={$songId} buildSimilarTracks ERROR: "
                . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n",
                FILE_APPEND | LOCK_EX
            );
            $similar = [];
            $pending = [];
        }

        ob_start();
        $seedTitle = htmlspecialchars((string)$seed['title']);
        $seedArtist = htmlspecialchars((string)$seed['artist_name']);
        $seedArtistId = (int)$seed['artist_id'];
        ?>
        <header class="radio-header">
            <div class="radio-header-cover">
                <img src="/cover/<?= (int)$seed['id'] ?>" alt="" onerror="this.style.display='none'">
            </div>
            <div class="radio-header-text">
                <div class="radio-header-eyebrow">Radio</div>
                <h1 class="radio-header-title"><?= $seedTitle ?></h1>
                <div class="radio-header-meta">Based on <a href="/artist/<?= $seedArtistId ?>" class="artist-link"><?= $seedArtist ?></a> · <?= (count($similar) + count($pending) + 1) ?> tracks</div>
            </div>
        </header>
        <div class="actions-bar">
            <button class="action-play" id="radio-play-all" data-seed-song-id="<?= (int)$seed['id'] ?>" title="Play radio"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg></button>
            <button class="action-btn action-shuffle" id="radio-shuffle" title="Shuffle"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/></svg></button>
        </div>
        <?php
        $allTracks = array_merge([$seed], $similar);
        echo '<div data-radio-view="1" data-seed-song-id="' . (int)$seed['id'] . '">';
        if (!empty($allTracks)) {
            Views::renderSongsTablePublic($allTracks);
        }
        if (!empty($pending)) {
            echo '<div class="pending-tracks-section"><div class="pending-tracks-label">Downloading from Spotify · <span data-pending-count>' . count($pending) . '</span></div>';
            foreach ($pending as $p) {
                $pTitle = (string)($p['title'] ?? '');
                $pArtist = (string)($p['artist'] ?? '');
                $pAlbum = (string)($p['album'] ?? '');
                $pReleased = substr((string)($p['release_date'] ?? ''), 0, 4);
                $pKey = mb_strtolower($pArtist) . '||' . mb_strtolower($pTitle);
                $metaLine = trim(implode(' · ', array_filter([$pArtist, $pAlbum, $pReleased])));
                echo '<div class="pending-track" data-key="' . htmlspecialchars($pKey, ENT_QUOTES) . '" data-search="' . htmlspecialchars(mb_strtolower($pTitle . ' ' . $pArtist . ' ' . $pAlbum), ENT_QUOTES) . '">';
                echo '<div class="pending-track-spinner"></div>';
                echo '<div class="pending-track-meta">';
                echo '<div class="pending-track-title">' . htmlspecialchars($pTitle) . '</div>';
                echo '<div class="pending-track-artist">' . htmlspecialchars($metaLine) . '</div>';
                echo '<div class="pending-track-stage" data-stage>queued</div>';
                echo '</div>';
                echo '<div class="pending-track-progress"><div class="pending-track-progress-bar" data-progress style="width:0%"></div></div>';
                echo '</div>';
            }
            echo '</div>';
        }
        if (count($similar) === 0 && count($pending) === 0) {
            echo '<div class="empty-state"><h2>No similar tracks yet</h2><p>Brak innych utworów w bibliotece. Pobierz playlistę albo poszukaj utworów, żeby radio miało z czego budować.</p></div>';
        }
        echo '</div>';
        ?>
        <script>
        (function () {
            const playBtn = document.getElementById('radio-play-all');
            if (playBtn) playBtn.addEventListener('click', () => {
                const seed = playBtn.dataset.seedSongId;
                const row = document.querySelector('[data-radio-view] tr[data-song-id="' + seed + '"]');
                if (row && typeof window.__loadFromRow === 'function') {
                    window.__loadFromRow(row, { userPick: true });
                } else if (row) {
                    row.click();
                }
                try { localStorage.setItem('doniix-radio', '1'); localStorage.setItem('doniix-playmode', 'smart'); } catch (e) {}
            });
        })();
        </script>
        <?php
        Layout::render('/radio/' . $songId, ob_get_clean());
    }

    private static function buildSimilarTracks(int $seedId, array $seed, int $userId): array
    {
        $target = 49;
        $similar = [];
        $pending = [];
        $seenIds = [$seedId => true];
        $seenKeys = [mb_strtolower((string)$seed['artist_name']) . '||' . mb_strtolower((string)$seed['title']) => true];

        $appendLocal = function (array $rows) use (&$similar, &$seenIds, &$seenKeys, $target) {
            foreach ($rows as $r) {
                $rid = (int)$r['id'];
                if (isset($seenIds[$rid])) continue;
                $key = mb_strtolower((string)$r['artist_name']) . '||' . mb_strtolower((string)$r['title']);
                if (isset($seenKeys[$key])) continue;
                $seenIds[$rid] = true;
                $seenKeys[$key] = true;
                $similar[] = $r;
                if (count($similar) >= $target) return;
            }
        };

        if (LastfmApi::isConfigured()) {
            $userTopSeeds = Database::fetchAll(
                'SELECT s.id, s.title, s.spotify_id, ar.name AS artist_name
                 FROM user_song_plays usp
                 JOIN songs s ON s.id = usp.song_id
                 JOIN artists ar ON ar.id = s.artist_id
                 WHERE usp.user_id = ? AND usp.last_played_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                 ORDER BY usp.play_count DESC, usp.last_played_at DESC
                 LIMIT 5',
                [$userId]
            );
            $seedTracks = [['title' => $seed['title'], 'artist' => $seed['artist_name']]];
            foreach ($userTopSeeds as $st) {
                $seedTracks[] = ['title' => $st['title'], 'artist' => $st['artist_name']];
            }

            $lfmAggregated = [];
            foreach ($seedTracks as $st) {
                $simResults = LastfmApi::getSimilarTracks((string)$st['artist'], (string)$st['title'], 50);
                foreach ($simResults as $sr) {
                    $key = mb_strtolower((string)$sr['artist']) . '||' . mb_strtolower((string)$sr['title']);
                    if (isset($seenKeys[$key])) continue;
                    if (!isset($lfmAggregated[$key])) {
                        $lfmAggregated[$key] = $sr;
                        $lfmAggregated[$key]['score'] = 0;
                    }
                    $lfmAggregated[$key]['score'] += (float)$sr['match'];
                }
            }
            usort($lfmAggregated, fn($a, $b) => $b['score'] <=> $a['score']);
            $lfmAggregated = array_slice($lfmAggregated, 0, 80);

            $lfmLocal = [];
            $lfmPending = [];
            foreach ($lfmAggregated as $lfm) {
                $lfmTitle = (string)$lfm['title'];
                $lfmArtist = (string)$lfm['artist'];
                $local = Database::fetchOne(
                    'SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                            ar.id AS artist_id, ar.name AS artist_name,
                            al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
                     FROM songs s
                     JOIN artists ar ON ar.id = s.artist_id
                     JOIN albums al ON al.id = s.album_id
                     WHERE LOWER(s.title) = LOWER(?) AND LOWER(ar.name) = LOWER(?) AND s.downloaded_by = ? LIMIT 1',
                    [$lfmTitle, $lfmArtist, $userId]
                );
                if ($local !== null) {
                    $lfmLocal[] = $local;
                } else {
                    $lfmPending[] = [
                        'title' => $lfmTitle,
                        'artist' => $lfmArtist,
                        'album' => '',
                        'release_date' => '',
                        'cover_url' => '',
                        'spotify_id' => '',
                    ];
                }
            }
            $appendLocal($lfmLocal);
            foreach ($lfmPending as $p) {
                $key = mb_strtolower((string)$p['artist']) . '||' . mb_strtolower((string)$p['title']);
                if (isset($seenKeys[$key])) continue;
                $seenKeys[$key] = true;
                $pending[] = $p;
                $searchUrl = 'https://open.spotify.com/search/' . rawurlencode($p['artist'] . ' ' . $p['title']);
                try {
                    YoutubeDownloader::queueBackground($searchUrl, [
                        'title' => $p['title'],
                        'artist' => $p['artist'],
                        'user_id' => $userId,
                    ]);
                } catch (\Throwable $e) {}
                if (count($similar) + count($pending) >= $target) break;
            }
        }

        $spotifyId = (string)($seed['spotify_id'] ?? '');
        if ($spotifyId === '' || !preg_match('/^[A-Za-z0-9]{22}$/', $spotifyId)) {
            $spotifyId = self::lookupSeedSpotifyId((string)$seed['artist_name'], (string)$seed['title']);
            if ($spotifyId !== '') {
                Database::execute('UPDATE songs SET spotify_id = ? WHERE id = ?', [$spotifyId, $seedId]);
            }
        }
        if ($spotifyId !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $spotifyId) && count($similar) + count($pending) < $target) {
            [$spotifyLocal, $spotifyPending] = self::fetchSpotifyRadio($spotifyId, $userId);
            $appendLocal($spotifyLocal);
            foreach ($spotifyPending as $p) {
                $key = mb_strtolower((string)$p['artist']) . '||' . mb_strtolower((string)$p['title']);
                if (isset($seenKeys[$key])) continue;
                $seenKeys[$key] = true;
                $pending[] = $p;
                if (count($similar) + count($pending) >= $target) break;
            }
        }

        $sameArtist = self::fetchLocalSongs(
            'WHERE s.artist_id = ? ORDER BY RAND()',
            [(int)$seed['artist_id']],
            $target,
            $userId
        );
        $appendLocal($sameArtist);

        $sameAlbum = self::fetchLocalSongs(
            'WHERE s.album_id = ? ORDER BY s.track_number ASC',
            [(int)$seed['album_id'] ?? 0],
            $target,
            $userId
        );
        $appendLocal($sameAlbum);

        $sharedPlaylist = self::fetchLocalSongs(
            'JOIN playlist_songs ps ON ps.song_id = s.id
             JOIN playlists pl ON pl.id = ps.playlist_id
             WHERE pl.user_id = ? AND ps.playlist_id IN (
                 SELECT ps2.playlist_id FROM playlist_songs ps2
                 JOIN playlists pl2 ON pl2.id = ps2.playlist_id
                 WHERE ps2.song_id = ? AND pl2.user_id = ?
             ) ORDER BY RAND()',
            [$userId, $seedId, $userId],
            $target,
            $userId
        );
        $appendLocal($sharedPlaylist);

        if (!empty($seed['album_year'])) {
            $low = (int)$seed['album_year'] - 3;
            $high = (int)$seed['album_year'] + 3;
            $eraTracks = self::fetchLocalSongs(
                'WHERE al.year BETWEEN ? AND ? ORDER BY RAND()',
                [$low, $high],
                $target,
                $userId
            );
            $appendLocal($eraTracks);
        }

        if (count($similar) < $target) {
            $popular = self::fetchLocalSongs('ORDER BY RAND()', [], $target, $userId);
            $appendLocal($popular);
        }

        $similar = array_slice($similar, 0, $target);
        $pending = array_slice($pending, 0, max(0, $target - count($similar)));

        return [$similar, $pending];
    }

    private static function fetchLocalSongs(string $where, array $params, int $limit, int $userId = 0): array
    {
        $userFilter = '';
        if ($userId > 0) {
            if (stripos($where, 'WHERE') === 0) {
                $userFilter = ' AND s.downloaded_by = ?';
            } elseif (stripos($where, 'WHERE') !== false) {
                $userFilter = ' AND s.downloaded_by = ?';
            } else {
                $userFilter = ' WHERE s.downloaded_by = ?';
            }
            $params[] = $userId;
        }
        return Database::fetchAll(
            'SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             JOIN albums al ON al.id = s.album_id
             ' . $where . $userFilter . ' LIMIT ' . (int)$limit,
            $params
        );
    }

    private static function lookupSeedSpotifyId(string $artist, string $title): string
    {
        if ($artist === '' || $title === '') return '';
        $token = SpotifyApi::getAccessToken();
        if ($token === '') return '';
        $q = 'track:"' . str_replace('"', '', $title) . '" artist:"' . str_replace('"', '', $artist) . '"';
        $url = 'https://api.spotify.com/v1/search?type=track&limit=1&market=PL&q=' . rawurlencode($q);
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
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) return '';
        $data = json_decode($body, true);
        $items = $data['tracks']['items'] ?? [];
        if (!is_array($items) || empty($items)) return '';
        $sid = (string)($items[0]['id'] ?? '');
        return preg_match('/^[A-Za-z0-9]{22}$/', $sid) ? $sid : '';
    }

    private static function spotifyGet(string $url, string $token, int $ttl = 1800): ?array
    {
        $cacheDir = __DIR__ . '/../../storage/cache/spotify-radio';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheFile = $cacheDir . '/' . md5($url) . '.json';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        static $rateLimitedUntil = 0;
        if ($rateLimitedUntil > time()) return null;

        $attempt = 0;
        while ($attempt < 2) {
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
            $retryAfter = 0;
            $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($status === 429) {
                $rateLimitedUntil = time() + 30;
                return null;
            }
            if ($status === 200 && is_string($body)) {
                $data = json_decode($body, true);
                if (is_array($data)) {
                    @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
                    return $data;
                }
            }
            $attempt++;
            if ($attempt < 2) usleep(300000);
        }
        return null;
    }

    private static function fetchSpotifyRadio(string $seedSpotifyId, int $userId): array
    {
        $token = SpotifyApi::getAccessToken();
        if ($token === '') return [[], []];

        $tracks = [];

        $recsData = self::spotifyGet('https://api.spotify.com/v1/recommendations?seed_tracks=' . rawurlencode($seedSpotifyId) . '&limit=80&market=PL', $token);
        if (is_array($recsData) && !empty($recsData['tracks'])) {
            foreach ($recsData['tracks'] as $t) $tracks[] = $t;
        }

        $seedTrack = self::spotifyGet('https://api.spotify.com/v1/tracks/' . rawurlencode($seedSpotifyId), $token);
        $artistId = '';
        $seedYear = 0;
        if (is_array($seedTrack)) {
            $artistId = (string)($seedTrack['artists'][0]['id'] ?? '');
            $rd = (string)($seedTrack['album']['release_date'] ?? '');
            if ($rd !== '') $seedYear = (int)substr($rd, 0, 4);
        }

        if ($artistId !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $artistId)) {
            $topData = self::spotifyGet('https://api.spotify.com/v1/artists/' . rawurlencode($artistId) . '/top-tracks?market=PL', $token);
            if (is_array($topData) && !empty($topData['tracks'])) {
                foreach ($topData['tracks'] as $t) $tracks[] = $t;
            }

            $albumsData = self::spotifyGet('https://api.spotify.com/v1/artists/' . rawurlencode($artistId) . '/albums?include_groups=album,single&limit=10&market=PL', $token);
            if (is_array($albumsData) && !empty($albumsData['items'])) {
                $pickedAlbums = array_slice($albumsData['items'], 0, 3);
                foreach ($pickedAlbums as $a) {
                    $aid = (string)($a['id'] ?? '');
                    if ($aid === '') continue;
                    $albumName = (string)($a['name'] ?? '');
                    $albumCover = (string)($a['images'][0]['url'] ?? '');
                    $albumRelease = (string)($a['release_date'] ?? '');
                    $tracksData = self::spotifyGet('https://api.spotify.com/v1/albums/' . rawurlencode($aid) . '/tracks?limit=20&market=PL', $token);
                    if (!is_array($tracksData) || empty($tracksData['items'])) continue;
                    foreach ($tracksData['items'] as $t) {
                        if (!is_array($t)) continue;
                        $t['album'] = [
                            'name' => $albumName,
                            'images' => $albumCover !== '' ? [['url' => $albumCover]] : [],
                            'release_date' => $albumRelease,
                        ];
                        $tracks[] = $t;
                    }
                }
            }
        }

        if (empty($tracks)) return [[], []];

        $byId = [];
        foreach ($tracks as $t) {
            if (!is_array($t) || empty($t['id'])) continue;
            $byId[(string)$t['id']] = $t;
        }
        $sids = array_keys($byId);
        if (empty($sids)) return [[], []];

        $placeholders = implode(',', array_fill(0, count($sids), '?'));
        $params = $sids;
        $params[] = $userId;
        $rows = Database::fetchAll(
            'SELECT s.id, s.title, s.duration, s.spotify_id, s.created_at AS song_added_at,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             JOIN albums al ON al.id = s.album_id
             WHERE s.spotify_id IN (' . $placeholders . ') AND s.downloaded_by = ?',
            $params
        );

        $haveSpotifyIds = [];
        foreach ($rows as $r) $haveSpotifyIds[(string)$r['spotify_id']] = true;

        $pending = [];
        foreach ($sids as $sid) {
            if (isset($haveSpotifyIds[$sid])) continue;
            $t = $byId[$sid];
            $artistName = is_array($t['artists'] ?? null) && !empty($t['artists'][0]['name']) ? (string)$t['artists'][0]['name'] : '';
            $title = (string)($t['name'] ?? '');
            if ($title === '' || $artistName === '') continue;
            $albumName = (string)($t['album']['name'] ?? '');
            $coverUrl = (string)($t['album']['images'][0]['url'] ?? '');
            $releaseDate = substr((string)($t['album']['release_date'] ?? ''), 0, 10);
            $durationMs = (int)($t['duration_ms'] ?? 0);
            $existsLocal = Database::fetchOne(
                'SELECT 1 FROM songs s JOIN artists ar ON ar.id = s.artist_id
                 WHERE LOWER(s.title) = LOWER(?) AND LOWER(ar.name) = LOWER(?) LIMIT 1',
                [$title, $artistName]
            );
            if ($existsLocal !== null) continue;
            $hint = [
                'title' => $title,
                'artist' => $artistName,
                'album' => $albumName,
                'cover_url' => $coverUrl,
                'spotify_id' => $sid,
                'song_id' => $sid,
                'duration_ms' => $durationMs,
                'release_date' => $releaseDate,
                'user_id' => $userId,
            ];
            try { YoutubeDownloader::queueBackground('https://open.spotify.com/track/' . $sid, $hint); } catch (\Throwable $e) {}
            $pending[] = [
                'title' => $title,
                'artist' => $artistName,
                'album' => $albumName,
                'spotify_id' => $sid,
                'cover_url' => $coverUrl,
                'release_date' => $releaseDate,
            ];
        }

        shuffle($rows);
        shuffle($pending);
        return [$rows, $pending];
    }
}
