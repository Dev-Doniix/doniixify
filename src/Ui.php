<?php

declare(strict_types=1);

namespace Doniixify;

final class Ui
{
    public static function shell(string $title, string $body, string $subtitle = ''): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — Doniixify</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box}
html,body{margin:0;padding:0;background:#000;color:#e7e9ea;font-family:'Inter',system-ui,-apple-system,sans-serif;line-height:1.5;-webkit-font-smoothing:antialiased}
body{min-height:100vh;display:flex;flex-direction:column}
.wrap{width:100%;max-width:480px;margin:0 auto;padding:48px 24px;flex:1;display:flex;flex-direction:column;justify-content:center}
.brand{font-size:20px;font-weight:700;letter-spacing:-0.02em;margin-bottom:32px;color:#e7e9ea}
.card{background:#16181c;border:1px solid #2f3336;border-radius:14px;padding:28px}
h1{font-size:24px;font-weight:700;letter-spacing:-0.02em;margin:0 0 6px;color:#e7e9ea}
.subtitle{color:#71767b;font-size:14px;margin:0 0 20px}
.muted{color:#565a5e;font-size:13px}
.error{background:rgba(244,33,46,0.08);border:1px solid rgba(244,33,46,0.3);color:#f4212e;padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:14px}
label{display:block;font-size:13px;font-weight:600;color:#71767b;margin-bottom:6px}
.field{margin-bottom:14px}
input[type=text],input[type=password],input[type=email]{width:100%;background:#000;border:1px solid #2f3336;color:#e7e9ea;padding:12px 14px;border-radius:10px;font-size:15px;font-family:inherit;outline:none}
input[type=text]:focus,input[type=password]:focus,input[type=email]:focus{border-color:#e7e9ea}
.btn{display:inline-flex;align-items:center;justify-content:center;background:#e7e9ea;color:#000;border:0;padding:12px 24px;border-radius:999px;cursor:pointer;font-size:15px;font-weight:700;font-family:inherit;text-decoration:none}
.btn:hover{background:#fff}
.btn-block{width:100%}
code{font-family:'JetBrains Mono',monospace;background:#000;border:1px solid #2f3336;padding:2px 6px;border-radius:5px;color:#e7e9ea;font-size:13px}
pre{font-family:'JetBrains Mono',monospace;background:#000;border:1px solid #2f3336;padding:12px 14px;border-radius:10px;overflow-x:auto;font-size:12px;margin:0;color:#71767b}
.row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #2f3336;font-size:14px}
.row:last-child{border-bottom:none}
.row-key{color:#71767b}
.row-val{color:#e7e9ea;font-family:'JetBrains Mono',monospace;font-size:13px}
details{margin-top:16px;background:#000;border:1px solid #2f3336;border-radius:10px;padding:10px 14px}
summary{cursor:pointer;color:#71767b;font-size:13px;user-select:none}
summary:hover{color:#e7e9ea}
footer{padding:24px;text-align:center;color:#565a5e;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
<div class="brand">Doniixify</div>
{$body}
</div>
<footer>Doniixify</footer>
</body>
</html>
HTML;
    }
}
