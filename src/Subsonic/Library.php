<?php

declare(strict_types=1);

namespace Doniixify\Subsonic;

use Doniixify\Database;
use Doniixify\Router;
use Doniixify\Scanner\Scanner;

final class Library
{
    private static function out(array $payload): void
    {
        $p = Router::params();
        Response::send(Response::ok($payload), $p['f'] ?? 'xml', $p['callback'] ?? null);
    }

    private static function userId(): int
    {
        $u = Router::requireAuth();
        return (int)$u['id'];
    }

    // ===== artist/album/song formatters =====
    private static function artistObj(array $r): array
    {
        return [
            '@id' => 'ar' . $r['id'],
            '@name' => $r['name'],
            '@albumCount' => (int)($r['album_count'] ?? 0),
            '@coverArt' => 'ar' . $r['id'],
        ];
    }

    private static function albumObj(array $r): array
    {
        $o = [
            '@id' => 'al' . $r['id'],
            '@parent' => 'ar' . $r['artist_id'],
            '@name' => $r['name'],
            '@title' => $r['name'],
            '@album' => $r['name'],
            '@artist' => $r['artist_name'] ?? '',
            '@artistId' => 'ar' . $r['artist_id'],
            '@coverArt' => 'al' . $r['id'],
            '@songCount' => (int)($r['song_count'] ?? 0),
            '@duration' => (int)($r['duration'] ?? 0),
            '@created' => self::iso($r['created_at'] ?? null),
            '@isDir' => true,
        ];
        if (!empty($r['year'])) $o['@year'] = (int)$r['year'];
        if (!empty($r['genre'])) $o['@genre'] = $r['genre'];
        return $o;
    }

    private static function songObj(array $r, int $uid): array
    {
        $starred = self::isStarred($uid, 'song', (int)$r['id']);
        $bitrate = (int)($r['bitrate'] ?? 0);
        if ($bitrate > 10000) $bitrate = (int)($bitrate / 1000);
        if ($bitrate <= 0) $bitrate = 192;
        $suffix = $r['suffix'] ?? '';
        if ($suffix === '' && !empty($r['path'])) $suffix = strtolower(pathinfo($r['path'], PATHINFO_EXTENSION));
        if ($suffix === '') $suffix = 'mp3';
        $contentType = $r['content_type'] ?? '';
        if ($contentType === '' || stripos($contentType, 'audio/') !== 0) {
            $contentType = match ($suffix) {
                'flac' => 'audio/flac',
                'ogg', 'oga' => 'audio/ogg',
                'opus' => 'audio/opus',
                'm4a', 'aac', 'mp4' => 'audio/mp4',
                'wav' => 'audio/wav',
                default => 'audio/mpeg',
            };
        }
        $o = [
            '@id' => 'tr' . $r['id'],
            '@parent' => 'al' . $r['album_id'],
            '@title' => $r['title'],
            '@album' => $r['album_name'] ?? '',
            '@artist' => $r['artist_name'] ?? '',
            '@albumId' => 'al' . $r['album_id'],
            '@artistId' => 'ar' . $r['artist_id'],
            '@coverArt' => 'al' . $r['album_id'],
            '@duration' => (int)$r['duration'],
            '@bitRate' => $bitrate,
            '@size' => (int)($r['size'] ?? 0),
            '@suffix' => $suffix,
            '@contentType' => $contentType,
            '@path' => !empty($r['path']) ? basename($r['path']) : ($r['title'] . '.' . $suffix),
            '@isDir' => false,
            '@isVideo' => false,
            '@type' => 'music',
            '@created' => self::iso($r['created_at'] ?? null),
        ];
        if (!empty($r['track_number'])) $o['@track'] = (int)$r['track_number'];
        if (!empty($r['year'])) $o['@year'] = (int)$r['year'];
        if ($starred) $o['@starred'] = self::iso(date('Y-m-d H:i:s'));
        return $o;
    }

    private static function iso(?string $dt): string
    {
        if (!$dt) return date('c');
        $ts = strtotime($dt);
        return $ts ? date('c', $ts) : date('c');
    }

    private static function isStarred(int $uid, string $type, int $id): bool
    {
        return Database::fetchOne(
            'SELECT 1 FROM stars WHERE user_id=? AND item_type=? AND item_id=?',
            [$uid, $type, $id]
        ) !== null;
    }

    private static function parseId(string $id): array
    {
        if (str_starts_with($id, 'ar')) return ['artist', (int)substr($id, 2)];
        if (str_starts_with($id, 'al')) return ['album', (int)substr($id, 2)];
        if (str_starts_with($id, 'tr')) return ['song', (int)substr($id, 2)];
        return ['song', (int)$id];
    }

    // ===== ENDPOINTS =====
    public static function getMusicFolders(): void
    {
        self::userId();
        self::out(['musicFolders' => ['musicFolder' => [['@id' => 1, '@name' => 'Muzyka']]]]);
    }

    public static function getGenres(): void
    {
        self::userId();
        $rows = Database::fetchAll(
            'SELECT genre AS name, COUNT(*) AS song_count FROM albums WHERE genre IS NOT NULL AND genre != "" GROUP BY genre ORDER BY genre'
        );
        $genres = array_map(fn($r) => ['@songCount' => (int)$r['song_count'], '@albumCount' => 0, 'value' => $r['name']], $rows);
        self::out(['genres' => ['genre' => $genres]]);
    }

    public static function getIndexes(): void
    {
        self::getArtistsInternal('indexes');
    }

    public static function getArtists(): void
    {
        self::getArtistsInternal('artists');
    }

    private static function getArtistsInternal(string $wrap): void
    {
        self::userId();
        $rows = Database::fetchAll('SELECT id, name, album_count FROM artists ORDER BY name_sort');
        $index = [];
        foreach ($rows as $r) {
            $letter = strtoupper(mb_substr($r['name'], 0, 1, 'UTF-8'));
            if (!preg_match('/[A-Z]/', $letter)) $letter = '#';
            $index[$letter][] = self::artistObj($r);
        }
        $indexArr = [];
        foreach ($index as $letter => $artists) {
            $indexArr[] = ['@name' => $letter, 'artist' => $artists];
        }
        if ($wrap === 'indexes') {
            self::out(['indexes' => ['@lastModified' => time() * 1000, '@ignoredArticles' => 'The A An', 'index' => $indexArr]]);
        } else {
            self::out(['artists' => ['@ignoredArticles' => 'The A An', 'index' => $indexArr]]);
        }
    }

    public static function getArtist(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $artist = Database::fetchOne('SELECT id, name, album_count FROM artists WHERE id = ?', [$id]);
        if ($artist === null) { self::notFound(); return; }
        $albums = Database::fetchAll(
            'SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id WHERE a.artist_id = ? ORDER BY a.year, a.name_sort',
            [$id]
        );
        $obj = self::artistObj($artist);
        $obj['album'] = array_map(fn($a) => self::albumObj($a), $albums);
        self::out(['artist' => $obj]);
    }

    public static function getAlbum(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $album = Database::fetchOne(
            'SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id WHERE a.id = ?',
            [$id]
        );
        if ($album === null) { self::notFound(); return; }
        $songs = Database::fetchAll(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s
             JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id
             WHERE s.album_id = ? ORDER BY s.disc_number, s.track_number, s.title_sort',
            [$id]
        );
        $obj = self::albumObj($album);
        $obj['song'] = array_map(fn($s) => self::songObj($s, $uid), $songs);
        self::out(['album' => $obj]);
    }

    public static function getSong(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $song = self::fetchSong($id);
        if ($song === null) { self::notFound(); return; }
        self::out(['song' => self::songObj($song, $uid)]);
    }

    public static function getMusicDirectory(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        if ($type === 'artist') {
            $artist = Database::fetchOne('SELECT * FROM artists WHERE id=?', [$id]);
            if (!$artist) { self::notFound(); return; }
            $albums = Database::fetchAll('SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id WHERE a.artist_id=? ORDER BY a.year, a.name_sort', [$id]);
            self::out(['directory' => ['@id' => 'ar' . $id, '@name' => $artist['name'], 'child' => array_map(fn($a) => self::albumObj($a), $albums)]]);
        } else {
            $album = Database::fetchOne('SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id WHERE a.id=?', [$id]);
            if (!$album) { self::notFound(); return; }
            $songs = Database::fetchAll('SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id WHERE s.album_id=? ORDER BY s.disc_number, s.track_number', [$id]);
            self::out(['directory' => ['@id' => 'al' . $id, '@name' => $album['name'], '@parent' => 'ar' . $album['artist_id'], 'child' => array_map(fn($s) => self::songObj($s, $uid), $songs)]]);
        }
    }

    public static function getAlbumList(): void { self::albumListInternal('albumList'); }
    public static function getAlbumList2(): void { self::albumListInternal('albumList2'); }

    private static function albumListInternal(string $wrap): void
    {
        $uid = self::userId();
        $p = Router::params();
        $type = $p['type'] ?? 'alphabeticalByName';
        $size = min(500, max(1, (int)($p['size'] ?? 50)));
        $offset = max(0, (int)($p['offset'] ?? 0));

        $order = match ($type) {
            'newest' => 'a.created_at DESC',
            'recent' => 'a.play_count DESC, a.created_at DESC',
            'frequent' => 'a.play_count DESC',
            'random' => 'RAND()',
            'alphabeticalByArtist' => 'ar.name_sort, a.year',
            'byYear' => 'a.year',
            default => 'a.name_sort',
        };
        $sql = 'SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id';
        $params = [];
        if ($type === 'byGenre' && !empty($p['genre'])) {
            $sql .= ' WHERE a.genre = ?';
            $params[] = $p['genre'];
        }
        $sql .= " ORDER BY {$order} LIMIT {$size} OFFSET {$offset}";
        $albums = Database::fetchAll($sql, $params);
        self::out([$wrap => ['album' => array_map(fn($a) => self::albumObj($a), $albums)]]);
    }

    public static function getRandomSongs(): void
    {
        $uid = self::userId();
        $size = min(500, max(1, (int)(Router::params()['size'] ?? 50)));
        $songs = Database::fetchAll(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s
             JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id
             ORDER BY RAND() LIMIT ' . $size
        );
        self::out(['randomSongs' => ['song' => array_map(fn($s) => self::songObj($s, $uid), $songs)]]);
    }

    public static function getSongsByGenre(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $genre = $p['genre'] ?? '';
        $size = min(500, max(1, (int)($p['size'] ?? 50)));
        $songs = Database::fetchAll(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s
             JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id
             WHERE al.genre = ? ORDER BY RAND() LIMIT ' . $size,
            [$genre]
        );
        self::out(['songsByGenre' => ['song' => array_map(fn($s) => self::songObj($s, $uid), $songs)]]);
    }

    public static function search2(): void { self::searchInternal('searchResult2'); }
    public static function search3(): void { self::searchInternal('searchResult3'); }

    private static function searchInternal(string $wrap): void
    {
        $uid = self::userId();
        $p = Router::params();
        $query = trim($p['query'] ?? '');
        $query = trim($query, '"');
        $songCount = min(500, (int)($p['songCount'] ?? 20));
        $albumCount = min(500, (int)($p['albumCount'] ?? 20));
        $artistCount = min(500, (int)($p['artistCount'] ?? 20));

        $result = [];
        if ($query === '') {
            self::out([$wrap => $result]);
            return;
        }
        $like = '%' . $query . '%';

        $artists = Database::fetchAll('SELECT id, name, album_count FROM artists WHERE name LIKE ? ORDER BY name_sort LIMIT ' . $artistCount, [$like]);
        $albums = Database::fetchAll('SELECT a.*, ar.name AS artist_name FROM albums a JOIN artists ar ON ar.id=a.artist_id WHERE a.name LIKE ? ORDER BY a.name_sort LIMIT ' . $albumCount, [$like]);
        $songs = Database::fetchAll('SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id WHERE s.title LIKE ? ORDER BY s.title_sort LIMIT ' . $songCount, [$like]);

        if ($artists) $result['artist'] = array_map(fn($a) => self::artistObj($a), $artists);
        if ($albums) $result['album'] = array_map(fn($a) => self::albumObj($a), $albums);
        if ($songs) $result['song'] = array_map(fn($s) => self::songObj($s, $uid), $songs);

        self::out([$wrap => $result]);
    }

    public static function getStarred(): void { self::starredInternal('starred'); }
    public static function getStarred2(): void { self::starredInternal('starred2'); }

    private static function starredInternal(string $wrap): void
    {
        $uid = self::userId();
        $songs = Database::fetchAll(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM stars st
             JOIN songs s ON s.id=st.item_id JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id
             WHERE st.user_id=? AND st.item_type="song" ORDER BY st.starred_at DESC',
            [$uid]
        );
        self::out([$wrap => ['song' => array_map(fn($s) => self::songObj($s, $uid), $songs)]]);
    }

    public static function star(): void { self::starToggle(true); }
    public static function unstar(): void { self::starToggle(false); }

    private static function starToggle(bool $add): void
    {
        $uid = self::userId();
        $p = Router::params();
        $ids = [];
        foreach (['id', 'albumId', 'artistId'] as $k) {
            if (isset($p[$k])) {
                $vals = is_array($p[$k]) ? $p[$k] : [$p[$k]];
                foreach ($vals as $v) $ids[] = $v;
            }
        }
        foreach ($ids as $rawId) {
            [$type, $id] = self::parseId($rawId);
            if ($add) {
                Database::execute('INSERT IGNORE INTO stars (user_id, item_type, item_id) VALUES (?, ?, ?)', [$uid, $type, $id]);
            } else {
                Database::execute('DELETE FROM stars WHERE user_id=? AND item_type=? AND item_id=?', [$uid, $type, $id]);
            }
        }
        self::out([]);
    }

    public static function scrobble(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $p = Router::params();
        $clientName = trim((string)($p['c'] ?? '')) ?: 'Subsonic client';
        $username = (string)($p['u'] ?? '');
        $thisDeviceId = 'sub-' . substr(md5($clientName . '|' . $username), 0, 24);
        $isFinal = !isset($p['submission']) || $p['submission'] === 'true' || $p['submission'] === '1';

        if (self::isKilled($uid, $thisDeviceId)) {
            self::markDevicePaused($uid, $thisDeviceId);
            self::devLog("scrobble KILLED client={$clientName}");
            self::respondSubsonicError(50, 'Playback was transferred', 403);
            return;
        }

        $newer = \Doniixify\Database::fetchOne(
            'SELECT name FROM active_devices
             WHERE user_id = ? AND device_id != ? AND is_playing = 1 AND playing_since IS NOT NULL
                   AND last_seen > (NOW() - INTERVAL 8 MINUTE)
             ORDER BY playing_since DESC LIMIT 1',
            [$uid, $thisDeviceId]
        );
        if ($newer !== null) {
            self::markDevicePaused($uid, $thisDeviceId);
            self::devLog("scrobble BLOCKED client={$clientName} (locked by {$newer['name']})");
            self::respondSubsonicError(50, 'Playback locked by ' . $newer['name'], 403);
            return;
        }

        if ($type === 'song' && $id > 0) {
            if ($isFinal) {
                Database::execute('UPDATE songs SET play_count = play_count + 1, last_played_at = NOW() WHERE id = ?', [$id]);
                Database::execute(
                    'INSERT INTO user_song_plays (user_id, song_id, play_count, last_played_at)
                     VALUES (?, ?, 1, NOW())
                     ON DUPLICATE KEY UPDATE play_count = play_count + 1, last_played_at = NOW()',
                    [$uid, $id]
                );
            }
            self::trackDevice($uid, $id, true);
            self::devLog("scrobble OK client={$clientName} song={$id} final=" . ($isFinal ? '1' : '0'));
        }
        self::out([]);
    }

    public static function getPlaylists(): void
    {
        $uid = self::userId();
        $rows = Database::fetchAll('SELECT * FROM playlists WHERE user_id=? ORDER BY name', [$uid]);
        $playlists = array_map(fn($p) => [
            '@id' => $p['id'],
            '@name' => $p['name'],
            '@owner' => Router::params()['u'] ?? '',
            '@songCount' => (int)$p['song_count'],
            '@duration' => (int)$p['duration'],
            '@created' => self::iso($p['created_at']),
            '@changed' => self::iso($p['changed_at']),
        ], $rows);
        self::out(['playlists' => ['playlist' => $playlists]]);
    }

    public static function getPlaylist(): void
    {
        $uid = self::userId();
        $id = (int)(Router::params()['id'] ?? 0);
        $pl = Database::fetchOne('SELECT * FROM playlists WHERE id=? AND user_id=?', [$id, $uid]);
        if ($pl === null) { self::notFound(); return; }
        $songs = Database::fetchAll(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM playlist_songs ps
             JOIN songs s ON s.id=ps.song_id JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id
             WHERE ps.playlist_id=? ORDER BY ps.position',
            [$id]
        );
        self::out(['playlist' => [
            '@id' => $pl['id'],
            '@name' => $pl['name'],
            '@songCount' => (int)$pl['song_count'],
            '@duration' => (int)$pl['duration'],
            '@created' => self::iso($pl['created_at']),
            '@changed' => self::iso($pl['changed_at']),
            'entry' => array_map(fn($s) => self::songObj($s, $uid), $songs),
        ]]);
    }

    public static function stream(): void
    {
        $uid = self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $song = self::fetchSong($id);
        if ($song === null || !is_file($song['path'])) { http_response_code(404); return; }

        $p = Router::params();
        $clientName = trim((string)($p['c'] ?? '')) ?: 'Subsonic client';
        $username = (string)($p['u'] ?? '');
        $thisDeviceId = 'sub-' . substr(md5($clientName . '|' . $username), 0, 24);

        if (self::isKilled($uid, $thisDeviceId)) {
            self::devLog("stream KILLED client={$clientName}");
            self::respondSubsonicError(50, 'Playback was transferred to another device', 403);
            return;
        }

        $newer = \Doniixify\Database::fetchOne(
            'SELECT device_id, name FROM active_devices
             WHERE user_id = ? AND device_id != ? AND is_playing = 1 AND playing_since IS NOT NULL
                   AND last_seen > (NOW() - INTERVAL 8 MINUTE)
             ORDER BY playing_since DESC LIMIT 1',
            [$uid, $thisDeviceId]
        );
        if ($newer !== null) {
            self::markDevicePaused($uid, $thisDeviceId);
            self::devLog("stream BLOCKED uid={$uid} client={$clientName} → locked by {$newer['name']}");
            self::respondSubsonicError(50, 'Playback locked by ' . $newer['name'], 403);
            return;
        }

        self::devLog("stream OK uid={$uid} client={$clientName} song={$id}");
        self::trackDevice($uid, $id, true);
        $mime = $song['content_type'] ?? '';
        if ($mime === '' || stripos($mime, 'audio/') !== 0) $mime = self::mimeFromPath($song['path']);
        self::sendFile($song['path'], $mime, $uid, $thisDeviceId);
    }

    private static function respondSubsonicError(int $code, string $msg, int $httpCode): void
    {
        http_response_code($httpCode);
        $p = Router::params();
        $format = strtolower((string)($p['f'] ?? 'xml'));
        if ($format === 'json' || $format === 'jsonp') {
            header('Content-Type: application/json; charset=UTF-8');
            $body = json_encode([
                'subsonic-response' => [
                    'status' => 'failed',
                    'version' => '1.16.1',
                    'type' => 'doniixify',
                    'error' => ['code' => $code, 'message' => $msg],
                ],
            ]);
            if ($format === 'jsonp' && !empty($p['callback'])) {
                echo preg_replace('/[^a-zA-Z0-9_]/', '', (string)$p['callback']) . '(' . $body . ');';
            } else {
                echo $body;
            }
            return;
        }
        header('Content-Type: application/xml; charset=UTF-8');
        $safe = htmlspecialchars($msg, ENT_QUOTES | ENT_XML1, 'UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>'
            . '<subsonic-response xmlns="http://subsonic.org/restapi" status="failed" version="1.16.1">'
            . '<error code="' . $code . '" message="' . $safe . '"/>'
            . '</subsonic-response>';
    }

    private static function devLog(string $msg): void
    {
        try {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/devices.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    public static function download(): void { self::stream(); }

    private static function mimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg', 'oga' => 'audio/ogg',
            'opus' => 'audio/opus',
            'm4a', 'mp4', 'aac' => 'audio/mp4',
            'wav' => 'audio/wav',
            'wma' => 'audio/x-ms-wma',
            default => 'audio/mpeg',
        };
    }

    private static function trackDevice(int $uid, int $songId, bool $playing): void
    {
        $p = Router::params();
        $client = trim((string)($p['c'] ?? '')) ?: 'Subsonic client';
        if (mb_strlen($client) > 255) $client = mb_substr($client, 0, 255);
        $username = (string)($p['u'] ?? '');
        $deviceId = 'sub-' . substr(md5($client . '|' . $username), 0, 24);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $effectivePlaying = $playing;
        if ($playing) {
            $newer = \Doniixify\Database::fetchOne(
                'SELECT 1 FROM active_devices
                 WHERE user_id = ? AND device_id != ? AND is_playing = 1 AND playing_since IS NOT NULL
                       AND last_seen > (NOW() - INTERVAL 8 MINUTE)
                 LIMIT 1',
                [$uid, $deviceId]
            );
            if ($newer !== null) $effectivePlaying = false;
        }

        $playingSinceSql = $effectivePlaying ? 'NOW()' : 'NULL';
        try {
            \Doniixify\Database::execute(
                "INSERT INTO active_devices (user_id, device_id, name, user_agent, current_song_id, is_playing, playing_since, last_seen)
                 VALUES (?, ?, ?, ?, ?, ?, {$playingSinceSql}, NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), user_agent = VALUES(user_agent),
                    current_song_id = VALUES(current_song_id),
                    playing_since = IF(is_playing = 1 AND VALUES(is_playing) = 1, playing_since, {$playingSinceSql}),
                    is_playing = VALUES(is_playing),
                    last_seen = NOW()",
                [$uid, $deviceId, $client, $ua, $songId > 0 ? $songId : null, $effectivePlaying ? 1 : 0]
            );
        } catch (\Throwable $e) {}
    }

    private static function markDevicePaused(int $uid, string $deviceId): void
    {
        try {
            \Doniixify\Database::execute(
                'UPDATE active_devices SET is_playing = 0, playing_since = NULL, last_seen = NOW()
                 WHERE user_id = ? AND device_id = ?',
                [$uid, $deviceId]
            );
        } catch (\Throwable $e) {}
    }

    private static function isKilled(int $uid, string $deviceId): bool
    {
        try {
            $row = \Doniixify\Database::fetchOne(
                'SELECT 1 FROM device_kills WHERE user_id = ? AND device_id = ? AND killed_until > NOW()',
                [$uid, $deviceId]
            );
            return $row !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function getCoverArt(): void
    {
        self::userId();
        [$type, $id] = self::parseId(Router::params()['id'] ?? '');
        $songPath = null;
        if ($type === 'album') {
            $row = Database::fetchOne('SELECT s.path FROM songs s WHERE s.album_id=? LIMIT 1', [$id]);
            $songPath = $row['path'] ?? null;
        } elseif ($type === 'artist') {
            $row = Database::fetchOne('SELECT s.path FROM songs s WHERE s.artist_id=? LIMIT 1', [$id]);
            $songPath = $row['path'] ?? null;
        } else {
            $row = Database::fetchOne('SELECT path FROM songs WHERE id=?', [$id]);
            $songPath = $row['path'] ?? null;
        }
        if ($songPath) {
            $cover = Scanner::coverPathFor($songPath);
            if ($cover !== null) {
                header('Content-Type: ' . $cover['mime']);
                header('Cache-Control: public, max-age=2592000');
                readfile($cover['path']);
                return;
            }
        }
        http_response_code(404);
    }

    public static function getLyrics(): void { self::out(['lyrics' => '']); }
    public static function getArtistInfo2(): void { self::out(['artistInfo2' => []]); }
    public static function getAlbumInfo2(): void { self::out(['albumInfo' => []]); }
    public static function getUser(): void
    {
        $u = Router::requireAuth();
        self::out(['user' => [
            '@username' => $u['username'],
            '@scrobblingEnabled' => true,
            '@adminRole' => (bool)$u['is_admin'],
            '@streamRole' => true,
            '@downloadRole' => true,
            '@playlistRole' => true,
        ]]);
    }

    private static function fetchSong(int $id): ?array
    {
        return Database::fetchOne(
            'SELECT s.*, ar.name AS artist_name, al.name AS album_name FROM songs s
             JOIN artists ar ON ar.id=s.artist_id JOIN albums al ON al.id=s.album_id WHERE s.id=?',
            [$id]
        );
    }

    private static function notFound(): void
    {
        $p = Router::params();
        Response::send(Response::error(Response::ERR_NOT_FOUND, 'Not found'), $p['f'] ?? 'xml', $p['callback'] ?? null);
    }

    private static function sendFile(string $path, string $mime, int $uid = 0, string $thisDeviceId = ''): void
    {
        $size = filesize($path);
        $fp = fopen($path, 'rb');
        if ($fp === false) { http_response_code(500); return; }
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Encoding: identity');
        header('X-Accel-Buffering: no');
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline; filename="' . basename($path) . '"');

        $range = $_SERVER['HTTP_RANGE'] ?? null;
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] === '' ? 0 : (int)$m[1];
            $end = $m[2] === '' ? $size - 1 : (int)$m[2];
            $end = min($end, $size - 1);
            $len = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
            header('Content-Length: ' . $len);
            fseek($fp, $start);
            self::streamLoop($fp, $len, $uid, $thisDeviceId);
        } else {
            header('Content-Length: ' . $size);
            self::streamLoop($fp, $size, $uid, $thisDeviceId);
        }
        fclose($fp);
    }

    private static function streamLoop($fp, int $total, int $uid, string $thisDeviceId): void
    {
        $rem = $total;
        $sinceCheck = 0;
        $checkEvery = 256 * 1024;
        while ($rem > 0 && !feof($fp)) {
            $chunk = fread($fp, min(8192, $rem));
            if ($chunk === false || $chunk === '') break;
            echo $chunk;
            $rem -= strlen($chunk);
            $sinceCheck += strlen($chunk);
            @ob_flush();
            @flush();
            if (connection_aborted()) break;
            if ($uid > 0 && $thisDeviceId !== '' && $sinceCheck >= $checkEvery) {
                $sinceCheck = 0;
                if (self::isKilled($uid, $thisDeviceId)) {
                    self::devLog("stream INTERRUPTED uid={$uid} deviceId={$thisDeviceId} — kill switch active");
                    self::markDevicePaused($uid, $thisDeviceId);
                    break;
                }
                $newer = \Doniixify\Database::fetchOne(
                    'SELECT 1 FROM active_devices
                     WHERE user_id = ? AND device_id != ? AND is_playing = 1 AND playing_since IS NOT NULL
                           AND last_seen > (NOW() - INTERVAL 8 MINUTE)
                     LIMIT 1',
                    [$uid, $thisDeviceId]
                );
                if ($newer !== null) {
                    self::devLog("stream INTERRUPTED uid={$uid} deviceId={$thisDeviceId} — newer device active");
                    self::markDevicePaused($uid, $thisDeviceId);
                    break;
                }
            }
        }
    }

    // ===== Subsonic spec compliance fillers =====

    public static function ping(): void
    {
        self::out([]);
    }

    public static function getLicense(): void
    {
        self::out(['license' => [
            'valid' => true,
            'email' => 'self-hosted@doniixify',
            'licenseExpires' => '2099-12-31T23:59:59',
        ]]);
    }

    public static function createPlaylist(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $name = trim((string)($p['name'] ?? 'New playlist'));
        $songIds = isset($p['songId']) ? (is_array($p['songId']) ? $p['songId'] : [$p['songId']]) : [];
        $playlistId = isset($p['playlistId']) ? (int)$p['playlistId'] : 0;
        try {
            if ($playlistId > 0) {
                $owner = Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$playlistId]);
                if (!$owner || (int)$owner['owner_id'] !== $uid) { self::out([]); return; }
                Database::execute('DELETE FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
            } else {
                Database::execute('INSERT INTO playlists (owner_id, name, created_at) VALUES (?, ?, NOW())', [$uid, $name]);
                $playlistId = (int)Database::lastInsertId();
            }
            $pos = 1;
            foreach ($songIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$playlistId, $sid, $pos++]);
            }
            self::out(['playlist' => ['id' => (string)$playlistId, 'name' => $name, 'owner' => '', 'songCount' => $pos - 1]]);
        } catch (\Throwable $e) {
            self::out(['error' => ['code' => 0, 'message' => $e->getMessage()]]);
        }
    }

    public static function updatePlaylist(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $plId = (int)($p['playlistId'] ?? 0);
        if ($plId <= 0) { self::out([]); return; }
        try {
            $owner = Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$plId]);
            if (!$owner || (int)$owner['owner_id'] !== $uid) { self::out([]); return; }
            if (!empty($p['name'])) {
                Database::execute('UPDATE playlists SET name = ? WHERE id = ?', [(string)$p['name'], $plId]);
            }
            if (!empty($p['songIdToAdd'])) {
                $songIds = is_array($p['songIdToAdd']) ? $p['songIdToAdd'] : [$p['songIdToAdd']];
                $maxPos = (int)(Database::fetchOne('SELECT COALESCE(MAX(position), 0) AS p FROM playlist_songs WHERE playlist_id = ?', [$plId])['p'] ?? 0);
                foreach ($songIds as $sid) {
                    $maxPos++;
                    Database::execute('INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)', [$plId, (int)$sid, $maxPos]);
                }
            }
            if (!empty($p['songIndexToRemove'])) {
                $indices = is_array($p['songIndexToRemove']) ? $p['songIndexToRemove'] : [$p['songIndexToRemove']];
                $songs = Database::fetchAll('SELECT id FROM playlist_songs WHERE playlist_id = ? ORDER BY position', [$plId]) ?: [];
                foreach ($indices as $idx) {
                    $idx = (int)$idx;
                    if (isset($songs[$idx])) {
                        Database::execute('DELETE FROM playlist_songs WHERE id = ?', [(int)$songs[$idx]['id']]);
                    }
                }
            }
            self::out([]);
        } catch (\Throwable $e) {
            self::out(['error' => ['code' => 0, 'message' => $e->getMessage()]]);
        }
    }

    public static function deletePlaylist(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $plId = (int)($p['id'] ?? 0);
        if ($plId <= 0) { self::out([]); return; }
        try {
            $owner = Database::fetchOne('SELECT owner_id FROM playlists WHERE id = ?', [$plId]);
            if (!$owner || (int)$owner['owner_id'] !== $uid) { self::out([]); return; }
            Database::execute('DELETE FROM playlist_songs WHERE playlist_id = ?', [$plId]);
            Database::execute('DELETE FROM playlists WHERE id = ?', [$plId]);
            self::out([]);
        } catch (\Throwable $e) {
            self::out(['error' => ['code' => 0, 'message' => $e->getMessage()]]);
        }
    }

    public static function getNowPlaying(): void
    {
        $uid = self::userId();
        try {
            $rows = Database::fetchAll(
                'SELECT s.id, s.title, ar.name AS artist, ad.last_seen, ad.name AS device_name,
                        ad.position, ad.user_id AS uid
                 FROM active_devices ad
                 LEFT JOIN songs s ON s.id = ad.song_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 WHERE ad.is_playing = 1 AND ad.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY ad.last_seen DESC LIMIT 50'
            ) ?: [];
            $entries = array_map(fn($r) => [
                'id' => (string)($r['id'] ?? 0),
                'title' => $r['title'] ?? '',
                'artist' => $r['artist'] ?? '',
                'username' => '',
                'minutesAgo' => 0,
                'playerName' => $r['device_name'] ?? '',
            ], $rows);
            self::out(['nowPlaying' => ['entry' => $entries]]);
        } catch (\Throwable $e) {
            self::out(['nowPlaying' => ['entry' => []]]);
        }
    }

    public static function setRating(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $sid = (int)($p['id'] ?? 0);
        $rating = max(0, min(5, (int)($p['rating'] ?? 0)));
        if ($sid <= 0) { self::out([]); return; }
        try {
            Database::execute(
                "CREATE TABLE IF NOT EXISTS song_ratings (
                    user_id INT NOT NULL, song_id INT NOT NULL, rating TINYINT NOT NULL, note TEXT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, song_id)
                )"
            );
            if ($rating === 0) {
                Database::execute('DELETE FROM song_ratings WHERE user_id = ? AND song_id = ?', [$uid, $sid]);
            } else {
                Database::execute(
                    'INSERT INTO song_ratings (user_id, song_id, rating) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE rating = VALUES(rating)',
                    [$uid, $sid, $rating]
                );
            }
            self::out([]);
        } catch (\Throwable $e) {
            self::out([]);
        }
    }

    public static function getTopSongs(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $artist = trim((string)($p['artist'] ?? ''));
        $count = max(1, min(50, (int)($p['count'] ?? 50)));
        try {
            $rows = $artist !== ''
                ? Database::fetchAll(
                    'SELECT s.id, s.title, s.duration, ar.name AS artist_name, s.play_count
                     FROM songs s JOIN artists ar ON ar.id = s.artist_id
                     WHERE LOWER(ar.name) = LOWER(?) ORDER BY s.play_count DESC LIMIT ?',
                    [$artist, $count]
                )
                : Database::fetchAll(
                    'SELECT s.id, s.title, s.duration, ar.name AS artist_name, s.play_count
                     FROM songs s JOIN artists ar ON ar.id = s.artist_id
                     ORDER BY s.play_count DESC LIMIT ?',
                    [$count]
                );
            $songs = array_map(fn($r) => [
                'id' => (string)$r['id'],
                'title' => $r['title'],
                'artist' => $r['artist_name'],
                'duration' => (int)$r['duration'],
            ], $rows ?: []);
            self::out(['topSongs' => ['song' => $songs]]);
        } catch (\Throwable $e) {
            self::out(['topSongs' => ['song' => []]]);
        }
    }

    public static function getSimilarSongs2(): void
    {
        $p = Router::params();
        $sid = (int)($p['id'] ?? 0);
        $count = max(1, min(50, (int)($p['count'] ?? 20)));
        if ($sid <= 0) { self::out(['similarSongs2' => ['song' => []]]); return; }
        try {
            $items = \Doniixify\Cache\RecommendationEngine::recommendForSong($sid, $count);
            $songs = array_map(fn($r) => [
                'id' => (string)$r['id'],
                'title' => $r['title'],
                'artist' => $r['artist_name'],
            ], $items);
            self::out(['similarSongs2' => ['song' => $songs]]);
        } catch (\Throwable $e) {
            self::out(['similarSongs2' => ['song' => []]]);
        }
    }

    public static function savePlayQueue(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $ids = isset($p['id']) ? (is_array($p['id']) ? $p['id'] : [$p['id']]) : [];
        $current = (int)($p['current'] ?? 0);
        $position = (int)($p['position'] ?? 0);
        try {
            Database::execute(
                "CREATE TABLE IF NOT EXISTS play_queues (
                    user_id INT PRIMARY KEY, ids_json TEXT, current_song_id INT, position_ms INT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )"
            );
            Database::execute(
                'INSERT INTO play_queues (user_id, ids_json, current_song_id, position_ms) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE ids_json = VALUES(ids_json), current_song_id = VALUES(current_song_id), position_ms = VALUES(position_ms)',
                [$uid, json_encode($ids), $current, $position]
            );
            self::out([]);
        } catch (\Throwable $e) {
            self::out([]);
        }
    }

    public static function getPlayQueue(): void
    {
        $uid = self::userId();
        try {
            $row = Database::fetchOne('SELECT ids_json, current_song_id, position_ms, updated_at FROM play_queues WHERE user_id = ?', [$uid]);
            if (!$row) { self::out(['playQueue' => null]); return; }
            $ids = json_decode($row['ids_json'] ?? '[]', true) ?: [];
            $entries = [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $songs = Database::fetchAll(
                    'SELECT s.id, s.title, ar.name AS artist FROM songs s JOIN artists ar ON ar.id = s.artist_id WHERE s.id IN (' . $placeholders . ')',
                    array_map('intval', $ids)
                ) ?: [];
                foreach ($songs as $s) {
                    $entries[] = ['id' => (string)$s['id'], 'title' => $s['title'], 'artist' => $s['artist']];
                }
            }
            self::out(['playQueue' => [
                'current' => (string)($row['current_song_id'] ?? ''),
                'position' => (int)$row['position_ms'],
                'changed' => $row['updated_at'],
                'entry' => $entries,
            ]]);
        } catch (\Throwable $e) {
            self::out(['playQueue' => null]);
        }
    }

    public static function getBookmarks(): void
    {
        $uid = self::userId();
        try {
            Database::execute(
                "CREATE TABLE IF NOT EXISTS bookmarks (
                    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, song_id INT NOT NULL,
                    position_ms INT DEFAULT 0, comment VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_user (user_id),
                    UNIQUE KEY uniq_user_song (user_id, song_id)
                )"
            );
            $rows = Database::fetchAll(
                'SELECT b.song_id, b.position_ms, b.comment, b.created_at, s.title, ar.name AS artist
                 FROM bookmarks b JOIN songs s ON s.id = b.song_id JOIN artists ar ON ar.id = s.artist_id
                 WHERE b.user_id = ? ORDER BY b.created_at DESC',
                [$uid]
            ) ?: [];
            $bookmarks = array_map(fn($r) => [
                'entry' => ['id' => (string)$r['song_id'], 'title' => $r['title'], 'artist' => $r['artist']],
                'position' => (int)$r['position_ms'],
                'comment' => $r['comment'] ?? '',
                'created' => $r['created_at'],
            ], $rows);
            self::out(['bookmarks' => ['bookmark' => $bookmarks]]);
        } catch (\Throwable $e) {
            self::out(['bookmarks' => ['bookmark' => []]]);
        }
    }

    public static function createBookmark(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $sid = (int)($p['id'] ?? 0);
        $pos = max(0, (int)($p['position'] ?? 0));
        $comment = trim((string)($p['comment'] ?? ''));
        if ($sid <= 0) { self::out([]); return; }
        try {
            Database::execute(
                'INSERT INTO bookmarks (user_id, song_id, position_ms, comment) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE position_ms = VALUES(position_ms), comment = VALUES(comment)',
                [$uid, $sid, $pos, $comment]
            );
            self::out([]);
        } catch (\Throwable $e) {
            self::out([]);
        }
    }

    public static function deleteBookmark(): void
    {
        $uid = self::userId();
        $p = Router::params();
        $sid = (int)($p['id'] ?? 0);
        if ($sid <= 0) { self::out([]); return; }
        try {
            Database::execute('DELETE FROM bookmarks WHERE user_id = ? AND song_id = ?', [$uid, $sid]);
            self::out([]);
        } catch (\Throwable $e) {
            self::out([]);
        }
    }
}
