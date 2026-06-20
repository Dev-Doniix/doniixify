<?php

declare(strict_types=1);

namespace Doniixify\Downloader;

use Doniixify\Env;
use Doniixify\Scanner\Scanner;

final class YoutubeDownloader
{
    public static function download(string $spotifyUrl, ?array $hint = null): array
    {
        $log = self::logger();
        $pid = getmypid();
        $log("download START url={$spotifyUrl}");
        JobTracker::start($pid, $spotifyUrl);
        JobTracker::update($pid, ['stage' => 'resolving metadata']);

        $meta = self::resolveMetadata($spotifyUrl, $hint);
        if ($meta === null) {
            $log("download FAIL: no metadata");
            JobTracker::complete($pid, false, 'Could not resolve track metadata');
            return ['ok' => false, 'error' => 'Could not resolve track metadata'];
        }

        $title = $meta['title'];
        $artist = $meta['artist'];
        $album = $meta['album'];
        $log("download metadata: {$artist} - {$title} [{$album}]");
        JobTracker::update($pid, ['title' => $title, 'artist' => $artist, 'album' => $album, 'status' => 'downloading', 'stage' => 'preparing']);

        $musicPath = rtrim((string)Env::get('MUSIC_PATH', '/music'), '/');
        $log("MUSIC_PATH={$musicPath} exists=" . (is_dir($musicPath) ? '1' : '0'));
        if (!is_dir($musicPath)) {
            // Spróbuj recursywnie z różnymi permissions
            $created = @mkdir($musicPath, 0775, true);
            if (!$created) $created = @mkdir($musicPath, 0777, true);
            if (!$created && !is_dir($musicPath)) {
                // Może parent istnieje ale brak permissions? Sprawdź każdy poziom
                $parent = dirname($musicPath);
                $log("mkdir FAIL — parent={$parent} exists=" . (is_dir($parent) ? '1' : '0') . " writable=" . (is_dir($parent) && is_writable($parent) ? '1' : '0'));
                JobTracker::complete($pid, false, "Music folder cannot be created: {$musicPath} (parent: {$parent})");
                return ['ok' => false, 'error' => "Music folder cannot be created: {$musicPath}"];
            }
            @chmod($musicPath, 0775);
            $log("MUSIC_PATH created: {$musicPath}");
        }
        if (!is_writable($musicPath)) {
            @chmod($musicPath, 0775);
            if (!is_writable($musicPath)) {
                $log("download FAIL: MUSIC_PATH not writable: {$musicPath} (chmod 775 nie pomogło)");
                JobTracker::complete($pid, false, "Music folder not writable: {$musicPath}");
                return ['ok' => false, 'error' => "Music folder not writable: {$musicPath}"];
            }
        }

        $quality = strtolower(trim((string)Env::get('AUDIO_QUALITY', 'mp3-320')));
        [$audioFormat, $audioQualityFlag] = match ($quality) {
            'flac' => ['flac', '0'],
            'opus-256', 'opus' => ['opus', '0'],
            'mp3-192' => ['mp3', '5'],
            'mp3-256' => ['mp3', '2'],
            'best', 'source', 'native' => ['best', '0'],
            default => ['mp3', '0'],
        };

        $artistDir = $musicPath . '/' . self::sanitize($artist);
        $albumDir = $artistDir . '/' . self::sanitize($album);
        $baseName = $albumDir . '/' . self::sanitize($title);
        $isBestMode = ($audioFormat === 'best');

        $existsAny = null;
        foreach (['opus', 'webm', 'm4a', 'mp3', 'flac', 'ogg'] as $ext) {
            if (is_file($baseName . '.' . $ext)) { $existsAny = $baseName . '.' . $ext; break; }
        }
        if ($existsAny !== null) {
            $log("download SKIP exists: {$existsAny}");
            JobTracker::update($pid, ['stage' => 'already in library', 'progress' => 100]);
            JobTracker::complete($pid, true);
            return ['ok' => true, 'path' => $existsAny, 'skipped' => true, 'title' => $title, 'artist' => $artist];
        }
        if (!is_dir($albumDir) && !@mkdir($albumDir, 0775, true) && !is_dir($albumDir)) {
            $log("download FAIL: mkdir {$albumDir}");
            JobTracker::complete($pid, false, "Cannot create folder: {$albumDir}");
            return ['ok' => false, 'error' => "Cannot create folder: {$albumDir}"];
        }

        $tmpRoot = dirname(__DIR__, 2) . '/storage/tmp-dl';
        if (!is_dir($tmpRoot)) @mkdir($tmpRoot, 0775, true);
        $tmpDir = $tmpRoot . '/' . $pid . '-' . bin2hex(random_bytes(4));
        if (!@mkdir($tmpDir, 0775, true)) {
            $log("download FAIL: mkdir tmpDir {$tmpDir}");
            JobTracker::complete($pid, false, "Cannot create temp folder");
            return ['ok' => false, 'error' => 'Cannot create temp folder'];
        }
        $tmpBase = $tmpDir . '/dl';
        $outTemplate = $isBestMode ? ($tmpBase . '.%(ext)s') : ($tmpBase . '.' . $audioFormat);

        $bin = self::ytDlpBinary();
        $ffmpeg = (string)Env::get('FFMPEG_BIN', '/usr/bin/ffmpeg');
        $cleanArtist = preg_replace('/[^\p{L}\p{N}\s\'\-&]/u', ' ', $artist) ?? $artist;
        $cleanTitle = preg_replace('/[^\p{L}\p{N}\s\'\-&]/u', ' ', $title) ?? $title;
        $cleanArtist = trim(preg_replace('/\s+/', ' ', $cleanArtist));
        $cleanTitle = trim(preg_replace('/\s+/', ' ', $cleanTitle));
        $query = 'ytsearch10:' . $cleanArtist . ' - ' . $cleanTitle . ' audio';

        $earlyCoverUrl = trim((string)($meta['cover_url'] ?? ''));
        if ($earlyCoverUrl !== '' && !is_file($albumDir . '/cover.jpg')) {
            self::saveFolderCover($albumDir, $earlyCoverUrl, $log);
        }

        $expectedSec = (int)floor(((int)($meta['duration_ms'] ?? 0)) / 1000);
        $minSec = 20;
        $maxSec = 1200;
        if ($expectedSec > 0) {
            $tolerance = max(30, (int)($expectedSec * 0.2));
            $minSec = max(15, $expectedSec - $tolerance);
            $maxSec = $expectedSec + $tolerance + 30;
        }
        $titleToken = '';
        $titleWords = preg_split('/\s+/', mb_strtolower($cleanTitle)) ?: [];
        $safe = static fn(string $w): string => preg_replace('/[^\p{L}\p{N}]/u', '', $w) ?? '';
        foreach ($titleWords as $w) {
            $s = $safe($w);
            if (mb_strlen($s) >= 4) { $titleToken = $s; break; }
        }
        if ($titleToken === '') {
            foreach ($titleWords as $w) {
                $s = $safe($w);
                if (mb_strlen($s) >= 2) { $titleToken = $s; break; }
            }
        }
        $matchFilter = "duration >= {$minSec} & duration <= {$maxSec}";
        if ($titleToken !== '') {
            $matchFilter .= " & title ~= '(?i)" . preg_quote($titleToken, "'") . "'";
        }

        $concurrentFragments = max(1, min(8, (int)Env::get('YTDLP_CONCURRENT_FRAGMENTS', '4')));
        $isPassthrough = in_array($audioFormat, ['best', 'opus', 'm4a'], true);
        $embedCover = is_file($albumDir . '/cover.jpg') ? '' : ' --embed-thumbnail --convert-thumbnails jpg';

        $cmd = escapeshellarg($bin)
            . ' --no-warnings --no-playlist --newline --no-check-certificate --no-mtime'
            . ' --ignore-errors --no-abort-on-error'
            . ' --max-downloads 1'
            . ' --concurrent-fragments ' . $concurrentFragments
            . ' --retries 2 --fragment-retries 2 --socket-timeout 15'
            . ' --geo-bypass --geo-bypass-country US'
            . ' --progress-template ' . escapeshellarg('DLP|%(progress._percent_str)s|%(progress.downloaded_bytes)s|%(progress.total_bytes)s')
            . ($isPassthrough
                ? ' -f ' . escapeshellarg('bestaudio[ext=m4a]/bestaudio[ext=opus]/bestaudio/best')
                  . ' --extract-audio --audio-format ' . escapeshellarg($audioFormat)
                : ' --extract-audio --audio-format ' . escapeshellarg($audioFormat)
                  . ' --audio-quality ' . escapeshellarg($audioQualityFlag))
            . ' --embed-metadata --no-overwrites'
            . $embedCover
            . ' --match-filter ' . escapeshellarg($matchFilter)
            . ' --ffmpeg-location ' . escapeshellarg($ffmpeg)
            . ' -o ' . escapeshellarg($outTemplate)
            . ' ' . escapeshellarg($query);
        $log("yt-dlp fast-mode passthrough={$isPassthrough} frags={$concurrentFragments} embedThumb=" . ($embedCover ? '1' : '0') . " match-filter: {$matchFilter} (expected={$expectedSec}s)");

        $log("yt-dlp run: {$cmd}");
        JobTracker::update($pid, ['stage' => 'downloading audio', 'progress' => 1]);

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            $log("yt-dlp proc_open failed");
            self::cleanupOrphanedAlbumDir($albumDir, $log);
            JobTracker::complete($pid, false, 'Could not start yt-dlp process');
            return ['ok' => false, 'error' => 'proc_open failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutBuf = '';
        $stderrAll = '';
        $lastProgress = 0;
        $lastUpdate = time();
        while (true) {
            $status = proc_get_status($proc);
            $chunk = @fread($pipes[1], 4096);
            if (is_string($chunk) && $chunk !== '') {
                $stdoutBuf .= $chunk;
                while (($nl = strpos($stdoutBuf, "\n")) !== false) {
                    $line = substr($stdoutBuf, 0, $nl);
                    $stdoutBuf = substr($stdoutBuf, $nl + 1);
                    if (preg_match('/DLP\|\s*([\d.]+)%/', $line, $m)) {
                        $p = (int)floor((float)$m[1]);
                        if ($p > $lastProgress || (time() - $lastUpdate) >= 1) {
                            $lastProgress = $p;
                            $lastUpdate = time();
                            JobTracker::update($pid, ['progress' => min(95, $p), 'stage' => "downloading {$p}%"]);
                        }
                    } elseif (str_contains($line, '[ExtractAudio]') || str_contains($line, 'Destination:')) {
                        JobTracker::update($pid, ['stage' => 'converting to mp3', 'progress' => 95]);
                    }
                }
            }
            $errChunk = @fread($pipes[2], 4096);
            if (is_string($errChunk) && $errChunk !== '') $stderrAll .= $errChunk;
            if (!$status['running']) break;
            usleep(150000);
        }
        $rc = (int)$status['exitcode'];
        $stdoutTail = $stdoutBuf;
        while (true) {
            $r = @fread($pipes[1], 4096);
            if (!is_string($r) || $r === '') break;
            $stdoutTail .= $r;
        }
        while (true) {
            $r = @fread($pipes[2], 4096);
            if (!is_string($r) || $r === '') break;
            $stderrAll .= $r;
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);

        $tail = substr($stderrAll !== '' ? $stderrAll : $stdoutTail, -600);
        $log("yt-dlp exit rc={$rc} tail=" . substr(str_replace("\n", ' | ', $tail), 0, 400));

        $tmpOutFile = $tmpBase . '.' . $audioFormat;
        if ($isBestMode) {
            foreach (['opus', 'webm', 'm4a', 'mp3', 'ogg', 'flac', 'best'] as $ext) {
                $candidate = $tmpBase . '.' . $ext;
                if (is_file($candidate)) { $tmpOutFile = $candidate; break; }
            }
        }

        $rcAcceptable = ($rc === 0 || $rc === 101);
        if (!$rcAcceptable || !is_file($tmpOutFile)) {
            if (!is_file($tmpOutFile) && $isBestMode === false) {
                foreach (['opus', 'webm', 'm4a', 'mp3', 'ogg', 'flac'] as $ext) {
                    $candidate = $tmpBase . '.' . $ext;
                    if (is_file($candidate)) { $tmpOutFile = $candidate; break; }
                }
            }
            if (!is_file($tmpOutFile)) {
                self::cleanupTmpDir($tmpDir);
                self::cleanupOrphanedAlbumDir($albumDir, $log);
                $err = 'yt-dlp failed (rc=' . $rc . ')';
                if ($stderrAll !== '') $err .= ': ' . substr(trim($stderrAll), 0, 300);
                JobTracker::complete($pid, false, $err);
                return [
                    'ok' => false,
                    'error' => $err,
                ];
            }
        }

        JobTracker::update($pid, ['stage' => 'verifying file', 'progress' => 96]);
        $sanity = self::verifyAudio($tmpOutFile, $ffmpeg);
        if (!$sanity['ok']) {
            $log("verifyAudio FAIL: {$sanity['reason']} — removing corrupt file");
            self::cleanupTmpDir($tmpDir);
            self::cleanupOrphanedAlbumDir($albumDir, $log);
            JobTracker::complete($pid, false, 'Downloaded file is corrupt: ' . $sanity['reason']);
            return ['ok' => false, 'error' => 'corrupt: ' . $sanity['reason']];
        }
        $log("verifyAudio OK duration={$sanity['duration']}s");

        $tmpExt = pathinfo($tmpOutFile, PATHINFO_EXTENSION);
        $outFile = $baseName . '.' . $tmpExt;
        $outDir = dirname($outFile);
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0775, true);
        }
        if (!@rename($tmpOutFile, $outFile)) {
            if (!@copy($tmpOutFile, $outFile)) {
                $diag = [
                    'tmp_exists' => is_file($tmpOutFile) ? 'yes' : 'no',
                    'tmp_size' => is_file($tmpOutFile) ? (int)@filesize($tmpOutFile) : 0,
                    'out_dir_exists' => is_dir($outDir) ? 'yes' : 'no',
                    'out_dir_writable' => is_writable($outDir) ? 'yes' : 'no',
                    'out_file_exists' => is_file($outFile) ? 'yes' : 'no',
                    'disk_free_mb' => (int)((@disk_free_space($outDir) ?: 0) / (1024 * 1024)),
                ];
                $lastErr = error_get_last();
                $errMsg = $lastErr['message'] ?? 'n/a';
                $log("rename/copy FAIL {$tmpOutFile} -> {$outFile} diag=" . json_encode($diag) . " last_error={$errMsg}");
                self::cleanupTmpDir($tmpDir);
                self::cleanupOrphanedAlbumDir($albumDir, $log);
                JobTracker::complete($pid, false, 'Failed to move file to library: ' . $errMsg);
                return ['ok' => false, 'error' => 'Failed to move file to library: ' . $errMsg];
            }
            @unlink($tmpOutFile);
        }
        self::cleanupTmpDir($tmpDir);
        self::cleanupStaleArtifacts($albumDir, $baseName);

        $coverUrl = trim((string)($meta['cover_url'] ?? ''));
        if ($coverUrl !== '') {
            self::saveFolderCover($albumDir, $coverUrl, $log);
        }

        JobTracker::update($pid, ['stage' => 'updating library', 'progress' => 99]);
        try {
            (new Scanner())->scanFile($outFile);
            $log("scan OK after download {$title} (scanFile)");
        } catch (\Throwable $e) {
            $log("scan failed: " . $e->getMessage());
        }

        $downloaderUserId = (int)($hint['user_id'] ?? 0);
        if ($downloaderUserId > 0) {
            try {
                \Doniixify\Database::execute(
                    'UPDATE songs SET downloaded_by = ? WHERE path = ? AND downloaded_by IS NULL',
                    [$downloaderUserId, $outFile]
                );
                $log("downloaded_by set to user {$downloaderUserId} for {$outFile}");
            } catch (\Throwable $e) {
                $log("downloaded_by update failed: " . $e->getMessage());
            }
        }

        $spotifyId = self::extractTrackId($spotifyUrl) ?? (string)($hint['song_id'] ?? $hint['spotify_id'] ?? '');
        if ($spotifyId !== '' && preg_match('/^[A-Za-z0-9]{22}$/', $spotifyId)) {
            try {
                \Doniixify\Database::execute(
                    'UPDATE songs SET spotify_id = ? WHERE path = ? AND spotify_id IS NULL',
                    [$spotifyId, $outFile]
                );
                $log("spotify_id={$spotifyId} saved for {$outFile}");
            } catch (\Throwable $e) {
                $log("spotify_id update failed: " . $e->getMessage());
            }
        }

        $playlistId = (int)($hint['playlist_id'] ?? 0);
        if ($playlistId > 0) {
            try {
                $song = \Doniixify\Database::fetchOne('SELECT id FROM songs WHERE path = ? LIMIT 1', [$outFile]);
                if ($song && !empty($song['id'])) {
                    $songId = (int)$song['id'];
                    $exists = \Doniixify\Database::fetchOne(
                        'SELECT 1 FROM playlist_songs WHERE playlist_id = ? AND song_id = ?',
                        [$playlistId, $songId]
                    );
                    if ($exists === null) {
                        $maxRow = \Doniixify\Database::fetchOne('SELECT COALESCE(MAX(position), 0) AS p FROM playlist_songs WHERE playlist_id = ?', [$playlistId]);
                        $pos = (int)($maxRow['p'] ?? 0) + 1;
                        \Doniixify\Database::execute(
                            'INSERT INTO playlist_songs (playlist_id, song_id, position) VALUES (?, ?, ?)',
                            [$playlistId, $songId, $pos]
                        );
                        \Doniixify\Database::execute(
                            'UPDATE playlists SET song_count = (SELECT COUNT(*) FROM playlist_songs WHERE playlist_id = ?),
                             duration = (SELECT COALESCE(SUM(s.duration),0) FROM playlist_songs ps JOIN songs s ON s.id = ps.song_id WHERE ps.playlist_id = ?),
                             changed_at = NOW() WHERE id = ?',
                            [$playlistId, $playlistId, $playlistId]
                        );
                        $log("auto-linked song={$songId} to playlist={$playlistId}");
                    }
                }
            } catch (\Throwable $e) {
                $log("playlist auto-link failed: " . $e->getMessage());
            }
        }

        $releaseYear = (int)($meta['release_year'] ?? 0);
        $releaseDate = (string)($meta['release_date'] ?? '');
        if ($releaseYear > 0 || $releaseDate !== '') {
            try {
                \Doniixify\Database::execute(
                    'UPDATE albums al
                     JOIN artists ar ON ar.id = al.artist_id
                     SET al.year = COALESCE(NULLIF(al.year, 0), ?),
                         al.release_date = COALESCE(NULLIF(al.release_date, \'\'), ?)
                     WHERE ar.name = ? AND al.name = ?',
                    [$releaseYear > 0 ? $releaseYear : null, $releaseDate !== '' ? $releaseDate : null, $artist, $album]
                );
                $log("album release updated year={$releaseYear} date={$releaseDate} for {$artist} / {$album}");
            } catch (\Throwable $e) {
                $log("album release update failed: " . $e->getMessage());
            }
        }

        JobTracker::complete($pid, true);
        return [
            'ok' => true,
            'path' => $outFile,
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
        ];
    }

    public static function queueAsync(string $spotifyUrl, ?array $hint = null): void
    {
        register_shutdown_function(function () use ($spotifyUrl, $hint) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                self::download($spotifyUrl, $hint);
            } catch (\Throwable $e) {
                $log = self::logger();
                $log("queueAsync error: " . $e->getMessage());
            }
        });
    }

    private const MAX_PARALLEL_DOWNLOADS = 8;

    private static function activeDownloadCount(): int
    {
        $count = 0;
        foreach (JobTracker::all(300) as $j) {
            $s = (string)($j['status'] ?? '');
            if ($s === 'starting' || $s === 'downloading') {
                $count++;
            }
        }
        return $count;
    }

    private static function enqueuePending(string $spotifyUrl, ?array $hint): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/queue';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/pending-downloads.jsonl';
        $line = json_encode([
            'url' => $spotifyUrl,
            'hint' => $hint,
            'queued_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }

    public static function processPendingQueue(): int
    {
        $dir = dirname(__DIR__, 2) . '/storage/queue';
        $file = $dir . '/pending-downloads.jsonl';
        if (!is_file($file)) return 0;

        $lockFile = $dir . '/pending.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if (!$lockHandle || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if ($lockHandle) @fclose($lockHandle);
            return 0;
        }

        $processed = 0;
        try {
            $body = @file_get_contents($file);
            if (!is_string($body) || $body === '') return 0;
            $lines = array_values(array_filter(explode("\n", $body), fn($l) => trim($l) !== ''));
            $remaining = [];

            foreach ($lines as $line) {
                if (self::activeDownloadCount() >= self::MAX_PARALLEL_DOWNLOADS) {
                    $remaining[] = $line;
                    continue;
                }
                $row = json_decode($line, true);
                if (!is_array($row) || empty($row['url'])) continue;
                $ok = self::spawnWorker((string)$row['url'], $row['hint'] ?? null);
                if ($ok) {
                    $processed++;
                } else {
                    $remaining[] = $line;
                }
            }

            if (empty($remaining)) {
                @unlink($file);
            } else {
                @file_put_contents($file, implode("\n", $remaining) . "\n", LOCK_EX);
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
        return $processed;
    }

    public static function queueBackground(string $spotifyUrl, ?array $hint = null): bool
    {
        $log = self::logger();
        $log("queueBackground call url={$spotifyUrl}");

        if (self::activeDownloadCount() >= self::MAX_PARALLEL_DOWNLOADS) {
            self::enqueuePending($spotifyUrl, $hint);
            $log("queueBackground: max parallel reached — queued to pending-downloads.jsonl");
            return true;
        }

        $ok = self::spawnWorker($spotifyUrl, $hint);
        if (!$ok) {
            $log("queueBackground: spawn failed — writing to pending queue as fallback");
            self::enqueuePending($spotifyUrl, $hint);
            return true;
        }
        return $ok;
    }

    private static function spawnWorker(string $spotifyUrl, ?array $hint): bool
    {
        $log = self::logger();
        $worker = realpath(__DIR__ . '/../../bin/download-worker.php');
        if ($worker === false || !is_file($worker)) {
            $log("spawnWorker: worker not found at " . __DIR__ . '/../../bin/download-worker.php — falling back to queueAsync');
            self::queueAsync($spotifyUrl, $hint);
            return false;
        }
        $php = self::phpBinary();
        $log("spawnWorker: worker={$worker} php={$php}");
        $hintJson = json_encode($hint ?? new \stdClass(), JSON_UNESCAPED_UNICODE);

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'start /B "" ' . escapeshellarg($php) . ' '
                . escapeshellarg($worker) . ' '
                . escapeshellarg($spotifyUrl) . ' '
                . escapeshellarg((string)$hintJson) . ' > NUL 2>&1';
            @pclose(@popen($cmd, 'r'));
            $log("queueBackground (Windows) spawned: {$cmd}");
            return true;
        }

        $hasSetsid = false;
        $whichSetsid = @shell_exec('command -v setsid 2>/dev/null');
        if (is_string($whichSetsid) && trim($whichSetsid) !== '') $hasSetsid = true;

        $prefix = $hasSetsid ? 'setsid -f ' : 'nohup ';
        $cmd = $prefix . escapeshellarg($php) . ' '
            . escapeshellarg($worker) . ' '
            . escapeshellarg($spotifyUrl) . ' '
            . escapeshellarg((string)$hintJson)
            . ' </dev/null >/dev/null 2>&1 &';

        $disabled = ini_get('disable_functions') ?: '';
        $execAllowed = !str_contains($disabled, 'exec');
        $popenAllowed = !str_contains($disabled, 'popen');
        $procOpenAllowed = !str_contains($disabled, 'proc_open');

        $spawned = false;

        if ($procOpenAllowed) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ];
            $proc = @proc_open(['/bin/sh', '-c', $cmd], $descriptors, $pipes);
            if (is_resource($proc)) {
                @proc_close($proc);
                $spawned = true;
                $log("queueBackground spawned via proc_open: {$cmd}");
            }
        }

        if (!$spawned && $popenAllowed) {
            $fp = @popen($cmd, 'r');
            if ($fp !== false) {
                @pclose($fp);
                $spawned = true;
                $log("queueBackground spawned via popen: {$cmd}");
            }
        }

        if (!$spawned && $execAllowed) {
            @exec($cmd);
            $spawned = true;
            $log("queueBackground spawned via exec: {$cmd}");
        }

        if (!$spawned) {
            $log("spawnWorker FAIL: exec/popen/proc_open all disabled");
            return false;
        }

        return true;
    }

    public static function phpBinary(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $configured = (string)Env::get('PHP_BIN', '');
        if ($configured !== '' && is_executable($configured) && !str_contains(basename($configured), 'fpm')) {
            return $cached = $configured;
        }
        $candidates = [
            '/usr/bin/php8.5',
            '/usr/bin/php8.4',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/etc/php/8.5/cli/bin/php',
            '/usr/bin/php',
            '/usr/local/bin/php',
            PHP_BINARY,
        ];
        foreach ($candidates as $c) {
            if (!$c || !is_file($c) || !is_executable($c)) continue;
            if (str_contains(basename((string)$c), 'fpm')) continue;
            // Weryfikuj że to faktycznie CLI binary
            $check = @shell_exec(escapeshellarg($c) . ' -r "echo PHP_SAPI;" 2>/dev/null');
            if (is_string($check) && trim($check) === 'cli') {
                return $cached = $c;
            }
        }
        $which = @shell_exec('command -v php 2>/dev/null');
        if (is_string($which)) {
            $found = trim($which);
            if ($found !== '' && is_executable($found) && !str_contains(basename($found), 'fpm')) {
                return $cached = $found;
            }
        }
        return 'php';
    }

    public static function extractTrackId(string $url): ?string
    {
        if (preg_match('#spotify\.com/track/([A-Za-z0-9]+)#i', $url, $m)) return $m[1];
        if (preg_match('/^[A-Za-z0-9]{22}$/', $url)) return $url;
        return null;
    }

    public static function ytDlpBinary(): string
    {
        $configured = (string)Env::get('YT_DLP_BIN', '');
        if ($configured !== '' && is_executable($configured)) return $configured;
        foreach (['/usr/local/bin/yt-dlp', '/usr/bin/yt-dlp', '/opt/yt-dlp/yt-dlp'] as $c) {
            if (is_executable($c)) return $c;
        }
        $which = @shell_exec('command -v yt-dlp 2>/dev/null');
        if (is_string($which)) {
            $found = trim($which);
            if ($found !== '' && is_executable($found)) return $found;
        }
        return 'yt-dlp';
    }

    private static function resolveMetadata(string $spotifyUrl, ?array $hint): ?array
    {
        $title = trim((string)($hint['title'] ?? ''));
        $artist = trim((string)($hint['artist'] ?? ''));
        $album = trim((string)($hint['album'] ?? ''));
        $durationMs = (int)($hint['duration_ms'] ?? 0);
        $coverUrl = trim((string)($hint['cover_url'] ?? ''));
        $releaseYear = (int)($hint['release_year'] ?? 0);
        $releaseDate = trim((string)($hint['release_date'] ?? ''));

        $trackId = self::extractTrackId($spotifyUrl);
        if ($trackId !== null) {
            $track = SpotifyApi::getTrack($trackId);
            if ($track !== null) {
                if ($title === '') $title = (string)($track['name'] ?? '');
                if ($artist === '') {
                    $artists = $track['artists'] ?? [];
                    if (is_array($artists) && !empty($artists[0]['name'])) {
                        $artist = (string)$artists[0]['name'];
                    }
                }
                if ($album === '') $album = (string)($track['album']['name'] ?? '');
                if ($durationMs <= 0) $durationMs = (int)($track['duration_ms'] ?? 0);
                if ($coverUrl === '' && !empty($track['album']['images'][0]['url'])) {
                    $coverUrl = (string)$track['album']['images'][0]['url'];
                }
                if (!empty($track['album']['release_date'])) {
                    $td = (string)$track['album']['release_date'];
                    if ($releaseDate === '') $releaseDate = substr($td, 0, 10);
                    if ($releaseYear <= 0) $releaseYear = (int)substr($td, 0, 4);
                }
            }
        }

        if ($title === '' && $artist === '') return null;
        if ($title === '') $title = 'Unknown Title';
        if ($artist === '') $artist = 'Unknown Artist';
        if ($album === '') $album = 'Singles';

        return [
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'duration_ms' => $durationMs,
            'cover_url' => $coverUrl,
            'release_year' => $releaseYear > 1900 && $releaseYear < 2100 ? $releaseYear : 0,
            'release_date' => $releaseDate !== '' ? substr($releaseDate, 0, 10) : '',
        ];
    }

    private static function saveFolderCover(string $albumDir, string $coverUrl, callable $log): void
    {
        if ($coverUrl === '') return;
        $target = $albumDir . '/cover.jpg';
        if (is_file($target) && filesize($target) > 4096) return;

        $data = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($coverUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status >= 200 && $status < 300 && is_string($body) && strlen($body) > 1024) {
                $data = $body;
            }
        } else {
            $body = @file_get_contents($coverUrl);
            if (is_string($body) && strlen($body) > 1024) $data = $body;
        }

        if ($data === null) {
            $log("cover fetch FAIL url={$coverUrl}");
            return;
        }
        if (@file_put_contents($target, $data) !== false) {
            $log("cover saved: {$target} (" . strlen($data) . " bytes)");
        }
    }

    private static function verifyAudio(string $path, string $ffmpeg): array
    {
        if (!is_file($path)) return ['ok' => false, 'reason' => 'file missing', 'duration' => 0];
        $size = filesize($path);
        if ($size === false || $size < 50_000) {
            return ['ok' => false, 'reason' => 'too small (' . (int)$size . ' bytes)', 'duration' => 0];
        }
        $ffprobe = preg_replace('#ffmpeg([^/\\\\]*)$#', 'ffprobe$1', $ffmpeg) ?: '';
        if ($ffprobe === '' || !@is_executable($ffprobe)) {
            $which = @shell_exec('command -v ffprobe 2>/dev/null');
            if (is_string($which)) {
                $found = trim($which);
                if ($found !== '' && @is_executable($found)) $ffprobe = $found;
            }
        }
        if ($ffprobe === '' || !@is_executable($ffprobe)) {
            return ['ok' => true, 'reason' => 'ffprobe missing — skipped', 'duration' => 0];
        }
        $cmd = escapeshellarg($ffprobe)
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($path) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (!is_string($out)) return ['ok' => false, 'reason' => 'ffprobe failed', 'duration' => 0];
        $sec = (float)trim($out);
        if ($sec <= 5) return ['ok' => false, 'reason' => 'duration too short (' . $sec . 's)', 'duration' => (int)$sec];
        return ['ok' => true, 'reason' => 'ok', 'duration' => (int)round($sec)];
    }

    private static function sanitize(string $s): string
    {
        $s = trim($s);
        $s = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]#u', '_', $s) ?? $s;
        $s = preg_replace('#[\[\]\(\)\{\}%$`]#u', '', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = trim($s, " ._");
        if ($s === '') return 'Unknown';
        return mb_substr($s, 0, 180);
    }

    private static function cleanupTmpDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) || is_link($f)) @unlink($f);
        }
        @rmdir($dir);
    }

    private static function cleanupOrphanedAlbumDir(string $albumDir, callable $log): void
    {
        if (!is_dir($albumDir)) return;
        $audioExt = ['m4a', 'mp3', 'opus', 'ogg', 'flac', 'webm', 'wav', 'aac'];
        $hasAudio = false;
        foreach (glob($albumDir . '/*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $audioExt, true)) { $hasAudio = true; break; }
        }
        if ($hasAudio) return;

        foreach (glob($albumDir . '/*') ?: [] as $f) {
            if (is_file($f) || is_link($f)) @unlink($f);
        }
        @rmdir($albumDir);
        $log("cleanup: removed orphaned album dir {$albumDir}");

        $artistDir = dirname($albumDir);
        $musicRoot = rtrim((string)Env::get('MUSIC_PATH', '/music'), '/');
        if ($artistDir !== $musicRoot && is_dir($artistDir)) {
            $left = glob($artistDir . '/*') ?: [];
            if (empty($left)) {
                @rmdir($artistDir);
                $log("cleanup: removed empty artist dir {$artistDir}");
            }
        }
    }

    private static function cleanupStaleArtifacts(string $albumDir, string $baseName): void
    {
        if (!is_dir($albumDir)) return;
        foreach (['part', 'ytdl', 'temp', 'tmp'] as $ext) {
            $f = $baseName . '.' . $ext;
            if (is_file($f)) @unlink($f);
        }
        foreach (glob($baseName . '.*.part') ?: [] as $f) @unlink($f);
        foreach (glob($albumDir . '/*.part') ?: [] as $f) @unlink($f);
        foreach (glob($albumDir . '/*.ytdl') ?: [] as $f) @unlink($f);
        foreach (glob($albumDir . '/*.temp') ?: [] as $f) @unlink($f);
    }

    private static function logger(): callable
    {
        $dir = __DIR__ . '/../../storage';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $file = $dir . '/download.log';
        return function (string $msg) use ($file): void {
            @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        };
    }
}
