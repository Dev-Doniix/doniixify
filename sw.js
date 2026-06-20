const CACHE_V = 'doniixify-' + new Date().toISOString().slice(0,10) + '-v18';
const COVER_CACHE = CACHE_V + '-covers';
const STATIC_CACHE = CACHE_V + '-static';
const OFFLINE_AUDIO_CACHE = 'doniix-offline-audio-v1';
const RETRY_TAG = 'doniix-retry-posts';
const RETRY_STORE = 'doniix-retry-queue';
const MAX_COVER_ENTRIES = 400;
const STATIC = ['/manifest.json'];

const idbOpen = () => new Promise((resolve, reject) => {
    const req = indexedDB.open(RETRY_STORE, 1);
    req.onupgradeneeded = () => req.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
});
const idbQueue = async (entry) => {
    const db = await idbOpen();
    return new Promise((res, rej) => {
        const tx = db.transaction('queue', 'readwrite');
        tx.objectStore('queue').add(entry);
        tx.oncomplete = () => res();
        tx.onerror = () => rej(tx.error);
    });
};
const idbDrain = async () => {
    const db = await idbOpen();
    return new Promise((res, rej) => {
        const tx = db.transaction('queue', 'readwrite');
        const store = tx.objectStore('queue');
        const req = store.getAll();
        req.onsuccess = async () => {
            const entries = req.result || [];
            for (const e of entries) {
                try {
                    const r = await fetch(e.url, { method: e.method, headers: e.headers || {}, body: e.body });
                    if (r.ok || (r.status >= 400 && r.status < 500)) {
                        store.delete(e.id);
                    }
                } catch (_) {}
            }
            res();
        };
        req.onerror = () => rej(req.error);
    });
};

self.addEventListener('sync', e => {
    if (e.tag === RETRY_TAG) e.waitUntil(idbDrain());
});

self.addEventListener('install', e => {
    e.waitUntil(caches.open(STATIC_CACHE).then(c => c.addAll(STATIC).catch(()=>null)).then(()=>self.skipWaiting()));
});
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys => Promise.all(
            keys.filter(k => k !== COVER_CACHE && k !== STATIC_CACHE).map(k => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

const trimCache = async (name, max) => {
    try {
        const cache = await caches.open(name);
        const reqs = await cache.keys();
        if (reqs.length <= max) return;
        const toDelete = reqs.length - max;
        for (let i = 0; i < toDelete; i++) {
            await cache.delete(reqs[i]);
        }
    } catch (_) {}
};

self.addEventListener('message', e => {
    if (e.data && e.data.type === 'queue-retry') {
        idbQueue(e.data.entry).then(() => {
            try { self.registration.sync.register(RETRY_TAG); } catch (_) {}
        }).catch(() => {});
    }
});

self.addEventListener('push', e => {
    try {
        const data = e.data ? e.data.json() : {};
        const title = data.title || 'Doniixify';
        const opts = {
            body: data.body || '',
            icon: data.icon || '/api/icon/192',
            badge: data.badge || '/api/icon/192',
            data: { url: data.url || '/' },
            tag: data.tag || 'doniix-' + Date.now(),
            renotify: false,
        };
        e.waitUntil(self.registration.showNotification(title, opts));
    } catch (_) {}
});

self.addEventListener('notificationclick', e => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
        for (const c of list) {
            if (c.url.includes(url) && 'focus' in c) return c.focus();
        }
        return self.clients.openWindow(url);
    }));
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);
    if (e.request.method !== 'GET') return;
    if (url.origin !== location.origin) return;
    if (url.pathname.startsWith('/stream/')) {
        e.respondWith(
            caches.open(OFFLINE_AUDIO_CACHE).then(cache =>
                cache.match(e.request, { ignoreVary: true }).then(cached => cached || fetch(e.request))
            )
        );
        return;
    }
    if (url.pathname.startsWith('/api/')) return;
    if (url.pathname === '/assets/js/app.js' || url.pathname === '/assets/css/app.css' || url.pathname === '/sw.js') {
        return;
    }
    if (url.pathname.startsWith('/cover/')) {
        e.respondWith(
            caches.open(COVER_CACHE).then(cache =>
                cache.match(e.request).then(cached => {
                    if (cached) {
                        fetch(e.request).then(r => {
                            if (r.ok) cache.put(e.request, r.clone()).then(() => trimCache(COVER_CACHE, MAX_COVER_ENTRIES));
                        }).catch(() => {});
                        return cached;
                    }
                    return fetch(e.request).then(r => {
                        if (r.ok) {
                            cache.put(e.request, r.clone()).then(() => trimCache(COVER_CACHE, MAX_COVER_ENTRIES));
                        }
                        return r;
                    }).catch(() => new Response('', { status: 404 }));
                })
            ).catch(() => new Response('', { status: 404 }))
        );
        return;
    }
    if (url.pathname === '/manifest.json' || url.pathname.match(/\.(woff2?|ttf|otf|eot|png|jpg|jpeg|webp|svg|ico)$/i)) {
        e.respondWith(
            caches.open(STATIC_CACHE).then(cache =>
                cache.match(e.request).then(cached => {
                    const f = fetch(e.request).then(r => { if (r.ok) cache.put(e.request, r.clone()); return r; }).catch(()=>cached);
                    return cached || f;
                })
            )
        );
        return;
    }
    const accept = e.request.headers.get('Accept') || '';
    if (accept.includes('text/html') && !e.request.headers.get('X-SPA')) {
        const PAGE_CACHE = CACHE_V + '-pages';
        e.respondWith(
            caches.open(PAGE_CACHE).then(cache =>
                cache.match(e.request).then(cached => {
                    const networkPromise = fetch(e.request).then(r => {
                        if (r.ok && r.status === 200) cache.put(e.request, r.clone()).catch(() => {});
                        return r;
                    }).catch(() => cached || new Response('Offline', { status: 503 }));
                    return cached || networkPromise;
                })
            ).catch(() => fetch(e.request))
        );
        return;
    }
});
