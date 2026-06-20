<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Env;
use Doniixify\Scanner\Scanner;

final class SettingsView
{
    public static function index(): void
    {
        $user = Session::requireLogin();
        $isAdmin = !empty($user['is_admin']);
        Session::start();

        $flashOk = $_SESSION['settings_ok'] ?? $_SESSION['users_ok'] ?? null;
        $flashError = $_SESSION['settings_error'] ?? $_SESSION['users_error'] ?? null;
        unset($_SESSION['settings_ok'], $_SESSION['settings_error'], $_SESSION['users_ok'], $_SESSION['users_error']);

        $listeningSeconds = 0;
        try {
            $listeningSeconds = (int)Database::pdo()
                ->query('SELECT COALESCE(listening_seconds, 0) FROM users WHERE id = ' . (int)$user['id'])
                ->fetchColumn();
        } catch (\Throwable $e) {}

        $stats = [
            'songs' => (int)Database::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn(),
            'duration' => (int)Database::pdo()->query('SELECT COALESCE(SUM(duration), 0) FROM songs')->fetchColumn(),
            'size' => (int)Database::pdo()->query('SELECT COALESCE(SUM(size), 0) FROM songs')->fetchColumn(),
            'favs' => (int)Database::pdo()->query('SELECT COUNT(*) FROM stars WHERE user_id = ' . (int)$user['id'] . " AND item_type='song'")->fetchColumn(),
            'users' => (int)Database::pdo()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'listening' => $listeningSeconds,
        ];

        $coverDir = Env::get('COVER_CACHE_PATH', '');
        $coverCount = is_dir($coverDir) ? count(glob($coverDir . '/*') ?: []) : 0;
        $coverDirExists = is_dir($coverDir);
        $coverDirWritable = $coverDirExists && is_writable($coverDir);
        $lastScan = Database::fetchOne('SELECT * FROM scans ORDER BY id DESC LIMIT 1');
        $musicPath = Env::get('MUSIC_PATH', '/music');
        $pathOk = is_dir($musicPath) && is_readable($musicPath);
        $downloadJobs = \Doniixify\Downloader\JobTracker::all(30);

        $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $isNativeApp = str_contains($userAgent, 'Doniixify-Desktop')
            || (str_contains($userAgent, 'wv') && str_contains($userAgent, 'Chrome'))
            || str_contains($userAgent, 'pywebview')
            || str_contains($userAgent, 'TWA');
        $showApps = !$isNativeApp;

        $users = [];
        if ($isAdmin) {
            $users = Database::fetchAll(
                'SELECT u.id, u.username, u.is_admin, u.created_at, u.last_login_at,
                        (SELECT COUNT(*) FROM stars WHERE user_id = u.id AND item_type = ?) AS fav_count
                 FROM users u ORDER BY u.id',
                ['song']
            );
        }

        $username = htmlspecialchars($user['username']);
        $appVersion = htmlspecialchars(Env::get('APP_VERSION', '0.1.0'));
        $appName = htmlspecialchars(Env::get('APP_NAME', 'Doniixify'));
        $initial = strtoupper(mb_substr($user['username'], 0, 1, 'UTF-8'));
        $serverUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $storedHash = Database::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$user['id']])['password_hash'] ?? '';
        $isBcrypt = is_string($storedHash) && (str_starts_with($storedHash, '$2y$') || str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$2b$'));

        ob_start();
        ?>
        <div class="settings-page">
            <?php if ($flashOk): ?><div class="flash-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

            <div class="settings-layout">
                <nav class="settings-nav" id="settings-nav">
                    <button class="settings-nav-item active" data-tab="account"><?= Icons::svg('user', 18) ?> <span>Profile</span></button>
                    <button class="settings-nav-item" data-tab="acc-stats"><?= Icons::svg('chart', 18) ?> <span>Stats</span></button>
                    <button class="settings-nav-item" data-tab="audio"><?= Icons::svg('volume', 18) ?> <span>Audio</span></button>
                    <?php if ($showApps): ?>
                        <button class="settings-nav-item" data-tab="apps"><?= Icons::svg('monitor', 18) ?> <span>Apps</span></button>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <div style="margin:8px 0 4px;font-size:10px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);padding:0 12px">Admin</div>
                        <button class="settings-nav-item" data-tab="downloads"><?= Icons::svg('disc', 18) ?> <span>Downloads</span></button>
                        <button class="settings-nav-item" data-tab="library"><?= Icons::svg('database', 18) ?> <span>Library scan</span></button>
                        <button class="settings-nav-item" data-tab="logs"><?= Icons::svg('clock', 18) ?> <span>Logs</span></button>
                        <button class="settings-nav-item" data-tab="users"><?= Icons::svg('users', 18) ?> <span>Users</span></button>
                    <?php endif; ?>
                </nav>

                <div class="settings-content">
                    <div style="margin-bottom:16px">
                        <div style="position:relative;max-width:400px">
                            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none"><?= Icons::svg('search', 16) ?></span>
                            <input type="text" id="settings-search" placeholder="Search settings…" style="width:100%;padding:10px 14px 10px 38px;background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:8px;color:#fff;font-size:14px;outline:none">
                        </div>
                    </div>
                    <script>
                    (function(){
                        const input = document.getElementById('settings-search');
                        if (!input) return;
                        let timer = null;
                        input.addEventListener('input', () => {
                            clearTimeout(timer);
                            timer = setTimeout(() => {
                                const q = input.value.trim().toLowerCase();
                                const subtabs = document.querySelectorAll('.acc-subtab');
                                subtabs.forEach(t => t.style.opacity = q === '' ? '1' : '0.5');
                                document.querySelectorAll('.settings-card').forEach(card => {
                                    const text = (card.textContent || '').toLowerCase();
                                    const match = q === '' || text.includes(q);
                                    if (q === '') {
                                        const subSection = card.dataset.accSection;
                                        let activeSub = 'profile';
                                        try { activeSub = sessionStorage.getItem('doniix-acc-sub') || 'profile'; } catch (_) {}
                                        card.style.display = (!subSection || subSection === activeSub) ? '' : 'none';
                                    } else {
                                        card.style.display = match ? '' : 'none';
                                    }
                                    card.dataset.searchHidden = (q !== '' && !match) ? '1' : '';
                                });
                            }, 100);
                        });
                    })();
                    </script>

                    <!-- ACCOUNT -->
                    <section class="settings-section active" data-tab-content="account">
                        <script>
                        window.__doAutoMapSections = function autoMapSections(){
                            const mapping = {
                                'change password': ['profile', 'account'], 'sign out': ['profile', 'account'],
                                'accessibility': ['appearance', 'a11y'], 'last.fm scrobble': ['profile', 'integrations'],
                                'listening stats': ['stats', 'overview'], 'streak': ['stats', 'overview'],
                                'last 30 days': ['stats', 'trends'], 'when you listen': ['stats', 'trends'],
                                'weekly listening goal': ['stats', 'goals'], 'year wrap': ['stats', 'annual'],
                                'sleep timer': ['audio', 'playback'], 'lyrics translation': ['audio', 'lyrics'],
                                'lyrics cache': ['audio', 'lyrics'], 'mood modes': ['audio', 'effects'],
                                'pitch shift': ['audio', 'effects'], 'reverb': ['audio', 'effects'],
                                'spectrum analyzer': ['audio', 'visualizers'], 'smart eq': ['audio', 'equalizer'],
                                'equalizer presets': ['audio', 'equalizer'], 'mini player': ['audio', 'playback'],
                                'smart break': ['audio', 'playback'], 'auto-pause when tab hidden': ['audio', 'playback'],
                                'volume normalization': ['audio', 'equalizer'], 'replaygain': ['audio', 'equalizer'],
                                'offline tracks': ['advanced', 'storage'], 'duplicate tracks': ['advanced', 'cleanup'],
                                'saved smart rules': ['advanced', 'playlists'], 'smart playlist builder': ['advanced', 'playlists'],
                                'export your data': ['advanced', 'export'],
                                'backup & restore': ['advanced', 'data'], 'storage breakdown': ['advanced', 'data'],
                                'library health': ['advanced', 'data'], 'app refresh & cache': ['advanced', 'system'],
                                'fix missing covers': ['advanced', 'cleanup'],
                                'appearance': ['appearance', 'theme'], 'theme': ['appearance', 'theme'],
                            };
                            const SUBCAT_ORDER = {
                                profile: ['account', 'integrations'],
                                appearance: ['theme', 'a11y'],
                                stats: ['overview', 'goals', 'trends', 'annual'],
                                audio: ['playback', 'equalizer', 'effects', 'visualizers', 'lyrics'],
                                advanced: ['data', 'storage', 'cleanup', 'playlists', 'export', 'system'],
                            };
                            window.__SUBCAT_ORDER = SUBCAT_ORDER;
                            const SUBCAT_LABELS = {
                                account: 'Account', integrations: 'Integrations',
                                theme: 'Theme & Colors', a11y: 'Accessibility',
                                overview: 'Overview', goals: 'Goals', trends: 'Trends', annual: 'Annual',
                                playback: 'Playback', equalizer: 'Equalizer & Loudness', effects: 'Audio Effects',
                                visualizers: 'Visualizers', lyrics: 'Lyrics',
                                storage: 'Storage', cleanup: 'Cleanup & Health', playlists: 'Smart Playlists', export: 'Export',
                                data: 'Data & Backup', system: 'System',
                            };
                            window.__SUBCAT_LABELS = SUBCAT_LABELS;
                            document.querySelectorAll('[data-tab-content="account"] .settings-card, [data-tab-content="account"] .stat-row').forEach(card => {
                                if (card.dataset.accSection && card.dataset.subcat) return;
                                const h = card.querySelector('h2');
                                const label = h ? h.textContent.toLowerCase().trim() : '';
                                let matched = null;
                                if (label) {
                                    for (const k in mapping) {
                                        if (label === k || label.startsWith(k)) { matched = mapping[k]; break; }
                                    }
                                }
                                if (!matched) matched = [card.dataset.accSection || 'advanced', 'misc'];
                                card.dataset.accSection = matched[0];
                                card.dataset.subcat = matched[1];
                            });
                        };
                        window.__filterAccountSection = (sub) => {
                            if (window.__doAutoMapSections) window.__doAutoMapSections();
                            const container = document.querySelector('[data-tab-content="account"]');
                            if (!container) return;
                            document.querySelectorAll('[data-tab-content="account"] [data-acc-section]').forEach(c => {
                                c.style.display = c.dataset.accSection === sub ? '' : 'none';
                            });
                            container.querySelectorAll('.subcat-divider').forEach(d => d.remove());
                            const order = (window.__SUBCAT_ORDER && window.__SUBCAT_ORDER[sub]) || [];
                            const labels = window.__SUBCAT_LABELS || {};
                            order.forEach(subcatKey => {
                                const first = container.querySelector('[data-acc-section="' + sub + '"][data-subcat="' + subcatKey + '"]');
                                if (!first) return;
                                const divider = document.createElement('div');
                                divider.className = 'subcat-divider';
                                divider.dataset.subcat = subcatKey;
                                divider.style.cssText = 'margin:24px 0 12px;font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:var(--text-muted);font-weight:700;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.06)';
                                divider.textContent = labels[subcatKey] || subcatKey;
                                first.parentNode.insertBefore(divider, first);
                            });
                            const visibleSubcats = new Set();
                            container.querySelectorAll('[data-acc-section="' + sub + '"][data-subcat]').forEach(c => visibleSubcats.add(c.dataset.subcat));
                            container.querySelectorAll('[data-acc-section="' + sub + '"][data-subcat="misc"]').forEach(c => {
                                if (visibleSubcats.size > 1) {
                                    const d = container.querySelector('.subcat-divider[data-subcat="misc"]');
                                    if (!d) {
                                        const div = document.createElement('div');
                                        div.className = 'subcat-divider';
                                        div.dataset.subcat = 'misc';
                                        div.style.cssText = 'margin:24px 0 12px;font-size:11px;text-transform:uppercase;letter-spacing:0.12em;color:var(--text-muted);font-weight:700;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,0.06)';
                                        div.textContent = 'Other';
                                        c.parentNode.insertBefore(div, c);
                                    }
                                }
                            });
                        };
                        </script>
                        <div class="stat-row stat-row-accent" data-acc-section="stats">
                            <div class="stat-tile stat-tile-accent">
                                <div class="stat-tile-label">Time listened</div>
                                <div class="stat-tile-value"><?= self::formatHours($stats['listening']) ?></div>
                                <div class="stat-tile-sub">Across every device you used</div>
                            </div>
                            <div class="stat-tile"><div class="stat-tile-label">Songs in library</div><div class="stat-tile-value"><?= number_format($stats['songs']) ?></div></div>
                            <div class="stat-tile"><div class="stat-tile-label">Liked</div><div class="stat-tile-value"><?= number_format($stats['favs']) ?></div></div>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Weekly listening goal</h2>
                                <p>Set a target. Bar fills as you reach it.</p>
                            </div>
                            <div id="settings-goal-progress" style="margin-bottom:12px"></div>
                            <div style="display:flex;gap:8px;align-items:center">
                                <input type="number" id="settings-goal-input" min="0" max="10080" placeholder="Minutes per week" class="field-input" style="width:160px">
                                <button type="button" id="settings-goal-save" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Save goal</button>
                                <span id="settings-goal-status" style="font-size:12px;color:var(--text-muted)"></span>
                            </div>
                            <script>
                            (async function(){
                                const box = document.getElementById('settings-goal-progress');
                                const input = document.getElementById('settings-goal-input');
                                const status = document.getElementById('settings-goal-status');
                                const refresh = async () => {
                                    try {
                                        const r = await fetch('/api/me/goal');
                                        const d = await r.json();
                                        if (!d || d.error) { box.textContent = 'Could not load goal.'; return; }
                                        input.value = d.weekly_goal_min;
                                        const pct = Math.min(100, d.percent || 0);
                                        const color = d.reached ? '#1ed760' : 'rgb(var(--accent-rgb,30,215,96))';
                                        box.innerHTML =
                                            '<div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:6px"><span>' + d.week_min + ' / ' + d.weekly_goal_min + ' min</span><span>' + pct + '%' + (d.reached ? ' ✓' : '') + '</span></div>' +
                                            '<div style="background:rgba(255,255,255,0.06);border-radius:6px;overflow:hidden;height:10px"><div style="background:' + color + ';height:100%;width:' + pct + '%;transition:width 0.5s ease"></div></div>';
                                    } catch(_) {}
                                };
                                refresh();
                                document.getElementById('settings-goal-save')?.addEventListener('click', async () => {
                                    const fd = new FormData();
                                    fd.append('minutes', String(parseInt(input.value, 10) || 0));
                                    const r = await fetch('/api/me/goal', { method: 'POST', body: fd });
                                    const d = await r.json();
                                    if (d && d.ok) { status.textContent = 'Saved'; status.style.color = '#1ed760'; refresh(); }
                                    else { status.textContent = 'Failed'; status.style.color = 'var(--danger,#f55)'; }
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Streak</h2>
                                <p>Days in a row with listening activity.</p>
                            </div>
                            <div id="settings-streak" style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px"></div>
                            <script>
                            (async function(){
                                const box = document.getElementById('settings-streak');
                                try {
                                    const r = await fetch('/api/me/streak');
                                    const d = await r.json();
                                    if (!d || d.error) return;
                                    const tile = (label, val, suffix) => '<div style="padding:14px;border:1px solid var(--border);border-radius:10px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:4px">' + label + '</div><div style="font-size:24px;font-weight:800;color:#fff">' + val + (suffix ? '<span style="font-size:14px;color:var(--text-muted);margin-left:4px">' + suffix + '</span>' : '') + '</div></div>';
                                    box.innerHTML =
                                        tile('Current streak', d.current_streak || 0, 'd') +
                                        tile('This week', d.week_minutes || 0, 'min') +
                                        tile('Plays this week', d.week_plays || 0, '');
                                } catch(e) {}
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Top genres</h2>
                                <p>Most-played tags. Use the auto-detect button to bootstrap.</p>
                            </div>
                            <div id="top-genres-list" style="margin-bottom:10px"></div>
                            <button type="button" id="auto-tag-btn" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);font-size:12px;padding:6px 12px">Auto-detect tags from filenames</button>
                            <span id="auto-tag-status" style="margin-left:10px;font-size:12px;color:var(--text-muted)"></span>
                            <script>
                            (async function(){
                                const box = document.getElementById('top-genres-list');
                                try {
                                    const r = await fetch('/api/me/top-genres');
                                    const d = await r.json();
                                    if (!d.genres || !d.genres.length) { box.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">No tagged songs yet.</div>'; return; }
                                    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                                    box.innerHTML = d.genres.slice(0, 10).map(g =>
                                        '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span>' + esc(g.tag) + ' <span style="color:var(--text-muted);font-size:11px">· ' + g.tracks + ' tracks</span></span><span style="color:var(--text-muted);font-size:12px">' + g.plays + ' (' + g.pct + '%)</span></div>'
                                    ).join('');
                                } catch(_) { box.textContent = 'Error'; }
                            })();
                            document.getElementById('auto-tag-btn')?.addEventListener('click', async () => {
                                const status = document.getElementById('auto-tag-status');
                                status.textContent = 'Detecting…';
                                try {
                                    const r = await fetch('/api/tags/auto-detect', { method: 'POST' });
                                    const d = await r.json();
                                    status.textContent = '✓ ' + (d.added || 0) + ' tags added';
                                    status.style.color = '#1ed760';
                                } catch(_) { status.textContent = 'Failed'; }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Listening calendar <?= date('Y') ?></h2>
                                <p>Daily activity heatmap for this year.</p>
                            </div>
                            <div id="calendar-box" style="overflow-x:auto"></div>
                            <script>
                            (async function(){
                                const box = document.getElementById('calendar-box');
                                try {
                                    const r = await fetch('/api/me/calendar');
                                    const d = await r.json();
                                    if (!d || !d.by_day) { box.textContent = 'No data.'; return; }
                                    const year = d.year || new Date().getFullYear();
                                    const start = new Date(year, 0, 1);
                                    const end = new Date(year, 11, 31);
                                    const days = Math.ceil((end - start) / 86400000) + 1;
                                    const weeks = Math.ceil(days / 7);
                                    let html = '<div style="display:grid;grid-template-columns:repeat(' + weeks + ',1fr);gap:2px;min-width:' + (weeks * 12) + 'px">';
                                    for (let w = 0; w < weeks; w++) {
                                        html += '<div style="display:grid;grid-template-rows:repeat(7,1fr);gap:2px">';
                                        for (let dow = 0; dow < 7; dow++) {
                                            const date = new Date(year, 0, 1 + w * 7 + dow);
                                            if (date.getFullYear() !== year) { html += '<div></div>'; continue; }
                                            const dStr = date.toISOString().slice(0, 10);
                                            const v = d.by_day[dStr] || 0;
                                            const intensity = d.max > 0 ? v / d.max : 0;
                                            const bg = intensity > 0 ? 'rgba(var(--accent-rgb,30,215,96),' + (0.2 + intensity * 0.8) + ')' : 'rgba(255,255,255,0.04)';
                                            html += '<div title="' + dStr + ': ' + v + ' plays" style="aspect-ratio:1;background:' + bg + ';border-radius:2px"></div>';
                                        }
                                        html += '</div>';
                                    }
                                    html += '</div><div style="margin-top:8px;font-size:11px;color:var(--text-muted)">' + d.active_days + ' active days in ' + year + '</div>';
                                    box.innerHTML = html;
                                } catch(_) { box.textContent = 'Error'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Listening sessions</h2>
                                <p>Your last 30 sessions (5+ min gap = new session).</p>
                            </div>
                            <div id="sessions-list" style="font-size:13px"></div>
                            <script>
                            (async function(){
                                const box = document.getElementById('sessions-list');
                                try {
                                    const r = await fetch('/api/me/sessions');
                                    const d = await r.json();
                                    if (!d.sessions || !d.sessions.length) { box.textContent = 'No sessions yet.'; return; }
                                    box.innerHTML = d.sessions.slice(0, 10).map(s =>
                                        '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><span>' + (s.started_at || '').slice(0, 16) + '</span><span style="color:var(--text-muted)">' + (s.duration_min || 0) + ' min · ' + (s.track_count || 0) + ' tracks</span></div>'
                                    ).join('');
                                } catch(_) { box.textContent = 'Error'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>When you listen</h2>
                                <p>Day-of-week × hour heatmap (last 30 days).</p>
                            </div>
                            <div id="settings-heatmap" style="overflow-x:auto"></div>
                            <script>
                            (async function(){
                                const box = document.getElementById('settings-heatmap');
                                try {
                                    const r = await fetch('/api/me/hour-heatmap?days=30');
                                    const d = await r.json();
                                    if (!d || !d.grid) { box.textContent = 'No data.'; return; }
                                    const dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                                    let html = '<div style="display:grid;grid-template-columns:auto repeat(24,1fr);gap:2px;font-size:9px;min-width:600px">';
                                    html += '<div></div>';
                                    for (let h = 0; h < 24; h++) html += '<div style="text-align:center;color:var(--text-muted)">' + (h % 6 === 0 ? h : '') + '</div>';
                                    for (let dow = 0; dow < 7; dow++) {
                                        html += '<div style="color:var(--text-muted);padding-right:6px;align-self:center">' + dows[dow] + '</div>';
                                        for (let h = 0; h < 24; h++) {
                                            const v = d.grid[dow][h];
                                            const intensity = d.max > 0 ? v / d.max : 0;
                                            const bg = intensity > 0 ? 'rgba(var(--accent-rgb,30,215,96),' + (0.15 + intensity * 0.85) + ')' : 'rgba(255,255,255,0.04)';
                                            html += '<div title="' + dows[dow] + ' ' + h + ':00 — ' + v + ' plays" style="aspect-ratio:1;background:' + bg + ';border-radius:3px"></div>';
                                        }
                                    }
                                    html += '</div>';
                                    box.innerHTML = html;
                                } catch(e) { box.textContent = 'Error'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Last 30 days</h2>
                                <p>Daily listening minutes.</p>
                            </div>
                            <div id="settings-timeseries-chart" style="height:120px;position:relative"></div>
                            <script>
                            (async function(){
                                const box = document.getElementById('settings-timeseries-chart');
                                if (!box) return;
                                try {
                                    const r = await fetch('/api/me/stats/timeseries?days=30');
                                    const d = await r.json();
                                    if (!d || !d.series || !d.series.length) { box.textContent = 'No data yet.'; return; }
                                    const max = Math.max(1, ...d.series.map(p => p.seconds));
                                    const w = box.clientWidth || 600;
                                    const h = 120;
                                    const bw = (w - 2) / d.series.length;
                                    const svgParts = ['<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="100%" preserveAspectRatio="none">'];
                                    d.series.forEach((p, i) => {
                                        const bh = Math.round((p.seconds / max) * (h - 12));
                                        const x = i * bw + 1;
                                        const y = h - bh;
                                        svgParts.push('<rect x="' + x + '" y="' + y + '" width="' + Math.max(1, bw - 2) + '" height="' + bh + '" fill="rgb(var(--accent-rgb,30,215,96))" opacity="' + (p.seconds > 0 ? 0.85 : 0.15) + '"><title>' + p.day + ': ' + Math.round(p.seconds / 60) + ' min</title></rect>');
                                    });
                                    svgParts.push('</svg>');
                                    box.innerHTML = svgParts.join('');
                                    const total = d.series.reduce((s, p) => s + p.seconds, 0);
                                    box.insertAdjacentHTML('beforeend', '<div style="margin-top:8px;font-size:12px;color:var(--text-muted);text-align:right">' + Math.round(total / 60) + ' minutes total · avg ' + Math.round(total / 60 / d.days) + ' min/day</div>');
                                } catch(e) { box.textContent = 'Could not load chart.'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Listening stats</h2>
                                <p>What you've been playing most.</p>
                            </div>
                            <div id="settings-stats-body" style="font-size:14px;color:var(--text-secondary)"><?= $skel = '' ?>Loading…</div>
                            <script>
                            (async function(){
                                const box = document.getElementById('settings-stats-body');
                                try {
                                    const r = await fetch('/api/me/stats');
                                    const d = await r.json();
                                    if (!d || d.error) { box.textContent = 'Could not load stats.'; return; }
                                    const fmtHours = (sec) => {
                                        const h = Math.floor(sec / 3600);
                                        const m = Math.floor((sec % 3600) / 60);
                                        return h > 0 ? h + 'h ' + m + 'm' : m + 'm';
                                    };
                                    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                                    let html = '';
                                    html += '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:20px">';
                                    html += '<div style="padding:14px;border:1px solid var(--border);border-radius:10px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:4px">Total plays</div><div style="font-size:24px;font-weight:800;color:#fff">' + (d.total_plays || 0) + '</div></div>';
                                    html += '<div style="padding:14px;border:1px solid var(--border);border-radius:10px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:4px">Listening time</div><div style="font-size:24px;font-weight:800;color:#fff">' + fmtHours(d.total_seconds || 0) + '</div></div>';
                                    html += '</div>';
                                    if ((d.top_artists || []).length) {
                                        html += '<div style="margin-bottom:18px"><div style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px">Top artists</div>';
                                        d.top_artists.slice(0, 5).forEach((a, i) => {
                                            html += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><a href="/artist/' + a.id + '" style="color:#fff;text-decoration:none">' + (i + 1) + '. ' + esc(a.name) + '</a><span style="color:var(--text-muted);font-size:13px">' + a.plays + ' plays</span></div>';
                                        });
                                        html += '</div>';
                                    }
                                    if ((d.top_songs || []).length) {
                                        html += '<div><div style="font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:8px">Top tracks</div>';
                                        d.top_songs.slice(0, 5).forEach((s, i) => {
                                            html += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid rgba(255,255,255,0.04)"><div style="min-width:0;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><span style="color:#fff">' + (i + 1) + '. ' + esc(s.title) + '</span> <span style="color:var(--text-muted);font-size:12px">— ' + esc(s.artist_name || '') + '</span></div><span style="color:var(--text-muted);font-size:13px;flex-shrink:0;margin-left:12px">' + s.play_count + 'x</span></div>';
                                        });
                                        html += '</div>';
                                    }
                                    box.innerHTML = html;
                                } catch(e) { box.textContent = 'Error loading stats.'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Change password</h2>
                                <p>Update the password used for both the web player and Subsonic clients.</p>
                            </div>
                            <form method="post" action="/settings/password" class="settings-form">
                                <div class="settings-field"><label>Current password</label><input type="password" name="old_password" required autocomplete="current-password"></div>
                                <div class="settings-field"><label>New password</label><input type="password" name="new_password" required minlength="6" autocomplete="new-password"></div>
                                <div class="settings-field"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="6" autocomplete="new-password"></div>
                                <div class="settings-form-actions">
                                    <button type="submit" class="btn">Update password</button>
                                </div>
                            </form>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>App refresh &amp; cache</h2>
                                <p>If something looks broken or stale, force the app to re-fetch all code and clear caches. Use this when after an update you still see the old behavior.</p>
                            </div>
                            <div id="settings-refresh-status" style="font-size:13px;color:var(--text-muted);margin-bottom:12px"></div>
                            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
                                <button type="button" id="settings-reset-app" class="btn" style="background:#ff3030;color:#fff;border:0;display:inline-flex;align-items:center;gap:8px">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg> Reset app (clear cache + SW)
                                </button>
                                <button type="button" id="settings-soft-refresh" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg> Soft refresh
                                </button>
                            </div>
                            <div style="margin-top:16px;font-size:11px;color:var(--text-muted);font-family:'JetBrains Mono',monospace;line-height:1.6">
                                <div>App version: <span id="settings-app-version"><?= (int)@filemtime(__DIR__ . '/../../assets/js/app.js') ?>/<?= (int)@filemtime(__DIR__ . '/../../assets/css/app.css') ?></span></div>
                                <div>Stored: <span id="settings-stored-version">…</span></div>
                                <div>SW: <span id="settings-sw-state">…</span></div>
                                <div>Caches: <span id="settings-cache-count">…</span></div>
                            </div>
                            <script>
                            (function(){
                                var verEl = document.getElementById('settings-stored-version');
                                var swEl = document.getElementById('settings-sw-state');
                                var cEl = document.getElementById('settings-cache-count');
                                var status = document.getElementById('settings-refresh-status');
                                try { verEl.textContent = localStorage.getItem('doniix-app-ver') || '(none)'; } catch(_) { verEl.textContent = '(no access)'; }
                                if ('serviceWorker' in navigator) {
                                    navigator.serviceWorker.getRegistrations().then(function(rs){
                                        swEl.textContent = rs.length === 0 ? 'none' : rs.map(function(r){
                                            return (r.active ? 'active' : r.installing ? 'installing' : r.waiting ? 'waiting' : 'idle');
                                        }).join(', ');
                                    }).catch(function(){ swEl.textContent = '(error)'; });
                                } else { swEl.textContent = '(unsupported)'; }
                                if ('caches' in window) {
                                    caches.keys().then(function(ks){ cEl.textContent = ks.length + (ks.length ? ' (' + ks.join(', ') + ')' : ''); }).catch(function(){ cEl.textContent = '(error)'; });
                                } else { cEl.textContent = '(unsupported)'; }
                                document.getElementById('settings-soft-refresh').addEventListener('click', function(){
                                    status.textContent = 'Soft refreshing…';
                                    location.replace(location.pathname + '?_t=' + Date.now());
                                });
                                document.getElementById('settings-reset-app').addEventListener('click', function(){
                                    var btn = this;
                                    btn.disabled = true;
                                    btn.style.opacity = '0.6';
                                    status.textContent = 'Unregistering service worker…';
                                    var done = false;
                                    var go = function(){
                                        if (done) return;
                                        done = true;
                                        location.replace(location.pathname + '?_t=' + Date.now());
                                    };
                                    setTimeout(go, 3000);
                                    var p = ('serviceWorker' in navigator)
                                        ? navigator.serviceWorker.getRegistrations().then(function(rs){
                                            return Promise.all(rs.map(function(r){ return r.unregister(); }));
                                          }).catch(function(){})
                                        : Promise.resolve();
                                    p.then(function(){
                                        status.textContent = 'Clearing caches…';
                                        if ('caches' in window) {
                                            return caches.keys().then(function(ns){
                                                return Promise.all(ns.map(function(n){ return caches.delete(n); }));
                                            }).catch(function(){});
                                        }
                                    }).then(function(){
                                        try { localStorage.removeItem('doniix-app-ver'); } catch(_) {}
                                        try { sessionStorage.removeItem('doniix-app-ver'); } catch(_) {}
                                        status.textContent = 'Reloading…';
                                        go();
                                    }).catch(go);
                                });
                            })();
                            </script>
                        </div>


                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Mood modes</h2>
                                <p>One-tap presets that adjust EQ, speed, and volume.</p>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <button type="button" class="btn mood-btn" data-mood="workout" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">💪 Workout</button>
                                <button type="button" class="btn mood-btn" data-mood="focus" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">🧘 Focus</button>
                                <button type="button" class="btn mood-btn" data-mood="bedtime" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Bedtime</button>
                                <button type="button" class="btn mood-btn" data-mood="party" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Party</button>
                            </div>
                            <script>
                            document.querySelectorAll('.mood-btn').forEach(b => b.addEventListener('click', () => {
                                if (window.__moodModes) window.__moodModes.apply(b.dataset.mood);
                            }));
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Smart EQ</h2>
                                <p>Auto-pick preset based on song tags (rock, jazz, hiphop, electronic, etc.).</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <input type="checkbox" id="settings-smart-eq" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable smart EQ matching</span>
                            </label>
                            <script>
                            (function(){
                                const cb = document.getElementById('settings-smart-eq');
                                try {
                                    const s = JSON.parse(localStorage.getItem('doniix-audio-settings') || '{}');
                                    cb.checked = !!s.smart_eq;
                                } catch (_) {}
                                cb.addEventListener('change', () => {
                                    try {
                                        const s = JSON.parse(localStorage.getItem('doniix-audio-settings') || '{}');
                                        s.smart_eq = cb.checked;
                                        localStorage.setItem('doniix-audio-settings', JSON.stringify(s));
                                        if (window.__showToast) window.__showToast('Smart EQ ' + (cb.checked ? 'on' : 'off'));
                                    } catch (_) {}
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Pitch shift</h2>
                                <p>Change pitch in semitones (±12). Independent of speed.</p>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px">
                                <button type="button" id="pitch-down" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);width:40px">−</button>
                                <span id="pitch-val" style="min-width:60px;text-align:center;font-family:JetBrains Mono,monospace;font-size:18px;font-weight:700">0</span>
                                <button type="button" id="pitch-up" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);width:40px">+</button>
                                <button type="button" id="pitch-reset" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);font-size:12px;padding:6px 12px">Reset</button>
                            </div>
                            <script>
                            (function(){
                                if (!window.__pitchShift) return;
                                const val = document.getElementById('pitch-val');
                                const refresh = () => { val.textContent = (window.__pitchShift.get() > 0 ? '+' : '') + window.__pitchShift.get() + ' st'; };
                                refresh();
                                document.getElementById('pitch-down')?.addEventListener('click', () => { window.__pitchShift.set(window.__pitchShift.get() - 1); refresh(); });
                                document.getElementById('pitch-up')?.addEventListener('click', () => { window.__pitchShift.set(window.__pitchShift.get() + 1); refresh(); });
                                document.getElementById('pitch-reset')?.addEventListener('click', () => { window.__pitchShift.set(0); refresh(); });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Reverb</h2>
                                <p>Add room/hall ambiance to playback.</p>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px">
                                <label style="font-size:13px;color:var(--text-secondary);min-width:80px">Wet mix</label>
                                <input type="range" id="reverb-slider" min="0" max="100" value="0" style="flex:1">
                                <span id="reverb-val" style="min-width:50px;text-align:right">0%</span>
                            </div>
                            <script>
                            (function(){
                                if (!window.__reverbEffect) return;
                                const slider = document.getElementById('reverb-slider');
                                const valEl = document.getElementById('reverb-val');
                                slider.value = Math.round(window.__reverbEffect.get() * 100);
                                valEl.textContent = slider.value + '%';
                                slider.addEventListener('input', () => {
                                    window.__reverbEffect.setMix(parseInt(slider.value, 10) / 100);
                                    valEl.textContent = slider.value + '%';
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Spectrum analyzer</h2>
                                <p>Fullscreen audio visualizer (Esc / × to close).</p>
                            </div>
                            <button type="button" id="open-spectrum" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Open spectrum</button>
                            <script>
                            document.getElementById('open-spectrum')?.addEventListener('click', () => {
                                if (window.__spectrumFullscreen) window.__spectrumFullscreen.open();
                            });
                            </script>
                        </div>


                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Equalizer presets</h2>
                                <p>Quick-apply a preset (overrides custom EQ sliders).</p>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                <?php foreach (['flat','bass','rock','jazz','classical','pop','vocal','electronic','hiphop','treble'] as $p): ?>
                                <button type="button" class="btn eq-preset-btn" data-preset="<?= $p ?>" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);text-transform:capitalize;font-size:12px;padding:6px 12px"><?= htmlspecialchars($p) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <script>
                            document.querySelectorAll('.eq-preset-btn').forEach(b => b.addEventListener('click', () => {
                                if (window.__applyEqPreset && window.__applyEqPreset(b.dataset.preset)) {
                                    if (window.__showToast) window.__showToast('Applied: ' + b.dataset.preset);
                                }
                            }));
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Mini player (Picture-in-Picture)</h2>
                                <p>Open a floating window with current track controls. Chrome/Edge only.</p>
                            </div>
                            <button type="button" id="settings-pip-open" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="14" rx="2"/><rect x="9" y="11" width="10" height="6" rx="1"/></svg>
                                Open mini player
                            </button>
                            <script>
                            document.getElementById('settings-pip-open')?.addEventListener('click', () => {
                                if (window.__miniPlayerPiP) window.__miniPlayerPiP.open();
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Backup &amp; restore</h2>
                                <p>Full backup of likes, playlists, tags, and prefs. Restore on any device.</p>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                                <a href="/api/backup" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);text-decoration:none">📦 Download backup (JSON)</a>
                                <label class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);cursor:pointer;display:inline-flex;align-items:center;gap:8px">
                                    <?= Icons::svg('upload', 14) ?> Restore from file
                                    <input type="file" id="settings-restore" accept="application/json" style="display:none">
                                </label>
                                <span id="settings-restore-status" style="font-size:12px;color:var(--text-muted)"></span>
                            </div>
                            <script>
                            document.getElementById('settings-restore')?.addEventListener('change', async (e) => {
                                const file = e.target.files[0];
                                if (!file) return;
                                const status = document.getElementById('settings-restore-status');
                                status.textContent = 'Restoring…';
                                const fd = new FormData();
                                fd.append('backup', file);
                                try {
                                    const r = await fetch('/api/restore', { method: 'POST', body: fd });
                                    const d = await r.json();
                                    if (d.ok && d.stats) {
                                        status.textContent = '✓ ' + d.stats.liked + ' liked · ' + d.stats.playlists + ' playlists · ' + d.stats.tags + ' tags';
                                        status.style.color = '#1ed760';
                                    } else { status.textContent = 'Failed: ' + (d.error || 'unknown'); status.style.color = 'var(--danger,#f55)'; }
                                } catch(_) { status.textContent = 'Network error'; }
                            });
                            </script>
                        </div>


                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Smart break</h2>
                                <p>Auto-pause after N minutes for hearing/eye health. 0 = off.</p>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px">
                                <input type="number" id="break-min" min="0" max="180" value="0" class="field-input" style="width:120px">
                                <span style="font-size:13px;color:var(--text-muted)">minutes</span>
                                <button type="button" id="break-save" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Save</button>
                            </div>
                            <script>
                            (function(){
                                if (!window.__smartBreak) return;
                                const input = document.getElementById('break-min');
                                input.value = window.__smartBreak.get();
                                document.getElementById('break-save')?.addEventListener('click', () => {
                                    window.__smartBreak.set(parseInt(input.value, 10) || 0);
                                    if (window.__showToast) window.__showToast('Smart break: ' + input.value + ' min');
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Continuous album play</h2>
                                <p>After queue ends, auto-add more tracks from the same album, then similar artists.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <input type="checkbox" id="cont-album-cb" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable</span>
                            </label>
                            <script>
                            (function(){
                                if (!window.__continuousAlbum) return;
                                const cb = document.getElementById('cont-album-cb');
                                cb.checked = window.__continuousAlbum.get();
                                cb.addEventListener('change', () => window.__continuousAlbum.set(cb.checked));
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Auto-pause when tab hidden</h2>
                                <p>Pauses music when you switch tabs. Resumes when you come back.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <input type="checkbox" id="auto-pause-cb" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable</span>
                            </label>
                            <script>
                            (function(){
                                if (!window.__autoPauseOnHidden) return;
                                const cb = document.getElementById('auto-pause-cb');
                                cb.checked = window.__autoPauseOnHidden.get();
                                cb.addEventListener('change', () => window.__autoPauseOnHidden.set(cb.checked));
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)" data-acc-section="library">
                            <div class="settings-card-head">
                                <h2>Fix missing covers</h2>
                                <p>Clear all "miss" markers so songs without covers retry remote lookup (iTunes / Deezer / Spotify) on next view.</p>
                            </div>
                            <button type="button" id="covers-repair" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                                Retry all missing covers
                            </button>
                            <span id="covers-repair-status" style="margin-left:10px;font-size:12px;color:var(--text-muted)"></span>
                            <script>
                            (function(){
                                const btn = document.getElementById('covers-repair');
                                const status = document.getElementById('covers-repair-status');
                                btn?.addEventListener('click', async () => {
                                    btn.disabled = true;
                                    status.textContent = 'Clearing miss markers…';
                                    try {
                                        const r = await fetch('/api/covers/repair-missing', { method: 'POST' });
                                        const d = await r.json();
                                        if (d.ok) {
                                            status.textContent = 'Cleared ' + d.cleared + ' miss markers · refresh covers anywhere';
                                            status.style.color = '#1ed760';
                                            if (window.__showToast) window.__showToast('Cleared ' + d.cleared + ' cover misses');
                                        } else {
                                            status.textContent = 'Failed: ' + (d.error || 'unknown');
                                            status.style.color = 'var(--danger,#f55)';
                                        }
                                    } catch (e) {
                                        status.textContent = 'Network error';
                                        status.style.color = 'var(--danger,#f55)';
                                    } finally {
                                        btn.disabled = false;
                                    }
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Library health</h2>
                                <p>Scan for broken files, missing covers, 0-duration songs.</p>
                            </div>
                            <button type="button" id="health-scan" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Run health scan</button>
                            <div id="health-results" style="margin-top:12px;font-size:13px"></div>
                            <script>
                            document.getElementById('health-scan')?.addEventListener('click', async () => {
                                const box = document.getElementById('health-results');
                                box.textContent = 'Scanning…';
                                try {
                                    const r = await fetch('/api/library/health');
                                    const d = await r.json();
                                    if (!d || d.error) { box.textContent = 'Failed: ' + (d?.error || 'unknown'); return; }
                                    box.innerHTML =
                                        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">' +
                                        '<div style="padding:12px;border:1px solid var(--border);border-radius:8px"><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Broken files</div><div style="font-size:22px;font-weight:800;color:' + (d.broken_count > 0 ? '#f55' : '#1ed760') + '">' + d.broken_count + '</div></div>' +
                                        '<div style="padding:12px;border:1px solid var(--border);border-radius:8px"><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">0-duration</div><div style="font-size:22px;font-weight:800;color:' + (d.zero_duration_count > 0 ? '#f55' : '#1ed760') + '">' + d.zero_duration_count + '</div></div>' +
                                        '<div style="padding:12px;border:1px solid var(--border);border-radius:8px"><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase">Missing covers</div><div style="font-size:22px;font-weight:800;color:' + (d.missing_covers_count > 0 ? 'var(--text-muted)' : '#1ed760') + '">' + d.missing_covers_count + '</div></div>' +
                                        '</div><div style="margin-top:8px;font-size:11px;color:var(--text-muted)">Checked ' + d.checked + ' songs</div>';
                                } catch(_) { box.textContent = 'Scan failed'; }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Storage breakdown</h2>
                                <p>Disk usage per category.</p>
                            </div>
                            <div id="storage-breakdown">Loading…</div>
                            <script>
                            (async function(){
                                const box = document.getElementById('storage-breakdown');
                                try {
                                    const r = await fetch('/api/library/storage');
                                    const d = await r.json();
                                    if (!d || d.error) { box.textContent = 'Could not load.'; return; }
                                    const fmt = (b) => b > 1024*1024*1024 ? (b/1024/1024/1024).toFixed(1)+'GB' : (b/1024/1024).toFixed(1)+'MB';
                                    const cats = ['music','covers','cache','logs','jobs','queue'];
                                    let html = '<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px">';
                                    cats.forEach(c => {
                                        const entry = d[c];
                                        if (!entry) return;
                                        const pct = d._total > 0 ? Math.round(entry.bytes / d._total * 100) : 0;
                                        html += '<div style="padding:10px;border:1px solid var(--border);border-radius:8px"><div style="display:flex;justify-content:space-between;margin-bottom:4px"><span style="font-size:12px;color:var(--text-muted);text-transform:uppercase">' + c + '</span><span style="font-size:11px;color:var(--text-muted)">' + pct + '%</span></div><div style="font-size:18px;font-weight:700;color:#fff">' + fmt(entry.bytes) + '</div><div style="font-size:11px;color:var(--text-muted)">' + entry.files + ' files</div></div>';
                                    });
                                    html += '</div>';
                                    if (d._disk_free && d._disk_total) {
                                        const usedPct = Math.round((d._disk_total - d._disk_free) / d._disk_total * 100);
                                        html += '<div style="margin-top:12px;font-size:12px;color:var(--text-muted)">Disk: ' + fmt(d._disk_total - d._disk_free) + ' used / ' + fmt(d._disk_total) + ' total (' + usedPct + '%)</div>';
                                    }
                                    box.innerHTML = html;
                                } catch(_) { box.textContent = 'Error'; }
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Saved smart rules</h2>
                                <p>Auto-refresh playlists from saved rules.</p>
                            </div>
                            <button type="button" id="sr-refresh" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Refresh all rules now</button>
                            <span id="sr-status" style="margin-left:10px;font-size:12px;color:var(--text-muted)"></span>
                            <div id="sr-list" style="margin-top:12px"></div>
                            <script>
                            (async function(){
                                const list = document.getElementById('sr-list');
                                try {
                                    const r = await fetch('/api/smart-rules/list');
                                    const d = await r.json();
                                    if (!d.rules || !d.rules.length) { list.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">No saved rules yet. Generate a smart playlist below and check "Save rules" (coming soon).</div>'; return; }
                                    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                                    list.innerHTML = d.rules.map(r =>
                                        '<div style="padding:10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px"><div style="font-weight:600">' + esc(r.name) + '</div><div style="font-size:11px;color:var(--text-muted)">refresh every ' + r.refresh_days + 'd · last run: ' + (r.last_run || 'never') + '</div></div>'
                                    ).join('');
                                } catch(_) { list.textContent = 'Failed to load rules.'; }
                            })();
                            document.getElementById('sr-refresh')?.addEventListener('click', async () => {
                                const status = document.getElementById('sr-status');
                                status.textContent = 'Refreshing…';
                                try {
                                    const r = await fetch('/api/smart-rules/refresh-all', { method: 'POST' });
                                    const d = await r.json();
                                    if (d.ok) { status.textContent = '✓ Refreshed ' + d.refreshed + ' playlist(s)'; status.style.color = '#1ed760'; if (window.__refreshSidebarPlaylists) window.__refreshSidebarPlaylists(); }
                                    else status.textContent = 'Failed: ' + (d.error || 'unknown');
                                } catch(_) { status.textContent = 'Network error'; }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Duplicate tracks</h2>
                                <p>Find tracks with same title + artist (potential duplicates).</p>
                            </div>
                            <button type="button" id="settings-dup-scan" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Scan for duplicates</button>
                            <div id="settings-dup-results" style="margin-top:12px"></div>
                            <script>
                            document.getElementById('settings-dup-scan')?.addEventListener('click', async () => {
                                const box = document.getElementById('settings-dup-results');
                                box.textContent = 'Scanning…';
                                try {
                                    const r = await fetch('/api/duplicates');
                                    const d = await r.json();
                                    if (!d.groups || !d.groups.length) { box.textContent = '✓ No duplicates found'; box.style.color = '#1ed760'; return; }
                                    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                                    box.style.color = '';
                                    box.innerHTML = '<div style="font-size:13px;color:var(--text-muted);margin-bottom:8px">' + d.groups.length + ' duplicate group(s) found:</div>' +
                                        d.groups.slice(0, 20).map(g =>
                                            '<div style="padding:8px;border:1px solid var(--border);border-radius:6px;margin-bottom:6px"><div style="font-weight:600">' + esc(g.norm_title) + ' — ' + esc(g.norm_artist) + ' <span style="color:var(--text-muted);font-weight:normal;font-size:12px">(' + g.count + ' copies)</span></div>' +
                                            '<div style="font-size:11px;color:var(--text-muted);margin-top:4px">IDs: ' + g.items.map(it => it.id + ' (' + it.duration + 's)').join(', ') + '</div></div>'
                                        ).join('');
                                } catch(_) { box.textContent = 'Scan failed'; }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Smart playlist builder</h2>
                                <p>Generate a playlist from rules.</p>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">
                                <input type="text" id="sp-name" class="field-input" placeholder="Playlist name" value="My smart mix">
                                <input type="number" id="sp-limit" class="field-input" placeholder="Limit" value="30" min="1" max="100">
                                <input type="number" id="sp-min-plays" class="field-input" placeholder="Min plays" min="0">
                                <input type="number" id="sp-max-plays" class="field-input" placeholder="Max plays" min="0">
                                <input type="number" id="sp-min-duration" class="field-input" placeholder="Min duration (sec)" min="0">
                                <input type="number" id="sp-max-duration" class="field-input" placeholder="Max duration (sec)" min="0">
                                <input type="text" id="sp-tag" class="field-input" placeholder="Tag filter (e.g. chill)">
                                <select id="sp-sort" class="field-input">
                                    <option value="random">Random</option>
                                    <option value="plays_desc">Most played</option>
                                    <option value="recent">Recently added</option>
                                    <option value="title">A-Z by title</option>
                                </select>
                            </div>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:6px">
                                <input type="checkbox" id="sp-liked-only"> Liked songs only
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:10px">
                                <input type="checkbox" id="sp-save-rule"> Save as rule (auto-refresh weekly)
                            </label>
                            <button type="button" id="sp-preview" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);margin-right:6px">Preview</button>
                            <button type="button" id="sp-generate" class="btn" style="background:rgb(var(--accent-rgb,30,215,96));color:#000;border:0">Generate</button>
                            <span id="sp-status" style="margin-left:10px;font-size:12px;color:var(--text-muted)"></span>
                            <div id="sp-preview-result" style="margin-top:12px;font-size:12px;color:var(--text-muted)"></div>
                            <script>
                            document.getElementById('sp-preview')?.addEventListener('click', async () => {
                                const body = collectBody();
                                const previewBox = document.getElementById('sp-preview-result');
                                previewBox.textContent = 'Previewing…';
                                try {
                                    const r = await fetch('/api/smart-playlist/preview', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                                    const d = await r.json();
                                    if (d.songs) {
                                        previewBox.innerHTML = '<strong>' + d.matching_count + '</strong> matching, showing first ' + d.preview_count + ':<div style="margin-top:6px">' + d.songs.slice(0, 10).map(s => '• ' + (s.title || '?') + ' — ' + (s.artist_name || '?')).join('<br>') + '</div>';
                                    } else previewBox.textContent = 'No matches.';
                                } catch(_) { previewBox.textContent = 'Preview failed.'; }
                            });
                            const collectBody = () => {
                                const b = {
                                    name: document.getElementById('sp-name').value || 'Smart playlist',
                                    limit: parseInt(document.getElementById('sp-limit').value, 10) || 30,
                                    min_plays: parseInt(document.getElementById('sp-min-plays').value, 10) || 0,
                                    max_plays: parseInt(document.getElementById('sp-max-plays').value, 10) || 0,
                                    min_duration: parseInt(document.getElementById('sp-min-duration').value, 10) || 0,
                                    max_duration: parseInt(document.getElementById('sp-max-duration').value, 10) || 0,
                                    tag: document.getElementById('sp-tag').value || '',
                                    sort: document.getElementById('sp-sort').value,
                                    liked_only: document.getElementById('sp-liked-only').checked,
                                };
                                Object.keys(b).forEach(k => { if (!b[k] && b[k] !== false) delete b[k]; });
                                return b;
                            };
                            document.getElementById('sp-generate')?.addEventListener('click', async () => {
                                const status = document.getElementById('sp-status');
                                const body = collectBody();
                                status.textContent = 'Generating…';
                                try {
                                    const r = await fetch('/api/smart-playlist/generate', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(body) });
                                    const d = await r.json();
                                    if (d.playlist_id) {
                                        status.textContent = '✓ Created: ' + d.name + ' (' + d.count + ' tracks)';
                                        status.style.color = '#1ed760';
                                        if (window.__refreshSidebarPlaylists) window.__refreshSidebarPlaylists();
                                        if (document.getElementById('sp-save-rule').checked) {
                                            await fetch('/api/smart-rules/save', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name: body.name, rules: body, refresh_days: 7 }) });
                                            if (window.__showToast) window.__showToast('Rule saved — refreshes weekly');
                                        }
                                    } else {
                                        status.textContent = 'Failed: ' + (d.error || 'unknown');
                                        status.style.color = 'var(--danger,#f55)';
                                    }
                                } catch(_) { status.textContent = 'Network error'; }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Year wrap</h2>
                                <p>Spotify Wrapped-style year recap.</p>
                            </div>
                            <a href="/wrap" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);text-decoration:none;display:inline-flex;align-items:center;gap:8px"><?= Icons::svg('star', 14) ?> View <?= date('Y') ?> wrap</a>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Export your data</h2>
                                <p>Download your listening history and liked tracks.</p>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <a href="/api/export/history.csv" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);text-decoration:none;display:inline-flex;align-items:center;gap:8px"><?= Icons::svg('chart', 14) ?> History (CSV)</a>
                                <a href="/api/export/liked.json" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);text-decoration:none;display:inline-flex;align-items:center;gap:8px">❤ Liked songs (JSON)</a>
                            </div>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Lyrics translation</h2>
                                <p>Target language for the "T" hotkey.</p>
                            </div>
                            <select id="settings-translate-lang" class="field-input" style="width:200px">
                                <?php foreach ([
                                    'en' => 'English', 'pl' => 'Polski', 'es' => 'Español', 'fr' => 'Français',
                                    'de' => 'Deutsch', 'it' => 'Italiano', 'pt' => 'Português', 'ru' => 'Русский',
                                    'ja' => '日本語', 'ko' => '한국어', 'zh' => '中文', 'uk' => 'Українська',
                                    'tr' => 'Türkçe', 'ar' => 'العربية', 'hi' => 'हिन्दी', 'nl' => 'Nederlands',
                                ] as $code => $name): ?>
                                <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <script>
                            (function(){
                                const sel = document.getElementById('settings-translate-lang');
                                try { sel.value = localStorage.getItem('doniix-translate-lang') || (navigator.language || 'en').split('-')[0]; } catch (_) {}
                                sel.addEventListener('change', () => {
                                    try { localStorage.setItem('doniix-translate-lang', sel.value); } catch (_) {}
                                    if (window.__showToast) window.__showToast('Translate target: ' + sel.options[sel.selectedIndex].text);
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Sleep timer</h2>
                                <p>Pause playback after a delay. Fades volume over the last 15 seconds.</p>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">
                                <button type="button" class="btn settings-sleep-btn" data-min="15" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">15 min</button>
                                <button type="button" class="btn settings-sleep-btn" data-min="30" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">30 min</button>
                                <button type="button" class="btn settings-sleep-btn" data-min="45" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">45 min</button>
                                <button type="button" class="btn settings-sleep-btn" data-min="60" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">1 hour</button>
                                <button type="button" class="btn settings-sleep-btn" data-min="90" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">1.5 hours</button>
                                <button type="button" id="settings-sleep-track" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">End of track</button>
                                <button type="button" id="settings-sleep-album" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">End of album</button>
                                <button type="button" id="settings-sleep-cancel" class="btn" style="background:transparent;border:1px solid var(--danger,#f55);color:var(--danger,#f55)">Cancel</button>
                            </div>
                            <div id="settings-sleep-status" style="font-size:13px;color:var(--text-muted)"></div>
                            <script>
                            (function(){
                                const status = document.getElementById('settings-sleep-status');
                                const fmt = (ms) => {
                                    if (ms <= 0) return '';
                                    const total = Math.ceil(ms / 1000);
                                    const m = Math.floor(total / 60);
                                    const s = total % 60;
                                    return m + 'm ' + String(s).padStart(2, '0') + 's remaining';
                                };
                                const refresh = () => {
                                    if (!window.__sleepTimer) { status.textContent = 'Not supported'; return; }
                                    const r = window.__sleepTimer.remaining();
                                    status.textContent = r > 0 ? fmt(r) : 'Sleep timer not active';
                                };
                                refresh();
                                const tickInterval = setInterval(refresh, 5000);
                                window.addEventListener('beforeunload', () => clearInterval(tickInterval));
                                document.querySelectorAll('.settings-sleep-btn').forEach(b => b.addEventListener('click', () => {
                                    const min = parseInt(b.dataset.min, 10);
                                    if (window.__sleepTimer && window.__sleepTimer.set(min)) {
                                        status.textContent = 'Sleep timer set for ' + min + ' min.';
                                        status.style.color = '#1ed760';
                                        setTimeout(refresh, 1000);
                                    }
                                }));
                                document.getElementById('settings-sleep-cancel')?.addEventListener('click', () => {
                                    if (window.__sleepTimer) {
                                        window.__sleepTimer.stop();
                                        status.textContent = 'Cancelled.';
                                        status.style.color = 'var(--text-muted)';
                                    }
                                });
                                document.getElementById('settings-sleep-track')?.addEventListener('click', () => {
                                    if (window.__sleepTimer && window.__sleepTimer.setEndOfTrack()) {
                                        status.textContent = 'Will pause at end of current track.';
                                        status.style.color = '#1ed760';
                                    } else {
                                        status.textContent = 'No track playing.';
                                        status.style.color = 'var(--danger,#f55)';
                                    }
                                });
                                document.getElementById('settings-sleep-album')?.addEventListener('click', () => {
                                    if (window.__sleepTimer && window.__sleepTimer.setEndOfAlbum()) {
                                        status.textContent = 'Will pause at end of current album.';
                                        status.style.color = '#1ed760';
                                    } else {
                                        status.textContent = 'No track playing.';
                                        status.style.color = 'var(--danger,#f55)';
                                    }
                                });
                            })();
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Offline tracks</h2>
                                <p>Downloaded songs play without network. Storage limit set by your browser.</p>
                            </div>
                            <div id="settings-offline-info" style="font-size:13px;color:var(--text-muted);margin-bottom:8px">Loading…</div>
                            <button type="button" id="settings-clear-offline" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px">Clear all offline tracks</button>
                            <script>
                            (function(){
                                const info = document.getElementById('settings-offline-info');
                                const refresh = async () => {
                                    if (typeof window.__listOfflineTracks !== 'function') { info.textContent = 'Not supported'; return; }
                                    const ids = await window.__listOfflineTracks();
                                    const size = await (window.__offlineStorageSize ? window.__offlineStorageSize() : Promise.resolve(null));
                                    const mb = size && size.usage ? (size.usage / 1024 / 1024).toFixed(1) : '?';
                                    info.textContent = ids.length + ' track(s) stored offline · ' + mb + ' MB used';
                                };
                                refresh();
                                document.getElementById('settings-clear-offline')?.addEventListener('click', async () => {
                                    const ids = await window.__listOfflineTracks();
                                    for (const id of ids) await window.__removeFromOffline(id);
                                    refresh();
                                });
                            })();
                            </script>
                        </div>

                        <?php $lfmKey = (string)\Doniixify\Env::get('LASTFM_API_KEY', ''); $lfmSecret = (string)\Doniixify\Env::get('LASTFM_SECRET', '') ?: (string)\Doniixify\Env::get('LASTFM_SHARED_SECRET', ''); ?>
                        <?php if ($lfmKey !== '' && $lfmSecret !== ''): ?>
                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)" id="lastfm-card" style="display:none">
                            <div class="settings-card-head">
                                <h2>Last.fm scrobble</h2>
                                <p>Connect Last.fm to auto-scrobble after 50% / 30s of playback.</p>
                            </div>
                            <div id="settings-lastfm-status" style="font-size:13px;color:var(--text-muted);margin-bottom:10px">Checking…</div>
                            <div id="settings-lastfm-actions"></div>
                            <script>
                            (async function(){
                                const card = document.getElementById('lastfm-card');
                                const status = document.getElementById('settings-lastfm-status');
                                const actions = document.getElementById('settings-lastfm-actions');
                                try {
                                    const r = await fetch('/api/lastfm/status');
                                    const d = await r.json();
                                    if (!d.has_credentials) { if (card) card.style.display = 'none'; return; }
                                    if (card) card.style.display = '';
                                    if (d.connected) {
                                        status.textContent = 'Connected as ' + d.name + ' (since ' + (d.connected_at || '?').slice(0, 10) + ')';
                                        status.style.color = '#1ed760';
                                        actions.innerHTML = '<button type="button" id="lf-disconnect" class="btn" style="background:transparent;border:1px solid var(--danger,#f55);color:var(--danger,#f55)">Disconnect</button>';
                                        document.getElementById('lf-disconnect').addEventListener('click', async () => {
                                            await fetch('/api/lastfm/disconnect', { method: 'POST' });
                                            location.reload();
                                        });
                                    } else {
                                        status.textContent = 'Not connected.';
                                        actions.innerHTML = '<a href="/api/lastfm/auth" class="btn" style="background:#d51007;color:#fff;border:0;text-decoration:none;display:inline-flex;align-items:center;gap:8px">Connect Last.fm</a>';
                                    }
                                } catch(_) { if (card) card.style.display = 'none'; }
                            })();
                            </script>
                        </div>
                        <?php endif; ?>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Lyrics cache</h2>
                                <p>If lyrics show "No lyrics for this track" but you know they exist, clear the cached negative result for the currently playing song.</p>
                            </div>
                            <button type="button" id="settings-clear-lyrics" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                Clear cache for current track
                            </button>
                            <div id="settings-clear-lyrics-status" style="margin-top:8px;font-size:12px;color:var(--text-muted)"></div>
                            <script>
                            document.getElementById('settings-clear-lyrics')?.addEventListener('click', async function() {
                                const meta = window.__currentTrackMeta || {};
                                const title = meta.title || document.getElementById('pb-title')?.textContent?.trim() || '';
                                const artist = (meta.artist || document.getElementById('pb-artist')?.textContent || '').split(/\s+·\s+on\s+/i)[0].split(/\s+·\s+/)[0].trim();
                                const status = document.getElementById('settings-clear-lyrics-status');
                                if (!title || title === '—' || !artist) {
                                    status.textContent = 'No track playing.';
                                    status.style.color = 'var(--danger,#f55)';
                                    return;
                                }
                                status.textContent = 'Clearing…';
                                status.style.color = 'var(--text-muted)';
                                try {
                                    const fd = new FormData();
                                    fd.append('artist', artist);
                                    fd.append('title', title);
                                    const r = await fetch('/api/lyrics/clear', { method: 'POST', body: fd });
                                    const d = await r.json();
                                    if (d.cleared) {
                                        status.textContent = 'Cleared: ' + title + ' — ' + artist + '. Reload the lyrics view.';
                                        status.style.color = '#1ed760';
                                    } else {
                                        status.textContent = 'Failed: ' + (d.error || 'unknown');
                                        status.style.color = 'var(--danger,#f55)';
                                    }
                                } catch(e) {
                                    status.textContent = 'Network error.';
                                    status.style.color = 'var(--danger,#f55)';
                                }
                            });
                            </script>
                        </div>

                        <div class="settings-card" style="background:transparent;border:1px solid var(--border)">
                            <div class="settings-card-head">
                                <h2>Sign out</h2>
                                <p>End your session on this device. You'll need to log in again with your username and password.</p>
                            </div>
                            <a href="/logout" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);display:inline-flex;align-items:center;gap:8px;text-decoration:none">
                                <?= Icons::svg('logout', 16) ?> Sign out (<?= $username ?>)
                            </a>
                        </div>
                    </section>

                    <?php if ($isAdmin): ?>
                    <!-- LIBRARY -->
                    <section class="settings-section" data-tab-content="library">
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Music folder</h2>
                                <p>The directory scanned for audio files.</p>
                            </div>
                            <div class="kv-row">
                                <div class="kv-key">Path</div>
                                <div class="kv-val"><code><?= htmlspecialchars($musicPath) ?></code></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-key">Status</div>
                                <div class="kv-val">
                                    <?php if ($pathOk): ?>
                                        <span class="status-pill ok">● Ready</span>
                                    <?php else: ?>
                                        <span class="status-pill err">● Unreachable</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="settings-form-actions" style="margin-top:18px">
                                <form method="post" action="/scan" style="margin:0">
                                    <button type="submit" class="btn" <?= !$pathOk ? 'disabled' : '' ?>><?= Icons::svg('disc', 16) ?> Scan new files</button>
                                </form>
                                <form method="post" action="/scan" style="margin:0" data-confirm="A full re-scan will drop the entire library and re-import everything from scratch." data-confirm-title="Full re-scan" data-confirm-ok="Re-scan" data-confirm-danger="1">
                                    <input type="hidden" name="mode" value="full">
                                    <button type="submit" class="btn btn-secondary" <?= !$pathOk ? 'disabled' : '' ?>>Full re-scan</button>
                                </form>
                                <form method="post" action="/settings/refresh-durations" style="margin:0" data-confirm="Re-probe every track length using ffprobe. Recommended if some tracks show wrong duration." data-confirm-title="Refresh durations" data-confirm-ok="Refresh">
                                    <button type="submit" class="btn btn-secondary" <?= !$pathOk ? 'disabled' : '' ?>>Refresh durations</button>
                                </form>
                            </div>
                        </div>

                        <?php if ($lastScan !== null): ?>
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Last scan</h2>
                            </div>
                            <div class="kv-row"><div class="kv-key">Status</div><div class="kv-val"><span class="status-pill <?= self::statusClass($lastScan['status']) ?>">● <?= htmlspecialchars($lastScan['status']) ?></span></div></div>
                            <div class="kv-row"><div class="kv-key">Started</div><div class="kv-val mono"><?= htmlspecialchars($lastScan['started_at']) ?></div></div>
                            <div class="kv-row"><div class="kv-key">Finished</div><div class="kv-val mono"><?= htmlspecialchars($lastScan['finished_at'] ?? '—') ?></div></div>
                            <div class="kv-row"><div class="kv-key">Files scanned</div><div class="kv-val"><?= (int)$lastScan['files_scanned'] ?></div></div>
                            <div class="kv-row"><div class="kv-key">Added</div><div class="kv-val" style="color:var(--success)">+<?= (int)$lastScan['files_added'] ?></div></div>
                            <div class="kv-row"><div class="kv-key">Removed</div><div class="kv-val" style="color:var(--danger)">−<?= (int)$lastScan['files_removed'] ?></div></div>
                            <?php if (!empty($lastScan['error_message'])): ?>
                                <pre class="error-pre"><?= htmlspecialchars($lastScan['error_message']) ?></pre>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Cover art cache</h2>
                                <p><?= $coverCount ?> file<?= $coverCount === 1 ? '' : 's' ?> stored in <code><?= htmlspecialchars($coverDir) ?></code></p>
                            </div>
                            <div class="kv-row">
                                <div class="kv-key">Status</div>
                                <div class="kv-val">
                                    <?php if (!$coverDirExists): ?>
                                        <span class="status-pill err">✗ Directory missing</span>
                                    <?php elseif (!$coverDirWritable): ?>
                                        <span class="status-pill err">✗ Not writable — run <code>chown -R www:www <?= htmlspecialchars($coverDir) ?></code></span>
                                    <?php else: ?>
                                        <span class="status-pill ok">● Writable</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="muted" style="font-size:12px;margin:8px 0 12px">Missing cover art is fetched on-demand from iTunes Search API and Deezer (free, no auth needed) when you open the library.</p>
                            <form method="post" action="/settings/clear-covers" data-confirm="Delete all cached cover art? They will be regenerated on next scan." data-confirm-title="Clear cover cache" data-confirm-ok="Clear" data-confirm-danger="1">
                                <button type="submit" class="btn btn-secondary">Clear cache</button>
                            </form>
                        </div>

                        <?php if ($isAdmin): ?>
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Search cache</h2>
                                <p>Cached Spotify/Deezer/iTunes search results. Clear if results look outdated (e.g. missing artist that should appear).</p>
                            </div>
                            <div class="kv-row">
                                <div class="kv-key">File cache</div>
                                <div class="kv-val mono"><?= number_format((int)(@count(@glob(__DIR__ . '/../../storage/cache/*.json') ?: []))) ?> entries</div>
                            </div>
                            <div class="settings-form-actions" style="margin-top:14px;gap:10px">
                                <button type="button" id="clear-search-cache-btn" class="btn btn-secondary">Clear search cache</button>
                                <span id="clear-search-cache-status" class="muted" style="font-size:13px"></span>
                            </div>
                            <script>
                            document.getElementById('clear-search-cache-btn')?.addEventListener('click', async () => {
                                const btn = document.getElementById('clear-search-cache-btn');
                                const status = document.getElementById('clear-search-cache-status');
                                btn.disabled = true;
                                status.textContent = 'Clearing…';
                                try {
                                    const r = await fetch('/api/admin/clear-search-cache', { method: 'POST' });
                                    const d = await r.json();
                                    if (r.ok && d.ok) {
                                        status.textContent = 'Cleared ' + (d.removed_files || 0) + ' file(s). Refresh /search now.';
                                        status.style.color = 'var(--success, #3ddc84)';
                                    } else {
                                        status.textContent = 'Failed: ' + (d.error || 'unknown');
                                        status.style.color = 'var(--danger, #ef4444)';
                                    }
                                } catch (e) {
                                    status.textContent = 'Network error: ' + e.message;
                                    status.style.color = 'var(--danger, #ef4444)';
                                }
                                btn.disabled = false;
                            });
                            </script>
                        </div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                    <!-- USERS -->
                    <section class="settings-section" data-tab-content="users">
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Add user</h2>
                                <p>New users will be able to sign in, favorite tracks and create playlists.</p>
                            </div>
                            <form method="post" action="/users/create" class="user-add-form">
                                <div class="settings-field"><label>Username</label><input type="text" name="username" required minlength="2" autocomplete="off"></div>
                                <div class="settings-field"><label>Password</label><input type="password" name="password" required minlength="6" autocomplete="new-password"></div>
                                <label class="checkbox-field"><input type="checkbox" name="is_admin" value="1"> Admin</label>
                                <button type="submit" class="btn">Create user</button>
                            </form>
                        </div>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Users <span class="head-counter"><?= count($users) ?></span></h2>
                            </div>
                            <div class="users-list">
                                <?php foreach ($users as $u):
                                    $isMe = (int)$u['id'] === (int)$user['id'];
                                    $uInitial = strtoupper(mb_substr($u['username'], 0, 1, 'UTF-8'));
                                ?>
                                    <div class="user-row">
                                        <div class="user-avatar"><?= htmlspecialchars($uInitial) ?></div>
                                        <div class="user-row-meta">
                                            <div class="user-row-name">
                                                <?= htmlspecialchars($u['username']) ?>
                                                <span class="badge <?= $u['is_admin'] ? 'badge-admin' : 'badge-user' ?>"><?= $u['is_admin'] ? 'Admin' : 'User' ?></span>
                                                <?php if ($isMe): ?><span class="badge badge-you">You</span><?php endif; ?>
                                            </div>
                                            <div class="user-row-sub">
                                                <?= (int)$u['fav_count'] ?> favorite<?= (int)$u['fav_count'] === 1 ? '' : 's' ?>
                                                · last seen <?= htmlspecialchars($u['last_login_at'] ?? 'never') ?>
                                            </div>
                                        </div>
                                        <div class="user-row-actions">
                                            <button type="button" class="btn btn-ghost" data-user-stats="<?= (int)$u['id'] ?>">Stats</button>
                                            <form method="post" action="/users/<?= (int)$u['id'] ?>/password" style="margin:0" onsubmit="this.querySelector('input').value=prompt('New password for <?= htmlspecialchars($u['username'], ENT_QUOTES) ?> (min 6 chars):');return !!this.querySelector('input').value">
                                                <input type="hidden" name="password" value="">
                                                <button type="submit" class="btn btn-ghost">Reset password</button>
                                            </form>
                                            <?php if (!$isMe): ?>
                                                <form method="post" action="/users/<?= (int)$u['id'] ?>/toggle-admin" style="margin:0">
                                                    <button type="submit" class="btn btn-ghost"><?= $u['is_admin'] ? 'Demote' : 'Promote' ?></button>
                                                </form>
                                                <form method="post" action="/users/<?= (int)$u['id'] ?>/delete" style="margin:0" data-confirm="Delete user <?= htmlspecialchars($u['username'], ENT_QUOTES) ?>? This cannot be undone." data-confirm-title="Delete user" data-confirm-ok="Delete" data-confirm-danger="1">
                                                    <button type="submit" class="btn btn-ghost danger">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div id="user-stats-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,0.65);backdrop-filter:blur(10px);align-items:center;justify-content:center;padding:24px">
                            <div style="background:var(--bg-elevated,#1a1a1f);border:1px solid var(--border);border-radius:12px;max-width:560px;width:100%;max-height:80vh;overflow-y:auto;padding:24px">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
                                    <h2 id="user-stats-title" style="margin:0;font-size:18px;font-weight:700">User stats</h2>
                                    <button id="user-stats-close" class="btn btn-ghost" style="padding:6px 10px">×</button>
                                </div>
                                <div id="user-stats-body" style="color:var(--text-primary);font-size:14px">Loading…</div>
                            </div>
                        </div>
                        <script>
                        (function () {
                            const modal = document.getElementById('user-stats-modal');
                            const body = document.getElementById('user-stats-body');
                            const title = document.getElementById('user-stats-title');
                            const closeBtn = document.getElementById('user-stats-close');
                            const fmtDate = (d) => d ? new Date(d.replace(' ', 'T')).toLocaleString() : 'never';
                            const row = (label, value) => `<div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.05)"><span style="color:var(--text-secondary)">${label}</span><span style="font-weight:600;color:var(--text-primary)">${value}</span></div>`;
                            const open = async (uid) => {
                                modal.style.display = 'flex';
                                body.innerHTML = 'Loading…';
                                title.textContent = 'User stats';
                                try {
                                    const r = await fetch(`/api/admin/users/${uid}/stats`);
                                    if (!r.ok) { body.innerHTML = '<div style="color:#f88">Failed to load</div>'; return; }
                                    const s = await r.json();
                                    title.textContent = `${s.username} — stats`;
                                    const onlineDot = s.online ? '<span style="color:#1db954">● online</span>' : '<span style="color:rgba(255,255,255,0.5)">○ offline</span>';
                                    const lastTrack = s.last_song_title ? `${s.last_song_title} — ${s.last_song_artist || ''}` : '—';
                                    body.innerHTML = `
                                        <div style="margin-bottom:18px;padding:14px;background:transparent;border:1px solid var(--border);border-radius:8px">
                                            <div style="font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px">Activity</div>
                                            ${row('Status', onlineDot)}
                                            ${row('Last seen', fmtDate(s.last_seen))}
                                            ${row('Last login', fmtDate(s.last_login))}
                                            ${row('Last played', lastTrack)}
                                        </div>
                                        <div style="margin-bottom:18px;padding:14px;background:transparent;border:1px solid var(--border);border-radius:8px">
                                            <div style="font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px">Totals</div>
                                            ${row('Listening time', s.listening_hours + ' h')}
                                            ${row('Songs in library', s.songs_in_library)}
                                            ${row('Favorites', s.favorites)}
                                            ${row('Playlists', s.playlists)}
                                        </div>
                                        <div style="padding:14px;background:transparent;border:1px solid var(--border);border-radius:8px">
                                            <div style="font-size:13px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px">Periods</div>
                                            <div style="color:var(--text-secondary);font-size:13px;line-height:1.5">${s.note || ''}</div>
                                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:12px">
                                                <div style="text-align:center;padding:10px;background:transparent;border:1px solid var(--border);border-radius:6px"><div style="font-size:11px;color:var(--text-secondary)">Week</div><div style="font-weight:700;margin-top:4px">${s.period_week.hours} h · ${s.period_week.songs}</div></div>
                                                <div style="text-align:center;padding:10px;background:transparent;border:1px solid var(--border);border-radius:6px"><div style="font-size:11px;color:var(--text-secondary)">Month</div><div style="font-weight:700;margin-top:4px">${s.period_month.hours} h · ${s.period_month.songs}</div></div>
                                                <div style="text-align:center;padding:10px;background:transparent;border:1px solid var(--border);border-radius:6px"><div style="font-size:11px;color:var(--text-secondary)">Year</div><div style="font-weight:700;margin-top:4px">${s.period_year.hours} h · ${s.period_year.songs}</div></div>
                                            </div>
                                        </div>
                                    `;
                                } catch (e) { body.innerHTML = '<div style="color:#f88">Network error: ' + e.message + '</div>'; }
                            };
                            document.querySelectorAll('[data-user-stats]').forEach(btn => {
                                btn.addEventListener('click', () => open(btn.dataset.userStats));
                            });
                            closeBtn?.addEventListener('click', () => { modal.style.display = 'none'; });
                            modal?.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
                        })();
                        </script>
                    </section>
                    <?php endif; ?>

                    <!-- AUDIO -->
                    <section class="settings-section" data-tab-content="audio">
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Volume normalization</h2>
                                <p>Auto-adjust loudness between tracks so quiet songs and loud songs sound similar.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <input type="checkbox" id="audio-normalize" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable normalization (uses DynamicsCompressor)</span>
                            </label>
                        </div>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Automix / Crossfade</h2>
                                <p>Smooth transition between tracks. 0.5s = quick fade, 10s = long DJ-style mix.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:14px">
                                <input type="checkbox" id="audio-automix" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable automix</span>
                            </label>
                            <div style="display:flex;align-items:center;gap:14px">
                                <input type="range" id="audio-crossfade" min="0.5" max="10" value="3" step="0.5" style="flex:1">
                                <span id="audio-crossfade-value" style="min-width:60px;text-align:right;font-family:'JetBrains Mono',monospace;font-size:14px">3.0s</span>
                            </div>
                        </div>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Equalizer</h2>
                                <p>5-band parametric EQ. Drag sliders ±12 dB per band. Toggle off to bypass entirely (audio goes straight to output).</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:16px">
                                <input type="checkbox" id="audio-eq-enabled" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable equalizer</span>
                            </label>
                            <div id="eq-sliders" style="display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-top:8px">
                                <?php foreach (['60Hz' => 60, '250Hz' => 250, '1kHz' => 1000, '4kHz' => 4000, '12kHz' => 12000] as $label => $freq): ?>
                                    <div style="display:flex;flex-direction:column;align-items:center;gap:8px">
                                        <span style="font-size:11px;color:rgba(255,255,255,0.5);font-family:'JetBrains Mono',monospace" id="eq-val-<?= $freq ?>">0 dB</span>
                                        <input type="range" min="-12" max="12" value="0" step="0.5" class="eq-slider" data-freq="<?= $freq ?>" style="writing-mode:vertical-lr;direction:rtl;width:24px;height:140px">
                                        <span style="font-size:11px;font-weight:600"><?= $label ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                                <button class="btn btn-secondary eq-preset" data-preset="flat" style="font-size:12px;padding:6px 12px">Flat</button>
                                <button class="btn btn-secondary eq-preset" data-preset="bass" style="font-size:12px;padding:6px 12px">Bass boost</button>
                                <button class="btn btn-secondary eq-preset" data-preset="treble" style="font-size:12px;padding:6px 12px">Treble boost</button>
                                <button class="btn btn-secondary eq-preset" data-preset="vocal" style="font-size:12px;padding:6px 12px">Vocal</button>
                                <button class="btn btn-secondary eq-preset" data-preset="rock" style="font-size:12px;padding:6px 12px">Rock</button>
                                <button class="btn btn-secondary eq-preset" data-preset="electronic" style="font-size:12px;padding:6px 12px">Electronic</button>
                            </div>
                        </div>

                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Screensaver</h2>
                                <p>After 5s of inactivity (while music is playing) show a full-screen Geiss/Milkdrop-style audio visualizer.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                                <input type="checkbox" id="screensaver-enabled" style="width:18px;height:18px;cursor:pointer">
                                <span>Enable screensaver</span>
                            </label>
                            <div style="margin-top:14px;display:flex;align-items:center;gap:14px">
                                <label for="screensaver-idle" style="min-width:120px;font-size:13px;color:var(--text-secondary)">Idle delay</label>
                                <input type="range" id="screensaver-idle" min="5" max="600" step="5" value="5" style="flex:1">
                                <input type="number" id="screensaver-idle-num" min="5" max="3600" step="5" value="5" style="width:70px;background:transparent;border:1px solid var(--border);color:var(--text-primary);padding:6px 8px;border-radius:6px;font-size:13px;outline:none">
                                <span style="font-size:12px;color:var(--text-muted);min-width:24px">s</span>
                            </div>
                        </div>
                        <script>
                        (function () {
                            const __uid = (window.__userId || 0);
                            const K = 'u' + __uid + ':doniix-audio-settings';
                            const defaults = {
                                quality: '192', volume: 80, normalize: false,
                                automix: false, crossfade: 3,
                                eq_enabled: false,
                                eq: { 60: 0, 250: 0, 1000: 0, 4000: 0, 12000: 0 }
                            };
                            let s = defaults;
                            try { s = Object.assign({}, defaults, JSON.parse(localStorage.getItem(K) || '{}')); } catch (e) {}
                            const save = () => { try { localStorage.setItem(K, JSON.stringify(s)); window.dispatchEvent(new CustomEvent('audio-settings-changed', { detail: s })); } catch (e) {} };

                            const qEl = document.getElementById('audio-quality');
                            if (qEl) {
                                qEl.value = String(s.quality);
                                qEl.addEventListener('change', () => { s.quality = qEl.value; save(); });
                            }

                            const nEl = document.getElementById('audio-normalize');
                            nEl.checked = !!s.normalize;
                            nEl.addEventListener('change', () => { s.normalize = nEl.checked; save(); });

                            const aEl = document.getElementById('audio-automix');
                            aEl.checked = !!s.automix;
                            aEl.addEventListener('change', () => { s.automix = aEl.checked; save(); });

                            const cEl = document.getElementById('audio-crossfade');
                            const cLabel = document.getElementById('audio-crossfade-value');
                            cEl.value = s.crossfade;
                            cLabel.textContent = (+s.crossfade).toFixed(1) + 's';
                            cEl.addEventListener('input', () => { s.crossfade = +cEl.value; cLabel.textContent = s.crossfade.toFixed(1) + 's'; save(); });

                            const eqToggle = document.getElementById('audio-eq-enabled');
                            const eqWrap = document.getElementById('eq-sliders');
                            const applyEqEnabledUi = () => {
                                eqWrap.style.opacity = s.eq_enabled ? '1' : '0.4';
                                eqWrap.style.pointerEvents = s.eq_enabled ? 'auto' : 'none';
                            };
                            eqToggle.checked = !!s.eq_enabled;
                            applyEqEnabledUi();
                            eqToggle.addEventListener('change', () => {
                                s.eq_enabled = eqToggle.checked;
                                applyEqEnabledUi();
                                save();
                            });

                            document.querySelectorAll('.eq-slider').forEach(slider => {
                                const f = +slider.dataset.freq;
                                slider.value = s.eq[f] || 0;
                                document.getElementById('eq-val-' + f).textContent = (s.eq[f] || 0) + ' dB';
                                slider.addEventListener('input', () => {
                                    s.eq[f] = +slider.value;
                                    document.getElementById('eq-val-' + f).textContent = slider.value + ' dB';
                                    save();
                                });
                            });

                            const presets = {
                                flat: { 60: 0, 250: 0, 1000: 0, 4000: 0, 12000: 0 },
                                bass: { 60: 6, 250: 3, 1000: 0, 4000: 0, 12000: 0 },
                                treble: { 60: 0, 250: 0, 1000: 0, 4000: 3, 12000: 6 },
                                vocal: { 60: -3, 250: 0, 1000: 4, 4000: 3, 12000: 0 },
                                rock: { 60: 4, 250: 2, 1000: -2, 4000: 3, 12000: 5 },
                                electronic: { 60: 5, 250: 0, 1000: -2, 4000: 1, 12000: 4 }
                            };
                            document.querySelectorAll('.eq-preset').forEach(btn => {
                                btn.addEventListener('click', () => {
                                    const p = presets[btn.dataset.preset];
                                    if (!p) return;
                                    s.eq = Object.assign({}, p);
                                    document.querySelectorAll('.eq-slider').forEach(slider => {
                                        const f = +slider.dataset.freq;
                                        slider.value = s.eq[f];
                                        document.getElementById('eq-val-' + f).textContent = s.eq[f] + ' dB';
                                    });
                                    save();
                                });
                            });
                        })();
                        </script>
                    </section>

                    <?php if ($isAdmin): ?>
                    <!-- DOWNLOADS -->
                    <section class="settings-section" data-tab-content="downloads">
                        <div class="settings-card">
                            <div class="settings-card-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
                                <div>
                                    <h2>Recent downloads</h2>
                                    <p>Last 30 background download jobs from Doniixify. Auto-refreshing every 2 seconds.</p>
                                </div>
                                <button type="button" id="downloads-clear-btn" class="btn btn-secondary" style="flex-shrink:0">Clear</button>
                            </div>
                            <div id="downloads-list">
                                <?php if (empty($downloadJobs)): ?>
                                    <div style="padding:32px;text-align:center;color:rgba(255,255,255,0.5)">No downloads yet. Search and hit Download on a track to start.</div>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:8px">
                                    <?php foreach ($downloadJobs as $j):
                                        $status = $j['status'] ?? 'unknown';
                                        $progress = (int)($j['progress'] ?? 0);
                                        $color = $status === 'done' ? '#00ba7c' : ($status === 'failed' ? '#f4212e' : '#1d9bf0');
                                        $label = match($status) {
                                            'done' => 'Done',
                                            'failed' => 'Failed',
                                            'starting' => 'Starting',
                                            'downloading' => 'Downloading',
                                            default => ucfirst($status),
                                        };
                                    ?>
                                        <div style="padding:14px 16px;background:transparent;border-radius:10px;border:1px solid var(--border)">
                                            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:8px">
                                                <div style="flex:1;min-width:0">
                                                    <div style="font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($j['title'] ?? '—') ?></div>
                                                    <div style="font-size:13px;color:rgba(255,255,255,0.55);margin-top:2px"><?= htmlspecialchars($j['artist'] ?? '') ?><?php if (!empty($j['album'])): ?> · <?= htmlspecialchars($j['album']) ?><?php endif; ?></div>
                                                </div>
                                                <span style="display:inline-block;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;background:<?= $color ?>22;color:<?= $color ?>;border:1px solid <?= $color ?>40;white-space:nowrap"><?= $label ?></span>
                                            </div>
                                            <?php if ($status === 'downloading' || $status === 'starting'): ?>
                                                <div style="height:4px;background:var(--bg-card);border-radius:2px;overflow:hidden;margin-top:8px">
                                                    <div style="height:100%;width:<?= $progress ?>%;background:linear-gradient(90deg,#1971c2,#7c3aed);transition:width 0.4s ease"></div>
                                                </div>
                                                <div style="display:flex;justify-content:space-between;margin-top:4px;font-size:11px;color:rgba(255,255,255,0.5)">
                                                    <span><?= htmlspecialchars($j['stage'] ?? '') ?></span><span><?= $progress ?>%</span>
                                                </div>
                                            <?php elseif ($status === 'failed' && !empty($j['error'])): ?>
                                                <div style="margin-top:8px;padding:8px 10px;background:transparent;border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-primary);font-family:'JetBrains Mono',monospace"><?= htmlspecialchars($j['error']) ?></div>
                                            <?php endif; ?>
                                            <div style="margin-top:6px;font-size:11px;color:rgba(255,255,255,0.4);font-family:'JetBrains Mono',monospace">PID <?= htmlspecialchars((string)($j['pid'] ?? '')) ?> · started <?= htmlspecialchars($j['started_at'] ?? '') ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <script>
                        (function() {
                            const listEl = document.getElementById('downloads-list');
                            if (!listEl) return;
                            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                            const renderJob = (j) => {
                                const status = j.status || 'unknown';
                                const progress = Math.max(0, Math.min(100, +j.progress || 0));
                                const colors = {done:'#00ba7c', failed:'#f4212e', starting:'#1d9bf0', downloading:'#1d9bf0'};
                                const c = colors[status] || '#1d9bf0';
                                const labels = {done:'Done', failed:'Failed', starting:'Starting', downloading:'Downloading'};
                                const label = labels[status] || status;
                                const isActive = status === 'downloading' || status === 'starting';
                                let progressHtml = '';
                                if (isActive) {
                                    progressHtml = `<div style="height:4px;background:var(--bg-card);border-radius:2px;overflow:hidden;margin-top:8px"><div style="height:100%;width:${progress}%;background:linear-gradient(90deg,#1971c2,#7c3aed);transition:width 0.4s ease"></div></div><div style="display:flex;justify-content:space-between;margin-top:4px;font-size:11px;color:rgba(255,255,255,0.5)"><span>${esc(j.stage || '')}</span><span>${progress}%</span></div>`;
                                } else if (status === 'failed' && j.error) {
                                    progressHtml = `<div style="margin-top:8px;padding:8px 10px;background:transparent;border:1px solid var(--border);border-radius:6px;font-size:12px;color:var(--text-primary);font-family:'JetBrains Mono',monospace">${esc(j.error)}</div>`;
                                }
                                return `<div style="padding:14px 16px;background:transparent;border-radius:10px;border:1px solid var(--border)"><div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:8px"><div style="flex:1;min-width:0"><div style="font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(j.title || '—')}</div><div style="font-size:13px;color:rgba(255,255,255,0.55);margin-top:2px">${esc(j.artist || '')}${j.album ? ' · ' + esc(j.album) : ''}</div></div><span style="display:inline-block;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;background:${c}22;color:${c};border:1px solid ${c}40;white-space:nowrap">${label}</span></div>${progressHtml}<div style="margin-top:6px;font-size:11px;color:rgba(255,255,255,0.4);font-family:'JetBrains Mono',monospace">PID ${esc(j.pid || '')} · started ${esc(j.started_at || '')}</div></div>`;
                            };
                            const refresh = async () => {
                                try {
                                    const res = await fetch('/api/downloads/status', { cache: 'no-store' });
                                    if (!res.ok) return;
                                    const data = await res.json();
                                    const all = (data.active || []).concat(data.recent || []);
                                    if (all.length === 0) {
                                        listEl.innerHTML = '<div style="padding:32px;text-align:center;color:rgba(255,255,255,0.5)">No downloads yet. Search and hit Download on a track to start.</div>';
                                        return;
                                    }
                                    listEl.innerHTML = '<div style="display:flex;flex-direction:column;gap:8px">' + all.map(renderJob).join('') + '</div>';
                                } catch (e) {}
                            };
                            setInterval(refresh, 2000);
                            const clearBtn = document.getElementById('downloads-clear-btn');
                            clearBtn?.addEventListener('click', async () => {
                                clearBtn.disabled = true;
                                const orig = clearBtn.textContent;
                                clearBtn.textContent = 'Clearing…';
                                try { await fetch('/api/downloads/clear', { method: 'POST' }); } catch (e) {}
                                clearBtn.disabled = false;
                                clearBtn.textContent = orig;
                                refresh();
                            });
                        })();
                        </script>
                    </section>
                    <?php endif; ?>

                    <!-- APPS -->
                    <?php if ($showApps): ?>
                    <section class="settings-section" data-tab-content="apps">
                        <div class="settings-card">
                            <div class="settings-card-head">
                                <h2>Install Doniixify</h2>
                                <p>Use the app on every device. Same library, same playback queue, instant sync.</p>
                            </div>
                            <?php
                                $buildsDir = realpath(__DIR__ . '/../../storage/builds');
                                $apkFile = $buildsDir ? glob($buildsDir . '/*.apk') : [];
                                $exeFile = $buildsDir ? glob($buildsDir . '/*.exe') : [];
                                $ipaFile = $buildsDir ? glob($buildsDir . '/*.ipa') : [];
                                $apk = !empty($apkFile) ? basename($apkFile[0]) : null;
                                $exe = !empty($exeFile) ? basename($exeFile[0]) : null;
                                $ipa = !empty($ipaFile) ? basename($ipaFile[0]) : null;
                                $apkSize = $apk ? @filesize($apkFile[0]) : 0;
                                $exeSize = $exe ? @filesize($exeFile[0]) : 0;
                                $ipaSize = $ipa ? @filesize($ipaFile[0]) : 0;
                                $fmt = fn($b) => $b < 1024*1024 ? round($b/1024) . ' KB' : round($b/1024/1024, 1) . ' MB';
                            ?>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:8px">
                                <div style="padding:20px;background:transparent;border:1px solid var(--border);border-radius:12px">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                                        <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#0078d4,#005a9e);display:flex;align-items:center;justify-content:center"><?= Icons::svg('monitor', 22) ?></div>
                                        <div>
                                            <div style="font-weight:700;font-size:15px">Windows</div>
                                            <div style="font-size:12px;color:rgba(255,255,255,0.5)">Desktop app · .exe installer</div>
                                        </div>
                                    </div>
                                    <?php if ($exe): ?>
                                        <a href="/downloads/<?= htmlspecialchars($exe) ?>" download class="btn" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:linear-gradient(135deg,#1971c2,#7c3aed);border:0;border-radius:8px;color:#fff;font-weight:600;text-decoration:none;font-size:14px">
                                            <?= Icons::svg('download', 16) ?> Download (<?= $fmt($exeSize) ?>)
                                        </a>
                                    <?php else: ?>
                                        <div style="padding:12px;background:transparent;border:1px dashed var(--border);border-radius:8px;font-size:12px;color:rgba(255,255,255,0.5);text-align:center">Not available — download Doniixify now from the Releases page</div>
                                    <?php endif; ?>
                                </div>

                                <div style="padding:20px;background:transparent;border:1px solid var(--border);border-radius:12px">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                                        <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#3ddc84,#1ba85a);display:flex;align-items:center;justify-content:center"><?= Icons::svg('phone', 22) ?></div>
                                        <div>
                                            <div style="font-weight:700;font-size:15px">Android</div>
                                            <div style="font-size:12px;color:rgba(255,255,255,0.5)">Native app · .apk file</div>
                                        </div>
                                    </div>
                                    <?php if ($apk): ?>
                                        <a href="/downloads/<?= htmlspecialchars($apk) ?>" download class="btn" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:linear-gradient(135deg,#3ddc84,#1ba85a);border:0;border-radius:8px;color:#fff;font-weight:600;text-decoration:none;font-size:14px">
                                            <?= Icons::svg('download', 16) ?> Download (<?= $fmt($apkSize) ?>)
                                        </a>
                                        <p style="margin-top:10px;font-size:12px;color:rgba(255,255,255,0.5)">Enable "Install unknown apps" in Android settings before installing.</p>
                                    <?php else: ?>
                                        <div style="padding:12px;background:transparent;border:1px dashed var(--border);border-radius:8px;font-size:12px;color:rgba(255,255,255,0.5);text-align:center">Not available — download Doniixify now from the Releases page</div>
                                    <?php endif; ?>
                                </div>

                                <div style="padding:20px;background:transparent;border:1px solid var(--border);border-radius:12px">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                                        <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#a8a8b3,#3a3a45);display:flex;align-items:center;justify-content:center"><?= Icons::svg('phone', 22) ?></div>
                                        <div>
                                            <div style="font-weight:700;font-size:15px">iOS</div>
                                            <div style="font-size:12px;color:rgba(255,255,255,0.5)">iPhone / iPad · .ipa file</div>
                                        </div>
                                    </div>
                                    <?php if ($ipa): ?>
                                        <a href="/downloads/<?= htmlspecialchars($ipa) ?>" download class="btn" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:10px;background:transparent;border:1px solid var(--border);border-radius:8px;color:var(--text-primary);font-weight:600;text-decoration:none;font-size:14px">
                                            <?= Icons::svg('download', 16) ?> Download (<?= $fmt($ipaSize) ?>)
                                        </a>
                                        <p style="margin-top:10px;font-size:12px;color:rgba(255,255,255,0.5)">Sideload via AltStore, Sideloadly or self-signed via Xcode (free Apple ID, 7-day cert).</p>
                                    <?php else: ?>
                                        <div style="padding:12px;background:transparent;border:1px dashed var(--border);border-radius:8px;font-size:12px;color:rgba(255,255,255,0.5);text-align:center">Not available — drop a .ipa file in <code>storage/builds/</code> to enable</div>
                                    <?php endif; ?>
                                </div>

                                <div style="padding:20px;background:transparent;border:1px solid var(--border);border-radius:12px">
                                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                                        <div style="width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#7c3aed,#4c1d95);display:flex;align-items:center;justify-content:center"><?= Icons::svg('disc', 22) ?></div>
                                        <div>
                                            <div style="font-weight:700;font-size:15px">PWA</div>
                                            <div style="font-size:12px;color:rgba(255,255,255,0.5)">Browser install (any platform)</div>
                                        </div>
                                    </div>
                                    <button id="install-pwa-btn" class="btn" style="width:100%;padding:10px;background:linear-gradient(135deg,#7c3aed,#4c1d95);border:0;border-radius:8px;color:#fff;font-weight:600;cursor:pointer;font-size:14px">Install via browser</button>
                                    <p style="margin-top:10px;font-size:12px;color:rgba(255,255,255,0.5)">Chrome/Edge desktop, Android Chrome, iOS Safari → "Add to Home Screen".</p>
                                </div>
                            </div>
                        </div>
                        <script>
                        (function(){
                            let deferredPrompt = null;
                            window.addEventListener('beforeinstallprompt', (e) => {
                                e.preventDefault();
                                deferredPrompt = e;
                            });
                            const btn = document.getElementById('install-pwa-btn');
                            if (btn) {
                                btn.addEventListener('click', async () => {
                                    if (deferredPrompt) {
                                        deferredPrompt.prompt();
                                        const { outcome } = await deferredPrompt.userChoice;
                                        deferredPrompt = null;
                                        btn.textContent = outcome === 'accepted' ? '✓ Installed' : 'Install via browser';
                                    } else {
                                        alert('Use your browser menu:\n\n• Chrome desktop: address bar icon (⊕) → Install\n• Edge: ··· menu → Apps → Install\n• Android Chrome: ⋮ → "Add to Home screen"\n• iOS Safari: Share → "Add to Home Screen"');
                                    }
                                });
                            }
                        })();
                        </script>
                    </section>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                    <!-- LOGS -->
                    <section class="settings-section" data-tab-content="logs">
                        <div class="settings-card">
                            <div class="settings-card-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px">
                                <div>
                                    <h2>Application errors</h2>
                                    <p>Recent errors from all subsystems. Auto-refresh every 5 seconds.</p>
                                </div>
                                <button type="button" id="logs-clear-btn" class="btn btn-secondary" style="flex-shrink:0">Clear logs</button>
                            </div>
                            <div id="logs-feed" style="font-family:'JetBrains Mono',monospace;font-size:12px;line-height:1.5;max-height:560px;overflow-y:auto">
                                Loading…
                            </div>
                        </div>
                        <script>
                        (function () {
                            const feed = document.getElementById('logs-feed');
                            if (!feed) return;
                            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                            const renderLogs = (items) => {
                                if (!items.length) { feed.innerHTML = '<div style="color:rgba(255,255,255,0.4);text-align:center;padding:32px">No errors logged.</div>'; return; }
                                feed.innerHTML = items.map(it => `<div style="padding:10px 12px;border-radius:8px;background:transparent;border:1px solid var(--border);margin-bottom:6px">
                                    <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:4px">
                                        <span style="font-weight:600;color:var(--text-secondary);font-size:11px;text-transform:uppercase;letter-spacing:0.05em">${esc(it.source)}</span>
                                        <span style="color:var(--text-muted);font-size:11px">${esc(it.ts)}</span>
                                    </div>
                                    <div style="color:var(--text-primary);white-space:pre-wrap;word-break:break-word">${esc(it.message)}</div>
                                </div>`).join('');
                            };
                            const refresh = async () => {
                                try {
                                    const r = await fetch('/api/logs/errors', { cache: 'no-store' });
                                    if (!r.ok) return;
                                    const data = await r.json();
                                    renderLogs(data.errors || []);
                                } catch (e) {}
                            };
                            refresh();
                            setInterval(refresh, 5000);
                            const clearBtn = document.getElementById('logs-clear-btn');
                            clearBtn?.addEventListener('click', async () => {
                                clearBtn.disabled = true;
                                clearBtn.textContent = 'Clearing…';
                                try {
                                    await fetch('/api/logs/clear', { method: 'POST' });
                                } catch (e) {}
                                clearBtn.disabled = false;
                                clearBtn.textContent = 'Clear logs';
                                refresh();
                            });
                        })();
                        </script>
                    </section>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
        (function() {
            const nav = document.querySelectorAll('#settings-nav .settings-nav-item');
            const sections = document.querySelectorAll('[data-tab-content]');
            const ACC_VIRTUAL = { 'account': 'profile', 'acc-stats': 'stats', 'acc-advanced': 'advanced' };
            if (window.__doAutoMapSections) window.__doAutoMapSections();
            const setActive = (tab) => {
                nav.forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
                const effective = ACC_VIRTUAL[tab] ? 'account' : tab;
                sections.forEach(s => s.classList.toggle('active', s.dataset.tabContent === effective));
                if (ACC_VIRTUAL[tab] && typeof window.__filterAccountSection === 'function') {
                    window.__filterAccountSection(ACC_VIRTUAL[tab]);
                }
                try { sessionStorage.setItem('doniix-settings-tab', tab); } catch(e) {}
            };
            nav.forEach(t => t.addEventListener('click', () => setActive(t.dataset.tab)));
            try {
                const saved = sessionStorage.getItem('doniix-settings-tab');
                if (saved && document.querySelector(`[data-tab="${saved}"]`)) setActive(saved);
                else setActive('account');
            } catch(e) { setActive('account'); }

            const testBtn = document.getElementById('subsonic-test');
            const testOut = document.getElementById('subsonic-test-result');
            testBtn?.addEventListener('click', async () => {
                const pwd = prompt('Enter your password to test the connection:');
                if (!pwd) return;
                testOut.textContent = 'Testing…';
                testOut.style.color = 'var(--text-secondary)';
                const u = '<?= addslashes($user['username']) ?>';
                try {
                    const url = `/rest/ping?u=${encodeURIComponent(u)}&p=${encodeURIComponent(pwd)}&v=1.16.1&c=Doniixify&f=json`;
                    const res = await fetch(url);
                    const data = await res.json();
                    const status = data?.['subsonic-response']?.status;
                    if (status === 'ok') {
                        testOut.textContent = '✓ Subsonic connection works. Use the URL/username/password above in Symfonium.';
                        testOut.style.color = '#3ddc84';
                    } else {
                        const err = data?.['subsonic-response']?.error?.message || 'Unknown error';
                        testOut.textContent = '✗ ' + err;
                        testOut.style.color = '#ff8b8b';
                    }
                } catch (e) {
                    testOut.textContent = '✗ Network error: ' + e.message;
                    testOut.style.color = '#ff8b8b';
                }
            });
        })();
        </script>
        <?php
        Layout::render('/settings', ob_get_clean());
    }

    private static function parseDownloadJobs(int $limit = 30): array
    {
        $logFile = __DIR__ . '/../../storage/download.log';
        if (!is_file($logFile)) return [];

        $size = filesize($logFile);
        $offset = max(0, $size - 65536);
        $fp = @fopen($logFile, 'r');
        if (!$fp) return [];
        @fseek($fp, $offset);
        $tail = stream_get_contents($fp) ?: '';
        @fclose($fp);

        $lines = explode("\n", $tail);
        $jobs = [];
        $lastPid = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[([\d\- :]+)\]\s+worker START pid=(\d+)\s+url=(\S+)/', $line, $m)) {
                $pid = $m[2];
                $jobs[$pid] = [
                    'ts' => $m[1],
                    'pid' => $pid,
                    'url' => $m[3],
                    'title' => null,
                    'artist' => null,
                    'status' => 'in_progress',
                ];
                $lastPid = $pid;
                continue;
            }
            if ($lastPid === null || !isset($jobs[$lastPid])) continue;

            if (preg_match('/^\[[\d\- :]+\]\s+download metadata: (.+?) - (.+?)\s+\[/', $line, $m)) {
                $jobs[$lastPid]['artist'] = $m[1];
                $jobs[$lastPid]['title'] = $m[2];
            } elseif (preg_match('/^\[[\d\- :]+\]\s+download SKIP exists/', $line)) {
                $jobs[$lastPid]['status'] = 'skipped';
            } elseif (preg_match('/^\[[\d\- :]+\]\s+download FAIL/', $line)) {
                $jobs[$lastPid]['status'] = 'failed';
            } elseif (preg_match('/^\[[\d\- :]+\]\s+worker DONE pid=(\d+)\s+ok=(\d)/', $line, $m)) {
                if (isset($jobs[$m[1]])) {
                    if ($jobs[$m[1]]['status'] === 'in_progress') {
                        $jobs[$m[1]]['status'] = $m[2] === '1' ? 'done' : 'failed';
                    }
                }
            }
        }

        return array_slice(array_reverse(array_values($jobs)), 0, $limit);
    }

    public static function changePassword(): void
    {
        $user = Session::requireLogin();
        Session::start();

        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $row = Database::fetchOne('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        $stored = $row['password_hash'] ?? '';
        $isBcrypt = is_string($stored) && (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$2b$'));
        $matches = $row !== null && ($isBcrypt ? password_verify($old, $stored) : hash_equals($stored, $old));
        if (!$matches) {
            $_SESSION['settings_error'] = 'Current password is incorrect.';
        } elseif ($new !== $confirm) {
            $_SESSION['settings_error'] = 'New password and confirmation do not match.';
        } elseif (strlen($new) < 6) {
            $_SESSION['settings_error'] = 'Password must be at least 6 characters.';
        } else {
            Database::execute('UPDATE users SET password_hash = ? WHERE id = ?', [$new, $user['id']]);
            $_SESSION['settings_ok'] = 'Password updated.';
        }
        header('Location: /settings');
    }

    public static function clearCovers(): void
    {
        Session::requireLogin();
        Session::start();
        $dir = Env::get('COVER_CACHE_PATH', '');
        $count = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f) && @unlink($f)) $count++;
            }
        }
        $_SESSION['settings_ok'] = "Removed {$count} cached cover" . ($count === 1 ? '' : 's') . '.';
        header('Location: /settings');
    }

    public static function refreshDurations(): void
    {
        Session::requireLogin();
        Session::start();
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $rows = \Doniixify\Database::fetchAll('SELECT id, path, duration FROM songs');
        $updated = 0;
        $skipped = 0;
        $missing = 0;
        foreach ($rows as $row) {
            $path = (string)$row['path'];
            if (!is_file($path)) { $missing++; continue; }
            $probed = \Doniixify\Scanner\Id3Parser::parse($path);
            $newDur = (int)($probed['duration'] ?? 0);
            if ($newDur <= 0) { $skipped++; continue; }
            $cur = (int)$row['duration'];
            if (abs($newDur - $cur) < 2) { $skipped++; continue; }
            \Doniixify\Database::execute('UPDATE songs SET duration = ? WHERE id = ?', [$newDur, (int)$row['id']]);
            $updated++;
        }
        \Doniixify\Database::pdo()->exec('UPDATE albums a SET duration = (SELECT COALESCE(SUM(duration),0) FROM songs s WHERE s.album_id = a.id)');

        $_SESSION['settings_ok'] = "Durations: updated {$updated}, unchanged {$skipped}, missing files {$missing}.";
        header('Location: /settings');
    }

    private static function formatHours(int $seconds): string
    {
        if ($seconds <= 0) return '0:00';
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm', $h, $m);
        }
        if ($m > 0) {
            return sprintf('%dm %02ds', $m, $s);
        }
        return sprintf('%ds', $s);
    }

    private static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return number_format($bytes, $i > 1 ? 1 : 0) . ' ' . $units[$i];
    }

    private static function statusClass(string $status): string
    {
        return match ($status) {
            'done' => 'ok',
            'error' => 'err',
            'running' => 'warn',
            default => '',
        };
    }
}
