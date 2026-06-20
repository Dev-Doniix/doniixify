<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Env;

final class LoginView
{
    public static function render(?string $error = null): void
    {
        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error) . '</div>' : '';
        $appName = htmlspecialchars(Env::get('APP_NAME', 'Doniixify'));

        header('Content-Type: text/html; charset=UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — {$appName}</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-wrap">
<div class="login-card">
<div class="login-brand">
<div class="brand-logo">D</div>
<div class="brand-name" style="font-size:18px">{$appName}</div>
</div>
<h1>Sign in</h1>
<p class="subtitle">Enter your credentials to continue</p>
{$errorHtml}
<form method="post" action="/login">
<div class="field">
<label for="u">Username</label>
<input type="text" id="u" name="username" required autocomplete="username" autofocus>
</div>
<div class="field">
<label for="p">Password</label>
<input type="password" id="p" name="password" required autocomplete="current-password">
</div>
<button type="submit" class="btn btn-block">Sign in</button>
</form>
</div>
</body>
</html>
HTML;
    }
}
