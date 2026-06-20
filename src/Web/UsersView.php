<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;

final class UsersView
{
    public static function index(): void
    {
        $admin = self::requireAdmin();
        Session::start();
        $flashOk = $_SESSION['users_ok'] ?? null;
        $flashError = $_SESSION['users_error'] ?? null;
        unset($_SESSION['users_ok'], $_SESSION['users_error']);

        $users = Database::fetchAll(
            'SELECT u.id, u.username, u.is_admin, u.created_at, u.last_login_at,
                    (SELECT COUNT(*) FROM stars WHERE user_id = u.id AND item_type = ?) AS fav_count
             FROM users u ORDER BY u.id',
            ['song']
        );

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Users</h1>
                <div class="page-subtitle"><?= count($users) ?> accounts · admin-only management</div>
            </div>
        </header>

        <?php if ($flashOk): ?><div class="flash-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
        <?php if ($flashError): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

        <div class="card">
            <h2>Add user</h2>
            <p class="subtitle">New users can sign in and create playlists.</p>
            <form method="post" action="/users/create" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:12px;align-items:end">
                <div class="field" style="margin:0">
                    <label>Username</label>
                    <input type="text" name="username" required minlength="2" autocomplete="off">
                </div>
                <div class="field" style="margin:0">
                    <label>Password</label>
                    <input type="password" name="password" required minlength="6" autocomplete="new-password">
                </div>
                <label style="margin:0;display:flex;align-items:center;gap:8px;padding:12px 0;cursor:pointer;text-transform:none;letter-spacing:0;font-size:13px;color:var(--text-primary);font-weight:500">
                    <input type="checkbox" name="is_admin" value="1" style="width:auto;margin:0">
                    Admin
                </label>
                <button type="submit" class="btn">Add</button>
            </form>
        </div>

        <div class="card">
            <h2>Users list</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Favorites</th>
                        <th>Created</th>
                        <th>Last login</th>
                        <th style="width:200px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u):
                        $isMe = (int)$u['id'] === (int)$admin['id'];
                    ?>
                        <tr>
                            <td class="muted"><?= (int)$u['id'] ?></td>
                            <td><strong><?= htmlspecialchars($u['username']) ?></strong><?php if ($isMe): ?> <span class="muted" style="font-size:11px">(you)</span><?php endif; ?></td>
                            <td><span class="badge <?= $u['is_admin'] ? 'badge-admin' : 'badge-user' ?>"><?= $u['is_admin'] ? 'Admin' : 'User' ?></span></td>
                            <td><?= (int)$u['fav_count'] ?></td>
                            <td class="muted" style="font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($u['created_at']) ?></td>
                            <td class="muted" style="font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($u['last_login_at'] ?? '—') ?></td>
                            <td style="display:flex;gap:6px">
                                <form method="post" action="/users/<?= (int)$u['id'] ?>/password" style="display:inline" onsubmit="this.querySelector('input').value=prompt('New password for <?= htmlspecialchars($u['username'], ENT_QUOTES) ?> (min 6 chars):');return !!this.querySelector('input').value">
                                    <input type="hidden" name="password" value="">
                                    <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px">Password</button>
                                </form>
                                <?php if (!$isMe): ?>
                                    <form method="post" action="/users/<?= (int)$u['id'] ?>/toggle-admin" style="display:inline">
                                        <button type="submit" class="btn btn-secondary" style="padding:6px 12px;font-size:12px"><?= $u['is_admin'] ? '↓ User' : '↑ Admin' ?></button>
                                    </form>
                                    <form method="post" action="/users/<?= (int)$u['id'] ?>/delete" style="display:inline" data-confirm="Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone." data-confirm-title="Delete user" data-confirm-ok="Delete" data-confirm-danger="1">
                                        <button type="submit" class="btn btn-danger" style="padding:6px 12px;font-size:12px">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        Layout::render('/users', ob_get_clean());
    }

    public static function create(): void
    {
        self::requireAdmin();
        Session::start();
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $isAdmin = !empty($_POST['is_admin']);

        if (mb_strlen($username) < 2) {
            $_SESSION['users_error'] = 'Username must be at least 2 characters.';
        } elseif (strlen($password) < 6) {
            $_SESSION['users_error'] = 'Password must be at least 6 characters.';
        } else {
            try {
                Database::execute('INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)', [$username, $password, $isAdmin ? 1 : 0]);
                $_SESSION['users_ok'] = "User {$username} created.";
            } catch (\PDOException $e) {
                $_SESSION['users_error'] = (int)$e->getCode() === 23000 ? "Username {$username} is taken." : 'Error: ' . $e->getMessage();
            }
        }
        header('Location: /users');
    }

    public static function changePassword(int $userId): void
    {
        self::requireAdmin();
        Session::start();
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 6) {
            $_SESSION['users_error'] = 'Password must be at least 6 characters.';
        } else {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$password, $userId]);
            $_SESSION['users_ok'] = 'Password changed.';
        }
        header('Location: /users');
    }

    public static function toggleAdmin(int $userId): void
    {
        $me = self::requireAdmin();
        Session::start();
        if ($userId === (int)$me['id']) {
            $_SESSION['users_error'] = 'You cannot change your own role.';
        } else {
            Database::execute('UPDATE users SET is_admin = NOT is_admin WHERE id = ?', [$userId]);
            $_SESSION['users_ok'] = 'Role changed.';
        }
        header('Location: /users');
    }

    public static function delete(int $userId): void
    {
        $me = self::requireAdmin();
        Session::start();
        if ($userId === (int)$me['id']) {
            $_SESSION['users_error'] = 'You cannot delete yourself.';
        } else {
            Database::execute('DELETE FROM users WHERE id = ?', [$userId]);
            $_SESSION['users_ok'] = 'User deleted.';
        }
        header('Location: /users');
    }

    private static function requireAdmin(): array
    {
        $user = Session::requireLogin();
        if (empty($user['is_admin'])) {
            http_response_code(403);
            echo 'Forbidden — admin only';
            exit;
        }
        return $user;
    }
}
