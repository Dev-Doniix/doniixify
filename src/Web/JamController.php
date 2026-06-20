<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class JamController
{
    public static function create(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $code = self::generateCode();
        for ($i = 0; $i < 5; $i++) {
            try {
                Database::execute(
                    'INSERT INTO jam_sessions (code, host_user_id, paused) VALUES (?, ?, 1)',
                    [$code, $user['id']]
                );
                break;
            } catch (\PDOException $e) {
                $code = self::generateCode();
            }
        }
        $jam = Database::fetchOne('SELECT id, code FROM jam_sessions WHERE code = ?', [$code]);
        if ($jam === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not create session']);
            return;
        }
        Database::execute(
            'INSERT IGNORE INTO jam_participants (jam_id, user_id) VALUES (?, ?)',
            [$jam['id'], $user['id']]
        );
        echo json_encode(['code' => $code, 'host' => true]);
    }

    public static function join(): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $code = strtoupper(trim((string)($input['code'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{4,8}$/', $code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid code']);
            return;
        }
        $jam = Database::fetchOne('SELECT id, host_user_id FROM jam_sessions WHERE code = ?', [$code]);
        if ($jam === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Session does not exist']);
            return;
        }
        Database::execute(
            'INSERT INTO jam_participants (jam_id, user_id, last_seen) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW()',
            [$jam['id'], $user['id']]
        );
        echo json_encode([
            'code' => $code,
            'host' => (int)$jam['host_user_id'] === (int)$user['id'],
        ]);
    }

    public static function state(string $code): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $code = strtoupper($code);
        $jam = Database::fetchOne(
            'SELECT j.id, j.code, j.host_user_id, j.song_id, j.position, j.paused, j.updated_at,
                    s.title AS song_title, ar.name AS song_artist, s.duration AS song_duration, u.username AS host_name
             FROM jam_sessions j
             LEFT JOIN songs s ON s.id = j.song_id
             LEFT JOIN artists ar ON ar.id = s.artist_id
             JOIN users u ON u.id = j.host_user_id
             WHERE j.code = ?',
            [$code]
        );
        if ($jam === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Session does not exist']);
            return;
        }
        Database::execute(
            'INSERT INTO jam_participants (jam_id, user_id, last_seen) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW()',
            [$jam['id'], $user['id']]
        );
        $participants = Database::fetchAll(
            'SELECT u.id, u.username, p.last_seen,
                    TIMESTAMPDIFF(SECOND, p.last_seen, NOW()) AS seconds_ago
             FROM jam_participants p
             JOIN users u ON u.id = p.user_id
             WHERE p.jam_id = ? AND p.last_seen > (NOW() - INTERVAL 1 MINUTE)
             ORDER BY p.joined_at',
            [$jam['id']]
        );
        $serverNow = Database::fetchOne('SELECT UNIX_TIMESTAMP(NOW()) AS now, UNIX_TIMESTAMP(?) AS updated', [$jam['updated_at']]);
        $drift = max(0, ((int)$serverNow['now']) - ((int)$serverNow['updated']));
        $position = (float)$jam['position'];
        if (!$jam['paused'] && $jam['song_id']) {
            $position = min($position + $drift, (float)($jam['song_duration'] ?? 0));
        }
        echo json_encode([
            'code' => $jam['code'],
            'host_id' => (int)$jam['host_user_id'],
            'host_name' => $jam['host_name'],
            'is_host' => (int)$jam['host_user_id'] === (int)$user['id'],
            'song_id' => $jam['song_id'] ? (int)$jam['song_id'] : null,
            'song_title' => $jam['song_title'],
            'song_artist' => $jam['song_artist'],
            'position' => $position,
            'paused' => (bool)$jam['paused'],
            'updated_at' => $jam['updated_at'],
            'participants' => array_map(fn($p) => [
                'id' => (int)$p['id'],
                'username' => $p['username'],
                'online' => (int)$p['seconds_ago'] < 10,
            ], $participants),
        ]);
    }

    public static function sync(string $code): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $code = strtoupper($code);
        $jam = Database::fetchOne('SELECT id, host_user_id FROM jam_sessions WHERE code = ?', [$code]);
        if ($jam === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Session does not exist']);
            return;
        }
        if ((int)$jam['host_user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Only the host can control playback']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $songId = isset($input['song_id']) && $input['song_id'] !== '' ? (int)$input['song_id'] : null;
        $position = max(0, (float)($input['position'] ?? 0));
        $paused = !empty($input['paused']) ? 1 : 0;

        Database::execute(
            'UPDATE jam_sessions SET song_id = ?, position = ?, paused = ?, updated_at = NOW() WHERE id = ?',
            [$songId, $position, $paused, $jam['id']]
        );
        echo json_encode(['ok' => true]);
    }

    public static function leave(string $code): void
    {
        $user = Session::requireLoginJson();
        header('Content-Type: application/json; charset=UTF-8');

        $code = strtoupper($code);
        $jam = Database::fetchOne('SELECT id, host_user_id FROM jam_sessions WHERE code = ?', [$code]);
        if ($jam !== null) {
            Database::execute('DELETE FROM jam_participants WHERE jam_id = ? AND user_id = ?', [$jam['id'], $user['id']]);
            if ((int)$jam['host_user_id'] === (int)$user['id']) {
                Database::execute('DELETE FROM jam_sessions WHERE id = ?', [$jam['id']]);
            }
        }
        echo json_encode(['ok' => true]);
    }

    private static function generateCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }
}
