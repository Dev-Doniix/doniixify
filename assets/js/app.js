/**
 * Doniixify frontend — monolithic IIFE (4100+ linii)
 *
 * SEKCJE (linijki przybliżone, użyj Ctrl+F po nagłówku):
 *   ~ 1-200   BOOTSTRAP        platform detection, CSRF, ICONS, fmtTime, setPlayIcon
 *   ~200-630  AUDIO CORE       loadSong, audio events, MediaSession, queue logic
 *   ~630-712  VOLUME           pb-volume control
 *   ~712-833  FAVORITES + KB   keyboard shortcuts, favorites toggle
 *   ~833-962  DIALOGS + UI     custom alert/confirm/prompt, modals, toast
 *   ~962-1012 QUEUE PANEL      queue rendering
 *   ~1012-1360 LYRICS PANEL    parseLrc(*), renderLyrics, tickLyrics, fullscreen
 *   ~1360-1740 NOW PLAYING     squeeze panel (desktop), np-fs (mobile)
 *   ~1740-2030 PLAYLIST UI     actions bar, screensaver, mini bar, sortable
 *   ~2030-2390 DEVICES PANEL   heartbeat, device list, remote transfer
 *   ~2390-2690 JAM SESSION     collab playback
 *   ~2690-3180 SPA NAV         navigate, prefetch, history
 *   ~3180-3460 CONTEXT MENU    long-press sheet, popup, playlist add
 *   ~3460-3700 IMPORT MODAL    Spotify URL import flow
 *   ~3700-4100 AUDIO SETTINGS  EQ, normalize, crossfade, visualizer
 *
 * Plan R3: rozbić to fizycznie na ~10 plików w assets/js/modules/,
 * concat przez PHP do single bundle. Risk: scope (closure capture).
 * Aktualnie: single IIFE z window.__* jako cross-section API.
 */
(function () {
    'use strict';

    if (!window.__platform) {
        const ua = navigator.userAgent || '';
        const mqMobile = window.matchMedia('(max-width: 720px)');
        const mqStandalone = window.matchMedia('(display-mode: standalone)');
        window.__platform = {
            get isMobile() { return mqMobile.matches; },
            get isStandalone() { return mqStandalone.matches || window.navigator.standalone === true; },
            isTv: !!window.__isTv,
            isDesktopApp: /Doniixify-Desktop|pywebview/i.test(ua),
            isTwa: /TWA|\bwv\b/i.test(ua),
            isNativeApp: false,
        };
        window.__platform.isNativeApp = window.__platform.isDesktopApp || window.__platform.isTwa || window.__platform.isStandalone;
    }

    window.__blurhashDecode = (hash, w, h) => {
        try {
            if (!hash || hash.length < 6) return null;
            const CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';
            const decode83 = (str) => {
                let v = 0;
                for (let i = 0; i < str.length; i++) {
                    const idx = CHARS.indexOf(str[i]);
                    if (idx < 0) return -1;
                    v = v * 83 + idx;
                }
                return v;
            };
            const sRGB = (v) => {
                v = Math.max(0, Math.min(1, v));
                return Math.round((v <= 0.0031308 ? v * 12.92 : 1.055 * Math.pow(v, 1 / 2.4) - 0.055) * 255);
            };
            const linearToSRGB = sRGB;
            const sizeFlag = decode83(hash[0]);
            const xComp = (sizeFlag % 9) + 1;
            const yComp = Math.floor(sizeFlag / 9) + 1;
            const quant = decode83(hash[1]);
            const maxAc = (quant + 1) / 166;
            const numComp = xComp * yComp;
            if (hash.length !== 4 + 2 * numComp) return null;
            const colors = new Array(numComp);
            const dc = decode83(hash.substring(2, 6));
            colors[0] = [
                ((dc >> 16) & 0xff) / 255,
                ((dc >> 8) & 0xff) / 255,
                (dc & 0xff) / 255,
            ];
            for (let i = 1; i < numComp; i++) {
                const v = decode83(hash.substring(4 + i * 2, 6 + i * 2));
                const r = Math.floor(v / (19 * 19));
                const g = Math.floor(v / 19) % 19;
                const b = v % 19;
                const sign = (x) => (x - 9) / 9;
                colors[i] = [
                    Math.sign(sign(r)) * Math.pow(Math.abs(sign(r)), 2) * maxAc,
                    Math.sign(sign(g)) * Math.pow(Math.abs(sign(g)), 2) * maxAc,
                    Math.sign(sign(b)) * Math.pow(Math.abs(sign(b)), 2) * maxAc,
                ];
            }
            const canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext('2d');
            const img = ctx.createImageData(w, h);
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    let r = 0, g = 0, b = 0;
                    for (let j = 0; j < yComp; j++) {
                        for (let i = 0; i < xComp; i++) {
                            const basis = Math.cos((Math.PI * x * i) / w) * Math.cos((Math.PI * y * j) / h);
                            const c = colors[i + j * xComp];
                            r += c[0] * basis;
                            g += c[1] * basis;
                            b += c[2] * basis;
                        }
                    }
                    const p = (y * w + x) * 4;
                    img.data[p] = linearToSRGB(r);
                    img.data[p + 1] = linearToSRGB(g);
                    img.data[p + 2] = linearToSRGB(b);
                    img.data[p + 3] = 255;
                }
            }
            ctx.putImageData(img, 0, 0);
            return canvas.toDataURL('image/png');
        } catch (_) { return null; }
    };

    window.__applyCoverHashPlaceholder = (img, songId) => {
        if (!img || !songId || !window.__blurhashDecode) return;
        if (img.dataset.bhDone === '1') return;
        img.dataset.bhDone = '1';
        fetch('/api/cover-hash?id=' + Number(songId), { cache: 'force-cache' })
            .then(r => r.ok ? r.json() : null)
            .then(d => {
                if (!d || !d.hash) return;
                const dataUrl = window.__blurhashDecode(d.hash, 32, 32);
                if (!dataUrl) return;
                if (!img.complete || img.naturalWidth === 0) {
                    img.style.backgroundImage = 'url(' + dataUrl + ')';
                    img.style.backgroundSize = 'cover';
                    img.style.backgroundPosition = 'center';
                    const clean = () => {
                        img.style.backgroundImage = '';
                        img.removeEventListener('load', clean);
                    };
                    img.addEventListener('load', clean, { once: true });
                }
            })
            .catch(() => {});
    };

    window.__extractDominantColor = (imgUrl) => new Promise((resolve) => {
        try {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    const c = document.createElement('canvas');
                    const size = 32;
                    c.width = size;
                    c.height = size;
                    const ctx = c.getContext('2d', { willReadFrequently: true });
                    if (!ctx) { resolve(null); return; }
                    ctx.drawImage(img, 0, 0, size, size);
                    const data = ctx.getImageData(0, 0, size, size).data;
                    let r = 0, g = 0, b = 0, count = 0;
                    for (let i = 0; i < data.length; i += 4) {
                        const ar = data[i], ag = data[i + 1], ab = data[i + 2];
                        const maxC = Math.max(ar, ag, ab);
                        const minC = Math.min(ar, ag, ab);
                        const sat = maxC === 0 ? 0 : (maxC - minC) / maxC;
                        if (sat > 0.25 && maxC > 60 && maxC < 245) {
                            r += ar; g += ag; b += ab; count++;
                        }
                    }
                    if (count < 5) { resolve(null); return; }
                    r = Math.round(r / count);
                    g = Math.round(g / count);
                    b = Math.round(b / count);
                    resolve({ r, g, b, css: 'rgb(' + r + ',' + g + ',' + b + ')' });
                } catch (_) { resolve(null); }
            };
            img.onerror = () => resolve(null);
            img.src = imgUrl;
        } catch (_) { resolve(null); }
    });

    window.__applyAccentColor = async (songId) => {
        if (!songId) return;
        const url = '/cover/' + Number(songId);
        const color = await window.__extractDominantColor(url);
        if (color) {
            document.documentElement.style.setProperty('--accent-r', String(color.r));
            document.documentElement.style.setProperty('--accent-g', String(color.g));
            document.documentElement.style.setProperty('--accent-b', String(color.b));
            document.documentElement.style.setProperty('--accent-rgb', color.r + ',' + color.g + ',' + color.b);
            document.documentElement.style.setProperty('--accent-color', color.css);
        }
        try { document.documentElement.style.setProperty('--np-cover-bg', 'url("' + url + '")'); } catch (_) {}
    };

    window.__viewTransition = (callback) => {
        if (typeof document.startViewTransition === 'function') {
            return document.startViewTransition(callback);
        }
        try { callback(); } catch (_) {}
        return { ready: Promise.resolve(), finished: Promise.resolve() };
    };

    window.__attachRipple = (() => {
        if (window.__rippleAttached) return () => {};
        window.__rippleAttached = true;
        const fire = (e, el) => {
            try {
                const rect = el.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = (e.clientX || e.touches?.[0]?.clientX || rect.left + rect.width / 2) - rect.left;
                const y = (e.clientY || e.touches?.[0]?.clientY || rect.top + rect.height / 2) - rect.top;
                const ripple = document.createElement('span');
                ripple.className = 'ripple-burst';
                ripple.style.width = ripple.style.height = size + 'px';
                ripple.style.left = (x - size / 2) + 'px';
                ripple.style.top = (y - size / 2) + 'px';
                el.appendChild(ripple);
                setTimeout(() => ripple.remove(), 700);
            } catch (_) {}
        };
        document.addEventListener('pointerdown', (e) => {
            const el = e.target.closest('.btn, .ctx-item, .pb-btn, .np-ctrl, .sidebar-item');
            if (!el) return;
            if (el.classList.contains('skel') || el.disabled) return;
            fire(e, el);
        }, { passive: true });
        return () => {};
    })();

    window.__skeletonRows = (count) => {
        let html = '';
        const n = Math.max(1, Math.min(20, count || 6));
        for (let i = 0; i < n; i++) {
            html += '<div class="skel-row">' +
                '<div class="skel skel-cover"></div>' +
                '<div class="skel-text">' +
                    '<div class="skel skel-line long"></div>' +
                    '<div class="skel skel-line short"></div>' +
                '</div>' +
            '</div>';
        }
        return html;
    };

    window.__replayGain = (() => {
        const cache = new Map();
        let worker = null;
        const dbToLinear = (db) => Math.pow(10, db / 20);
        const TARGET_RMS_DB = -18;
        const ensureWorker = () => {
            if (worker) return worker;
            try {
                worker = new Worker('/assets/js/workers/rms-analyzer.js');
                worker.addEventListener('message', async (e) => {
                    const d = e.data || {};
                    if (d.type !== 'result' || !d.songId) return;
                    cache.set(d.songId, d.rms_db);
                    try {
                        const fd = new FormData();
                        fd.append('song_id', String(d.songId));
                        fd.append('rms_db', String(d.rms_db));
                        if (d.peak_db != null) fd.append('peak_db', String(d.peak_db));
                        fetch('/api/song/loudness', { method: 'POST', body: fd });
                    } catch (_) {}
                });
            } catch (_) { worker = null; }
            return worker;
        };
        const fetchCached = async (songId) => {
            try {
                const r = await fetch('/api/song/loudness?song_id=' + Number(songId));
                const d = await r.json();
                if (d && d.cached && d.rms_db != null) return d.rms_db;
            } catch (_) {}
            return null;
        };
        const schedule = async (songId) => {
            if (!songId || cache.has(songId)) return cache.get(songId);
            const cached = await fetchCached(songId);
            if (cached != null) { cache.set(songId, cached); return cached; }
            const w = ensureWorker();
            if (!w) return null;
            w.postMessage({ type: 'analyze', songId: Number(songId), url: '/stream/' + Number(songId) });
            return null;
        };
        const apply = async (songId) => {
            if (!window.__audioCtx || !window.__audioSrcNode) return;
            let gainNode = window.__replayGainNode;
            if (!gainNode) {
                gainNode = window.__audioCtx.createGain();
                gainNode.gain.value = 1;
                window.__replayGainNode = gainNode;
            }
            const rmsDb = await schedule(songId);
            if (rmsDb == null) { try { gainNode.gain.setTargetAtTime(1, window.__audioCtx.currentTime, 0.5); } catch (_) {} return; }
            const correction = TARGET_RMS_DB - rmsDb;
            const target = dbToLinear(Math.max(-12, Math.min(12, correction)));
            try { gainNode.gain.setTargetAtTime(target, window.__audioCtx.currentTime, 0.3); } catch (_) {}
        };
        return { apply, cache, schedule };
    })();

    window.__scrubMode = (() => {
        if (window.__scrubModeInit) return;
        window.__scrubModeInit = true;
        const seekBar = () => document.getElementById('pb-seek-bar');
        let active = false;
        let startX = 0, startY = 0, startTime = 0, sensitivity = 1;
        let originalRect = null, baseTime = 0;
        const a = () => document.querySelector('audio');
        const ind = () => {
            let el = document.getElementById('scrub-mode-indicator');
            if (!el) {
                el = document.createElement('div');
                el.id = 'scrub-mode-indicator';
                el.style.cssText = 'position:fixed;bottom:140px;left:50%;transform:translateX(-50%);background:rgba(22,24,28,0.95);color:#fff;padding:10px 18px;border-radius:14px;font-family:JetBrains Mono,monospace;font-size:14px;font-weight:700;z-index:9900;display:none;border:1px solid rgba(255,255,255,0.08);box-shadow:0 8px 24px rgba(0,0,0,0.5);';
                document.body.appendChild(el);
            }
            return el;
        };
        const fmtT = (s) => { const m = Math.floor(s/60); return m + ':' + String(Math.floor(s%60)).padStart(2,'0'); };
        const onMove = (e) => {
            if (!active) return;
            const x = e.touches ? e.touches[0].clientX : e.clientX;
            const y = e.touches ? e.touches[0].clientY : e.clientY;
            const dy = y - startY;
            sensitivity = dy > 50 ? Math.max(0.1, 1 - (dy - 50) / 200) : 1;
            const dx = x - startX;
            const audio = a();
            if (!audio || !audio.duration) return;
            const pixelsPerSec = (originalRect.width / audio.duration) * sensitivity;
            const newTime = Math.max(0, Math.min(audio.duration, baseTime + dx / pixelsPerSec));
            audio.currentTime = newTime;
            const i = ind();
            i.style.display = 'block';
            i.textContent = fmtT(newTime) + (sensitivity < 0.5 ? ' · ' + Math.round(sensitivity * 100) + '%' : '');
            if (e.preventDefault) e.preventDefault();
        };
        const onUp = () => {
            active = false;
            const i = ind();
            setTimeout(() => { i.style.display = 'none'; }, 800);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
        };
        const attach = () => {
            const bar = seekBar();
            if (!bar || bar.dataset.scrubBound === '1') return;
            bar.dataset.scrubBound = '1';
            let holdTimer = null;
            const start = (e) => {
                const x = e.touches ? e.touches[0].clientX : e.clientX;
                const y = e.touches ? e.touches[0].clientY : e.clientY;
                holdTimer = setTimeout(() => {
                    const audio = a();
                    if (!audio || !audio.duration) return;
                    active = true;
                    startX = x; startY = y;
                    baseTime = audio.currentTime;
                    originalRect = bar.getBoundingClientRect();
                    try { if (navigator.vibrate) navigator.vibrate(15); } catch (_) {}
                    document.addEventListener('mousemove', onMove, { passive: false });
                    document.addEventListener('mouseup', onUp);
                    document.addEventListener('touchmove', onMove, { passive: false });
                    document.addEventListener('touchend', onUp);
                }, 400);
            };
            const cancel = () => { if (holdTimer) { clearTimeout(holdTimer); holdTimer = null; } };
            bar.addEventListener('mousedown', start);
            bar.addEventListener('mouseup', cancel);
            bar.addEventListener('mouseleave', cancel);
            bar.addEventListener('touchstart', start, { passive: true });
            bar.addEventListener('touchend', cancel);
            bar.addEventListener('touchcancel', cancel);
        };
        attach();
        setInterval(attach, 5000);
    })();

    window.__reshuffleQueue = () => {
        if (!Array.isArray(window.doniixify?.queue) || window.doniixify.queue.length < 3) {
            if (window.__showToast) window.__showToast('Queue too short');
            return false;
        }
        const q = window.doniixify.queue;
        const currentRow = q[queueIndex];
        const others = q.filter((_, i) => i !== queueIndex);
        const shuffled = window.__smartShuffle ? window.__smartShuffle(others) : others.sort(() => Math.random() - 0.5);
        q.length = 0;
        q.push(currentRow);
        shuffled.forEach(r => q.push(r));
        try { if (typeof queueIndex !== 'undefined') queueIndex = 0; } catch (_) {}
        if (typeof renderQueue === 'function') try { renderQueue(); } catch (_) {}
        if (typeof renderQueueOverlay === 'function') try { renderQueueOverlay(); } catch (_) {}
        if (window.__showToast) window.__showToast('Queue reshuffled');
        return true;
    };

    window.__queueFilterChips = (() => {
        if (window.__queueChipsInit) return;
        window.__queueChipsInit = true;
        const render = (container) => {
            if (!container) return;
            const q = window.doniixify?.queue || [];
            if (q.length < 2) return;
            const artists = new Map();
            const albums = new Map();
            q.forEach(r => {
                const ar = r?.dataset?.artist;
                const al = r?.dataset?.album;
                if (ar) artists.set(ar, (artists.get(ar) || 0) + 1);
                if (al) albums.set(al, (albums.get(al) || 0) + 1);
            });
            const topArtists = Array.from(artists.entries()).filter(([, c]) => c >= 2).sort((a, b) => b[1] - a[1]).slice(0, 4);
            const topAlbums = Array.from(albums.entries()).filter(([, c]) => c >= 2).sort((a, b) => b[1] - a[1]).slice(0, 3);
            if (!topArtists.length && !topAlbums.length) return;
            const bar = document.createElement('div');
            bar.className = 'queue-chip-bar';
            bar.style.cssText = 'display:flex;gap:6px;padding:8px 12px;flex-wrap:wrap;border-bottom:1px solid var(--border)';
            const chip = (label, kind, val, count) => `<button class="queue-chip" data-kind="${kind}" data-val="${label.replace(/"/g, '&quot;')}" style="background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--text-primary);font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;cursor:pointer">${label} <span style="color:var(--text-muted)">${count}</span></button>`;
            bar.innerHTML = topArtists.map(([a, c]) => chip(a, 'artist', a, c)).join('') + topAlbums.map(([a, c]) => chip(a, 'album', a, c)).join('');
            container.prepend(bar);
            bar.addEventListener('click', (e) => {
                const btn = e.target.closest('.queue-chip');
                if (!btn) return;
                const items = container.querySelectorAll('.queue-item');
                items.forEach(item => {
                    const idx = parseInt(item.dataset.idx, 10);
                    const row = q[idx];
                    if (!row) return;
                    const matchVal = btn.dataset.kind === 'artist' ? row.dataset.artist : row.dataset.album;
                    item.style.display = (!btn.classList.contains('active') && matchVal !== btn.dataset.val) ? 'none' : '';
                });
                bar.querySelectorAll('.queue-chip').forEach(c => c.classList.remove('active'));
                if (!btn.classList.contains('active')) btn.classList.add('active');
                else items.forEach(it => it.style.display = '');
            });
        };
        document.addEventListener('queue-rendered', (e) => {
            const target = e.detail?.container || document.querySelector('.queue-overlay-body, .side-panel-body');
            const existing = target?.querySelector('.queue-chip-bar');
            if (existing) existing.remove();
            render(target);
        });
    })();

    window.__dropImport = (() => {
        if (window.__dropImportInit) return;
        window.__dropImportInit = true;
        let overlay = null;
        const ensureOverlay = () => {
            if (overlay && overlay.isConnected) return overlay;
            overlay = document.createElement('div');
            overlay.id = 'drop-import-overlay';
            overlay.innerHTML = '<div class="drop-import-card"><svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg><div class="drop-import-text">Drop audio file to upload</div></div>';
            overlay.style.cssText = 'position:fixed;inset:0;z-index:9800;background:rgba(0,0,0,0.7);backdrop-filter:blur(10px);display:none;align-items:center;justify-content:center;pointer-events:none;';
            const card = overlay.querySelector('.drop-import-card');
            card.style.cssText = 'background:#16181c;border:2px dashed rgba(30,215,96,0.6);border-radius:18px;padding:48px 56px;color:#1ed760;text-align:center;font-family:Inter,system-ui;font-weight:700;font-size:18px;';
            overlay.querySelector('.drop-import-text').style.cssText = 'margin-top:16px;';
            document.body.appendChild(overlay);
            return overlay;
        };
        let dragDepth = 0;
        const show = () => { const ov = ensureOverlay(); ov.style.display = 'flex'; };
        const hide = () => { if (overlay) overlay.style.display = 'none'; dragDepth = 0; };
        window.addEventListener('dragenter', (e) => {
            if (!e.dataTransfer || !Array.from(e.dataTransfer.types || []).includes('Files')) return;
            dragDepth++;
            if (dragDepth === 1) show();
        });
        window.addEventListener('dragleave', () => {
            dragDepth--;
            if (dragDepth <= 0) hide();
        });
        window.addEventListener('dragover', (e) => { if (e.dataTransfer) e.preventDefault(); });
        window.addEventListener('drop', async (e) => {
            hide();
            const files = e.dataTransfer && e.dataTransfer.files;
            if (!files || !files.length) return;
            e.preventDefault();
            const audio = Array.from(files).filter(f => /^audio\//.test(f.type) || /\.(mp3|m4a|flac|ogg|opus|wav|webm|aac)$/i.test(f.name));
            if (!audio.length) {
                if (window.__showToast) window.__showToast('Only audio files supported (mp3, m4a, flac, ogg, opus, wav, webm, aac)');
                const ov = ensureOverlay();
                ov.style.display = 'flex';
                const card = ov.querySelector('.drop-import-card');
                if (card) {
                    card.style.borderColor = 'rgba(245, 100, 100, 0.7)';
                    card.style.color = '#f56464';
                    card.style.animation = 'dropShake 0.4s ease-in-out';
                    setTimeout(() => {
                        ov.style.display = 'none';
                        card.style.borderColor = '';
                        card.style.color = '';
                        card.style.animation = '';
                    }, 800);
                }
                return;
            }
            for (const f of audio) {
                if (window.__showToast) window.__showToast('Uploading ' + f.name + '…', { duration: 4000 });
                const fd = new FormData();
                fd.append('file', f);
                try {
                    const r = await fetch('/api/upload', { method: 'POST', body: fd });
                    if (r.status === 401 || r.status === 403) {
                        if (window.__showToast) window.__showToast('Please log in first');
                        return;
                    }
                    if (r.status === 413) {
                        if (window.__showToast) window.__showToast(f.name + ': file too large (>200MB)');
                        continue;
                    }
                    const d = await r.json().catch(() => null);
                    if (r.ok && d && d.song_id) {
                        if (window.__showToast) window.__showToast('Uploaded: ' + (d.title || f.name));
                    } else {
                        if (window.__showToast) window.__showToast('Upload failed: ' + (d?.error || f.name));
                    }
                } catch (err) {
                    if (window.__showToast) window.__showToast('Upload error: ' + (err && err.message || err));
                }
            }
        });
    })();

    window.__searchHistory = (() => {
        const KEY = 'doniix-search-history';
        const MAX = 15;
        const get = () => {
            try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (_) { return []; }
        };
        const add = (q) => {
            q = (q || '').trim();
            if (!q || q.length < 2) return;
            let arr = get();
            arr = arr.filter(x => x.toLowerCase() !== q.toLowerCase());
            arr.unshift(q);
            if (arr.length > MAX) arr = arr.slice(0, MAX);
            try { localStorage.setItem(KEY, JSON.stringify(arr)); } catch (_) {}
        };
        const clear = () => { try { localStorage.removeItem(KEY); } catch (_) {} };
        const remove = (q) => {
            try {
                const arr = get().filter(x => x.toLowerCase() !== (q || '').toLowerCase());
                localStorage.setItem(KEY, JSON.stringify(arr));
            } catch (_) {}
        };
        return { get, add, clear, remove };
    })();

    if (typeof document !== 'undefined') {
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form || form.method?.toLowerCase() !== 'get') return;
            const action = form.getAttribute('action') || location.pathname;
            if (!action.startsWith('/search')) return;
            const q = (new FormData(form).get('q') || '').toString().trim();
            if (q) window.__searchHistory.add(q);
        }, true);
    }

    window.__miniPlayerPiP = (() => {
        let pipWin = null;
        const openPiP = async () => {
            if (!window.documentPictureInPicture || typeof window.documentPictureInPicture.requestWindow !== 'function') {
                if (window.__showToast) window.__showToast('Picture-in-Picture not supported');
                return false;
            }
            try {
                pipWin = await window.documentPictureInPicture.requestWindow({
                    width: 320, height: 120,
                });
                Array.from(document.styleSheets).forEach(ss => {
                    try {
                        const link = pipWin.document.createElement('link');
                        link.rel = 'stylesheet';
                        link.href = ss.href || '';
                        if (link.href) pipWin.document.head.appendChild(link);
                    } catch (_) {}
                });
                const inline = pipWin.document.createElement('style');
                inline.textContent = 'body{margin:0;background:#0a0a0d;color:#fff;font-family:Inter,system-ui;display:flex;align-items:center;gap:10px;padding:10px}img{width:64px;height:64px;border-radius:6px;object-fit:cover}button{background:transparent;border:0;color:#fff;cursor:pointer;padding:6px}.pip-meta{flex:1;min-width:0}.pip-title{font-weight:700;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pip-artist{font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}';
                pipWin.document.head.appendChild(inline);
                pipWin.document.body.innerHTML =
                    '<img id="pip-cover" alt="">' +
                    '<div class="pip-meta">' +
                        '<div class="pip-title" id="pip-title">—</div>' +
                        '<div class="pip-artist" id="pip-artist">—</div>' +
                    '</div>' +
                    '<button id="pip-prev" title="Previous">⏮</button>' +
                    '<button id="pip-play" title="Play/Pause">⏸</button>' +
                    '<button id="pip-next" title="Next">⏭</button>';
                const sync = () => {
                    if (!pipWin || pipWin.closed) return;
                    const meta = window.__currentTrackMeta || {};
                    const t = pipWin.document.getElementById('pip-title');
                    const a = pipWin.document.getElementById('pip-artist');
                    const c = pipWin.document.getElementById('pip-cover');
                    const p = pipWin.document.getElementById('pip-play');
                    if (t) t.textContent = meta.title || '—';
                    if (a) a.textContent = meta.artist || '—';
                    if (c && meta.songId) c.src = '/cover/' + Number(meta.songId);
                    const aud = document.querySelector('audio');
                    if (p && aud) p.textContent = aud.paused ? '▶' : '⏸';
                };
                pipWin.document.getElementById('pip-prev').addEventListener('click', () => document.getElementById('pb-prev')?.click());
                pipWin.document.getElementById('pip-next').addEventListener('click', () => document.getElementById('pb-next')?.click());
                pipWin.document.getElementById('pip-play').addEventListener('click', () => document.getElementById('pb-play')?.click());
                sync();
                const syncInterval = setInterval(sync, 1000);
                pipWin.addEventListener('pagehide', () => { clearInterval(syncInterval); pipWin = null; });
                if (window.__showToast) window.__showToast('Mini player opened');
                return true;
            } catch (e) {
                if (window.__showToast) window.__showToast('PiP failed: ' + (e && e.message || e));
                return false;
            }
        };
        const close = () => {
            try { if (pipWin && !pipWin.closed) pipWin.close(); } catch (_) {}
            pipWin = null;
        };
        const isOpen = () => !!(pipWin && !pipWin.closed);
        return { open: openPiP, close, isOpen };
    })();

    document.addEventListener('click', (e) => {
        const t = e.target.closest('.theme-btn');
        if (t && window.__theme && t.dataset.theme) {
            window.__theme.set(t.dataset.theme);
            if (window.__showToast) window.__showToast('Theme: ' + t.dataset.theme);
        }
        const accentReset = e.target.closest('#settings-accent-clear');
        if (accentReset && window.__customAccent) {
            window.__customAccent.clear();
            if (window.__showToast) window.__showToast('Accent now follows cover art');
        }
        const preset = e.target.closest('#theme-presets button[data-hex]');
        if (preset && window.__customAccent) {
            window.__customAccent.set(preset.dataset.hex);
            const picker = document.getElementById('settings-custom-accent');
            if (picker) picker.value = preset.dataset.hex;
            if (window.__showToast) window.__showToast('Accent: ' + preset.dataset.hex);
        }
    });
    document.addEventListener('input', (e) => {
        if (e.target.id === 'settings-custom-accent' && window.__customAccent) {
            window.__customAccent.set(e.target.value);
        }
    });

    window.__theme = (() => {
        const KEY = 'doniix-theme';
        const apply = (mode) => {
            const root = document.documentElement;
            if (mode === 'auto') {
                const prefers = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                root.dataset.theme = prefers;
            } else {
                root.dataset.theme = mode;
            }
        };
        const get = () => { try { return localStorage.getItem(KEY) || 'dark'; } catch (_) { return 'dark'; } };
        const set = (mode) => {
            if (!['dark', 'light', 'auto'].includes(mode)) return;
            try { localStorage.setItem(KEY, mode); } catch (_) {}
            apply(mode);
        };
        apply(get());
        try {
            window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
                if (get() === 'auto') apply('auto');
            });
        } catch (_) {}
        return { get, set };
    })();

    window.__speedPicker = (() => {
        if (window.__speedPickerInit) return;
        window.__speedPickerInit = true;
        const speeds = [0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0];
        const open = (anchorEl) => {
            const existing = document.getElementById('speed-picker-popup');
            if (existing) { existing.remove(); return; }
            const audio = document.querySelector('audio');
            if (!audio) return;
            const popup = document.createElement('div');
            popup.id = 'speed-picker-popup';
            popup.style.cssText = 'position:fixed;background:#16181c;border:1px solid var(--border);border-radius:10px;padding:6px;z-index:9700;box-shadow:0 8px 24px rgba(0,0,0,0.4);';
            popup.innerHTML = speeds.map(s => '<button data-s="' + s + '" style="display:block;width:80px;padding:8px 12px;background:' + (Math.abs(audio.playbackRate - s) < 0.01 ? 'rgb(var(--accent-rgb,30,215,96))' : 'transparent') + ';color:' + (Math.abs(audio.playbackRate - s) < 0.01 ? '#000' : '#fff') + ';border:0;border-radius:6px;cursor:pointer;text-align:left;font-weight:600">' + s.toFixed(2) + 'x</button>').join('');
            document.body.appendChild(popup);
            const rect = anchorEl ? anchorEl.getBoundingClientRect() : { left: window.innerWidth / 2 - 40, top: window.innerHeight - 200, width: 0 };
            const pRect = popup.getBoundingClientRect();
            popup.style.left = Math.min(rect.left, window.innerWidth - pRect.width - 8) + 'px';
            popup.style.top = (rect.top - pRect.height - 8) + 'px';
            popup.addEventListener('click', (e) => {
                const btn = e.target.closest('button[data-s]');
                if (!btn) return;
                audio.playbackRate = parseFloat(btn.dataset.s);
                try { localStorage.setItem('doniix-playback-rate', String(audio.playbackRate)); } catch (_) {}
                if (window.__showToast) window.__showToast('Speed ' + audio.playbackRate.toFixed(2) + 'x');
                popup.remove();
            });
            setTimeout(() => {
                document.addEventListener('click', function close(e) {
                    if (!popup.contains(e.target)) { popup.remove(); document.removeEventListener('click', close); }
                });
            }, 50);
        };
        return { open };
    })();

    window.__vocalCancel = (() => {
        let active = false;
        let merger = null, splitter = null, leftInv = null;
        const enable = () => {
            if (active) return true;
            if (!window.__audioCtx || !window.__audioSrcNode) return false;
            try {
                splitter = window.__audioCtx.createChannelSplitter(2);
                merger = window.__audioCtx.createChannelMerger(2);
                leftInv = window.__audioCtx.createGain();
                leftInv.gain.value = -1;
                window.__audioSrcNode.disconnect();
                window.__audioSrcNode.connect(splitter);
                splitter.connect(leftInv, 0);
                splitter.connect(merger, 1, 0);
                splitter.connect(merger, 1, 1);
                leftInv.connect(merger, 0, 0);
                leftInv.connect(merger, 0, 1);
                merger.connect(window.__replayGainNode || window.__audioCtx.destination);
                active = true;
                return true;
            } catch (_) { active = false; return false; }
        };
        const disable = () => {
            if (!active) return;
            try {
                splitter?.disconnect();
                merger?.disconnect();
                leftInv?.disconnect();
                window.__audioSrcNode?.disconnect();
                window.__audioSrcNode?.connect(window.__replayGainNode || window.__audioCtx.destination);
                active = false;
            } catch (_) {}
        };
        const toggle = () => {
            const ok = active ? (disable(), true) : enable();
            if (window.__showToast) window.__showToast(active ? 'Karaoke ON (vocal removed)' : 'Karaoke OFF');
            return ok;
        };
        return { enable, disable, toggle, isActive: () => active };
    })();

    window.__themePresets = {
        'spotify': { name: 'Spotify Green', hex: '#1ed760' },
        'apple-pink': { name: 'Apple Pink', hex: '#fc3c44' },
        'apple-blue': { name: 'Apple Blue', hex: '#3a8bff' },
        'youtube-red': { name: 'YouTube Red', hex: '#ff0033' },
        'amazon-orange': { name: 'Amazon Orange', hex: '#ff9900' },
        'tidal-cyan': { name: 'Tidal Cyan', hex: '#00ffff' },
        'soundcloud-orange': { name: 'SoundCloud', hex: '#ff5500' },
        'deezer-purple': { name: 'Deezer Purple', hex: '#a238ff' },
        'pastel-mint': { name: 'Pastel Mint', hex: '#a8e6cf' },
        'sunset': { name: 'Sunset', hex: '#ff6b6b' },
    };

    window.__customAccent = (() => {
        const KEY = 'doniix-custom-accent';
        const apply = (hex) => {
            if (!hex || !/^#[0-9a-f]{6}$/i.test(hex)) return false;
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);
            const root = document.documentElement;
            root.style.setProperty('--accent-r', String(r));
            root.style.setProperty('--accent-g', String(g));
            root.style.setProperty('--accent-b', String(b));
            root.style.setProperty('--accent-rgb', r + ',' + g + ',' + b);
            root.style.setProperty('--accent-color', 'rgb(' + r + ',' + g + ',' + b + ')');
            return true;
        };
        const set = (hex) => {
            if (!apply(hex)) return false;
            try { localStorage.setItem(KEY, hex); } catch (_) {}
            return true;
        };
        const clear = () => {
            try { localStorage.removeItem(KEY); } catch (_) {}
            const root = document.documentElement;
            ['--accent-r','--accent-g','--accent-b','--accent-rgb','--accent-color'].forEach(p => root.style.removeProperty(p));
        };
        try {
            const saved = localStorage.getItem(KEY);
            if (saved) apply(saved);
        } catch (_) {}
        return { set, clear, get: () => { try { return localStorage.getItem(KEY); } catch(_) { return null; } } };
    })();

    window.__eqPresets = {
        flat:      { 60:  0, 250:  0, 1000:  0, 4000:  0, 12000:  0 },
        bass:      { 60: +6, 250: +4, 1000:  0, 4000: -1, 12000: -2 },
        rock:      { 60: +4, 250: +2, 1000: -2, 4000: +3, 12000: +4 },
        jazz:      { 60: +3, 250: +1, 1000: -1, 4000: +1, 12000: +3 },
        classical: { 60: +3, 250:  0, 1000:  0, 4000:  0, 12000: +4 },
        pop:       { 60: -1, 250: +2, 1000: +4, 4000: +3, 12000:  0 },
        vocal:     { 60: -3, 250: -1, 1000: +4, 4000: +5, 12000: +2 },
        electronic:{ 60: +5, 250: +3, 1000: -1, 4000: +2, 12000: +5 },
        hiphop:    { 60: +6, 250: +3, 1000: -1, 4000: +1, 12000: +2 },
        treble:    { 60: -2, 250:  0, 1000:  0, 4000: +3, 12000: +6 },
    };
    window.__smartEqMatch = async (songId) => {
        if (!songId) return null;
        try {
            const __uid = window.__userId || 0;
            const settings = JSON.parse(localStorage.getItem('u' + __uid + ':doniix-audio-settings') || localStorage.getItem('doniix-audio-settings') || '{}');
            if (!settings.smart_eq) return null;
            const r = await fetch('/api/tags/list?song_id=' + Number(songId));
            const d = await r.json();
            const tags = (d?.tags || []).map(t => t.toLowerCase());
            if (!tags.length) return null;
            const map = {
                'rock': 'rock', 'metal': 'rock', 'punk': 'rock', 'alternative': 'rock',
                'jazz': 'jazz', 'blues': 'jazz', 'swing': 'jazz',
                'classical': 'classical', 'orchestra': 'classical', 'symphony': 'classical',
                'pop': 'pop', 'indie pop': 'pop', 'kpop': 'pop',
                'vocal': 'vocal', 'acapella': 'vocal',
                'electronic': 'electronic', 'edm': 'electronic', 'techno': 'electronic', 'house': 'electronic', 'dance': 'electronic',
                'hiphop': 'hiphop', 'hip-hop': 'hiphop', 'rap': 'hiphop', 'trap': 'hiphop',
                'bass': 'bass', 'dubstep': 'bass', 'drum and bass': 'bass',
            };
            for (const t of tags) {
                if (map[t]) {
                    window.__applyEqPreset(map[t]);
                    return map[t];
                }
            }
            return null;
        } catch (_) { return null; }
    };

    window.__applyEqPreset = (name) => {
        const preset = window.__eqPresets[name];
        if (!preset) return false;
        try {
            const __uid = window.__userId || 0;
            const key = 'u' + __uid + ':doniix-audio-settings';
            const existing = JSON.parse(localStorage.getItem(key) || localStorage.getItem('doniix-audio-settings') || '{}');
            existing.eq = { ...preset };
            existing.eq_enabled = name !== 'flat';
            localStorage.setItem(key, JSON.stringify(existing));
            window.dispatchEvent(new CustomEvent('audio-settings-changed', { detail: existing }));
            return true;
        } catch (_) { return false; }
    };

    window.__goalNotifier = (() => {
        const KEY = 'doniix-goal-notified';
        const check = async () => {
            if (!('Notification' in window)) return;
            if (Notification.permission !== 'granted') return;
            try {
                const r = await fetch('/api/me/goal');
                const d = await r.json();
                if (!d || !d.reached) return;
                const lastNotif = localStorage.getItem(KEY) || '';
                const weekKey = new Date().toISOString().slice(0, 10).split('-').slice(0, 2).join('-') + '-W' + Math.ceil(new Date().getDate() / 7);
                if (lastNotif === weekKey) return;
                new Notification('🎉 Weekly goal reached!', {
                    body: 'You listened to ' + d.week_min + ' minutes this week — past your ' + d.weekly_goal_min + ' min goal!',
                    icon: '/api/icon/192',
                });
                localStorage.setItem(KEY, weekKey);
            } catch (_) {}
        };
        setTimeout(check, 30000);
        setInterval(check, 30 * 60 * 1000);
        return { check };
    })();

    window.__a11y = (() => {
        const KEY_FONT = 'doniix-lyrics-font-pct';
        const KEY_CONTRAST = 'doniix-high-contrast';
        const KEY_DYSLEXIC = 'doniix-dyslexic-font';
        const apply = () => {
            try {
                const pct = parseInt(localStorage.getItem(KEY_FONT) || '100', 10);
                document.documentElement.style.setProperty('--lyrics-font-scale', String(pct / 100));
                const hc = localStorage.getItem(KEY_CONTRAST) === '1';
                document.documentElement.classList.toggle('high-contrast', hc);
                const dys = localStorage.getItem(KEY_DYSLEXIC) === '1';
                document.documentElement.classList.toggle('dyslexic-font', dys);
            } catch (_) {}
        };
        apply();
        return {
            setLyricsFont: (pct) => { try { localStorage.setItem(KEY_FONT, String(Math.max(60, Math.min(180, pct)))); } catch (_) {} apply(); },
            setHighContrast: (val) => { try { localStorage.setItem(KEY_CONTRAST, val ? '1' : '0'); } catch (_) {} apply(); },
            setDyslexicFont: (val) => { try { localStorage.setItem(KEY_DYSLEXIC, val ? '1' : '0'); } catch (_) {} apply(); },
            getLyricsFont: () => { try { return parseInt(localStorage.getItem(KEY_FONT) || '100', 10); } catch (_) { return 100; } },
            getHighContrast: () => { try { return localStorage.getItem(KEY_CONTRAST) === '1'; } catch (_) { return false; } },
            getDyslexicFont: () => { try { return localStorage.getItem(KEY_DYSLEXIC) === '1'; } catch (_) { return false; } },
        };
    })();

    window.__smartBreak = (() => {
        let intervalMin = 0;
        try { intervalMin = parseInt(localStorage.getItem('doniix-smart-break-min') || '0', 10); } catch (_) {}
        let lastPauseAt = Date.now();
        let timerId = null;
        const set = (minutes) => {
            intervalMin = Math.max(0, Math.min(180, minutes));
            try { localStorage.setItem('doniix-smart-break-min', String(intervalMin)); } catch (_) {}
            schedule();
        };
        const schedule = () => {
            if (timerId) { clearTimeout(timerId); timerId = null; }
            if (intervalMin <= 0) return;
            const elapsed = Date.now() - lastPauseAt;
            const wait = Math.max(60000, intervalMin * 60 * 1000 - elapsed);
            timerId = setTimeout(() => {
                const a = document.querySelector('audio');
                if (a && !a.paused) {
                    a.pause();
                    if (window.__showToast) window.__showToast('🛑 Smart break — your ' + intervalMin + ' min are up');
                    if ('Notification' in window && Notification.permission === 'granted') {
                        try { new Notification('Smart break', { body: 'You\'ve been listening for ' + intervalMin + ' min', icon: '/api/icon/192' }); } catch (_) {}
                    }
                }
                lastPauseAt = Date.now();
                schedule();
            }, wait);
        };
        document.querySelector('audio')?.addEventListener('pause', () => { lastPauseAt = Date.now(); schedule(); });
        document.querySelector('audio')?.addEventListener('play', schedule);
        schedule();
        return { set, get: () => intervalMin };
    })();

    window.__autoPauseOnHidden = (() => {
        let enabled = false;
        try { enabled = localStorage.getItem('doniix-auto-pause-hidden') === '1'; } catch (_) {}
        const set = (val) => {
            enabled = !!val;
            try { localStorage.setItem('doniix-auto-pause-hidden', enabled ? '1' : '0'); } catch (_) {}
        };
        let wasPlayingBeforeHide = false;
        document.addEventListener('visibilitychange', () => {
            if (!enabled) return;
            const a = document.querySelector('audio');
            if (!a) return;
            if (document.visibilityState === 'hidden') {
                wasPlayingBeforeHide = !a.paused;
                if (wasPlayingBeforeHide) a.pause();
            } else if (document.visibilityState === 'visible' && wasPlayingBeforeHide) {
                a.play().catch(() => {});
            }
        });
        return { set, get: () => enabled };
    })();

    window.__lyricsHighlight = (() => {
        if (window.__lyricsHighlightInit) return;
        window.__lyricsHighlightInit = true;
        let holdTimer = null;
        const save = async (line) => {
            const meta = window.__currentTrackMeta || {};
            if (!meta.songId || !line) return;
            const fd = new FormData();
            fd.append('song_id', String(meta.songId));
            fd.append('line', line);
            try {
                await fetch('/api/lyrics/highlight', { method: 'POST', body: fd });
                if (window.__showToast) window.__showToast('💛 Highlighted: "' + (line.length > 30 ? line.slice(0, 30) + '…' : line) + '"');
            } catch (_) {}
        };
        document.addEventListener('pointerdown', (e) => {
            if (e.target.closest && e.target.closest('#pb-seek-bar, .np-fs-seek-bar, .lf-seek-bar')) return;
            const lineEl = e.target.closest && e.target.closest('.lf-lyric-line, .np-lyric-line');
            if (!lineEl) return;
            holdTimer = setTimeout(() => {
                const text = (lineEl.dataset.origText || lineEl.textContent || '').trim();
                if (text && text !== '♪') {
                    try { if (navigator.vibrate) navigator.vibrate([10, 50, 10]); } catch (_) {}
                    save(text);
                    lineEl.style.transition = 'background 0.4s ease';
                    lineEl.style.background = 'rgba(255,215,0,0.25)';
                    setTimeout(() => { lineEl.style.background = ''; }, 1200);
                }
                holdTimer = null;
            }, 600);
        });
        document.addEventListener('pointerup', () => { if (holdTimer) clearTimeout(holdTimer); });
        document.addEventListener('pointercancel', () => { if (holdTimer) clearTimeout(holdTimer); });
        document.addEventListener('pointermove', () => { if (holdTimer) clearTimeout(holdTimer); });
    })();

    window.__multiSelect = (() => {
        if (window.__multiSelectInit) return;
        window.__multiSelectInit = true;
        let selected = new Set();
        let lastClickedIdx = null;
        const updateRow = (row) => {
            const id = Number(row.dataset.songId);
            row.style.background = selected.has(id) ? 'rgba(var(--accent-rgb,30,215,96),0.15)' : '';
        };
        const renderToolbar = () => {
            let tb = document.getElementById('multi-select-toolbar');
            if (selected.size === 0) { if (tb) tb.remove(); return; }
            if (!tb) {
                tb = document.createElement('div');
                tb.id = 'multi-select-toolbar';
                tb.style.cssText = 'position:fixed;bottom:calc(env(safe-area-inset-bottom,0px) + 96px);left:50%;transform:translateX(-50%);background:#16181c;border:1px solid var(--border);border-radius:14px;padding:12px 18px;display:flex;gap:12px;align-items:center;z-index:9000;box-shadow:0 8px 28px rgba(0,0,0,0.5);font-family:Inter,system-ui;';
                document.body.appendChild(tb);
            }
            tb.innerHTML = '<span style="font-weight:700">' + selected.size + ' selected</span>' +
                '<button id="ms-add-queue" style="background:transparent;border:1px solid var(--border);color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">Add to queue</button>' +
                '<button id="ms-tag" style="background:transparent;border:1px solid var(--border);color:#fff;padding:6px 12px;border-radius:6px;cursor:pointer">Tag…</button>' +
                '<button id="ms-clear" style="background:transparent;border:0;color:rgba(255,255,255,0.6);padding:6px 8px;cursor:pointer">×</button>';
            tb.querySelector('#ms-clear').addEventListener('click', () => { selected.clear(); document.querySelectorAll('tr[data-song-id]').forEach(updateRow); renderToolbar(); });
            tb.querySelector('#ms-add-queue').addEventListener('click', () => {
                let added = 0;
                selected.forEach(id => {
                    const row = document.querySelector('tr[data-song-id="' + id + '"]');
                    if (row && window.doniixify?.queue) {
                        const clone = row.cloneNode(true);
                        clone.dataset.queued = '1';
                        window.doniixify.queue.push(clone);
                        added++;
                    }
                });
                if (window.__showToast) window.__showToast('Added ' + added + ' to queue');
                selected.clear();
                document.querySelectorAll('tr[data-song-id]').forEach(updateRow);
                renderToolbar();
            });
            tb.querySelector('#ms-tag').addEventListener('click', async () => {
                if (!window.__dialogPrompt) return;
                const tag = await window.__dialogPrompt('Tag for ' + selected.size + ' tracks:', { title: 'Bulk tag', placeholder: 'chill', okText: 'Apply' });
                if (!tag) return;
                const r = await fetch('/api/tags/bulk-add', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ song_ids: [...selected], tag }) });
                const d = await r.json();
                if (window.__showToast) window.__showToast('Tagged ' + (d.added || 0) + ' tracks');
                selected.clear();
                document.querySelectorAll('tr[data-song-id]').forEach(updateRow);
                renderToolbar();
            });
        };
        document.addEventListener('click', (e) => {
            const row = e.target.closest && e.target.closest('tr[data-song-id]');
            if (!row) return;
            if (row.closest('.settings-page, .settings-section')) return;
            if (e.target.closest('a, button, [contenteditable]')) return;
            const isMod = e.metaKey || e.ctrlKey;
            const isShift = e.shiftKey;
            if (!isMod && !isShift) return;
            e.preventDefault();
            e.stopPropagation();
            const id = Number(row.dataset.songId);
            const rows = Array.from(document.querySelectorAll('tr[data-song-id]'));
            const idx = rows.indexOf(row);
            if (isShift && lastClickedIdx !== null) {
                const start = Math.min(lastClickedIdx, idx);
                const end = Math.max(lastClickedIdx, idx);
                for (let i = start; i <= end; i++) selected.add(Number(rows[i].dataset.songId));
            } else if (isMod) {
                if (selected.has(id)) selected.delete(id);
                else selected.add(id);
                lastClickedIdx = idx;
            }
            rows.forEach(updateRow);
            renderToolbar();
        }, true);
        return { getSelected: () => [...selected], clear: () => { selected.clear(); renderToolbar(); } };
    })();

    window.__sessionLog = (() => {
        if (window.__sessionLogInit) return;
        window.__sessionLogInit = true;
        let sessionActive = false;
        let lastTickAt = 0;
        let endTimer = null;
        const start = () => {
            if (sessionActive) return;
            sessionActive = true;
            const fd = new FormData();
            fd.append('action', 'start');
            fetch('/api/me/session', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
        };
        const end = () => {
            if (!sessionActive) return;
            sessionActive = false;
            const fd = new FormData();
            fd.append('action', 'end');
            fetch('/api/me/session', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
        };
        const tick = () => {
            if (!sessionActive) return;
            if (Date.now() - lastTickAt < 60000) return;
            lastTickAt = Date.now();
            const fd = new FormData();
            fd.append('action', 'tick');
            fetch('/api/me/session', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
        };
        const audio = document.querySelector('audio');
        audio?.addEventListener('play', () => {
            if (endTimer) { clearTimeout(endTimer); endTimer = null; }
            start();
        });
        audio?.addEventListener('pause', () => {
            if (endTimer) clearTimeout(endTimer);
            endTimer = setTimeout(end, 5 * 60 * 1000);
        });
        audio?.addEventListener('ended', tick);
        window.addEventListener('pagehide', end);
        window.addEventListener('beforeunload', end);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                if (sessionActive) {
                    const fd = new FormData();
                    fd.append('action', 'end');
                    try { navigator.sendBeacon('/api/me/session', fd); } catch (_) {}
                }
            }
        });
        return { start, end };
    })();

    window.__surpriseMe = async () => {
        try {
            const r = await fetch('/api/surprise');
            const d = await r.json();
            if (!d || d.error || !d.id) {
                if (window.__showToast) window.__showToast('No surprise available');
                return;
            }
            if (window.doniixify && window.doniixify.loadSong) {
                window.doniixify.loadSong(Number(d.id), d.title || '', d.artist_name || '', true);
                if (window.__showToast) window.__showToast('🎲 ' + (d.title || '?') + ' — ' + (d.artist_name || ''));
            }
        } catch (_) {}
    };

    window.__perTrackVolume = (() => {
        const cache = new Map();
        let currentMultiplier = 1;
        const recomputeAudioVolume = () => {
            const audio = document.querySelector('audio');
            if (!audio) return;
            try {
                const __uid = window.__userId || 0;
                const baseSettings = JSON.parse(localStorage.getItem('u' + __uid + ':doniix-audio-settings') || localStorage.getItem('doniix-audio-settings') || '{}');
                const baseVolume = (baseSettings.volume ?? 80) / 100;
                const final = Math.max(0, Math.min(1, baseVolume * currentMultiplier));
                audio.volume = final;
            } catch (_) {}
        };
        const apply = async (songId) => {
            if (!songId) { currentMultiplier = 1; recomputeAudioVolume(); return; }
            let gain = cache.get(songId);
            if (gain === undefined) {
                try {
                    const r = await fetch('/api/song/volume?song_id=' + Number(songId));
                    const d = await r.json();
                    gain = parseFloat(d?.gain_db ?? 0);
                    cache.set(songId, gain);
                } catch (_) { gain = 0; cache.set(songId, 0); }
            }
            currentMultiplier = Math.pow(10, gain / 20);
            recomputeAudioVolume();
        };
        const set = async (songId, gainDb) => {
            const fd = new FormData();
            fd.append('song_id', String(songId));
            fd.append('gain_db', String(gainDb));
            await fetch('/api/song/volume', { method: 'POST', body: fd });
            cache.set(songId, gainDb);
            apply(songId);
        };
        return { apply, set, cache, getMultiplier: () => currentMultiplier };
    })();

    window.__vibeQuiz = (() => {
        const open = () => {
            if (document.getElementById('vibe-modal')) return;
            const moods = [
                { key: 'happy', emoji: '😊', label: 'Happy' },
                { key: 'sad', emoji: '😢', label: 'Sad' },
                { key: 'angry', emoji: '😤', label: 'Angry' },
                { key: 'chill', emoji: '😌', label: 'Chill' },
                { key: 'romantic', emoji: '🥰', label: 'Romantic' },
                { key: 'energetic', emoji: '⚡', label: 'Energetic' },
            ];
            let mood = 'happy', energy = 3, tempo = 'medium';
            const modal = document.createElement('div');
            modal.id = 'vibe-modal';
            modal.style.cssText = 'position:fixed;inset:0;z-index:9900;background:rgba(0,0,0,0.7);backdrop-filter:blur(12px);display:flex;align-items:center;justify-content:center;padding:20px';
            modal.innerHTML = '<div style="background:#16181c;border:1px solid var(--border);border-radius:18px;padding:28px;max-width:420px;width:100%;font-family:Inter,system-ui"><h2 style="margin:0 0 6px;font-size:22px;font-weight:800">Vibe Check ✨</h2><p style="color:var(--text-muted);font-size:13px;margin:0 0 20px">Tell us your vibe — we\'ll generate a 25-track playlist.</p><div style="margin-bottom:20px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:10px">Mood</div><div id="vibe-moods" style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px"></div></div><div style="margin-bottom:20px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:10px">Energy</div><div style="display:flex;align-items:center;gap:10px"><input type="range" id="vibe-energy" min="1" max="5" value="3" style="flex:1"><span id="vibe-energy-val" style="font-weight:700;min-width:20px;text-align:right">3</span></div></div><div style="margin-bottom:24px"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-muted);margin-bottom:10px">Tempo</div><div style="display:flex;gap:6px" id="vibe-tempo">' +
                ['slow', 'medium', 'fast'].map(t => '<button data-t="' + t + '" style="flex:1;background:transparent;border:1px solid var(--border);color:var(--text-primary);padding:8px;border-radius:8px;cursor:pointer;text-transform:capitalize">' + t + '</button>').join('') +
                '</div></div><div style="display:flex;gap:8px;justify-content:flex-end"><button id="vibe-cancel" style="background:transparent;border:1px solid var(--border);color:var(--text-primary);padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600">Cancel</button><button id="vibe-go" style="background:rgb(var(--accent-rgb,30,215,96));color:#000;border:0;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:700">✨ Generate</button></div></div>';
            document.body.appendChild(modal);
            const moodsBox = modal.querySelector('#vibe-moods');
            moodsBox.innerHTML = moods.map(m => '<button data-m="' + m.key + '" style="background:' + (m.key === mood ? 'rgb(var(--accent-rgb,30,215,96))' : 'transparent') + ';color:' + (m.key === mood ? '#000' : 'var(--text-primary)') + ';border:1px solid var(--border);border-radius:10px;padding:14px 8px;cursor:pointer;font-size:13px;font-weight:600;display:flex;flex-direction:column;align-items:center;gap:4px"><span style="font-size:24px">' + m.emoji + '</span><span>' + m.label + '</span></button>').join('');
            moodsBox.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-m]');
                if (!btn) return;
                mood = btn.dataset.m;
                moodsBox.querySelectorAll('[data-m]').forEach(b => {
                    b.style.background = b.dataset.m === mood ? 'rgb(var(--accent-rgb,30,215,96))' : 'transparent';
                    b.style.color = b.dataset.m === mood ? '#000' : 'var(--text-primary)';
                });
            });
            const tempoBox = modal.querySelector('#vibe-tempo');
            tempoBox.querySelectorAll('[data-t="' + tempo + '"]').forEach(b => { b.style.background = 'rgb(var(--accent-rgb,30,215,96))'; b.style.color = '#000'; });
            tempoBox.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-t]');
                if (!btn) return;
                tempo = btn.dataset.t;
                tempoBox.querySelectorAll('[data-t]').forEach(b => {
                    b.style.background = b.dataset.t === tempo ? 'rgb(var(--accent-rgb,30,215,96))' : 'transparent';
                    b.style.color = b.dataset.t === tempo ? '#000' : 'var(--text-primary)';
                });
            });
            const energySlider = modal.querySelector('#vibe-energy');
            const energyVal = modal.querySelector('#vibe-energy-val');
            energySlider.addEventListener('input', () => { energy = parseInt(energySlider.value, 10); energyVal.textContent = energy; });
            modal.querySelector('#vibe-cancel').addEventListener('click', () => modal.remove());
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
            modal.querySelector('#vibe-go').addEventListener('click', async () => {
                modal.remove();
                if (window.__showToast) window.__showToast('Generating ' + mood + ' vibe playlist…');
                try {
                    const fd = new FormData();
                    fd.append('mood', mood); fd.append('energy', String(energy)); fd.append('tempo', tempo);
                    const r = await fetch('/api/vibe-check', { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok && d.playlist_id) {
                        if (window.__showToast) window.__showToast('✨ Created: ' + d.name + ' (' + d.count + ' tracks)');
                        if (window.__refreshSidebarPlaylists) window.__refreshSidebarPlaylists();
                        if (window.__navigate) window.__navigate('/playlist/' + d.playlist_id);
                    } else if (window.__showToast) window.__showToast('Failed: ' + (d.error || 'unknown'));
                } catch (_) { if (window.__showToast) window.__showToast('Network error'); }
            });
        };
        return { open };
    })();

    window.__livePlayingIndicator = (() => {
        if (window.__livePlayingInit) return;
        window.__livePlayingInit = true;
        const ensure = () => {
            let el = document.getElementById('live-playing-pill');
            if (el) return el;
            el = document.createElement('div');
            el.id = 'live-playing-pill';
            el.style.cssText = 'position:fixed;top:calc(env(safe-area-inset-top,0px) + 10px);left:50%;transform:translateX(-50%);background:rgba(22,24,28,0.95);color:#fff;padding:6px 14px;border-radius:999px;font-family:Inter,system-ui;font-size:11px;font-weight:600;z-index:9000;display:none;border:1px solid rgba(var(--accent-rgb,30,215,96),0.4);backdrop-filter:blur(12px);box-shadow:0 4px 12px rgba(0,0,0,0.4);cursor:pointer;';
            el.addEventListener('click', () => {
                document.getElementById('pb-devices')?.click();
            });
            document.body.appendChild(el);
            return el;
        };
        const update = (devices) => {
            const playing = devices.filter(d => d.is_playing).length;
            const el = ensure();
            if (playing <= 1) { el.style.display = 'none'; return; }
            el.style.display = 'block';
            const dot = '<span style="display:inline-block;width:6px;height:6px;background:#1ed760;border-radius:50%;margin-right:6px;animation:livePulse 1.5s infinite"></span>';
            el.innerHTML = dot + 'Playing on ' + playing + ' devices';
        };
        window.addEventListener('doniix-live-devices', (e) => {
            if (e.detail && e.detail.devices) update(e.detail.devices);
        });
        if (!document.getElementById('live-pulse-style')) {
            const s = document.createElement('style');
            s.id = 'live-pulse-style';
            s.textContent = '@keyframes livePulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }';
            document.head.appendChild(s);
        }
    })();

    window.__bookmarks = (() => {
        const restoreOffer = async (songId) => {
            if (!songId || !window.__platform) return;
            try {
                const params = new URLSearchParams({
                    u: 'doniix', v: '1.16.1', c: 'doniix-web', f: 'json', id: String(songId),
                });
                const r = await fetch('/rest/getBookmarks?' + params, { credentials: 'same-origin' });
                if (!r.ok) return;
                const d = await r.json();
                const bookmarks = d?.['subsonic-response']?.bookmarks?.bookmark || [];
                const match = bookmarks.find?.(b => String(b?.entry?.id) === String(songId));
                if (!match || !match.position) return;
                const pos = parseInt(match.position, 10) / 1000;
                const audio = document.querySelector('audio');
                if (!audio || pos < 30) return;
                if (window.__showToast) {
                    const toast = document.createElement('div');
                    toast.style.cssText = 'pointer-events:auto;background:#16181c;color:#e7e9ea;padding:12px 18px;border-radius:10px;border:1px solid rgba(var(--accent-rgb,30,215,96),0.4);box-shadow:0 8px 28px rgba(0,0,0,0.55);font-size:13px;font-weight:600;font-family:Inter,system-ui;cursor:pointer;display:flex;gap:10px;align-items:center;';
                    toast.innerHTML = '🔖 Resume from ' + Math.floor(pos / 60) + ':' + String(Math.floor(pos % 60)).padStart(2, '0') + '? <button style="background:rgb(var(--accent-rgb,30,215,96));color:#000;border:0;border-radius:6px;padding:4px 10px;font-weight:700;cursor:pointer">Yes</button>';
                    const yesBtn = toast.querySelector('button');
                    const container = document.getElementById('toast-stack') || document.body;
                    container.appendChild(toast);
                    yesBtn.addEventListener('click', () => {
                        try { audio.currentTime = pos; } catch (_) {}
                        toast.remove();
                    });
                    setTimeout(() => toast.remove(), 8000);
                }
            } catch (_) {}
        };
        const save = async () => {
            const meta = window.__currentTrackMeta || {};
            const audio = document.querySelector('audio');
            if (!meta.songId || !audio) return;
            const params = new URLSearchParams({
                u: 'doniix', v: '1.16.1', c: 'doniix-web', f: 'json',
                id: String(meta.songId),
                position: String(Math.floor(audio.currentTime * 1000)),
            });
            await fetch('/rest/createBookmark?' + params, { credentials: 'same-origin' }).catch(() => {});
            if (window.__showToast) window.__showToast('🔖 Bookmarked at ' + Math.floor(audio.currentTime) + 's');
        };
        return { save, restoreOffer };
    })();

    window.__liveDeviceFeed = (() => {
        if (window.__liveFeedInit) return;
        window.__liveFeedInit = true;
        let es = null;
        let reconnectTimer = null;
        const connect = () => {
            if (es) return;
            try {
                es = new EventSource('/api/events/live');
                es.addEventListener('message', (e) => {
                    try {
                        const d = JSON.parse(e.data);
                        if (d && d.devices) {
                            window.dispatchEvent(new CustomEvent('doniix-live-devices', { detail: d }));
                        }
                    } catch (_) {}
                });
                es.addEventListener('error', () => {
                    try { es.close(); } catch (_) {}
                    es = null;
                    if (reconnectTimer) clearTimeout(reconnectTimer);
                    reconnectTimer = setTimeout(connect, 5000);
                });
            } catch (_) { es = null; }
        };
        const disconnect = () => {
            if (es) { try { es.close(); } catch (_) {} es = null; }
            if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') connect();
            else disconnect();
        });
        if (document.visibilityState === 'visible') connect();
        window.addEventListener('beforeunload', disconnect);
        return { connect, disconnect };
    })();

    window.__continuousAlbum = (() => {
        const get = () => { try { return localStorage.getItem('doniix-continuous-album') === '1'; } catch (_) { return false; } };
        const set = (val) => { try { localStorage.setItem('doniix-continuous-album', val ? '1' : '0'); } catch (_) {} };
        const audio = document.querySelector('audio');
        audio?.addEventListener('ended', async () => {
            if (!get()) return;
            const q = window.doniixify?.queue || [];
            if (queueIndex + 1 < q.length) return;
            const currentRow = q[queueIndex];
            if (!currentRow) return;
            const currentAlbum = currentRow.dataset?.album;
            const currentArtist = currentRow.dataset?.artist;
            if (!currentAlbum) return;
            try {
                const sameAlbumRows = document.querySelectorAll('tr[data-album="' + CSS.escape(currentAlbum) + '"][data-song-id]');
                if (sameAlbumRows.length > q.length) {
                    let added = 0;
                    const existing = new Set(q.map(r => Number(r.dataset.songId)));
                    sameAlbumRows.forEach(r => {
                        const id = Number(r.dataset.songId);
                        if (!existing.has(id)) {
                            q.push(r.cloneNode(true));
                            added++;
                        }
                    });
                    if (added > 0 && window.__showToast) window.__showToast('Continuous album: +' + added + ' tracks');
                    return;
                }
                const r = await fetch('/api/discover');
                const d = await r.json();
                const moreFromArtist = (d?.more_like || []).filter(s => s.artist_id);
                if (moreFromArtist.length > 0) {
                    const next = moreFromArtist[Math.floor(Math.random() * moreFromArtist.length)];
                    if (window.doniixify?.loadSong) window.doniixify.loadSong(Number(next.id), next.title, next.artist_name, true);
                    if (window.__showToast) window.__showToast('Continuous: ' + next.title);
                }
            } catch (_) {}
        });
        return { get, set };
    })();

    window.__upNextHint = (() => {
        const ensure = () => {
            let el = document.getElementById('pb-up-next-hint');
            if (el) return el;
            const meta = document.querySelector('.player-bar .pb-meta');
            if (!meta) return null;
            el = document.createElement('div');
            el.id = 'pb-up-next-hint';
            el.style.cssText = 'font-size:10px;color:rgba(255,255,255,0.5);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:1px;display:none;';
            meta.appendChild(el);
            return el;
        };
        const update = () => {
            try {
                const q = window.doniixify?.queue || [];
                const nextRow = q[queueIndex + 1];
                if (!nextRow) {
                    const el = document.getElementById('pb-up-next-hint');
                    if (el) el.style.display = 'none';
                    return;
                }
                const el = ensure();
                if (!el) return;
                const title = nextRow.dataset?.title || '—';
                const artist = nextRow.dataset?.artist || '';
                const a = document.querySelector('audio');
                const showThreshold = (a && a.duration && (a.duration - a.currentTime) < 20);
                if (showThreshold) {
                    el.style.display = 'block';
                    el.textContent = '▶ Next: ' + title + (artist ? ' · ' + artist : '');
                } else {
                    el.style.display = 'none';
                }
            } catch (_) {}
        };
        const audio = document.querySelector('audio');
        audio?.addEventListener('timeupdate', () => {
            if (Math.floor(audio.currentTime) % 5 === 0) update();
        });
        audio?.addEventListener('ended', () => {
            const el = document.getElementById('pb-up-next-hint');
            if (el) el.style.display = 'none';
        });
        return { update };
    })();

    window.__spectrumFullscreen = (() => {
        let overlay = null, canvas = null, ctx = null, rafId = null, analyser = null;
        const open = () => {
            if (!window.__audioCtx || !window.__audioSrcNode) { if (window.__showToast) window.__showToast('Start playback first'); return; }
            if (!analyser) {
                analyser = window.__audioCtx.createAnalyser();
                analyser.fftSize = 1024;
                window.__audioSrcNode.connect(analyser);
            }
            if (overlay) return;
            overlay = document.createElement('div');
            overlay.id = 'spectrum-overlay';
            overlay.style.cssText = 'position:fixed;inset:0;background:#000;z-index:9800;display:flex;align-items:center;justify-content:center;';
            canvas = document.createElement('canvas');
            canvas.style.cssText = 'width:100vw;height:100vh;';
            ctx = canvas.getContext('2d');
            overlay.appendChild(canvas);
            const closeBtn = document.createElement('button');
            closeBtn.style.cssText = 'position:absolute;top:calc(env(safe-area-inset-top,0px) + 14px);right:14px;background:rgba(0,0,0,0.6);color:#fff;border:1px solid rgba(255,255,255,0.2);border-radius:50%;width:42px;height:42px;cursor:pointer;font-size:20px;line-height:1;display:flex;align-items:center;justify-content:center;';
            closeBtn.innerHTML = '×';
            closeBtn.addEventListener('click', close);
            overlay.appendChild(closeBtn);
            document.body.appendChild(overlay);
            const buf = new Uint8Array(analyser.frequencyBinCount);
            const draw = () => {
                if (!overlay) return;
                const dpr = window.devicePixelRatio || 1;
                if (canvas.width !== window.innerWidth * dpr) { canvas.width = window.innerWidth * dpr; canvas.height = window.innerHeight * dpr; }
                analyser.getByteFrequencyData(buf);
                const w = canvas.width, h = canvas.height;
                ctx.fillStyle = 'rgba(0,0,0,0.15)';
                ctx.fillRect(0, 0, w, h);
                const bars = 96;
                const barW = w / bars * 0.85;
                const gap = w / bars * 0.15;
                const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent-rgb').trim() || '30,215,96';
                for (let i = 0; i < bars; i++) {
                    const v = buf[Math.floor(i * buf.length / bars)] / 255;
                    const bh = Math.pow(v, 0.7) * h * 0.85;
                    const grad = ctx.createLinearGradient(0, h - bh, 0, h);
                    grad.addColorStop(0, 'rgba(' + accent + ',0.95)');
                    grad.addColorStop(1, 'rgba(' + accent + ',0.3)');
                    ctx.fillStyle = grad;
                    ctx.fillRect(i * (barW + gap), h - bh, barW, bh);
                }
                rafId = requestAnimationFrame(draw);
            };
            draw();
        };
        const close = () => {
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
            if (overlay) { overlay.remove(); overlay = null; canvas = null; ctx = null; }
        };
        return { open, close };
    })();

    window.__reverbEffect = (() => {
        let convolver = null;
        let wetGain = null;
        let dryGain = null;
        let active = false;
        const createImpulse = (ctx, duration, decay) => {
            const rate = ctx.sampleRate;
            const length = rate * duration;
            const impulse = ctx.createBuffer(2, length, rate);
            for (let ch = 0; ch < 2; ch++) {
                const data = impulse.getChannelData(ch);
                for (let i = 0; i < length; i++) {
                    data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / length, decay);
                }
            }
            return impulse;
        };
        const setup = () => {
            if (convolver || !window.__audioCtx || !window.__audioSrcNode) return false;
            try {
                convolver = window.__audioCtx.createConvolver();
                convolver.buffer = createImpulse(window.__audioCtx, 2.5, 3);
                wetGain = window.__audioCtx.createGain();
                wetGain.gain.value = 0;
                dryGain = window.__audioCtx.createGain();
                dryGain.gain.value = 1;
                const dest = window.__replayGainNode || window.__audioCtx.destination;
                window.__audioSrcNode.connect(dryGain).connect(dest);
                window.__audioSrcNode.connect(convolver).connect(wetGain).connect(dest);
                return true;
            } catch (_) { convolver = null; return false; }
        };
        const setMix = (wet) => {
            wet = Math.max(0, Math.min(1, wet));
            if (!convolver) setup();
            if (!convolver) return;
            if (wetGain) wetGain.gain.setTargetAtTime(wet, window.__audioCtx.currentTime, 0.1);
            if (dryGain) dryGain.gain.setTargetAtTime(1 - wet * 0.3, window.__audioCtx.currentTime, 0.1);
            active = wet > 0;
            try { localStorage.setItem('doniix-reverb-wet', String(wet)); } catch (_) {}
        };
        const get = () => {
            try { return parseFloat(localStorage.getItem('doniix-reverb-wet') || '0'); } catch (_) { return 0; }
        };
        return { setMix, get, isActive: () => active };
    })();

    window.__pitchShift = (() => {
        let semitones = 0;
        let detuneNode = null;
        const apply = () => {
            const a = document.querySelector('audio');
            if (!a) return;
            a.preservesPitch = (semitones === 0);
            if (window.__audioCtx && window.__audioSrcNode) {
                if (!detuneNode) {
                    try {
                        detuneNode = window.__audioCtx.createGain();
                        detuneNode.gain.value = 1;
                    } catch (_) {}
                }
            }
            const rateAdjust = Math.pow(2, semitones / 12);
            if (semitones !== 0) {
                a.playbackRate = rateAdjust;
                a.preservesPitch = false;
            } else {
                try {
                    const savedRate = parseFloat(localStorage.getItem('doniix-playback-rate') || '1');
                    a.playbackRate = (savedRate >= 0.5 && savedRate <= 2) ? savedRate : 1;
                    a.preservesPitch = true;
                } catch (_) { a.playbackRate = 1; a.preservesPitch = true; }
            }
        };
        const set = (n) => {
            semitones = Math.max(-12, Math.min(12, n));
            try { localStorage.setItem('doniix-pitch-semi', String(semitones)); } catch (_) {}
            apply();
        };
        try {
            const saved = parseInt(localStorage.getItem('doniix-pitch-semi') || '0', 10);
            if (saved >= -12 && saved <= 12 && saved !== 0) semitones = saved;
        } catch (_) {}
        return { set, get: () => semitones, apply };
    })();

    window.__abLoop = (() => {
        let pointA = null;
        let pointB = null;
        let rafId = null;
        const indicator = () => {
            let el = document.getElementById('ab-loop-indicator');
            if (!el) {
                el = document.createElement('div');
                el.id = 'ab-loop-indicator';
                el.style.cssText = 'position:fixed;bottom:140px;right:12px;background:rgba(22,24,28,0.95);color:#fff;padding:8px 14px;border-radius:10px;font-family:JetBrains Mono,monospace;font-size:12px;font-weight:700;z-index:9100;display:none;border:1px solid rgba(var(--accent-rgb,30,215,96),0.5);box-shadow:0 4px 16px rgba(0,0,0,0.4);cursor:pointer;';
                el.addEventListener('click', clear);
                document.body.appendChild(el);
            }
            return el;
        };
        const fmt = (s) => { const m = Math.floor(s/60); const sec = Math.floor(s%60); return m + ':' + String(sec).padStart(2,'0'); };
        const render = () => {
            const el = indicator();
            if (pointA == null && pointB == null) { el.style.display = 'none'; return; }
            el.style.display = 'block';
            const aStr = pointA != null ? fmt(pointA) : '—';
            const bStr = pointB != null ? fmt(pointB) : '—';
            el.textContent = '🔁 ' + aStr + ' → ' + bStr + ' · tap to clear';
        };
        const tick = () => {
            const a = document.querySelector('audio');
            if (!a || pointA == null || pointB == null) { rafId = null; return; }
            if (a.currentTime >= pointB) {
                a.currentTime = pointA;
            }
            rafId = requestAnimationFrame(tick);
        };
        const setPoint = () => {
            const a = document.querySelector('audio');
            if (!a) return;
            if (pointA == null) {
                pointA = a.currentTime;
                if (window.__showToast) window.__showToast('A point: ' + fmt(pointA));
            } else if (pointB == null) {
                pointB = a.currentTime;
                if (pointB <= pointA) { pointB = null; pointA = a.currentTime; if (window.__showToast) window.__showToast('New A point: ' + fmt(pointA)); }
                else {
                    if (window.__showToast) window.__showToast('B point: ' + fmt(pointB) + ' · looping');
                    if (!rafId) rafId = requestAnimationFrame(tick);
                }
            } else {
                clear();
                return;
            }
            render();
        };
        const clear = () => {
            const hadActive = pointA != null || pointB != null;
            pointA = null;
            pointB = null;
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
            render();
            if (hadActive && window.__showToast) window.__showToast('A-B loop cleared');
        };
        document.querySelector('audio')?.addEventListener('emptied', clear);
        return { setPoint, clear, getA: () => pointA, getB: () => pointB };
    })();

    window.__reversePlayback = (() => {
        let reverseBuf = null;
        let source = null;
        let startedAt = 0;
        let pausedAt = 0;
        let songIdCached = null;
        const start = async () => {
            if (!window.__audioCtx) return false;
            const audio = document.querySelector('audio');
            if (!audio || !currentSongId) return false;
            try {
                if (reverseBuf == null || songIdCached !== currentSongId) {
                    if (window.__showToast) window.__showToast('Loading reverse buffer…');
                    const resp = await fetch('/stream/' + Number(currentSongId));
                    const arr = await resp.arrayBuffer();
                    const decoded = await window.__audioCtx.decodeAudioData(arr);
                    const channels = decoded.numberOfChannels;
                    reverseBuf = window.__audioCtx.createBuffer(channels, decoded.length, decoded.sampleRate);
                    for (let c = 0; c < channels; c++) {
                        const src = decoded.getChannelData(c);
                        const dst = reverseBuf.getChannelData(c);
                        for (let i = 0, n = src.length; i < n; i++) dst[n - 1 - i] = src[i];
                    }
                    songIdCached = currentSongId;
                }
                audio.pause();
                source = window.__audioCtx.createBufferSource();
                source.buffer = reverseBuf;
                source.connect(window.__replayGainNode || window.__audioCtx.destination);
                source.start();
                startedAt = window.__audioCtx.currentTime;
                if (window.__showToast) window.__showToast('▶ Reverse playback');
                return true;
            } catch (e) {
                if (window.__showToast) window.__showToast('Reverse failed: ' + (e.message || e));
                return false;
            }
        };
        const stop = () => {
            try { source?.stop(); } catch (_) {}
            source = null;
        };
        const toggle = async () => {
            if (source) { stop(); if (window.__showToast) window.__showToast('Reverse stopped'); return false; }
            return await start();
        };
        return { toggle, start, stop, isPlaying: () => !!source };
    })();

    window.__shareWrapImage = async (wrapData) => {
        const canvas = document.createElement('canvas');
        canvas.width = 1080;
        canvas.height = 1920;
        const ctx = canvas.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 1920);
        grad.addColorStop(0, 'rgb(' + (getComputedStyle(document.documentElement).getPropertyValue('--accent-rgb').trim() || '30,215,96') + ')');
        grad.addColorStop(1, '#08080c');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 1080, 1920);
        ctx.fillStyle = '#fff';
        ctx.textAlign = 'center';
        ctx.font = '900 64px Inter, system-ui';
        ctx.fillText(wrapData.year || new Date().getFullYear(), 540, 280);
        ctx.font = '700 36px Inter, system-ui';
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        ctx.fillText('Year in music', 540, 340);
        ctx.font = '900 220px Inter, system-ui';
        ctx.fillStyle = '#fff';
        ctx.fillText(String(wrapData.total_hours || 0), 540, 700);
        ctx.font = '600 42px Inter, system-ui';
        ctx.fillStyle = 'rgba(255,255,255,0.7)';
        ctx.fillText('hours of music', 540, 770);
        ctx.font = '700 32px Inter, system-ui';
        ctx.fillStyle = 'rgba(255,255,255,0.5)';
        ctx.fillText(String(wrapData.total_plays || 0) + ' plays  ·  ' + String(wrapData.unique_tracks || 0) + ' tracks', 540, 850);
        if (wrapData.top_song && wrapData.top_song.id) {
            await new Promise((res) => {
                const img = new Image();
                img.crossOrigin = 'anonymous';
                img.onload = () => {
                    ctx.save();
                    ctx.beginPath();
                    ctx.roundRect(290, 1000, 500, 500, 24);
                    ctx.clip();
                    ctx.drawImage(img, 290, 1000, 500, 500);
                    ctx.restore();
                    res();
                };
                img.onerror = () => res();
                img.src = '/cover/' + wrapData.top_song.id;
            });
            ctx.font = '700 32px Inter, system-ui';
            ctx.fillStyle = 'rgba(255,255,255,0.6)';
            ctx.fillText('Your #1 song', 540, 1580);
            ctx.font = '800 44px Inter, system-ui';
            ctx.fillStyle = '#fff';
            const songTitle = (wrapData.top_song.title || '').slice(0, 26);
            ctx.fillText(songTitle, 540, 1660);
            ctx.font = '500 30px Inter, system-ui';
            ctx.fillStyle = 'rgba(255,255,255,0.7)';
            ctx.fillText(wrapData.top_song.artist_name || '', 540, 1710);
        }
        ctx.font = '600 28px Inter, system-ui';
        ctx.fillStyle = 'rgba(255,255,255,0.45)';
        ctx.fillText('Doniixify', 540, 1840);
        const blob = await new Promise((res) => canvas.toBlob(res, 'image/png'));
        if (!blob) return false;
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'doniixify-wrap-' + (wrapData.year || new Date().getFullYear()) + '.png';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
        if (navigator.share && navigator.canShare?.({ files: [new File([blob], a.download, { type: 'image/png' })] })) {
            try { await navigator.share({ files: [new File([blob], a.download, { type: 'image/png' })], title: 'My ' + (wrapData.year || '') + ' wrap' }); } catch (_) {}
        }
        return true;
    };

    window.__moodModes = (() => {
        const modes = {
            workout: {
                name: 'Workout',
                eq: 'electronic',
                minDuration: 0,
                speed: 1.0,
                concert: false,
                tagFilter: ['electronic', 'edm', 'hiphop', 'rap', 'rock', 'metal'],
            },
            focus: {
                name: 'Focus',
                eq: 'classical',
                speed: 1.0,
                tagFilter: ['classical', 'instrumental', 'ambient', 'lo-fi', 'jazz', 'acoustic'],
            },
            bedtime: {
                name: 'Bedtime',
                eq: 'jazz',
                speed: 0.9,
                volumeReduce: 30,
                tagFilter: ['ambient', 'acoustic', 'classical', 'jazz', 'chill', 'lo-fi'],
            },
            party: {
                name: 'Party',
                eq: 'electronic',
                concert: true,
                tagFilter: ['party', 'dance', 'pop', 'edm', 'house', 'electronic'],
            },
        };
        const apply = async (modeName) => {
            const m = modes[modeName];
            if (!m) return false;
            try {
                if (m.eq && window.__applyEqPreset) window.__applyEqPreset(m.eq);
                if (m.speed != null) {
                    const a = document.querySelector('audio');
                    if (a) { a.playbackRate = m.speed; try { localStorage.setItem('doniix-playback-rate', String(m.speed)); } catch (_) {} }
                }
                if (m.volumeReduce != null) {
                    const a = document.querySelector('audio');
                    if (a) {
                        const target = Math.max(0.1, a.volume - m.volumeReduce / 100);
                        const start = a.volume;
                        const dur = 8000;
                        const startT = Date.now();
                        const fade = () => {
                            const t = (Date.now() - startT) / dur;
                            if (t >= 1) { a.volume = target; return; }
                            a.volume = start + (target - start) * t;
                            requestAnimationFrame(fade);
                        };
                        fade();
                    }
                }
                if (m.concert && window.__concertMode) window.__concertMode.toggle();
                if (window.__showToast) window.__showToast(m.name + ' mode activated');
                return true;
            } catch (e) {
                if (window.__showToast) window.__showToast('Mood mode failed: ' + (e.message || e));
                return false;
            }
        };
        return { apply, modes };
    })();

    window.__driveMode = (() => {
        let active = false;
        let wakeLock = null;
        const toggle = async () => {
            if (active) {
                document.body.classList.remove('drive-mode');
                if (wakeLock) { try { await wakeLock.release(); } catch (_) {} wakeLock = null; }
                if (document.fullscreenElement) { try { await document.exitFullscreen(); } catch (_) {} }
                active = false;
                if (window.__showToast) window.__showToast('Drive mode off');
                return false;
            }
            try { if (document.documentElement.requestFullscreen) await document.documentElement.requestFullscreen(); } catch (_) {}
            try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (_) {}
            document.body.classList.add('drive-mode');
            active = true;
            if (window.__showToast) window.__showToast('Drive mode — huge UI, screen stays on');
            return true;
        };
        return { toggle, isActive: () => active };
    })();

    window.__concertMode = (() => {
        let active = false;
        let escHandler = null;
        const toggle = async () => {
            if (active) {
                document.body.classList.remove('concert-mode');
                if (document.fullscreenElement) { try { await document.exitFullscreen(); } catch(_) {} }
                if (escHandler) document.removeEventListener('keydown', escHandler);
                active = false;
                return false;
            }
            try {
                if (document.documentElement.requestFullscreen) await document.documentElement.requestFullscreen();
            } catch (_) {}
            document.body.classList.add('concert-mode');
            active = true;
            escHandler = (e) => {
                if (e.key === 'Escape' || e.key === 'q') {
                    e.preventDefault();
                    toggle();
                }
            };
            document.addEventListener('keydown', escHandler);
            try { if (window.__showToast) window.__showToast('Concert mode — press Esc or Q to exit'); } catch(_) {}
            return true;
        };
        return { toggle, isActive: () => active };
    })();

    window.__translateLyrics = async (targetLang) => {
        const hasTranslated = document.querySelector('[data-orig-text]');
        if (hasTranslated) {
            window.__restoreLyricsOriginal();
            if (window.__showToast) window.__showToast('Original restored');
            return;
        }
        const saved = (() => { try { return localStorage.getItem('doniix-translate-lang'); } catch (_) { return null; } })();
        const target = targetLang || saved || (navigator.language || 'en').split('-')[0];
        try { localStorage.setItem('doniix-translate-lang', target); } catch (_) {}
        const containers = [
            document.getElementById('lf-lyrics'),
            document.getElementById('np-lyrics-text'),
            document.getElementById('np-fs-lyrics'),
        ].filter(Boolean);
        let lines = [];
        for (const c of containers) {
            const els = c.querySelectorAll('.lf-lyric-line, .np-lyric-line');
            if (els.length) {
                lines = Array.from(els).map(el => el.textContent || '').filter(Boolean);
                if (lines.length) break;
            }
        }
        if (!lines.length) {
            if (window.__showToast) window.__showToast('No lyrics to translate');
            return;
        }
        const joined = lines.join('\n');
        if (window.__showToast) window.__showToast('Translating to ' + target + '…');
        try {
            const r = await fetch('/api/lyrics/translate?target=' + encodeURIComponent(target) + '&text=' + encodeURIComponent(joined));
            const d = await r.json();
            if (!d || !d.translation) { if (window.__showToast) window.__showToast('Translate failed'); return; }
            const tr = d.translation.split('\n');
            for (const c of containers) {
                const els = c.querySelectorAll('.lf-lyric-line, .np-lyric-line');
                els.forEach((el, i) => {
                    if (tr[i]) {
                        if (!el.dataset.origText) el.dataset.origText = el.textContent;
                        el.textContent = tr[i];
                    }
                });
            }
            if (window.__showToast) window.__showToast('Translated. Click translate again to restore.');
        } catch (_) {
            if (window.__showToast) window.__showToast('Translate failed');
        }
    };
    window.__restoreLyricsOriginal = () => {
        document.querySelectorAll('[data-orig-text]').forEach(el => {
            if (el.dataset.origText) {
                el.textContent = el.dataset.origText;
                delete el.dataset.origText;
            }
        });
    };

    (function removeSearchFab() {
        const remove = () => {
            const fab = document.getElementById('mobile-search-fab');
            if (fab) fab.remove();
        };
        remove();
        document.addEventListener('DOMContentLoaded', remove);
        setTimeout(remove, 500);
        setTimeout(remove, 2000);
    })();

    window.__freqVisualizer = (() => {
        if (window.__freqVizInit) return;
        window.__freqVizInit = true;
        let canvas = null, ctx = null, rafId = null;
        const BARS = 24;
        const ensure = () => {
            const pbCover = document.querySelector('.player-bar .pb-cover');
            if (!pbCover) return null;
            if (canvas && canvas.parentNode === pbCover) return canvas;
            canvas = document.createElement('canvas');
            canvas.id = 'pb-freq-viz';
            canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;pointer-events:none;opacity:0;transition:opacity 0.3s ease;mix-blend-mode:overlay;border-radius:inherit;';
            pbCover.style.position = 'relative';
            pbCover.appendChild(canvas);
            ctx = canvas.getContext('2d');
            return canvas;
        };
        const start = () => {
            if (!window.__audioCtx || !window.__audioSrcNode) return;
            const c = ensure();
            if (!c) return;
            let analyser = window.__freqAnalyser;
            if (!analyser) {
                analyser = window.__audioCtx.createAnalyser();
                analyser.fftSize = 64;
                window.__audioSrcNode.connect(analyser);
                window.__freqAnalyser = analyser;
            }
            const buf = new Uint8Array(analyser.frequencyBinCount);
            c.style.opacity = '0.6';
            const tick = () => {
                if (!c || !ctx) return;
                analyser.getByteFrequencyData(buf);
                const rect = c.getBoundingClientRect();
                const dpr = window.devicePixelRatio || 1;
                if (c.width !== rect.width * dpr) { c.width = rect.width * dpr; c.height = rect.height * dpr; }
                const w = c.width, h = c.height;
                ctx.clearRect(0, 0, w, h);
                const barW = w / BARS * 0.7;
                const gap = w / BARS * 0.3;
                ctx.fillStyle = 'rgba(255,255,255,0.6)';
                for (let i = 0; i < BARS; i++) {
                    const v = buf[Math.floor(i * buf.length / BARS)] / 255;
                    const bh = v * h * 0.9;
                    ctx.fillRect(i * (barW + gap), h - bh, barW, bh);
                }
                rafId = requestAnimationFrame(tick);
            };
            tick();
        };
        const stop = () => {
            if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
            if (canvas) canvas.style.opacity = '0';
        };
        const audio = document.querySelector('audio');
        audio?.addEventListener('play', () => {
            try { if (window.__audioSettings?.viz_enabled !== false) start(); } catch (_) {}
        });
        audio?.addEventListener('pause', stop);
        audio?.addEventListener('ended', stop);
        return { start, stop };
    })();

    window.__sleepBadge = (() => {
        if (window.__sleepBadgeInit) return;
        window.__sleepBadgeInit = true;
        let badge = null;
        const fmt = (ms) => {
            const total = Math.ceil(ms / 1000);
            const m = Math.floor(total / 60);
            return m + 'm';
        };
        const ensure = () => {
            if (badge && badge.isConnected) return badge;
            const pb = document.querySelector('.player-bar .pb-meta') || document.querySelector('.player-bar');
            if (!pb) return null;
            badge = document.createElement('div');
            badge.id = 'sleep-badge';
            badge.style.cssText = 'position:absolute;top:-6px;right:8px;background:rgba(30,215,96,0.9);color:#000;font-size:10px;font-weight:800;padding:2px 6px;border-radius:8px;pointer-events:auto;cursor:pointer;z-index:5;display:none;';
            badge.title = 'Click to cancel sleep timer';
            badge.addEventListener('click', (e) => {
                e.stopPropagation();
                if (window.__sleepTimer) window.__sleepTimer.stop();
                badge.style.display = 'none';
                if (window.__showToast) window.__showToast('Sleep timer cancelled');
            });
            (document.querySelector('.player-bar') || document.body).style.position = 'relative';
            (document.querySelector('.player-bar') || document.body).appendChild(badge);
            return badge;
        };
        const tick = () => {
            if (!window.__sleepTimer) return;
            const r = window.__sleepTimer.remaining();
            const b = ensure();
            if (!b) return;
            if (r > 0) {
                b.style.display = 'block';
                b.textContent = '💤 ' + fmt(r);
                if (r < 120 * 1000) {
                    b.style.animation = 'sleepPulse 1s ease-in-out infinite';
                    b.style.background = 'rgba(245, 100, 100, 0.95)';
                    b.style.color = '#fff';
                } else {
                    b.style.animation = '';
                    b.style.background = 'rgba(30,215,96,0.9)';
                    b.style.color = '#000';
                }
            } else {
                b.style.display = 'none';
                b.style.animation = '';
            }
        };
        setInterval(tick, 5000);
        setTimeout(tick, 500);
    })();

    window.__a2hs = (function () {
        let deferredEvent = null;
        let dismissedAt = 0;
        try { dismissedAt = parseInt(localStorage.getItem('doniix-a2hs-dismissed') || '0', 10); } catch (_) {}
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredEvent = e;
        });
        window.addEventListener('appinstalled', () => {
            deferredEvent = null;
            try { localStorage.setItem('doniix-a2hs-installed', '1'); } catch (_) {}
        });
        return {
            available: () => !!deferredEvent,
            prompt: async () => {
                if (!deferredEvent) return { error: 'no-prompt' };
                deferredEvent.prompt();
                const choice = await deferredEvent.userChoice.catch(() => null);
                deferredEvent = null;
                return { choice: choice && choice.outcome };
            },
            dismiss: () => {
                try { localStorage.setItem('doniix-a2hs-dismissed', String(Date.now())); } catch (_) {}
                dismissedAt = Date.now();
            },
            dismissedRecently: () => (Date.now() - dismissedAt) < 7 * 24 * 60 * 60 * 1000,
            isStandalone: () => window.__platform && window.__platform.isStandalone,
        };
    })();

    window.__sleepTimer = (function () {
        let timerId = null;
        let endsAt = 0;
        let fadeRafId = null;
        let endOfTrackMode = false;
        let endOfAlbumMode = false;
        let pauseHandler = null;
        const stop = () => {
            if (timerId) { clearTimeout(timerId); timerId = null; }
            if (fadeRafId) { cancelAnimationFrame(fadeRafId); fadeRafId = null; }
            endsAt = 0;
            endOfTrackMode = false;
            endOfAlbumMode = false;
            if (pauseHandler) {
                const a = document.querySelector('audio');
                if (a) a.removeEventListener('ended', pauseHandler);
                pauseHandler = null;
            }
            try { localStorage.removeItem('doniix-sleep-end'); } catch (_) {}
        };
        const set = (minutes, opts) => {
            stop();
            if (!minutes || minutes < 1) return false;
            const ms = minutes * 60 * 1000;
            endsAt = Date.now() + ms;
            try { localStorage.setItem('doniix-sleep-end', String(endsAt)); } catch (_) {}
            const fadeSec = (opts && opts.fadeOutSec) || 15;
            timerId = setTimeout(() => {
                const a = document.querySelector('audio');
                if (!a) return;
                const startVol = a.volume;
                const startT = Date.now();
                const fadeMs = fadeSec * 1000;
                const tick = () => {
                    if (a.paused) { a.volume = startVol; stop(); return; }
                    const elapsed = Date.now() - startT;
                    if (elapsed >= fadeMs) {
                        a.pause();
                        a.volume = startVol;
                        stop();
                        if (window.__showToast) window.__showToast('Sleep timer: paused');
                        return;
                    }
                    a.volume = Math.max(0, startVol * (1 - elapsed / fadeMs));
                    fadeRafId = requestAnimationFrame(tick);
                };
                tick();
            }, ms);
            return true;
        };
        const remaining = () => endsAt > 0 ? Math.max(0, endsAt - Date.now()) : 0;
        const setEndOfTrack = () => {
            stop();
            const a = document.querySelector('audio');
            if (!a) return false;
            endOfTrackMode = true;
            pauseHandler = () => {
                if (!endOfTrackMode) return;
                a.pause();
                stop();
                if (window.__showToast) window.__showToast('Sleep timer: end of track');
            };
            a.addEventListener('ended', pauseHandler, { once: true });
            return true;
        };
        const setEndOfAlbum = () => {
            stop();
            const a = document.querySelector('audio');
            if (!a) return false;
            const currentAlbum = window.doniixify?.queue?.[queueIndex]?.dataset?.album || '';
            endOfAlbumMode = true;
            pauseHandler = () => {
                if (!endOfAlbumMode) return;
                const q = window.doniixify?.queue || [];
                const nextAlbum = q[queueIndex + 1]?.dataset?.album || '';
                if (nextAlbum !== currentAlbum) {
                    a.pause();
                    stop();
                    if (window.__showToast) window.__showToast('Sleep timer: end of album');
                } else {
                    a.addEventListener('ended', pauseHandler, { once: true });
                }
            };
            a.addEventListener('ended', pauseHandler, { once: true });
            return true;
        };
        const mode = () => endOfTrackMode ? 'track' : endOfAlbumMode ? 'album' : (endsAt > 0 ? 'timer' : 'none');
        try {
            const saved = parseInt(localStorage.getItem('doniix-sleep-end') || '0', 10);
            if (saved > Date.now()) {
                const min = Math.ceil((saved - Date.now()) / 60000);
                set(min);
            }
        } catch (_) {}
        return { set, stop, remaining, setEndOfTrack, setEndOfAlbum, mode };
    })();

    window.__downloadForOffline = async (songId) => {
        if (!('caches' in window) || !songId) return { error: 'unsupported' };
        try {
            const cache = await caches.open('doniix-offline-audio-v1');
            const url = '/stream/' + Number(songId);
            const existing = await cache.match(url);
            if (existing) return { ok: true, existed: true };
            const r = await fetch(url);
            if (!r.ok) return { error: 'fetch-failed-' + r.status };
            await cache.put(url, r.clone());
            return { ok: true };
        } catch (e) { return { error: String(e && e.message || e) }; }
    };
    window.__removeFromOffline = async (songId) => {
        if (!('caches' in window) || !songId) return { error: 'unsupported' };
        try {
            const cache = await caches.open('doniix-offline-audio-v1');
            const removed = await cache.delete('/stream/' + Number(songId));
            return { ok: removed };
        } catch (e) { return { error: String(e && e.message || e) }; }
    };
    window.__listOfflineTracks = async () => {
        if (!('caches' in window)) return [];
        try {
            const cache = await caches.open('doniix-offline-audio-v1');
            const keys = await cache.keys();
            return keys.map(req => {
                const m = req.url.match(/\/stream\/(\d+)/);
                return m ? Number(m[1]) : null;
            }).filter(Boolean);
        } catch (_) { return []; }
    };
    window.__offlineStorageSize = async () => {
        if (!navigator.storage || !navigator.storage.estimate) return null;
        try { const e = await navigator.storage.estimate(); return { usage: e.usage, quota: e.quota }; }
        catch (_) { return null; }
    };

    window.__pushSubscribe = async (vapidPublicKey) => {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return { error: 'unsupported' };
        try {
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') return { error: 'denied' };
            const reg = await navigator.serviceWorker.ready;
            const existing = await reg.pushManager.getSubscription();
            if (existing) return { subscription: existing, existed: true };
            if (!vapidPublicKey) return { error: 'no-vapid-key' };
            const b64 = vapidPublicKey.replace(/-/g, '+').replace(/_/g, '/');
            const padded = b64 + '='.repeat((4 - b64.length % 4) % 4);
            const raw = atob(padded);
            const arr = new Uint8Array(raw.length);
            for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
            const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: arr });
            await fetch('/api/push/subscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(sub.toJSON())
            }).catch(() => {});
            return { subscription: sub };
        } catch (e) { return { error: String(e && e.message || e) }; }
    };
    window.__pushUnsubscribe = async () => {
        try {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (!sub) return { ok: true, already: true };
            const ep = sub.endpoint;
            const r = await sub.unsubscribe();
            await fetch('/api/push/unsubscribe', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ endpoint: ep })
            }).catch(() => {});
            return { ok: r };
        } catch (e) { return { error: String(e && e.message || e) }; }
    };

    window.__queueRetry = async (url, method, body, headers) => {
        try {
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'queue-retry',
                    entry: { url, method: method || 'POST', body: body || null, headers: headers || {} }
                });
            }
        } catch (_) {}
    };

    if (!window.__smartShuffle) {
        const fisherYates = (arr) => {
            for (let i = arr.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [arr[i], arr[j]] = [arr[j], arr[i]];
            }
            return arr;
        };
        window.__smartShuffle = (rows) => {
            if (!Array.isArray(rows) || rows.length < 3) return fisherYates(rows || []);
            const shuffled = fisherYates(rows.slice());
            const getArtist = (r) => (r?.dataset?.artist || r?.dataset?.artistId || '').toString().toLowerCase();
            const artists = new Set(shuffled.map(getArtist).filter(Boolean));
            if (artists.size <= 1) return shuffled;
            for (let pass = 0; pass < 3; pass++) {
                let swaps = 0;
                for (let i = 0; i < shuffled.length - 1; i++) {
                    const a = getArtist(shuffled[i]);
                    const b = getArtist(shuffled[i + 1]);
                    if (a && a === b) {
                        for (let j = i + 2; j < shuffled.length; j++) {
                            const c = getArtist(shuffled[j]);
                            if (c !== a && (j + 1 >= shuffled.length || getArtist(shuffled[j + 1]) !== c)) {
                                [shuffled[i + 1], shuffled[j]] = [shuffled[j], shuffled[i + 1]];
                                swaps++;
                                break;
                            }
                        }
                    }
                }
                if (swaps === 0) break;
            }
            return shuffled;
        };
    }

    if (!window.__csrfFetchPatched) {
        window.__csrfFetchPatched = true;
        const __origFetch = window.fetch.bind(window);
        const __getCsrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        window.fetch = function (input, init = {}) {
            try {
                const method = (init.method || (typeof input === 'object' && input?.method) || 'GET').toUpperCase();
                if (method !== 'GET' && method !== 'HEAD') {
                    const urlStr = (typeof input === 'string') ? input : (input?.url || '');
                    let sameOrigin = true;
                    try {
                        if (urlStr) {
                            const u = new URL(urlStr, location.origin);
                            sameOrigin = (u.origin === location.origin);
                        }
                    } catch (_) {}
                    if (sameOrigin) {
                        const token = __getCsrf();
                        if (token) {
                            const headers = new Headers(init.headers || (typeof input === 'object' ? input.headers : undefined) || {});
                            if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
                            init = { ...init, headers };
                        }
                    }
                }
            } catch (_) {}
            return __origFetch(input, init);
        };
    }

    const fetchWT = (url, opts = {}, ms = 8000) => {
        const ctrl = new AbortController();
        const t = setTimeout(() => ctrl.abort(), ms);
        return fetch(url, { ...opts, signal: ctrl.signal }).finally(() => clearTimeout(t));
    };
    window.fetchWT = fetchWT;

    const missingSongIds = new Set();
    const refreshingCovers = new Set();
    const purgeRow = (songId) => {
        document.querySelectorAll(`tr[data-song-id="${songId}"]`).forEach(el => el.remove());
        if (typeof queue !== 'undefined' && Array.isArray(queue)) {
            for (let i = queue.length - 1; i >= 0; i--) {
                if (queue[i] && Number(queue[i].dataset?.songId) === songId) queue.splice(i, 1);
            }
        }
    };
    const GENERIC_COVER_PALETTE = [
        ['#1e3a8a', '#3b82f6'], ['#7c2d12', '#ea580c'], ['#14532d', '#22c55e'],
        ['#581c87', '#a855f7'], ['#831843', '#ec4899'], ['#0c4a6e', '#0ea5e9'],
        ['#713f12', '#eab308'], ['#7f1d1d', '#ef4444'], ['#064e3b', '#10b981'],
        ['#1e1b4b', '#6366f1'], ['#365314', '#84cc16'], ['#3f1d1d', '#fb7185']
    ];
    const makeGenericCover = (seed) => {
        const idx = Math.abs(Number(seed) || 0) % GENERIC_COVER_PALETTE.length;
        const [a, b] = GENERIC_COVER_PALETTE[idx];
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="' + a + '"/><stop offset="100%" stop-color="' + b + '"/></linearGradient></defs><rect width="200" height="200" fill="url(#g)"/><g transform="translate(100 100)" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"><path d="M-20 25V-25l40-7v45"/><circle cx="-26" cy="25" r="8"/><circle cx="14" cy="18" r="8"/></g></svg>';
        return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
    };
    const applyGenericCover = (img, songId) => {
        try {
            img.dataset.genericCover = '1';
            img.src = makeGenericCover(songId || 0);
            img.style.opacity = '1';
            if (!songId) return;
            const wrap = img.parentElement;
            if (!wrap || wrap.querySelector('.cover-redl-btn')) return;
            const cs = getComputedStyle(wrap);
            if (cs.position === 'static') wrap.style.position = 'relative';
            const btn = document.createElement('button');
            btn.className = 'cover-redl-btn';
            btn.type = 'button';
            btn.title = 'Re-download to fix cover';
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
            btn.style.cssText = 'position:absolute;bottom:6px;right:6px;z-index:3;width:26px;height:26px;border-radius:50%;background:rgba(0,0,0,0.75);backdrop-filter:blur(6px);border:1px solid rgba(255,255,255,0.18);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;opacity:0.85;transition:opacity 0.15s ease,transform 0.15s ease';
            btn.addEventListener('mouseenter', () => { btn.style.opacity = '1'; btn.style.transform = 'scale(1.1)'; });
            btn.addEventListener('mouseleave', () => { btn.style.opacity = '0.85'; btn.style.transform = ''; });
            btn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                btn.disabled = true;
                btn.style.opacity = '0.5';
                try {
                    const r = await fetch('/api/song/' + songId + '/redownload', { method: 'POST' });
                    const d = await r.json();
                    if (d && d.ok) {
                        if (window.__showToast) window.__showToast('Queued for re-download · cover fix incoming');
                    } else {
                        if (window.__showToast) window.__showToast('Re-download failed: ' + (d?.error || 'unknown'));
                        btn.disabled = false;
                        btn.style.opacity = '0.85';
                    }
                } catch (_) {
                    btn.disabled = false;
                    btn.style.opacity = '0.85';
                }
            });
            wrap.appendChild(btn);
        } catch (_) {}
    };
    window.__handleMissingCover = (img) => {
        if (!img || img.dataset.genericCover === '1') return;
        let songId = Number(img?.dataset?.songId || 0);
        if (!songId && img?.src) {
            const m = img.src.match(/\/cover\/(\d+)/);
            if (m) songId = Number(m[1]);
        }
        if (!songId) { applyGenericCover(img, 0); return; }
        const retryCount = Number(img?.dataset?.coverRetry || 0);
        if (retryCount < 1) {
            img.dataset.coverRetry = String(retryCount + 1);
            if (!refreshingCovers.has(songId)) {
                refreshingCovers.add(songId);
                fetch('/cover/' + songId + '?refresh=1', { cache: 'no-store' })
                    .catch(() => {})
                    .finally(() => { refreshingCovers.delete(songId); });
            }
            setTimeout(() => {
                if (img.isConnected) img.src = '/cover/' + songId + '?t=' + Date.now();
            }, 2500);
            return;
        }
        applyGenericCover(img, songId);
    };

    const audio = document.getElementById('audio-player');
    if (!audio) return;

    // unlockAudio removed — modern browsers allow play() within user-gesture handlers

    const ui = {
        cover: document.querySelector('.pb-cover'),
        title: document.getElementById('pb-title'),
        artist: document.getElementById('pb-artist'),
        play: document.getElementById('pb-play'),
        prev: document.getElementById('pb-prev'),
        next: document.getElementById('pb-next'),
        favorite: document.getElementById('pb-favorite'),
        volumeBtn: document.getElementById('pb-volume'),
        volumeBar: document.querySelector('.pb-volume-bar'),
        volumeFill: document.getElementById('pb-volume-fill'),
        seekBar: document.getElementById('pb-seek-bar'),
        seekFill: document.getElementById('pb-seek-fill'),
        timeCurrent: document.getElementById('pb-time-current'),
        timeTotal: document.getElementById('pb-time-total'),
    };

    const ICONS = {
        play: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>',
        pause: '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>',
        playSmall: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>',
        pauseSmall: '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>',
        heart: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
        heartFill: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="#fa5252" stroke="#fa5252" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>',
        musicFallback: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        volume: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.5 7a8 8 0 0 1 0 10"/></svg>',
        volumeMute: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/><line x1="22" x2="16" y1="9" y2="15"/><line x1="16" x2="22" y1="9" y2="15"/></svg>',
        shuffle: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/></svg>',
        smart: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.5 4.5L18 9l-4.5 1.5L12 15l-1.5-4.5L6 9l4.5-1.5z"/><path d="M5 18l1 2 2 1-2 1-1 2-1-2-2-1 2-1z"/><path d="M19 14l1 2 2 1-2 1-1 2-1-2-2-1 2-1z"/></svg>',
    };

    const __uid = (window.__userId || 0);
    const __k = (k) => 'u' + __uid + ':' + k;
    const STATE_KEY = __k('doniixify-state-v1');
    const QUEUE_KEY = __k('doniixify-queue-v1');
    const FAVS_KEY = __k('doniixify-favs-v1');
    (function purgeLegacy() {
        try {
            const legacy = ['doniixify-state-v1', 'doniixify-queue-v1', 'doniixify-favs-v1'];
            legacy.forEach(k => localStorage.removeItem(k));
        } catch (e) {}
    })();

    const queue = [];
    let queueIndex = -1;
    let currentRow = null;
    let currentSongId = null;
    let lastVolume = 0.8;
    let favorites = new Set();
    let pendingResume = null;
    let pendingResumeSongId = null;
    let shuffleOn = false;
    let repeatMode = 0; // 0=off, 1=all, 2=one


    const loadFavs = function () {
        try {
            const f = JSON.parse(localStorage.getItem(FAVS_KEY) || '[]');
            favorites = new Set(f.map(Number));
        } catch (e) {}
        fetch('/api/favorites/list', { cache: 'no-store' }).then(r => r.ok ? r.json() : null).then(arr => {
            if (!Array.isArray(arr)) return;
            favorites = new Set(arr.map(Number));
            saveFavs();
            document.querySelectorAll('.row-fav-btn').forEach(btn => {
                const sid = Number(btn.dataset.songId);
                if (sid) btn.classList.toggle('active', favorites.has(sid));
            });
            if (currentSongId) updateFavIcon(currentSongId);
        }).catch(() => {});
    };
    const saveFavs = function () {
        localStorage.setItem(FAVS_KEY, JSON.stringify([...favorites]));
    };

    const saveState = function () {
        const state = {
            songId: currentSongId,
            time: audio.currentTime || 0,
            paused: audio.paused,
            volume: audio.volume,
            muted: audio.muted,
        };
        localStorage.setItem(STATE_KEY, JSON.stringify(state));
        localStorage.setItem(QUEUE_KEY, JSON.stringify(queue.map(tr => ({
            id: tr.dataset.songId,
            title: tr.dataset.title,
            artist: tr.dataset.artist,
        }))));
    };

    const loadState = function () {
        try {
            return JSON.parse(localStorage.getItem(STATE_KEY) || 'null');
        } catch (e) { return null; }
    };

    const fmtTime = function (sec) {
        if (!isFinite(sec) || sec < 0) return '0:00';
        const m = Math.floor(sec / 60);
        const s = Math.floor(sec % 60).toString().padStart(2, '0');
        return `${m}:${s}`;
    };

    const setPlayIcon = function (playing) {
        ui.play.innerHTML = playing ? ICONS.pause : ICONS.play;
    };
    const syncPlayIcon = () => setPlayIcon(!audio.paused && !audio.ended);
    audio.addEventListener('play', syncPlayIcon);
    audio.addEventListener('pause', syncPlayIcon);
    audio.addEventListener('pause', () => {
        try { if (window.__originalTitle) document.title = document.title.replace(/^[▶⏸]\s*/, ''); } catch (_) {}
    });
    audio.addEventListener('play', () => {
        try { document.title = document.title.replace(/^⏸\s*/, ''); } catch (_) {}
    });
    audio.addEventListener('ended', syncPlayIcon);
    audio.addEventListener('emptied', syncPlayIcon);
    audio.addEventListener('waiting', syncPlayIcon);
    audio.addEventListener('playing', syncPlayIcon);

    const updateFavIcon = function (songId) {
        if (!ui.favorite) return;
        if (Number(songId) !== Number(currentSongId)) return;
        const liked = favorites.has(Number(currentSongId));
        ui.favorite.innerHTML = liked ? ICONS.heartFill : ICONS.heart;
        ui.favorite.classList.toggle('liked', liked);
        ui.favorite.dataset.liked = liked ? '1' : '0';
    };

    const updateVolumeIcon = function () {
        if (!ui.volumeBtn) return;
        ui.volumeBtn.innerHTML = (audio.muted || audio.volume === 0) ? ICONS.volumeMute : ICONS.volume;
    };

    const updateVolumeFill = function () {
        if (!ui.volumeFill) return;
        const vol = audio.muted ? 0 : audio.volume;
        ui.volumeFill.style.width = (vol * 100) + '%';
    };

    const setMetadata = function (title, artist, songId, artistId) {
        ui.title.textContent = title || '—';
        ui.artist.textContent = artist || '';
        window.__currentTrackMeta = { title: title || '', artist: artist || '', songId: songId || null };
        if (songId && (!title || !artist)) {
            fetch('/api/song/' + Number(songId) + '/info').then(r => r.ok ? r.json() : null).then(d => {
                if (!d || d.error || Number(currentSongId) !== Number(songId)) return;
                const t = d.title || title || '';
                const a = d.artist_name || artist || '';
                if (t) ui.title.textContent = t;
                if (a) ui.artist.textContent = a;
                window.__currentTrackMeta = { title: t, artist: a, songId };
                if ('mediaSession' in navigator) {
                    try { navigator.mediaSession.metadata = new MediaMetadata({ title: t || 'Unknown', artist: a || '' }); } catch (_) {}
                }
            }).catch(() => {});
        }
        window.__currentDesktopLyrics = null;
        try { window.__abLoop?.clear?.(); } catch (_) {}
        try { window.__reversePlayback?.stop?.(); } catch (_) {}
        if (songId) {
            try { window.__applyAccentColor(songId); } catch (_) {}
            try { window.__smartEqMatch?.(songId); } catch (_) {}
            try { setTimeout(() => window.__bookmarks?.restoreOffer?.(songId), 1500); } catch (_) {}
            try { window.__perTrackVolume?.apply?.(songId); } catch (_) {}
            try {
                if (title && artist && (!window.__platform || !window.__platform.isMobile)) {
                    fetch('/api/lyrics?artist=' + encodeURIComponent(artist) + '&title=' + encodeURIComponent(title))
                        .then(r => r.ok ? r.json() : null)
                        .then(d => {
                            if (d && d.has_synced && Array.isArray(d.lines)) window.__currentDesktopLyrics = d.lines;
                        }).catch(() => {});
                }
            } catch (_) {}
            try {
                if (title && artist) {
                    const fd = new FormData();
                    fd.append('artist', artist);
                    fd.append('title', title);
                    fetch('/api/lastfm/now-playing', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
                }
            } catch (_) {}
            try {
                if (!window.__originalTitle) window.__originalTitle = document.title.replace(/^.+?\s+·\s+.+?\s+—\s+/, '');
                if (title && artist) document.title = title + ' · ' + artist + ' — ' + window.__originalTitle;
            } catch (_) {}
        }
        const artistHref = (artistId && Number(artistId) > 0)
            ? '/artist/' + Number(artistId)
            : (artist ? '/search?q=' + encodeURIComponent(artist) : '#');
        if (ui.artist.tagName === 'A') ui.artist.href = artistHref;
        if (ui.title.tagName === 'A') ui.title.href = artistHref;
        if (songId) {
            ui.cover.innerHTML = `<img src="/cover/${songId}" alt="" fetchpriority="high" decoding="async" onerror="this.remove()">`;
            try {
                const nextRow = queue[queueIndex + 1];
                const nextId = nextRow?.dataset?.songId;
                if (nextId) {
                    document.head.querySelectorAll('link[data-cover-prefetch],link[data-audio-prefetch]').forEach(el => el.remove());
                    const coverHint = document.createElement('link');
                    coverHint.rel = 'preload';
                    coverHint.as = 'image';
                    coverHint.href = '/cover/' + Number(nextId);
                    coverHint.fetchPriority = 'low';
                    coverHint.dataset.coverPrefetch = '1';
                    document.head.appendChild(coverHint);
                    const audioHint = document.createElement('link');
                    audioHint.rel = 'prefetch';
                    audioHint.as = 'audio';
                    audioHint.href = '/stream/' + Number(nextId);
                    audioHint.dataset.audioPrefetch = '1';
                    document.head.appendChild(audioHint);
                }
            } catch (_) {}
        }
        if ('mediaSession' in navigator && songId) {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: title || 'Unknown',
                artist: artist || '',
            });
        }
        updateFavIcon(songId);
    };

    const highlightRow = function (songId) {
        document.querySelectorAll('tr.playing').forEach(t => {
            t.classList.remove('playing');
            const btn = t.querySelector('.row-play-btn');
            if (btn) btn.innerHTML = ICONS.playSmall;
        });
        const row = document.querySelector(`tr[data-song-id="${songId}"]`);
        if (row) {
            row.classList.add('playing');
            const btn = row.querySelector('.row-play-btn');
            if (btn) btn.innerHTML = audio.paused ? ICONS.playSmall : ICONS.pauseSmall;
            currentRow = row;
        }
    };

    const showAudioError = (err) => {
        if (err.name === 'AbortError') return;
        const msg = `${err.name}: ${err.message}`;
        console.warn('Playback:', msg);
        if (err.name === 'NotAllowedError' || err.name === 'NotSupportedError') {
            if (window.__showToast) window.__showToast('Playback blocked: tap play to retry');
        }
    };

    const loadSong = function (songId, title, artist, autoplay = true, artistId = null) {
        try {
            currentSongId = Number(songId);
            document.body.classList.add('has-track');
            if (typeof pushRecentSeed === 'function') pushRecentSeed(songId);
            try {
                fetch('/api/track-play/' + Number(songId), { method: 'POST' }).catch(() => {});
            } catch (e) {}
            const newSrc = `${location.origin}/stream/${songId}`;
            const srcChanged = audio.src !== newSrc;
            if (srcChanged) {
                try { audio.pause(); } catch (_) {}
                audio.preload = 'auto';
                audio.src = newSrc;
                try { audio.load(); } catch(_) {}
            }
            setMetadata(title, artist, songId, artistId);
            highlightRow(songId);
            queueIndex = queue.findIndex(t => Number(t.dataset.songId) === currentSongId);
            if (autoplay) {
                const tryPlay = () => {
                    const p = audio.play();
                    if (p && typeof p.catch === 'function') p.catch(showAudioError);
                };
                if (!srcChanged || audio.readyState >= 2) {
                    tryPlay();
                } else {
                    audio.addEventListener('canplay', tryPlay, { once: true });
                    setTimeout(tryPlay, 500);
                }
            }
            if (window.__heartbeat) window.__heartbeat();
            saveState();
            if (typeof radioEnabled !== 'undefined' && radioEnabled && currentSongId) {
                setTimeout(() => {
                    try { if (typeof extendQueueWithRadio === 'function') extendQueueWithRadio(currentSongId); } catch (e) {}
                }, 800);
            }
        } catch (err) {
            document.body.classList.remove('has-track');
            console.warn('loadSong error:', err);
        }
    };

    if (!window.__hasTrackCleanupBound) {
        window.__hasTrackCleanupBound = true;
        audio.addEventListener('ended', () => {
            if (queueIndex < 0 || queueIndex >= queue.length - 1) {
                document.body.classList.remove('has-track');
            }
        });
        audio.addEventListener('error', () => {
            document.body.classList.remove('has-track');
        });
    }

    const loadFromRow = function (row, opts = {}) {
        if (opts.userPick) {
            radioSeedId = Number(row.dataset.songId);
            buildQueue();
        }
        loadSong(row.dataset.songId, row.dataset.title, row.dataset.artist, true, row.dataset.artistId);
    };

    const buildQueue = function () {
        const radioRows = queue.filter(tr => tr && tr.dataset && tr.dataset.radio === '1');
        const seen = new Set();
        queue.length = 0;
        document.querySelectorAll('tr[data-song-id]').forEach(tr => {
            const id = Number(tr.dataset.songId);
            if (!id || seen.has(id)) return;
            seen.add(id);
            queue.push(tr);
        });
        radioRows.forEach(tr => {
            const id = Number(tr.dataset.songId);
            if (!id || seen.has(id)) return;
            seen.add(id);
            queue.push(tr);
        });
    };

    audio.addEventListener('play', () => {
        setPlayIcon(true);
        if (currentRow) {
            const btn = currentRow.querySelector('.row-play-btn');
            if (btn) btn.innerHTML = ICONS.pauseSmall;
        }
        saveState();
    });
    audio.addEventListener('pause', () => {
        setPlayIcon(false);
        if (currentRow) {
            const btn = currentRow.querySelector('.row-play-btn');
            if (btn) btn.innerHTML = ICONS.playSmall;
        }
        saveState();
    });
    let radioFetching = false;
    let radioSeedId = null;
    let radioEnabled = localStorage.getItem(__k('doniix-radio')) !== '0';
    const recentPlayedSeeds = [];
    const pushRecentSeed = (sid) => {
        const id = Number(sid);
        if (!id) return;
        const idx = recentPlayedSeeds.indexOf(id);
        if (idx !== -1) recentPlayedSeeds.splice(idx, 1);
        recentPlayedSeeds.unshift(id);
        if (recentPlayedSeeds.length > 5) recentPlayedSeeds.length = 5;
    };
    const extendQueueWithRadio = async (fallbackSeedId) => {
        if (!radioEnabled) return false;
        if (!radioSeedId && fallbackSeedId) radioSeedId = Number(fallbackSeedId);
        const seedId = radioSeedId;
        if (!seedId) return false;
        if (radioFetching) return false;
        radioFetching = true;
        try {
            const seeds = recentPlayedSeeds.length > 0 ? [...recentPlayedSeeds] : [seedId];
            if (!seeds.includes(seedId)) seeds.unshift(seedId);
            const excludeIds = queue.map(t => Number(t.dataset.songId)).filter(Boolean);
            const r = await fetch('/api/smart-queue/extend', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ seeds: seeds.slice(0, 5), exclude: excludeIds })
            });
            if (!r.ok) {
                const fn = window.__logClientError;
                if (fn) fn('radio', 'Error with radio extend (HTTP ' + r.status + ')', 'seeds=' + seeds.join(','));
                return false;
            }
            const tracks = await r.json();
            if (!Array.isArray(tracks) || tracks.length === 0) {
                return false;
            }
            const existing = new Set(queue.map(t => Number(t.dataset.songId)));
            let added = 0;
            tracks.forEach(t => {
                const id = Number(t.id);
                if (existing.has(id)) return;
                const fake = document.createElement('tr');
                fake.dataset.songId = id;
                fake.dataset.title = t.title || '—';
                fake.dataset.artist = t.artist_name || '';
                fake.dataset.radio = '1';
                queue.push(fake);
                existing.add(id);
                added++;
            });
            if (added > 0) {
                if (typeof sidePanelMode !== 'undefined' && sidePanelMode === 'queue' && typeof renderQueue === 'function') {
                    try { renderQueue(); } catch (e) {}
                }
                try {
                    const npq = document.getElementById('np-view-queue');
                    if (npq && npq.style.display !== 'none' && typeof renderQueueOverlay === 'function') renderQueueOverlay();
                } catch (_) {}
            }
            return added > 0;
        } catch (e) {
            console.error('[radio] fail', e);
            return false;
        } finally {
            radioFetching = false;
        }
    };

    const playNext = async function () {
        if (repeatMode === 2) {
            audio.currentTime = 0;
            audio.play();
            return;
        }
        if ((shuffleOn || playMode === 'smart') && queue.length > 1) {
            const pool = queue.map((_, i) => i).filter(i => i !== queueIndex);
            const idx = pool[Math.floor(Math.random() * pool.length)];
            loadFromRow(queue[idx]);
            return;
        }
        if (queueIndex + 1 < queue.length) {
            loadFromRow(queue[queueIndex + 1]);
            if (queue.length - queueIndex < 4 && currentSongId) extendQueueWithRadio(currentSongId);
            return;
        }
        if (repeatMode === 1 && queue.length > 0) {
            loadFromRow(queue[0]);
            return;
        }
        if (currentSongId) {
            const ok = await extendQueueWithRadio(currentSongId);
            if (ok && queueIndex + 1 < queue.length) {
                loadFromRow(queue[queueIndex + 1]);
                return;
            }
        }
        setPlayIcon(false);
        saveState();
    };

    audio.addEventListener('ended', playNext);
    audio.addEventListener('loadedmetadata', () => {
        ui.timeTotal.textContent = fmtTime(audio.duration);
        if (pendingResume !== null && (pendingResumeSongId === null || pendingResumeSongId === currentSongId)) {
            try { audio.currentTime = pendingResume; } catch (_) {}
            pendingResume = null;
            pendingResumeSongId = null;
        }
    });
    audio.addEventListener('timeupdate', () => {
        if (!audio.duration) return;
        const pct = (audio.currentTime / audio.duration) * 100;
        ui.seekFill.style.width = pct + '%';
        document.querySelector('.player-bar')?.style.setProperty('--pb-progress', pct + '%');
        ui.timeCurrent.textContent = fmtTime(audio.currentTime);
        if (Math.floor(audio.currentTime) !== Math.floor(audio.__lastSaved || -1)) {
            audio.__lastSaved = audio.currentTime;
            saveState();
        }
        if (!audio.__scrobbled && currentSongId) {
            const halfway = audio.currentTime >= audio.duration * 0.5;
            const minPlayed = audio.currentTime >= 30;
            if ((halfway || minPlayed) && audio.duration >= 30) {
                audio.__scrobbled = true;
                const sid = currentSongId;
                const meta = window.__currentTrackMeta || {};
                fetch('/api/track-play/' + Number(sid), { method: 'POST', keepalive: true }).catch(() => {
                    if (window.__queueRetry) window.__queueRetry('/api/track-play/' + Number(sid), 'POST');
                });
                if (meta.title && meta.artist) {
                    const fd = new FormData();
                    fd.append('artist', meta.artist);
                    fd.append('title', meta.title);
                    fd.append('timestamp', String(Math.floor(Date.now() / 1000) - Math.floor(audio.currentTime)));
                    fetch('/api/lastfm/scrobble', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
                }
            }
        }
    });
    audio.addEventListener('loadstart', () => { audio.__scrobbled = false; });
    audio.addEventListener('emptied', () => { audio.__scrobbled = false; });
    audio.addEventListener('volumechange', () => {
        updateVolumeIcon();
        updateVolumeFill();
        if (!audio.muted && audio.volume > 0) lastVolume = audio.volume;
        saveState();
    });

    ui.play.addEventListener('click', async () => {
        // If another device is currently playing, this click controls it remotely:
        // - pause icon (looks playing): send remote pause to other device
        // - play icon (looks paused): take over playback to this device
        if (otherDeviceState && audio.paused) {
            try {
                audio.muted = true;
                await audio.play().catch(() => {});
            } catch (e) {}
            try {
                const songId = otherDeviceState.song_id;
                const title = otherDeviceState.song_title || '';
                const artist = otherDeviceState.song_artist || '';
                if (songId) {
                    audio.muted = false;
                    loadSong(songId, title, artist, true);
                    const targetPos = Number(otherDeviceState.position) || 0;
                    if (targetPos > 0) {
                        const seekWhenReady = () => { try { audio.currentTime = targetPos; } catch (e) {} };
                        if (audio.readyState >= 1) seekWhenReady();
                        else audio.addEventListener('loadedmetadata', seekWhenReady, { once: true });
                    }
                    // Tell other device to pause
                    fetch('/api/devices/control', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'pause', device_id: deviceId }),
                    }).catch(() => {});
                    otherDeviceState = null;
                    if (window.__heartbeat) window.__heartbeat();
                }
            } catch (e) { audio.muted = false; }
            return;
        }

        if (!audio.src) {
            if (currentSongId) {
                loadSong(currentSongId, ui.title?.textContent || '', (ui.artist?.textContent || '').split('·')[0].trim(), true);
                return;
            }
            buildQueue();
            if (queue.length > 0) loadFromRow(queue[0], { userPick: true });
            return;
        }
        if (audio.paused) {
            if (audio.readyState < 2) audio.load();
            const p = audio.play();
            if (p && typeof p.catch === 'function') p.catch(showAudioError);
        } else {
            audio.pause();
        }
    });

    window.__playbackHistory = window.__playbackHistory || [];
    const pushHistoryIfNew = () => {
        const last = window.__playbackHistory[window.__playbackHistory.length - 1];
        if (currentSongId && (!last || last !== currentSongId)) {
            window.__playbackHistory.push(currentSongId);
            if (window.__playbackHistory.length > 50) window.__playbackHistory.shift();
        }
    };
    ui.prev?.addEventListener('click', () => {
        if (audio.currentTime > 3) {
            audio.currentTime = 0;
            return;
        }
        if (window.__playbackHistory.length > 1) {
            window.__playbackHistory.pop();
            const prevId = window.__playbackHistory[window.__playbackHistory.length - 1];
            const histRow = queue.find(r => Number(r?.dataset?.songId) === Number(prevId)) ||
                document.querySelector(`tr[data-song-id="${Number(prevId)}"]`);
            if (histRow) {
                window.__playbackHistory.pop();
                loadFromRow(histRow, { userPick: true });
                return;
            }
        }
        if (queueIndex > 0) loadFromRow(queue[queueIndex - 1]);
    });
    const isEffectiveShuffle = () => shuffleOn || playMode === 'smart';
    ui.next?.addEventListener('click', async () => {
        if (currentSongId && audio.duration > 0 && audio.currentTime < audio.duration * 0.3) {
            try {
                const fd = new FormData();
                fd.append('song_id', String(currentSongId));
                fd.append('played_sec', String(Math.floor(audio.currentTime)));
                fetch('/api/track-skip', { method: 'POST', body: fd, keepalive: true }).catch(() => {});
            } catch (_) {}
        }
        pushHistoryIfNew();
        const skipRecent = (() => { try { return localStorage.getItem('doniix-shuffle-skip-recent') === '1'; } catch (_) { return false; } })();
        if (isEffectiveShuffle() && queue.length > 1 && skipRecent) {
            const recentHistory = new Set((window.__playbackHistory || []).slice(-10).map(Number));
            const pool = queue.map((_, i) => i).filter(i => i !== queueIndex && !recentHistory.has(Number(queue[i]?.dataset?.songId)));
            if (pool.length > 0) {
                const idx = pool[Math.floor(Math.random() * pool.length)];
                loadFromRow(queue[idx]);
                if (queue.length - queueIndex < 4 && currentSongId) extendQueueWithRadio(currentSongId);
                return;
            }
        }
        if (isEffectiveShuffle() && queue.length > 1) {
            const pool = queue.map((_, i) => i).filter(i => i !== queueIndex);
            const idx = pool[Math.floor(Math.random() * pool.length)];
            loadFromRow(queue[idx]);
            if (queue.length - queueIndex < 4 && currentSongId) extendQueueWithRadio(currentSongId);
        } else if (queueIndex + 1 < queue.length) {
            loadFromRow(queue[queueIndex + 1]);
            if (queue.length - queueIndex < 4 && currentSongId) extendQueueWithRadio(currentSongId);
        } else if (currentSongId) {
            const ok = await extendQueueWithRadio(currentSongId);
            if (ok && queueIndex + 1 < queue.length) { loadFromRow(queue[queueIndex + 1]); return; }
            try {
                const r = await fetch('/api/surprise');
                const d = await r.json();
                if (d && d.id) loadSong(Number(d.id), d.title || '', d.artist_name || '', true, d.artist_id || null);
            } catch (_) {}
        } else {
            try {
                const r = await fetch('/api/surprise');
                const d = await r.json();
                if (d && d.id) loadSong(Number(d.id), d.title || '', d.artist_name || '', true, d.artist_id || null);
            } catch (_) {}
        }
    });

    const shuffleBtn = document.getElementById('pb-shuffle');
    let playMode = localStorage.getItem(__k('doniix-playmode')) || (radioEnabled ? 'smart' : 'off');
    if (!['off','shuffle','smart'].includes(playMode)) playMode = 'off';
    shuffleOn = playMode === 'shuffle';
    radioEnabled = playMode === 'smart';

    const refreshRadioBtn = () => {
        if (!shuffleBtn) return;
        shuffleBtn.classList.toggle('active', playMode !== 'off');
        if (playMode === 'off') {
            shuffleBtn.innerHTML = ICONS.shuffle;
            shuffleBtn.title = 'Linear playback — click: shuffle';
        } else if (playMode === 'shuffle') {
            shuffleBtn.innerHTML = ICONS.shuffle;
            shuffleBtn.title = 'Shuffle mode — click: Smart queue';
        } else {
            shuffleBtn.innerHTML = ICONS.smart;
            shuffleBtn.title = 'Smart queue — click: off';
        }
    };
    refreshRadioBtn();
    shuffleBtn?.addEventListener('click', async () => {
        playMode = playMode === 'off' ? 'shuffle' : playMode === 'shuffle' ? 'smart' : 'off';
        shuffleOn = playMode === 'shuffle';
        radioEnabled = playMode === 'smart';
        localStorage.setItem(__k('doniix-playmode'), playMode);
        localStorage.setItem(__k('doniix-radio'), radioEnabled ? '1' : '0');
        refreshRadioBtn();
        if (playMode === 'off') {
            for (let i = queue.length - 1; i > queueIndex; i--) {
                if (queue[i] && queue[i].dataset && queue[i].dataset.radio === '1') {
                    queue.splice(i, 1);
                }
            }
            showToast('Linear playback');
        } else if (playMode === 'shuffle') {
            showToast('Shuffle mode');
        } else if (playMode === 'smart' && currentSongId) {
            radioSeedId = radioSeedId || Number(currentSongId);
            shuffleBtn.style.opacity = '0.5';
            try {
                const ok = await extendQueueWithRadio(currentSongId);
                if (ok) showToast('Smart queue');
            } finally { shuffleBtn.style.opacity = '1'; }
        }
    });

    const repeatBtn = document.getElementById('pb-repeat');
    const REPEAT_ICONS = {
        all: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg>',
        one: '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/><path d="M11 10h1v4"/></svg>',
    };
    repeatBtn?.addEventListener('click', () => {
        repeatMode = (repeatMode + 1) % 3;
        repeatBtn.classList.toggle('active', repeatMode > 0);
        repeatBtn.innerHTML = repeatMode === 2 ? REPEAT_ICONS.one : REPEAT_ICONS.all;
        repeatBtn.title = ['Repeat: off', 'Repeat all', 'Repeat one'][repeatMode];
        localStorage.setItem(__k('doniix-repeat'), String(repeatMode));
    });

    ui.seekBar.addEventListener('click', (e) => {
        if (!audio.duration) return;
        const rect = ui.seekBar.getBoundingClientRect();
        const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        audio.currentTime = audio.duration * pct;
    });

    // ===== VOLUME =====
    let draggingVolume = false;
    const setVolumeFromEvent = (e) => {
        const rect = ui.volumeBar.getBoundingClientRect();
        const pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        audio.volume = pct;
        audio.muted = pct === 0;
    };
    ui.volumeBar?.addEventListener('mousedown', (e) => {
        draggingVolume = true;
        setVolumeFromEvent(e);
        e.preventDefault();
    });
    document.addEventListener('mousemove', (e) => {
        if (draggingVolume) setVolumeFromEvent(e);
    });
    document.addEventListener('mouseup', () => { draggingVolume = false; });

    ui.volumeBtn?.addEventListener('click', () => {
        if (audio.muted || audio.volume === 0) {
            audio.muted = false;
            audio.volume = lastVolume || 0.8;
        } else {
            audio.muted = true;
        }
    });

    // ===== FAVORITES =====
    ui.favorite?.addEventListener('click', () => {
        if (!currentSongId) return;
        toggleFavorite(currentSongId);
    });

    const smartBtn = document.getElementById('pb-smart');
    smartBtn?.addEventListener('click', async () => {
        if (!currentSongId) return;
        smartBtn.style.opacity = '0.5';
        try {
            const res = await fetch(`/api/smart-queue/${currentSongId}`);
            const data = await res.json();
            if (Array.isArray(data) && data.length > 0) {
                queue.length = 0;
                const seedRow = document.querySelector(`tr[data-song-id="${currentSongId}"]`);
                if (seedRow) queue.push(seedRow);
                data.forEach(t => {
                    const fake = document.createElement('tr');
                    fake.dataset.songId = t.id;
                    fake.dataset.title = t.title;
                    fake.dataset.artist = t.artist_name || '';
                    queue.push(fake);
                });
                queueIndex = 0;
                smartBtn.classList.add('active');
                smartBtn.title = `Queue: ${data.length} similar tracks`;
                setTimeout(() => smartBtn.classList.remove('active'), 2000);
            }
        } catch (e) { console.error(e); }
        finally { smartBtn.style.opacity = '1'; }
    });

    const toggleFavorite = function (songId) {
        const id = Number(songId);
        const wasLiked = favorites.has(id);
        if (wasLiked) favorites.delete(id);
        else favorites.add(id);
        saveFavs();
        updateFavIcon(id);
        document.querySelectorAll(`.row-fav-btn[data-song-id="${id}"]`).forEach(btn => {
            btn.classList.toggle('active', !wasLiked);
        });
        fetch(`/api/favorite/${id}/toggle`, { method: 'POST' }).then(r => r.ok ? r.json() : null).then(data => {
            if (!data) return;
            const serverLiked = !!data.starred;
            if (serverLiked) favorites.add(id); else favorites.delete(id);
            saveFavs();
            updateFavIcon(id);
            document.querySelectorAll(`.row-fav-btn[data-song-id="${id}"]`).forEach(btn => {
                btn.classList.toggle('active', serverLiked);
            });
            if (!serverLiked && location.pathname === '/favorites') {
                document.querySelectorAll(`tr[data-song-id="${id}"]`).forEach(tr => tr.remove());
                const cnt = document.querySelectorAll('.song-table tbody tr').length;
                const sub = document.querySelector('.page-subtitle');
                if (sub) sub.textContent = cnt + ' song' + (cnt === 1 ? '' : 's');
                if (cnt === 0) {
                    const table = document.querySelector('.song-table');
                    if (table) {
                        const empty = document.createElement('div');
                        empty.className = 'empty-state';
                        empty.innerHTML = '<h2>No liked songs</h2><p>Tap heart on any track.</p>';
                        table.replaceWith(empty);
                    }
                }
            }
        }).catch(() => {});
    };

    // ===== KEYBOARD =====
    const showShortcutsCheatsheet = () => {
        if (document.getElementById('shortcuts-cheatsheet')) return;
        const shortcuts = [
            ['Space', 'Play / Pause'],
            ['Shift + →', 'Next track'],
            ['Shift + ←', 'Previous track (back stack)'],
            ['L', 'Toggle fullscreen lyrics'],
            ['T', 'Translate lyrics to system language'],
            ['K', 'Karaoke mode (vocal removal)'],
            ['C', 'Concert mode (fullscreen)'],
            ['D', 'Drive mode (huge UI + screen wake)'],
            ['P', 'Mini player (Picture-in-Picture)'],
            ['X', 'Speed picker popup'],
            ['+ / -', 'Playback speed ±0.1x'],
            ['0', 'Reset speed to 1.0x'],
            ['B', 'Set A-B loop point (press 2x to loop)'],
            ['Shift + B', 'Bookmark current position'],
            ['R', 'Reverse playback'],
            ['V', 'Vibe check quiz (mood→playlist)'],
            ['Shift + Space', 'Surprise me (random track)'],
            ['/', 'Focus search input'],
            ['Shift + S', 'Sleep timer (prompt for minutes)'],
            ['Esc', 'Close overlay / exit concert'],
            ['?', 'Show this cheatsheet'],
        ];
        const html = '<div class="cs-backdrop"></div><div class="cs-panel">' +
            '<h3>Keyboard shortcuts</h3>' +
            '<table>' + shortcuts.map(([k, d]) => '<tr><td><kbd>' + k + '</kbd></td><td>' + d + '</td></tr>').join('') + '</table>' +
            '<button class="cs-close">Close</button></div>';
        const wrap = document.createElement('div');
        wrap.id = 'shortcuts-cheatsheet';
        wrap.innerHTML = html;
        wrap.style.cssText = 'position:fixed;inset:0;z-index:99000;display:flex;align-items:center;justify-content:center;font-family:Inter,system-ui,sans-serif;';
        document.body.appendChild(wrap);
        wrap.querySelector('.cs-backdrop').style.cssText = 'position:absolute;inset:0;background:rgba(0,0,0,0.6);backdrop-filter:blur(8px);';
        const panel = wrap.querySelector('.cs-panel');
        panel.style.cssText = 'position:relative;background:#16181c;border:1px solid #2f3336;border-radius:14px;padding:24px 28px;color:#e7e9ea;min-width:300px;max-width:90vw;box-shadow:0 16px 48px rgba(0,0,0,0.6);';
        panel.querySelector('h3').style.cssText = 'margin:0 0 16px;font-size:18px;font-weight:700;';
        const table = panel.querySelector('table');
        table.style.cssText = 'width:100%;border-collapse:collapse;font-size:14px;';
        table.querySelectorAll('td').forEach(td => td.style.cssText = 'padding:6px 0;color:#b3b3b3;');
        table.querySelectorAll('td:first-child').forEach(td => td.style.cssText = 'padding:6px 16px 6px 0;width:1px;white-space:nowrap;');
        table.querySelectorAll('kbd').forEach(kb => kb.style.cssText = 'background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.12);border-radius:6px;padding:3px 8px;font-family:JetBrains Mono,monospace;font-size:12px;color:#fff;');
        const btn = panel.querySelector('.cs-close');
        btn.style.cssText = 'margin-top:16px;background:#e7e9ea;color:#000;border:0;padding:8px 20px;border-radius:999px;cursor:pointer;font-weight:600;font-size:13px;';
        const close = () => wrap.remove();
        btn.addEventListener('click', close);
        wrap.querySelector('.cs-backdrop').addEventListener('click', close);
    };
    window.__showShortcutsCheatsheet = showShortcutsCheatsheet;
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.code === 'Space' && !e.shiftKey) { e.preventDefault(); ui.play.click(); }
        if (e.code === 'ArrowRight' && e.shiftKey) { e.preventDefault(); ui.next?.click(); }
        if (e.code === 'ArrowLeft' && e.shiftKey) { e.preventDefault(); ui.prev?.click(); }
        if (e.key === '?' || (e.shiftKey && e.code === 'Slash')) {
            const cs = document.getElementById('shortcuts-cheatsheet');
            if (cs) cs.remove();
            else showShortcutsCheatsheet();
        }
        if (e.key === 'c' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__concertMode) window.__concertMode.toggle();
        }
        if (e.key === 't' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__translateLyrics) window.__translateLyrics();
        }
        if (e.key === 'p' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__miniPlayerPiP) window.__miniPlayerPiP.open();
        }
        if (e.key === 'k' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__vocalCancel) window.__vocalCancel.toggle();
        }
        if (e.key === 'd' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__driveMode) window.__driveMode.toggle();
        }
        if (e.key === 'x' && !e.metaKey && !e.ctrlKey && !e.altKey) {
            if (window.__speedPicker) window.__speedPicker.open(document.getElementById('pb-play'));
        }
        if (e.key === 'r' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            if (window.__reversePlayback) window.__reversePlayback.toggle();
        }
        if (e.key === 'b' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            if (window.__abLoop) window.__abLoop.setPoint();
        }
        if (e.key === 'B' && e.shiftKey) {
            e.preventDefault();
            if (window.__bookmarks) window.__bookmarks.save();
        }
        if (e.key === 'v' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            if (window.__vibeQuiz) window.__vibeQuiz.open();
        }
        if (e.key === ' ' && e.shiftKey) {
            e.preventDefault();
            if (window.__surpriseMe) window.__surpriseMe();
        }
        if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            const searchInput = document.querySelector('#home-search, #search-input-main, #settings-search, .search-input');
            if (searchInput) { e.preventDefault(); searchInput.focus(); searchInput.select?.(); }
        }
        if ((e.key === '+' || e.key === '=') && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            const a = document.querySelector('audio');
            if (a) {
                a.playbackRate = Math.min(2, Math.round((a.playbackRate + 0.1) * 10) / 10);
                if (window.__showToast) window.__showToast('Speed ' + a.playbackRate.toFixed(1) + 'x');
                try { localStorage.setItem('doniix-playback-rate', String(a.playbackRate)); } catch (_) {}
            }
        }
        if (e.key === '-' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            const a = document.querySelector('audio');
            if (a) {
                a.playbackRate = Math.max(0.5, Math.round((a.playbackRate - 0.1) * 10) / 10);
                if (window.__showToast) window.__showToast('Speed ' + a.playbackRate.toFixed(1) + 'x');
                try { localStorage.setItem('doniix-playback-rate', String(a.playbackRate)); } catch (_) {}
            }
        }
        if (e.key === '0' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.shiftKey) {
            const a = document.querySelector('audio');
            if (a) {
                a.playbackRate = 1.0;
                if (window.__showToast) window.__showToast('Speed reset 1.0x');
                try { localStorage.removeItem('doniix-playback-rate'); } catch (_) {}
            }
        }
        if (e.key === 's' && e.shiftKey) {
            e.preventDefault();
            if (window.__sleepTimer) {
                const min = parseInt(prompt('Sleep timer minutes (15-180):', '30'), 10);
                if (min >= 1 && min <= 180 && window.__sleepTimer.set(min)) {
                    if (window.__showToast) window.__showToast('Sleep timer: ' + min + ' min');
                }
            }
        }
    });

    // ===== RESTORE STATE =====
    loadFavs();
    localStorage.removeItem('doniix-shuffle');
    const savedRepeat = parseInt(localStorage.getItem(__k('doniix-repeat')) || '0', 10);
    if (savedRepeat > 0 && repeatBtn) {
        repeatMode = savedRepeat;
        repeatBtn.classList.add('active');
        repeatBtn.innerHTML = repeatMode === 2 ? REPEAT_ICONS.one : REPEAT_ICONS.all;
    }
    const state = loadState();
    if ((!state || !state.songId) && !currentSongId) {
        (async () => {
            try {
                const r = await fetch('/api/me/last-played');
                if (!r.ok) return;
                const last = await r.json();
                if (!last || !last.id || currentSongId) return;
                currentSongId = Number(last.id);
                const title = (last.title || '—');
                const artist = (last.artist_name || '');
                setMetadata(title, artist, last.id);
                pendingResume = 0;
            } catch (_) {}
        })();
    }
    if (state && state.songId) {
        audio.volume = state.volume ?? 0.8;
        audio.muted = !!state.muted;
        updateVolumeIcon();
        updateVolumeFill();

        let queuedRows = [];
        try { queuedRows = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); } catch (e) {}
        if (queuedRows.length > 0 && queue.length === 0) {
            queue.push(...queuedRows.map(r => {
                const fake = document.createElement('tr');
                fake.dataset.songId = r.id;
                fake.dataset.title = r.title;
                fake.dataset.artist = r.artist;
                return fake;
            }));
        }

        const row = document.querySelector(`tr[data-song-id="${state.songId}"]`);
        const title = row?.dataset.title || queuedRows.find(r => r.id == state.songId)?.title || '—';
        const artist = row?.dataset.artist || queuedRows.find(r => r.id == state.songId)?.artist || '';

        currentSongId = Number(state.songId);
        pendingResume = Number(state.time) || 0;
        pendingResumeSongId = Number(state.songId);
        setMetadata(title, artist, state.songId);
        highlightRow(state.songId);
        queueIndex = queue.findIndex(t => Number(t.dataset.songId) === currentSongId);
        setPlayIcon(false);
        try {
            audio.src = `${location.origin}/stream/${state.songId}`;
            audio.preload = 'auto';
            audio.load();
        } catch (_) {}
        const resumeSec = Number(state.time) || 0;
        if (ui.seekFill) ui.seekFill.style.width = '0%';
        if (ui.timeCurrent) ui.timeCurrent.textContent = fmtTime(resumeSec);
        const timeTotal = document.getElementById('pb-time-total');
        if (timeTotal) timeTotal.textContent = '0:00';
    } else {
        audio.volume = 0.8;
        updateVolumeIcon();
        updateVolumeFill();
        if (ui.seekFill) ui.seekFill.style.width = '0%';
        if (ui.timeCurrent) ui.timeCurrent.textContent = '0:00';
    }

    if ('mediaSession' in navigator) {
        navigator.mediaSession.setActionHandler('play', () => audio.play());
        navigator.mediaSession.setActionHandler('pause', () => audio.pause());
        navigator.mediaSession.setActionHandler('previoustrack', () => ui.prev?.click());
        navigator.mediaSession.setActionHandler('nexttrack', () => ui.next?.click());
        try {
            navigator.mediaSession.setActionHandler('seekbackward', (d) => {
                const skip = d.seekOffset || 10;
                audio.currentTime = Math.max(0, audio.currentTime - skip);
            });
            navigator.mediaSession.setActionHandler('seekforward', (d) => {
                const skip = d.seekOffset || 10;
                audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + skip);
            });
            navigator.mediaSession.setActionHandler('seekto', (d) => {
                if (d.seekTime == null) return;
                if (d.fastSeek && typeof audio.fastSeek === 'function') {
                    audio.fastSeek(d.seekTime);
                } else {
                    audio.currentTime = d.seekTime;
                }
            });
            navigator.mediaSession.setActionHandler('stop', () => { audio.pause(); audio.currentTime = 0; });
        } catch (_) {}

        audio.addEventListener('play', () => { navigator.mediaSession.playbackState = 'playing'; });
        audio.addEventListener('pause', () => { navigator.mediaSession.playbackState = 'paused'; });
        audio.addEventListener('ended', () => { navigator.mediaSession.playbackState = 'none'; });

        const updatePositionState = () => {
            try {
                if (audio.duration && isFinite(audio.duration)) {
                    navigator.mediaSession.setPositionState({
                        duration: audio.duration,
                        position: Math.min(audio.currentTime, audio.duration),
                        playbackRate: audio.playbackRate || 1
                    });
                }
            } catch (_) {}
        };
        audio.addEventListener('timeupdate', updatePositionState);
        audio.addEventListener('loadedmetadata', updatePositionState);
        audio.addEventListener('ratechange', updatePositionState);
    }

    window.addEventListener('beforeunload', saveState);

    // ===== CUSTOM DIALOGS (replace native alert/confirm/prompt) =====
    const buildDialog = (opts) => new Promise(resolve => {
        const root = document.createElement('div');
        root.className = 'dlg-backdrop';
        const isPrompt = opts.kind === 'prompt';
        const showCancel = opts.kind !== 'alert';
        root.innerHTML = `
            <div class="dlg-card">
                ${opts.title ? `<div class="dlg-title">${escapeAttr(opts.title)}</div>` : ''}
                ${opts.message ? `<div class="dlg-msg">${escapeAttr(opts.message)}</div>` : ''}
                ${isPrompt ? `<input type="text" class="dlg-input" value="${escapeAttr(opts.defaultValue || '')}" placeholder="${escapeAttr(opts.placeholder || '')}">` : ''}
                <div class="dlg-actions">
                    ${showCancel ? `<button class="btn btn-ghost dlg-cancel">${escapeAttr(opts.cancelText || 'Cancel')}</button>` : ''}
                    <button class="btn ${opts.danger ? 'dlg-danger' : ''} dlg-ok">${escapeAttr(opts.okText || 'OK')}</button>
                </div>
            </div>`;
        document.body.appendChild(root);
        requestAnimationFrame(() => root.classList.add('open'));
        const input = root.querySelector('.dlg-input');
        if (input) { input.focus(); input.select(); }
        const close = (val) => {
            root.classList.remove('open');
            setTimeout(() => root.remove(), 180);
            resolve(val);
        };
        root.querySelector('.dlg-ok').addEventListener('click', () => close(isPrompt ? (input?.value ?? '').trim() : true));
        root.querySelector('.dlg-cancel')?.addEventListener('click', () => close(isPrompt ? null : false));
        root.addEventListener('click', (e) => { if (e.target === root) close(isPrompt ? null : false); });
        root.querySelector('.dlg-card').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && isPrompt) { e.preventDefault(); close((input?.value ?? '').trim()); }
            if (e.key === 'Escape') close(isPrompt ? null : false);
        });
        if (input) input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); close((input.value ?? '').trim()); }
            if (e.key === 'Escape') close(null);
        });
    });
    const dialogAlert = (msg, title) => buildDialog({ kind: 'alert', message: msg, title, okText: 'OK' });
    const dialogConfirm = (msg, opts = {}) => buildDialog({ kind: 'confirm', message: msg, title: opts.title, okText: opts.okText || 'OK', cancelText: opts.cancelText || 'Cancel', danger: opts.danger });
    const dialogPrompt = (msg, opts = {}) => buildDialog({ kind: 'prompt', message: msg, title: opts.title, defaultValue: opts.defaultValue, placeholder: opts.placeholder, okText: opts.okText || 'OK', cancelText: opts.cancelText || 'Cancel' });
    window.__dialogAlert = dialogAlert;
    window.__dialogConfirm = dialogConfirm;
    window.__dialogPrompt = dialogPrompt;

    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('form[data-confirm]');
        if (!form || form.dataset.confirmed === '1') return;
        e.preventDefault();
        const ok = await dialogConfirm(form.dataset.confirm, {
            title: form.dataset.confirmTitle || 'Confirm',
            okText: form.dataset.confirmOk || 'OK',
            danger: form.dataset.confirmDanger === '1',
        });
        if (ok) { form.dataset.confirmed = '1'; form.submit(); }
    }, true);

    // ===== SIDE PANEL + MODAL HELPERS =====
    const sidePanel = document.getElementById('side-panel');
    const sidePanelBody = document.getElementById('side-panel-body');
    const sidePanelTitle = document.getElementById('side-panel-title');
    const sidePanelClose = document.getElementById('side-panel-close');
    let sidePanelMode = null;
    let sidePanelTimer = null;
    const SIDEPANEL_KEY = __k('doniix-sidepanel-mode');

    const closeSidePanel = () => {
        const wasOpen = sidePanel?.classList.contains('open');
        sidePanel?.classList.remove('open');
        sidePanelMode = null;
        if (sidePanelTimer) { clearInterval(sidePanelTimer); sidePanelTimer = null; }
        try { localStorage.removeItem(SIDEPANEL_KEY); } catch (_) {}
        try {
            const app = document.querySelector('.app');
            if (app && !app.classList.contains('now-playing-open')) {
                document.querySelectorAll('.np-view').forEach(v => v.style.display = v.dataset.npView === 'info' ? '' : 'none');
                const t = document.getElementById('np-head-title'); if (t) t.textContent = 'Now Playing';
                const x = document.getElementById('np-close-view'); if (x) x.style.display = 'none';
                app.classList.add('now-playing-open');
            }
        } catch (_) {}
        try {
            if (wasOpen && history.state && history.state.__sidePanel) {
                window.__sidePanelClosingViaHistory = true;
                history.back();
            }
        } catch (_) {}
    };
    sidePanelClose?.addEventListener('click', closeSidePanel);

    const openSidePanel = (mode, title, renderFn) => {
        if (sidePanelMode === mode) { closeSidePanel(); return; }
        if (sidePanelTimer) { clearInterval(sidePanelTimer); sidePanelTimer = null; }
        sidePanelMode = mode;
        sidePanelTitle.textContent = title;
        sidePanel.classList.add('open');
        try { localStorage.setItem(SIDEPANEL_KEY, mode); } catch (_) {}
        try {
            const cur = history.state || {};
            if (!cur.__sidePanel && window.__platform.isMobile) {
                history.pushState({ ...cur, __sidePanel: 1 }, '', location.pathname + location.search + '#panel-' + mode);
            }
        } catch (_) {}
        renderFn();
    };
    window.__closeSidePanel = closeSidePanel;

    const __applyToastContainerStyle = (c) => {
        const isMobile = window.innerWidth <= 720;
        const bottomOffset = isMobile
            ? 'calc(var(--tabbar-h, 60px) + var(--mini-player-h, 64px) + env(safe-area-inset-bottom, 0px) + 12px)'
            : '110px';
        c.style.cssText = isMobile
            ? 'position:fixed;left:12px;right:12px;bottom:' + bottomOffset + ';z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;'
            : 'position:fixed;left:50%;transform:translateX(-50%);bottom:' + bottomOffset + ';z-index:99999;display:flex;flex-direction:column;gap:8px;align-items:center;pointer-events:none;';
    };
    const __ensureToastContainer = () => {
        let c = document.getElementById('toast-stack');
        if (!c) {
            c = document.createElement('div');
            c.id = 'toast-stack';
            __applyToastContainerStyle(c);
            document.body.appendChild(c);
            window.addEventListener('resize', () => __applyToastContainerStyle(c), { passive: true });
        }
        return c;
    };
    const showToast = (msg, opts) => {
        const container = __ensureToastContainer();
        const t = document.createElement('div');
        t.className = 'toast';
        t.textContent = msg;
        const isMobile = window.innerWidth <= 720;
        t.style.cssText = 'pointer-events:auto;background:#16181c;color:#e7e9ea;display:flex;align-items:center;border:1px solid #2f3336;box-shadow:0 16px 48px rgba(0,0,0,0.6);font-family:Inter,system-ui,sans-serif;font-weight:600;animation:doniixSlide 0.3s ease-out;box-sizing:border-box;' + (isMobile
            ? 'padding:10px 12px;font-size:13px;border-radius:12px;width:auto;max-width:none;'
            : 'padding:12px 18px;font-size:14px;border-radius:14px;max-width:380px;');
        container.appendChild(t);
        const dur = (opts && opts.duration) || 2500;
        setTimeout(() => {
            t.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            t.style.opacity = '0';
            t.style.transform = 'translateY(-8px)';
            setTimeout(() => t.remove(), 320);
        }, dur);
        while (container.children.length > 4) container.removeChild(container.firstChild);
    };
    window.__showToast = showToast;

    const modalBackdrop = document.getElementById('modal-backdrop');
    const jamModal = document.getElementById('jam-modal');
    const jamModalBody = document.getElementById('jam-modal-body');
    const importModal = document.getElementById('import-modal');
    const importModalBody = document.getElementById('import-modal-body');
    const closeModal = () => {
        modalBackdrop?.classList.remove('open');
        jamModal?.classList.remove('open');
        importModal?.classList.remove('open');
    };
    modalBackdrop?.addEventListener('click', closeModal);
    document.querySelectorAll('[data-modal-close]').forEach(b => b.addEventListener('click', closeModal));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { closeModal(); closeSidePanel(); } });

    // ===== QUEUE PANEL =====
    const queueBtn = document.getElementById('pb-queue-toggle');
    const renderQueue = () => {
        if (!queue.length) {
            sidePanelBody.innerHTML = '<div class="lyrics-empty">Queue is empty. Click a track to start.</div>';
            return;
        }
        const cur = queueIndex;
        let html = '';
        if (cur >= 0 && cur < queue.length && queue[cur]) {
            html += '<div class="queue-section-label">Now playing</div>';
            html += renderQueueItem(queue[cur], true);
            if (cur + 1 < queue.length) {
                html += '<div class="queue-section-label">Up next</div>';
                for (let i = cur + 1; i < queue.length; i++) {
                    if (queue[i]) html += renderQueueItem(queue[i], false);
                }
            }
        } else {
            html += '<div class="queue-section-label">Queue</div>';
            queue.forEach(tr => { if (tr) html += renderQueueItem(tr, false); });
        }
        sidePanelBody.innerHTML = html;
        sidePanelBody.querySelectorAll('.queue-item').forEach(el => {
            el.addEventListener('click', () => {
                const idx = Number(el.dataset.idx);
                if (!Number.isFinite(idx) || !queue[idx]) return;
                loadFromRow(queue[idx]);
                renderQueue();
            });
        });
    };
    const renderQueueItem = (tr, active) => {
        if (!tr || !tr.dataset) return '';
        const id = tr.dataset.songId;
        const title = tr.dataset.title || '—';
        const artist = tr.dataset.artist || '';
        const idx = queue.indexOf(tr);
        return `<div class="queue-item${active ? ' active' : ''}" data-idx="${idx}" draggable="true">
            <div class="queue-cover"><img src="/cover/${id}" alt="" onerror="this.remove()"></div>
            <div class="queue-meta">
                <div class="queue-title">${escapeAttr(title)}</div>
                <div class="queue-artist">${escapeAttr(artist)}</div>
            </div>
        </div>`;
    };
    const escapeAttr = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    window.__mobileQueueSheet = (() => {
        if (window.__mobileQueueSheetInit) return;
        window.__mobileQueueSheetInit = true;
        let sheet = null;
        let backdrop = null;
        const build = () => {
            if (sheet) return sheet;
            backdrop = document.createElement('div');
            backdrop.className = 'mq-sheet-backdrop';
            backdrop.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0);z-index:9100;transition:background 0.25s ease;pointer-events:none;';
            sheet = document.createElement('div');
            sheet.className = 'mq-sheet';
            sheet.innerHTML = '<div class="mq-grip"></div><div class="mq-header"><h3>Queue</h3><button class="mq-close" aria-label="Close">×</button></div><div class="mq-body" id="mq-sheet-body"></div>';
            sheet.style.cssText = 'position:fixed;left:0;right:0;bottom:0;background:#16181c;border-radius:18px 18px 0 0;z-index:9101;transform:translateY(100%);transition:transform 0.32s cubic-bezier(0.32,0.72,0,1);max-height:80vh;display:flex;flex-direction:column;padding-bottom:env(safe-area-inset-bottom,0px);box-shadow:0 -8px 32px rgba(0,0,0,0.5);';
            sheet.querySelector('.mq-grip').style.cssText = 'width:36px;height:4px;background:rgba(255,255,255,0.25);border-radius:2px;margin:8px auto 6px;';
            sheet.querySelector('.mq-header').style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:8px 18px 12px;border-bottom:1px solid rgba(255,255,255,0.06)';
            sheet.querySelector('h3').style.cssText = 'margin:0;font-size:16px;font-weight:700;color:#fff;';
            sheet.querySelector('.mq-close').style.cssText = 'background:transparent;border:0;color:rgba(255,255,255,0.7);font-size:24px;cursor:pointer;line-height:1;padding:0;width:32px;height:32px;';
            sheet.querySelector('.mq-body').style.cssText = 'overflow-y:auto;padding:8px 12px;flex:1;-webkit-overflow-scrolling:touch;';
            sheet.querySelector('.mq-close').addEventListener('click', close);
            backdrop.addEventListener('click', close);
            document.body.appendChild(backdrop);
            document.body.appendChild(sheet);
            let touchStartY = null;
            sheet.addEventListener('touchstart', (e) => {
                if (e.touches[0].clientY < sheet.getBoundingClientRect().top + 60) {
                    touchStartY = e.touches[0].clientY;
                }
            }, { passive: true });
            sheet.addEventListener('touchmove', (e) => {
                if (touchStartY === null) return;
                const dy = e.touches[0].clientY - touchStartY;
                if (dy > 0) sheet.style.transform = 'translateY(' + (dy * 0.7) + 'px)';
            }, { passive: true });
            sheet.addEventListener('touchend', (e) => {
                if (touchStartY === null) return;
                const dy = (e.changedTouches[0].clientY) - touchStartY;
                touchStartY = null;
                if (dy > 100) close();
                else sheet.style.transform = 'translateY(0)';
            }, { passive: true });
            return sheet;
        };
        const render = () => {
            if (!sheet) return;
            const body = sheet.querySelector('#mq-sheet-body');
            const q = window.doniixify?.queue || [];
            if (!q.length) { body.innerHTML = '<div style="padding:32px;text-align:center;color:rgba(255,255,255,0.5)">Queue is empty</div>'; return; }
            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const html = q.map((r, i) => {
                const active = i === queueIndex;
                const id = r?.dataset?.songId;
                const title = r?.dataset?.title || '—';
                const artist = r?.dataset?.artist || '';
                return '<div class="mq-item" data-idx="' + i + '" style="display:flex;gap:12px;padding:8px;align-items:center;border-radius:8px;cursor:pointer;' + (active ? 'background:rgba(var(--accent-rgb,30,215,96),0.15)' : '') + '"><img src="/cover/' + id + '" alt="" style="width:44px;height:44px;border-radius:6px;object-fit:cover;flex-shrink:0" onerror="this.style.opacity=0.3"><div style="min-width:0;flex:1"><div style="font-weight:600;color:#fff;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(title) + '</div><div style="font-size:12px;color:rgba(255,255,255,0.6);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(artist) + '</div></div>' + (active ? '<div style="width:8px;height:8px;border-radius:50%;background:#1ed760"></div>' : '') + '</div>';
            }).join('');
            body.innerHTML = html;
            body.querySelectorAll('.mq-item').forEach(it => it.addEventListener('click', () => {
                const idx = parseInt(it.dataset.idx, 10);
                const r = q[idx];
                if (r) { try { loadFromRow(r, { userPick: true }); } catch (_) {} close(); }
            }));
        };
        const open = () => {
            build();
            render();
            requestAnimationFrame(() => {
                backdrop.style.background = 'rgba(0,0,0,0.55)';
                backdrop.style.pointerEvents = 'auto';
                sheet.style.transform = 'translateY(0)';
            });
        };
        const close = () => {
            if (!sheet) return;
            backdrop.style.background = 'rgba(0,0,0,0)';
            backdrop.style.pointerEvents = 'none';
            sheet.style.transform = 'translateY(100%)';
        };
        return { open, close, render };
    })();

    window.__tagFilterChips = (() => {
        if (window.__tagChipsInit) return;
        window.__tagChipsInit = true;
        let allTagsCache = null;
        let songTagsCache = null;
        const loadAllTags = async () => {
            if (allTagsCache) return allTagsCache;
            try {
                const r = await fetch('/api/tags/list');
                const d = await r.json();
                allTagsCache = (d && d.tags) || [];
                return allTagsCache;
            } catch (_) { return []; }
        };
        const renderBar = async (container, table) => {
            if (!container || container.querySelector('.tag-chip-bar')) return;
            const tags = await loadAllTags();
            if (!tags.length) return;
            const top = tags.slice(0, 8);
            const bar = document.createElement('div');
            bar.className = 'tag-chip-bar';
            bar.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;padding:10px 0;margin-bottom:8px;border-bottom:1px solid var(--border)';
            bar.innerHTML = '<div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.08em;margin-right:8px;align-self:center">Tags</div>' +
                top.map(t => `<button class="tag-chip" data-tag="${t.tag}" style="background:rgba(255,255,255,0.06);border:1px solid var(--border);color:var(--text-primary);font-size:12px;padding:5px 12px;border-radius:999px;cursor:pointer">${t.tag} <span style="color:var(--text-muted)">${t.n}</span></button>`).join('') +
                '<button class="tag-chip" data-tag="" style="background:transparent;border:1px solid var(--border);color:var(--text-muted);font-size:12px;padding:5px 12px;border-radius:999px;cursor:pointer">Clear</button>';
            container.insertBefore(bar, container.firstChild);
            bar.addEventListener('click', async (e) => {
                const btn = e.target.closest('.tag-chip');
                if (!btn) return;
                bar.querySelectorAll('.tag-chip').forEach(c => c.style.background = 'rgba(255,255,255,0.06)');
                const tag = btn.dataset.tag;
                if (!tag) {
                    table?.querySelectorAll('tr[data-song-id]').forEach(r => r.style.display = '');
                    return;
                }
                btn.style.background = 'rgb(var(--accent-rgb,30,215,96))';
                btn.style.color = '#000';
                let matchSet = songTagsCache?.[tag];
                if (!matchSet) {
                    try {
                        const r = await fetch('/api/tags/songs?tag=' + encodeURIComponent(tag));
                        const d = await r.json();
                        songTagsCache = songTagsCache || {};
                        songTagsCache[tag] = new Set((d?.song_ids || []).map(Number));
                        matchSet = songTagsCache[tag];
                    } catch (_) { return; }
                }
                table?.querySelectorAll('tr[data-song-id]').forEach(row => {
                    row.style.display = matchSet.has(Number(row.dataset.songId)) ? '' : 'none';
                });
            });
        };
        const tryRender = () => {
            const table = document.querySelector('.main-scroll .song-table');
            const container = document.querySelector('.main-scroll .page-header')?.parentNode || document.querySelector('.main-scroll > div');
            if (table && container) renderBar(container, table);
        };
        document.addEventListener('DOMContentLoaded', tryRender);
        setTimeout(tryRender, 800);
        document.addEventListener('spa-navigated', () => {
            allTagsCache = null;
            songTagsCache = null;
            setTimeout(tryRender, 100);
        });
    })();

    window.__playlistDragReorder = (() => {
        if (window.__playlistDragInit) return;
        window.__playlistDragInit = true;
        let srcRow = null;
        const isPlaylistView = (el) => !!el.closest('[data-playlist-view="1"][data-playlist-id]');
        const markDraggable = () => {
            document.querySelectorAll('[data-playlist-view="1"] tr[data-song-id]').forEach(r => {
                if (!r.hasAttribute('draggable')) r.setAttribute('draggable', 'true');
            });
        };
        markDraggable();
        if ('MutationObserver' in window) {
            new MutationObserver(markDraggable).observe(document.body, { childList: true, subtree: true });
        }
        document.addEventListener('dragstart', (e) => {
            const row = e.target.closest && e.target.closest('tr[data-song-id]');
            if (!row || !isPlaylistView(row)) return;
            srcRow = row;
            row.style.opacity = '0.4';
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', row.dataset.songId); } catch (_) {}
        });
        document.addEventListener('dragover', (e) => {
            const row = e.target.closest && e.target.closest('tr[data-song-id]');
            if (!row || !srcRow || !isPlaylistView(row) || row === srcRow) return;
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (_) {}
            row.classList.add('drag-over-row');
        });
        document.addEventListener('dragleave', (e) => {
            const row = e.target.closest && e.target.closest('tr[data-song-id]');
            if (row) row.classList.remove('drag-over-row');
        });
        document.addEventListener('drop', async (e) => {
            const dstRow = e.target.closest && e.target.closest('tr[data-song-id]');
            if (!dstRow || !srcRow || !isPlaylistView(dstRow) || dstRow === srcRow) return;
            e.preventDefault();
            const tbody = dstRow.parentNode;
            const srcRect = srcRow.getBoundingClientRect();
            const dstRect = dstRow.getBoundingClientRect();
            if (srcRect.top < dstRect.top) tbody.insertBefore(srcRow, dstRow.nextSibling);
            else tbody.insertBefore(srcRow, dstRow);
            const view = dstRow.closest('[data-playlist-view="1"][data-playlist-id]');
            const plId = view.dataset.playlistId;
            const order = Array.from(tbody.querySelectorAll('tr[data-song-id]')).map(r => Number(r.dataset.songId));
            try {
                await fetch('/api/playlists/reorder', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ playlist_id: Number(plId), order }),
                });
                if (window.__showToast) window.__showToast('Reordered');
            } catch (_) {}
        });
        document.addEventListener('dragend', () => {
            document.querySelectorAll('tr.drag-over-row').forEach(r => r.classList.remove('drag-over-row'));
            if (srcRow) srcRow.style.opacity = '';
            srcRow = null;
        });
    })();

    window.__queueReorderBound = window.__queueReorderBound || false;
    if (!window.__queueReorderBound) {
        window.__queueReorderBound = true;
        let dragSrcIdx = null;
        document.addEventListener('dragstart', (e) => {
            const item = e.target.closest && e.target.closest('.queue-item');
            if (!item) return;
            dragSrcIdx = parseInt(item.dataset.idx, 10);
            item.style.opacity = '0.4';
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(dragSrcIdx)); } catch (_) {}
        });
        document.addEventListener('dragover', (e) => {
            const item = e.target.closest && e.target.closest('.queue-item');
            if (!item) return;
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (_) {}
            item.classList.add('drag-over');
        });
        document.addEventListener('dragleave', (e) => {
            const item = e.target.closest && e.target.closest('.queue-item');
            if (item) item.classList.remove('drag-over');
        });
        document.addEventListener('drop', (e) => {
            const item = e.target.closest && e.target.closest('.queue-item');
            if (!item || dragSrcIdx === null) return;
            e.preventDefault();
            const dstIdx = parseInt(item.dataset.idx, 10);
            if (Number.isNaN(dstIdx) || Number.isNaN(dragSrcIdx) || dstIdx === dragSrcIdx) return;
            try {
                const tr = queue.splice(dragSrcIdx, 1)[0];
                if (tr) {
                    queue.splice(dstIdx, 0, tr);
                    if (dragSrcIdx === queueIndex) queueIndex = dstIdx;
                    else if (dragSrcIdx < queueIndex && dstIdx >= queueIndex) queueIndex--;
                    else if (dragSrcIdx > queueIndex && dstIdx <= queueIndex) queueIndex++;
                    if (typeof renderQueue === 'function') renderQueue();
                    if (typeof renderQueueOverlay === 'function') renderQueueOverlay();
                }
            } catch (_) {}
        });
        document.addEventListener('dragend', () => {
            document.querySelectorAll('.queue-item').forEach(it => {
                it.style.opacity = '';
                it.classList.remove('drag-over');
            });
            dragSrcIdx = null;
        });
    }

    // queueBtn handled by Now Playing panel (below) — no side-panel queue

    // ===== LYRICS PANEL =====
    const lyricsBtn = document.getElementById('pb-lyrics');
    let currentLyrics = null;

    let lfSyncedLines = null;
    let lfRafId = null;
    const LF_LOOKAHEAD = 0.12;

    function estimateSyllables(word) {
        if (!word) return 1;
        const w = word.toLowerCase().replace(/[^a-ząćęłńóśźżäöüáéíóúàèìòùâêîôûãõ]/gi, '');
        if (!w) return 1;
        const isPolish = /[ąćęłńóśźż]/.test(w);
        const vowels = 'aeiouyąęóáéíóúàèìòùäöüâêîôûãõ';
        let syl = 0;
        let prevVowel = false;
        for (let i = 0; i < w.length; i++) {
            const ch = w[i];
            const v = vowels.includes(ch);
            if (v) {
                const prev = w[i - 1];
                const isDiphthong = !isPolish && prevVowel && (
                    (prev === 'a' && (ch === 'i' || ch === 'u' || ch === 'y')) ||
                    (prev === 'e' && (ch === 'a' || ch === 'i' || ch === 'u' || ch === 'y')) ||
                    (prev === 'o' && (ch === 'i' || ch === 'u' || ch === 'y' || ch === 'a')) ||
                    (prev === 'i' && ch === 'e') ||
                    (prev === 'u' && ch === 'i')
                );
                if (!prevVowel || isDiphthong) {
                    if (!isDiphthong) syl++;
                }
            }
            prevVowel = v;
        }
        if (!isPolish && syl > 1 && /[^aeiou]e$/.test(w)) syl--;
        if (!isPolish && /^(the|a|an|and|or|of|to|in|is|it|by|on|at|as|be)$/.test(w)) return 1;
        return Math.max(1, syl);
    }

    function karaokeWordIdx(words, prog) {
        const n = words.length;
        if (!n) return -1;
        const weights = new Array(n);
        let total = 0;
        for (let i = 0; i < n; i++) {
            const raw = (words[i].textContent || '').trim();
            const syl = estimateSyllables(raw);
            const longBoost = raw.length >= 8 ? 1.15 : 1;
            const stop = /[.!?…]$/.test(raw);
            const pause = /[,;:—–]$/.test(raw);
            const tail = stop ? 1.35 : pause ? 0.7 : 0;
            weights[i] = Math.max(0.85, syl * longBoost) + tail;
            total += weights[i];
        }
        const stretched = Math.max(0, Math.min(1, prog / 0.88));
        const p = Math.pow(stretched, 0.95);
        const target = p * total;
        const ANTICIPATE = 0.25;
        let acc = 0;
        for (let i = 0; i < n; i++) {
            const w = weights[i];
            const boundary = acc + w * (1 - ANTICIPATE);
            if (target < boundary) return i;
            acc += w;
        }
        return n - 1;
    }

    function buildLyricsFullscreen() {
        let lf = document.getElementById('lyrics-fullscreen');
        if (lf) return lf;
        lf = document.createElement('div');
        lf.id = 'lyrics-fullscreen';
        lf.innerHTML =
            '<button class="lf-close" title="Close (Esc)" aria-label="Close">×</button>' +
            '<div class="lf-grid">' +
                '<div class="lf-lyrics-col"><div class="lf-lyrics" id="lf-lyrics">Loading lyrics…</div></div>' +
                '<div class="lf-cover-col">' +
                    '<div class="lf-cover-wrap"><img id="lf-cover" alt=""></div>' +
                    '<div class="lf-title" id="lf-title">—</div>' +
                    '<div class="lf-artist" id="lf-artist">—</div>' +
                    '<div class="lf-seek"><span id="lf-time-cur">0:00</span><div class="lf-seek-bar" id="lf-seek-bar"><div class="lf-seek-fill" id="lf-seek-fill"></div></div><span id="lf-time-tot">0:00</span></div>' +
                    '<div class="lf-controls">' +
                        '<button class="lf-ctrl" id="lf-shuffle" title="Smart"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 14 4 4-4 4"/><path d="m18 2 4 4-4 4"/><path d="M2 18h1.973a4 4 0 0 0 3.3-1.7l5.454-8.6a4 4 0 0 1 3.3-1.7H22"/><path d="M2 6h1.972a4 4 0 0 1 3.6 2.2"/><path d="M22 18h-6.041a4 4 0 0 1-3.3-1.8l-.359-.45"/></svg></button>' +
                        '<button class="lf-ctrl" id="lf-prev" title="Previous"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="19 20 9 12 19 4 19 20"/><rect x="5" y="5" width="2" height="14"/></svg></button>' +
                        '<button class="lf-ctrl lf-play" id="lf-play" title="Play/Pause"><svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg></button>' +
                        '<button class="lf-ctrl" id="lf-next" title="Next"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 4 15 12 5 20 5 4"/><rect x="17" y="5" width="2" height="14"/></svg></button>' +
                        '<button class="lf-ctrl" id="lf-repeat" title="Repeat"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></svg></button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(lf);
        lf.querySelector('.lf-close').addEventListener('click', closeLyricsFullscreen);
        lf.addEventListener('click', (e) => { if (e.target === lf) closeLyricsFullscreen(); });
        const relay = (targetId, after) => () => {
            const el = document.getElementById(targetId);
            if (!el) return;
            try { if (window.__audioCtx && window.__audioCtx.state === 'suspended') window.__audioCtx.resume().catch(() => {}); } catch (_) {}
            el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
            if (typeof after === 'function') after();
            try { if (typeof syncPlaylistShuffle === 'function') syncPlaylistShuffle(); } catch (_) {}
        };
        lf.querySelector('#lf-play').addEventListener('click', () => {
            try { if (window.__audioCtx && window.__audioCtx.state === 'suspended') window.__audioCtx.resume().catch(() => {}); } catch (_) {}
            if (audio.paused) { audio.play().catch(() => {}); } else { audio.pause(); }
        });
        lf.querySelector('#lf-prev').addEventListener('click', relay('pb-prev'));
        lf.querySelector('#lf-next').addEventListener('click', relay('pb-next'));
        lf.querySelector('#lf-shuffle').addEventListener('click', relay('pb-shuffle'));
        lf.querySelector('#lf-repeat').addEventListener('click', relay('pb-repeat'));
        const seekBar = lf.querySelector('#lf-seek-bar');
        seekBar.addEventListener('click', (e) => {
            if (!audio.duration) return;
            const r = seekBar.getBoundingClientRect();
            const pct = (e.clientX - r.left) / r.width;
            audio.currentTime = pct * audio.duration;
        });
        return lf;
    }

    function lfFmtTime(s) {
        if (!isFinite(s) || s < 0) return '0:00';
        const m = Math.floor(s / 60);
        const ss = Math.floor(s % 60).toString().padStart(2, '0');
        return m + ':' + ss;
    }

    function lfUpdateUi() {
        if (!document.body.classList.contains('lyrics-fullscreen-active')) return;
        lfRafId = requestAnimationFrame(lfUpdateUi);
        const cur = document.getElementById('lf-time-cur');
        const tot = document.getElementById('lf-time-tot');
        const fill = document.getElementById('lf-seek-fill');
        if (cur) cur.textContent = lfFmtTime(audio.currentTime);
        if (tot) tot.textContent = lfFmtTime(audio.duration);
        if (fill && audio.duration > 0) fill.style.width = ((audio.currentTime / audio.duration) * 100) + '%';
        const playBtn = document.getElementById('lf-play');
        if (playBtn) {
            const playing = !audio.paused && !audio.ended;
            playBtn.innerHTML = playing
                ? '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
        }
        const cover = document.querySelector('.player-bar .pb-cover img');
        const lfCover = document.getElementById('lf-cover');
        if (cover && lfCover && lfCover.src !== cover.src && cover.src) lfCover.src = cover.src;
        const lfT = document.getElementById('lf-title');
        const lfA = document.getElementById('lf-artist');
        if (lfT) lfT.textContent = ui.title.textContent || '—';
        if (lfA) lfA.textContent = ui.artist.textContent || '—';
        if (lfSyncedLines && lfSyncedLines.length) {
            const t = audio.currentTime + LF_LOOKAHEAD;
            let idx = -1;
            for (let i = 0; i < lfSyncedLines.length; i++) {
                if (lfSyncedLines[i].time <= t) idx = i;
                else break;
            }
            const lines = document.querySelectorAll('#lf-lyrics .lf-lyric-line');
            lines.forEach((el, i) => {
                el.classList.toggle('active', i === idx);
                el.classList.toggle('past', i < idx);
            });
            if (idx >= 0 && lines[idx]) {
                const since = Date.now() - (window.__lfUserScroll || 0);
                if (since > 2500) lines[idx].scrollIntoView({ behavior: 'smooth', block: 'center' });
                const cur = lfSyncedLines[idx];
                const next = lfSyncedLines[idx + 1];
                const endT = next ? next.time : cur.time + 3.5;
                const dur = Math.max(0.4, endT - cur.time);
                const prog = Math.max(0, Math.min(1, (t - cur.time) / dur));
                const lineEl = lines[idx];
                const words = lineEl.querySelectorAll('.lf-word');
                if (words.length) {
                    const wi = karaokeWordIdx(words, prog);
                    words.forEach((w, k) => {
                        w.classList.toggle('lit', k <= wi);
                        w.classList.toggle('now', k === wi);
                    });
                }
                const dots = lineEl.querySelectorAll('.lf-inst-dot');
                if (dots.length) {
                    const dp = prog * dots.length;
                    dots.forEach((d, k) => d.classList.toggle('lit', dp >= k + 0.4));
                }
            }
        }
    }

    window.__parseLrcEnhanced = function (synced) {
        if (!synced || !/<\d{1,2}:\d{2}/.test(synced)) return null;
        const out = [];
        const lineRe = /\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/;
        const wordRe = /<(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?>([^<]*)/g;
        synced.split(/\r?\n/).forEach(ln => {
            const lm = ln.match(lineRe);
            if (!lm) return;
            const lineStart = parseInt(lm[1], 10) * 60 + parseInt(lm[2], 10) + (lm[3] ? parseInt(lm[3].padEnd(3, '0').slice(0, 3), 10) / 1000 : 0);
            const tail = ln.slice(lm.index + lm[0].length);
            const words = [];
            let m;
            while ((m = wordRe.exec(tail)) !== null) {
                const t = parseInt(m[1], 10) * 60 + parseInt(m[2], 10) + (m[3] ? parseInt(m[3].padEnd(3, '0').slice(0, 3), 10) / 1000 : 0);
                const text = (m[4] || '').trim();
                if (text) words.push({ time: t, text });
            }
            wordRe.lastIndex = 0;
            const lineText = tail.replace(/<\d{1,2}:\d{2}(?:\.\d{1,3})?>/g, '').trim();
            out.push({ time: lineStart, text: lineText, words: words.length > 0 ? words : null });
        });
        out.sort((a, b) => a.time - b.time);
        return out.length > 0 ? out : null;
    };

    window.__parseLrcBasic = window.__parseLrcBasic || function (synced, opts) {
        const out = [];
        if (!synced) return out;
        const re = /\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/g;
        synced.split(/\r?\n/).forEach(ln => {
            let m, last = 0;
            const stamps = [];
            while ((m = re.exec(ln)) !== null) {
                const min = parseInt(m[1], 10);
                const sec = parseInt(m[2], 10);
                const ms = m[3] ? parseInt(m[3].padEnd(3, '0').slice(0, 3), 10) : 0;
                stamps.push(min * 60 + sec + ms / 1000);
                last = m.index + m[0].length;
            }
            const txt = ln.slice(last).trim();
            for (const t of stamps) out.push({ time: t, text: txt });
        });
        out.sort((a, b) => a.time - b.time);
        if (!opts || !opts.enriched) return out;
        if (!out.length) return null;
        const enriched = [];
        const SENSIBLE_TEXT_DUR = 4.5;
        for (let i = 0; i < out.length; i++) {
            const cur = out[i];
            const next = out[i + 1];
            if (i === 0 && cur.time > 3) {
                enriched.push({ time: 0, endTime: cur.time, text: '', instrumental: true });
            }
            const textEnd = next ? Math.min(cur.time + SENSIBLE_TEXT_DUR, next.time) : cur.time + SENSIBLE_TEXT_DUR;
            enriched.push({ time: cur.time, endTime: textEnd, text: cur.text });
            if (next && (next.time - textEnd) > 1.5 && cur.text) {
                enriched.push({ time: textEnd, endTime: next.time, text: '', instrumental: true });
            }
        }
        return enriched;
    };
    const parseLfLrc = (synced) => window.__parseLrcBasic(synced);

    async function openLyricsFullscreen() {
        if (!currentSongId) return;
        const lf = buildLyricsFullscreen();
        document.body.classList.add('lyrics-fullscreen-active');
        lf.classList.add('open');
        const cover = document.querySelector('.player-bar .pb-cover img');
        const lfCover = document.getElementById('lf-cover');
        if (lfCover && cover) lfCover.src = cover.src;
        document.getElementById('lf-title').textContent = ui.title.textContent || '—';
        document.getElementById('lf-artist').textContent = ui.artist.textContent || '—';
        if (!document.getElementById('lf-download-lrc')) {
            const dlBtn = document.createElement('button');
            dlBtn.id = 'lf-download-lrc';
            dlBtn.title = 'Download .lrc';
            dlBtn.style.cssText = 'position:absolute;top:18px;right:60px;background:transparent;border:0;color:rgba(255,255,255,0.7);cursor:pointer;padding:8px;z-index:5;';
            dlBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>';
            dlBtn.addEventListener('click', () => {
                const meta = window.__currentTrackMeta || {};
                if (!meta.title || !meta.artist) return;
                const url = '/api/export/lrc?artist=' + encodeURIComponent(meta.artist) + '&title=' + encodeURIComponent(meta.title);
                location.href = url;
            });
            lf.appendChild(dlBtn);
        }
        const target = document.getElementById('lf-lyrics');
        target.innerHTML = '<div class="lf-lyric-placeholder">Loading lyrics…</div>';
        lfSyncedLines = null;
        if (lfRafId) cancelAnimationFrame(lfRafId);
        lfRafId = requestAnimationFrame(lfUpdateUi);
        target.addEventListener('scroll', () => { window.__lfUserScroll = Date.now(); }, { passive: true });
        const title = ui.title.textContent;
        const artist = ui.artist.textContent;
        const duration = Math.round(audio.duration || 0);
        try {
            const r = await fetch(`/api/lyrics?artist=${encodeURIComponent(artist)}&title=${encodeURIComponent(title)}&duration=${duration}`);
            const d = await r.json();
            if (!d.found || (!d.plain && !d.synced)) {
                target.innerHTML = '<div class="lf-lyric-placeholder">No lyrics for this track</div>';
                return;
            }
            if (d.synced) {
                lfSyncedLines = parseLfLrc(d.synced);
                if (lfSyncedLines.length) {
                    target.innerHTML = lfSyncedLines.map((l, i) => {
                        if (!l.text) return '<div class="lf-lyric-line lf-instr" data-i="' + i + '"><span class="lf-inst-dot"></span><span class="lf-inst-dot"></span><span class="lf-inst-dot"></span></div>';
                        const wordParts = l.text.split(/(\s+)/).filter(s => s.length > 0);
                        const wordHtml = wordParts.map(p => /^\s+$/.test(p) ? p : '<span class="lf-word">' + p.replace(/</g, '&lt;') + '</span>').join('');
                        return '<div class="lf-lyric-line" data-i="' + i + '">' + wordHtml + '</div>';
                    }).join('');
                    return;
                }
            }
            const plain = (d.plain || '').trim();
            if (plain) {
                target.innerHTML = '<pre class="lf-lyric-plain">' + plain.replace(/</g, '&lt;') + '</pre>';
            } else {
                target.innerHTML = '<div class="lf-lyric-placeholder">No lyrics for this track</div>';
            }
        } catch (e) {
            target.innerHTML = '<div class="lf-lyric-placeholder">Could not load lyrics</div>';
        }
    }

    function closeLyricsFullscreen() {
        document.body.classList.remove('lyrics-fullscreen-active');
        const lf = document.getElementById('lyrics-fullscreen');
        if (lf) lf.classList.remove('open');
        if (lfRafId) { cancelAnimationFrame(lfRafId); lfRafId = null; }
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.body.classList.contains('lyrics-fullscreen-active')) {
            closeLyricsFullscreen();
        }
        if ((e.key === 'l' || e.key === 'L') && !e.target.matches('input, textarea, [contenteditable]')) {
            if (document.body.classList.contains('lyrics-fullscreen-active')) closeLyricsFullscreen();
            else openLyricsFullscreen();
        }
    });

    document.getElementById('pb-lyrics')?.addEventListener('click', (e) => {
        if (e.shiftKey || e.altKey) {
            e.preventDefault();
            e.stopImmediatePropagation();
            openLyricsFullscreen();
        }
    }, true);
    window.__openLyricsFullscreen = openLyricsFullscreen;
    const renderLyrics = async () => {
        if (!currentSongId) {
            sidePanelBody.innerHTML = '<div class="lyrics-empty">Play a track first.</div>';
            return;
        }
        sidePanelBody.innerHTML = '<div class="lyrics-empty">Looking up lyrics…</div>';
        const title = ui.title.textContent;
        const artist = ui.artist.textContent;
        const duration = Math.round(audio.duration || 0);
        try {
            const res = await fetch(`/api/lyrics?artist=${encodeURIComponent(artist)}&title=${encodeURIComponent(title)}&duration=${duration}`);
            const data = await res.json();
            if (!data.found || (!data.plain && !data.synced)) {
                currentLyrics = null;
                window.__currentDesktopLyrics = null;
                sidePanelBody.innerHTML = '<div class="lyrics-empty">No lyrics found for this track.</div>';
                return;
            }
            if (data.synced) {
                currentLyrics = parseLrc(data.synced);
                window.__currentDesktopLyrics = currentLyrics;
                renderSyncedLyrics();
            } else {
                currentLyrics = null;
                window.__currentDesktopLyrics = null;
                sidePanelBody.innerHTML = '<div class="lyrics-body plain">' + escapeAttr(data.plain) + '</div>';
            }
        } catch (e) {
            sidePanelBody.innerHTML = '<div class="lyrics-empty">Error: ' + escapeAttr(e.message) + '</div>';
        }
    };
    const parseLrc = (text) => window.__parseLrcBasic(text);
    const renderSyncedLyrics = () => {
        const html = '<div class="lyrics-body">' +
            currentLyrics.map((l, i) => `<div class="lyrics-line" data-i="${i}" data-t="${l.time}">${escapeAttr(l.text || '♪')}</div>`).join('') +
            '</div>';
        sidePanelBody.innerHTML = html;
        sidePanelBody.querySelectorAll('.lyrics-line').forEach(el => {
            el.addEventListener('click', () => {
                const t = Number(el.dataset.t);
                if (!isNaN(t)) audio.currentTime = t;
            });
        });
    };
    let lyricsUserScrollAt = 0;
    let lyricsProgrammaticScroll = false;
    sidePanelBody.addEventListener('scroll', () => {
        if (lyricsProgrammaticScroll) return;
        if (sidePanelMode === 'lyrics') lyricsUserScrollAt = Date.now();
    });

    const tickLyrics = () => {
        if (sidePanelMode !== 'lyrics' || !currentLyrics) return;
        const t = audio.currentTime + 0.12;
        let activeIdx = -1;
        for (let i = 0; i < currentLyrics.length; i++) {
            if (currentLyrics[i].time <= t) activeIdx = i; else break;
        }
        const cur = sidePanelBody.querySelector('.lyrics-line.active');
        if (cur && Number(cur.dataset.i) === activeIdx) return;
        const lines = sidePanelBody.querySelectorAll('.lyrics-line');
        lines.forEach((el, i) => {
            el.classList.remove('active', 'prev', 'next', 'played');
            if (i === activeIdx) el.classList.add('active');
            else if (i === activeIdx - 1) el.classList.add('prev');
            else if (i === activeIdx + 1) el.classList.add('next');
            else if (i < activeIdx) el.classList.add('played');
        });
        if (activeIdx >= 0 && lines[activeIdx] && Date.now() - lyricsUserScrollAt > 4000) {
            lyricsProgrammaticScroll = true;
            lines[activeIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(() => { lyricsProgrammaticScroll = false; }, 700);
        }
    };
    audio.addEventListener('timeupdate', tickLyrics);
    // ===== NOW PLAYING SQUEEZE PANEL (Spotify-style right side) =====
    const appEl = document.querySelector('.app');
    const npPanel = document.getElementById('now-playing-panel');
    const npCover = document.getElementById('np-cover');
    const npTitle = document.getElementById('np-title');
    const npArtistName = document.getElementById('np-artist-name');
    const npArtistCard = document.getElementById('np-artist-card');
    const npArtistCardName = document.getElementById('np-artist-card-name');
    const npUpNextList = document.getElementById('np-up-next-list');
    const npLyricsText = document.getElementById('np-lyrics-text');
    const npLyricsEmpty = document.getElementById('np-lyrics-empty');
    const npQueueList = document.getElementById('np-queue-list');

    const isNpVisible = () => appEl?.classList.contains('now-playing-open');
    const showNp = () => {
        appEl?.classList.add('now-playing-open');
        updateNp();
    };
    const hideNp = () => {
        appEl?.classList.remove('now-playing-open');
        document.querySelectorAll('.np-overlay.open').forEach(o => o.classList.remove('open'));
        try { localStorage.removeItem(__k('doniix-npview')); } catch (_) {}
    };
    const toggleNp = () => { isNpVisible() ? hideNp() : showNp(); };
    const npHeadTitle = document.getElementById('np-head-title');
    const npCloseView = document.getElementById('np-close-view');
    const closeAllOtherPanels = () => {
        try { document.querySelector('.side-panel.open')?.classList.remove('open'); } catch (_) {}
        try {
            const jm = document.getElementById('jam-modal');
            if (jm) jm.classList.remove('open');
            const mb = document.getElementById('modal-backdrop');
            if (mb) mb.classList.remove('open');
        } catch (_) {}
    };
    const NPVIEW_KEY = __k('doniix-npview');
    const setNpView = (view) => {
        closeAllOtherPanels();
        if (!isNpVisible()) showNp();
        document.querySelectorAll('.np-view').forEach(v => v.style.display = v.dataset.npView === view ? '' : 'none');
        if (npHeadTitle) npHeadTitle.textContent = view === 'lyrics' ? 'Lyrics' : view === 'queue' ? 'Queue' : 'Now Playing';
        if (npCloseView) npCloseView.style.display = view === 'info' ? 'none' : '';
        const lfBtn = document.getElementById('np-lyrics-fullscreen-btn');
        if (lfBtn) lfBtn.style.display = view === 'lyrics' ? '' : 'none';
        if (view === 'lyrics') loadLyricsIntoNp();
        if (view === 'queue') renderQueueOverlay();
        if (view === 'info') updateNp();
        try {
            if (view === 'lyrics' || view === 'queue') localStorage.setItem(NPVIEW_KEY, view);
            else localStorage.removeItem(NPVIEW_KEY);
        } catch (_) {}
    };
    npCloseView?.addEventListener('click', () => setNpView('info'));

    try {
        const savedView = localStorage.getItem(NPVIEW_KEY);
        if (savedView === 'lyrics' || savedView === 'queue') {
            requestAnimationFrame(() => { try { setNpView(savedView); } catch (_) {} });
        }
    } catch (_) {}

    const renderQueueOverlay = () => {
        if (!npQueueList) return;
        if (queueIndex < 0 || queueIndex + 1 >= queue.length) {
            npQueueList.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:12px">Queue is empty</div>`;
            return;
        }
        let html = '';
        for (let i = queueIndex + 1; i < Math.min(queue.length, queueIndex + 50); i++) {
            const r = queue[i];
            const sid = r.dataset.songId;
            html += `<div class="np-up-next" data-song-id="${sid}" data-queue-idx="${i}" style="cursor:pointer">
                <img class="np-up-next-cover" src="/cover/${sid}" onerror="this.style.opacity='0.3'">
                <div class="np-up-next-info">
                    <div class="np-up-next-title">${(r.dataset.title || '').replace(/</g, '&lt;')}</div>
                    <div class="np-up-next-artist">${(r.dataset.artist || '').replace(/</g, '&lt;')}</div>
                </div>
            </div>`;
        }
        npQueueList.innerHTML = html;
    };

    npQueueList?.addEventListener('click', (e) => {
        const item = e.target.closest('.np-up-next[data-queue-idx]');
        if (!item) return;
        const idx = Number(item.dataset.queueIdx);
        if (!Number.isFinite(idx) || idx < 0 || idx >= queue.length) return;
        queueIndex = idx;
        const target = queue[idx];
        if (target) loadFromRow(target, { userPick: true });
        renderQueueOverlay();
    });
    npQueueList?.addEventListener('contextmenu', (e) => {
        const item = e.target.closest('.np-up-next[data-queue-idx]');
        if (!item) return;
        e.preventDefault();
        const idx = Number(item.dataset.queueIdx);
        document.querySelectorAll('.np-queue-ctx').forEach(el => el.remove());
        const menu = document.createElement('div');
        menu.className = 'np-queue-ctx ctx-menu open';
        menu.style.position = 'fixed';
        menu.style.zIndex = '9999';
        menu.innerHTML = `
            <div class="ctx-item" data-act="play"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>Play now</div>
            <div class="ctx-item" data-act="remove" style="color:var(--danger,#f88)"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Remove from queue</div>
        `;
        document.body.appendChild(menu);
        const rect = menu.getBoundingClientRect();
        menu.style.left = Math.min(e.clientX, window.innerWidth - rect.width - 8) + 'px';
        menu.style.top = Math.min(e.clientY, window.innerHeight - rect.height - 8) + 'px';
        const close = () => { menu.remove(); document.removeEventListener('click', close, true); };
        setTimeout(() => document.addEventListener('click', close, true), 0);
        menu.querySelector('[data-act="play"]')?.addEventListener('click', () => {
            queueIndex = idx;
            const target = queue[idx];
            if (target) loadFromRow(target, { userPick: true });
            renderQueueOverlay();
            close();
        });
        menu.querySelector('[data-act="remove"]')?.addEventListener('click', () => {
            if (idx >= 0 && idx < queue.length) {
                queue.splice(idx, 1);
                renderQueueOverlay();
                if (typeof showToast === 'function') showToast('Removed from queue');
            }
            close();
        });
    });

    const updateNp = () => {
        if (!currentSongId) return;
        if (npCover) { npCover.src = `/cover/${currentSongId}`; npCover.style.opacity = '1'; }
        if (npTitle) npTitle.textContent = ui.title?.textContent || '—';
        if (npArtistName) npArtistName.textContent = ui.artist?.textContent || '—';
        if (npArtistCardName) npArtistCardName.textContent = ui.artist?.textContent || '—';
        if (npArtistCard) {
            const href = ui.artist?.getAttribute('href') || '#';
            npArtistCard.setAttribute('href', href);
            const artistName = (ui.artist?.textContent || '').trim();
            if (artistName && npArtistCard.dataset.loadedFor !== artistName) {
                npArtistCard.dataset.loadedFor = artistName;
                window.__artistImgCache = window.__artistImgCache || {};
                const cached = window.__artistImgCache[artistName];
                const setImg = (url) => {
                    if (!url) return;
                    const av = npArtistCard.querySelector('.np-artist-avatar');
                    if (!av) return;
                    av.innerHTML = '<img src="' + url + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit" onerror="this.remove()">';
                };
                if (cached !== undefined) {
                    setImg(cached);
                } else {
                    fetch('/api/artist-image?name=' + encodeURIComponent(artistName)).then(r => r.json()).then(d => {
                        window.__artistImgCache[artistName] = d.url || null;
                        if ((ui.artist?.textContent || '').trim() === artistName) setImg(d.url);
                    }).catch(() => {});
                }
            }
        }
        if (npUpNextList) {
            if (queueIndex >= 0 && queueIndex + 1 < queue.length) {
                const nx = queue[queueIndex + 1];
                const sid = nx.dataset.songId;
                npUpNextList.innerHTML = `
                    <div class="np-up-next" data-song-id="${sid}">
                        <img class="np-up-next-cover" src="/cover/${sid}" onerror="this.style.opacity='0.3'">
                        <div class="np-up-next-info">
                            <div class="np-up-next-title">${(nx.dataset.title || '').replace(/</g, '&lt;')}</div>
                            <div class="np-up-next-artist">${(nx.dataset.artist || '').replace(/</g, '&lt;')}</div>
                        </div>
                    </div>`;
            } else {
                npUpNextList.innerHTML = `<div style="color:var(--text-muted);font-size:13px;padding:8px">Queue is empty</div>`;
            }
        }
    };

    let lastLyricsSongId = null;
    let lastLyricsText = '';
    let lastLyricsLines = null;
    const stripLrcTimestamps = (s) => (s || '').replace(/\[\d{1,2}:\d{1,2}(?:\.\d+)?\]/g, '').replace(/\n{3,}/g, '\n\n').trim();
    const parseLrcSynced = (lrc) => window.__parseLrcBasic(lrc, { enriched: true });
    const __parseLrcSynced_LEGACY_UNUSED = (lrc) => {
        if (!lrc) return null;
        const out = [];
        const lines = lrc.split('\n');
        for (const line of lines) {
            const matches = line.matchAll(/\[(\d{1,2}):(\d{1,2})(?:\.(\d{1,3}))?\]/g);
            const times = [];
            for (const m of matches) {
                const min = parseInt(m[1], 10) || 0;
                const sec = parseInt(m[2], 10) || 0;
                const ms = m[3] ? parseInt(m[3].padEnd(3, '0').slice(0, 3), 10) : 0;
                times.push(min * 60 + sec + ms / 1000);
            }
            if (!times.length) continue;
            const text = line.replace(/\[[^\]]*\]/g, '').trim();
            for (const t of times) out.push({ time: t, text });
        }
        if (!out.length) return null;
        out.sort((a, b) => a.time - b.time);
        const enriched = [];
        const SENSIBLE_TEXT_DUR = 4.5;
        for (let i = 0; i < out.length; i++) {
            const cur = out[i];
            const next = out[i + 1];
            const gap = next ? next.time - cur.time : 4;
            if (i === 0 && cur.time > 3) {
                enriched.push({ time: 0, endTime: cur.time, text: '', instrumental: true });
            }
            const textEnd = next
                ? Math.min(cur.time + SENSIBLE_TEXT_DUR, next.time)
                : cur.time + SENSIBLE_TEXT_DUR;
            enriched.push({ time: cur.time, endTime: textEnd, text: cur.text });
            if (next && (next.time - textEnd) > 1.5 && cur.text) {
                enriched.push({ time: textEnd, endTime: next.time, text: '', instrumental: true });
            }
        }
        return enriched;
    };
    const splitWords = (text) => {
        if (!text) return [];
        return text.split(/(\s+)/).filter(s => s.length > 0);
    };
    const renderSyncedLyricsNp = (lines) => {
        if (!npLyricsText) return;
        npLyricsText.innerHTML = lines.map((l, i) => {
            if (l.instrumental) {
                return `<div class="np-lyric-line np-lyric-inst" data-i="${i}" data-time="${l.time.toFixed(2)}" data-end="${(l.endTime || l.time).toFixed(2)}"><span class="np-inst-dot"></span><span class="np-inst-dot"></span><span class="np-inst-dot"></span></div>`;
            }
            const words = splitWords(l.text || '');
            const wordHtml = words.map((w, wi) => {
                if (/^\s+$/.test(w)) return w;
                return `<span class="np-word" data-w="${wi}">${w.replace(/</g, '&lt;')}</span>`;
            }).join('');
            const nextGap = lines[i + 1] && (lines[i + 1].time - l.time > 4) ? ' np-lyric-gap-after' : '';
            return `<div class="np-lyric-line${nextGap}" data-i="${i}" data-time="${l.time.toFixed(2)}" data-end="${(l.endTime || l.time).toFixed(2)}">${wordHtml || '<span class="np-word">♪</span>'}</div>`;
        }).join('');
    };
    let lyricsRafIdNp = null;
    let lyricsLastIdxNp = -2;
    const lyricsTickSchedule = (fn) => window.__platform.isTv ? setTimeout(fn, 500) : requestAnimationFrame(fn);
    const lyricsTickCancel = (id) => window.__platform.isTv ? clearTimeout(id) : cancelAnimationFrame(id);
    const stopLyricsSyncNp = () => {
        if (lyricsRafIdNp) { lyricsTickCancel(lyricsRafIdNp); lyricsRafIdNp = null; }
        lyricsLastIdxNp = -2;
    };
    const tickLyricsNp = () => {
        lyricsRafIdNp = null;
        if (!lastLyricsLines || !npLyricsText) return;
        if (npLyricsText.style.display === 'none' || !document.querySelector('.app.now-playing-open')) {
            lyricsRafIdNp = lyricsTickSchedule(tickLyricsNp);
            return;
        }
        const t = audio.currentTime + 0.12;
        let activeIdx = -1;
        for (let i = 0; i < lastLyricsLines.length; i++) {
            if (lastLyricsLines[i].time <= t) activeIdx = i;
            else break;
        }
        const els = npLyricsText.querySelectorAll('.np-lyric-line');
        if (activeIdx !== lyricsLastIdxNp) {
            els.forEach((el, i) => {
                el.classList.toggle('active', i === activeIdx);
                el.classList.toggle('past', i < activeIdx);
            });
            if (activeIdx >= 0 && els[activeIdx] && Date.now() - (window.__lyricsUserScroll || 0) > 2500) {
                els[activeIdx].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            lyricsLastIdxNp = activeIdx;
        }
        if (activeIdx >= 0) {
            const cur = lastLyricsLines[activeIdx];
            const next = lastLyricsLines[activeIdx + 1];
            const endT = cur.endTime || (next ? next.time : cur.time + 3.5);
            const dur = Math.max(0.4, endT - cur.time);
            const progress = Math.max(0, Math.min(1, (t - cur.time) / dur));
            const lineEl = els[activeIdx];
            if (lineEl && !cur.instrumental) {
                const wordEls = lineEl.querySelectorAll('.np-word');
                if (wordEls.length) {
                    const wi = karaokeWordIdx(wordEls, progress);
                    wordEls.forEach((we, idx) => {
                        const wasLit = we.classList.contains('lit');
                        const shouldLit = idx <= wi;
                        if (wasLit !== shouldLit) we.classList.toggle('lit', shouldLit);
                        const wasNow = we.classList.contains('now');
                        const shouldNow = idx === wi;
                        if (wasNow !== shouldNow) we.classList.toggle('now', shouldNow);
                    });
                }
            } else if (lineEl && cur.instrumental) {
                const dots = lineEl.querySelectorAll('.np-inst-dot');
                dots.forEach((d, idx) => {
                    const dProg = progress * dots.length;
                    d.classList.toggle('lit', dProg >= idx + 0.4);
                });
            }
        }
        lyricsRafIdNp = lyricsTickSchedule(tickLyricsNp);
    };
    const startLyricsSync = () => {
        stopLyricsSyncNp();
        lyricsRafIdNp = lyricsTickSchedule(tickLyricsNp);
    };
    audio.addEventListener('seeked', () => { lyricsLastIdxNp = -2; });
    document.addEventListener('scroll', () => { window.__lyricsUserScroll = Date.now(); }, true);
    window.__loadLyrics = () => loadLyricsIntoNp();
    const loadLyricsIntoNp = async () => {
        const cidAtStart = currentSongId;
        let sid = currentSongId;
        if (!sid) {
            const m = document.querySelector('.pb-cover img')?.src.match(/\/cover\/(\d+)/);
            if (m) sid = Number(m[1]);
        }
        const title = (ui.title?.textContent || '').trim();
        const artist = (ui.artist?.textContent || '').split('·')[0].trim();
        if (!sid && (!title || title === '—')) {
            if (npLyricsEmpty) { npLyricsEmpty.style.display = 'block'; npLyricsEmpty.textContent = 'Play a track to see lyrics'; }
            if (npLyricsText) npLyricsText.style.display = 'none';
            return;
        }
        if (lastLyricsSongId === sid && (lastLyricsText || lastLyricsLines)) {
            if (npLyricsEmpty) npLyricsEmpty.style.display = 'none';
            if (npLyricsText) {
                if (lastLyricsLines) renderSyncedLyricsNp(lastLyricsLines);
                else npLyricsText.textContent = lastLyricsText;
                npLyricsText.style.display = 'block';
            }
            if (lastLyricsLines) startLyricsSync();
            return;
        }
        if (npLyricsEmpty) { npLyricsEmpty.style.display = 'block'; npLyricsEmpty.textContent = 'Looking up lyrics…'; }
        if (npLyricsText) npLyricsText.style.display = 'none';
        try {
            const duration = Math.round(audio.duration || 0);
            const url = `/api/lyrics?artist=${encodeURIComponent(artist)}&title=${encodeURIComponent(title)}&duration=${duration}`;
            const res = await fetch(url);
            if (currentSongId !== cidAtStart) return;
            let data = null;
            try { data = await res.json(); } catch (_) { data = null; }
            if (currentSongId !== cidAtStart) return;
            let text = '';
            let syncedLines = null;
            if (data) {
                if (data.synced) {
                    syncedLines = parseLrcSynced(data.synced);
                    text = stripLrcTimestamps(data.synced);
                }
                if (!text && data.plain) text = (data.plain || '').trim();
            }
            if (currentSongId !== cidAtStart) return;
            if (!text) {
                if (npLyricsEmpty) { npLyricsEmpty.style.display = 'block'; npLyricsEmpty.textContent = 'No lyrics for this track'; }
                if (npLyricsText) npLyricsText.style.display = 'none';
                lastLyricsSongId = sid;
                lastLyricsText = '';
                lastLyricsLines = null;
            } else {
                if (npLyricsEmpty) npLyricsEmpty.style.display = 'none';
                if (npLyricsText) {
                    if (syncedLines) renderSyncedLyricsNp(syncedLines);
                    else npLyricsText.textContent = text;
                    npLyricsText.style.display = 'block';
                }
                lastLyricsSongId = sid;
                lastLyricsText = text;
                lastLyricsLines = syncedLines;
                if (syncedLines) startLyricsSync();
            }
        } catch (e) {
            console.warn('[lyrics] fetch failed', e);
            if (npLyricsEmpty) { npLyricsEmpty.style.display = 'block'; npLyricsEmpty.textContent = 'Error: ' + (e.message || 'connection failed'); }
        }
    };

    document.getElementById('np-jam-start')?.addEventListener('click', () => {
        document.getElementById('pb-jam')?.click();
        const status = document.getElementById('np-jam-status');
        if (status) status.textContent = 'Creating session…';
    });

    // ===== PLAYLIST ACTIONS BAR (delegated) =====
    const collectPlaylistRows = () => Array.from(document.querySelectorAll('[data-playlist-view="1"] tr[data-song-id]'));
    const ICON_PLAY_22 = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>';
    const ICON_PAUSE_22 = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';
    const refreshPlaylistPlayIcon = () => {
        const icn = document.getElementById('playlist-play-icon');
        if (!icn) return;
        const rows = collectPlaylistRows();
        const playingPlaylist = rows.length && currentSongId && rows.some(r => Number(r.dataset.songId) === currentSongId);
        icn.innerHTML = (playingPlaylist && !audio.paused) ? ICON_PAUSE_22 : ICON_PLAY_22;
    };
    audio.addEventListener('play', refreshPlaylistPlayIcon);
    audio.addEventListener('pause', refreshPlaylistPlayIcon);

    document.addEventListener('click', (e) => {
        if (e.target.closest('#playlist-play-all')) {
            e.preventDefault();
            const rows = collectPlaylistRows();
            if (!rows.length) return;
            const playingPlaylist = currentSongId && rows.some(r => Number(r.dataset.songId) === currentSongId);
            if (playingPlaylist) {
                if (audio.paused) { const p = audio.play(); if (p?.catch) p.catch(showAudioError); }
                else audio.pause();
            } else {
                loadFromRow(rows[0], { userPick: true });
            }
            return;
        }
        if (e.target.closest('#playlist-shuffle')) {
            e.preventDefault();
            document.getElementById('pb-shuffle')?.click();
            return;
        }
        if (e.target.closest('#playlist-download')) {
            e.preventDefault();
            showToast('Already in your library');
            return;
        }
        if (e.target.closest('#playlist-more')) {
            e.preventDefault();
            e.stopPropagation();
            document.getElementById('playlist-more-menu')?.classList.toggle('open');
            return;
        }
        const plAct = e.target.closest('[data-pl-act]');
        if (plAct && e.target.closest('#playlist-more-menu')) {
            const act = plAct.dataset.plAct;
            document.getElementById('playlist-more-menu')?.classList.remove('open');
            if (act === 'queue') {
                const rows = collectPlaylistRows();
                let inserted = 0;
                rows.forEach(r => {
                    const clone = r.cloneNode(true);
                    clone.dataset.queued = '1';
                    queue.splice(queueIndex + 1 + inserted, 0, clone);
                    inserted++;
                });
                showToast(`${inserted} added to queue`);
            }
            if (act === 'jam') {
                const rows = collectPlaylistRows();
                if (rows.length) loadFromRow(rows[0], { userPick: true });
                document.getElementById('pb-jam')?.click();
            }
            return;
        }
        if (!e.target.closest('#playlist-more') && !e.target.closest('#playlist-more-menu')) {
            document.getElementById('playlist-more-menu')?.classList.remove('open');
        }
    });

    const mirrorButton = (sourceId, mirrorSelector) => {
        const src = document.getElementById(sourceId);
        if (!src) return;
        const isActive = src.classList.contains('active');
        const srcSvg = src.querySelector('svg');
        const srcTitle = src.getAttribute('title') || '';
        document.querySelectorAll(mirrorSelector).forEach(m => {
            if (m === src) return;
            m.classList.toggle('active', isActive);
            m.setAttribute('title', srcTitle);
            if (srcSvg) {
                const mSvg = m.querySelector('svg');
                if (mSvg && mSvg.innerHTML !== srcSvg.innerHTML) mSvg.innerHTML = srcSvg.innerHTML;
            }
        });
    };
    const syncPlaylistShuffle = () => {
        mirrorButton('pb-shuffle', '#playlist-shuffle, .action-shuffle, #lf-shuffle');
        mirrorButton('pb-repeat', '#playlist-repeat, .action-repeat, #lf-repeat');
    };
    ['pb-shuffle', 'pb-repeat'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        new MutationObserver(syncPlaylistShuffle).observe(el, { attributes: true, attributeFilter: ['class', 'title'], childList: true, subtree: true });
    });
    new MutationObserver(() => {
        if (document.querySelector('#playlist-shuffle, .action-shuffle, #lf-shuffle, #playlist-repeat, .action-repeat, #lf-repeat')) syncPlaylistShuffle();
    }).observe(document.body, { childList: true, subtree: true });
    syncPlaylistShuffle();

    // ===== SCREENSAVER SETTINGS WIRING (delegated, survives SPA) =====
    function wireScreensaverInputs() {
        const cb = document.getElementById('screensaver-enabled');
        const rng = document.getElementById('screensaver-idle');
        const num = document.getElementById('screensaver-idle-num');
        if (cb && !cb.dataset.wired) {
            cb.dataset.wired = '1';
            cb.checked = (typeof window.__getScreensaverEnabled === 'function') ? window.__getScreensaverEnabled() : false;
            cb.addEventListener('change', () => {
                if (typeof window.__setScreensaverEnabled === 'function') window.__setScreensaverEnabled(cb.checked);
            });
        }
        if (rng && !rng.dataset.wired) {
            rng.dataset.wired = '1';
            const cur = (typeof window.__getScreensaverIdleSec === 'function') ? window.__getScreensaverIdleSec() : 5;
            rng.value = String(cur);
            if (num) num.value = String(cur);
            const apply = (val) => {
                if (typeof window.__setScreensaverIdleSec === 'function') window.__setScreensaverIdleSec(val);
                if (rng.value !== String(val)) rng.value = String(val);
                if (num && num.value !== String(val)) num.value = String(val);
            };
            rng.addEventListener('input', () => apply(rng.value));
            if (num) num.addEventListener('change', () => apply(num.value));
        }
    }
    wireScreensaverInputs();
    new MutationObserver(() => {
        if (document.getElementById('screensaver-enabled') || document.getElementById('screensaver-idle')) wireScreensaverInputs();
    }).observe(document.body, { childList: true, subtree: true });

    // ===== LYRICS FULLSCREEN CONTROL DELEGATION (always works) =====
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('#lf-play, #lf-prev, #lf-next, #lf-shuffle, #lf-repeat');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        try { if (window.__audioCtx && window.__audioCtx.state === 'suspended') window.__audioCtx.resume().catch(() => {}); } catch (_) {}
        const id = btn.id;
        if (id === 'lf-play') {
            const ae = document.querySelector('audio');
            if (!ae) { console.warn('[lf-play] no audio element'); return; }
            if (!ae.src && currentSongId) {
                try { ae.src = location.origin + '/stream/' + currentSongId; ae.load(); } catch (_) {}
            }
            if (ae.paused) { ae.play().catch(err => console.warn('[lf-play] play fail', err)); }
            else { ae.pause(); }
            return;
        }
        const map = { 'lf-prev':'pb-prev', 'lf-next':'pb-next', 'lf-shuffle':'pb-shuffle', 'lf-repeat':'pb-repeat' };
        const target = document.getElementById(map[id]);
        if (target) target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, true);

    // ===== SIDEBAR PLAYLIST SEARCH =====
    const sidebarSearchApply = () => {
        const inp = document.getElementById('sidebar-search-input');
        const list = document.getElementById('sidebar-playlists-list');
        if (!inp || !list) return;
        const q = (inp.value || '').trim().toLowerCase();
        list.querySelectorAll('.sidebar-item').forEach(item => {
            const t = (item.textContent || '').trim().toLowerCase();
            item.style.display = (!q || t.includes(q)) ? '' : 'none';
        });
    };
    document.addEventListener('click', (e) => {
        const t = e.target.closest('#sidebar-search-toggle');
        if (!t) return;
        e.preventDefault();
        e.stopPropagation();
        const wrap = document.getElementById('sidebar-search-wrap');
        const inp = document.getElementById('sidebar-search-input');
        if (!wrap || !inp) return;
        const shown = wrap.style.display !== 'none';
        wrap.style.display = shown ? 'none' : 'block';
        if (!shown) { setTimeout(() => inp.focus(), 30); }
        else { inp.value = ''; sidebarSearchApply(); }
    });
    document.addEventListener('input', (e) => {
        if (e.target && e.target.id === 'sidebar-search-input') sidebarSearchApply();
    });
    document.addEventListener('keydown', (e) => {
        if (e.target && e.target.id === 'sidebar-search-input' && e.key === 'Escape') {
            const wrap = document.getElementById('sidebar-search-wrap');
            e.target.value = '';
            sidebarSearchApply();
            if (wrap) wrap.style.display = 'none';
        }
    });

    // ===== ALBUM MINI BAR (discography) =====
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('.album-mini-btn');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        const bar = btn.closest('.album-mini-bar');
        const albumId = bar?.dataset.albumId;
        if (!albumId) return;
        const act = btn.dataset.act;
        try {
            const res = await fetch(`/api/album/${albumId}/songs`);
            if (!res.ok) { showToast('Album not loaded'); return; }
            const songs = await res.json();
            if (!songs.length) return;
            const fakeRows = songs.map(s => {
                const tr = document.createElement('tr');
                tr.dataset.songId = s.id;
                tr.dataset.title = s.title;
                tr.dataset.artist = s.artist_name || '';
                return tr;
            });
            if (act === 'play') {
                queue.length = 0;
                fakeRows.forEach(r => queue.push(r));
                queueIndex = 0;
                loadFromRow(fakeRows[0], { userPick: true });
            } else if (act === 'shuffle') {
                const sh = window.__smartShuffle(fakeRows.slice());
                queue.length = 0;
                sh.forEach(r => queue.push(r));
                queueIndex = 0;
                loadFromRow(sh[0], { userPick: true });
                showToast('Shuffle album');
            } else if (act === 'add') {
                let i = 0;
                fakeRows.forEach(r => { queue.splice(queueIndex + 1 + i, 0, r); i++; });
                showToast(`${fakeRows.length} added`);
            }
        } catch (_) { showToast('Album load failed'); }
    });

    // ===== SORTABLE TABLE HEADERS =====
    document.addEventListener('click', (e) => {
        const th = e.target.closest('th.sortable');
        if (!th) return;
        const table = th.closest('table.sortable-table');
        if (!table) return;
        const key = th.dataset.sortKey;
        const type = th.dataset.sortType || 'text';
        const currentDir = th.classList.contains('asc') ? 'desc' : th.classList.contains('desc') ? 'asc' : 'asc';
        table.querySelectorAll('th.sortable').forEach(x => x.classList.remove('asc', 'desc', 'active'));
        th.classList.add(currentDir, 'active');
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const rows = Array.from(tbody.querySelectorAll('tr[data-song-id]'));
        const dirMul = currentDir === 'asc' ? 1 : -1;
        rows.sort((a, b) => {
            let av = a.dataset[key] || '';
            let bv = b.dataset[key] || '';
            if (type === 'date') {
                av = Date.parse(av) || 0;
                bv = Date.parse(bv) || 0;
                return (av - bv) * dirMul;
            }
            return av.localeCompare(bv, 'pl', { numeric: true, sensitivity: 'base' }) * dirMul;
        });
        rows.forEach((r, i) => {
            tbody.appendChild(r);
            const idx = r.querySelector('.col-idx .idx-num');
            if (idx) idx.textContent = i + 1;
        });
    });

    document.getElementById('np-edge-toggle')?.addEventListener('click', () => {
        if (isNpVisible()) hideNp(); else setNpView('info');
    });

    document.getElementById('np-close-panel')?.addEventListener('click', () => hideNp());

    lyricsBtn?.addEventListener('click', () => setNpView('lyrics'));
    document.getElementById('pb-queue-toggle')?.addEventListener('click', (e) => {
        e.preventDefault();
        if (window.__platform && window.__platform.isMobile && window.__mobileQueueSheet) {
            window.__mobileQueueSheet.open();
            return;
        }
        setNpView('queue');
    });

    if (ui.title) {
        const npObs = new MutationObserver(() => {
            if (!isNpVisible()) return;
            const activeView = document.querySelector('.np-view:not([style*="none"])')?.dataset.npView;
            if (activeView === 'info') updateNp();
            if (activeView === 'lyrics') { lastLyricsSongId = null; loadLyricsIntoNp(); }
            if (activeView === 'queue') renderQueueOverlay();
        });
        npObs.observe(ui.title, { childList: true, characterData: true, subtree: true });
    }
    audio.addEventListener('loadedmetadata', () => {
        if (sidePanelMode === 'lyrics') renderLyrics();
    });

    // ===== DEVICES PANEL =====
    const devicesBtn = document.getElementById('pb-devices');
    // Per-tab device id: sessionStorage isolates tabs of same origin so 2 windows
    // don't fight over one device record in active_devices.
    let deviceId = sessionStorage.getItem('doniix-device-id');
    if (!deviceId) {
        deviceId = 'dev_' + Math.random().toString(36).slice(2, 12) + Date.now().toString(36);
        sessionStorage.setItem('doniix-device-id', deviceId);
    }
    const deviceName = (() => {
        const ua = navigator.userAgent;
        if (/Doniixify-Desktop/i.test(ua)) {
            if (/Windows/i.test(ua)) return 'Desktop App (Windows)';
            if (/Mac|Darwin/i.test(ua)) return 'Desktop App (Mac)';
            if (/Linux/i.test(ua)) return 'Desktop App (Linux)';
            return 'Desktop App';
        }
        if (/Android/i.test(ua)) return 'Android phone';
        if (/iPhone|iPod/i.test(ua)) return 'iPhone';
        if (/iPad/i.test(ua)) return 'iPad';
        if (/Macintosh/i.test(ua)) return 'Mac browser';
        if (/Windows/i.test(ua)) return 'Windows browser';
        if (/Linux/i.test(ua)) return 'Linux browser';
        return 'Browser';
    })();

    let lastPlayingAt = 0;
    let claimNextHeartbeat = false;
    audio.addEventListener('playing', () => { lastPlayingAt = Date.now(); claimNextHeartbeat = true; if (window.__heartbeat) window.__heartbeat(); });
    audio.addEventListener('pause', () => { if (window.__heartbeat) window.__heartbeat(); });
    audio.addEventListener('loadedmetadata', () => { if (window.__heartbeat) window.__heartbeat(); });
    audio.addEventListener('timeupdate', () => { if (!audio.paused) lastPlayingAt = Date.now(); });

    let lastHeartbeatAt = Date.now();
    const heartbeat = async () => {
        if (!document.body.dataset.loggedIn && !document.querySelector('.player-bar')) return;
        try {
            const now = Date.now();
            const isReallyPaused = audio.paused && (now - lastPlayingAt) > 2000;
            const isPlaying = !isReallyPaused && currentSongId != null;
            const wantsForce = claimNextHeartbeat && isPlaying;
            claimNextHeartbeat = false;
            const playedDelta = isPlaying ? Math.min(60, Math.max(0, Math.round((now - lastHeartbeatAt) / 1000))) : 0;
            lastHeartbeatAt = now;
            const r = await fetch('/api/devices/heartbeat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    device_id: deviceId,
                    name: deviceName,
                    song_id: currentSongId,
                    playing: isPlaying,
                    position: Number(audio.currentTime) || 0,
                    force: wantsForce ? 1 : 0,
                    played_delta: playedDelta,
                }),
            });
            const data = await r.json();
            if (data.should_pause && !audio.paused) {
                console.warn('[hb] should_pause TRUE — moving to', data.taken_over_by, 'currentSong=', currentSongId);
                audio.pause();
            }
            updateOtherDevicePlaying(data.other_playing || null);
            if (Array.isArray(data.pending_commands) && data.pending_commands.length) {
                data.pending_commands.forEach(handleRemoteCommand);
            }
        } catch (e) {}
    };

    const handleRemoteCommand = (cmd) => {
        try {
            const click = (id) => { const el = document.getElementById(id); if (el) el.click(); };
            switch (cmd) {
                case 'play':
                    if (audio.paused) click('pb-play');
                    break;
                case 'pause':
                    if (!audio.paused) click('pb-play');
                    break;
                case 'toggle':
                    click('pb-play');
                    break;
                case 'next':
                    click('pb-next');
                    break;
                case 'prev':
                    click('pb-prev');
                    break;
                case 'like':
                    click('pb-favorite');
                    break;
                case 'volume_up': {
                    const v = Math.min(1, (audio.volume || 0) + 0.1);
                    audio.volume = v;
                    break;
                }
                case 'volume_down': {
                    const v = Math.max(0, (audio.volume || 0) - 0.1);
                    audio.volume = v;
                    break;
                }
            }
            console.log('[remote] executed:', cmd);
        } catch (e) { console.warn('[remote] failed:', cmd, e); }
    };

    let otherDeviceState = null;
    let otherDeviceTickAt = 0;

    const formatTime = (s) => {
        if (!isFinite(s) || s < 0) s = 0;
        const m = Math.floor(s / 60);
        const sec = String(Math.floor(s % 60)).padStart(2, '0');
        return `${m}:${sec}`;
    };

    const paintOtherDeviceTimeline = () => {
        if (!otherDeviceState) return;
        const seekFill = document.getElementById('pb-seek-fill');
        const timeCur = document.getElementById('pb-time-current');
        const timeTotal = document.getElementById('pb-time-total');
        let pos = otherDeviceState.position || 0;
        if (otherDeviceState.is_playing) {
            const elapsed = (Date.now() - otherDeviceTickAt) / 1000;
            pos = Math.min((otherDeviceState.duration || pos + elapsed), pos + elapsed);
        }
        const dur = otherDeviceState.duration || 0;
        if (seekFill && dur > 0) {
            const pct = Math.min(100, (pos / dur) * 100);
            seekFill.style.width = pct + '%';
            document.querySelector('.player-bar')?.style.setProperty('--pb-progress', pct + '%');
        }
        if (timeCur) timeCur.textContent = formatTime(pos);
        if (timeTotal && dur > 0) timeTotal.textContent = formatTime(dur);
    };

    const updateOtherDevicePlaying = (other) => {
        const titleEl = ui.title;
        const artistEl = ui.artist;
        if (!titleEl || !artistEl) return;

        const pbCoverImg = document.querySelector('.player-bar .pb-cover img') || document.querySelector('.player-bar .pb-cover')?.appendChild?.(Object.assign(document.createElement('img'), { alt: '' }));
        if (other && audio.paused) {
            const songTitle = other.song_title || 'Untitled';
            const songArtist = other.song_artist || '';
            const dev = other.device_name || 'another device';
            titleEl.textContent = songTitle;
            artistEl.textContent = songArtist + ' · on ' + dev;
            titleEl.dataset.otherDevice = '1';
            artistEl.dataset.otherDevice = '1';
            window.__currentTrackMeta = { title: songTitle, artist: songArtist, songId: other.song_id || null };
            titleEl.style.opacity = '0.7';
            artistEl.style.opacity = '0.7';
            if (artistEl.tagName === 'A' && songArtist) {
                artistEl.href = '/discover/' + encodeURIComponent(songArtist);
            }
            if (other.song_id && pbCoverImg) {
                const newSrc = location.origin + '/cover/' + Number(other.song_id);
                if (pbCoverImg.src !== newSrc) pbCoverImg.src = newSrc;
            }
            const npCovImg = document.getElementById('np-fs-cover');
            if (npCovImg && other.song_id) {
                const newSrc = location.origin + '/cover/' + Number(other.song_id);
                if (npCovImg.src !== newSrc) npCovImg.src = newSrc;
            }
            const npT = document.getElementById('np-fs-title');
            const npA = document.getElementById('np-fs-artist');
            if (npT) npT.textContent = songTitle;
            if (npA) npA.textContent = songArtist + ' · on ' + dev;
            try { if (typeof window.__forceSyncTitleMobile === 'function') window.__forceSyncTitleMobile(); } catch (_) {}
            try { if (typeof window.__npSyncMeta === 'function') window.__npSyncMeta(); } catch (_) {}
            otherDeviceState = other;
            otherDeviceTickAt = Date.now();
            paintOtherDeviceTimeline();
        } else if (titleEl.dataset.otherDevice === '1') {
            titleEl.dataset.otherDevice = '';
            artistEl.dataset.otherDevice = '';
            titleEl.style.opacity = '';
            artistEl.style.opacity = '';
            otherDeviceState = null;
            if (!currentSongId) {
                titleEl.textContent = '—';
                artistEl.textContent = '';
                const seekFill = document.getElementById('pb-seek-fill');
                const timeCur = document.getElementById('pb-time-current');
                const timeTotal = document.getElementById('pb-time-total');
                if (seekFill) seekFill.style.width = '0%';
                if (timeCur) timeCur.textContent = '0:00';
                if (timeTotal) timeTotal.textContent = '0:00';
            }
        } else if (other === null) {
            otherDeviceState = null;
        }
    };

    setInterval(() => {
        if (otherDeviceState && audio.paused) {
            paintOtherDeviceTimeline();
        }
    }, window.__platform.isTv ? 5000 : 1000);
    heartbeat();
    let heartbeatTimer = null;
    const scheduleHeartbeat = () => {
        const delay = (!audio.paused && currentSongId != null) ? 1000 : 2500;
        if (heartbeatTimer) clearTimeout(heartbeatTimer);
        heartbeatTimer = setTimeout(async () => {
            await heartbeat();
            scheduleHeartbeat();
        }, delay);
    };
    scheduleHeartbeat();

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            heartbeat().catch(() => {});
            scheduleHeartbeat();
        }
    });
    window.addEventListener('focus', () => {
        heartbeat().catch(() => {});
        scheduleHeartbeat();
    });
    window.addEventListener('online', () => {
        heartbeat().catch(() => {});
        scheduleHeartbeat();
    });

    const sendDisconnect = () => {
        try {
            const blob = new Blob([JSON.stringify({
                device_id: deviceId,
                name: deviceName,
                disconnect: 1,
            })], { type: 'application/json' });
            navigator.sendBeacon('/api/devices/heartbeat', blob);
        } catch (e) {}
    };
    window.addEventListener('pagehide', sendDisconnect);
    window.addEventListener('beforeunload', sendDisconnect);

    audio.addEventListener('error', () => {
        if (!audio.src) return;
        if (!audio.src.includes('/stream/')) {
            audio.removeAttribute('src');
            audio.load();
            return;
        }
        if (audio.error && (audio.error.code === 4 || audio.error.code === 2)) {
            console.warn('[audio] playback error code', audio.error.code, 'src=', audio.src);
            currentSongId = null;
            audio.removeAttribute('src');
            audio.load();
        }
    });
    window.__heartbeat = heartbeat;
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') heartbeat(); });
    window.addEventListener('focus', heartbeat);

    const deviceIconSvg = (kind) => {
        if (kind === 'phone') return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" x2="12.01" y1="18" y2="18"/></svg>';
        if (kind === 'tablet') return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><line x1="12" x2="12" y1="18" y2="18"/></svg>';
        return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>';
    };

    let devicesCache = null;
    const paintDevices = (devices) => {
        if (!devices.length) {
            sidePanelBody.innerHTML = '<div class="empty-panel">No other devices. Open Doniixify on your phone or another browser to see it here.</div>';
            return;
        }
        const html = devices.map(d => {
            const isThis = d.device_id === deviceId;
            const songInfo = d.song ? `${escapeAttr(d.song.title)} · ${escapeAttr(d.song.artist || '')}` : 'Idle';
            const status = d.playing ? `Playing · ${songInfo}` : songInfo;
            const canTransfer = !isThis && d.song;
            return `<div class="device-item${isThis ? ' this' : ''}${d.playing ? ' playing' : ''}" data-device="${escapeAttr(d.device_id)}">
                <div class="device-icon">${deviceIconSvg(d.kind)}</div>
                <div class="device-meta">
                    <div class="device-name">${escapeAttr(d.name)}${isThis ? ' <span class="device-this">this device</span>' : ''}</div>
                    <div class="device-status">${status}</div>
                </div>
                ${canTransfer ? `<button class="device-transfer" data-transfer-from="${escapeAttr(d.device_id)}" title="Pick up playback from this device">Take over</button>` : ''}
            </div>`;
        }).join('');
        sidePanelBody.innerHTML = `<div class="panel-section-label">Active (${devices.length})</div>${html}`;
        sidePanelBody.querySelectorAll('[data-transfer-from]').forEach(btn => {
            btn.addEventListener('click', async (ev) => {
                ev.preventDefault();
                ev.stopPropagation();
                const fromId = btn.dataset.transferFrom;
                btn.disabled = true; btn.textContent = 'Transferring…';
                // Claim user gesture for autoplay — play current (paused) audio so browser allows .play() later
                try {
                    audio.muted = true;
                    await audio.play().catch(() => {});
                } catch (e) {}
                try {
                    const r = await fetch('/api/devices/transfer', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ from_device_id: fromId, to_device_id: deviceId }) });
                    const data = await r.json();
                    if (data.song_id) {
                        radioSeedId = Number(data.song_id);
                        audio.muted = false;
                        loadSong(data.song_id, data.title || '—', data.artist || '', true);
                        const targetPos = Number(data.position) || 0;
                        if (targetPos > 0) {
                            const seekWhenReady = () => {
                                try { audio.currentTime = targetPos; } catch (e) {}
                            };
                            if (audio.readyState >= 1) seekWhenReady();
                            else audio.addEventListener('loadedmetadata', seekWhenReady, { once: true });
                        }
                        if (window.__heartbeat) window.__heartbeat();
                    } else {
                        audio.muted = false;
                        const fn = window.__logClientError;
                        if (fn) fn('transfer', 'Error with transfer: source device idle', 'from=' + fromId);
                    }
                } catch (e) {
                    audio.muted = false;
                    const fn = window.__logClientError;
                    if (fn) fn('transfer', 'Error with transfer: ' + (e && e.message ? e.message : 'unknown'), 'from=' + fromId);
                }
                btn.disabled = false; btn.textContent = 'Take over';
            });
        });
    };
    const renderDevices = async (silent = false) => {
        if (!silent && !devicesCache) {
            sidePanelBody.innerHTML = '<div class="empty-panel skeleton">Loading…</div>';
        }
        try {
            const res = await fetchWT('/api/devices/list', {}, 4000);
            const devices = await res.json();
            devicesCache = devices;
            paintDevices(devices);
        } catch (e) {
            if (!silent) sidePanelBody.innerHTML = '<div class="empty-panel">Could not load devices</div>';
        }
    };
    devicesBtn?.addEventListener('click', () => {
        try { document.querySelector('.app')?.classList.remove('now-playing-open'); } catch (_) {}
        try {
            const jm = document.getElementById('jam-modal');
            if (jm) jm.classList.remove('open');
            const mb = document.getElementById('modal-backdrop');
            if (mb) mb.classList.remove('open');
        } catch (_) {}
        openSidePanel('devices', 'Devices', () => renderDevices(false));
        if (sidePanelMode === 'devices') {
            sidePanelTimer = setInterval(() => renderDevices(true), window.__platform.isTv ? 10000 : 2000);
        }
    });
    window.__refreshDevices = () => renderDevices(true);

    // ===== JAM SESSION =====
    const jamBtn = document.getElementById('pb-jam');
    let jamState = null;
    let jamTimer = null;
    let jamSyncFromHost = false;

    const openJamModal = () => {
        modalBackdrop.classList.add('open');
        jamModal.classList.add('open');
        renderJamModal();
    };
    const renderJamSidePanel = () => {
        const body = document.getElementById('side-panel-body') || sidePanelBody;
        if (!body) return;
        if (!jamState || !jamState.code) {
            body.innerHTML = `<div style="text-align:center;padding:40px 0;color:var(--text-secondary)">Creating session…</div>`;
            return;
        }
        const link = `${location.origin}/?jam=${jamState.code}`;
        const participants = jamState.participants || [];
        const npTitle = (ui.title?.textContent || '').trim();
        const npArtist = (ui.artist?.textContent || '').split('·')[0].trim();
        const pbCoverImg = document.querySelector('.pb-cover img');
        let nowCoverId = currentSongId;
        if (!nowCoverId && pbCoverImg?.src) {
            const m = pbCoverImg.src.match(/\/cover\/(\d+)/);
            if (m) nowCoverId = Number(m[1]);
        }
        const hasNowPlaying = !!(nowCoverId || (npTitle && npTitle !== '—'));
        const upcoming = [];
        for (let i = queueIndex + 1; i < Math.min(queue.length, queueIndex + 20); i++) {
            upcoming.push(queue[i]);
        }
        body.innerHTML = `
            <div style="margin-bottom:24px">
                <div class="np-section-head" style="margin-bottom:10px">Share link</div>
                <div style="display:flex;gap:8px">
                    <input type="text" readonly value="${link.replace(/"/g, '&quot;')}" id="jam-link-input" style="flex:1;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:8px;padding:10px 12px;color:var(--text-primary);font-size:12px;font-family:'JetBrains Mono',monospace">
                    <button id="jam-copy-btn" class="btn" style="white-space:nowrap;padding:10px 16px">Copy link</button>
                </div>
            </div>
            <div style="margin-bottom:24px">
                <div class="np-section-head" style="margin-bottom:10px">Listeners (${participants.length})</div>
                <div>
                    ${participants.map(p => `
                        <div class="np-up-next" style="cursor:default">
                            <div class="np-up-next-cover" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--text-secondary);background:transparent;border:1px solid var(--border)">${(p.username || '?').charAt(0).toUpperCase()}</div>
                            <div class="np-up-next-info">
                                <div class="np-up-next-title">${(p.username || 'Unknown').replace(/</g, '&lt;')}${p.is_host ? ' · host' : ''}</div>
                            </div>
                        </div>
                    `).join('') || '<div style="color:var(--text-muted);font-size:13px;padding:8px">Waiting for listeners…</div>'}
                </div>
            </div>
            <div style="margin-bottom:24px">
                <div class="np-section-head" style="margin-bottom:10px">Now playing</div>
                ${hasNowPlaying ? `
                    <div class="np-up-next" style="cursor:default">
                        ${nowCoverId ? `<img class="np-up-next-cover" src="/cover/${nowCoverId}" onerror="this.style.opacity='0.3'">` : `<div class="np-up-next-cover"></div>`}
                        <div class="np-up-next-info">
                            <div class="np-up-next-title">${(npTitle || '—').replace(/</g, '&lt;')}</div>
                            <div class="np-up-next-artist">${npArtist.replace(/</g, '&lt;')}</div>
                        </div>
                    </div>
                ` : '<div style="color:var(--text-muted);font-size:13px;padding:8px">Nothing playing</div>'}
            </div>
            <div>
                <div class="np-section-head" style="margin-bottom:10px">Queue (${upcoming.length})</div>
                <div>
                    ${upcoming.map(r => {
                        const sid = r.dataset.songId;
                        return `<div class="np-up-next">
                            <img class="np-up-next-cover" src="/cover/${sid}" onerror="this.style.opacity='0.3'">
                            <div class="np-up-next-info">
                                <div class="np-up-next-title">${(r.dataset.title || '').replace(/</g, '&lt;')}</div>
                                <div class="np-up-next-artist">${(r.dataset.artist || '').replace(/</g, '&lt;')}</div>
                            </div>
                        </div>`;
                    }).join('') || '<div style="color:var(--text-muted);font-size:13px;padding:8px">Queue is empty</div>'}
                </div>
            </div>
        `;
        document.getElementById('jam-copy-btn')?.addEventListener('click', () => {
            navigator.clipboard?.writeText(link).then(() => showToast('Link copied'));
        });
    };

    jamBtn?.addEventListener('click', async () => {
        try { document.querySelector('.app')?.classList.remove('now-playing-open'); } catch (_) {}
        openSidePanel('jam', 'Jam', renderJamSidePanel);
        if (!jamState || !jamState.code) {
            try {
                const res = await fetch('/api/jam/create', { method: 'POST' });
                const data = await res.json();
                if (!res.ok) { showToast('Error: ' + (data.error || res.status)); return; }
                jamState = { code: data.code, is_host: true, host_id: -1, participants: [] };
                startJamPolling();
            } catch (e) { showToast('Network error'); }
        }
        renderJamSidePanel();
        const tick = () => { if (sidePanelMode === 'jam') renderJamSidePanel(); };
        if (sidePanelTimer) clearInterval(sidePanelTimer);
        sidePanelTimer = setInterval(tick, window.__platform.isTv ? 10000 : 3000);
    });

    const renderJamModal = () => {
        if (jamState) {
            jamModalBody.innerHTML = `
                <div class="jam-active-banner">
                    <span style="font-size:18px">●</span> You're in a session ${jamState.is_host ? '(host)' : ''}
                </div>
                <div class="jam-code-display">
                    <div style="color:var(--text-secondary);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em">Session code</div>
                    <div class="jam-code">${escapeAttr(jamState.code)}</div>
                    <button class="jam-code-copy" id="jam-copy"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg> Copy code</button>
                </div>
                <div class="jam-participants" id="jam-participants"></div>
                <button class="jam-btn" id="jam-leave" style="margin-top:16px;color:var(--danger);border-color:rgba(244,33,46,0.3)">Leave session</button>
            `;
            renderJamParticipants();
            document.getElementById('jam-copy').addEventListener('click', () => {
                navigator.clipboard?.writeText(jamState.code).then(() => showToast('Copied: ' + jamState.code));
            });
            document.getElementById('jam-leave').addEventListener('click', leaveJam);
            return;
        }
        jamModalBody.innerHTML = `
            <p style="color:var(--text-secondary);font-size:14px;margin:0 0 20px;text-align:center">
                Listen to music in sync with another user. The host controls playback, everyone else syncs automatically.
            </p>
            <div class="jam-actions">
                <button class="jam-btn primary" id="jam-create">Create session</button>
                <button class="jam-btn" id="jam-join-show">Join session</button>
            </div>
            <div id="jam-join-form" style="display:none;margin-top:16px">
                <input type="text" class="jam-input" id="jam-code-input" maxlength="8" placeholder="ENTER CODE" autocomplete="off">
                <button class="jam-btn primary" id="jam-join" style="width:100%">Join</button>
                <div class="jam-error" id="jam-error"></div>
            </div>
        `;
        document.getElementById('jam-create').addEventListener('click', createJam);
        document.getElementById('jam-join-show').addEventListener('click', () => {
            document.getElementById('jam-join-form').style.display = 'block';
            document.getElementById('jam-code-input').focus();
        });
        document.getElementById('jam-join').addEventListener('click', joinJam);
    };

    const renderJamListenersBar = () => {
        document.getElementById('jam-listeners-bar')?.remove();
    };

    const renderJamParticipants = () => {
        renderJamListenersBar();
        const el = document.getElementById('jam-participants');
        if (!el || !jamState) return;
        const parts = jamState.participants || [];
        if (!parts.length) {
            el.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:12px">No one has joined yet. Share the code with a friend.</div>';
            return;
        }
        el.innerHTML = parts.map(p => `
            <div class="jam-participant${p.id === jamState.host_id ? ' host' : ''}">
                <div class="jam-avatar">${escapeAttr((p.username || '?').slice(0, 1).toUpperCase())}</div>
                <div>
                    <div style="font-weight:600">${escapeAttr(p.username)} ${p.id === jamState.host_id ? '<span style="color:var(--primary);font-size:11px;font-weight:700">HOST</span>' : ''}</div>
                </div>
                <span class="jam-status">${p.online ? '● online' : '○ offline'}</span>
            </div>
        `).join('');
    };

    const createJam = async () => {
        try {
            const res = await fetch('/api/jam/create', { method: 'POST' });
            const data = await res.json();
            if (!res.ok) { showToast('Error: ' + (data.error || res.status)); return; }
            jamState = { code: data.code, is_host: true, host_id: -1, participants: [] };
            startJamPolling();
            renderJamModal();
            showToast('Session created: ' + data.code);
        } catch (e) { showToast('Network error'); }
    };

    const joinJam = async () => {
        const code = document.getElementById('jam-code-input').value.trim().toUpperCase();
        if (!/^[A-Z0-9]{4,8}$/.test(code)) {
            document.getElementById('jam-error').textContent = 'Enter a valid code (4-8 characters)';
            return;
        }
        try {
            const res = await fetch('/api/jam/join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code }),
            });
            const data = await res.json();
            if (!res.ok) {
                document.getElementById('jam-error').textContent = data.error || 'Error';
                return;
            }
            jamState = { code: data.code, is_host: data.host, host_id: -1, participants: [] };
            startJamPolling();
            renderJamModal();
            showToast('Joined session ' + data.code);
        } catch (e) { showToast('Network error'); }
    };

    const leaveJam = async () => {
        if (!jamState) return;
        const code = jamState.code;
        stopJamPolling();
        jamState = null;
        renderJamListenersBar();
        await fetch(`/api/jam/${code}/leave`, { method: 'POST' }).catch(() => {});
        renderJamModal();
        showToast('Left the session');
    };

    const startJamPolling = () => {
        stopJamPolling();
        pollJam();
        jamTimer = setInterval(pollJam, window.__platform.isTv ? 10000 : 2000);
    };
    const stopJamPolling = () => {
        if (jamTimer) { clearInterval(jamTimer); jamTimer = null; }
    };

    const pollJam = async () => {
        if (!jamState) return;
        try {
            const res = await fetchWT(`/api/jam/${jamState.code}/state`, {}, 4000);
            if (!res.ok) {
                if (res.status === 404) { jamState = null; stopJamPolling(); showToast('Session ended'); renderJamModal(); }
                return;
            }
            const data = await res.json();
            jamState = { ...jamState, ...data };
            renderJamParticipants();
            if (!data.is_host && data.song_id) {
                applyJamSync(data);
            }
            if (data.is_host) {
                pushJamState();
            }
        } catch (e) {}
    };

    const applyJamSync = (data) => {
        const targetSongId = Number(data.song_id);
        const targetPos = Number(data.position) || 0;
        const targetPaused = !!data.paused;
        jamSyncFromHost = true;
        try {
            if (currentSongId !== targetSongId) {
                const row = document.querySelector(`tr[data-song-id="${targetSongId}"]`);
                const title = row?.dataset.title || data.song_title || '';
                const artist = row?.dataset.artist || data.song_artist || '';
                loadSong(targetSongId, title, artist, !targetPaused);
                pendingResume = targetPos;
            } else if (Math.abs(audio.currentTime - targetPos) > 0.8) {
                audio.currentTime = targetPos;
            }
            if (targetPaused && !audio.paused) audio.pause();
            if (!targetPaused && audio.paused) audio.play().catch(() => {});
        } finally {
            setTimeout(() => { jamSyncFromHost = false; }, 300);
        }
    };

    const pushJamState = () => {
        if (!jamState || !jamState.is_host) return;
        fetch(`/api/jam/${jamState.code}/sync`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                song_id: currentSongId,
                position: audio.currentTime || 0,
                paused: audio.paused,
            }),
        }).catch(() => {});
    };

    // Restore jam session if was active before refresh
    try {
        const saved = JSON.parse(localStorage.getItem(__k('doniix-jam')) || 'null');
        if (saved && saved.code) {
            jamState = saved;
            startJamPolling();
        }
    } catch (e) {}
    window.addEventListener('beforeunload', () => {
        if (jamState) localStorage.setItem(__k('doniix-jam'), JSON.stringify(jamState));
        else localStorage.removeItem(__k('doniix-jam'));
    });

    // refresh queue / push jam on track changes
    audio.addEventListener('play', () => { if (sidePanelMode === 'queue') renderQueue(); if (jamState?.is_host) pushJamState(); heartbeat(); });
    audio.addEventListener('pause', () => { if (jamState?.is_host) pushJamState(); heartbeat(); });
    audio.addEventListener('seeked', () => { if (jamState?.is_host && !jamSyncFromHost) pushJamState(); });

    // ===== SPA NAVIGATION =====
    const rebindContent = function () {
        if (queue.length === 0) buildQueue();
        const inPlaylistView = !!document.querySelector('[data-playlist-view="1"]');
        document.querySelectorAll('tr[data-song-id]').forEach(tr => {
            if (tr.__bound) return;
            tr.__bound = true;
            if (inPlaylistView && tr.closest('[data-playlist-view="1"]')) {
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('.row-fav-btn') || e.target.closest('.row-play-btn')) return;
                    document.querySelectorAll('[data-playlist-view="1"] tr[data-song-id]').forEach(r => r.classList.remove('selected'));
                    tr.classList.add('selected');
                });
                tr.addEventListener('dblclick', (e) => {
                    if (e.target.closest('.row-fav-btn')) return;
                    const songId = Number(tr.dataset.songId);
                    if (currentSongId === songId) {
                        if (audio.paused) {
                            const p = audio.play();
                            if (p && typeof p.catch === 'function') p.catch(showAudioError);
                        } else {
                            audio.pause();
                        }
                    } else {
                        loadFromRow(tr, { userPick: true });
                    }
                });
            } else {
                tr.addEventListener('click', (e) => {
                    if (e.target.closest('.row-fav-btn')) return;
                    const songId = Number(tr.dataset.songId);
                    if (currentSongId === songId) {
                        if (audio.paused) {
                            const p = audio.play();
                            if (p && typeof p.catch === 'function') p.catch(showAudioError);
                        } else {
                            audio.pause();
                        }
                    } else {
                        loadFromRow(tr, { userPick: true });
                    }
                });
            }
        });
        document.querySelectorAll('.row-fav-btn').forEach(btn => {
            if (btn.__bound) return;
            btn.__bound = true;
            const songId = Number(btn.dataset.songId);
            if (favorites.has(songId)) btn.classList.add('active');
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleFavorite(songId);
                btn.classList.toggle('active', favorites.has(songId));
            });
        });
        document.querySelectorAll('.row-play-btn').forEach(btn => {
            if (btn.__bound) return;
            btn.__bound = true;
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                e.preventDefault();
                const tr = btn.closest('tr[data-song-id]');
                if (!tr) return;
                const songId = Number(tr.dataset.songId);
                if (currentSongId === songId) {
                    if (audio.paused) {
                        const p = audio.play();
                        if (p && typeof p.catch === 'function') p.catch(showAudioError);
                    } else {
                        audio.pause();
                    }
                } else {
                    loadFromRow(tr, { userPick: true });
                }
            });
        });
        const playAllBtn = document.getElementById('playlist-play-all');
        if (playAllBtn && !playAllBtn.__bound) {
            playAllBtn.__bound = true;
            playAllBtn.addEventListener('click', () => {
                const rows = document.querySelectorAll('[data-playlist-view="1"] tr[data-song-id]');
                if (rows.length) loadFromRow(rows[0], { userPick: true });
            });
        }
        if (currentSongId) highlightRow(currentSongId);
        setupImportPolling();
    };

    const surgicalPlaylistUpdate = async (playlistId) => {
        try {
            const res = await fetch('/playlist/' + playlistId, { headers: { 'X-SPA': '1' } });
            if (!res.ok) return;
            const html = await res.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const newView = doc.querySelector('[data-playlist-view]');
            const curView = document.querySelector('[data-playlist-view]');
            if (!newView) return;
            if (!curView) {
                const newScroll = doc.querySelector('.main-scroll');
                const mainScroll = document.querySelector('.main-scroll');
                if (newScroll && mainScroll) {
                    const saved = mainScroll.scrollTop;
                    mainScroll.innerHTML = newScroll.innerHTML;
                    mainScroll.scrollTop = saved;
                    rebindContent();
                }
                return;
            }
            const existingSongIds = new Set(
                [...curView.querySelectorAll('tr[data-song-id]')].map(tr => tr.dataset.songId)
            );
            const newRows = [...newView.querySelectorAll('tr[data-song-id]')];
            const tbody = curView.querySelector('.song-table tbody');
            let addedAny = false;
            if (tbody) {
                newRows.forEach(newTr => {
                    if (!existingSongIds.has(newTr.dataset.songId)) {
                        tbody.appendChild(newTr.cloneNode(true));
                        addedAny = true;
                    }
                });
            } else {
                const newTable = newView.querySelector('.song-table');
                if (newTable) {
                    curView.insertBefore(newTable.cloneNode(true), curView.firstChild);
                    addedAny = true;
                }
            }
            const newPending = newView.querySelector('.pending-tracks-section');
            const oldPending = curView.querySelector('.pending-tracks-section');
            if (newPending && oldPending) {
                const newPendingKeys = new Set(
                    [...newPending.querySelectorAll('.pending-track[data-key]')].map(el => el.dataset.key)
                );
                oldPending.querySelectorAll('.pending-track[data-key]').forEach(el => {
                    if (!newPendingKeys.has(el.dataset.key)) el.remove();
                });
                const countEl = oldPending.querySelector('[data-pending-count]');
                const newCountEl = newPending.querySelector('[data-pending-count]');
                if (countEl && newCountEl) countEl.textContent = newCountEl.textContent;
            } else if (!newPending && oldPending) {
                oldPending.remove();
            } else if (newPending && !oldPending) {
                curView.appendChild(newPending.cloneNode(true));
            }
            const newSubtitle = doc.querySelector('.page-subtitle');
            const curSubtitle = document.querySelector('.page-subtitle');
            if (newSubtitle && curSubtitle) curSubtitle.textContent = newSubtitle.textContent;
            if (addedAny && typeof rebindContent === 'function') {
                try { rebindContent(); } catch (e) {}
            }
        } catch (e) {}
    };

    const setupImportPolling = () => {
        if (window.__importPollInterval) {
            clearInterval(window.__importPollInterval);
            window.__importPollInterval = null;
        }
        const banner = document.querySelector('.import-banner[data-importing="1"]');
        if (!banner) return;
        const playlistId = banner.dataset.playlistId;
        let lastHave = Number(banner.dataset.have || 0);
        let lastTotal = 0;
        const tick = async () => {
            if (document.hidden) return;
            if (!document.querySelector('.import-banner[data-importing="1"][data-playlist-id="' + playlistId + '"]')) {
                clearInterval(window.__importPollInterval);
                window.__importPollInterval = null;
                return;
            }
            try {
                const r = await fetch('/api/playlists/' + playlistId + '/import-tracks');
                if (!r.ok) return;
                const d = await r.json();
                const currentHave = Number(d.have || 0);
                const currentTotal = Number(d.total || 0);
                const isDone = d.status === 'complete' || d.status === 'no_tracks';
                const isFailed = d.status === 'failed_editorial' || d.status === 'failed_fetch';
                const bannerSub = document.querySelector('.import-banner[data-importing="1"] .import-banner-sub');
                if (bannerSub) {
                    if (isFailed && d.error) {
                        bannerSub.textContent = d.error;
                    } else {
                        const missing = Math.max(currentTotal - currentHave, 0);
                        bannerSub.textContent = currentHave + ' of ' + (currentTotal || '?') + ' downloaded · ' + missing + ' pending · refreshes automatically';
                    }
                }
                const bannerTitle = document.querySelector('.import-banner[data-importing="1"] .import-banner-title');
                if (bannerTitle && d.status) {
                    if (isFailed) {
                        bannerTitle.textContent = 'Import failed';
                    } else {
                        const niceStatus = d.status.charAt(0).toUpperCase() + d.status.slice(1);
                        bannerTitle.textContent = 'Importing playlist · ' + niceStatus;
                    }
                }
                if (isFailed) {
                    const bannerEl = document.querySelector('.import-banner[data-importing="1"]');
                    if (bannerEl) {
                        bannerEl.dataset.importing = '0';
                        const spinner = bannerEl.querySelector('.import-banner-spinner');
                        if (spinner) spinner.remove();
                    }
                    clearInterval(window.__importPollInterval);
                    window.__importPollInterval = null;
                    return;
                }
                const totalPending = (d.tracks || []).filter(t => !t.have).length;
                const hasPendingSection = !!document.querySelector('.pending-tracks-section');
                if (totalPending > 0 && !hasPendingSection && location.pathname === '/playlist/' + playlistId) {
                    await surgicalPlaylistUpdate(playlistId);
                    return;
                }
                (d.tracks || []).forEach(tr => {
                    if (tr.have || !tr.key) return;
                    const row = document.querySelector('.pending-track[data-key="' + (window.CSS && CSS.escape ? CSS.escape(tr.key) : tr.key.replace(/"/g, '\\"')) + '"]');
                    if (!row) return;
                    const stageEl = row.querySelector('[data-stage]');
                    const barEl = row.querySelector('[data-progress]');
                    const stage = tr.stage || (tr.job_status === 'failed' ? 'failed' : 'queued');
                    const progress = Math.max(0, Math.min(100, Number(tr.progress || 0)));
                    if (stageEl) stageEl.textContent = progress > 0 ? stage + ' · ' + progress + '%' : stage;
                    if (barEl) barEl.style.width = progress + '%';
                    if (tr.job_status === 'failed') row.classList.add('pending-track--failed');
                });
                if (currentHave !== lastHave && location.pathname === '/playlist/' + playlistId) {
                    await surgicalPlaylistUpdate(playlistId);
                    lastHave = currentHave;
                    lastTotal = currentTotal;
                }
                if (isDone) {
                    clearInterval(window.__importPollInterval);
                    window.__importPollInterval = null;
                    refreshSidebarPlaylists();
                    if (location.pathname === '/playlist/' + playlistId) {
                        await surgicalPlaylistUpdate(playlistId);
                        const banner = document.querySelector('.import-banner[data-importing="1"]');
                        if (banner) banner.remove();
                    }
                }
            } catch (e) {}
        };
        setTimeout(tick, 800);
        window.__importPollInterval = setInterval(tick, 4000);
    };

    window.__navigate = (path, push = true, opts = {}) => navigate(path, push, opts);
    const __prefetched = new Set();
    const __isNavigableHref = (h) => h && h.startsWith('/') && !h.startsWith('/stream') && !h.startsWith('/cover') && !h.startsWith('/api') && !h.startsWith('/logout') && !h.startsWith('/login');
    const __prefetchPath = (path) => {
        if (!path || __prefetched.has(path)) return;
        __prefetched.add(path);
        const cleanPath = path.split('?')[0];
        fetch(path, { headers: { 'X-SPA': '1' } })
            .then(r => r.ok ? r.text() : null)
            .then(html => { if (html && window.__pageCache) window.__pageCache.set(cleanPath, { html, ts: Date.now() }); })
            .catch(() => { __prefetched.delete(path); });
    };
    document.addEventListener('mouseenter', (e) => {
        const t = e.target;
        if (!t || !t.closest) return;
        const a = t.closest('a[href^="/"]');
        if (a && __isNavigableHref(a.getAttribute('href'))) __prefetchPath(a.getAttribute('href'));
    }, true);
    document.addEventListener('touchstart', (e) => {
        const t = e.target;
        if (!t || !t.closest) return;
        const a = t.closest('a[href^="/"]');
        if (a && __isNavigableHref(a.getAttribute('href'))) __prefetchPath(a.getAttribute('href'));
    }, { passive: true, capture: true });
    const __mobileReorderPbButtons = () => {
        if (!window.__platform.isMobile) return;
        const fav = document.getElementById('pb-favorite');
        const dev = document.getElementById('pb-devices');
        const play = document.getElementById('pb-play');
        const controls = document.querySelector('.player-bar .pb-controls');
        const pbLeft = document.querySelector('.player-bar .pb-left');
        if (!fav || !play || !controls) return;
        if (dev) dev.style.setProperty('display', 'none', 'important');
        if (fav.parentNode !== controls || fav.nextSibling !== play) {
            controls.insertBefore(fav, play);
        }
        fav.style.setProperty('display', 'inline-flex', 'important');
        fav.style.setProperty('visibility', 'visible', 'important');
        fav.style.setProperty('opacity', '1', 'important');
        fav.style.setProperty('width', '36px', 'important');
        fav.style.setProperty('height', '36px', 'important');
        fav.style.setProperty('background', 'transparent', 'important');
        fav.style.setProperty('border', '0', 'important');
        fav.style.setProperty('padding', '0', 'important');
        fav.style.setProperty('margin', '0 4px 0 0', 'important');
        fav.style.setProperty('order', '0', 'important');
        const oldSpacer = pbLeft && pbLeft.querySelector('.pb-spacer-push');
        if (oldSpacer) oldSpacer.remove();
    };
    const __coverObserver = ('IntersectionObserver' in window) ? new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (!e.isIntersecting) return;
            const img = e.target;
            __coverObserver.unobserve(img);
            const real = img.dataset.coverSrc;
            if (real && img.src !== real) img.src = real;
        });
    }, { rootMargin: '200px 200px', threshold: 0.01 }) : null;
    const __optimizeCoverImg = (img) => {
        if (!img || img.dataset.coverOpt === '1') return;
        const src = img.getAttribute('src') || '';
        if (!src.includes('/cover/')) return;
        img.dataset.coverOpt = '1';
        if (!img.hasAttribute('decoding')) img.setAttribute('decoding', 'async');
        const rect = img.getBoundingClientRect();
        const inView = rect.top < window.innerHeight + 200 && rect.bottom > -200;
        const inHorizontalView = rect.left < window.innerWidth + 800 && rect.right > -800;
        if (inView && inHorizontalView) {
            img.setAttribute('loading', 'eager');
            img.setAttribute('fetchpriority', 'high');
        } else {
            img.setAttribute('loading', 'lazy');
            img.setAttribute('fetchpriority', 'low');
        }
        const sidMatch = src.match(/\/cover\/(\d+)/);
        const songId = sidMatch ? Number(sidMatch[1]) : null;
        const checkPlaceholder = () => {
            if (img.naturalWidth === 200 && img.naturalHeight === 200) {
                window.__handleMissingCover && window.__handleMissingCover(img);
            }
        };
        if (img.complete && img.naturalWidth > 0) checkPlaceholder();
        else img.addEventListener('load', checkPlaceholder, { once: true });
        if (!inView && __coverObserver) {
            img.dataset.coverSrc = src;
            img.src = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1" fill="%23222"/></svg>';
            __coverObserver.observe(img);
        }
    };
    const __scanCoverImgs = (root) => {
        const scope = root || document;
        scope.querySelectorAll('img[src*="/cover/"]').forEach(__optimizeCoverImg);
    };
    if ('MutationObserver' in window) {
        if (window.__coverMutObs) {
            try { window.__coverMutObs.disconnect(); } catch (_) {}
        }
        window.__coverMutObs = new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const n of m.addedNodes) {
                    if (n.nodeType !== 1) continue;
                    if (n.tagName === 'IMG') __optimizeCoverImg(n);
                    else __scanCoverImgs(n);
                }
            }
        });
        window.__coverMutObs.observe(document.body, { childList: true, subtree: true });
        window.addEventListener('pagehide', () => {
            try { window.__coverMutObs?.disconnect(); } catch (_) {}
            try { __coverObserver?.disconnect(); } catch (_) {}
        }, { once: true });
    }
    if (document.readyState !== 'loading') __scanCoverImgs();
    else document.addEventListener('DOMContentLoaded', () => __scanCoverImgs());

    __mobileReorderPbButtons();
    setTimeout(__mobileReorderPbButtons, 200);
    setTimeout(__mobileReorderPbButtons, 1000);
    window.addEventListener('resize', __mobileReorderPbButtons, { passive: true });

    const __warmCache = () => {
        ['/', '/library', '/search', '/favorites'].forEach(p => __prefetchPath(p));
        document.querySelectorAll('.sidebar-item[href^="/"]').forEach(a => {
            const h = a.getAttribute('href');
            if (__isNavigableHref(h)) __prefetchPath(h);
        });
        document.querySelectorAll('.mobile-bottom-nav a[href^="/"]').forEach(a => {
            const h = a.getAttribute('href');
            if (__isNavigableHref(h)) __prefetchPath(h);
        });
    };
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(__warmCache, 50);
    } else {
        document.addEventListener('DOMContentLoaded', () => setTimeout(__warmCache, 50));
    }
    const __titleRe = /<title[^>]*>([^<]*)<\/title>/i;
    const __applyHtml = (html, cleanPath, savedScroll) => {
        const scroll = document.querySelector('.main-scroll');
        if (!scroll) return false;
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const newMain = doc.querySelector('.main-scroll');
        if (!newMain) return false;
        const inner = newMain.innerHTML;
        const tm = html.match(__titleRe);
        if (tm) {
            window.__originalTitle = tm[1];
            const meta = window.__currentTrackMeta || {};
            const audio = document.querySelector('audio');
            if (meta.title && meta.artist) {
                document.title = meta.title + ' · ' + meta.artist + ' — ' + tm[1];
            } else {
                document.title = tm[1];
            }
        }
        scroll.innerHTML = inner;
        const playerBars = document.querySelectorAll('.player-bar');
        if (playerBars.length > 1) {
            for (let i = 1; i < playerBars.length; i++) playerBars[i].remove();
        }
        const sidePanels = document.querySelectorAll('.side-panel');
        if (sidePanels.length > 1) {
            for (let i = 1; i < sidePanels.length; i++) sidePanels[i].remove();
        }
        const npFs = document.querySelectorAll('#now-playing-fullscreen');
        if (npFs.length > 1) {
            for (let i = 1; i < npFs.length; i++) npFs[i].remove();
        }
        document.querySelectorAll('.sidebar-item').forEach(a => {
            a.classList.toggle('active', a.getAttribute('href') === cleanPath);
        });
        document.querySelectorAll('.mobile-bottom-nav a').forEach(a => {
            const href = a.getAttribute('href');
            let active = false;
            if (href === '/') active = (cleanPath === '/' || cleanPath === '');
            else if (href === '/library') active = (cleanPath.startsWith('/library') || cleanPath.startsWith('/favorites') || cleanPath.startsWith('/playlist'));
            else if (href) active = cleanPath.startsWith(href);
            a.classList.toggle('active', active);
        });
        scroll.scrollTop = savedScroll;
        scroll.querySelectorAll('script').forEach(old => {
            const s = document.createElement('script');
            if (old.src) s.src = old.src;
            else {
                const code = old.textContent || '';
                s.textContent = '(function(){try{' + code + '}catch(e){console.warn("SPA script error:",e);}})();';
            }
            try { old.replaceWith(s); } catch (e) { /* skip bad inline scripts */ }
        });
        rebindContent();
        return true;
    };
    const navigate = async function (path, push = true, opts = {}) {
        try {
            const cleanPath = path.split('?')[0];
            const cached = window.__pageCache && window.__pageCache.get(cleanPath);
            const scroll = document.querySelector('.main-scroll');
            const savedScroll = opts.preserveScroll && scroll ? scroll.scrollTop : 0;
            if (cached) {
                window.__viewTransition(() => __applyHtml(cached.html, cleanPath, savedScroll));
                if (push) history.pushState({ path }, '', path);
                return;
            }
            if (!opts.silent) document.body.classList.add('loading');
            const res = await fetch(path, { headers: { 'X-SPA': '1' } });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const html = await res.text();
            if (window.__pageCache) window.__pageCache.set(cleanPath, { html, ts: Date.now() });
            let ok = false;
            window.__viewTransition(() => { ok = __applyHtml(html, cleanPath, savedScroll); });
            if (!ok) { location.href = path; return; }
            if (push) history.pushState({ path }, '', path);
        } catch (e) {
            console.error('Navigation failed:', e);
            location.href = path;
        } finally {
            document.body.classList.remove('loading');
        }
    };

    document.addEventListener('click', (e) => {
        const link = e.target.closest('a[href^="/"]');
        if (!link) return;
        const href = link.getAttribute('href');
        if (!href || href.startsWith('/stream/') || href.startsWith('/cover/') || href.startsWith('/api/') || href.startsWith('/logout') || href.startsWith('/login')) return;
        if (link.target === '_blank' || e.ctrlKey || e.metaKey) return;
        e.preventDefault();
        navigate(href);
    });

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form || form.method.toLowerCase() !== 'get') return;
        const action = form.getAttribute('action') || location.pathname;
        if (!action.startsWith('/') || action.startsWith('/api/')) return;
        e.preventDefault();
        const params = new URLSearchParams(new FormData(form)).toString();
        navigate(action + (params ? '?' + params : ''));
    });

    window.addEventListener('popstate', (e) => {
        if (window.__npClosingViaHistory) {
            window.__npClosingViaHistory = false;
            return;
        }
        if (window.__sidePanelClosingViaHistory) {
            window.__sidePanelClosingViaHistory = false;
            return;
        }
        const isMobile = window.__platform.isMobile;
        if (isMobile) {
            const sp = document.getElementById('side-panel');
            if (sp && sp.classList.contains('open')) {
                if (window.__closeSidePanel) window.__closeSidePanel();
                else sp.classList.remove('open');
                return;
            }
            if (document.body.classList.contains('now-playing-open')) {
                document.body.classList.remove('now-playing-open');
                try { window.__npObserver?.disconnect(); window.__npObserver = null; } catch (_) {}
                return;
            }
        }
        navigate(location.pathname + location.search, false);
    });

    rebindContent();

    // ===== CONTEXT MENU =====
    let ctxMenu = null;
    let ctxBackdrop = null;
    const isMobileViewport = () => window.__platform.isMobile;
    const closeCtx = () => {
        if (ctxMenu) {
            const wasSheet = ctxMenu.classList.contains('mobile-sheet');
            ctxMenu.classList.remove('open');
            const m = ctxMenu;
            ctxMenu = null;
            setTimeout(() => { try { m.remove(); } catch (_) {} }, 220);
            if (wasSheet) document.body.classList.remove('ctx-sheet-open');
        }
        if (ctxBackdrop) {
            ctxBackdrop.classList.remove('open');
            const b = ctxBackdrop;
            ctxBackdrop = null;
            setTimeout(() => { try { b.remove(); } catch (_) {} }, 220);
        }
    };

    const openContextMenu = async (x, y, songId, title) => {
        closeCtx();
        const isFav = favorites.has(Number(songId));
        const playlistView = document.querySelector('[data-playlist-view="1"][data-playlist-id]');
        const currentPlaylistId = playlistView ? playlistView.dataset.playlistId : null;
        const mobileSheet = isMobileViewport();
        const row = document.querySelector(`tr[data-song-id="${songId}"]`);
        const artistId = row?.dataset.artistId;
        const albumId = row?.dataset.albumId;
        const songArtist = row?.dataset.artist || row?.querySelector('.row-artist')?.textContent?.trim() || '';
        const coverSrc = `/cover/${Number(songId)}`;
        if (mobileSheet) {
            ctxBackdrop = document.createElement('div');
            ctxBackdrop.className = 'ctx-backdrop';
            document.body.appendChild(ctxBackdrop);
            requestAnimationFrame(() => ctxBackdrop.classList.add('open'));
            ctxBackdrop.addEventListener('click', closeCtx);
        }
        ctxMenu = document.createElement('div');
        ctxMenu.className = 'ctx-menu' + (mobileSheet ? ' mobile-sheet' : '');
        const headerHtml = mobileSheet ? `
            <div class="ctx-sheet-grip"></div>
            <div class="ctx-sheet-header">
                <img class="ctx-sheet-cover" src="${coverSrc}" alt="" onerror="this.style.visibility='hidden'">
                <div class="ctx-sheet-meta">
                    <div class="ctx-sheet-title">${escapeHtmlJs(title || '')}</div>
                    <div class="ctx-sheet-artist">${escapeHtmlJs(songArtist)}</div>
                </div>
            </div>
        ` : '';
        const canShare = (typeof navigator.share === 'function');
        ctxMenu.innerHTML = headerHtml + `
            <div class="ctx-item" data-action="play">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>
                Play
            </div>
            <div class="ctx-item" data-action="queue">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15V6"/><path d="M18.5 18a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/><path d="M12 12H3"/><path d="M16 6H3"/><path d="M12 18H3"/></svg>
                Add to queue
            </div>
            ${canShare ? `<div class="ctx-item" data-action="share">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                Share
            </div>` : ''}
            <div class="ctx-item" data-action="tag">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41L13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                Tag…
            </div>
            <div class="ctx-item" data-action="rate">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Rate &amp; note…
            </div>
            <div class="ctx-item" data-action="jam">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Start Jam
            </div>
            <div class="ctx-divider"></div>
            <div class="ctx-item" data-action="fav">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="${isFav ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                ${isFav ? 'Remove from favorites' : 'Save to favorites'}
            </div>
            <div class="ctx-item" data-action="radio">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9"/><path d="M7.8 16.2c-2.3-2.3-2.3-6.1 0-8.5"/><circle cx="12" cy="12" r="2"/><path d="M16.2 7.8c2.3 2.3 2.3 6.1 0 8.5"/><path d="M19.1 4.9C23 8.8 23 15.1 19.1 19"/></svg>
                Start radio from this song
            </div>
            <div class="ctx-item" data-action="goto-radio">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                Go to song radio
            </div>
            <div class="ctx-divider"></div>
            ${artistId ? `<div class="ctx-item" data-action="go-artist">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg>
                Go to artist
            </div>` : ''}
            ${albumId ? `<div class="ctx-item" data-action="go-album">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg>
                Go to album
            </div>` : ''}
            <div class="ctx-divider"></div>
            <div class="ctx-item ctx-submenu">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Add to playlist
                <span class="ctx-submenu-arrow">›</span>
                <div class="ctx-submenu-panel" id="ctx-playlists"><div class="ctx-empty">Loading…</div></div>
            </div>
            ${currentPlaylistId ? `
            <div class="ctx-divider"></div>
            <div class="ctx-item" data-action="remove-from-playlist" style="color:var(--danger)">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Remove from this playlist
            </div>` : ''}
            ${window.__isAdmin ? `
            <div class="ctx-divider"></div>
            <div class="ctx-item" data-action="admin-delete" style="color:var(--danger)">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                Delete from hosting
            </div>` : ''}
        `;
        document.body.appendChild(ctxMenu);

        if (mobileSheet) {
            document.body.classList.add('ctx-sheet-open');
            requestAnimationFrame(() => ctxMenu.classList.add('open'));
        } else {
            ctxMenu.classList.add('open');
            const rect = ctxMenu.getBoundingClientRect();
            const px = Math.min(x, window.innerWidth - rect.width - 8);
            const py = Math.min(y, window.innerHeight - rect.height - 8);
            ctxMenu.style.left = px + 'px';
            ctxMenu.style.top = py + 'px';
        }

        ctxMenu.querySelector('[data-action="play"]').addEventListener('click', () => {
            const r = document.querySelector(`tr[data-song-id="${songId}"]`);
            if (r) loadFromRow(r, { userPick: true });
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="queue"]')?.addEventListener('click', () => {
            const r = document.querySelector(`tr[data-song-id="${songId}"]`);
            if (r) { const clone = r.cloneNode(true); clone.dataset.queued = '1'; queue.splice(queueIndex + 1, 0, clone); showToast('Added to queue'); }
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="rate"]')?.addEventListener('click', async () => {
            closeCtx();
            const existing = await fetch('/api/song/rating?song_id=' + Number(songId)).then(r => r.json()).catch(() => ({}));
            const cur = existing?.rating || 0;
            const curNote = existing?.note || '';
            const modal = document.createElement('div');
            modal.style.cssText = 'position:fixed;inset:0;z-index:9700;background:rgba(0,0,0,0.6);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px)';
            modal.innerHTML = '<div style="background:#16181c;border:1px solid var(--border);border-radius:14px;padding:24px;min-width:320px;max-width:90vw"><h3 style="margin:0 0 4px;font-size:16px;font-weight:700">Rate this track</h3><div style="color:var(--text-muted);font-size:13px;margin-bottom:16px">' + escapeHtmlJs(title || '') + '</div><div id="rate-stars" style="display:flex;gap:6px;justify-content:center;margin-bottom:16px;font-size:32px"></div><textarea id="rate-note" placeholder="Optional note…" style="width:100%;background:transparent;border:1px solid var(--border);color:#fff;border-radius:8px;padding:10px;font-family:inherit;font-size:13px;min-height:60px;resize:vertical">' + escapeHtmlJs(curNote) + '</textarea><div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end"><button id="rate-cancel" class="btn" style="background:transparent;border:1px solid var(--border);color:var(--text-primary)">Cancel</button><button id="rate-save" class="btn" style="background:rgb(var(--accent-rgb,30,215,96));color:#000;border:0">Save</button></div></div>';
            document.body.appendChild(modal);
            const starsEl = modal.querySelector('#rate-stars');
            let selected = cur;
            const render = () => {
                starsEl.innerHTML = [1,2,3,4,5].map(i => '<span data-r="' + i + '" style="cursor:pointer;color:' + (i <= selected ? 'rgb(var(--accent-rgb,30,215,96))' : 'rgba(255,255,255,0.2)') + ';transition:transform 0.1s ease">' + (i <= selected ? '★' : '☆') + '</span>').join('');
                starsEl.querySelectorAll('[data-r]').forEach(s => {
                    s.addEventListener('mouseenter', () => s.style.transform = 'scale(1.2)');
                    s.addEventListener('mouseleave', () => s.style.transform = '');
                    s.addEventListener('click', () => { selected = parseInt(s.dataset.r, 10) === selected ? 0 : parseInt(s.dataset.r, 10); render(); });
                });
            };
            render();
            modal.querySelector('#rate-cancel').addEventListener('click', () => modal.remove());
            modal.querySelector('#rate-save').addEventListener('click', async () => {
                const fd = new FormData();
                fd.append('song_id', String(Number(songId)));
                fd.append('rating', String(selected));
                fd.append('note', modal.querySelector('#rate-note').value);
                await fetch('/api/song/rating', { method: 'POST', body: fd });
                modal.remove();
                showToast(selected > 0 ? 'Rated ' + selected + '★' : 'Rating cleared');
            });
            modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
        });
        ctxMenu.querySelector('[data-action="tag"]')?.addEventListener('click', async () => {
            closeCtx();
            const existing = await fetch('/api/tags/list?song_id=' + Number(songId)).then(r => r.json()).catch(() => null);
            const current = (existing?.tags || []).join(', ');
            const newTags = await dialogPrompt(
                'Tags (comma-separated, e.g. "chill, indie, 2024"):',
                { title: 'Tag track', defaultValue: current, placeholder: 'chill, indie, road trip', okText: 'Save' }
            );
            if (newTags === null) return;
            const desired = new Set(newTags.split(',').map(s => s.toLowerCase().trim().replace(/[^a-z0-9 \-]/g, '')).filter(Boolean));
            const present = new Set((existing?.tags || []).map(s => s.toLowerCase()));
            const toAdd = [...desired].filter(t => !present.has(t));
            const toRm = [...present].filter(t => !desired.has(t));
            const ops = [];
            toAdd.forEach(t => {
                const fd = new FormData();
                fd.append('song_id', String(Number(songId)));
                fd.append('tag', t);
                ops.push(fetch('/api/tags/add', { method: 'POST', body: fd }));
            });
            toRm.forEach(t => {
                const fd = new FormData();
                fd.append('song_id', String(Number(songId)));
                fd.append('tag', t);
                ops.push(fetch('/api/tags/remove', { method: 'POST', body: fd }));
            });
            await Promise.all(ops);
            showToast(toAdd.length + toRm.length === 0 ? 'No changes' : 'Tags saved (' + desired.size + ')');
        });
        ctxMenu.querySelector('[data-action="share"]')?.addEventListener('click', async () => {
            closeCtx();
            const row = document.querySelector(`tr[data-song-id="${songId}"]`);
            const songTitle = row?.dataset?.title || title || 'Track';
            const songArtistName = row?.dataset?.artist || '';
            const url = location.origin + '/play/' + Number(songId);
            try {
                await navigator.share({
                    title: songTitle,
                    text: songArtistName ? songTitle + ' — ' + songArtistName : songTitle,
                    url,
                });
            } catch (err) {
                if (err && err.name !== 'AbortError') {
                    try { await navigator.clipboard.writeText(url); showToast('Link copied to clipboard'); }
                    catch (_) { showToast('Could not share'); }
                }
            }
        });
        ctxMenu.querySelector('[data-action="jam"]')?.addEventListener('click', () => {
            const r = document.querySelector(`tr[data-song-id="${songId}"]`);
            if (r) loadFromRow(r, { userPick: true });
            document.getElementById('pb-jam')?.click();
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="go-artist"]')?.addEventListener('click', () => {
            if (artistId) navigate('/artist/' + artistId);
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="go-album"]')?.addEventListener('click', () => {
            if (albumId) navigate('/album/' + albumId);
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="fav"]').addEventListener('click', () => {
            toggleFavorite(songId);
            const rowBtn = document.querySelector(`tr[data-song-id="${songId}"] .row-fav-btn`);
            if (rowBtn) rowBtn.classList.toggle('active', favorites.has(Number(songId)));
            closeCtx();
        });
        ctxMenu.querySelector('[data-action="radio"]').addEventListener('click', () => {
            closeCtx();
            const row = document.querySelector(`tr[data-song-id="${songId}"]`);
            if (row) loadFromRow(row, { userPick: true });
            radioSeedId = Number(songId);
            radioEnabled = true;
            playMode = 'smart';
            try { localStorage.setItem(__k('doniix-radio'), '1'); localStorage.setItem(__k('doniix-playmode'), 'smart'); } catch (e) {}
            /* silent radio start */
            extendQueueWithRadio(Number(songId));
        });
        ctxMenu.querySelector('[data-action="goto-radio"]')?.addEventListener('click', () => {
            closeCtx();
            navigate('/radio/' + Number(songId));
        });
        const removeItem = ctxMenu.querySelector('[data-action="remove-from-playlist"]');
        if (removeItem && currentPlaylistId) {
            removeItem.addEventListener('click', async () => {
                closeCtx();
                try {
                    const r = await fetch(`/api/playlists/${currentPlaylistId}/songs/${Number(songId)}/remove`, { method: 'POST' });
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    const row = document.querySelector(`[data-playlist-view="1"] tr[data-song-id="${songId}"]`);
                    if (row) row.remove();
                    const qi = queue.findIndex(t => Number(t.dataset.songId) === Number(songId));
                    if (qi >= 0) queue.splice(qi, 1);
                    toast('Removed from playlist');
                    refreshSidebarPlaylists();
                } catch (e) {
                    toast('Remove failed: ' + e.message);
                }
            });
        }
        const adminDelItem = ctxMenu.querySelector('[data-action="admin-delete"]');
        if (adminDelItem) {
            adminDelItem.addEventListener('click', async () => {
                closeCtx();
                const ok = await dialogConfirm(`Permanently delete "${title}" from hosting? This removes the audio file from the server.`, { title: 'Delete from hosting', okText: 'Delete', danger: true });
                if (!ok) return;
                try {
                    const r = await fetch(`/api/admin/songs/${Number(songId)}/delete`, { method: 'POST' });
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    document.querySelectorAll(`tr[data-song-id="${songId}"]`).forEach(el => el.remove());
                    document.querySelectorAll(`img[src*="/cover/${songId}"]`).forEach(el => el.remove());
                    const qi = queue.findIndex(t => Number(t.dataset.songId) === Number(songId));
                    if (qi >= 0) queue.splice(qi, 1);
                    if (Number(currentSongId) === Number(songId)) {
                        try { audio.pause(); audio.src = ''; } catch (e) {}
                    }
                    toast('Deleted from hosting');
                } catch (e) {
                    toast('Delete failed: ' + e.message);
                }
            });
        }

        // load playlists
        try {
            const res = await fetch('/api/playlists');
            const playlists = await res.json();
            const panel = document.getElementById('ctx-playlists');
            if (!panel) return;
            let html = `<div class="ctx-item" data-new="1"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>New playlist</div>`;
            if (playlists.length) {
                html += '<div class="ctx-divider"></div>';
                playlists.forEach(p => {
                    html += `<div class="ctx-item" data-pl="${p.id}">${escapeHtmlJs(p.name)} <span style="margin-left:auto;color:var(--text-muted);font-size:12px">${p.song_count}</span></div>`;
                });
            }
            panel.innerHTML = html;
            panel.querySelector('[data-new]').addEventListener('click', async () => {
                closeCtx();
                const name = await dialogPrompt('Name for the new playlist:', { title: 'New playlist', placeholder: 'My playlist', okText: 'Create' });
                if (!name) return;
                await fetch('/api/playlists/create', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name, song_id: Number(songId) }) });
                toast('Playlist "' + name + '" created');
                refreshSidebarPlaylists();
            });
            panel.querySelectorAll('[data-pl]').forEach(el => {
                el.addEventListener('click', async () => {
                    await fetch(`/api/playlists/${el.dataset.pl}/add`, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ song_id: Number(songId) }) });
                    closeCtx();
                    toast('Added to playlist');
                });
            });
        } catch (e) {
            const panel = document.getElementById('ctx-playlists');
            if (panel) panel.innerHTML = '<div class="ctx-empty">Loading error</div>';
        }

        if (mobileSheet) {
            const submenuRoot = ctxMenu.querySelector('.ctx-submenu');
            if (submenuRoot) {
                submenuRoot.addEventListener('click', (ev) => {
                    if (ev.target.closest('.ctx-submenu-panel')) return;
                    ev.stopPropagation();
                    submenuRoot.classList.toggle('open-sub');
                });
            }
        }
    };

    const escapeHtmlJs = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const refreshSidebarPlaylists = async () => {
        try {
            const res = await fetch('/api/playlists');
            const playlists = await res.json();
            const list = document.getElementById('sidebar-playlists-list');
            if (!list) return;
            list.style.display = playlists.length ? '' : 'none';
            list.innerHTML = playlists.map(p =>
                `<a class="sidebar-item" href="/playlist/${p.id}"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg><span>${escapeHtmlJs(p.name)}</span></a>`
            ).join('');
        } catch (e) {}
    };
    window.__refreshSidebarPlaylists = refreshSidebarPlaylists;

    const toast = (msg) => window.__showToast ? window.__showToast(msg) : dialogAlert(msg);

    const logClientError = (kind, message, context) => {
        try { console.warn('[client/' + kind + ']', message, context || ''); } catch (e) {}
        try {
            fetch('/api/logs/client', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind: String(kind || 'client'), message: String(message || ''), context: String(context || '') }),
                keepalive: true,
            }).catch(() => {});
        } catch (e) {}
    };
    window.__logClientError = logClientError;

    // ===== IMPORT PLAYLIST MODAL =====
    let importSource = null;
    const renderImportModal = () => {
        if (!importModalBody) return;
        importSource = null;
        importSource = 'spotify';
        importModalBody.innerHTML = `
            <form class="import-form" id="import-form">
                <label class="field-label" for="import-url">Spotify URL</label>
                <input type="url" id="import-url" class="field-input" required placeholder="https://open.spotify.com/playlist/...">
                <div class="import-status" id="import-status"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn">Import</button>
                </div>
            </form>
        `;
        importModalBody.querySelector('[data-modal-close]')?.addEventListener('click', closeModal);
        importModalBody.querySelector('#import-url').focus();
        importModalBody.querySelector('#import-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const url = importModalBody.querySelector('#import-url').value.trim();
            const status = importModalBody.querySelector('#import-status');
            const submitBtn = importModalBody.querySelector('#import-form button[type=submit]');
            if (!url) return;
            if (!/^https?:\/\/(open\.)?spotify\.com\/(playlist|album|artist|track)\//i.test(url)) {
                status.textContent = 'Only Spotify URLs are supported (playlist / album / artist / track)';
                status.className = 'import-status error';
                return;
            }
            status.textContent = 'Creating playlist…';
            status.className = 'import-status';
            submitBtn.disabled = true;

            const findLatestPlaylist = async () => {
                try {
                    const r = await fetch('/api/playlists');
                    if (!r.ok) return null;
                    const list = await r.json();
                    if (!Array.isArray(list) || !list.length) return null;
                    return list.reduce((a, b) => ((b.id || 0) > (a.id || 0) ? b : a));
                } catch (e) { return null; }
            };
            const optimisticOpen = async (toastMsg) => {
                status.textContent = 'Checking sidebar for new playlist…';
                await new Promise(r => setTimeout(r, 1200));
                const latest = await findLatestPlaylist();
                if (latest && latest.id) {
                    toast(toastMsg);
                    closeModal();
                    document.body.classList.remove('loading');
                    location.href = '/playlist/' + latest.id;
                } else {
                    status.textContent = 'Could not confirm — refresh and check sidebar';
                    status.className = 'import-status error';
                    submitBtn.disabled = false;
                }
            };

            const ctrl = new AbortController();
            const timeoutId = setTimeout(() => ctrl.abort(), 8000);
            try {
                const res = await fetch('/api/playlists/import', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ url }),
                    signal: ctrl.signal,
                });
                clearTimeout(timeoutId);
                if (!res.ok) {
                    await optimisticOpen('Playlist may have been created — opening…');
                    return;
                }
                const data = await res.json();
                if (!data.id) {
                    await optimisticOpen('Playlist may have been created — opening…');
                    return;
                }
                const msg = data.existing
                    ? 'Playlist "' + data.name + '" already imported — opening existing'
                    : 'Playlist "' + data.name + '" created — downloading in background';
                toast(msg);
                closeModal();
                document.body.classList.remove('loading');
                location.href = '/playlist/' + data.id;
            } catch (err) {
                clearTimeout(timeoutId);
                if (err.name === 'AbortError') {
                    await optimisticOpen('Server slow — opening playlist anyway…');
                } else {
                    await optimisticOpen('Network hiccup — checking if created…');
                }
            }
        });
    };

    document.getElementById('sidebar-import-btn')?.addEventListener('click', () => {
        renderImportModal();
        modalBackdrop?.classList.add('open');
        importModal?.classList.add('open');
    });

    document.addEventListener('contextmenu', (e) => {
        const sidebarPl = e.target.closest('a.sidebar-item[href^="/playlist/"]');
        if (sidebarPl) {
            e.preventDefault();
            const m = sidebarPl.getAttribute('href').match(/^\/playlist\/(\d+)/);
            if (m) openPlaylistContextMenu(e.clientX, e.clientY, Number(m[1]), sidebarPl.textContent.trim());
            return;
        }
        const row = e.target.closest('tr[data-song-id]');
        if (!row) return;
        e.preventDefault();
        openContextMenu(e.clientX, e.clientY, row.dataset.songId, row.dataset.title);
    });

    const openPlaylistContextMenu = (x, y, plId, plName) => {
        closeCtx();
        ctxMenu = document.createElement('div');
        ctxMenu.className = 'ctx-menu open';
        const plCanShare = typeof navigator.share === 'function';
        ctxMenu.innerHTML = `
            <div class="ctx-item" data-act="open"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>Open</div>
            <div class="ctx-item" data-act="play"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><polygon points="6 3 20 12 6 21 6 3"/></svg>Play all</div>
            ${plCanShare ? `<div class="ctx-item" data-act="share"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>Share playlist</div>` : ''}
            <div class="ctx-divider"></div>
            <div class="ctx-item" data-act="rename"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Rename</div>
            <div class="ctx-item" data-act="delete" style="color:var(--danger)"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/></svg>Delete</div>
        `;
        document.body.appendChild(ctxMenu);
        const rect = ctxMenu.getBoundingClientRect();
        ctxMenu.style.left = Math.min(x, window.innerWidth - rect.width - 8) + 'px';
        ctxMenu.style.top = Math.min(y, window.innerHeight - rect.height - 8) + 'px';

        ctxMenu.querySelector('[data-act="open"]').addEventListener('click', () => { closeCtx(); navigate('/playlist/' + plId); });
        ctxMenu.querySelector('[data-act="play"]').addEventListener('click', () => { closeCtx(); playPlaylist(plId); });
        ctxMenu.querySelector('[data-act="share"]')?.addEventListener('click', async () => {
            closeCtx();
            try {
                const fd = new FormData();
                fd.append('playlist_id', String(plId));
                const r = await fetch('/api/playlists/share', { method: 'POST', body: fd });
                const d = await r.json();
                if (!d.url) { toast('Share failed'); return; }
                try {
                    await navigator.share({ title: plName, text: 'Playlist: ' + plName, url: d.url });
                } catch (err) {
                    if (err && err.name !== 'AbortError') {
                        try { await navigator.clipboard.writeText(d.url); toast('Link copied'); }
                        catch (_) { toast(d.url); }
                    }
                }
            } catch (_) { toast('Share failed'); }
        });
        ctxMenu.querySelector('[data-act="rename"]').addEventListener('click', async () => {
            closeCtx();
            const newName = await dialogPrompt('New name:', { title: 'Rename playlist', defaultValue: plName, okText: 'Rename' });
            if (!newName || newName === plName) return;
            const res = await fetch('/api/playlists/' + plId + '/rename', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ name: newName }) });
            if (res.ok) { toast('Renamed'); refreshSidebarPlaylists(); }
            else toast('Rename failed');
        });
        ctxMenu.querySelector('[data-act="delete"]').addEventListener('click', async () => {
            closeCtx();
            const ok = await dialogConfirm(`Delete playlist "${plName}"? This cannot be undone.`, { title: 'Delete playlist', okText: 'Delete', danger: true });
            if (!ok) return;
            const fd = new FormData();
            const res = await fetch('/playlist/' + plId + '/delete', { method: 'POST', body: fd });
            if (res.ok || res.redirected) { toast('Deleted'); refreshSidebarPlaylists(); if (location.pathname === '/playlist/' + plId) navigate('/'); }
            else toast('Delete failed');
        });
    };
    document.addEventListener('click', (e) => {
        if (ctxMenu && !e.target.closest('.ctx-menu') && !e.target.closest('.ctx-backdrop')) closeCtx();
    });
    document.addEventListener('scroll', (e) => {
        if (!ctxMenu) return;
        if (ctxMenu.classList.contains('mobile-sheet')) {
            if (e.target && (e.target === ctxMenu || (e.target.contains && e.target.contains(ctxMenu)) || (ctxMenu.contains && ctxMenu.contains(e.target)))) return;
        }
        closeCtx();
    }, true);

    let touchTimer = null;
    let touchStartXY = null;
    document.addEventListener('touchstart', (e) => {
        const sidebarPl = e.target.closest('a.sidebar-item[href^="/playlist/"]');
        if (sidebarPl) {
            touchStartXY = { x: e.touches[0].clientX, y: e.touches[0].clientY };
            const m = sidebarPl.getAttribute('href').match(/^\/playlist\/(\d+)/);
            if (!m) return;
            const plId = Number(m[1]);
            const plName = sidebarPl.textContent.trim();
            touchTimer = setTimeout(() => {
                openPlaylistContextMenu(touchStartXY.x, touchStartXY.y, plId, plName);
                touchTimer = null;
            }, 500);
            return;
        }
        const row = e.target.closest('tr[data-song-id]');
        if (!row) return;
        touchStartXY = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        touchTimer = setTimeout(() => {
            openContextMenu(touchStartXY.x, touchStartXY.y, row.dataset.songId, row.dataset.title);
            touchTimer = null;
        }, 500);
    }, { passive: true });
    document.addEventListener('touchmove', (e) => {
        if (!touchTimer || !touchStartXY) return;
        const dx = Math.abs(e.touches[0].clientX - touchStartXY.x);
        const dy = Math.abs(e.touches[0].clientY - touchStartXY.y);
        if (dx > 10 || dy > 10) { clearTimeout(touchTimer); touchTimer = null; }
    }, { passive: true });
    document.addEventListener('touchend', () => {
        if (touchTimer) { clearTimeout(touchTimer); touchTimer = null; }
    });

    let activePlaylistId = null;

    document.addEventListener('dblclick', (e) => {
        const sidebarPl = e.target.closest('a.sidebar-item[href^="/playlist/"]');
        if (!sidebarPl) return;
        e.preventDefault();
        const m = sidebarPl.getAttribute('href').match(/^\/playlist\/(\d+)/);
        if (!m) return;
        const clickedId = Number(m[1]);
        if (activePlaylistId === clickedId && currentSongId) {
            if (audio.paused) {
                const p = audio.play();
                if (p && typeof p.catch === 'function') p.catch(showAudioError);
            } else {
                audio.pause();
            }
            return;
        }
        playPlaylist(clickedId);
    });

    const playPlaylist = async (id) => {
        try {
            const res = await fetch('/api/playlists/' + id + '/songs');
            if (!res.ok) return;
            const songs = await res.json();
            if (!songs.length) return;
            queue.length = 0;
            songs.forEach(s => {
                const fake = document.createElement('tr');
                fake.dataset.songId = s.id;
                fake.dataset.title = s.title;
                fake.dataset.artist = s.artist_name || '';
                queue.push(fake);
            });
            queueIndex = 0;
            activePlaylistId = id;
            loadFromRow(queue[0], { userPick: true });
        } catch (e) {}
    };

    window.doniixify = { audio, queue, loadSong, toggleFavorite, navigate };

    // ===== AUDIO SETTINGS (EQ, normalize, volume, crossfade) =====
    const AUDIO_K = __k('doniix-audio-settings');
    const audioDefaults = {
        quality: '192', volume: 80, normalize: false,
        automix: false, crossfade: 3,
        eq_enabled: false,
        eq: { 60: 0, 250: 0, 1000: 0, 4000: 0, 12000: 0 }
    };
    let audioSettings = audioDefaults;
    try { audioSettings = Object.assign({}, audioDefaults, JSON.parse(localStorage.getItem(AUDIO_K) || '{}')); } catch (e) {}

    let ctx = null, srcNode = null, gainNode = null, compNode = null, eqNodes = {};

    const setupAudioGraph = () => {
        if (ctx) return;
        try {
            ctx = new (window.AudioContext || window.webkitAudioContext)();
            srcNode = ctx.createMediaElementSource(audio);
            window.__audioCtx = ctx;
            window.__audioSrcNode = srcNode;
            const bands = [60, 250, 1000, 4000, 12000];
            const filters = bands.map((freq, i) => {
                const f = ctx.createBiquadFilter();
                f.type = i === 0 ? 'lowshelf' : (i === bands.length - 1 ? 'highshelf' : 'peaking');
                f.frequency.value = freq;
                f.Q.value = 1;
                f.gain.value = audioSettings.eq_enabled ? (audioSettings.eq[freq] || 0) : 0;
                eqNodes[freq] = f;
                return f;
            });
            compNode = ctx.createDynamicsCompressor();
            compNode.threshold.value = -24;
            compNode.knee.value = 30;
            compNode.ratio.value = 4;
            compNode.attack.value = 0.003;
            compNode.release.value = 0.25;
            gainNode = ctx.createGain();
            gainNode.gain.value = audioSettings.volume / 100;

            let node = srcNode;
            filters.forEach(f => { node.connect(f); node = f; });
            if (audioSettings.normalize) {
                node.connect(compNode);
                node = compNode;
            }
            node.connect(gainNode);
            gainNode.connect(ctx.destination);
        } catch (e) {
            console.warn('[audio] Web Audio API failed', e);
        }
    };

    const applyAudioSettings = (s) => {
        audioSettings = s;
        if (gainNode) gainNode.gain.value = s.volume / 100;
        audio.volume = s.volume / 100;
        if (eqNodes) {
            Object.keys(eqNodes).forEach(freq => {
                eqNodes[freq].gain.value = s.eq_enabled ? (s.eq[freq] || 0) : 0;
            });
        }
        if (compNode && srcNode) {
            compNode.ratio.value = s.normalize ? 4 : 1;
        }
    };

    audio.addEventListener('play', () => {
        setupAudioGraph();
        if (ctx && ctx.state === 'suspended') ctx.resume();
        applyAudioSettings(audioSettings);
    }, { once: true });

    window.addEventListener('audio-settings-changed', (e) => {
        applyAudioSettings(e.detail);
    });

    audio.volume = audioSettings.volume / 100;
    try {
        const savedRate = parseFloat(localStorage.getItem('doniix-playback-rate') || '1');
        if (savedRate >= 0.5 && savedRate <= 2 && savedRate !== 1) audio.playbackRate = savedRate;
    } catch (_) {}

    // Crossfade: monitor track end, fade out + load next
    audio.addEventListener('timeupdate', () => {
        if (!audioSettings.automix) return;
        if (!audio.duration || audio.paused) return;
        const remaining = audio.duration - audio.currentTime;
        const fade = +audioSettings.crossfade || 3;
        if (audioSettings.gapless_smart && window.__audioCtx && window.__audioSrcNode && !audio.__skipFadeChecked) {
            audio.__skipFadeChecked = true;
            try {
                const an = window.__audioAnalyser || (window.__audioCtx.createAnalyser());
                if (!window.__audioAnalyser) {
                    an.fftSize = 256;
                    window.__audioSrcNode.connect(an);
                    window.__audioAnalyser = an;
                }
                const buf = new Float32Array(an.fftSize);
                an.getFloatTimeDomainData(buf);
                let sum = 0;
                for (let i = 0; i < buf.length; i++) sum += buf[i] * buf[i];
                const rms = Math.sqrt(sum / buf.length);
                audio.__nearSilence = rms < 0.005;
            } catch (_) { audio.__nearSilence = false; }
        }
        if (remaining > fade + 0.5) audio.__skipFadeChecked = false;
        if (audio.__nearSilence && remaining < 0.5) return;
        if (remaining < fade && remaining > 0.2 && !audio.__crossfading) {
            audio.__crossfading = true;
            const startVol = audio.volume;
            const tick = () => {
                if (audio.paused) { audio.volume = startVol; audio.__crossfading = false; return; }
                const r = audio.duration - audio.currentTime;
                if (r <= 0.2) { audio.volume = startVol; audio.__crossfading = false; return; }
                audio.volume = Math.max(0, startVol * (r / fade));
                requestAnimationFrame(tick);
            };
            tick();
        }
        if (remaining > fade + 1) {
            audio.__crossfading = false;
        }
    });

    audio.addEventListener('ended', () => {
        audio.volume = audioSettings.volume / 100;
    });

    // F5 / Ctrl+R reload — works in desktop app (pywebview) and browser
    document.addEventListener('keydown', (e) => {
        if (e.key === 'F5' || (e.ctrlKey && e.key.toLowerCase() === 'r' && !e.shiftKey)) {
            e.preventDefault();
            location.reload();
        }
        if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === 'r') {
            e.preventDefault();
            // Hard reload — also drop SW caches
            if ('caches' in window) {
                caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))).finally(() => location.reload(true));
            } else {
                location.reload(true);
            }
        }
    });

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', async () => {
            try {
                await navigator.serviceWorker.register('/sw.js');
            } catch (e) {}
        });
    }

    const isNativeApp = /Doniixify-Desktop|pywebview|TWA|\bwv\b/i.test(navigator.userAgent)
        || window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || (window.navigator.standalone === true);
    if (isNativeApp) {
        const hideAppsTab = () => {
            const removed = document.querySelectorAll('[data-tab="apps"], [data-tab-content="apps"], [data-tab="about"], [data-tab-content="about"]');
            removed.forEach(el => el.remove());
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', hideAppsTab);
        } else {
            hideAppsTab();
        }
    }

    if (window.__isAdmin) {
        const sweepBroken = async () => {
            if (sessionStorage.getItem('doniix-broken-swept') === '1') return { removed: 0 };
            sessionStorage.setItem('doniix-broken-swept', '1');
            try {
                const r = await fetchWT('/api/admin/cleanup-broken', { method: 'POST' }, 10000);
                if (!r.ok) return { removed: 0 };
                return await r.json().catch(() => ({ removed: 0 }));
            } catch (e) { return { removed: 0 }; }
        };
        const purgeMissing = async () => {
            if (sessionStorage.getItem('doniix-missing-purged') === '1') return { deleted: 0 };
            sessionStorage.setItem('doniix-missing-purged', '1');
            try {
                const r = await fetchWT('/api/admin/purge-missing', { method: 'POST' }, 30000);
                if (!r.ok) return { deleted: 0 };
                return await r.json().catch(() => ({ deleted: 0 }));
            } catch (e) { return { deleted: 0 }; }
        };
        const backfillYears = async () => {
            if (sessionStorage.getItem('doniix-years-backfilled-v2') === '1') return { updated: 0 };
            sessionStorage.setItem('doniix-years-backfilled-v2', '1');
            try {
                const r = await fetchWT('/api/admin/backfill-years?limit=50', { method: 'POST' }, 45000);
                if (!r.ok) return { updated: 0 };
                return await r.json().catch(() => ({ updated: 0 }));
            } catch (e) { return { updated: 0 }; }
        };
        const MAINT_KEY = 'doniix-admin-maint-last';
        const runAdminMaintenance = () => {
            try {
                const last = parseInt(localStorage.getItem(MAINT_KEY) || '0', 10);
                if (Date.now() - last < 6 * 60 * 60 * 1000) return;
            } catch (_) {}
            try { localStorage.setItem(MAINT_KEY, String(Date.now())); } catch (_) {}
            const run = async () => {
                const [sweep, years, missing] = await Promise.all([sweepBroken(), backfillYears(), purgeMissing()]);
                const removed = sweep.removed || 0;
                const updated = years.updated || 0;
                const purged = missing.deleted || 0;
                if ((removed > 0 || updated > 0 || purged > 0) && window.__showToast) {
                    const parts = [];
                    if (removed > 0) parts.push(`removed ${removed} junk`);
                    if (purged > 0) parts.push(`purged ${purged} missing`);
                    if (updated > 0) parts.push(`filled ${updated} years`);
                    window.__showToast(parts.join(' · '));
                }
            };
            if ('requestIdleCallback' in window) {
                requestIdleCallback(run, { timeout: 5000 });
            } else {
                setTimeout(run, 3000);
            }
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', runAdminMaintenance);
        } else {
            runAdminMaintenance();
        }
    }
})();

(function () {
    const SS_KEY = 'u' + (window.__userId || 0) + ':doniix-screensaver-v2';
    try { localStorage.removeItem('u' + (window.__userId || 0) + ':doniix-screensaver'); } catch (_) {}
    let enabled = localStorage.getItem(SS_KEY) === '1';
    let idleTimer = null;
    let overlay = null;
    let canvas = null;
    let presetCycleTimer = null;
    let rafId = null;
    let isActive = false;
    const SS_IDLE_KEY = 'u' + (window.__userId || 0) + ':doniix-screensaver-idle-sec';
    const PRESET_CYCLE_MS = 22000;
    function getIdleMs() {
        const raw = parseInt(localStorage.getItem(SS_IDLE_KEY) || '5', 10);
        const sec = isFinite(raw) ? Math.max(5, Math.min(3600, raw)) : 5;
        return sec * 1000;
    }

    window.__getScreensaverEnabled = () => enabled;
    window.__setScreensaverEnabled = (val) => {
        enabled = !!val;
        localStorage.setItem(SS_KEY, enabled ? '1' : '0');
        if (!enabled) deactivate();
        resetIdle();
    };
    window.__getScreensaverIdleSec = () => {
        const raw = parseInt(localStorage.getItem(SS_IDLE_KEY) || '5', 10);
        return isFinite(raw) ? Math.max(5, Math.min(3600, raw)) : 5;
    };
    window.__setScreensaverIdleSec = (sec) => {
        const v = Math.max(5, Math.min(3600, parseInt(sec, 10) || 5));
        localStorage.setItem(SS_IDLE_KEY, String(v));
        resetIdle();
    };

    let visualizer = null;
    let presetKeys = [];
    let presetIdx = 0;
    let bcLoading = false;
    let bcLoaded = false;

    function loadScript(url) {
        return new Promise((res, rej) => {
            const s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = () => res();
            s.onerror = () => rej(new Error('Failed to load ' + url));
            document.head.appendChild(s);
        });
    }

    async function loadScriptTry(urls) {
        for (const u of urls) {
            try { await loadScript(u); return true; } catch (_) {}
        }
        return false;
    }

    async function loadButterchurn() {
        if (bcLoaded) return true;
        if (bcLoading) {
            while (bcLoading) await new Promise(r => setTimeout(r, 100));
            return bcLoaded;
        }
        bcLoading = true;
        try {
            await loadScript('https://cdn.jsdelivr.net/npm/butterchurn@2.6.7/lib/butterchurn.min.js');
            await loadScript('https://cdn.jsdelivr.net/npm/butterchurn-presets@2.4.7/lib/butterchurnPresets.min.js');
            await loadScriptTry([
                'https://cdn.jsdelivr.net/npm/butterchurn-presets@2.4.7/lib/butterchurnPresetsNonMinimal.min.js',
                'https://unpkg.com/butterchurn-presets@2.4.7/lib/butterchurnPresetsNonMinimal.min.js',
            ]);
            await loadScriptTry([
                'https://cdn.jsdelivr.net/npm/butterchurn-presets@2.4.7/lib/butterchurnPresetsExtra.min.js',
                'https://unpkg.com/butterchurn-presets@2.4.7/lib/butterchurnPresetsExtra.min.js',
            ]);
            await loadScriptTry([
                'https://cdn.jsdelivr.net/npm/butterchurn-presets-md@1.0.5/dist/butterchurnPresetsMD.min.js',
                'https://unpkg.com/butterchurn-presets-md/dist/butterchurnPresetsMD.min.js',
                'https://cdn.jsdelivr.net/gh/jberg/butterchurn-presets-md/dist/butterchurnPresetsMD.min.js',
            ]);
            bcLoaded = true;
            return true;
        } catch (e) {
            console.warn('[screensaver] butterchurn load fail', e);
            return false;
        } finally {
            bcLoading = false;
        }
    }

    function ensureAudioGraph() {
        try {
            const audio = document.querySelector('audio');
            if (!audio) return null;
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            let actx = window.__audioCtx;
            let srcNode = window.__audioSrcNode;
            if (!actx) { actx = new AC(); window.__audioCtx = actx; }
            if (actx.state === 'suspended') { actx.resume().catch(() => {}); }
            if (!srcNode) {
                try {
                    srcNode = actx.createMediaElementSource(audio);
                    srcNode.connect(actx.destination);
                    window.__audioSrcNode = srcNode;
                } catch (e) { return null; }
            }
            return { actx, srcNode };
        } catch (e) { return null; }
    }

    function buildOverlay() {
        if (overlay) return true;
        overlay = document.createElement('div');
        overlay.id = 'doniix-screensaver';
        overlay.innerHTML = '<canvas id="screensaver-canvas"></canvas>';
        document.body.appendChild(overlay);
        canvas = overlay.querySelector('canvas');
        return true;
    }

    function resize() {
        if (!canvas) return;
        const w = window.innerWidth;
        const h = window.innerHeight;
        canvas.width = w;
        canvas.height = h;
        canvas.style.width = w + 'px';
        canvas.style.height = h + 'px';
        if (visualizer) {
            try { visualizer.setRendererSize(w, h); } catch (e) {}
        }
    }

    async function initVisualizer() {
        if (visualizer) return true;
        const ok = await loadButterchurn();
        if (!ok) return false;
        const graph = ensureAudioGraph();
        if (!graph) return false;
        const bc = window.butterchurn && (window.butterchurn.default || window.butterchurn);
        const bcPresets = window.butterchurnPresets && (window.butterchurnPresets.default || window.butterchurnPresets);
        if (!bc || typeof bc.createVisualizer !== 'function') return false;
        if (!bcPresets || typeof bcPresets.getPresets !== 'function') return false;
        try {
            visualizer = bc.createVisualizer(graph.actx, canvas, {
                width: window.innerWidth,
                height: window.innerHeight,
                pixelRatio: Math.min(window.devicePixelRatio || 1, 1.5),
                textureRatio: 1,
            });
            visualizer.connectAudio(graph.srcNode);
            const merged = {};
            const addBundle = (g) => {
                if (!g) return 0;
                const u = g.default || g;
                try {
                    if (typeof u.getPresets === 'function') {
                        const p = u.getPresets();
                        if (p) { Object.assign(merged, p); return Object.keys(p).length; }
                    } else if (typeof u === 'object') {
                        Object.assign(merged, u);
                        return Object.keys(u).length;
                    }
                } catch (_) {}
                return 0;
            };
            const counts = {
                core: addBundle(bcPresets),
                nonmin: addBundle(window.butterchurnPresetsNonMinimal),
                extra: addBundle(window.butterchurnPresetsExtra),
                md: addBundle(window.butterchurnPresetsMD),
            };
            console.log('[screensaver] bundle sizes:', counts);
            presetKeys = Object.keys(merged);
            for (let i = presetKeys.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [presetKeys[i], presetKeys[j]] = [presetKeys[j], presetKeys[i]];
            }
            window.__bcPresets = merged;
            presetIdx = 0;
            console.log('[screensaver] presets loaded:', presetKeys.length, '(all bundles)');
            loadCurrentPreset(0);
            return true;
        } catch (e) {
            console.warn('[screensaver] visualizer init fail', e);
            return false;
        }
    }

    function loadCurrentPreset(blendSec) {
        if (!visualizer || !window.__bcPresets || !presetKeys.length) return;
        try {
            visualizer.loadPreset(window.__bcPresets[presetKeys[presetIdx]], blendSec || 0);
        } catch (e) { console.warn('[screensaver] loadPreset fail', e); }
    }

    function cyclePreset() {
        if (!presetKeys.length) return;
        presetIdx = (presetIdx + 1) % presetKeys.length;
        loadCurrentPreset(3.5);
    }

    function tickVis() {
        if (!isActive) return;
        rafId = requestAnimationFrame(tickVis);
        if (visualizer) { try { visualizer.render(); } catch (e) {} }
    }

    async function activate() {
        if (isActive) return;
        const audio = document.querySelector('audio');
        if (!audio || audio.paused) return;
        if (!enabled) return;
        buildOverlay();
        resize();
        const ready = await initVisualizer();
        if (!ready) {
            if (overlay) overlay.classList.add('active');
            isActive = true;
            return;
        }
        overlay.classList.add('active');
        isActive = true;
        if (presetCycleTimer) clearInterval(presetCycleTimer);
        presetCycleTimer = setInterval(cyclePreset, PRESET_CYCLE_MS);
        rafId = requestAnimationFrame(tickVis);
        try { document.body.style.cursor = 'none'; } catch (e) {}
    }

    function deactivate() {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = null;
        if (presetCycleTimer) { clearInterval(presetCycleTimer); presetCycleTimer = null; }
        if (overlay) {
            overlay.classList.remove('active');
            try { overlay.remove(); } catch (_) {}
            overlay = null;
            canvas = null;
            visualizer = null;
        }
        try { document.body.style.cursor = ''; } catch (e) {}
        isActive = false;
    }

    function resetIdle() {
        if (idleTimer) clearTimeout(idleTimer);
        if (isActive) deactivate();
        if (!enabled) return;
        idleTimer = setTimeout(activate, getIdleMs());
    }

    ['mousemove', 'keydown', 'touchstart', 'click', 'wheel'].forEach(ev => {
        window.addEventListener(ev, resetIdle, { passive: true });
    });
    window.addEventListener('resize', () => { if (isActive) resize(); });
    document.addEventListener('visibilitychange', () => { if (document.hidden) deactivate(); });
    resetIdle();
})();
