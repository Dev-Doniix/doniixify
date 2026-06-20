<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) return;
        if (session_status() === PHP_SESSION_NONE) {
            $https = !empty($_SERVER['HTTPS'])
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (($_SERVER['SERVER_PORT'] ?? '') == 443);
            $TEN_YEARS = 60 * 60 * 24 * 365 * 10;
            @ini_set('session.gc_maxlifetime', (string)$TEN_YEARS);
            @ini_set('session.cookie_lifetime', (string)$TEN_YEARS);
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.cookie_httponly', '1');
            session_set_cookie_params([
                'lifetime' => $TEN_YEARS,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => $https ? 'None' : 'Lax',
            ]);
            session_name('doniixify');
            session_start();
        }
        self::$started = true;
    }

    public static function login(string $username, string $password): ?array
    {
        self::start();
        $user = Database::fetchOne(
            'SELECT id, username, password_hash, is_admin FROM users WHERE username = ?',
            [$username]
        );
        if ($user === null) return null;

        $stored = (string)$user['password_hash'];
        $ok = false;

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2')) {
            $ok = password_verify($password, $stored);
        } else {
            $ok = hash_equals($stored, $password);
            if ($ok) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $user['id']]);
            }
        }

        if (!$ok) return null;

        if (password_needs_rehash($stored, PASSWORD_DEFAULT) && str_starts_with($stored, '$')) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$newHash, $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        return $user;
    }

    public static function user(): ?array
    {
        self::start();
        if (empty($_SESSION['user_id'])) return null;
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'is_admin' => $_SESSION['is_admin'] ?? false,
        ];
    }

    public static function isLogged(): bool
    {
        return self::user() !== null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if ($user === null) {
            header('Location: /login');
            exit;
        }
        return $user;
    }

    public static function requireLoginJson(): array
    {
        $user = self::user();
        if ($user === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        return $user;
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::start();
        $stored = (string)($_SESSION['csrf_token'] ?? '');
        if ($stored === '' || $token === null || $token === '') return false;
        return hash_equals($stored, $token);
    }
}
