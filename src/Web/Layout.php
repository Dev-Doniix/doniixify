<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Env;

final class Layout
{
    public static function render(string $activeRoute, string $content, array $context = []): void
    {
        $user = Session::user();
        $username = htmlspecialchars($user['username'] ?? '');
        $userId = (int)($user['id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);
        $appName = htmlspecialchars(Env::get('APP_NAME', 'Doniixify'));

        $playlists = [];
        if ($user) {
            $playlists = Database::fetchAll('SELECT id, name FROM playlists WHERE user_id = ? ORDER BY name', [$user['id']]);
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isTv = (bool)preg_match('/webOS|Web0S|SmartTV|Tizen|HbbTV|VIDAA|NetCast|LG Browser|SMART-TV/i', $ua);
        $isMobile = (bool)preg_match('/Android.+Mobile|iPhone|iPod|Mobile Safari|BlackBerry|IEMobile|Opera Mini/i', $ua) && !$isTv;
        $tvAttr = $isTv ? ' data-tv="1"' : ($isMobile ? ' data-mobile="1"' : '');

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html>';
        ?>
<html lang="en"<?= $tvAttr ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $appName ?></title>
<?php if ($isTv): ?>
<style id="tv-perf">
[data-tv="1"] .stars, [data-tv="1"] .stars-layer, [data-tv="1"] .nebula { display: none !important; }
[data-tv="1"] body { background: #000 !important; }
</style>
<?php endif; ?>
<?php if ($isMobile): ?>
<style id="mobile-perf">
[data-mobile="1"] .stars-3, [data-mobile="1"] .nebula { display: none !important; }
</style>
<?php endif; ?>
<link rel="icon" type="image/png" sizes="192x192" href="/api/icon/192">
<link rel="icon" type="image/png" sizes="512x512" href="/api/icon/512">
<link rel="shortcut icon" type="image/png" href="/api/icon/192">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0a0a0d">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Doniixify">
<meta name="csrf-token" content="<?= htmlspecialchars(Session::csrfToken()) ?>">
<link rel="apple-touch-icon" href="/api/icon/512">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<script>
(function() {
    var SERVER_VER = '<?= (int)max(@filemtime(__DIR__ . '/../../assets/js/app.js'), @filemtime(__DIR__ . '/../../assets/css/app.css')) ?>';
    var forceReset = location.search.indexOf('reset=1') !== -1 || location.search.indexOf('nuke=1') !== -1;
    if (location.hash && location.hash.indexOf('#fresh=') === 0) {
        try { history.replaceState(null, '', location.pathname + location.search); } catch(_) {}
    }
    if (forceReset) {
        try {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(function(rs) {
                    rs.forEach(function(r) { try { r.unregister(); } catch(_) {} });
                });
            }
            if ('caches' in window) {
                caches.keys().then(function(ns) {
                    ns.forEach(function(n) { caches.delete(n); });
                });
            }
            setTimeout(function() { location.replace(location.pathname); }, 800);
        } catch(_) {}
    }
})();
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').then(function(reg) {
            try { reg.update(); } catch(_) {}
            reg.addEventListener('updatefound', function() {
                var nsw = reg.installing;
                if (!nsw) return;
                nsw.addEventListener('statechange', function() {
                    if (nsw.state === 'installed' && navigator.serviceWorker.controller) {
                        try { window.showUpdateBanner && window.showUpdateBanner(); } catch(_) {}
                    }
                });
            });
            setInterval(function(){ try { reg.update(); } catch(_) {} }, 60000);
        }).catch(()=>{});
    });
}
// Auto-update — check on boot + every 60s, show banner if new version available
(function() {
    var KEY = 'doniix-app-ver';
    var jsTs = <?= (int)@filemtime(__DIR__ . '/../../assets/js/app.js') ?>;
    var cssTs = <?= (int)@filemtime(__DIR__ . '/../../assets/css/app.css') ?>;
    var bootVer = Math.max(jsTs, cssTs);
    var stored = parseInt(localStorage.getItem(KEY) || sessionStorage.getItem(KEY) || '0', 10);
    if (stored > 0 && bootVer > stored) {
        document.addEventListener('DOMContentLoaded', function() { try { showUpdateBanner(); } catch(_) {} });
    }
    try { localStorage.setItem(KEY, String(bootVer)); sessionStorage.setItem(KEY, String(bootVer)); } catch(_) {}

    function showUpdateBanner() {
        if (document.getElementById('doniix-update-banner')) return;
        var b = document.createElement('div');
        b.id = 'doniix-update-banner';
        var isMobileBanner = window.innerWidth <= 720;
        b.innerHTML = '<span class="d-upd-label">Update available</span><button id="doniix-update-btn">Reload</button><button id="doniix-update-dismiss" aria-label="Dismiss">×</button>';
        var bottomOffset = isMobileBanner
            ? 'calc(var(--tabbar-h, 60px) + var(--mini-player-h, 64px) + env(safe-area-inset-bottom, 0px) + 12px)'
            : '110px';
        var mobileStyle = isMobileBanner
            ? 'left:12px;right:12px;transform:none;max-width:none;width:auto;padding:10px 12px;font-size:13px;gap:8px;border-radius:12px;'
            : 'left:50%;transform:translateX(-50%);max-width:380px;padding:12px 18px;font-size:14px;gap:12px;border-radius:14px;';
        b.style.cssText = 'position:fixed;' + mobileStyle + 'bottom:' + bottomOffset + ';z-index:99999;background:#16181c;color:#e7e9ea;display:flex;align-items:center;border:1px solid #2f3336;box-shadow:0 16px 48px rgba(0,0,0,0.6);font-family:Inter,system-ui,sans-serif;font-weight:600;animation:doniixSlide 0.3s ease-out;box-sizing:border-box;';
        var s = document.createElement('style');
        s.textContent = '@keyframes doniixSlide{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}' +
            '#doniix-update-banner .d-upd-label{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
            '#doniix-update-dismiss{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;font-size:18px;line-height:1;flex-shrink:0}' +
            '#doniix-update-btn{background:#e7e9ea;border:0;color:#000;padding:6px 14px;border-radius:999px;cursor:pointer;font-family:inherit;font-size:13px;font-weight:700;flex-shrink:0;white-space:nowrap}' +
            '#doniix-update-btn:hover{background:#fff}' +
            '#doniix-update-dismiss{background:transparent;border:0;color:#71767b;cursor:pointer;font-family:inherit;font-weight:600;padding:0}' +
            '#doniix-update-dismiss:hover{color:#e7e9ea}';
        document.head.appendChild(s);
        document.body.appendChild(b);
        document.getElementById('doniix-update-btn').onclick = function() {
            try {
                if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                    navigator.serviceWorker.getRegistrations().then(function(regs) {
                        regs.forEach(function(r) { r.unregister(); });
                        if ('caches' in window) {
                            caches.keys().then(function(names) {
                                Promise.all(names.map(function(n) { return caches.delete(n); })).then(function() {
                                    location.reload(true);
                                });
                            });
                        } else {
                            location.reload(true);
                        }
                    });
                } else {
                    location.reload(true);
                }
            } catch (e) { location.reload(true); }
        };
        document.getElementById('doniix-update-dismiss').onclick = function() { b.remove(); };
    }

    function checkUpdate() {
        fetch('/api/version', { cache: 'no-store' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.version > bootVer && bootVer > 0) {
                    showUpdateBanner();
                }
            })
            .catch(function() {});
    }
    window.showUpdateBanner = showUpdateBanner;
    window.__forceFullRefresh = function() {
        var nuke = function() {
            if ('serviceWorker' in navigator) {
                return navigator.serviceWorker.getRegistrations().then(function(rs) {
                    return Promise.all(rs.map(function(r) { return r.unregister(); }));
                });
            }
            return Promise.resolve();
        };
        nuke().then(function() {
            if ('caches' in window) {
                return caches.keys().then(function(ns) {
                    return Promise.all(ns.map(function(n) { return caches.delete(n); }));
                });
            }
        }).then(function() {
            try { localStorage.removeItem(KEY); } catch(_) {}
            location.replace(location.pathname + '?_t=' + Date.now());
        }).catch(function() {
            location.replace(location.pathname + '?_t=' + Date.now());
        });
    };
    setTimeout(checkUpdate, 3000);
    setInterval(checkUpdate, 60 * 1000);
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') checkUpdate();
    });
    window.addEventListener('focus', checkUpdate);

})();
(function(){
    const isMobile = window.matchMedia && window.matchMedia('(max-width: 720px)').matches;
    if (!isMobile && sessionStorage.getItem('doniix-splash-shown') === '1') return;
    sessionStorage.setItem('doniix-splash-shown', '1');
    document.documentElement.dataset.showSplash = '1';
    const hide = () => {
        const s = document.querySelector('.doniix-splash');
        if (s) { s.classList.add('hide'); setTimeout(() => s.remove(), 500); }
    };
    document.addEventListener('DOMContentLoaded', () => {
        const wait = Math.max(0, 600 - (performance.now()));
        setTimeout(hide, wait);
    });
    window.addEventListener('load', () => setTimeout(hide, 200));
    setTimeout(hide, 3500);
})();
// SPA in-memory page cache — instant Home/Search/Library switching
window.__pageCache = window.__pageCache || new Map();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/app.css') ?: '1' ?>">
<link rel="stylesheet" href="/assets/css/themes.css?v=<?= @filemtime(__DIR__ . '/../../assets/css/themes.css') ?: '1' ?>">
<script>window.__userId = <?= (int)$userId ?>; window.__isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;</script>
<script src="/assets/js/themes.js?v=<?= htmlspecialchars(Env::get('APP_VERSION', '0.1.0')) ?>-<?= filemtime(__DIR__ . '/../../assets/js/themes.js') ?: time() ?>"></script>
</head>
<body>
<?php
$splashSession = $_SESSION['doniix_splash_seen'] ?? false;
$forceSplash = $isMobile || $isTv;
if (!$splashSession || $forceSplash):
    $_SESSION['doniix_splash_seen'] = true;
?>
<div class="doniix-splash" id="doniix-splash" aria-hidden="true">
    <div class="doniix-splash-stars">
        <div class="doniix-splash-star-layer doniix-splash-star-1"></div>
        <div class="doniix-splash-star-layer doniix-splash-star-2"></div>
        <div class="doniix-splash-star-layer doniix-splash-star-3"></div>
    </div>
    <div class="doniix-splash-logo">
        <img src="/api/icon/512" alt="Doniixify" style="width:100%;height:100%;object-fit:cover;display:block">
    </div>
    <div class="doniix-splash-name">Doniixify</div>
    <div class="doniix-splash-bar"></div>
</div>
<?php endif; ?>
<div class="stars">
    <div class="stars-layer stars-1"></div>
    <div class="stars-layer stars-2"></div>
    <div class="stars-layer stars-3"></div>
    <div class="nebula nebula-1"></div>
    <div class="nebula nebula-2"></div>
</div>

<div class="app">
    <aside class="sidebar">
        <nav class="sidebar-section" style="padding-top:16px">
            <?php self::navItem('/', 'home', 'Home', $activeRoute) ?>
            <?php self::navItem('/search', 'search', 'Search', $activeRoute) ?>
        </nav>

        <nav class="sidebar-section" id="sidebar-playlists">
            <div class="sidebar-section-head">
                <div class="sidebar-section-label">Playlists</div>
                <div class="sidebar-section-actions">
                    <button type="button" class="sidebar-add-btn" id="sidebar-search-toggle" title="Search playlists">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                    <button class="sidebar-add-btn" id="sidebar-import-btn" title="Import playlist">
                        <?= self::icon('plus', 16) ?>
                    </button>
                </div>
            </div>
            <div class="sidebar-search-wrap" id="sidebar-search-wrap" style="display:none;padding:6px 12px 4px">
                <input type="text" id="sidebar-search-input" class="sidebar-search-input" placeholder="Find playlist…" autocomplete="off" style="width:100%;background:transparent;border:1px solid var(--border);color:var(--text-primary);padding:7px 10px;border-radius:8px;font-size:13px;outline:none">
            </div>
            <div id="sidebar-playlists-list">
                <a class="sidebar-item<?= str_starts_with($activeRoute, '/favorites') || str_starts_with($activeRoute, '/library/liked') ? ' active' : '' ?>" href="/library/liked">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    <span>Liked Songs</span>
                </a>
                <?php foreach ($playlists as $pl): ?>
                    <a class="sidebar-item" href="/playlist/<?= (int)$pl['id'] ?>">
                        <?= self::icon('list', 18) ?>
                        <span><?= htmlspecialchars($pl['name']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </nav>

        <div class="sidebar-footer">
            <a class="user-menu" href="/settings" style="text-decoration:none;color:inherit;display:block">
                <div class="user-button" style="cursor:pointer">
                    <div class="user-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
                    <div class="user-info">
                        <div class="user-name"><?= $username ?></div>
                        <div class="user-role"><?= $isAdmin ? 'Administrator' : 'User' ?></div>
                    </div>
                    <?= self::icon('settings', 16) ?>
                </div>
            </a>
        </div>
    </aside>

    <nav class="mobile-bottom-nav" id="mobile-bottom-nav">
        <a href="/" class="<?= $activeRoute === '/' ? 'active' : '' ?>" data-spa>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Home
        </a>
        <a href="/search" class="<?= str_starts_with($activeRoute, '/search') ? 'active' : '' ?>" data-spa>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            Search
        </a>
        <a href="/library" class="<?= (str_starts_with($activeRoute, '/library') || str_starts_with($activeRoute, '/favorites') || str_starts_with($activeRoute, '/playlist')) ? 'active' : '' ?>" data-spa>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/></svg>
            Library
        </a>
        <a href="/settings" class="<?= str_starts_with($activeRoute, '/settings') ? 'active' : '' ?>" data-spa>
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>
    <main class="main">
        <div class="main-scroll" id="main-scroll">
            <?= $content ?>
        </div>
    </main>
    <script>
    const injectMobileBackBtn = () => {
        document.querySelectorAll('.mobile-back-btn').forEach(b => b.remove());
    };
    document.addEventListener('DOMContentLoaded', injectMobileBackBtn);
    setInterval(injectMobileBackBtn, 1000);
    window.__injectMobileBack = injectMobileBackBtn;

    const initMobileNp = () => {
        if (window.innerWidth > 720) return;
        if (document.getElementById('now-playing-fullscreen')) return;
        const npHtml = `
            <div class="now-playing-fullscreen" id="now-playing-fullscreen">
                <button class="np-close" id="np-close" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button>
                <div class="np-cover"><img id="np-fs-cover" alt=""></div>
                <div class="np-title" id="np-fs-title">—</div>
                <div class="np-artist" id="np-fs-artist">—</div>
                <div class="np-lyrics-title" id="np-fs-lyrics-title">Lyrics <button class="np-fs-expand" id="np-fs-expand-lyrics" aria-label="Open lyrics fullscreen"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button></div>
                <div class="np-lyrics" id="np-fs-lyrics">Tap song to load lyrics…</div>
                <div class="np-fs-seek">
                    <span id="np-fs-time-cur">0:00</span>
                    <div class="np-fs-seek-bar" id="np-fs-seek-bar"><div class="np-fs-seek-fill" id="np-fs-seek-fill"></div></div>
                    <span id="np-fs-time-tot">0:00</span>
                </div>
                <div class="np-controls">
                    <button class="np-ctrl" id="np-fs-shuffle" aria-label="Smart radio"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2.5l1.6 5.2 5.4 1.3-5.4 1.3L12 15.5l-1.6-5.2L5 9l5.4-1.3z"/></svg></button>
                    <button class="np-ctrl" id="np-fs-prev" aria-label="Previous"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M5 5h2v14H5z"/><path d="M19 5v14L8 12z"/></svg></button>
                    <button class="np-ctrl np-play" id="np-fs-play" aria-label="Play/Pause"><svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="#000"><path d="M7 4.5 19.5 12 7 19.5z"/></svg></button>
                    <button class="np-ctrl" id="np-fs-next" aria-label="Next"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="currentColor"><path d="M16 12 5 5v14z"/><path d="M17 5h2v14h-2z"/></svg></button>
                    <button class="np-ctrl" id="np-fs-repeat" aria-label="Repeat"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg></button>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', npHtml);
        const NP_PAUSE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="#000"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>';
        const NP_PLAY_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="#000"><path d="M7 4.5 19.5 12 7 19.5z"/></svg>';
        const setIfChanged = (el, prop, val) => { if (el && el[prop] !== val) el[prop] = val; };
        const syncMeta = () => {
            const pbT = document.getElementById('pb-title');
            const pbA = document.getElementById('pb-artist');
            const pbCov = document.querySelector('.player-bar .pb-cover img');
            const npT = document.getElementById('np-fs-title');
            const npA = document.getElementById('np-fs-artist');
            const npC = document.getElementById('np-fs-cover');
            const tText = pbT ? (pbT.textContent || '').trim() : '';
            const aText = pbA ? (pbA.textContent || '').trim() : '';
            if (tText && tText !== '—') setIfChanged(npT, 'textContent', tText);
            if (aText && aText !== '—') setIfChanged(npA, 'textContent', aText);
            if (pbCov && pbCov.src && !pbCov.src.endsWith('/cover/0')) setIfChanged(npC, 'src', pbCov.src);
            const npS = document.getElementById('np-fs-shuffle');
            const pbS = document.getElementById('pb-shuffle');
            if (npS && pbS) npS.classList.toggle('active', pbS.classList.contains('active'));
            const npR = document.getElementById('np-fs-repeat');
            const pbR = document.getElementById('pb-repeat');
            if (npR && pbR) npR.classList.toggle('active', pbR.classList.contains('active'));
            const audio = document.querySelector('audio');
            if (audio) {
                const remotePlaying = pbT?.dataset?.otherDevice === '1';
                const playing = (!audio.paused && !audio.ended) || remotePlaying;
                setIfChanged(document.getElementById('np-fs-play'), 'innerHTML', playing ? NP_PAUSE_SVG : NP_PLAY_SVG);
            }
        };
        const attachObserver = () => {
            try { window.__npObserver?.disconnect(); } catch (_) {}
            const obs = new MutationObserver(syncMeta);
            const pbT = document.getElementById('pb-title');
            const pbA = document.getElementById('pb-artist');
            const pbCov = document.querySelector('.player-bar .pb-cover img');
            if (pbT) obs.observe(pbT, { childList: true, characterData: true, subtree: true, attributes: true });
            if (pbA) obs.observe(pbA, { childList: true, characterData: true, subtree: true, attributes: true });
            if (pbCov) obs.observe(pbCov, { attributes: true, attributeFilter: ['src'] });
            window.__npObserver = obs;
        };
        attachObserver();
        try {
            const audio = document.querySelector('audio');
            if (audio) {
                ['play','pause','loadedmetadata','emptied'].forEach(ev => {
                    audio.addEventListener(ev, syncMeta, { passive: true });
                });
            }
        } catch (_) {}
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') syncMeta();
        });
        window.__npSyncMeta = syncMeta;
        const pbTitle = document.getElementById('pb-title');
        const pbArtist = document.getElementById('pb-artist');
        const getCurrentSongId = () => {
            const audio = document.querySelector('audio');
            if (audio && audio.src) {
                const m = audio.src.match(/\/stream\/(\d+)/);
                if (m) return Number(m[1]);
            }
            return window.currentSongId
                || document.querySelector('tr.playing')?.dataset.songId
                || document.querySelector('[data-song-id].playing')?.dataset.songId
                || null;
        };
        let lyricLines = [];
        const renderLyricLines = (synced, plain) => {
            const el = document.getElementById('np-lyrics');
            if (!el) return;
            lyricLines = [];
            if (synced && synced.trim()) {
                const lines = synced.split('\n');
                const parsed = [];
                for (const ln of lines) {
                    const m = ln.match(/^\[(\d+):(\d+(?:\.\d+)?)\](.*)$/);
                    if (!m) continue;
                    const t = Number(m[1]) * 60 + parseFloat(m[2]);
                    const text = m[3].trim();
                    parsed.push({ t, text });
                }
                parsed.sort((a,b) => a.t - b.t);
                for (let i = 0; i < parsed.length; i++) {
                    parsed[i].end = (i+1 < parsed.length) ? parsed[i+1].t : (parsed[i].t + 5);
                }
                lyricLines = parsed;
                el.innerHTML = parsed.map((p,i) =>
                    `<div class="np-lyric-line" data-i="${i}" data-time="${p.t}" data-end="${p.end}">${p.text ? p.text.replace(/[<>&]/g, c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) : '♪'}</div>`
                ).join('');
            } else if (plain && plain.trim()) {
                el.innerHTML = plain.split('\n').map(l =>
                    `<div class="np-lyric-line">${l.replace(/[<>&]/g, c=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]) ) || '♪'}</div>`
                ).join('');
            } else {
                el.textContent = 'No lyrics found';
            }
        };
        const loadLyrics = async () => {
            const songId = getCurrentSongId();
            const el = document.getElementById('np-lyrics');
            if (!el) return;
            if (!songId) { el.textContent = 'No song playing'; return; }
            if (el.dataset.songId == songId && el.children.length > 0) return;
            el.textContent = 'Loading lyrics…';
            try {
                const r = await fetch('/api/lyrics/' + songId);
                if (r.ok) {
                    const d = await r.json();
                    renderLyricLines(d.synced, d.lyrics);
                    el.dataset.songId = songId;
                } else { el.textContent = 'No lyrics available'; }
            } catch (e) { el.textContent = 'No lyrics available'; }
        };
        let lastActiveIdx = -2;
        const tickLyrics = () => {
            if (!document.body.classList.contains('now-playing-open')) return;
            if (!lyricLines.length) return;
            const audio = document.querySelector('audio');
            if (!audio) return;
            const t = (audio.currentTime || 0) + 0.12;
            let activeIdx = -1;
            for (let i = 0; i < lyricLines.length; i++) {
                if (t >= lyricLines[i].t) activeIdx = i;
                else break;
            }
            if (activeIdx === lastActiveIdx) return;
            lastActiveIdx = activeIdx;
            const el = document.getElementById('np-lyrics');
            if (!el) return;
            const lines = el.querySelectorAll('.np-lyric-line');
            lines.forEach((ln,i) => ln.classList.toggle('active', i === activeIdx));
            const active = lines[activeIdx];
            if (active && active.scrollIntoView) {
                active.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };
        setInterval(tickLyrics, window.__isTv ? 1000 : 100);
        document.querySelector('audio')?.addEventListener('timeupdate', tickLyrics);
        let __lastPlayedCache = null;
        let __lastPlayedInflight = null;
        const fetchLastPlayedFallback = () => {
            const applyCached = () => {
                if (!__lastPlayedCache) return;
                const d = __lastPlayedCache;
                const npT = document.getElementById('np-title');
                const npA = document.getElementById('np-artist');
                const npC = document.getElementById('np-cover-img');
                const pbT = document.getElementById('pb-title');
                const pbA = document.getElementById('pb-artist');
                const pbCov = document.querySelector('.player-bar .pb-cover img');
                const fsT = document.getElementById('np-fs-title');
                const fsA = document.getElementById('np-fs-artist');
                const fsC = document.getElementById('np-fs-cover');
                const curPbT = pbT ? (pbT.textContent || '').trim() : '';
                const audioEl = document.querySelector('audio');
                const hasReal = audioEl && audioEl.src && audioEl.src.includes('/stream/');
                if (hasReal) return;
                if (!curPbT || curPbT === '—') {
                    if (pbT && d.title) pbT.textContent = d.title;
                    if (pbA && d.artist_name) pbA.textContent = d.artist_name;
                    if (pbCov && d.id) {
                        const cov = location.origin + '/cover/' + Number(d.id);
                        if (pbCov.src !== cov) pbCov.src = cov;
                    } else if (!pbCov && d.id) {
                        const wrap = document.querySelector('.player-bar .pb-cover');
                        if (wrap) {
                            const img = document.createElement('img');
                            img.alt = '';
                            img.src = location.origin + '/cover/' + Number(d.id);
                            wrap.appendChild(img);
                        }
                    }
                    if (fsT && d.title) fsT.textContent = d.title;
                    if (fsA && d.artist_name) fsA.textContent = d.artist_name;
                    if (fsC && d.id) {
                        const cov = location.origin + '/cover/' + Number(d.id);
                        if (fsC.src !== cov) fsC.src = cov;
                    }
                    if (npT && d.title) npT.textContent = d.title;
                    if (npA) npA.textContent = d.artist_name || '';
                    if (npC && d.id) {
                        const newSrc = location.origin + '/cover/' + Number(d.id);
                        if (npC.src !== newSrc) npC.src = newSrc;
                    }
                }
            };
            if (__lastPlayedCache) { applyCached(); return; }
            if (__lastPlayedInflight) return;
            __lastPlayedInflight = fetch('/api/me/last-played', { cache: 'no-store' })
                .then(r => r.ok ? r.json() : null)
                .then(d => {
                    if (d && d.id) __lastPlayedCache = d;
                    applyCached();
                })
                .catch(() => {})
                .finally(() => { __lastPlayedInflight = null; });
        };
        const forceSyncTitle = () => {
            const allTitles = document.querySelectorAll('#pb-title, .pb-title');
            const allArtists = document.querySelectorAll('#pb-artist, .pb-artist');
            const allCovers = document.querySelectorAll('.player-bar .pb-cover img, .pb-cover img');
            let titleText = '';
            let artistText = '';
            let coverSrc = '';
            for (const el of allTitles) { const t = (el.innerText || el.textContent || '').trim(); if (t && t !== '—') { titleText = t; break; } }
            for (const el of allArtists) { const t = (el.innerText || el.textContent || '').trim(); if (t && t !== '—') { artistText = t; break; } }
            for (const img of allCovers) { if (img.src && !img.src.endsWith('/cover/0')) { coverSrc = img.src; break; } }
            const npT = document.getElementById('np-fs-title');
            const npA = document.getElementById('np-fs-artist');
            const npC = document.getElementById('np-fs-cover');
            if (titleText) {
                if (npT && npT.textContent !== titleText) npT.textContent = titleText;
                if (npA && npA.textContent !== (artistText || '')) npA.textContent = artistText || '';
                if (npC && coverSrc && npC.src !== coverSrc) npC.src = coverSrc;
            } else {
                const curNpT = npT ? (npT.textContent || '').trim() : '';
                if (!curNpT) {
                    if (npT) npT.textContent = '—';
                    fetchLastPlayedFallback();
                }
            }
        };
        window.__forceSyncTitleMobile = forceSyncTitle;
        let __lastLyricsKey = null;
        const parseMobileLrc = (synced) => {
            if (typeof window.__parseLrcBasic === 'function') return window.__parseLrcBasic(synced);
            const out = [];
            const re = /\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/g;
            synced.split(/\r?\n/).forEach(ln => {
                let m, last = 0;
                const stamps = [];
                while ((m = re.exec(ln)) !== null) {
                    const mm = parseInt(m[1], 10);
                    const ss = parseInt(m[2], 10);
                    const ms = m[3] ? parseInt(m[3].padEnd(3,'0').slice(0,3), 10) : 0;
                    stamps.push(mm * 60 + ss + ms / 1000);
                    last = m.index + m[0].length;
                }
                const txt = ln.slice(last).trim();
                for (const t of stamps) out.push({ time: t, text: txt });
            });
            out.sort((a,b) => a.time - b.time);
            return out;
        };
        const escMobile = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        const fetchMobileLyrics = (force) => {
            const dest = document.getElementById('np-fs-lyrics');
            if (!dest) return;
            const audioEl = document.querySelector('audio');
            const meta = window.__currentTrackMeta || {};
            const titleSrc = (meta.title || document.getElementById('pb-title')?.textContent || '').trim();
            const artistSrcRaw = (meta.artist || document.getElementById('pb-artist')?.textContent || '').trim();
            const artistSrc = artistSrcRaw.split(/\s+·\s+on\s+/i)[0].split(/\s+·\s+/)[0].trim();
            const dur = Math.round((audioEl && audioEl.duration) || 0);
            const key = (titleSrc || '') + '|' + (artistSrc || '');
            if (!force && key === __lastLyricsKey) return;
            __lastLyricsKey = key;
            if (!titleSrc || titleSrc === '—' || !artistSrc) {
                if (!window.__mobileLyricsLines && (!dest.textContent || dest.textContent === 'Loading lyrics…' || dest.textContent === 'Tap song to load lyrics…')) {
                    dest.textContent = 'Play a song first';
                }
                return;
            }
            window.__mobileLyricsLines = null;
            window.__mobileLyricsRetryCount = window.__mobileLyricsRetryCount || {};
            const retryKey = key;
            const tries = window.__mobileLyricsRetryCount[retryKey] || 0;
            if (tries === 0) dest.textContent = 'Loading lyrics…';
            const url = '/api/lyrics?artist=' + encodeURIComponent(artistSrc) + '&title=' + encodeURIComponent(titleSrc) + '&duration=' + dur + (tries > 0 ? '&force=1' : '');
            fetch(url, { cache: 'no-store' })
                .then(r => r.ok ? r.json() : null)
                .then(d => {
                    if (key !== __lastLyricsKey) return;
                    if (!d || !d.found || (!d.plain && !d.synced)) {
                        if (tries < 1) {
                            window.__mobileLyricsRetryCount[retryKey] = tries + 1;
                            __lastLyricsKey = null;
                            setTimeout(() => fetchMobileLyrics(true), 2500);
                            return;
                        }
                        const shortT = titleSrc.length > 40 ? titleSrc.slice(0,40) + '…' : titleSrc;
                        const shortA = artistSrc.length > 40 ? artistSrc.slice(0,40) + '…' : artistSrc;
                        dest.innerHTML = '<div>No lyrics for this track</div>' +
                            '<div style="margin-top:12px;font-size:11px;color:rgba(255,255,255,0.4)">Searched: ' +
                            escMobile(shortT) + ' — ' + escMobile(shortA) + '</div>' +
                            '<div style="margin-top:8px;font-size:11px;color:rgba(255,255,255,0.5)">Tap to retry</div>';
                        return;
                    }
                    window.__mobileLyricsRetryCount[retryKey] = 0;
                    if (d.has_synced && Array.isArray(d.lines) && d.lines.length) {
                        window.__mobileLyricsLines = d.lines;
                        dest.innerHTML = d.lines.map((l, i) =>
                            '<div class="np-lyric-line" data-i="' + i + '" data-t="' + (l.time != null ? l.time.toFixed(2) : '0.00') + '">' + (l.text ? escMobile(l.text) : '♪') + '</div>'
                        ).join('');
                        return;
                    }
                    if (d.synced) {
                        const parsed = parseMobileLrc(d.synced);
                        if (parsed.length) {
                            window.__mobileLyricsLines = parsed;
                            dest.innerHTML = parsed.map((l, i) =>
                                '<div class="np-lyric-line" data-i="' + i + '" data-t="' + l.time.toFixed(2) + '">' + (l.text ? escMobile(l.text) : '♪') + '</div>'
                            ).join('');
                            return;
                        }
                    }
                    const text = (d.plain || '').trim();
                    dest.textContent = text || 'No lyrics for this track';
                })
                .catch(() => { if (key === __lastLyricsKey) dest.textContent = 'Could not load lyrics'; });
        };
        let __mlLastIdx = -2;
        const tickMobileLyrics = () => {
            if (!document.body.classList.contains('now-playing-open')) return;
            const lines = window.__mobileLyricsLines;
            if (!lines || !lines.length) return;
            const dest = document.getElementById('np-fs-lyrics');
            if (!dest) return;
            const audio = document.querySelector('audio');
            let t = (audio && !audio.paused) ? audio.currentTime : 0;
            const pbCur = document.getElementById('pb-time-current');
            if (pbCur) {
                const parts = (pbCur.textContent || '0:00').split(':').map(n => parseInt(n, 10) || 0);
                const tt = (parts[0] || 0) * 60 + (parts[1] || 0);
                if (tt > t) t = tt;
            }
            t += 0.12;
            let idx = -1;
            for (let i = 0; i < lines.length; i++) {
                if (lines[i].time <= t) idx = i;
                else break;
            }
            if (idx === __mlLastIdx) return;
            __mlLastIdx = idx;
            const els = dest.querySelectorAll('.np-lyric-line');
            els.forEach((el, i) => {
                el.classList.toggle('active', i === idx);
                el.classList.toggle('past', i < idx);
            });
            if (idx >= 0 && els[idx] && Date.now() - (window.__mobileLyricsUserScroll || 0) > 2000) {
                els[idx].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        };
        setInterval(tickMobileLyrics, 200);
        document.querySelector('audio')?.addEventListener('timeupdate', tickMobileLyrics);
        document.addEventListener('click', (e) => {
            if (e.target.closest && e.target.closest('#np-fs-lyrics .np-lyric-line')) {
                const ln = e.target.closest('.np-lyric-line');
                const tt = Number(ln.dataset.t);
                if (!isNaN(tt)) {
                    const a = document.querySelector('audio');
                    if (a && a.duration) {
                        try { a.currentTime = tt; } catch(_) {}
                    }
                }
            }
        });
        window.__mobileFetchLyrics = fetchMobileLyrics;
        const open = () => {
            syncMeta();
            fetchLastPlayedFallback();
            document.body.classList.add('now-playing-open');
            try {
                const cur = history.state || {};
                if (!cur.__npOpen) {
                    history.pushState({ ...cur, __npOpen: 1 }, '', location.pathname + location.search + '#np');
                }
            } catch (_) {}
            fetchMobileLyrics(true);
        };
        setInterval(syncMeta, 500);
        const audio2 = document.querySelector('audio');
        if (audio2) {
            audio2.addEventListener('loadedmetadata', () => {
                if (document.body.classList.contains('now-playing-open')) fetchMobileLyrics(false);
            });
        }
        const pbTitleEl = document.getElementById('pb-title');
        if (pbTitleEl) {
            new MutationObserver(() => {
                syncMeta();
                if (document.body.classList.contains('now-playing-open')) fetchMobileLyrics(false);
            }).observe(pbTitleEl, { childList: true, characterData: true, subtree: true });
        }
        syncMeta();
        const close = () => {
            document.body.classList.remove('now-playing-open');
            try { window.__npObserver?.disconnect(); window.__npObserver = null; } catch (_) {}
            try {
                if (history.state && history.state.__npOpen) {
                    window.__npClosingViaHistory = true;
                    history.back();
                }
            } catch (_) {}
        };
        const reopen = () => { attachObserver(); open(); };
        document.addEventListener('click', (e) => {
            if (e.target.closest('.player-bar .pb-btn')) return;
            const pb = e.target.closest('.player-bar');
            if (pb) {
                e.preventDefault();
                e.stopPropagation();
                if (!window.__npObserver) attachObserver();
                open();
                return;
            }
        }, true);
        if (!window.__npButtonsDelegated) {
            window.__npButtonsDelegated = true;
            const npProxyMap = {
                'np-close': () => close(),
                'np-fs-expand-lyrics': (e) => { e.stopPropagation(); if (window.__openLyricsFullscreen) window.__openLyricsFullscreen(); },
                'np-fs-play': () => document.getElementById('pb-play')?.click(),
                'np-fs-next': () => document.getElementById('pb-next')?.click(),
                'np-fs-prev': () => document.getElementById('pb-prev')?.click(),
                'np-fs-shuffle': () => document.getElementById('pb-shuffle')?.click(),
                'np-fs-repeat': () => document.getElementById('pb-repeat')?.click(),
            };
            document.addEventListener('click', (e) => {
                if (!e.target || !e.target.closest) return;
                for (const id in npProxyMap) {
                    if (e.target.closest('#' + id)) {
                        e.preventDefault();
                        e.stopPropagation();
                        npProxyMap[id](e);
                        return;
                    }
                }
            }, true);
        }
        const fmtT = (s) => { if (!isFinite(s) || s < 0) s = 0; const m = Math.floor(s/60); return m + ':' + String(Math.floor(s%60)).padStart(2,'0'); };
        const updateFsSeek = () => {
            const fill = document.getElementById('np-fs-seek-fill');
            const cur = document.getElementById('np-fs-time-cur');
            const tot = document.getElementById('np-fs-time-tot');
            const pbFill = document.getElementById('pb-seek-fill');
            const pbCur = document.getElementById('pb-time-current');
            const pbTot = document.getElementById('pb-time-total');
            if (cur && pbCur) cur.textContent = pbCur.textContent || '0:00';
            if (tot && pbTot) tot.textContent = pbTot.textContent || '0:00';
            if (fill && pbFill) fill.style.width = pbFill.style.width || '0%';
            if (fill && pbFill && (!pbFill.style.width || pbFill.style.width === '0%')) {
                const a = document.querySelector('audio');
                if (a && a.duration > 0) {
                    const pct = (a.currentTime / a.duration) * 100;
                    fill.style.width = pct + '%';
                    if (cur) cur.textContent = fmtT(a.currentTime);
                    if (tot) tot.textContent = fmtT(a.duration);
                }
            }
        };
        setInterval(() => { if (document.body.classList.contains('now-playing-open')) updateFsSeek(); }, 250);
        document.querySelector('audio')?.addEventListener('timeupdate', updateFsSeek);

        const npRoot = document.getElementById('now-playing-fullscreen');
        if (npRoot) {
            let __npTouchStartY = null;
            let __npTouchStartX = null;
            let __npTouchTime = 0;
            let __npStartScrollTop = 0;
            let __npDragging = false;
            npRoot.addEventListener('touchstart', (e) => {
                if (!document.body.classList.contains('now-playing-open')) return;
                if (npRoot.dataset.snapState === 'half' && e.touches[0].clientY < window.innerHeight * 0.5) {
                    npRoot.style.transition = 'transform 0.28s cubic-bezier(0.32,0.72,0,1)';
                    npRoot.style.transform = '';
                    npRoot.dataset.snapState = '';
                    setTimeout(() => { npRoot.style.transition = ''; }, 300);
                    try { if (navigator.vibrate) navigator.vibrate(6); } catch (_) {}
                    e.preventDefault();
                    return;
                }
                __npTouchStartY = e.touches[0].clientY;
                __npTouchStartX = e.touches[0].clientX;
                __npTouchTime = Date.now();
                __npStartScrollTop = npRoot.scrollTop || 0;
                __npDragging = false;
            }, { passive: true });
            let __npHapticFired = false;
            npRoot.addEventListener('touchmove', (e) => {
                if (__npTouchStartY === null) return;
                const cy = e.touches[0].clientY;
                const cx = e.touches[0].clientX;
                const dy = cy - __npTouchStartY;
                const dx = Math.abs(cx - __npTouchStartX);
                const onLyrics = e.target.closest && e.target.closest('#np-fs-lyrics');
                if (dy > 10 && dx < Math.abs(dy) && __npStartScrollTop <= 0 && !onLyrics) {
                    __npDragging = true;
                    if (e.cancelable) e.preventDefault();
                    const damped = dy < 200 ? dy * 0.85 : 170 + Math.pow(dy - 200, 0.6) * 4;
                    npRoot.style.transform = 'translateY(' + Math.min(damped, 320) + 'px)';
                    npRoot.style.transition = 'none';
                    if (dy > 80 && !__npHapticFired) {
                        __npHapticFired = true;
                        try { if (navigator.vibrate) navigator.vibrate(8); } catch (_) {}
                    } else if (dy <= 80 && __npHapticFired) {
                        __npHapticFired = false;
                    }
                }
            }, { passive: false });
            npRoot.addEventListener('touchend', (e) => {
                if (__npTouchStartY === null) return;
                const ey = (e.changedTouches[0] || {}).clientY ?? __npTouchStartY;
                const ex = (e.changedTouches[0] || {}).clientX ?? __npTouchStartX;
                const dy = ey - __npTouchStartY;
                const dx = Math.abs(ex - __npTouchStartX);
                const dt = Date.now() - __npTouchTime;
                const wasDragging = __npDragging;
                __npTouchStartY = null;
                __npDragging = false;
                const onLyrics = e.target.closest && (e.target.closest('#np-fs-lyrics') || e.target.closest('.np-lyrics-title'));
                if (onLyrics && dy < -50 && dx < 80 && dt < 600) {
                    npRoot.style.transform = '';
                    npRoot.style.transition = '';
                    if (window.__openLyricsFullscreen) window.__openLyricsFullscreen();
                    return;
                }
                const velocity = dy / Math.max(dt, 1);
                const vh = window.innerHeight || 800;
                const SNAP_HALF = vh * 0.45;
                const SNAP_FULL = vh * 0.85;
                const shouldClose = wasDragging && (dy > SNAP_FULL || (velocity > 0.8 && dy > 60));
                const shouldHalf = wasDragging && !shouldClose && dy > SNAP_HALF && velocity > 0.2;
                __npHapticFired = false;
                if (shouldHalf) {
                    npRoot.style.transition = 'transform 0.25s cubic-bezier(0.32,0.72,0,1)';
                    npRoot.style.transform = 'translateY(' + Math.round(vh * 0.5) + 'px)';
                    npRoot.dataset.snapState = 'half';
                    setTimeout(() => { npRoot.style.transition = ''; }, 270);
                    try { if (navigator.vibrate) navigator.vibrate(6); } catch (_) {}
                    return;
                }
                if (shouldClose) {
                    npRoot.style.transition = 'transform 0.28s cubic-bezier(0.32,0.72,0,1)';
                    npRoot.style.transform = 'translateY(100%)';
                    setTimeout(() => {
                        if (typeof close === 'function') close();
                        npRoot.style.transform = '';
                        npRoot.style.transition = '';
                    }, 270);
                } else if (wasDragging) {
                    npRoot.style.transition = 'transform 0.22s cubic-bezier(0.32,0.72,0,1)';
                    npRoot.style.transform = '';
                    setTimeout(() => { npRoot.style.transition = ''; }, 240);
                }
            }, { passive: true });
            const lyricsBox = document.getElementById('np-fs-lyrics');
            if (lyricsBox) {
                lyricsBox.addEventListener('scroll', () => { window.__mobileLyricsUserScroll = Date.now(); }, { passive: true });
                lyricsBox.addEventListener('dblclick', () => {
                    if (window.__openLyricsFullscreen) window.__openLyricsFullscreen();
                });
                lyricsBox.addEventListener('click', (ev) => {
                    if (ev.target.closest('.np-lyric-line')) return;
                    const txt = (lyricsBox.textContent || '').trim();
                    if (txt.indexOf('No lyrics') === 0 || txt.indexOf('Could not load') === 0 || txt.indexOf('Tap to retry') !== -1 || txt.indexOf('Searched') !== -1) {
                        const meta = window.__currentTrackMeta || {};
                        const titleSrcRaw = (meta.title || document.getElementById('pb-title')?.textContent || '').trim();
                        const artistSrcRaw = (meta.artist || document.getElementById('pb-artist')?.textContent || '').trim();
                        const artistSrc = artistSrcRaw.split(/\s+·\s+on\s+/i)[0].split(/\s+·\s+/)[0].trim();
                        if (!titleSrcRaw || titleSrcRaw === '—' || !artistSrc) {
                            lyricsBox.innerHTML = '<div>No song info</div>' +
                                '<div style="margin-top:8px;font-size:11px;color:rgba(255,255,255,0.4)">title=' + escMobile(titleSrcRaw) + ' | artist=' + escMobile(artistSrc) + '</div>';
                            return;
                        }
                        const audioEl = document.querySelector('audio');
                        const dur = Math.round((audioEl && audioEl.duration) || 0);
                        const reqUrl = '/api/lyrics?force=1&artist=' + encodeURIComponent(artistSrc) + '&title=' + encodeURIComponent(titleSrcRaw) + '&duration=' + dur;
                        lyricsBox.innerHTML = '<div>Fetching forced…</div>' +
                            '<div style="margin-top:8px;font-size:10px;color:rgba(255,255,255,0.4);word-break:break-all">' + escMobile(reqUrl) + '</div>';
                        fetch(reqUrl, { cache: 'no-store' })
                            .then(r => r.text().then(t => ({ status: r.status, body: t })))
                            .then(res => {
                                let d = null;
                                try { d = JSON.parse(res.body); } catch(_) {}
                                if (!d || !d.found || (!d.plain && !d.synced)) {
                                    const preview = (res.body || '').slice(0, 200).replace(/\s+/g, ' ');
                                    lyricsBox.innerHTML = '<div>No lyrics for this track</div>' +
                                        '<div style="margin-top:12px;font-size:11px;color:rgba(255,255,255,0.5)">Status: ' + res.status + '</div>' +
                                        '<div style="margin-top:6px;font-size:11px;color:rgba(255,255,255,0.4)">Searched: ' + escMobile(titleSrcRaw) + ' — ' + escMobile(artistSrc) + '</div>' +
                                        '<div style="margin-top:6px;font-size:10px;color:rgba(255,255,255,0.35);word-break:break-all">Response: ' + escMobile(preview) + '</div>' +
                                        '<div style="margin-top:8px;font-size:11px;color:rgba(255,255,255,0.6)">Tap to retry</div>';
                                    return;
                                }
                                if (d.synced) {
                                    const parsed = parseMobileLrc(d.synced);
                                    if (parsed.length) {
                                        window.__mobileLyricsLines = parsed;
                                        lyricsBox.innerHTML = parsed.map((l, i) =>
                                            '<div class="np-lyric-line" data-i="' + i + '" data-t="' + l.time.toFixed(2) + '">' + (l.text ? escMobile(l.text) : '♪') + '</div>'
                                        ).join('');
                                        return;
                                    }
                                }
                                const text = (d.plain || '').trim();
                                lyricsBox.textContent = text || 'No lyrics for this track';
                            })
                            .catch(err => {
                                lyricsBox.innerHTML = '<div>Could not load lyrics</div>' +
                                    '<div style="margin-top:6px;font-size:11px;color:rgba(255,255,255,0.4)">Error: ' + escMobile(err && err.message ? err.message : String(err)) + '</div>' +
                                    '<div style="margin-top:8px;font-size:11px;color:rgba(255,255,255,0.6)">Tap to retry</div>';
                            });
                    }
                });
            }
        }
        const seekBar = document.getElementById('np-fs-seek-bar');
        seekBar?.addEventListener('click', (e) => {
            const a = document.querySelector('audio');
            if (!a || !a.duration) return;
            const r = seekBar.getBoundingClientRect();
            const pct = (e.clientX - r.left) / r.width;
            a.currentTime = Math.max(0, Math.min(a.duration, pct * a.duration));
        });
        try { syncMeta(); } catch (_) {}
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileNp);
    } else {
        initMobileNp();
    }
    setTimeout(initMobileNp, 300);
    setTimeout(initMobileNp, 800);
    setTimeout(initMobileNp, 1500);
    setInterval(initMobileNp, 2000);
    window.__initMobileNp = initMobileNp;

    document.querySelectorAll('.mobile-bottom-nav a').forEach(a => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const href = a.getAttribute('href');
            if (typeof navigate === 'function') {
                navigate(href);
            } else if (window.__navigate) {
                window.__navigate(href);
            } else {
                window.location.href = href;
            }
            document.querySelectorAll('.mobile-bottom-nav a').forEach(x => x.classList.remove('active'));
            a.classList.add('active');
        });
    });
    (function() {
        const btn = document.getElementById('mobile-menu-btn');
        if (!btn) return;
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            document.body.classList.toggle('sidebar-open');
        });
        document.addEventListener('click', (e) => {
            if (!document.body.classList.contains('sidebar-open')) return;
            const inSidebar = e.target.closest('.sidebar');
            const onBtn = e.target.closest('#mobile-menu-btn');
            if (onBtn) return;
            if (!inSidebar) {
                document.body.classList.remove('sidebar-open');
                return;
            }
            if (e.target.closest('a, .sidebar-item, .playlist-item')) {
                document.body.classList.remove('sidebar-open');
            }
        }, true);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                document.body.classList.remove('sidebar-open');
            }
        });
    })();
    </script>

    <button class="np-edge-toggle" id="np-edge-toggle" title="Show Now Playing"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
    <aside class="now-playing-panel" id="now-playing-panel">
        <div class="np-head">
            <div class="np-head-title" id="np-head-title">Now Playing</div>
            <div class="np-head-actions">
                <button class="np-icon-btn" id="np-close-view" title="Back" style="display:none"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg></button>
                <button class="np-icon-btn" id="np-lyrics-fullscreen-btn" title="Full screen lyrics (L)" style="display:none" onclick="window.__openLyricsFullscreen && window.__openLyricsFullscreen()"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></button>
                <button class="np-icon-btn" id="np-close-panel" title="Close Now Playing"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
        </div>
        <div class="np-view" id="np-view-info" data-np-view="info">
            <img class="np-cover" id="np-cover" alt="" onerror="this.style.opacity='0.3'">
            <h2 class="np-title" id="np-title">—</h2>
            <p class="np-artist" id="np-artist-name">—</p>

            <div class="np-section-head">About the artist</div>
            <a class="np-artist-card" id="np-artist-card" href="#">
                <div class="np-artist-avatar"><?= self::icon('user', 24) ?></div>
                <div>
                    <div class="np-artist-name" id="np-artist-card-name">—</div>
                    <div class="np-artist-role">Artist</div>
                </div>
            </a>

            <div class="np-section-head" style="margin-top:24px">Up next</div>
            <div id="np-up-next-list"><div style="color:var(--text-muted);font-size:13px;padding:8px">Queue is empty</div></div>
        </div>
        <div class="np-view" id="np-view-queue" data-np-view="queue" style="display:none">
            <div id="np-queue-list"></div>
        </div>
        <div class="np-view" id="np-view-lyrics" data-np-view="lyrics" style="display:none">
            <div class="np-lyrics-empty" id="np-lyrics-empty">Play a track to see lyrics</div>
            <div class="np-lyrics" id="np-lyrics-text" style="display:none"></div>
        </div>
    </aside>

    <div class="player-bar">
        <div class="pb-left">
            <div class="pb-cover"><?= self::icon('music', 24) ?></div>
            <div class="pb-meta">
                <a class="pb-title" href="#" id="pb-title" style="cursor:pointer">—</a>
                <a class="pb-artist" id="pb-artist" href="#" style="text-decoration:none;cursor:pointer"></a>
            </div>
            <button class="pb-btn pb-btn-mini" id="pb-favorite" title="Favorite"><?= self::icon('heart', 18) ?></button>
        </div>
        <div class="pb-center">
            <div class="pb-controls">
                <button class="pb-btn" id="pb-shuffle" title="Smart play — queue similar tracks"><?= self::icon('shuffle', 18) ?></button>
                <button class="pb-btn" id="pb-prev" title="Previous"><?= self::icon('skip-back', 18) ?></button>
                <button class="pb-btn pb-btn-play" id="pb-play" title="Play / Pause"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg></button>
                <button class="pb-btn" id="pb-next" title="Next"><?= self::icon('skip-forward', 18) ?></button>
                <button class="pb-btn" id="pb-repeat" title="Repeat"><?= self::icon('repeat', 18) ?></button>
            </div>
            <div class="pb-seek">
                <span class="pb-time" id="pb-time-current">0:00</span>
                <div class="pb-seek-bar" id="pb-seek-bar">
                    <div class="pb-seek-fill" id="pb-seek-fill"><div class="pb-seek-handle"></div></div>
                </div>
                <span class="pb-time" id="pb-time-total">0:00</span>
            </div>
        </div>
        <div class="pb-right">
            <button class="pb-btn" id="pb-lyrics" title="Lyrics"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a3 3 0 0 0-3 3v6a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3z"/><path d="M19 11a1 1 0 0 0-2 0 5 5 0 0 1-10 0 1 1 0 0 0-2 0 7 7 0 0 0 6 6.92V20H8a1 1 0 0 0 0 2h8a1 1 0 0 0 0-2h-3v-2.08A7 7 0 0 0 19 11z"/></svg></button>
            <button class="pb-btn" id="pb-queue-toggle" title="Queue"><?= self::icon('list-music', 18) ?></button>
            <button class="pb-btn" id="pb-devices" title="Devices"><?= self::icon('monitor', 18) ?></button>
            <button class="pb-btn" id="pb-jam" title="Jam — listen together"><?= self::icon('users', 18) ?></button>
            <button class="pb-btn" id="pb-volume" title="Mute / Unmute"><?= self::icon('volume', 18) ?></button>
            <div class="pb-volume-bar" id="pb-volume-bar">
                <div class="pb-volume-fill" id="pb-volume-fill" style="width:80%"><div class="pb-volume-handle"></div></div>
            </div>
        </div>
    </div>

    <aside class="side-panel" id="side-panel">
        <div class="side-panel-stars" aria-hidden="true">
            <div class="stars-layer stars-1"></div>
            <div class="stars-layer stars-2"></div>
            <div class="stars-layer stars-3"></div>
        </div>
        <header class="side-panel-header">
            <h3 id="side-panel-title">Queue</h3>
            <button class="side-panel-close" id="side-panel-close" aria-label="Close">
                <?= self::icon('x', 18) ?>
            </button>
        </header>
        <div class="side-panel-body" id="side-panel-body"></div>
    </aside>

    <div class="modal-backdrop" id="modal-backdrop"></div>
    <div class="modal" id="jam-modal">
        <header class="modal-header">
            <h3>Jam — listen together</h3>
            <button class="modal-close" data-modal-close aria-label="Close"><?= self::icon('x', 18) ?></button>
        </header>
        <div class="modal-body" id="jam-modal-body"></div>
    </div>

    <div class="modal" id="import-modal">
        <header class="modal-header">
            <h3>Import playlist</h3>
            <button class="modal-close" data-modal-close aria-label="Close"><?= self::icon('x', 18) ?></button>
        </header>
        <div class="modal-body" id="import-modal-body"></div>
    </div>

    <audio id="audio-player" preload="auto" playsinline webkit-playsinline>
</div>
<script defer src="/assets/js/app.js?v=<?= htmlspecialchars(Env::get('APP_VERSION', '0.1.0')) ?>-<?= filemtime(__DIR__ . '/../../assets/js/app.js') ?: time() ?>-2"></script>
</body>
</html>
        <?php
    }

    private static function navItem(string $href, string $icon, string $label, string $active): void
    {
        $isActive = $href === $active;
        $cls = 'sidebar-item' . ($isActive ? ' active' : '');
        echo '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">'
           . self::icon($icon, 22)
           . '<span>' . htmlspecialchars($label) . '</span>'
           . '</a>';
    }

    public static function icon(string $name, int $size = 20): string
    {
        return Icons::svg($name, $size);
    }
}
