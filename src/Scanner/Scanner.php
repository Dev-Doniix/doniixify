<?php

declare(strict_types=1);

namespace Doniixify\Scanner;

use Doniixify\Database;
use Doniixify\Env;

final class Scanner
{
    private const SUPPORTED = ['mp3', 'flac', 'ogg', 'm4a', 'opus', 'wav'];

    /** @var array<array{0:string,1:string,2:int}> */
    private array $pendingLyrics = [];

    public function scan(?string $path = null): array
    {
        $path = $path ?? Env::get('MUSIC_PATH', '/music');

        Database::execute('INSERT INTO scans (status) VALUES (?)', ['running']);
        $scanId = (int)Database::lastInsertId();

        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
            @chmod($path, 0775);
        }
        if (!is_dir($path)) {
            $msg = "Folder nie istnieje i nie można utworzyć: {$path}";
            Database::execute(
                'UPDATE scans SET status=?, finished_at=NOW(), error_message=? WHERE id=?',
                ['error', $msg, $scanId]
            );
            return ['error' => $msg];
        }
        if (!is_readable($path)) {
            $msg = "Cannot read: {$path} (check chown/chmod)";
            Database::execute(
                'UPDATE scans SET status=?, finished_at=NOW(), error_message=? WHERE id=?',
                ['error', $msg, $scanId]
            );
            return ['error' => $msg];
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $stats = ['scanned' => 0, 'added' => 0, 'updated' => 0, 'removed' => 0, 'errors' => 0];
        $stats['broken'] = self::purgeBrokenAndJunk();
        $seenPaths = [];

        try {
            $dirIterator = new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            );
            $iter = new \RecursiveIteratorIterator(
                $dirIterator,
                \RecursiveIteratorIterator::SELF_FIRST,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($iter as $file) {
                try { $file->isFile(); } catch (\Throwable $e) {
                    $stats['errors']++;
                    continue;
                }
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['part', 'ytdl', 'temp', 'tmp'], true)) {
                    @unlink($file->getRealPath());
                    continue;
                }
                if (!in_array($ext, self::SUPPORTED, true)) continue;

                $stats['scanned']++;
                $abs = $file->getRealPath();
                $seenPaths[] = $abs;

                try {
                    $existing = Database::fetchOne('SELECT id, modified_at FROM songs WHERE path = ?', [$abs]);
                    $mtime = filemtime($abs);
                    $modifiedAt = $mtime ? date('Y-m-d H:i:s', $mtime) : null;

                    if ($existing !== null && $existing['modified_at'] === $modifiedAt) {
                        continue;
                    }

                    $result = $this->processFile($abs, $ext);
                    if ($result === null) {
                        $stats['errors']++;
                        continue;
                    }
                    if ($existing === null) {
                        $stats['added']++;
                    } else {
                        $stats['updated']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    error_log("Scanner error on {$abs}: " . $e->getMessage());
                }
            }

            $stats['removed'] = $this->removeMissing($seenPaths);
            $stats['deduped'] = $this->dedupeDuplicates();

            $this->recomputeAggregates();

            Database::execute(
                'UPDATE scans SET status=?, finished_at=NOW(), files_scanned=?, files_added=?, files_removed=? WHERE id=?',
                ['done', $stats['scanned'], $stats['added'], $stats['removed'], $scanId]
            );
        } catch (\Throwable $e) {
            Database::execute(
                'UPDATE scans SET status=?, finished_at=NOW(), error_message=? WHERE id=?',
                ['error', $e->getMessage(), $scanId]
            );
            $stats['error'] = $e->getMessage();
        }

        $this->flushPendingLyrics();
        return $stats;
    }

    private function flushPendingLyrics(): void
    {
        if (empty($this->pendingLyrics)) return;
        $items = $this->pendingLyrics;
        $this->pendingLyrics = [];
        register_shutdown_function(function () use ($items) {
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            @set_time_limit(120);
            foreach ($items as $row) {
                try { \Doniixify\Web\LyricsController::prefetch($row[0], $row[1], $row[2]); } catch (\Throwable $e) {}
            }
        });
    }

    public function scanFile(string $absPath): array
    {
        if (!is_file($absPath)) {
            return ['ok' => false, 'error' => 'file not found'];
        }
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED, true)) {
            return ['ok' => false, 'error' => 'unsupported extension'];
        }
        try {
            $result = $this->processFile($absPath, $ext);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($result === null) {
            return ['ok' => false, 'error' => 'processFile returned null'];
        }
        $songId = (int)($result['id'] ?? 0);
        if ($songId > 0) {
            $this->recomputeAggregatesFor($songId);
        }
        $this->flushPendingLyrics();
        return ['ok' => true, 'song_id' => $songId];
    }

    private function recomputeAggregatesFor(int $songId): void
    {
        $row = Database::fetchOne('SELECT album_id, artist_id FROM songs WHERE id = ?', [$songId]);
        if (!$row) return;
        Database::execute(
            'UPDATE albums SET song_count = (SELECT COUNT(*) FROM songs WHERE album_id = ?),
                duration = (SELECT COALESCE(SUM(duration), 0) FROM songs WHERE album_id = ?)
             WHERE id = ?',
            [$row['album_id'], $row['album_id'], $row['album_id']]
        );
        Database::execute(
            'UPDATE artists SET song_count = (SELECT COUNT(*) FROM songs WHERE artist_id = ?),
                album_count = (SELECT COUNT(*) FROM albums WHERE artist_id = ?)
             WHERE id = ?',
            [$row['artist_id'], $row['artist_id'], $row['artist_id']]
        );
    }

    private function processFile(string $abs, string $ext): ?array
    {
        $parsed = Id3Parser::parse($abs);
        $rawDuration = isset($parsed['duration']) ? (int)$parsed['duration'] : 0;
        $rawSize = (int)(@filesize($abs) ?: 0);
        if ($rawDuration > 0 && $rawDuration < 10) {
            $this->purgeCorruptFile($abs);
            return null;
        }
        if ($rawSize > 0 && $rawSize < 30_000) {
            $this->purgeCorruptFile($abs);
            return null;
        }
        $tagsCorrupt = self::looksCorrupt($parsed['title'] ?? '') || self::looksCorrupt($parsed['artist'] ?? '');
        if ((empty($parsed['title']) && empty($parsed['artist'])) || $tagsCorrupt) {
            $fromName = $this->guessFromFilename($abs);
            $parsed = array_merge($fromName, array_filter($parsed, fn($v, $k) => !in_array($k, ['title', 'artist'], true) || !self::looksCorrupt((string)$v), ARRAY_FILTER_USE_BOTH));
        }
        $tags = $parsed;

        // Folder hierarchia: <MUSIC_PATH>/<Artist>/<Album>/<Track>.ext
        $folderArtist = basename(dirname(dirname($abs)));
        $folderAlbum = basename(dirname($abs));
        $artistName = $this->cleanString($tags['artist'] ?? '');
        if ($artistName === '' || $artistName === 'Unknown' || $artistName === 'Unknown Artist') {
            $artistName = $this->cleanString($folderArtist ?: 'Unknown Artist');
        }
        $albumName = $this->cleanString($tags['album'] ?? '');
        if ($albumName === '' || $albumName === 'Unknown' || $albumName === 'Unknown Album') {
            $albumName = $this->cleanString($folderAlbum ?: 'Unknown Album');
        }
        $title = $this->cleanString($tags['title'] ?? pathinfo($abs, PATHINFO_FILENAME));
        $year = isset($tags['year']) ? (int)$tags['year'] : null;
        $genre = isset($tags['genre']) ? $this->cleanString($tags['genre']) : null;
        $track = isset($tags['track']) ? (int)$tags['track'] : null;
        $disc = isset($tags['disc']) ? (int)$tags['disc'] : null;
        $duration = isset($tags['duration']) ? (int)$tags['duration'] : 0;

        $artistId = $this->upsertArtist($artistName);
        $albumId = $this->upsertAlbum($artistId, $albumName, $year, $genre);

        $size = filesize($abs) ?: 0;
        $mtime = filemtime($abs);
        $modifiedAt = $mtime ? date('Y-m-d H:i:s', $mtime) : null;

        if (!empty($tags['cover']['data'])) {
            $this->saveCover($abs, $tags['cover']);
        } else {
            $folderCover = $this->findFolderCover($abs);
            if ($folderCover !== null) {
                $this->saveCover($abs, $folderCover);
            }
        }

        $existing = Database::fetchOne('SELECT id FROM songs WHERE path = ?', [$abs]);
        if ($existing !== null) {
            Database::execute(
                'UPDATE songs SET album_id=?, artist_id=?, title=?, title_sort=?, track_number=?, disc_number=?, duration=?, suffix=?, content_type=?, size=?, modified_at=? WHERE id=?',
                [$albumId, $artistId, $title, $this->sortKey($title), $track, $disc, $duration, $ext, $this->mimeFor($ext), $size, $modifiedAt, $existing['id']]
            );
            return ['id' => (int)$existing['id'], 'updated' => true];
        }

        $titleLower = mb_strtolower($title);
        $dupCandidate = Database::fetchOne(
            'SELECT id, path FROM songs WHERE artist_id = ? AND LOWER(title) = ? AND ABS(duration - ?) <= 5 LIMIT 1',
            [$artistId, $titleLower, $duration]
        );
        if ($dupCandidate !== null) {
            $oldPath = (string)($dupCandidate['path'] ?? '');
            if ($oldPath !== '' && !is_file($oldPath)) {
                Database::execute(
                    'UPDATE songs SET album_id=?, title=?, title_sort=?, track_number=?, disc_number=?, duration=?, suffix=?, content_type=?, size=?, path=?, modified_at=? WHERE id=?',
                    [$albumId, $title, $this->sortKey($title), $track, $disc, $duration, $ext, $this->mimeFor($ext), $size, $abs, $modifiedAt, $dupCandidate['id']]
                );
                return ['id' => (int)$dupCandidate['id'], 'duplicate' => true];
            }
            $this->purgeFile($abs);
            return ['id' => (int)$dupCandidate['id'], 'duplicate' => true, 'purged' => true];
        }

        Database::execute(
            'INSERT INTO songs (album_id, artist_id, title, title_sort, track_number, disc_number, duration, suffix, content_type, size, path, modified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$albumId, $artistId, $title, $this->sortKey($title), $track, $disc, $duration, $ext, $this->mimeFor($ext), $size, $abs, $modifiedAt]
        );
        $newSongId = (int)Database::lastInsertId();
        $this->pendingLyrics[] = [$artistName, $title, $duration];
        return ['id' => $newSongId, 'added' => true];
    }

    private function upsertArtist(string $name): int
    {
        $row = Database::fetchOne('SELECT id FROM artists WHERE name = ?', [$name]);
        if ($row !== null) return (int)$row['id'];
        Database::execute('INSERT INTO artists (name, name_sort) VALUES (?, ?)', [$name, $this->sortKey($name)]);
        return (int)Database::lastInsertId();
    }

    private function upsertAlbum(int $artistId, string $name, ?int $year, ?string $genre): int
    {
        $row = Database::fetchOne('SELECT id FROM albums WHERE artist_id = ? AND name = ?', [$artistId, $name]);
        if ($row !== null) {
            if ($year !== null || $genre !== null) {
                Database::execute(
                    'UPDATE albums SET year = COALESCE(?, year), genre = COALESCE(?, genre) WHERE id = ?',
                    [$year, $genre, $row['id']]
                );
            }
            return (int)$row['id'];
        }
        Database::execute(
            'INSERT INTO albums (artist_id, name, name_sort, year, genre) VALUES (?, ?, ?, ?, ?)',
            [$artistId, $name, $this->sortKey($name), $year, $genre]
        );
        return (int)Database::lastInsertId();
    }

    private function removeMissing(array $seenPaths): int
    {
        if (empty($seenPaths)) {
            $count = (int)Database::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn();
            Database::execute('DELETE FROM songs');
            return $count;
        }
        $existing = Database::fetchAll('SELECT id, path FROM songs');
        $seenSet = array_flip($seenPaths);
        $toDelete = [];
        foreach ($existing as $row) {
            if (!isset($seenSet[$row['path']])) {
                $toDelete[] = (int)$row['id'];
            }
        }
        if (!empty($toDelete)) {
            $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
            Database::execute("DELETE FROM songs WHERE id IN ({$placeholders})", $toDelete);
        }
        return count($toDelete);
    }

    private function dedupeDuplicates(): int
    {
        $candidates = Database::fetchAll(
            "SELECT artist_id, LOWER(title) AS title_lc
             FROM songs
             GROUP BY artist_id, LOWER(title)
             HAVING COUNT(*) > 1"
        );
        if (empty($candidates)) return 0;
        $coverDir = (string)Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        $removed = 0;
        foreach ($candidates as $c) {
            $rows = Database::fetchAll(
                "SELECT id, artist_id, duration, path, size FROM songs
                 WHERE artist_id = ? AND LOWER(title) = ? ORDER BY duration",
                [(int)$c['artist_id'], (string)$c['title_lc']]
            );
            if (count($rows) < 2) continue;
            $merged = [];
            $current = [];
            $lastDur = null;
            foreach ($rows as $r) {
                $d = (int)$r['duration'];
                if ($lastDur === null || ($d - $lastDur) <= 5) {
                    $current[] = $r;
                } else {
                    if (count($current) > 1) $merged[] = $current;
                    $current = [$r];
                }
                $lastDur = $d;
            }
            if (count($current) > 1) $merged[] = $current;
            foreach ($merged as $grp) {
                if (count($grp) < 2) continue;
                usort($grp, function ($a, $b) use ($coverDir) {
                    $aHasCover = is_file($coverDir . '/' . md5((string)$a['path'])) ? 1 : 0;
                    $bHasCover = is_file($coverDir . '/' . md5((string)$b['path'])) ? 1 : 0;
                    if ($aHasCover !== $bHasCover) return $bHasCover - $aHasCover;
                    $ae = is_file((string)$a['path']) ? 1 : 0;
                    $be = is_file((string)$b['path']) ? 1 : 0;
                    if ($ae !== $be) return $be - $ae;
                    $as = (int)($a['size'] ?? 0);
                    $bs = (int)($b['size'] ?? 0);
                    if ($as !== $bs) return $bs - $as;
                    return (int)$a['id'] - (int)$b['id'];
                });
                $keep = array_shift($grp);
                $keepPath = (string)($keep['path'] ?? '');
                foreach ($grp as $dup) {
                    $dupPath = (string)($dup['path'] ?? '');
                    if ($dupPath !== '' && $dupPath !== $keepPath && is_file($dupPath)) {
                        $this->purgeFile($dupPath);
                    }
                    Database::execute('DELETE FROM stars WHERE item_type = ? AND item_id = ?', ['song', (int)$dup['id']]);
                    Database::execute('DELETE FROM playlist_songs WHERE song_id = ?', [(int)$dup['id']]);
                    Database::execute('DELETE FROM songs WHERE id = ?', [(int)$dup['id']]);
                    $removed++;
                }
            }
        }
        return $removed;
    }

    private function purgeFile(string $abs): void
    {
        self::purgeFileStatic($abs);
    }

    private function purgeCorruptFile(string $abs): void
    {
        self::purgeFileStatic($abs);
        try {
            Database::execute('DELETE FROM songs WHERE path = ?', [$abs]);
        } catch (\Throwable $e) {}
    }

    private static function purgeFileStatic(string $abs): void
    {
        if ($abs === '' || !is_file($abs)) return;
        @unlink($abs);
        $cacheDir = (string)Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        $hash = md5($abs);
        @unlink($cacheDir . '/' . $hash);
        @unlink($cacheDir . '/' . $hash . '.mime');
        $dir = dirname($abs);
        if (is_dir($dir)) {
            $entries = @scandir($dir) ?: [];
            $rest = array_values(array_diff($entries, ['.', '..']));
            if (empty($rest)) {
                @rmdir($dir);
                $parent = dirname($dir);
                if (is_dir($parent)) {
                    $parentEntries = @scandir($parent) ?: [];
                    if (count(array_diff($parentEntries, ['.', '..'])) === 0) @rmdir($parent);
                }
            }
        }
    }

    public static function purgeSongById(int $songId): bool
    {
        $row = Database::fetchOne('SELECT id, path, album_id, artist_id FROM songs WHERE id = ?', [$songId]);
        if (!$row) return false;
        $abs = (string)($row['path'] ?? '');
        $albumId = (int)($row['album_id'] ?? 0);
        $artistId = (int)($row['artist_id'] ?? 0);

        if ($abs !== '') self::purgeFileStatic($abs);
        $cacheDir = (string)Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        @unlink($cacheDir . '/remote-' . $songId . '.jpg');
        @unlink($cacheDir . '/remote-' . $songId . '.miss');
        Database::execute('DELETE FROM stars WHERE item_type = ? AND item_id = ?', ['song', $songId]);
        Database::execute('DELETE FROM playlist_songs WHERE song_id = ?', [$songId]);
        Database::execute('DELETE FROM songs WHERE id = ?', [$songId]);

        if ($albumId > 0) {
            $remaining = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM songs WHERE album_id = ?', [$albumId])['c'] ?? 0);
            if ($remaining === 0) {
                Database::execute('DELETE FROM albums WHERE id = ?', [$albumId]);
            } else {
                Database::execute(
                    'UPDATE albums SET song_count = ?, duration = (SELECT COALESCE(SUM(duration), 0) FROM songs WHERE album_id = ?) WHERE id = ?',
                    [$remaining, $albumId, $albumId]
                );
            }
        }
        if ($artistId > 0) {
            $remaining = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM songs WHERE artist_id = ?', [$artistId])['c'] ?? 0);
            if ($remaining === 0) {
                Database::execute('DELETE FROM artists WHERE id = ?', [$artistId]);
            } else {
                Database::execute(
                    'UPDATE artists SET song_count = ?, album_count = (SELECT COUNT(*) FROM albums WHERE artist_id = ?) WHERE id = ?',
                    [$remaining, $artistId, $artistId]
                );
            }
        }
        return true;
    }

    public static function purgeMissingFiles(): int
    {
        $rows = Database::fetchAll('SELECT id, path FROM songs');
        $deleted = 0;
        $albumIds = [];
        $artistIds = [];
        foreach ($rows as $r) {
            $path = (string)($r['path'] ?? '');
            if ($path === '' || is_file($path)) continue;
            $sid = (int)$r['id'];
            $detail = Database::fetchOne('SELECT album_id, artist_id FROM songs WHERE id = ?', [$sid]);
            if ($detail) {
                $albumIds[(int)$detail['album_id']] = true;
                $artistIds[(int)$detail['artist_id']] = true;
            }
            Database::execute('DELETE FROM stars WHERE item_type = ? AND item_id = ?', ['song', $sid]);
            Database::execute('DELETE FROM playlist_songs WHERE song_id = ?', [$sid]);
            Database::execute('DELETE FROM songs WHERE id = ?', [$sid]);
            $deleted++;
        }
        foreach (array_keys($albumIds) as $aid) {
            if ($aid <= 0) continue;
            $cnt = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM songs WHERE album_id = ?', [$aid])['c'] ?? 0);
            if ($cnt === 0) Database::execute('DELETE FROM albums WHERE id = ?', [$aid]);
        }
        foreach (array_keys($artistIds) as $aid) {
            if ($aid <= 0) continue;
            $cnt = (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM songs WHERE artist_id = ?', [$aid])['c'] ?? 0);
            if ($cnt === 0) Database::execute('DELETE FROM artists WHERE id = ?', [$aid]);
        }
        return $deleted;
    }

    public static function backfillAlbumYears(int $limit = 20): int
    {
        $rows = Database::fetchAll(
            "SELECT al.id, al.name, ar.name AS artist_name
             FROM albums al
             JOIN artists ar ON ar.id = al.artist_id
             WHERE (al.release_date IS NULL OR al.release_date = '')
               AND al.name <> 'Singles' AND al.name <> 'Unknown Album'
             ORDER BY al.id DESC
             LIMIT " . (int)$limit
        );
        $updated = 0;
        foreach ($rows as $r) {
            try {
                $rd = self::fetchAlbumReleaseDate((string)$r['artist_name'], (string)$r['name']);
                if ($rd === '') continue;
                $year = (int)substr($rd, 0, 4);
                if ($year < 1900 || $year > 2100) continue;
                Database::execute(
                    'UPDATE albums SET release_date = ?, year = COALESCE(NULLIF(year, 0), ?) WHERE id = ?',
                    [$rd, $year, (int)$r['id']]
                );
                $updated++;
            } catch (\Throwable $e) {}
        }
        return $updated;
    }

    private static function fetchAlbumReleaseDate(string $artist, string $album): string
    {
        $q = trim($artist . ' ' . $album);
        if ($q === '') return '';

        $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
        if ($token === '') return '';

        $url = 'https://api.spotify.com/v1/search?'
            . 'q=' . rawurlencode($q)
            . '&type=track'
            . '&limit=5'
            . '&market=PL';
        $body = self::quickHttpGetAuth($url, $token, 6);
        if ($body === '') return '';

        $data = json_decode($body, true);
        $tracks = is_array($data) ? ($data['tracks']['items'] ?? []) : [];
        if (!is_array($tracks) || empty($tracks)) return '';

        $albumLower = mb_strtolower(trim($album));
        foreach ($tracks as $t) {
            if (!is_array($t)) continue;
            $tAlbumName = mb_strtolower((string)($t['album']['name'] ?? ''));
            $rd = (string)($t['album']['release_date'] ?? '');
            if ($rd === '') continue;
            if ($tAlbumName === $albumLower || str_contains($tAlbumName, $albumLower) || str_contains($albumLower, $tAlbumName)) {
                return substr($rd, 0, 10);
            }
        }
        $first = $tracks[0]['album']['release_date'] ?? '';
        return $first !== '' ? substr((string)$first, 0, 10) : '';
    }

    private static function quickHttpGetAuth(string $url, string $token, int $timeoutSec = 6): string
    {
        if (!function_exists('curl_init')) return '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return ($status === 200 && is_string($body)) ? $body : '';
    }

    private static function quickHttpGet(string $url, int $timeoutSec = 5): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Doniixify)',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($status === 200 && is_string($body)) ? $body : '';
        }
        $ctx = stream_context_create(['http' => ['timeout' => $timeoutSec, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : '';
    }

    public static function purgeBrokenAndJunk(): int
    {
        $rows = Database::fetchAll(
            "SELECT id, path, duration FROM songs
             WHERE duration <= 5
                OR path LIKE '%.temp'
                OR path LIKE '%.tmp'
                OR path LIKE '%.part'
                OR path LIKE '%.ytdl'"
        );
        $removed = 0;
        foreach ($rows as $r) {
            $abs = (string)($r['path'] ?? '');
            if ($abs !== '' && is_file($abs)) self::purgeFileStatic($abs);
            $sid = (int)$r['id'];
            Database::execute('DELETE FROM stars WHERE item_type = ? AND item_id = ?', ['song', $sid]);
            Database::execute('DELETE FROM playlist_songs WHERE song_id = ?', [$sid]);
            Database::execute('DELETE FROM songs WHERE id = ?', [$sid]);
            $removed++;
        }
        $musicPath = rtrim((string)Env::get('MUSIC_PATH', '/music'), '/');
        if ($musicPath !== '' && is_dir($musicPath)) {
            try {
                $iter = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($musicPath, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iter as $f) {
                    if (!$f->isFile()) continue;
                    $ext = strtolower($f->getExtension());
                    if (in_array($ext, ['part', 'ytdl', 'temp', 'tmp'], true)) {
                        @unlink($f->getRealPath());
                        $removed++;
                    }
                }
            } catch (\Throwable $e) {}
        }
        return $removed;
    }

    private function recomputeAggregates(): void
    {
        $pdo = Database::pdo();
        $pdo->exec('UPDATE albums a SET
            song_count = (SELECT COUNT(*) FROM songs s WHERE s.album_id = a.id),
            duration   = (SELECT COALESCE(SUM(duration), 0) FROM songs s WHERE s.album_id = a.id)');
        $pdo->exec('UPDATE artists ar SET
            song_count  = (SELECT COUNT(*) FROM songs s WHERE s.artist_id = ar.id),
            album_count = (SELECT COUNT(*) FROM albums al WHERE al.artist_id = ar.id)');
        $pdo->exec('DELETE FROM albums WHERE song_count = 0');
        $pdo->exec('DELETE FROM artists WHERE song_count = 0');
    }

    private function guessFromFilename(string $abs): array
    {
        $name = str_replace('_', ' ', pathinfo($abs, PATHINFO_FILENAME));
        $folderArtist = basename(dirname(dirname($abs)));
        if ($folderArtist !== '' && $folderArtist !== '.' && $folderArtist !== 'music') {
            return ['title' => $name];
        }
        $parts = preg_split('/\s*-\s*/', $name, 2);
        if (count($parts) === 2) {
            return ['artist' => trim($parts[0]), 'title' => trim($parts[1]), 'album' => 'Unknown Album'];
        }
        return ['title' => $name];
    }

    private function cleanString(string $s): string
    {
        $s = str_replace('_', ' ', $s);
        $s = trim($s);
        $s = preg_replace('/\s+/', ' ', $s) ?: $s;
        $s = preg_replace('/\?+$/u', '', $s);
        $s = trim($s);
        if ($s === '') return 'Unknown';
        return mb_substr($s, 0, 490);
    }

    private static function looksCorrupt(string $s): bool
    {
        if ($s === '') return false;
        $qCount = substr_count($s, '?');
        if ($qCount === 0) return false;
        $len = mb_strlen($s);
        if ($len === 0) return false;
        return ($qCount / $len) >= 0.15;
    }

    private function sortKey(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/^(the|a|an)\s+/i', '', $s) ?? $s;
        return mb_substr($s, 0, 490);
    }

    private function findFolderCover(string $songPath): ?array
    {
        $dir = dirname($songPath);
        $candidates = ['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'album.jpg', 'album.png', 'front.jpg', 'AlbumArt.jpg', 'AlbumArtSmall.jpg', 'thumb.jpg'];
        foreach ($candidates as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                $data = @file_get_contents($path);
                if ($data === false || strlen($data) < 100) continue;
                $mime = str_ends_with(strtolower($name), '.png') ? 'image/png' : 'image/jpeg';
                return ['mime' => $mime, 'data' => $data];
            }
        }
        $imgs = @glob($dir . '/*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
        if (!empty($imgs)) {
            foreach ($imgs as $img) {
                if (filesize($img) > 200 * 1024) continue;
                $data = @file_get_contents($img);
                if ($data === false || strlen($data) < 100) continue;
                $mime = str_ends_with(strtolower($img), '.png') ? 'image/png' : 'image/jpeg';
                return ['mime' => $mime, 'data' => $data];
            }
        }
        return null;
    }

    private function saveCover(string $songPath, array $cover): void
    {
        $cacheDir = Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        if (!is_writable($cacheDir)) return;

        $hash = md5($songPath);
        $coverPath = $cacheDir . '/' . $hash;
        if (!is_file($coverPath)) {
            $data = self::stripBeforeImageHeader((string)$cover['data']);
            @file_put_contents($coverPath, $data);
            @file_put_contents($coverPath . '.mime', $cover['mime']);
        }
    }

    private static function stripBeforeImageHeader(string $data): string
    {
        if ($data === '') return $data;
        $jpgStart = strpos($data, "\xFF\xD8\xFF");
        $pngStart = strpos($data, "\x89PNG\r\n\x1A\n");
        $candidates = array_filter([$jpgStart, $pngStart], fn($v) => $v !== false && $v >= 0);
        if (empty($candidates)) return $data;
        $offset = min($candidates);
        return $offset === 0 ? $data : substr($data, $offset);
    }

    public static function coverPathFor(string $songPath): ?array
    {
        $cacheDir = Env::get('COVER_CACHE_PATH', __DIR__ . '/../../storage/covers');
        $hash = md5($songPath);
        $path = $cacheDir . '/' . $hash;
        if (!is_file($path)) return null;
        if (filesize($path) < 200) return null;
        self::repairCoverFile($path);
        $mime = @file_get_contents($path . '.mime') ?: 'image/jpeg';
        return ['path' => $path, 'mime' => trim($mime)];
    }

    private static function repairCoverFile(string $path): void
    {
        $head = @file_get_contents($path, false, null, 0, 16);
        if ($head === false || $head === '') return;
        if (substr($head, 0, 3) === "\xFF\xD8\xFF") return;
        if (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n") return;
        $full = @file_get_contents($path);
        if ($full === false) return;
        $fixed = self::stripBeforeImageHeader($full);
        if ($fixed === $full || $fixed === '') return;
        @file_put_contents($path, $fixed);
    }

    private function mimeFor(string $ext): string
    {
        return match ($ext) {
            'mp3' => 'audio/mpeg',
            'flac' => 'audio/flac',
            'ogg' => 'audio/ogg',
            'm4a' => 'audio/mp4',
            'opus' => 'audio/opus',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };
    }
}
