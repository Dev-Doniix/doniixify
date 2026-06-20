<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Env;
use Doniixify\Scanner\Scanner;

final class ScanView
{
    public static function index(): void
    {
        Session::requireLogin();
        Session::start();

        $flashError = $_SESSION['scan_error'] ?? null;
        $flashOk = $_SESSION['scan_done'] ?? null;
        unset($_SESSION['scan_error'], $_SESSION['scan_done']);

        $lastScan = Database::fetchOne('SELECT * FROM scans ORDER BY id DESC LIMIT 1');
        $musicPath = Env::get('MUSIC_PATH', '/music');
        $pathExists = is_dir($musicPath);
        $pathReadable = $pathExists && is_readable($musicPath);
        $musicPathEsc = htmlspecialchars($musicPath);

        $stats = [
            'artists' => (int)Database::pdo()->query('SELECT COUNT(*) FROM artists')->fetchColumn(),
            'albums' => (int)Database::pdo()->query('SELECT COUNT(*) FROM albums')->fetchColumn(),
            'songs' => (int)Database::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
        ];

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Library</h1>
                <div class="page-subtitle">Scanning and indexing files</div>
            </div>
        </header>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px">
            <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-bottom:6px">Artists</div>
                <div style="font-size:28px;font-weight:800"><?= $stats['artists'] ?></div>
            </div>
            <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-bottom:6px">Albums</div>
                <div style="font-size:28px;font-weight:800"><?= $stats['albums'] ?></div>
            </div>
            <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:18px">
                <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;font-weight:600;margin-bottom:6px">Songs</div>
                <div style="font-size:28px;font-weight:800"><?= $stats['songs'] ?></div>
            </div>
        </div>

        <?php if ($flashError): ?>
            <div style="background:rgba(250,82,82,0.08);border:1px solid rgba(250,82,82,0.25);color:#ff8b8b;padding:14px 18px;border-radius:10px;margin-bottom:16px;font-size:14px">
                <strong>Scan error:</strong> <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>
        <?php if ($flashOk): ?>
            <div style="background:rgba(61,220,132,0.08);border:1px solid rgba(61,220,132,0.25);color:#3ddc84;padding:14px 18px;border-radius:10px;margin-bottom:16px;font-size:14px">
                <?= htmlspecialchars($flashOk) ?>
            </div>
        <?php endif; ?>

        <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:16px">
            <h2 style="margin:0 0 8px;font-size:18px;font-weight:700">Music folder</h2>
            <p style="margin:0 0 16px;color:var(--text-secondary)">
                Scanned directory: <code style="background:var(--bg-main);padding:3px 8px;border-radius:5px;font-family:var(--font-mono);font-size:12px;color:#9cd5ff"><?= $musicPathEsc ?></code>
                <?php if (!$pathExists): ?>
                    <span style="display:inline-block;margin-left:8px;padding:3px 8px;border-radius:5px;background:rgba(250,82,82,0.12);color:#ff8b8b;font-size:11px;font-weight:600">FOLDER MISSING</span>
                <?php elseif (!$pathReadable): ?>
                    <span style="display:inline-block;margin-left:8px;padding:3px 8px;border-radius:5px;background:rgba(250,176,5,0.12);color:#fab005;font-size:11px;font-weight:600">NO PERMISSIONS</span>
                <?php else: ?>
                    <span style="display:inline-block;margin-left:8px;padding:3px 8px;border-radius:5px;background:rgba(61,220,132,0.12);color:#3ddc84;font-size:11px;font-weight:600">OK</span>
                <?php endif; ?>
            </p>
            <?php if (!$pathExists): ?>
                <p style="color:var(--text-secondary);font-size:13px;margin:0 0 14px">
                    Edit <code>.env</code> and set <code>MUSIC_PATH</code> to the absolute path of your music folder.
                </p>
            <?php endif; ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <form method="post" action="/scan" id="scan-form" style="margin:0">
                    <button type="submit" class="btn" id="scan-btn" <?= !$pathReadable ? 'disabled style="opacity:0.5;cursor:not-allowed"' : '' ?>><?= Icons::svg('disc', 18) ?> Scan new files</button>
                </form>
                <form method="post" action="/scan" id="scan-full-form" style="margin:0" data-confirm="Full rescan will wipe the database and read everything from scratch." data-confirm-title="Full rescan" data-confirm-ok="Rescan" data-confirm-danger="1">
                    <input type="hidden" name="mode" value="full">
                    <button type="submit" class="btn btn-secondary" <?= !$pathReadable ? 'disabled style="opacity:0.5;cursor:not-allowed"' : '' ?>>Full rescan</button>
                </form>
            </div>
        </div>

        <?php if ($lastScan !== null): ?>
            <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;padding:24px">
                <h2 style="margin:0 0 16px;font-size:18px;font-weight:700">Last scan</h2>
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Status</td><td style="text-align:right"><span style="padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600;text-transform:uppercase;<?= self::statusStyle($lastScan['status']) ?>"><?= htmlspecialchars($lastScan['status']) ?></span></td></tr>
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Started</td><td style="text-align:right;font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($lastScan['started_at']) ?></td></tr>
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Finished</td><td style="text-align:right;font-family:var(--font-mono);font-size:12px"><?= htmlspecialchars($lastScan['finished_at'] ?? '—') ?></td></tr>
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Files scanned</td><td style="text-align:right;font-weight:600"><?= (int)$lastScan['files_scanned'] ?></td></tr>
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Added</td><td style="text-align:right;font-weight:600;color:var(--success)"><?= (int)$lastScan['files_added'] ?></td></tr>
                    <tr><td style="padding:8px 0;color:var(--text-muted)">Removed</td><td style="text-align:right;font-weight:600;color:var(--danger)"><?= (int)$lastScan['files_removed'] ?></td></tr>
                    <?php if (!empty($lastScan['error_message'])): ?>
                        <tr><td colspan="2" style="padding:12px 0"><pre style="background:rgba(250,82,82,0.08);border:1px solid rgba(250,82,82,0.25);color:#ff8b8b;padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;margin:0"><?= htmlspecialchars($lastScan['error_message']) ?></pre></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>

        <script>
        document.getElementById('scan-form').addEventListener('submit', function(e){
            var btn = document.getElementById('scan-btn');
            btn.disabled = true;
            btn.innerHTML = 'Scanning... (may take a few minutes)';
        });
        </script>
        <?php
        Layout::render('/library', ob_get_clean());
    }

    public static function run(): void
    {
        Session::requireLogin();
        Session::start();

        if (($_POST['mode'] ?? '') === 'full') {
            \Doniixify\Database::pdo()->exec('DELETE FROM songs');
            \Doniixify\Database::pdo()->exec('DELETE FROM albums');
            \Doniixify\Database::pdo()->exec('DELETE FROM artists');
        }

        $scanner = new Scanner();
        $stats = $scanner->scan();
        if (isset($stats['error'])) {
            $_SESSION['scan_error'] = $stats['error'];
        } else {
            $_SESSION['scan_done'] = sprintf(
                'Scan complete. Added: %d, updated: %d, removed: %d, errors: %d',
                $stats['added'] ?? 0,
                $stats['updated'] ?? 0,
                $stats['removed'] ?? 0,
                $stats['errors'] ?? 0
            );
        }
        header('Location: /library');
        exit;
    }

    private static function statusStyle(string $status): string
    {
        return match ($status) {
            'done' => 'background:rgba(61,220,132,0.12);color:#3ddc84',
            'error' => 'background:rgba(250,82,82,0.12);color:#ff8b8b',
            'running' => 'background:rgba(250,176,5,0.12);color:#fab005',
            default => 'background:var(--bg-main);color:var(--text-muted)',
        };
    }
}
