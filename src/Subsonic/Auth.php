<?php

declare(strict_types=1);

namespace Doniixify\Subsonic;

use Doniixify\Database;

final class Auth
{
    public static function authenticate(array $params): ?array
    {
        $username = $params['u'] ?? null;
        if ($username === null || $username === '') {
            return null;
        }

        $user = Database::fetchOne(
            'SELECT id, username, password_hash, is_admin FROM users WHERE username = ?',
            [$username]
        );
        if ($user === null) {
            return null;
        }

        $stored = $user['password_hash'];
        $isBcrypt = is_string($stored) && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$'));

        if (isset($params['t']) && $params['t'] !== '' && isset($params['s']) && $params['s'] !== '') {
            if ($isBcrypt) {
                return null;
            }
            $hash = md5($stored . $params['s']);
            if (hash_equals($hash, (string)$params['t'])) {
                return $user;
            }
            return null;
        }

        if (isset($params['p']) && $params['p'] !== '') {
            $password = (string)$params['p'];
            if (str_starts_with($password, 'enc:')) {
                $password = @hex2bin(substr($password, 4)) ?: '';
            }
            if ($isBcrypt) {
                if (password_verify($password, $stored)) {
                    return $user;
                }
                return null;
            }
            if (hash_equals($stored, $password)) {
                return $user;
            }
            return null;
        }

        return null;
    }

    public static function hashPassword(string $plain): string
    {
        return $plain;
    }

    public static function createUser(string $username, string $password, bool $isAdmin = false): int
    {
        Database::execute(
            'INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, ?, NOW())',
            [$username, self::hashPassword($password), $isAdmin ? 1 : 0]
        );
        return (int)Database::lastInsertId();
    }
}
