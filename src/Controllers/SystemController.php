<?php

declare(strict_types=1);

namespace Doniixify\Controllers;

use Doniixify\Env;
use Doniixify\Router;
use Doniixify\Subsonic\Response;

final class SystemController
{
    public static function ping(): void
    {
        $params = Router::params();
        $client = trim((string)($params['c'] ?? '')) ?: '?';
        $username = (string)($params['u'] ?? '?');
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '?');
        try {
            $user = Router::requireAuth();
            self::devLog("ping OK client={$client} user={$username} ip={$ip} ua={$ua}");
            self::touchDevice((int)$user['id']);
        } catch (\Throwable $e) {
            self::devLog("ping FAIL client={$client} user={$username} ip={$ip} reason=" . $e->getMessage());
            throw $e;
        }
        Response::send(
            Response::ok(),
            $params['f'] ?? 'xml',
            $params['callback'] ?? null
        );
    }

    private static function devLog(string $msg): void
    {
        try {
            $dir = __DIR__ . '/../../storage';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            @file_put_contents($dir . '/devices.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {}
    }

    private static function touchDevice(int $uid): void
    {
        $p = Router::params();
        $client = trim((string)($p['c'] ?? '')) ?: 'Subsonic client';
        if (mb_strlen($client) > 255) $client = mb_substr($client, 0, 255);
        $username = (string)($p['u'] ?? '');
        $deviceId = 'sub-' . substr(md5($client . '|' . $username), 0, 24);
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        try {
            \Doniixify\Database::execute(
                'INSERT INTO active_devices (user_id, device_id, name, user_agent, current_song_id, is_playing, last_seen)
                 VALUES (?, ?, ?, ?, NULL, 0, NOW())
                 ON DUPLICATE KEY UPDATE name = VALUES(name), user_agent = VALUES(user_agent), last_seen = NOW()',
                [$uid, $deviceId, $client, $ua]
            );
        } catch (\Throwable $e) {}
    }

    public static function getLicense(): void
    {
        Router::requireAuth();
        $params = Router::params();
        Response::send(
            Response::ok([
                'license' => [
                    '@valid' => true,
                    '@email' => 'self-hosted@doniixify',
                    '@licenseExpires' => '2099-12-31T23:59:59',
                ],
            ]),
            $params['f'] ?? 'xml',
            $params['callback'] ?? null
        );
    }

    public static function getOpenSubsonicExtensions(): void
    {
        Router::requireAuth();
        $params = Router::params();
        Response::send(
            Response::ok([
                'openSubsonicExtensions' => [
                    ['name' => 'transcodeOffset', 'versions' => [1]],
                    ['name' => 'formPost', 'versions' => [1]],
                ],
            ]),
            $params['f'] ?? 'xml',
            $params['callback'] ?? null
        );
    }
}
