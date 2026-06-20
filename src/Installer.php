<?php

declare(strict_types=1);

namespace Doniixify;

use PDO;
use PDOException;
use Doniixify\Subsonic\Auth;

final class Installer
{
    public static function isNeeded(): bool
    {
        try {
            $pdo = Database::pdo();
        } catch (PDOException $e) {
            return true;
        }

        try {
            $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
            if (!$hasUsers) {
                return true;
            }
            $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
            return $hasAdmin === 0;
        } catch (PDOException $e) {
            return true;
        }
    }

    public static function run(): void
    {
        $action = $_POST['action'] ?? null;

        try {
            Database::pdo();
        } catch (PDOException $e) {
            self::renderDbError($e->getMessage());
            return;
        }

        Migrator::runPending();

        if ($action === 'create-admin') {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($username === '' || $password === '') {
                self::renderAdminForm('Fill in both fields.');
                return;
            }
            if (strlen($password) < 6) {
                self::renderAdminForm('Password must be at least 6 characters.');
                return;
            }
            try {
                Auth::createUser($username, $password, true);
                self::renderSuccess($username);
                return;
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000) {
                    self::renderAdminForm('A user with this name already exists.');
                    return;
                }
                throw $e;
            }
        }

        self::renderAdminForm();
    }

    private static function renderDbError(string $msg): void
    {
        $dbHost = htmlspecialchars(Env::get('DB_HOST', '127.0.0.1'));
        $dbName = htmlspecialchars(Env::get('DB_NAME', 'doniixify'));
        $dbUser = htmlspecialchars(Env::get('DB_USER', 'doniixify'));
        $msgHtml = htmlspecialchars($msg);

        Ui::shell('Database', <<<HTML
<div class="card">
<h1>Database</h1>
<p class="subtitle">Cannot connect to MySQL. Check <code>.env</code>.</p>

<div class="error">Connection refused.</div>

<div class="row"><span class="row-key">Host</span><span class="row-val">{$dbHost}</span></div>
<div class="row"><span class="row-key">Database</span><span class="row-val">{$dbName}</span></div>
<div class="row"><span class="row-key">User</span><span class="row-val">{$dbUser}</span></div>

<details>
<summary>Details</summary>
<pre>{$msgHtml}</pre>
</details>
</div>
HTML);
    }

    private static function renderAdminForm(?string $error = null): void
    {
        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';

        Ui::shell('Create account', <<<HTML
<div class="card">
<h1>Create account</h1>
<p class="subtitle">First admin account.</p>

{$errorHtml}

<form method="post">
<input type="hidden" name="action" value="create-admin">
<div class="field">
<label for="username">Username</label>
<input type="text" id="username" name="username" required autocomplete="username" autocapitalize="off" autocorrect="off" value="doniix">
</div>
<div class="field">
<label for="password">Password</label>
<input type="password" id="password" name="password" required minlength="6" autocomplete="new-password">
</div>
<button type="submit" class="btn btn-block">Create</button>
</form>
</div>
HTML);
    }

    private static function renderSuccess(string $username): void
    {
        $u = htmlspecialchars($username);

        Ui::shell('Done', <<<HTML
<div class="card">
<h1>Done</h1>
<p class="subtitle">Account <strong style="color:#e7e9ea">{$u}</strong> created.</p>
<a href="/" class="btn btn-block">Continue</a>
</div>
HTML);
    }
}
