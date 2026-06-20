<?php

declare(strict_types=1);

namespace Doniixify\Web;

use Doniixify\Database;
use Doniixify\Env;
use Doniixify\Scanner\Scanner;

final class Views
{
    public static function libraryLiked(): void
    {
        $user = Session::requireLogin();
        $uid = (int)$user['id'];
        $favSongs = [];
        try {
            $favSongs = Database::fetchAll(
                "SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                        ar.id AS artist_id, ar.name AS artist_name,
                        al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
                 FROM stars st
                 JOIN songs s ON s.id = st.item_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 LEFT JOIN albums al ON al.id = s.album_id
                 WHERE st.user_id = ? AND st.item_type = 'song'
                 ORDER BY st.starred_at DESC",
                [$uid]
            ) ?: [];
        } catch (\Throwable $e) {}
        $cnt = count($favSongs);
        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Liked Songs</h1>
                <div class="page-subtitle"><?= $cnt ?> song<?= $cnt === 1 ? '' : 's' ?></div>
            </div>
        </header>
        <?php if (empty($favSongs)): ?>
            <div class="empty-state"><div class="icon"><?= Icons::svg('heart', 32) ?></div><h2>No liked songs</h2><p>Tap heart on any track.</p></div>
        <?php else: self::renderSongsTable($favSongs); endif; ?>
        <?php
        Layout::render('/library/liked', ob_get_clean());
    }

    public static function library(): void
    {
        $user = Session::requireLogin();
        $uid = (int)$user['id'];
        $playlists = [];
        $favSongs = [];
        $favCount = 0;
        try {
            $playlists = Database::fetchAll(
                'SELECT id, name, song_count FROM playlists WHERE user_id = ? ORDER BY id DESC',
                [$uid]
            ) ?: [];
        } catch (\Throwable $e) { $playlists = []; }
        try {
            $favSongs = Database::fetchAll(
                "SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                        ar.id AS artist_id, ar.name AS artist_name,
                        al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
                 FROM stars st
                 JOIN songs s ON s.id = st.item_id
                 LEFT JOIN artists ar ON ar.id = s.artist_id
                 LEFT JOIN albums al ON al.id = s.album_id
                 WHERE st.user_id = ? AND st.item_type = 'song'
                 ORDER BY st.starred_at DESC",
                [$uid]
            ) ?: [];
            $favCount = count($favSongs);
        } catch (\Throwable $e) { $favSongs = []; $favCount = 0; }

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Your Library</h1>
                <div class="page-subtitle"><?= count($playlists) ?> playlist<?= count($playlists) === 1 ? '' : 's' ?> · <?= $favCount ?> liked</div>
            </div>
        </header>

        <a href="/library/liked" style="display:flex;align-items:center;gap:12px;padding:8px;text-decoration:none;color:inherit;margin-bottom:6px">
            <div style="width:56px;height:56px;border-radius:6px;background:var(--bg-surface-2,rgba(255,255,255,0.06));display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--text-secondary,#b3b3b3)">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:600;font-size:14px">Liked Songs</div>
                <div style="font-size:12px;color:var(--text-muted)">Playlist · <?= $favCount ?> song<?= $favCount === 1 ? '' : 's' ?></div>
            </div>
        </a>

        <div style="display:flex;flex-direction:column;gap:6px">
            <?php foreach ($playlists as $pl):
                $name = htmlspecialchars((string)$pl['name']);
                $count = (int)$pl['song_count'];
            ?>
                <a href="/playlist/<?= (int)$pl['id'] ?>" style="display:flex;align-items:center;gap:12px;padding:8px;text-decoration:none;color:inherit">
                    <div style="width:56px;height:56px;border-radius:6px;background:var(--bg-surface-2,rgba(255,255,255,0.06));display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--text-secondary,#b3b3b3)">
                        <?= Icons::svg('list', 24) ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div style="font-weight:600;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $name ?></div>
                        <div style="font-size:12px;color:var(--text-muted)">Playlist · <?= $count ?> track<?= $count === 1 ? '' : 's' ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if (empty($playlists)): ?>
                <div style="padding:16px;color:var(--text-muted);font-size:14px;text-align:center">No playlists yet. Create one from a track context menu.</div>
            <?php endif; ?>
        </div>
        <?php
        Layout::render('/library', ob_get_clean());
    }

    public static function home(): void
    {
        $user = Session::requireLogin();
        self::maybeAutoScan();

        $userId = (int)($user['id'] ?? 0);
        $totalCount = (int)Database::pdo()->query('SELECT COUNT(*) FROM songs')->fetchColumn();
        $myCount = $userId > 0
            ? (int)(Database::fetchOne('SELECT COUNT(*) AS c FROM songs WHERE downloaded_by = ?', [$userId])['c'] ?? 0)
            : 0;

        if ($totalCount === 0) {
            ob_start();
            ?>
            <header class="page-header">
                <div>
                    <h1 class="page-title">Home</h1>
                    <div class="page-subtitle">Welcome <?= htmlspecialchars($user['username']) ?></div>
                </div>
            </header>
            <div class="empty-state">
                <div class="icon"><?= Icons::svg('music', 32) ?></div>
                <h2>No tracks in library</h2>
                <p>Use search to find and download music.</p>
                <a href="/search" class="btn"><?= Icons::svg('search', 16) ?> Search music</a>
            </div>
            <?php
            Layout::render('/', ob_get_clean());
            return;
        }

        if ($myCount === 0) {
            ob_start();
            ?>
            <header class="page-header">
                <div>
                    <h1 class="page-title">Home</h1>
                    <div class="page-subtitle">Welcome <?= htmlspecialchars($user['username']) ?></div>
                </div>
            </header>
            <div class="empty-state">
                <div class="icon"><?= Icons::svg('music', 32) ?></div>
                <h2>No tracks yet</h2>
                <p>Find music and download to your library — it will appear here.</p>
                <a href="/search" class="btn"><?= Icons::svg('search', 16) ?> Search music</a>
            </div>
            <?php
            Layout::render('/', ob_get_clean());
            return;
        }

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Home</h1>
                <div class="page-subtitle">Welcome <?= htmlspecialchars($user['username']) ?></div>
            </div>
        </header>

        <div id="recently-played-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">Recently played</h2>
            <div id="recently-played-grid" class="hscroll-row" style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:14px;overflow-x:auto !important;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:none"></div>
        </div>

        <div id="top-week-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">Top this week</h2>
            <div id="top-week-grid" class="hscroll-row" style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:14px;overflow-x:auto !important;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:none"></div>
        </div>
        <script>
        (async function(){
            try {
                const r = await fetch('/api/me/top-week');
                const d = await r.json();
                if (!d || !d.items || !d.items.length) return;
                const widget = document.getElementById('top-week-widget');
                const grid = document.getElementById('top-week-grid');
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                grid.innerHTML = d.items.slice(0, 10).map((s, i) =>
                    '<a href="#" data-tw="' + s.id + '" data-t="' + esc(s.title) + '" data-ar="' + esc(s.artist_name || '') + '" style="text-decoration:none;color:inherit;display:block">' +
                    '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:8px;position:relative"><img src="/cover/' + s.id + '" alt="" style="width:100%;height:100%;object-fit:cover" onerror="window.__handleMissingCover && window.__handleMissingCover(this)"><div style="position:absolute;top:6px;left:6px;background:rgba(0,0,0,0.7);color:#fff;font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px">#' + (i+1) + '</div><div style="position:absolute;bottom:6px;right:6px;background:rgba(var(--accent-rgb,30,215,96),0.9);color:#000;font-size:10px;font-weight:800;padding:2px 6px;border-radius:4px">' + s.plays + ' plays</div></div>' +
                    '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                    '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist_name || '') + '</div>' +
                    '</a>'
                ).join('');
                widget.style.display = 'block';
                grid.querySelectorAll('[data-tw]').forEach(a => a.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (window.doniixify && window.doniixify.loadSong) window.doniixify.loadSong(Number(a.dataset.tw), a.dataset.t, a.dataset.ar, true);
                }));
            } catch(_) {}
        })();
        </script>

        <div id="for-you-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">Made for you <span style="font-weight:400;color:var(--text-muted);font-size:13px">· based on your listening</span></h2>
            <div id="for-you-grid" class="hscroll-row" style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:14px;overflow-x:auto !important;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:none"></div>
        </div>
        <script>
        (async function(){
            try {
                const r = await fetch('/api/recommendations/for-me');
                const d = await r.json();
                if (!d || !d.items || !d.items.length) return;
                const widget = document.getElementById('for-you-widget');
                const grid = document.getElementById('for-you-grid');
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                grid.innerHTML = d.items.slice(0, 10).map(s =>
                    '<a href="#" data-fy="' + s.id + '" data-t="' + esc(s.title) + '" data-ar="' + esc(s.artist_name || '') + '" style="text-decoration:none;color:inherit;display:block">' +
                    '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:8px;position:relative"><img src="/cover/' + s.id + '" alt="" style="width:100%;height:100%;object-fit:cover" onerror="window.__handleMissingCover && window.__handleMissingCover(this)"></div>' +
                    '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                    '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist_name || '') + '</div>' +
                    '</a>'
                ).join('');
                widget.style.display = 'block';
                grid.querySelectorAll('[data-fy]').forEach(a => a.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (window.doniixify && window.doniixify.loadSong) window.doniixify.loadSong(Number(a.dataset.fy), a.dataset.t, a.dataset.ar, true);
                }));
            } catch(_) {}
        })();
        </script>

        <div id="new-in-lib-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">New in library</h2>
            <div id="new-in-lib-grid" class="hscroll-row" style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:14px;overflow-x:auto !important;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:none"></div>
        </div>
        <script>
        (async function(){
            try {
                const r = await fetch('/api/me/recently-added');
                const d = await r.json();
                if (!d || !d.items || !d.items.length) return;
                const widget = document.getElementById('new-in-lib-widget');
                const grid = document.getElementById('new-in-lib-grid');
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                grid.innerHTML = d.items.slice(0, 10).map(s =>
                    '<a href="#" data-na="' + s.id + '" data-t="' + esc(s.title) + '" data-ar="' + esc(s.artist_name || '') + '" style="text-decoration:none;color:inherit;display:block">' +
                    '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:8px"><img src="/cover/' + s.id + '" alt="" data-song-id="' + s.id + '" style="width:100%;height:100%;object-fit:cover" onerror="window.__handleMissingCover && window.__handleMissingCover(this)"></div>' +
                    '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                    '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist_name || '') + '</div>' +
                    '</a>'
                ).join('');
                widget.style.display = 'block';
                grid.querySelectorAll('[data-na]').forEach(a => a.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (window.doniixify?.loadSong) window.doniixify.loadSong(Number(a.dataset.na), a.dataset.t, a.dataset.ar, true);
                }));
            } catch(_) {}
        })();
        </script>

        <div id="random-mix-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">Surprise mix <span style="font-weight:400;color:var(--text-muted);font-size:13px">· refresh page for new picks</span></h2>
            <div id="random-mix-grid" class="hscroll-row" style="display:flex !important;flex-direction:row !important;flex-wrap:nowrap !important;gap:14px;overflow-x:auto !important;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:none"></div>
        </div>
        <script>
        (async function(){
            try {
                const r = await fetch('/api/me/random-pool');
                const d = await r.json();
                if (!d || !d.items || !d.items.length) return;
                const widget = document.getElementById('random-mix-widget');
                const grid = document.getElementById('random-mix-grid');
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                grid.innerHTML = d.items.slice(0, 10).map(s =>
                    '<a href="#" data-rm="' + s.id + '" data-t="' + esc(s.title) + '" data-ar="' + esc(s.artist_name || '') + '" style="text-decoration:none;color:inherit;display:block">' +
                    '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:8px"><img src="/cover/' + s.id + '" alt="" data-song-id="' + s.id + '" style="width:100%;height:100%;object-fit:cover" onerror="window.__handleMissingCover && window.__handleMissingCover(this)"></div>' +
                    '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                    '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist_name || '') + '</div>' +
                    '</a>'
                ).join('');
                widget.style.display = 'block';
                grid.querySelectorAll('[data-rm]').forEach(a => a.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (window.doniixify?.loadSong) window.doniixify.loadSong(Number(a.dataset.rm), a.dataset.t, a.dataset.ar, true);
                }));
            } catch(_) {}
        })();
        </script>

        <div id="discover-widget" style="margin-bottom:32px;display:none">
            <h2 style="font-size:18px;font-weight:800;margin:0 0 12px;letter-spacing:-0.01em">Discover</h2>
            <div id="discover-sections"></div>
        </div>

        <script>
        window.__addScrollArrows = (grid) => {
            if (!grid || grid.dataset.arrowsBound === '1') return;
            grid.dataset.arrowsBound = '1';
            const parent = grid.parentElement;
            if (!parent) return;
            if (getComputedStyle(parent).position === 'static') parent.style.position = 'relative';
            const mkBtn = (dir) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'hscroll-arrow hscroll-arrow-' + dir;
                b.setAttribute('aria-label', dir === 'left' ? 'Scroll left' : 'Scroll right');
                b.innerHTML = dir === 'left'
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
                b.style.cssText = 'position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:36px;height:36px;border-radius:50%;background:rgba(0,0,0,0.75);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,0.1);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.18s ease,transform 0.18s ease;' + (dir === 'left' ? 'left:-4px' : 'right:-4px');
                b.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const w = grid.clientWidth;
                    grid.scrollBy({ left: (dir === 'left' ? -1 : 1) * w * 0.75, behavior: 'smooth' });
                });
                b.addEventListener('mouseenter', () => b.style.transform = 'translateY(-50%) scale(1.08)');
                b.addEventListener('mouseleave', () => b.style.transform = 'translateY(-50%)');
                return b;
            };
            const left = mkBtn('left');
            const right = mkBtn('right');
            parent.appendChild(left);
            parent.appendChild(right);
            const update = () => {
                const canLeft = grid.scrollLeft > 4;
                const canRight = grid.scrollLeft + grid.clientWidth < grid.scrollWidth - 4;
                left.style.opacity = canLeft ? '1' : '0';
                left.style.pointerEvents = canLeft ? 'auto' : 'none';
                right.style.opacity = canRight ? '1' : '0';
                right.style.pointerEvents = canRight ? 'auto' : 'none';
            };
            update();
            grid.addEventListener('scroll', update, { passive: true });
            window.addEventListener('resize', update, { passive: true });
            const mo = new MutationObserver(update);
            mo.observe(grid, { childList: true, subtree: false });
        };
        document.addEventListener('DOMContentLoaded', () => {
            const tryWrap = () => {
                document.querySelectorAll('.hscroll-row').forEach(g => window.__addScrollArrows(g));
            };
            setTimeout(tryWrap, 200);
            setTimeout(tryWrap, 1200);
            setTimeout(tryWrap, 3000);
        });
        </script>

        <script>
        (function(){
            const widget = document.getElementById('discover-widget');
            if (!widget) return;
            let loaded = false;
            const load = async () => {
                if (loaded) return; loaded = true;
                try {
                    const r = await fetch('/api/lastfm/discover');
                    if (!r.ok) return;
                    const d = await r.json();
                    if (!d || d.error) return;
                    render(d);
                } catch(_) {}
            };
            const io = new IntersectionObserver((entries) => {
                entries.forEach(e => { if (e.isIntersecting) { io.disconnect(); load(); } });
            }, { rootMargin: '300px' });
            io.observe(widget);
            const render = (d) => {
                const sections = document.getElementById('discover-sections');
                if (!sections) return;
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                const renderRow = (label, items) => {
                    if (!items || !items.length) return '';
                    return '<div style="margin-bottom:18px"><h3 style="font-size:13px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.08em;margin:0 0 10px">' + esc(label) + ' <span style="font-size:10px;font-weight:600;color:rgb(var(--accent-rgb,30,215,96));margin-left:6px">· last.fm</span></h3>' +
                        '<div class="hscroll-row" style="display:flex;gap:12px;overflow-x:auto;overflow-y:hidden;scroll-snap-type:x mandatory;padding-bottom:8px;scrollbar-width:thin">' +
                        items.map(s => {
                            const localId = s.local_id ? Number(s.local_id) : 0;
                            const placeholder = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1" fill="%23222"/></svg>';
                            const initialSrc = localId ? ('/cover/' + localId) : (s.image || placeholder);
                            const needsLookup = !localId && !s.image;
                            return '<a href="#"' +
                                ' data-local-id="' + localId + '" data-title="' + esc(s.title) + '" data-artist="' + esc(s.artist) + '"' +
                                ' style="text-decoration:none;color:inherit;display:block;position:relative">' +
                                '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:6px;position:relative">' +
                                '<img src="' + esc(initialSrc) + '" alt="" style="width:100%;height:100%;object-fit:cover"' +
                                (needsLookup ? ' data-cover-lookup="1"' : '') +
                                ' referrerpolicy="no-referrer" onerror="window.__handleMissingCover && window.__handleMissingCover(this)">' +
                                '</div>' +
                                '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                                '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist) + '</div>' +
                                '</a>';
                        }).join('') +
                        '</div></div>';
                };
                let html = '';
                if ((d.trending || []).length) html += renderRow('Trending on Last.fm', d.trending);
                if ((d.similar || []).length && d.based_on) html += renderRow('Because you like ' + d.based_on, d.similar);
                if ((d.top_artist_tracks || []).length && d.top_artist) html += renderRow('Top tracks · ' + d.top_artist, d.top_artist_tracks);
                if (!html) return;
                sections.innerHTML = html;
                widget.style.display = 'block';
                const coverCache = window.__itunesCoverCache || (window.__itunesCoverCache = new Map());
                const lookupCover = async (img) => {
                    const a = img.closest('a[data-title]');
                    if (!a) return;
                    const artist = a.dataset.artist || '';
                    const title = a.dataset.title || '';
                    const key = (artist + '||' + title).toLowerCase();
                    if (coverCache.has(key)) {
                        const url = coverCache.get(key);
                        if (url) img.src = url;
                        return;
                    }
                    try {
                        const r = await fetch('/api/lastfm/cover-lookup?artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(title));
                        const j = await r.json();
                        const url = j?.url || '';
                        coverCache.set(key, url);
                        if (url) img.src = url;
                    } catch (_) {}
                };
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(e => {
                        if (!e.isIntersecting) return;
                        const img = e.target;
                        observer.unobserve(img);
                        delete img.dataset.coverLookup;
                        lookupCover(img);
                    });
                }, { rootMargin: '200px' });
                sections.querySelectorAll('img[data-cover-lookup]').forEach(img => observer.observe(img));
                sections.querySelectorAll('[data-local-id]').forEach(a => {
                    a.addEventListener('click', async (e) => {
                        e.preventDefault();
                        const localId = Number(a.dataset.localId || 0);
                        const title = a.dataset.title || '';
                        const artist = a.dataset.artist || '';
                        if (localId > 0 && window.doniixify && window.doniixify.loadSong) {
                            window.doniixify.loadSong(localId, title, artist, true);
                            return;
                        }
                        try {
                            const fd = new FormData();
                            fd.append('artist', artist);
                            fd.append('title', title);
                            const r = await fetch('/api/lastfm/queue-download', { method: 'POST', body: fd });
                            const d2 = await r.json();
                            if (d2 && d2.ok) {
                                if (window.__showToast) window.__showToast((d2.already_local ? 'Already in library: ' : 'Downloading: ') + artist + ' — ' + title);
                            } else {
                                if (window.__showToast) window.__showToast('Download failed: ' + (d2?.error || 'unknown'));
                            }
                        } catch (_) {}
                    });
                });
            };
        })();
        </script>
        <script>
        (async function(){
            try {
                const r = await fetch('/api/me/recently-played?limit=10');
                const d = await r.json();
                if (!d || !d.items || !d.items.length) return;
                const widget = document.getElementById('recently-played-widget');
                const grid = document.getElementById('recently-played-grid');
                if (!widget || !grid) return;
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                grid.innerHTML = d.items.map(s =>
                    '<a href="#" data-recent-id="' + s.id + '" data-t="' + esc(s.title) + '" data-ar="' + esc(s.artist_name || '') + '" style="text-decoration:none;color:inherit;display:block">' +
                    '<div style="aspect-ratio:1;background:#222;border-radius:8px;overflow:hidden;margin-bottom:8px"><img src="/cover/' + s.id + '" alt="" style="width:100%;height:100%;object-fit:cover" onerror="window.__handleMissingCover && window.__handleMissingCover(this)"></div>' +
                    '<div style="font-size:13px;font-weight:600;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.title) + '</div>' +
                    '<div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(s.artist_name || '') + '</div>' +
                    '</a>'
                ).join('');
                widget.style.display = 'block';
                grid.querySelectorAll('[data-recent-id]').forEach(a => {
                    a.addEventListener('click', (e) => {
                        e.preventDefault();
                        const id = a.dataset.recentId;
                        if (window.doniixify && window.doniixify.loadSong) {
                            window.doniixify.loadSong(Number(id), a.dataset.t || '', a.dataset.ar || '', true);
                        }
                    });
                });
            } catch(_) {}
        })();
        </script>

        <?php
        Layout::render('/', ob_get_clean());
    }

    public static function songs(): void
    {
        Session::requireLogin();
        self::maybeAutoScan();
        self::renderTracks('Songs', 'Full library', '/songs', null);
    }

    private static function fetchArtistSpotifyTopTracks(string $artistName, array $localSongs, int $userId): array
    {
        if ($artistName === '') return [];
        $cacheDir = __DIR__ . '/../../storage/cache/spotify-artist';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cacheKey = md5(mb_strtolower($artistName));
        $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
        $cached = null;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $cached = json_decode((string)@file_get_contents($cacheFile), true);
        }

        if (!is_array($cached)) {
            $token = \Doniixify\Downloader\SpotifyApi::getAccessToken();
            if ($token === '') return [];

            $searchUrl = 'https://api.spotify.com/v1/search?type=artist&limit=1&market=PL&q=' . rawurlencode('artist:"' . str_replace('"', '', $artistName) . '"');
            $searchData = self::spotifyApiGet($searchUrl, $token);
            $spotifyArtistId = '';
            if (is_array($searchData) && !empty($searchData['artists']['items'][0]['id'])) {
                $spotifyArtistId = (string)$searchData['artists']['items'][0]['id'];
            }
            if (!preg_match('/^[A-Za-z0-9]{22}$/', $spotifyArtistId)) return [];

            $topData = self::spotifyApiGet('https://api.spotify.com/v1/artists/' . rawurlencode($spotifyArtistId) . '/top-tracks?market=PL', $token);
            $tracks = is_array($topData) && !empty($topData['tracks']) ? $topData['tracks'] : [];
            $cached = $tracks;
            @file_put_contents($cacheFile, json_encode($tracks, JSON_UNESCAPED_UNICODE));
        }
        if (empty($cached)) return [];

        $haveKeys = [];
        foreach ($localSongs as $s) {
            $haveKeys[mb_strtolower((string)$s['artist_name']) . '||' . mb_strtolower((string)$s['title'])] = true;
        }

        $pending = [];
        foreach ($cached as $t) {
            if (!is_array($t)) continue;
            $title = (string)($t['name'] ?? '');
            $tArtist = '';
            if (!empty($t['artists'][0]['name'])) $tArtist = (string)$t['artists'][0]['name'];
            if ($title === '' || $tArtist === '') continue;
            $key = mb_strtolower($tArtist) . '||' . mb_strtolower($title);
            if (isset($haveKeys[$key])) continue;
            $sid = (string)($t['id'] ?? '');
            $albumName = (string)($t['album']['name'] ?? '');
            $coverUrl = (string)($t['album']['images'][0]['url'] ?? '');
            $release = (string)($t['album']['release_date'] ?? '');
            $pending[] = [
                'title' => $title,
                'artist' => $tArtist,
                'album' => $albumName,
                'release_date' => $release,
                'cover_url' => $coverUrl,
                'spotify_id' => $sid,
            ];
            if ($sid !== '') {
                $hint = [
                    'title' => $title,
                    'artist' => $tArtist,
                    'album' => $albumName,
                    'cover_url' => $coverUrl,
                    'release_date' => substr($release, 0, 10),
                    'duration_ms' => (int)($t['duration_ms'] ?? 0),
                    'spotify_id' => $sid,
                    'song_id' => $sid,
                    'user_id' => $userId,
                ];
                try { \Doniixify\Downloader\YoutubeDownloader::queueBackground('https://open.spotify.com/track/' . $sid, $hint); } catch (\Throwable $e) {}
            }
        }
        return $pending;
    }

    private static function spotifyApiGet(string $url, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body)) return null;
        $data = json_decode($body, true);
        return is_array($data) ? $data : null;
    }

    public static function artist(int $artistId): void
    {
        Session::requireLogin();
        $artist = Database::fetchOne('SELECT id, name FROM artists WHERE id = ?', [$artistId]);
        if ($artist === null) {
            http_response_code(404);
            Layout::render('/', '<div class="empty-state"><h2>Artist not found</h2></div>');
            return;
        }
        $songs = Database::fetchAll(
            'SELECT s.id, s.title, s.duration, s.created_at AS song_added_at, s.play_count, s.spotify_id,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             JOIN albums al ON al.id = s.album_id
             WHERE s.artist_id = ?
             ORDER BY s.play_count DESC, s.title_sort ASC',
            [$artistId]
        );
        $totalCount = count($songs);
        $pendingSpotify = self::fetchArtistSpotifyTopTracks((string)$artist['name'], $songs, (int)(Session::user()['id'] ?? 0));
        $albums = Database::fetchAll(
            'SELECT al.id, al.name, al.year, al.cover_id, COUNT(s.id) AS song_count
             FROM albums al
             LEFT JOIN songs s ON s.album_id = al.id
             WHERE al.artist_id = ?
             GROUP BY al.id, al.name, al.year, al.cover_id
             ORDER BY al.year DESC, al.name ASC',
            [$artistId]
        );

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($artist['name']) ?></h1>
                <div class="page-subtitle"><?= $totalCount ?> in library<?php if (!empty($pendingSpotify)): ?> · <?= count($pendingSpotify) ?> more on Spotify<?php endif; ?></div>
            </div>
            <?php if (!empty($songs)): ?>
                <button type="button" class="btn" id="artist-play-all"><?= Icons::svg('play-fill', 16) ?> Play top</button>
            <?php endif; ?>
        </header>
        <?php
        if (empty($songs) && empty($pendingSpotify)) {
            ?><div class="empty-state"><div class="icon"><?= Icons::svg('music', 32) ?></div><h2>No tracks</h2></div><?php
        } else {
            echo '<div data-artist-view="1">';
            if (!empty($songs)) self::renderSongsTable($songs);
            if (!empty($pendingSpotify)) {
                echo '<div class="pending-tracks-section"><div class="pending-tracks-label">More on Spotify · <span data-pending-count>' . count($pendingSpotify) . '</span></div>';
                foreach ($pendingSpotify as $p) {
                    $pTitle = (string)($p['title'] ?? '');
                    $pArtist = (string)($p['artist'] ?? '');
                    $pAlbum = (string)($p['album'] ?? '');
                    $pReleased = substr((string)($p['release_date'] ?? ''), 0, 4);
                    $pKey = mb_strtolower($pArtist) . '||' . mb_strtolower($pTitle);
                    $metaLine = trim(implode(' · ', array_filter([$pAlbum, $pReleased])));
                    echo '<div class="pending-track" data-key="' . htmlspecialchars($pKey, ENT_QUOTES) . '" data-search="' . htmlspecialchars(mb_strtolower($pTitle . ' ' . $pArtist . ' ' . $pAlbum), ENT_QUOTES) . '">';
                    echo '<div class="pending-track-spinner"></div>';
                    echo '<div class="pending-track-meta">';
                    echo '<div class="pending-track-title">' . htmlspecialchars($pTitle) . '</div>';
                    echo '<div class="pending-track-artist">' . htmlspecialchars($metaLine !== '' ? $metaLine : 'Downloading from Spotify') . '</div>';
                    echo '<div class="pending-track-stage" data-stage>queued</div>';
                    echo '</div>';
                    echo '<div class="pending-track-progress"><div class="pending-track-progress-bar" data-progress style="width:0%"></div></div>';
                    echo '</div>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        if (!empty($albums)): ?>
            <section class="discography" style="margin-top:48px">
                <h2 style="font-size:22px;font-weight:700;margin:0 0 20px">Discography</h2>
                <div class="album-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:20px">
                    <?php foreach ($albums as $al):
                        $cnt = (int)$al['song_count'];
                        $year = (int)($al['year'] ?? 0);
                    ?>
                        <div class="album-card-wrap" style="position:relative">
                            <a class="album-card" href="/album/<?= (int)$al['id'] ?>" style="display:block;text-decoration:none;color:inherit;background:rgba(255,255,255,0.03);padding:14px;border-radius:10px;transition:background 0.15s">
                                <div style="aspect-ratio:1;background:var(--bg-surface-2,rgba(255,255,255,0.06));border-radius:8px;margin-bottom:12px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden">
                                    <?php if (!empty($al['cover_id'])): ?>
                                        <img src="/album-cover/<?= (int)$al['id'] ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:8px" onerror="this.style.display='none'">
                                    <?php else: ?>
                                        <?= Icons::svg('disc', 32) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-weight:600;font-size:14px;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($al['name']) ?></div>
                                <div style="font-size:12px;color:var(--text-secondary);margin-top:4px">
                                    <?= $cnt ?> track<?= $cnt === 1 ? '' : 's' ?><?= $year ? ' · ' . $year : '' ?>
                                </div>
                            </a>
                            <div class="album-mini-bar" data-album-id="<?= (int)$al['id'] ?>">
                                <button class="album-mini-btn album-mini-play" data-act="play" title="Play"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg></button>
                                <button class="album-mini-btn" data-act="shuffle" title="Shuffle"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/></svg></button>
                                <button class="album-mini-btn" data-act="add" title="Add to queue"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <script>
        document.getElementById('artist-play-all')?.addEventListener('click', () => {
            const first = document.querySelector('[data-artist-view="1"] tr[data-song-id]');
            if (first) first.click();
        });
        </script>
        <?php
        Layout::render('/artist/' . $artistId, ob_get_clean());
    }

    public static function favorites(): void
    {
        Session::requireLogin();
        $user = Session::user();
        $songs = Database::fetchAll(
            "SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                    ar.id AS artist_id, ar.name AS artist_name,
                    al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
             FROM songs s
             JOIN artists ar ON ar.id = s.artist_id
             JOIN albums al ON al.id = s.album_id
             JOIN stars st ON st.item_id = s.id AND st.item_type = 'song'
             WHERE st.user_id = ?
             ORDER BY st.starred_at DESC",
            [$user['id']]
        );

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Favorites</h1>
                <div class="page-subtitle"><?= count($songs) ?> tracks</div>
            </div>
        </header>
        <?php
        if (empty($songs)) {
            ?><div class="empty-state"><div class="icon"><?= Icons::svg('heart', 32) ?></div><h2>No favorites yet</h2><p>Tap the heart on any track to save it here.</p></div><?php
        } else {
            self::renderSongsTable($songs);
        }
        Layout::render('/favorites', ob_get_clean());
    }

    public static function discover(string $artistName): void
    {
        Session::requireLogin();
        $artistName = trim($artistName);
        if ($artistName === '') {
            header('Location: /search');
            exit;
        }
        header('Location: /search?q=' . urlencode($artistName) . '&t=artist&hero=' . urlencode($artistName));
        exit;
    }

    public static function album(int $deezerAlbumId): void
    {
        Session::requireLogin();

        $albumData = self::fetchDeezerAlbum($deezerAlbumId);
        if ($albumData === null) {
            http_response_code(404);
            Layout::render('/', '<div class="empty-state"><h2>Album not found</h2><p>The Deezer ID ' . $deezerAlbumId . ' returned no data.</p></div>');
            return;
        }

        $title = $albumData['title'] ?? 'Unknown Album';
        $artist = $albumData['artist']['name'] ?? 'Unknown Artist';
        $cover = $albumData['cover_xl'] ?? $albumData['cover_big'] ?? $albumData['cover_medium'] ?? '';
        $releaseDate = $albumData['release_date'] ?? '';
        $recordType = $albumData['record_type'] ?? 'album';
        $tracks = $albumData['tracks']['data'] ?? [];
        $totalDuration = 0;
        foreach ($tracks as $t) { $totalDuration += (int)($t['duration'] ?? 0); }
        $year = $releaseDate ? substr($releaseDate, 0, 4) : '';
        $fullReleased = self::formatReleaseDate((string)$releaseDate, $year !== '' ? (int)$year : null);

        ob_start();
        ?>
        <header class="page-header" style="display:flex;align-items:flex-end;gap:32px;padding-bottom:32px;margin-bottom:32px;border-bottom:1px solid rgba(255,255,255,0.06)">
            <?php if ($cover): ?>
                <div style="width:220px;height:220px;border-radius:12px;overflow:hidden;flex-shrink:0;box-shadow:0 24px 48px -16px rgba(0,0,0,0.6)">
                    <img src="<?= htmlspecialchars($cover) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                </div>
            <?php endif; ?>
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.6);font-weight:600;margin-bottom:8px"><?= htmlspecialchars(ucfirst($recordType)) ?></div>
                <h1 class="page-title" style="margin:0;font-size:56px;line-height:1.1;font-weight:900"><?= htmlspecialchars($title) ?></h1>
                <div style="margin-top:16px;color:rgba(255,255,255,0.8);font-size:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                    <a href="/discover/<?= rawurlencode($artist) ?>" style="color:#fff;text-decoration:none;font-weight:700"><?= htmlspecialchars($artist) ?></a>
                    <?php if ($fullReleased !== '—'): ?>· <span><?= htmlspecialchars($fullReleased) ?></span><?php endif; ?>
                    · <span><?= count($tracks) ?> track<?= count($tracks) === 1 ? '' : 's' ?></span>
                    <?php if ($totalDuration > 0): ?>· <span><?= sprintf('%d min', intdiv($totalDuration, 60)) ?></span><?php endif; ?>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <button id="album-download-all" class="btn" style="padding:12px 22px;background:linear-gradient(135deg,#1971c2,#7c3aed);color:#fff;border:0;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:8px">
                    <?= Icons::svg('download', 16) ?> Download all (<?= count($tracks) ?>)
                </button>
                <a href="javascript:history.back()" style="padding:10px 18px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#fff;text-decoration:none;font-size:13px;font-weight:600;text-align:center">← Back</a>
            </div>
        </header>
        <div id="album-bulk-status" style="display:none;margin-bottom:24px;padding:12px 16px;border-radius:10px;background:rgba(29,155,194,0.1);border:1px solid rgba(29,155,194,0.3);color:#9cd5ff;font-size:14px"></div>

        <div id="album-results">
            <table class="song-table">
                <thead><tr><th class="col-idx">#</th><th>Title</th><th class="col-time"><?= Icons::svg('clock', 14) ?></th><th style="width:120px"></th></tr></thead>
                <tbody>
                <?php foreach ($tracks as $i => $t):
                    $tTitle = htmlspecialchars((string)($t['title'] ?? ''));
                    $tArtist = htmlspecialchars((string)($t['artist']['name'] ?? $artist));
                    $tDuration = (int)($t['duration'] ?? 0);
                    $tDurStr = sprintf('%d:%02d', intdiv($tDuration, 60), $tDuration % 60);
                    $tDeezerId = (string)($t['id'] ?? '');
                    $tUrl = 'deezer:' . $tDeezerId;
                ?>
                    <tr data-spotify-url="<?= htmlspecialchars($tUrl) ?>" data-title="<?= $tTitle ?>" data-artist="<?= $tArtist ?>">
                        <td class="col-idx" style="color:rgba(255,255,255,0.4)"><?= $i + 1 ?></td>
                        <td><div class="song-cell"><div class="song-info"><div class="song-title"><?= $tTitle ?></div><div style="color:rgba(255,255,255,0.5);font-size:12px;margin-top:2px"><?= $tArtist ?></div></div></div></td>
                        <td class="col-time"><?= $tDurStr ?></td>
                        <td><button class="btn btn-secondary spotify-dl-btn" style="padding:6px 12px;font-size:12px"><?= Icons::svg('download', 14) ?> Download</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <script>
        (function() {
            const results = document.getElementById('album-results');
            const markDownloaded = (btn, libraryId) => {
                btn.disabled = false;
                btn.innerHTML = '▶ Play';
                btn.style.background = '#00ba7c';
                btn.style.color = '#fff';
                btn.style.borderColor = '#00ba7c';
                if (libraryId) {
                    btn.dataset.libraryId = libraryId;
                    const tr = btn.closest('tr');
                    if (tr) tr.dataset.localSongId = libraryId;
                }
                btn.classList.add('play-from-library');
            };
            const markProgress = (btn) => {
                btn.disabled = true;
                btn.innerHTML = 'Downloading…';
                btn.style.background = '#1d9bf0';
                btn.style.color = '#fff';
            };
            const markError = (btn, msg) => {
                btn.disabled = false;
                btn.innerHTML = msg;
                btn.style.background = '#f4212e';
                btn.style.color = '#fff';
            };
            const triggerDownload = async (btn, url, title, artist) => {
                markProgress(btn);
                try {
                    const res = await fetch('/api/spotify/download', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ url, title, artist }),
                    });
                    if (!res.ok) {
                        const d = await res.json().catch(() => ({}));
                        markError(btn, 'Error: ' + (d.error || res.status));
                        return false;
                    }
                    setTimeout(() => markDownloaded(btn), 1500);
                    return true;
                } catch (err) {
                    markError(btn, 'Network error');
                    return false;
                }
            };

            results.querySelectorAll('.spotify-dl-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const tr = e.target.closest('tr');
                    await triggerDownload(btn, tr.dataset.spotifyUrl, tr.dataset.title || '', tr.dataset.artist || '');
                });
            });

            const allBtn = document.getElementById('album-download-all');
            const statusBox = document.getElementById('album-bulk-status');
            if (allBtn) {
                allBtn.addEventListener('click', async () => {
                    const rows = Array.from(results.querySelectorAll('tr[data-spotify-url]'));
                    if (rows.length === 0) return;
                    allBtn.disabled = true;
                    allBtn.style.opacity = '0.6';
                    allBtn.style.cursor = 'wait';
                    statusBox.style.display = 'block';
                    let done = 0, fail = 0;
                    const total = rows.length;
                    const update = () => {
                        statusBox.textContent = `Queueing downloads… ${done + fail}/${total}` + (fail ? ` (${fail} failed)` : '');
                    };
                    update();
                    for (const tr of rows) {
                        const btn = tr.querySelector('.spotify-dl-btn');
                        if (!btn || btn.disabled) { done++; update(); continue; }
                        const ok = await triggerDownload(btn, tr.dataset.spotifyUrl, tr.dataset.title || '', tr.dataset.artist || '');
                        if (ok) done++; else fail++;
                        update();
                        await new Promise(r => setTimeout(r, 250));
                    }
                    statusBox.textContent = `Queued ${done}/${total} downloads. Tracks appear in library after Doniixify finishes processing.`;
                    statusBox.style.background = 'rgba(0,186,124,0.1)';
                    statusBox.style.borderColor = 'rgba(0,186,124,0.3)';
                    statusBox.style.color = '#5ce0a5';
                    allBtn.innerHTML = '✓ Queued ' + done;
                    allBtn.style.background = '#00ba7c';
                });
            }
        })();
        </script>
        <?php
        Layout::render('/album/' . $deezerAlbumId, ob_get_clean());
    }

    private static function fetchDeezerAlbum(int $id): ?array
    {
        $ch = curl_init('https://api.deezer.com/album/' . $id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || !is_string($body) || $body === '') return null;
        $data = json_decode($body, true);
        return is_array($data) && empty($data['error']) ? $data : null;
    }

    public static function search(): void
    {
        Session::requireLogin();
        $q = trim($_GET['q'] ?? '');
        $initialType = (($_GET['t'] ?? 'title') === 'artist') ? 'artist' : 'title';
        $heroArtist = trim($_GET['hero'] ?? '');
        $heroInitial = $heroArtist !== '' ? mb_strtoupper(mb_substr($heroArtist, 0, 1, 'UTF-8')) : '';

        ob_start();
        ?>
        <?php if ($heroArtist !== ''): ?>
        <div class="discover-hero" id="discover-hero" style="position:relative;margin:-32px -32px 24px;padding:64px 32px 32px;min-height:380px;display:flex;flex-direction:column;justify-content:flex-end;background:linear-gradient(180deg, transparent 0%, rgba(0,0,0,0.7) 70%, #0a0a0d 100%), linear-gradient(135deg,#3b1c5e,#1a2540);overflow:hidden">
            <div id="discover-cover-bg" style="position:absolute;inset:0;z-index:0;opacity:0;background:no-repeat center/cover;filter:blur(8px) brightness(0.7);transition:opacity 0.6s ease"></div>
            <div style="position:relative;z-index:1">
                <div style="font-size:13px;text-transform:uppercase;letter-spacing:0.1em;color:rgba(255,255,255,0.85);font-weight:700;margin-bottom:12px">Artist</div>
                <h1 style="margin:0;font-size:96px;line-height:1;font-weight:900;letter-spacing:-0.02em;color:#fff;text-shadow:0 2px 24px rgba(0,0,0,0.5)"><?= htmlspecialchars($heroArtist) ?></h1>
                <div style="margin-top:20px;color:rgba(255,255,255,0.8);font-size:14px;font-weight:500" id="discover-count">Loading discography…</div>
            </div>
            <div style="position:absolute;top:24px;right:24px;z-index:2;display:flex;gap:8px">
                <button id="discover-refresh" title="Force refresh from sources" style="padding:8px 14px;background:rgba(0,0,0,0.5);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;font-size:13px;font-weight:600;cursor:pointer">↻ Refresh</button>
                <a href="/search" style="padding:8px 16px;background:rgba(0,0,0,0.5);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.15);border-radius:10px;color:#fff;text-decoration:none;font-size:13px;font-weight:600">← Back</a>
            </div>
        </div>
        <form action="/search" id="spotify-search-form" style="display:none">
            <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
        </form>
        <?php else: ?>
        <header class="page-header">
            <div>
                <h1 class="page-title">Search</h1>
                <?php if ($q !== ''): ?>
                    <div class="page-subtitle">"<?= htmlspecialchars($q) ?>"</div>
                <?php endif; ?>
            </div>
            <form action="/search" id="spotify-search-form" style="position:relative">
                <input type="text" name="q" id="search-input-main" class="search-input" placeholder="Track title, artist, or paste Spotify URL…" value="<?= htmlspecialchars($q) ?>" autofocus autocomplete="off">
                <div id="search-history-dropdown" style="position:absolute;top:100%;left:0;right:0;background:#16181c;border:1px solid var(--border);border-radius:10px;margin-top:6px;box-shadow:0 8px 32px rgba(0,0,0,0.4);max-height:280px;overflow-y:auto;display:none;z-index:50"></div>
            </form>
            <script>
            (function(){
                const input = document.getElementById('search-input-main');
                const dropdown = document.getElementById('search-history-dropdown');
                if (!input || !dropdown || !window.__searchHistory) return;
                const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                const render = () => {
                    const items = window.__searchHistory.get();
                    if (!items.length) { dropdown.style.display = 'none'; return; }
                    dropdown.innerHTML = '<div style="padding:8px 14px;font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;display:flex;justify-content:space-between"><span>Recent searches</span><button type="button" id="sh-clear-all" style="background:transparent;border:0;color:var(--text-muted);cursor:pointer;font-size:11px;text-transform:uppercase">Clear</button></div>' +
                        items.map(q => '<div class="sh-item" data-q="' + esc(q) + '" style="padding:9px 14px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;color:#e7e9ea;font-size:14px"><span style="display:flex;align-items:center;gap:10px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' + esc(q) + '</span><button type="button" class="sh-rm" data-q="' + esc(q) + '" style="background:transparent;border:0;color:var(--text-muted);cursor:pointer;padding:2px 6px;font-size:16px">×</button></div>').join('');
                    dropdown.style.display = 'block';
                    dropdown.querySelectorAll('.sh-item').forEach(it => {
                        it.addEventListener('click', (e) => {
                            if (e.target.closest('.sh-rm')) return;
                            input.value = it.dataset.q;
                            input.form?.submit();
                        });
                    });
                    dropdown.querySelectorAll('.sh-rm').forEach(b => b.addEventListener('click', (e) => {
                        e.stopPropagation();
                        window.__searchHistory.remove(b.dataset.q);
                        render();
                    }));
                    document.getElementById('sh-clear-all')?.addEventListener('click', () => { window.__searchHistory.clear(); render(); });
                };
                input.addEventListener('focus', render);
                input.addEventListener('input', () => { if (!input.value.trim()) render(); else dropdown.style.display = 'none'; });
                document.addEventListener('click', (e) => { if (!input.form.contains(e.target)) dropdown.style.display = 'none'; });
            })();
            </script>
        </header>
        <?php endif; ?>

        <?php
        $recentSearches = [];
        if ($q === '' || mb_strlen($q) < 2) {
            $currentUser = Session::user();
            if ($currentUser) {
                try {
                    $recentSearches = Database::fetchAll(
                        'SELECT query, query_type, hit_count FROM search_history WHERE user_id = ? ORDER BY last_searched_at DESC LIMIT 12',
                        [(int)$currentUser['id']]
                    );
                } catch (\Throwable $e) { $recentSearches = []; }
            }
        }
        ?>
        <?php if (!empty($recentSearches)): ?>
            <div class="recent-searches" style="margin-bottom:24px">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
                    <div style="color:var(--text-secondary);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em">Recent searches</div>
                    <button id="recent-clear" type="button" style="background:none;border:0;color:var(--text-muted);cursor:pointer;font-size:12px;font-weight:600">Clear</button>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap" id="recent-chips">
                    <?php foreach ($recentSearches as $rs): ?>
                        <span class="recent-chip-wrap" data-q="<?= htmlspecialchars($rs['query']) ?>" style="display:inline-flex;align-items:stretch;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.08);border-radius:18px;overflow:hidden">
                            <button type="button" class="recent-chip" data-q="<?= htmlspecialchars($rs['query']) ?>" style="padding:6px 12px 6px 14px;background:none;border:0;color:var(--text-primary);font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                                <?= htmlspecialchars($rs['query']) ?>
                                <span style="color:var(--text-muted);font-size:11px"><?= (int)$rs['hit_count'] ?></span>
                            </button>
                            <button type="button" class="recent-chip-x" data-q="<?= htmlspecialchars($rs['query']) ?>" title="Remove" style="padding:0 10px;background:none;border:0;border-left:1px solid rgba(255,255,255,0.08);color:var(--text-muted);cursor:pointer;font-size:14px;line-height:1">×</button>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <div id="spotify-results">
            <?php if ($q === '' || mb_strlen($q) < 2): ?>
                <div class="empty-state">
                    <div class="icon"><?= Icons::svg('search', 32) ?></div>
                    <h2>Type a track name</h2>
                    <p>Minimum 2 characters. Searches public tracks on Spotify — hit <strong>Download</strong> to add it to your library.</p>
                </div>
            <?php else: ?>
                <div class="empty-state"><div class="icon"><?= Icons::svg('search', 32) ?></div><h2>Searching…</h2></div>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            const form = document.getElementById('spotify-search-form');
            const results = document.getElementById('spotify-results');
            const input = form.querySelector('input[name=q]');
            let abortController = null;

            const fmtDuration = (sec) => {
                if (!sec) return '';
                const m = Math.floor(sec / 60);
                const s = String(Math.floor(sec % 60)).padStart(2, '0');
                return `${m}:${s}`;
            };

            const MONTHS_EN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const fmtRel = (raw) => {
                if (!raw) return '—';
                const s = String(raw).trim();
                let m;
                if ((m = s.match(/^(\d{4})-(\d{2})-(\d{2})/))) {
                    const mi = parseInt(m[2], 10) - 1;
                    if (mi >= 0 && mi < 12) return MONTHS_EN[mi] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
                }
                if ((m = s.match(/^(\d{4})-(\d{2})/))) {
                    const mi = parseInt(m[2], 10) - 1;
                    if (mi >= 0 && mi < 12) return MONTHS_EN[mi] + ' ' + m[1];
                }
                if ((m = s.match(/^(\d{4})/))) return m[1];
                return '—';
            };

            const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

            const PAGE_SIZE = 20;
            let allItems = [];
            let currentPage = 1;
            let currentType = <?= json_encode($initialType) ?>;
            const heroArtist = <?= json_encode($heroArtist) ?>;
            const countEl = document.getElementById('discover-count');
            const coverEl = document.getElementById('discover-cover-bg');

            const markDownloaded = (btn, libraryId) => {
                btn.disabled = false;
                btn.innerHTML = '▶ Play';
                btn.style.background = '#00ba7c';
                btn.style.color = '#fff';
                btn.style.borderColor = '#00ba7c';
                if (libraryId) {
                    btn.dataset.libraryId = libraryId;
                    const tr = btn.closest('tr');
                    if (tr) tr.dataset.localSongId = libraryId;
                }
                btn.classList.add('play-from-library');
            };

            const markProgress = (btn, pct) => {
                btn.disabled = true;
                const label = pct >= 0 ? `Downloading ${pct}%` : 'Downloading…';
                btn.innerHTML = `<span style="display:inline-block;width:10px;height:10px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:dl-spin 0.7s linear infinite;margin-right:6px;vertical-align:-2px"></span>${label}`;
                btn.style.background = '#1d9bf0';
                btn.style.color = '#fff';
                btn.style.borderColor = '#1d9bf0';
            };

            const markError = (btn, msg) => {
                btn.disabled = false;
                btn.innerHTML = msg;
                btn.style.background = '#f4212e';
                btn.style.color = '#fff';
                btn.style.borderColor = '#f4212e';
            };

            const showDownloadOverlay = (title, artist) => { return; // disabled — no UI dim
            // legacy:
                let o = document.getElementById('dl-overlay');
                if (!o) {
                    o = document.createElement('div');
                    o.id = 'dl-overlay';
                    o.className = 'dl-overlay';
                    o.innerHTML = `
                        <div class="dl-overlay-card">
                            <div class="dl-spinner"></div>
                            <div class="dl-overlay-title" id="dl-overlay-title">Downloading…</div>
                            <div class="dl-overlay-sub" id="dl-overlay-sub"></div>
                            <div class="dl-overlay-progress" id="dl-overlay-progress"></div>
                            <div class="dl-overlay-hint">You can leave this page, the download continues in the background.</div>
                            <button type="button" class="btn btn-ghost" id="dl-overlay-close" style="margin-top:12px">Continue browsing</button>
                        </div>`;
                    document.body.appendChild(o);
                    document.getElementById('dl-overlay-close').addEventListener('click', () => o.remove());
                }
                document.getElementById('dl-overlay-title').textContent = 'Downloading "' + title + '"';
                document.getElementById('dl-overlay-sub').textContent = artist;
                document.getElementById('dl-overlay-progress').textContent = 'Queued for download…';
                return o;
            };
            const updateDownloadOverlay = (msg, pct) => {
                const el = document.getElementById('dl-overlay-progress');
                if (!el) return;
                el.textContent = pct >= 0 ? `${msg} ${pct}%` : msg;
            };
            const closeDownloadOverlay = () => document.getElementById('dl-overlay')?.remove();

            const pollDownload = (btn, title, artist) => {
                let attempts = 0;
                const max = 60;
                const tick = async () => {
                    attempts++;
                    try {
                        const res = await fetch('/api/library/has-track?title=' + encodeURIComponent(title) + '&artist=' + encodeURIComponent(artist));
                        const data = await res.json();
                        if (data.found) {
                            markDownloaded(btn, data.id);
                            updateDownloadOverlay('Done', 100);
                            setTimeout(closeDownloadOverlay, 1200);
                            if (data.id) fetch('/cover/' + data.id + '?refresh=1').catch(() => {});
                            return;
                        }
                        if (typeof data.progress === 'number') {
                            markProgress(btn, data.progress);
                            updateDownloadOverlay('Downloading', data.progress);
                        } else {
                            updateDownloadOverlay('Working…', -1);
                        }
                    } catch (e) {}
                    if (attempts < max) setTimeout(tick, 3000);
                    else { markDownloaded(btn); closeDownloadOverlay(); }
                };
                setTimeout(tick, 4000);
            };

            const checkExistingTracks = async () => {
                const rows = results.querySelectorAll('tr[data-title]');
                rows.forEach(async (tr) => {
                    const title = tr.dataset.title || '';
                    const artist = tr.dataset.artist || '';
                    if (!title) return;
                    try {
                        const res = await fetch('/api/library/has-track?title=' + encodeURIComponent(title) + '&artist=' + encodeURIComponent(artist));
                        const data = await res.json();
                        if (data.found) {
                            const btn = tr.querySelector('.spotify-dl-btn');
                            if (btn) markDownloaded(btn, data.id);
                        }
                    } catch (e) {}
                });
            };


            const bindDownloadButtons = () => {
                results.querySelectorAll('.spotify-dl-btn').forEach(btn => {
                    btn.addEventListener('click', async (e) => {
                        if (btn.classList.contains('play-from-library')) {
                            const libraryId = btn.dataset.libraryId;
                            const tr2 = e.target.closest('tr');
                            const t2 = tr2?.dataset.title || '';
                            const a2 = tr2?.dataset.artist || '';
                            if (libraryId && window.doniixify?.loadSong) {
                                window.doniixify.loadSong(libraryId, t2, a2, true);
                            }
                            return;
                        }
                        const tr = e.target.closest('tr');
                        const url = tr.dataset.spotifyUrl;
                        const id = tr.dataset.spotifyId;
                        const title = tr.dataset.title || '';
                        const artist = tr.dataset.artist || '';
                        if (!url && !id) {
                            markError(btn, 'Missing track ID');
                            return;
                        }
                        markProgress(btn, -1);
                        showDownloadOverlay(title, artist);

                        try {
                            const res = await fetch('/api/spotify/download', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ url, song_id: id, title, artist }),
                            });
                            const data = await res.json().catch(() => ({}));
                            if (!res.ok) {
                                markError(btn, 'Error: ' + (data.error || res.status));
                                return;
                            }
                            pollDownload(btn, title, artist);
                        } catch (err) {
                            markError(btn, 'Network error');
                        }
                    });
                });
            };

            const coverCache = new Map();
            const lazyLoadCovers = () => {
                const slots = results.querySelectorAll('.song-cover[data-needs-cover="1"]');
                slots.forEach(async (slot) => {
                    const sid = slot.dataset.sid;
                    if (!sid || !/^[A-Za-z0-9]{8,32}$/.test(sid)) { slot.dataset.needsCover = '0'; return; }
                    slot.dataset.needsCover = '0';
                    if (coverCache.has(sid)) {
                        const url = coverCache.get(sid);
                        if (url) slot.innerHTML = `<img src="${escapeHtml(url)}" loading="lazy" alt="">`;
                        return;
                    }
                    try {
                        const res = await fetch('/api/spotify/cover?id=' + encodeURIComponent(sid));
                        const data = await res.json();
                        coverCache.set(sid, data.url || null);
                        if (data.url) slot.innerHTML = `<img src="${escapeHtml(data.url)}" loading="lazy" alt="">`;
                    } catch (e) {}
                });
            };

            const renderAlbumGroups = () => {
                const groups = new Map();
                for (const item of allItems) {
                    const a = item.album || {};
                    const albumName = a.name || 'Singles';
                    const albumId = a.id || null;
                    const cover = (a.images && a.images[0]?.url) || item.cover_url || '';
                    const releaseDate = a.release_date || '';
                    const recordType = a.record_type || '';
                    const year = releaseDate ? releaseDate.substring(0, 4) : '';
                    const key = albumId ? 'id:' + albumId : 'name:' + albumName.toLowerCase();
                    if (!groups.has(key)) {
                        groups.set(key, { id: albumId, name: albumName, cover, year, releaseDate, recordType, tracks: [] });
                    } else {
                        const g = groups.get(key);
                        if (!g.cover && cover) g.cover = cover;
                        if (!g.releaseDate && releaseDate) g.releaseDate = releaseDate;
                    }
                    groups.get(key).tracks.push(item);
                }

                const albums = Array.from(groups.values())
                    .sort((a, b) => {
                        const ad = a.releaseDate || (a.year ? a.year + '-00-00' : '0000-00-00');
                        const bd = b.releaseDate || (b.year ? b.year + '-00-00' : '0000-00-00');
                        if (ad !== bd) return bd.localeCompare(ad);
                        return b.tracks.length - a.tracks.length;
                    });

                if (countEl) countEl.textContent = albums.length + ' album' + (albums.length === 1 ? '' : 's') + ' · ' + allItems.length + ' track' + (allItems.length === 1 ? '' : 's');

                let html = '';

                const popular = allItems.slice(0, 10);
                if (popular.length > 0) {
                    html += `<section style="margin-bottom:48px">
                        <h2 style="margin:0 0 16px;font-size:24px;font-weight:800">Popular</h2>
                        <table class="song-table" style="margin:0"><tbody>`;
                    popular.forEach((item, i) => {
                        const title = escapeHtml(item.name || 'Untitled');
                        const rawArtists = item.artists || [];
                        const artistList = Array.isArray(rawArtists)
                            ? rawArtists.map(a => typeof a === 'string' ? a : (a && a.name) || '')
                            : [String(rawArtists)];
                        const artist = escapeHtml(artistList.filter(Boolean).join(', '));
                        const dur = fmtDuration(item.duration_ms ? item.duration_ms / 1000 : (item.duration || 0));
                        const url = escapeHtml(item.url || '');
                        const sid = escapeHtml(item.song_id || '');
                        const cover = escapeHtml((item.album && item.album.images && item.album.images[0]?.url) || item.cover_url || '');
                        html += `<tr data-spotify-url="${url}" data-spotify-id="${sid}" data-title="${title}" data-artist="${artist}">
                            <td class="col-idx" style="width:40px;color:rgba(255,255,255,0.5)">${i + 1}</td>
                            <td><div class="song-cell" style="display:flex;align-items:center;gap:12px"><div class="song-cover" style="width:40px;height:40px;border-radius:4px;overflow:hidden;flex-shrink:0;background:#0d0d12">${cover ? `<img src="${cover}" alt="" style="width:100%;height:100%;object-fit:cover">` : ''}</div><div class="song-info"><div class="song-title" style="font-weight:600">${title}</div></div></div></td>
                            <td class="col-time" style="color:rgba(255,255,255,0.5);font-size:13px">${dur}</td>
                            <td style="width:120px"><button class="btn btn-secondary spotify-dl-btn" style="padding:6px 12px;font-size:12px">Download</button></td>
                        </tr>`;
                    });
                    html += `</tbody></table></section>`;
                }

                if (albums.length > 0) {
                    html += `<section style="margin-bottom:48px">
                        <h2 style="margin:0 0 20px;font-size:24px;font-weight:800">Discography</h2>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:24px">`;
                    albums.forEach((album) => {
                        const safeName = escapeHtml(album.name);
                        const cover = album.cover ? escapeHtml(album.cover) : '';
                        const released = escapeHtml(fmtRel(album.releaseDate || album.year || ''));
                        const recordType = escapeHtml(album.recordType || 'Album');
                        const linkHref = album.id ? '/album/' + album.id : '#';
                        html += `<a href="${linkHref}" style="text-decoration:none;color:inherit;display:block;cursor:${album.id ? 'pointer' : 'default'}">
                            <div style="padding:16px;border-radius:8px;background:rgba(255,255,255,0.03);transition:background 0.2s" onmouseover="this.style.background='rgba(255,255,255,0.06)'" onmouseout="this.style.background='rgba(255,255,255,0.03)'">
                                <div style="width:100%;aspect-ratio:1;border-radius:6px;overflow:hidden;background:#0d0d12;box-shadow:0 8px 24px -8px rgba(0,0,0,0.5);margin-bottom:12px">
                                    ${cover ? `<img src="${cover}" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover">` : ''}
                                </div>
                                <div style="font-weight:700;font-size:15px;line-height:1.3;margin-bottom:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${safeName}</div>
                                <div style="color:rgba(255,255,255,0.5);font-size:13px">${released !== '—' ? released + ' · ' : ''}${recordType.charAt(0).toUpperCase() + recordType.slice(1)}</div>
                            </div>
                        </a>`;
                    });
                    html += `</div></section>`;
                }

                results.innerHTML = html;
                bindDownloadButtons();
                checkExistingTracks();
            };

            const renderPage = () => {
                const totalPages = Math.max(1, Math.ceil(allItems.length / PAGE_SIZE));
                if (currentPage > totalPages) currentPage = totalPages;
                const start = (currentPage - 1) * PAGE_SIZE;
                const pageItems = allItems.slice(start, start + PAGE_SIZE);

                const titleActive = currentType === 'title';
                const artistActive = currentType === 'artist';
                let html = '<table class="song-table"><thead><tr>'
                    + '<th class="col-idx">#</th>'
                    + '<th class="search-th" data-type="title" style="cursor:pointer;user-select:none;' + (titleActive ? 'color:var(--accent,#1d9bf0)' : 'opacity:0.7') + '">Title' + (titleActive ? ' ▾' : '') + '</th>'
                    + '<th class="search-th" data-type="artist" style="cursor:pointer;user-select:none;' + (artistActive ? 'color:var(--accent,#1d9bf0)' : 'opacity:0.7') + '">Artist' + (artistActive ? ' ▾' : '') + '</th>'
                    + '<th style="opacity:0.7">Album</th>'
                    + '<th style="opacity:0.7;width:90px">Released</th>'
                    + '<th class="col-time">⏱</th>'
                    + '<th style="width:120px"></th>'
                    + '</tr></thead><tbody>';
                pageItems.forEach((item, i) => {
                    const cover = item.cover_url || item.cover || item.image || item.thumbnail || item.album_cover || item.album_image || (item.album && (item.album.cover_url || item.album.image)) || '';
                    const title = escapeHtml(item.name || item.title || item.track_name || item.song_name || 'Untitled');
                    const rawArtists = item.artists || item.artist || [];
                    const artistList = Array.isArray(rawArtists)
                        ? rawArtists.map(a => typeof a === 'string' ? a : (a && a.name) || '')
                        : [String(rawArtists)];
                    const artist = escapeHtml(artistList.filter(Boolean).join(', '));
                    const dur = fmtDuration(item.duration_ms ? item.duration_ms / 1000 : (item.duration || 0));
                    const url = escapeHtml(item.url || item.spotify_url || item.external_url || '');
                    const sid = escapeHtml(item.song_id || item.id || item.spotify_id || item.track_id || '');
                    const albumName = escapeHtml((item.album && item.album.name) || item.album_name || '—');
                    const rawRelease = (item.album && item.album.release_date) || item.release_date || '';
                    const released = escapeHtml(fmtRel(rawRelease));
                    const popularity = Number(item.popularity || 0);
                    const popBadge = popularity >= 70
                        ? `<span title="Popularity ${popularity}/100" style="display:inline-block;padding:1px 6px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:8px;font-size:10px;font-weight:700;color:#04210d;margin-left:6px;vertical-align:middle">${popularity}</span>`
                        : popularity >= 40
                        ? `<span title="Popularity ${popularity}/100" style="display:inline-block;padding:1px 6px;background:rgba(255,255,255,0.08);border-radius:8px;font-size:10px;font-weight:600;color:var(--text-secondary);margin-left:6px;vertical-align:middle">${popularity}</span>`
                        : '';
                    const coverInner = cover
                        ? `<img src="${escapeHtml(cover)}" loading="lazy" alt="" onerror="this.parentElement.innerHTML='<svg xmlns=\\'http://www.w3.org/2000/svg\\' width=\\'18\\' height=\\'18\\' viewBox=\\'0 0 24 24\\' fill=\\'none\\' stroke=\\'currentColor\\' stroke-width=\\'2\\'><path d=\\'M9 18V5l12-2v13\\'/><circle cx=\\'6\\' cy=\\'18\\' r=\\'3\\'/><circle cx=\\'18\\' cy=\\'16\\' r=\\'3\\'/></svg>'">`
                        : '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
                    html += `<tr data-spotify-url="${url}" data-spotify-id="${sid}" data-title="${title}" data-artist="${artist}">
                        <td class="col-idx">${start + i + 1}</td>
                        <td><div class="song-cell">
                            <div class="song-cover" data-needs-cover="${cover ? '0' : '1'}" data-sid="${sid}">${coverInner}</div>
                            <div class="song-info"><div class="song-title">${title}${popBadge}</div></div>
                        </div></td>
                        <td class="muted"><a href="#" class="artist-link search-artist-link" data-artist="${artist}">${artist}</a></td>
                        <td class="muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px">${albumName}</td>
                        <td class="muted" style="font-size:13px">${released}</td>
                        <td class="col-time">${dur}</td>
                        <td><button class="btn btn-secondary spotify-dl-btn" style="padding:6px 12px;font-size:12px"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Download</button></td>
                    </tr>`;
                });
                html += '</tbody></table>';

                if (totalPages > 1) {
                    html += '<div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:24px">';
                    html += `<button class="btn btn-secondary" id="pg-prev" ${currentPage === 1 ? 'disabled style="opacity:0.4"' : ''} style="padding:8px 16px">← Back</button>`;
                    html += `<span style="color:var(--text-secondary);font-size:14px;font-weight:600;padding:0 12px">Page ${currentPage} / ${totalPages}</span>`;
                    html += `<button class="btn btn-secondary" id="pg-next" ${currentPage === totalPages ? 'disabled style="opacity:0.4"' : ''} style="padding:8px 16px">Next →</button>`;
                    html += '</div>';
                }

                results.innerHTML = html;
                bindDownloadButtons();
                lazyLoadCovers();
                checkExistingTracks();
                results.querySelectorAll('.search-artist-link').forEach(a => {
                    a.addEventListener('click', (e) => {
                        e.preventDefault();
                        const name = a.dataset.artist || '';
                        if (!name) return;
                        window.location.href = '/discover/' + encodeURIComponent(name);
                    });
                });
                results.querySelectorAll('.search-th').forEach(th => {
                    th.addEventListener('click', () => {
                        const t = th.dataset.type;
                        if (!t || t === currentType) return;
                        currentType = t;
                        doSearch(input.value.trim());
                    });
                });

                const prev = document.getElementById('pg-prev');
                const next = document.getElementById('pg-next');
                prev?.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderPage(); results.scrollIntoView({behavior:'smooth',block:'start'}); } });
                next?.addEventListener('click', () => { if (currentPage < totalPages) { currentPage++; renderPage(); results.scrollIntoView({behavior:'smooth',block:'start'}); } });
            };

            const render = (items) => {
                if (!items || items.length === 0) {
                    results.innerHTML = '<div class="empty-state"><div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></div><h2>No results</h2><p>Try a different query.</p></div>';
                    if (countEl) countEl.textContent = 'No tracks found';
                    return;
                }
                allItems = items;
                currentPage = 1;
                if (heroArtist) {
                    renderAlbumGroups();
                } else {
                    renderPage();
                    if (countEl) countEl.textContent = items.length + ' track' + (items.length === 1 ? '' : 's');
                }
                if (coverEl && heroArtist) {
                    let heroCover = '';
                    for (const it of items) {
                        if (it.artist_picture) { heroCover = it.artist_picture; break; }
                    }
                    if (!heroCover) heroCover = items[0]?.cover_url || '';
                    if (heroCover) {
                        coverEl.style.backgroundImage = "url('" + heroCover.replace(/'/g, "\\'") + "')";
                        coverEl.style.opacity = '1';
                    }
                }
            };

            const SPOTIFY_URL_RE = /^https?:\/\/(open\.)?spotify\.com\/(playlist|album|artist|track)\/[A-Za-z0-9]+/i;

            const renderSpotifyUrlImport = async (url) => {
                const m = url.match(/\/(playlist|album|artist|track)\/([A-Za-z0-9]+)/i);
                if (!m) return false;
                const kind = m[1].toLowerCase();
                const kindLabel = kind.charAt(0).toUpperCase() + kind.slice(1);
                results.innerHTML = `<div class="empty-state"><h2>Loading link metadata…</h2></div>`;

                let title = '', artist = '', cover = '', extraInfo = '';
                try {
                    const oembed = await fetch('https://open.spotify.com/oembed?url=' + encodeURIComponent(url)).then(r => r.json()).catch(() => null);
                    if (oembed) {
                        const raw = String(oembed.title || '');
                        const partsBy = raw.split(/\s+by\s+/i);
                        if (partsBy.length === 2) { title = partsBy[0].trim(); artist = partsBy[1].trim(); }
                        else { title = raw; }
                        cover = oembed.thumbnail_url || '';
                        if (oembed.author_name) artist = artist || oembed.author_name;
                    }
                } catch (e) {}

                const coverHtml = cover ? `<div style="width:120px;height:120px;border-radius:10px;overflow:hidden;flex-shrink:0;box-shadow:0 8px 24px -8px rgba(0,0,0,0.5)"><img src="${escapeHtml(cover)}" alt="" style="width:100%;height:100%;object-fit:cover"></div>` : '';
                const titleHtml = title ? escapeHtml(title) : `Spotify ${kindLabel}`;
                const artistHtml = artist ? `<div style="color:rgba(255,255,255,0.65);font-size:14px;margin-top:4px">${escapeHtml(artist)}</div>` : '';

                results.innerHTML = `
                    <div style="display:flex;gap:20px;align-items:center;padding:20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:14px;max-width:640px;margin:32px auto 0">
                        ${coverHtml}
                        <div style="flex:1;min-width:0">
                            <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:rgba(255,255,255,0.5);font-weight:600;margin-bottom:4px">${escapeHtml(kindLabel)}</div>
                            <div style="font-size:22px;font-weight:800;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${titleHtml}</div>
                            ${artistHtml}
                            <button class="btn" id="url-import-btn" style="margin-top:14px">Import to library</button>
                        </div>
                    </div>`;
                document.getElementById('url-import-btn').addEventListener('click', async () => {
                    const btn = document.getElementById('url-import-btn');
                    btn.disabled = true;
                    btn.innerHTML = 'Importing…';

                    if (kind === 'track') {
                        try {
                            const res = await fetch('/api/spotify/download', {
                                method: 'POST',
                                headers: {'Content-Type':'application/json'},
                                body: JSON.stringify({ url, title, artist }),
                            });
                            const data = await res.json().catch(() => ({}));
                            if (res.ok && (data.status === 'queued' || data.target)) {
                                btn.innerHTML = 'Downloading in background…';
                                btn.disabled = true;
                                if (window.__showToast) window.__showToast('Track queued — appears in library after download');
                            } else {
                                btn.innerHTML = 'Failed: ' + escapeHtml(data.error || 'Unknown');
                                btn.disabled = false;
                            }
                        } catch (e) {
                            btn.innerHTML = 'Network error';
                            btn.disabled = false;
                        }
                        return;
                    }

                    const ctrl = new AbortController();
                    const tid = setTimeout(() => ctrl.abort(), 10000);
                    try {
                        const res = await fetch('/api/playlists/import', {
                            method: 'POST',
                            headers: {'Content-Type':'application/json'},
                            body: JSON.stringify({ url }),
                            signal: ctrl.signal,
                        });
                        clearTimeout(tid);
                        const data = await res.json();
                        if (res.ok && data.id) {
                            location.href = '/playlist/' + data.id;
                        } else {
                            btn.innerHTML = 'Failed: ' + escapeHtml(data.error || 'Unknown');
                        }
                    } catch (e) {
                        clearTimeout(tid);
                        try {
                            const r = await fetch('/api/playlists');
                            const list = await r.json();
                            if (Array.isArray(list) && list.length) {
                                const latest = list.reduce((a, b) => (b.id > a.id ? b : a));
                                location.href = '/playlist/' + latest.id;
                                return;
                            }
                        } catch (_) {}
                        btn.innerHTML = 'Could not confirm — check sidebar';
                    }
                });
                return true;
            };

            const doSearch = async (q) => {
                if (abortController) abortController.abort();
                abortController = new AbortController();
                if (q.length < 2) {
                    results.innerHTML = '<div class="empty-state"><div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></div><h2>Type a track name</h2><p>Minimum 2 characters.</p></div>';
                    return;
                }
                if (SPOTIFY_URL_RE.test(q)) {
                    await renderSpotifyUrlImport(q);
                    return;
                }
                results.innerHTML = '<div class="empty-state"><div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg></div><h2>Searching…</h2></div>';
                window._discoverForceRefresh = false;
                try {
                    const cacheBust = '&_t=' + Date.now();
                    const res = await fetch('/api/spotify/search?q=' + encodeURIComponent(q) + '&t=' + encodeURIComponent(currentType) + cacheBust, { signal: abortController.signal, cache: 'no-store' });
                    if (!res.ok) {
                        const err = await res.json().catch(() => ({}));
                        results.innerHTML = '<div class="empty-state"><div class="icon" style="color:#fa5252"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div><h2>HTTP error ' + res.status + '</h2><p>' + escapeHtml(err.error || 'Spotify search is not responding. Check the server log at storage/download.log.') + '</p></div>';
                        return;
                    }
                    const data = await res.json();
                    render(data);
                } catch (e) {
                    if (e.name !== 'AbortError') {
                        results.innerHTML = '<div class="empty-state"><h2>Network error</h2><p>' + escapeHtml(e.message) + '</p></div>';
                    }
                }
            };

            let debounceTimer;
            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => doSearch(input.value.trim()), 350);
            });
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                doSearch(input.value.trim());
            });

            document.querySelectorAll('.recent-chip').forEach(chip => {
                chip.addEventListener('click', () => {
                    const q = chip.dataset.q || '';
                    if (!q) return;
                    input.value = q;
                    doSearch(q);
                    input.focus();
                });
            });
            document.querySelectorAll('.recent-chip-x').forEach(x => {
                x.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const q = x.dataset.q || '';
                    if (!q) return;
                    try {
                        const r = await fetch('/api/search/recent/remove', {
                            method: 'POST',
                            headers: {'Content-Type':'application/json'},
                            body: JSON.stringify({ query: q })
                        });
                        if (r.ok) x.closest('.recent-chip-wrap')?.remove();
                    } catch (e2) {}
                    const remaining = document.querySelectorAll('.recent-chip-wrap').length;
                    if (remaining === 0) document.querySelector('.recent-searches')?.remove();
                });
            });
            document.getElementById('recent-clear')?.addEventListener('click', async () => {
                try {
                    const r = await fetch('/api/search/recent/clear', { method: 'POST' });
                    if (r.ok) document.querySelector('.recent-searches')?.remove();
                } catch (e) {}
            });

            const refreshBtn = document.getElementById('discover-refresh');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', () => {
                    window._discoverForceRefresh = true;
                    refreshBtn.disabled = true;
                    refreshBtn.textContent = '↻ Refreshing…';
                    doSearch(input.value.trim()).then(() => {
                        setTimeout(() => {
                            refreshBtn.disabled = false;
                            refreshBtn.textContent = '↻ Refresh';
                        }, 600);
                    });
                });
            }

            // wyczyść stary sessionStorage cache - od teraz brak per-user cache
            try {
                Object.keys(sessionStorage).filter(k => k.startsWith('doniix-search')).forEach(k => sessionStorage.removeItem(k));
            } catch (e) {}
            // odpal search jeśli query w URL
            if (input.value.trim().length >= 2) {
                doSearch(input.value.trim());
            }

            let searchCtx = null;
            const closeSearchCtx = () => { if (searchCtx) { searchCtx.remove(); searchCtx = null; } };
            results.addEventListener('contextmenu', async (e) => {
                let row = e.target.closest('tr[data-local-song-id]');
                if (!row) {
                    const candidate = e.target.closest('tr[data-title]');
                    if (!candidate) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const title = candidate.dataset.title || '';
                    const artist = candidate.dataset.artist || '';
                    if (!title) return;
                    try {
                        const r = await fetch('/api/library/has-track?title=' + encodeURIComponent(title) + '&artist=' + encodeURIComponent(artist));
                        const d = await r.json();
                        if (d.found && d.id) {
                            candidate.dataset.localSongId = d.id;
                            row = candidate;
                        } else { return; }
                    } catch (er) { return; }
                } else {
                    e.preventDefault();
                    e.stopPropagation();
                }
                closeSearchCtx();
                const songId = Number(row.dataset.localSongId);
                if (!songId) return;
                searchCtx = document.createElement('div');
                searchCtx.className = 'ctx-menu open';
                searchCtx.innerHTML = `
                    <div class="ctx-item" data-act="play"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>Play</div>
                    <div class="ctx-divider"></div>
                    <div class="ctx-item ctx-submenu">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                        Add to playlist
                        <span class="ctx-submenu-arrow">›</span>
                        <div class="ctx-submenu-panel" id="search-ctx-pls"><div class="ctx-empty">Loading…</div></div>
                    </div>
                `;
                document.body.appendChild(searchCtx);
                const rect = searchCtx.getBoundingClientRect();
                searchCtx.style.left = Math.min(e.clientX, window.innerWidth - rect.width - 8) + 'px';
                searchCtx.style.top = Math.min(e.clientY, window.innerHeight - rect.height - 8) + 'px';

                searchCtx.querySelector('[data-act="play"]').addEventListener('click', () => {
                    closeSearchCtx();
                    const title = row.dataset.title || '';
                    const artist = row.dataset.artist || '';
                    if (window.doniixify?.loadSong) {
                        window.doniixify.loadSong(songId, title, artist, true);
                    }
                });

                try {
                    const r = await fetch('/api/playlists');
                    const pls = await r.json();
                    const panel = document.getElementById('search-ctx-pls');
                    if (!panel) return;
                    let html = `<div class="ctx-item" data-new="1"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New playlist</div>`;
                    if (pls.length) {
                        html += '<div class="ctx-divider"></div>';
                        pls.forEach(p => {
                            html += `<div class="ctx-item" data-pl="${p.id}">${(p.name || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))} <span style="margin-left:auto;color:var(--text-muted);font-size:12px">${p.song_count}</span></div>`;
                        });
                    }
                    panel.innerHTML = html;
                    panel.querySelectorAll('[data-pl]').forEach(item => {
                        item.addEventListener('click', async () => {
                            const plId = Number(item.dataset.pl);
                            closeSearchCtx();
                            try {
                                const res = await fetch(`/api/playlists/${plId}/add`, {
                                    method: 'POST',
                                    headers: {'Content-Type':'application/json'},
                                    body: JSON.stringify({ song_id: songId }),
                                });
                                if (res.ok && window.__showToast) window.__showToast('Added to playlist');
                                else if (window.__showToast) window.__showToast('Add failed');
                            } catch (e2) {}
                        });
                    });
                    panel.querySelector('[data-new]')?.addEventListener('click', async () => {
                        closeSearchCtx();
                        const name = prompt('Playlist name:');
                        if (!name) return;
                        try {
                            const cr = await fetch('/api/playlists/create', {
                                method: 'POST',
                                headers: {'Content-Type':'application/json'},
                                body: JSON.stringify({ name, song_id: songId }),
                            });
                            if (cr.ok && window.__showToast) window.__showToast(`Added to "${name}"`);
                        } catch (e2) {}
                    });
                } catch (e2) {}
            });
            document.addEventListener('click', (e) => {
                if (searchCtx && !e.target.closest('.ctx-menu')) closeSearchCtx();
            });
        })();
        </script>
        <?php
        Layout::render('/search', ob_get_clean());
    }

    private static function renderTracks(string $title, string $subtitle, string $route, ?string $whereClause): void
    {
        $songs = self::fetchSongs($whereClause, [], 1000);

        ob_start();
        ?>
        <header class="page-header">
            <div>
                <h1 class="page-title"><?= htmlspecialchars($title) ?></h1>
                <div class="page-subtitle"><?= htmlspecialchars($subtitle) ?></div>
            </div>
        </header>
        <?php
        if (empty($songs)) {
            ?><div class="empty-state"><div class="icon"><?= Icons::svg('music', 32) ?></div><h2>No tracks</h2><p>Upload files and refresh.</p></div><?php
        } else {
            self::renderSongsTable($songs);
        }
        Layout::render($route, ob_get_clean());
    }

    public static function renderSongsTablePublic(array $songs): void
    {
        self::renderSongsTable($songs);
    }

    private static function renderSongsTable(array $songs): void
    {
        $user = Session::user();
        $favIds = [];
        if ($user && !empty($songs)) {
            $ids = array_values(array_filter(array_column($songs, 'id')));
            if (empty($ids)) $ids = [0];
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll(
                "SELECT item_id FROM stars WHERE user_id = ? AND item_type='song' AND item_id IN ({$placeholders})",
                array_merge([$user['id']], $ids)
            );
            $favIds = array_flip(array_column($rows, 'item_id'));
        }
        ?>
        <table class="song-table sortable-table" data-sortable="1">
            <thead>
                <tr>
                    <th class="col-idx">#</th>
                    <th class="sortable" data-sort-key="title" data-sort-type="text">Title <span class="sort-arrow"></span></th>
                    <th class="sortable" data-sort-key="artist" data-sort-type="text">Artist <span class="sort-arrow"></span></th>
                    <th class="sortable" data-sort-key="album" data-sort-type="text">Album <span class="sort-arrow"></span></th>
                    <th class="sortable col-added" data-sort-key="released" data-sort-type="date">Released <span class="sort-arrow"></span></th>
                    <th class="col-fav" title="Favorite"><?= Icons::svg('heart', 14) ?></th>
                    <th class="col-time" title="Duration"><?= Icons::svg('clock', 14) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($songs as $i => $s):
                    $title = htmlspecialchars(self::cleanText($s['title'] ?? ''));
                    $artist = htmlspecialchars(self::cleanText($s['artist_name'] ?? ''));
                    $artistId = isset($s['artist_id']) ? (int)$s['artist_id'] : null;
                    $albumName = htmlspecialchars(self::cleanText($s['album_name'] ?? ''));
                    $albumId = isset($s['album_id']) ? (int)$s['album_id'] : null;
                    $releaseYear = isset($s['album_year']) && (int)$s['album_year'] > 0 ? (int)$s['album_year'] : null;
                    $releaseDateRaw = isset($s['album_release_date']) ? trim((string)$s['album_release_date']) : '';
                    $released = self::formatReleaseDate($releaseDateRaw, $releaseYear);
                    $releaseSort = $releaseDateRaw !== '' ? $releaseDateRaw : ($releaseYear !== null ? $releaseYear . '-00-00' : '0000-00-00');
                    $isFav = isset($favIds[$s['id']]);
                ?>
                    <tr data-song-id="<?= (int)$s['id'] ?>" data-title="<?= $title ?>" data-artist="<?= $artist ?>" data-album="<?= $albumName ?>" data-released="<?= htmlspecialchars($releaseSort) ?>" data-artist-id="<?= $artistId ?? '' ?>" data-album-id="<?= $albumId ?? '' ?>">
                        <td class="col-idx">
                            <span class="idx-num"><?= $i + 1 ?></span>
                            <button class="row-play-btn"><?= Icons::svg('play-fill', 14) ?></button>
                        </td>
                        <td>
                            <div class="song-cell">
                                <div class="song-cover">
                                    <img src="/cover/<?= (int)$s['id'] ?>" loading="lazy" alt="" data-song-id="<?= (int)$s['id'] ?>" onerror="window.__handleMissingCover && window.__handleMissingCover(this);">
                                </div>
                                <div class="song-info">
                                    <div class="song-title"><?= $title ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="muted">
                            <?php if ($artistId): ?>
                                <a class="artist-link" href="/artist/<?= $artistId ?>"><?= $artist ?></a>
                            <?php else: ?>
                                <?= $artist ?>
                            <?php endif; ?>
                        </td>
                        <td class="muted">
                            <?php if ($albumId): ?>
                                <a class="artist-link" href="/album/<?= $albumId ?>"><?= $albumName ?></a>
                            <?php else: ?>
                                <?= $albumName ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-added muted"><?= $released ?></td>
                        <td class="col-fav">
                            <button class="row-fav-btn<?= $isFav ? ' active' : '' ?>" data-song-id="<?= (int)$s['id'] ?>" title="Favorite"><?= Icons::svg($isFav ? 'heart-fill' : 'heart', 16) ?></button>
                        </td>
                        <td class="col-time"><?= self::formatDuration((int)$s['duration']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function cleanText(string $s): string
    {
        $s = preg_replace('/\?+$/u', '', $s);
        $s = trim($s);
        return $s === '' ? '—' : $s;
    }

    private static function fetchSongs(?string $where, array $params, int $limit): array
    {
        $sql = 'SELECT s.id, s.title, s.duration, s.created_at AS song_added_at,
                       ar.id AS artist_id, ar.name AS artist_name,
                       al.id AS album_id, al.name AS album_name, al.year AS album_year, al.release_date AS album_release_date
                FROM songs s
                JOIN artists ar ON ar.id = s.artist_id
                JOIN albums al ON al.id = s.album_id';
        if ($where !== null && $where !== '') {
            $sql .= ' ' . $where;
        }
        $sql .= ' ORDER BY s.title_sort LIMIT ' . (int)$limit;
        return Database::fetchAll($sql, $params);
    }

    private static function formatPolishDate(?string $datetime): string
    {
        if (!$datetime) return '—';
        $t = strtotime($datetime);
        if (!$t) return '—';
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return $months[(int)date('n', $t) - 1] . ' ' . (int)date('j', $t) . ', ' . date('Y', $t);
    }

    private static function formatReleaseDate(string $releaseDate, ?int $year): string
    {
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        if ($releaseDate !== '') {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $releaseDate, $m)) {
                $mi = (int)$m[2] - 1;
                if ($mi >= 0 && $mi < 12) {
                    return $months[$mi] . ' ' . (int)$m[3] . ', ' . $m[1];
                }
            }
            if (preg_match('/^(\d{4})-(\d{2})/', $releaseDate, $m)) {
                $mi = (int)$m[2] - 1;
                if ($mi >= 0 && $mi < 12) {
                    return $months[$mi] . ' ' . $m[1];
                }
            }
            if (preg_match('/^(\d{4})/', $releaseDate, $m)) {
                return $m[1];
            }
        }
        if ($year !== null && $year > 0) return (string)$year;
        return '—';
    }

    private static function relativeDate(?string $datetime): string
    {
        if (!$datetime) return '—';
        $t = strtotime($datetime);
        if (!$t) return '—';
        $diff = time() - $t;
        if ($diff < 60) return 'teraz';
        if ($diff < 3600) return intdiv($diff, 60) . ' min temu';
        if ($diff < 86400) return intdiv($diff, 3600) . ' godz. temu';
        if ($diff < 86400 * 7) return intdiv($diff, 86400) . ' dni temu';
        if ($diff < 86400 * 30) return intdiv($diff, 86400 * 7) . ' tyg. temu';
        if ($diff < 86400 * 365) return intdiv($diff, 86400 * 30) . ' mies. temu';
        return date('Y-m-d', $t);
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) return '0:00';
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private static function maybeAutoScan(): void
    {
        $musicPath = Env::get('MUSIC_PATH', '/music');
        if (!is_dir($musicPath) || !is_readable($musicPath)) return;

        $last = Database::fetchOne('SELECT started_at, status FROM scans ORDER BY id DESC LIMIT 1');

        $shouldScan = false;
        if ($last === null) {
            $shouldScan = true;
        } else {
            $age = time() - strtotime($last['started_at']);
            if ($last['status'] !== 'running' && $age > 300) {
                $shouldScan = true;
            }
        }

        if (!$shouldScan) return;

        $lockFile = dirname(__DIR__, 2) . '/storage/autoscan.lock';
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) @mkdir($lockDir, 0775, true);
        $fp = @fopen($lockFile, 'c');
        if (!$fp) return;
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            @fclose($fp);
            return;
        }
        @ftruncate($fp, 0);
        @fwrite($fp, (string)getmypid());

        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function () use ($fp, $lockFile) {
                try {
                    (new Scanner())->scan();
                } catch (\Throwable $e) {
                    error_log('Auto-scan error: ' . $e->getMessage());
                } finally {
                    @flock($fp, LOCK_UN);
                    @fclose($fp);
                    @unlink($lockFile);
                }
            });
        } else {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            @unlink($lockFile);
        }
    }
}
