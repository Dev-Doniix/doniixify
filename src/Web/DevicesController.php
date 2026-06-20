<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class DevicesController
{
    public static function diagnostics(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $cols = Database::fetchAll('SHOW COLUMNS FROM active_devices');
        $hasPlayingSince = false;
        foreach ($cols as $c) if (($c['Field'] ?? '') === 'playing_since') $hasPlayingSince = true;
        $devices = Database::fetchAll(
            'SELECT device_id, name, is_playing, playing_since, last_seen,
                    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen,
                    TIMESTAMPDIFF(SECOND, playing_since, NOW()) AS seconds_since_play
             FROM active_devices WHERE user_id = ? ORDER BY last_seen DESC',
            [$user['id']]
        );
        echo json_encode([
            'migration_004_applied' => $hasPlayingSince,
            'columns' => array_column($cols, 'Field'),
            'devices' => $devices,
            'server_time' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT);
    }

    public static function logSnapshot(): void
    {
        Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $dir = __DIR__ . '/../../storage';
        $files = ['devices.log', 'autodl.log', 'import.log'];
        $out = [];
        foreach ($files as $f) {
            $path = $dir . '/' . $f;
            if (!is_file($path)) { $out[$f] = '(empty)'; continue; }
            $content = @file_get_contents($path);
            if ($content === false) { $out[$f] = '(unreadable)'; continue; }
            $lines = explode("\n", $content);
            $tail = array_slice($lines, -50);
            $out[$f] = implode("\n", $tail);
        }
        $out['active_devices'] = Database::fetchAll(
            'SELECT device_id, name, is_playing, playing_since, last_seen,
                    TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen
             FROM active_devices ORDER BY last_seen DESC'
        );

        $srcRoot = realpath(__DIR__ . '/../');
        $check = [
            'SmartQueueController.php' => [$srcRoot . '/Web/SmartQueueController.php', 'autoDownloadSimilar', 'fetchTracks'],
            'DevicesController.php' => [$srcRoot . '/Web/DevicesController.php', 'logSnapshot', 'transfer'],
            'Library.php' => [$srcRoot . '/Subsonic/Library.php', 'isKilled', 'devLog', 'streamLoop'],
            'PlaylistController.php' => [$srcRoot . '/Web/PlaylistController.php', 'findTrackArray', 'scanForTracks'],
            'Migrator.php' => [$srcRoot . '/Migrator.php', 'public static function run', 'public static function status'],
        ];
        $version = [];
        foreach ($check as $label => $arr) {
            $path = array_shift($arr);
            if (!is_file($path)) { $version[$label] = 'MISSING FILE'; continue; }
            $body = (string)@file_get_contents($path);
            $missing = [];
            foreach ($arr as $needle) if (!str_contains($body, $needle)) $missing[] = $needle;
            $version[$label] = [
                'mtime' => date('Y-m-d H:i:s', (int)filemtime($path)),
                'size' => filesize($path),
                'sha1' => sha1($body),
                'has_new_code' => empty($missing),
                'missing_markers' => $missing,
            ];
        }
        $out['__version__'] = $version;

        echo json_encode($out, JSON_PRETTY_PRINT);
    }

    public static function heartbeat(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $deviceId = (string)($input['device_id'] ?? '');
        if ($deviceId === '' || strlen($deviceId) > 64) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid device_id']);
            return;
        }
        $name = trim((string)($input['name'] ?? '')) ?: 'Browser';
        if (strlen($name) > 255) $name = substr($name, 0, 255);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $songId = isset($input['song_id']) && $input['song_id'] !== '' ? (int)$input['song_id'] : null;
        $playing = !empty($input['playing']) ? 1 : 0;
        $position = isset($input['position']) ? max(0.0, (float)$input['position']) : 0.0;
        $force = !empty($input['force']) ? 1 : 0;
        $playedDelta = isset($input['played_delta']) ? max(0, min(60, (int)$input['played_delta'])) : 0;
        if ($playedDelta > 0) {
            try {
                Database::execute(
                    'UPDATE users SET listening_seconds = listening_seconds + ? WHERE id = ?',
                    [$playedDelta, (int)$user['id']]
                );
            } catch (\Throwable $e) {}
        }

        if ($playing && $force) {
            try {
                Database::execute(
                    'DELETE FROM device_kills WHERE user_id = ? AND device_id = ?',
                    [$user['id'], $deviceId]
                );
            } catch (\Throwable $e) {}
        }

        $preKill = null;
        try {
            $preKill = Database::fetchOne(
                "SELECT killed_until, reason FROM device_kills
                 WHERE user_id = ? AND device_id = ? AND killed_until > NOW()",
                [$user['id'], $deviceId]
            );
        } catch (\Throwable $e) {}
        if ($preKill !== null && $playing) {
            $playing = 0;
        }

        $current = Database::fetchOne(
            'SELECT is_playing, playing_since FROM active_devices WHERE user_id = ? AND device_id = ?',
            [$user['id'], $deviceId]
        );
        $playingSinceClause = ', playing_since = ';
        if ($playing && (!$current || !(int)$current['is_playing'])) {
            $playingSinceClause .= 'NOW()';
        } elseif (!$playing) {
            $playingSinceClause .= 'NULL';
        } else {
            $playingSinceClause .= 'playing_since';
        }

        Database::execute(
            "INSERT INTO active_devices (user_id, device_id, name, user_agent, current_song_id, current_position, position_updated_at, is_playing, playing_since, last_seen)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, " . ($playing ? 'NOW()' : 'NULL') . ", NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), user_agent = VALUES(user_agent),
                current_song_id = VALUES(current_song_id),
                current_position = VALUES(current_position),
                position_updated_at = NOW(),
                is_playing = VALUES(is_playing){$playingSinceClause}, last_seen = NOW()",
            [$user['id'], $deviceId, $name, $ua, $songId, $position, $playing]
        );

        Database::execute(
            'DELETE FROM active_devices WHERE user_id = ? AND last_seen < (NOW() - INTERVAL 30 SECOND)',
            [$user['id']]
        );

        if (!empty($input['disconnect'])) {
            try {
                Database::execute(
                    'DELETE FROM active_devices WHERE user_id = ? AND device_id = ?',
                    [$user['id'], $deviceId]
                );
                echo json_encode(['ok' => true, 'disconnected' => true]);
                return;
            } catch (\Throwable $e) {}
        }


        $newer = null;
        $killedBy = $preKill !== null ? (string)($preKill['reason'] ?? 'another device') : null;

        if ($playing && $killedBy === null) {
            $newer = Database::fetchOne(
                'SELECT device_id, name, playing_since
                 FROM active_devices
                 WHERE user_id = ? AND device_id != ? AND is_playing = 1 AND playing_since IS NOT NULL
                       AND last_seen > (NOW() - INTERVAL 8 MINUTE)
                       AND playing_since > COALESCE((SELECT playing_since FROM (SELECT playing_since FROM active_devices WHERE user_id = ? AND device_id = ?) t), 0)
                 ORDER BY playing_since DESC LIMIT 1',
                [$user['id'], $deviceId, $user['id'], $deviceId]
            );
        }

        $shouldPause = $newer !== null || $killedBy !== null;

        $otherPlaying = null;
        if (!$playing) {
            $row = Database::fetchOne(
                "SELECT ad.device_id, ad.name AS device_name, ad.current_song_id,
                        ad.current_position, ad.is_playing,
                        TIMESTAMPDIFF(SECOND, ad.position_updated_at, NOW()) AS pos_age_s,
                        s.title AS song_title, s.duration AS song_duration,
                        ar.name AS song_artist
                 FROM active_devices ad
                 LEFT JOIN songs s ON s.id = ad.current_song_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 WHERE ad.user_id = ? AND ad.device_id != ?
                       AND ad.is_playing = 1 AND ad.playing_since IS NOT NULL
                       AND ad.last_seen > (NOW() - INTERVAL 8 MINUTE)
                 ORDER BY ad.playing_since DESC LIMIT 1",
                [$user['id'], $deviceId]
            );
            if ($row !== null) {
                $otherPos = (float)($row['current_position'] ?? 0);
                if (!empty($row['is_playing']) && $row['pos_age_s'] !== null) {
                    $otherPos += (float)$row['pos_age_s'];
                }
                $otherPlaying = [
                    'device_id' => $row['device_id'],
                    'device_name' => $row['device_name'],
                    'song_id' => $row['current_song_id'] ? (int)$row['current_song_id'] : null,
                    'song_title' => $row['song_title'],
                    'song_artist' => $row['song_artist'],
                    'position' => round($otherPos, 1),
                    'duration' => (int)($row['song_duration'] ?? 0),
                    'is_playing' => (bool)($row['is_playing'] ?? false),
                ];
            }
        }

        $pendingCommands = [];
        try {
            $rows = Database::fetchAll(
                'SELECT id, command FROM playback_commands
                 WHERE user_id = ? AND consumed_at IS NULL
                 ORDER BY id ASC LIMIT 10',
                [(int)$user['id']]
            );
            if (!empty($rows)) {
                $ids = array_map(fn($r) => (int)$r['id'], $rows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                Database::execute(
                    "UPDATE playback_commands SET consumed_at = NOW() WHERE id IN ($placeholders)",
                    $ids
                );
                $pendingCommands = array_map(fn($r) => $r['command'], $rows);
            }
            Database::execute(
                'DELETE FROM playback_commands WHERE consumed_at IS NOT NULL AND consumed_at < (NOW() - INTERVAL 1 HOUR)',
                []
            );
        } catch (\Throwable $e) {}

        echo json_encode([
            'ok' => true,
            'should_pause' => $shouldPause,
            'taken_over_by' => $shouldPause ? ($newer['name'] ?? $killedBy) : null,
            'other_playing' => $otherPlaying,
            'pending_commands' => $pendingCommands,
        ]);
    }

    public static function listDevices(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $rows = Database::fetchAll(
            "SELECT d.device_id, d.name, d.user_agent, d.is_playing, d.last_seen, d.current_song_id,
                    s.title AS song_title, ar.name AS song_artist,
                    TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seconds_ago
             FROM active_devices d
             LEFT JOIN songs s ON s.id = d.current_song_id
             LEFT JOIN artists ar ON ar.id = s.artist_id
             WHERE d.user_id = ? AND d.last_seen > (NOW() - INTERVAL 30 SECOND)
             ORDER BY d.is_playing DESC, d.device_id ASC",
            [$user['id']]
        );

        $devices = array_map(fn($r) => [
            'device_id' => $r['device_id'],
            'name' => $r['name'],
            'kind' => self::kindFromUa($r['user_agent'] ?? ''),
            'playing' => (bool)$r['is_playing'],
            'song' => $r['song_title'] ? ['title' => $r['song_title'], 'artist' => $r['song_artist']] : null,
            'active' => (int)$r['seconds_ago'] < 30,
        ], $rows);

        echo json_encode($devices);
    }

    public static function transfer(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $fromId = (string)($input['from_device_id'] ?? '');
        $toId = (string)($input['to_device_id'] ?? '');
        self::log("transfer call uid={$user['id']} from={$fromId} to={$toId}");
        if ($fromId === '' || $toId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing device ids']);
            return;
        }
        $src = Database::fetchOne(
            'SELECT d.current_song_id, d.current_position, d.is_playing,
                    TIMESTAMPDIFF(SECOND, d.position_updated_at, NOW()) AS pos_age_s,
                    s.title, ar.name AS artist
             FROM active_devices d
             LEFT JOIN songs s ON s.id = d.current_song_id
             LEFT JOIN artists ar ON ar.id = s.artist_id
             WHERE d.user_id = ? AND d.device_id = ?',
            [$user['id'], $fromId]
        );
        if ($src === null || empty($src['current_song_id'])) {
            self::log("transfer: source has no current song (src=" . json_encode($src) . ")");
            echo json_encode(['song_id' => null]);
            return;
        }
        $position = (float)($src['current_position'] ?? 0);
        if (!empty($src['is_playing']) && $src['pos_age_s'] !== null) {
            $position += (float)$src['pos_age_s'];
        }
        Database::execute(
            'UPDATE active_devices SET is_playing = 0, playing_since = NULL WHERE user_id = ? AND device_id = ?',
            [$user['id'], $fromId]
        );
        Database::execute(
            'INSERT INTO active_devices (user_id, device_id, name, user_agent, current_song_id, is_playing, playing_since, last_seen)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE current_song_id = VALUES(current_song_id), is_playing = 1, playing_since = NOW(), last_seen = NOW()',
            [
                $user['id'], $toId,
                'Browser', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
                (int)$src['current_song_id'],
            ]
        );
        try {
            Database::execute(
                'INSERT INTO device_kills (user_id, device_id, killed_until, reason)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)
                 ON DUPLICATE KEY UPDATE killed_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE), reason = VALUES(reason)',
                [$user['id'], $fromId, 'transfer to ' . $toId]
            );
        } catch (\Throwable $e) {
            self::log("kill insert failed: " . $e->getMessage());
        }
        self::log("transfer OK uid={$user['id']} song={$src['current_song_id']} pos={$position}s from={$fromId} to={$toId} (kill 5min)");
        echo json_encode([
            'song_id' => (int)$src['current_song_id'],
            'title' => $src['title'] ?? '',
            'artist' => $src['artist'] ?? '',
            'position' => round($position, 1),
        ]);
    }

    public static function control(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string)($input['action'] ?? '');
        $fromDeviceId = (string)($input['device_id'] ?? '');

        if ($action === 'pause') {
            Database::execute(
                'UPDATE active_devices SET is_playing = 0, playing_since = NULL
                 WHERE user_id = ? AND is_playing = 1 AND device_id != ?',
                [$user['id'], $fromDeviceId]
            );
            try {
                Database::execute(
                    "INSERT INTO device_kills (user_id, device_id, killed_until, reason)
                     SELECT user_id, device_id, DATE_ADD(NOW(), INTERVAL 30 SECOND), 'remote pause'
                     FROM active_devices
                     WHERE user_id = ? AND device_id != ? AND last_seen > (NOW() - INTERVAL 5 MINUTE)
                     ON DUPLICATE KEY UPDATE killed_until = DATE_ADD(NOW(), INTERVAL 30 SECOND), reason = 'remote pause'",
                    [$user['id'], $fromDeviceId]
                );
            } catch (\Throwable $e) {}
            self::log("control pause uid={$user['id']} from={$fromDeviceId}");
            echo json_encode(['ok' => true, 'action' => 'pause']);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }

    private static function log(string $msg): void
    {
        try {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/devices.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    public static function remove(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $deviceId = (string)($input['device_id'] ?? '');
        if ($deviceId === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing device_id']);
            return;
        }
        Database::execute('DELETE FROM active_devices WHERE user_id = ? AND device_id = ?', [$user['id'], $deviceId]);
        echo json_encode(['ok' => true]);
    }

    private static function kindFromUa(string $ua): string
    {
        $ua = strtolower($ua);
        if (str_contains($ua, 'android')) return 'phone';
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipod')) return 'phone';
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) return 'tablet';
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'windows') || str_contains($ua, 'linux')) return 'desktop';
        return 'desktop';
    }
}
