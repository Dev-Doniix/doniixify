# Doniixify

> Self-hosted music streaming PWA with Subsonic-compatible API, YouTube/Spotify ingestion, collaborative playback, and a recommendation engine — all in pure PHP + vanilla JS, no Node build step required.

![License](https://img.shields.io/badge/license-BUSL--1.1-blue) ![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4) ![No build step](https://img.shields.io/badge/build-zero%20config-success)

---

## Highlights

- **Spotify-grade UX** in the browser: horizontal scroll widgets, live recommendations, smart queues, gapless playback, MediaSession integration
- **Subsonic-compatible REST API** (`/rest/*`) — works with DSub, Substreamer, Symfonium, Ultrasonic, and 20+ other clients out of the box
- **Multi-source ingestion** — paste a Spotify track/album/playlist URL or a YouTube link → `yt-dlp` downloads, `ffmpeg` transcodes, ID3v2 tags + embedded artwork written automatically
- **Item-based collaborative filtering** recommendation engine (pure PHP, no Python/ML dependency)
- **Real-time multi-device sync** via SSE — "Now playing on X" pills, remote transfer, exclusive playback
- **Collaborative listening (Jam sessions)** — invite friends to a synced playback session via URL token
- **Offline-first PWA** — Service Worker caches HTML/CSS/JS/covers, IndexedDB stores favourite tracks for offline playback
- **Zero build step** — no webpack, no rollup, no TypeScript. Edit `app.js` and refresh.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                       Browser (PWA)                          │
│  ┌────────────────┐  ┌───────────────┐  ┌─────────────────┐  │
│  │  app.js        │  │  Service      │  │  IndexedDB      │  │
│  │  ~7350 lines   │  │  Worker       │  │  offline audio  │  │
│  │  single IIFE   │  │  v18 cache    │  │  + retry queue  │  │
│  └────────┬───────┘  └───────┬───────┘  └─────────────────┘  │
└───────────┼──────────────────┼────────────────────────────────┘
            │ fetch / SSE      │ stream / cover cache
            ▼                  ▼
┌──────────────────────────────────────────────────────────────┐
│                       PHP 8.1 backend                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐    │
│  │  Router.php  │  │  Web/*       │  │  Subsonic/*      │    │
│  │  197 routes  │  │  Controllers │  │  REST adapter    │    │
│  └──────┬───────┘  └──────┬───────┘  └────────┬─────────┘    │
│         │                 │                   │              │
│  ┌──────▼───────┐  ┌──────▼────────┐  ┌──────▼─────────┐     │
│  │  Database    │  │  Scanner      │  │  Downloader    │     │
│  │  PDO/MySQL   │  │  (ID3 parser) │  │  yt-dlp/Spot.  │     │
│  └──────┬───────┘  └──────┬────────┘  └────────────────┘     │
│         │                 │                                  │
│  ┌──────▼─────────────────▼────────────────────────────┐     │
│  │  Cache/ — RecommendationEngine, SearchCache, etc.   │     │
│  └─────────────────────────────────────────────────────┘     │
└──────────────────────────────────────────────────────────────┘
            │                  │                     │
       MySQL 8.0          storage/              external APIs
       (18 migrations)   covers, music         iTunes/Deezer/
                                              Spotify/Last.fm
```

### Directory layout

| Path | Purpose |
|------|---------|
| `index.php` | Entry point — bootstrap, route registration, autoloader |
| `src/Router.php` | Dispatcher + CSRF + Subsonic auth + dynamic regex routes |
| `src/Database.php` | PDO wrapper with `fetchOne`/`fetchAll`/`execute` helpers |
| `src/Env.php` | `.env` loader (handles BOM, CRLF, `export`, quoted values) |
| `src/Migrator.php` | Auto-runs `migrations/*.sql` on first request after deploy |
| `src/Web/*` | Controllers + view renderers (one class per feature area) |
| `src/Subsonic/*` | Subsonic 1.16.1 REST adapter (Library, Auth, Response XML/JSON) |
| `src/Scanner/*` | Filesystem scan, ID3v2/Vorbis/FLAC tag parsing, BlurHash gen |
| `src/Downloader/*` | yt-dlp queue manager, Spotify Web API, Last.fm scrobble |
| `src/Cache/RecommendationEngine.php` | Item-based collaborative filtering (cosine-like) |
| `migrations/` | 18 SQL migration files, applied sequentially |
| `bin/` | CLI workers — cron jobs for downloads, smart rules, recommendations |
| `assets/js/app.js` | Frontend monolithic IIFE (~7350 lines, see header for sections) |
| `assets/css/app.css` | Styling — design tokens + responsive layouts + animations |
| `sw.js` | Service Worker — page cache, cover cache, offline audio cache |

### How a request flows

1. **HTTP request** → nginx → PHP-FPM → `index.php`
2. **Bootstrap** — loads `.env`, ensures storage folders exist, registers PSR-4 autoloader
3. **Router dispatch** — `Router::dispatch()` matches path against 197 registered routes (static + dynamic regex)
4. **CSRF check** — `POST`/`PUT`/`DELETE` on `/api/*` require `X-CSRF-Token` header (Bearer tokens exempt)
5. **Controller method** — fetches data via `Database::fetchOne/fetchAll`, returns JSON or renders view
6. **Layout render** — `Layout::render($route, $content)` wraps content in `<html>`, injects meta tags, CSRF token, theme bootstrap script, splash screen, and loads `app.js`/`app.css` with cache-busting timestamps

### Frontend module map

`assets/js/app.js` is a single IIFE with semantic sections (see file header for line ranges):

| Section | Approximate lines | Owns |
|---------|-------------------|------|
| BOOTSTRAP | 1–200 | platform detection, CSRF auto-injection, ICONS, helpers |
| AUDIO CORE | 200–630 | `loadSong`, queue logic, MediaSession, audio events |
| VOLUME | 630–712 | volume bar, per-track volume, smart EQ |
| KEYBOARD + FAVOURITES | 712–833 | hotkeys (Space, Shift+→, /, L, K, etc.), star toggle |
| DIALOGS + UI | 833–962 | custom alert/confirm/prompt, modals, toast notifications |
| QUEUE PANEL | 962–1012 | queue side panel rendering |
| LYRICS | 1012–1740 | LRC/enhanced-LRC parser, line highlighting, translation |
| NOW PLAYING | 1360–1740 | squeeze panel (desktop), fullscreen (mobile) |
| DEVICES + JAM | 2030–2690 | multi-device sync, collaborative sessions |
| THEMES + ACCENT | 625–833 | dark/light/auto, custom accent color, cover-based extraction |
| RECOMMENDATIONS | various | smart queue, surprise me, "for you" widget |
| SERVICE WORKER glue | 6950+ | registration, update banner trigger |

The IIFE exports ~140 helpers as `window.__*` so PHP-injected `<script>` blocks in views can call them without name collisions.

---

## Install

### Requirements

| Software | Version |
|----------|---------|
| PHP | 8.1+ (extensions: `pdo_mysql`, `mbstring`, `curl`, `gd`, `fileinfo`) |
| MySQL | 8.0+ (or MariaDB 10.5+) |
| yt-dlp | latest (https://github.com/yt-dlp/yt-dlp) |
| ffmpeg | 4.0+ |
| Web server | nginx + PHP-FPM (recommended) or Apache 2.4+ |

### Steps

```bash
git clone <repo-url> doniixify
cd doniixify

cp .env.example .env
# Edit .env: DB credentials, MUSIC_PATH, COVER_CACHE_PATH, API keys

mkdir -p storage/{music,covers,cache,icons,converter,tmp-dl,logs,locks}
chmod -R 775 storage

# DB schema runs automatically via migrations/ on first request
```

Visit `https://your-domain.com/` — installer creates the first admin account if no users exist.

### nginx (minimal)

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/doniixify;

    client_max_body_size 100M;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location /stream/ {
        proxy_request_buffering off;
    }
}
```

### Cron jobs (optional but recommended)

See `bin/README.md` for full crontab. Quick version:

```cron
# Background download queue (every minute, single-flight)
* * * * * cd /var/www/doniixify && flock -n storage/locks/dl.lock php bin/download-worker.php

# Recommendation matrix rebuild (daily 5am)
0 5 * * * cd /var/www/doniixify && php bin/build-recommendations.php

# Smart-playlist auto-refresh (daily 4am)
0 4 * * * cd /var/www/doniixify && php bin/refresh-rules.php
```

### API keys

| Service | URL | Required for |
|---------|-----|--------------|
| Spotify | https://developer.spotify.com/dashboard | Cover lookup, downloader hints |
| Last.fm | https://www.last.fm/api/account/create | Scrobble, discover widget |
| VAPID (push) | https://web-push-codelab.glitch.me/ | Web Push notifications |

All three are free. Last.fm key takes 5 minutes; Spotify ~3 minutes.

---

## Subsonic clients

Point any Subsonic-compatible client at `https://your-domain.com/rest/`. Auth: your Doniixify username + password.

Tested with: **DSub**, **Substreamer**, **Symfonium**, **Ultrasonic**, **Sublime Music**, **Jamstash**. Read-only operations + playlist management work; some clients also support starring and rating.

---

## Plugin / addon system — is it worth adding?

**Short answer: yes, but only in a specific shape.** Here's the tradeoff:

### What you'd gain

- **Community features** without you maintaining them (custom equalizers, visualizers, theme packs, lyrics providers, alternative downloaders, file-format converters)
- **Per-user customisation** — power users can opt into features that don't fit the default UX
- **Reduced surface area in core** — strip rarely-used features (e.g. screensaver, vocal cancel) and let plugins re-add them

### What it would cost

- **Security headaches** — plugins running in user browsers can leak tokens, scrape covers, exfiltrate playback history; sandboxing is hard in vanilla JS
- **API stability** — once you ship a plugin API, you can't refactor `loadSong()` without breaking everyone's plugins; window-namespace approach (`window.__*`) is already a soft contract
- **Update story** — `git pull` becomes painful when plugins ship their own DB migrations or PHP files

### Recommended approach (low-risk, high-value)

If you do this, **keep it simple and progressive**:

1. **Frontend-only plugin manifest** — drop a `plugins/<name>/plugin.json` + `plugin.js` + optional `plugin.css`. Backend just lists files in `/api/plugins/list`. Frontend `<script>`-injects approved plugins on page load. No PHP execution, no DB access. Plugins use existing `window.__*` API.
2. **Permission scopes** declared in manifest (`audio.read`, `audio.write`, `queue.modify`, `ui.injectPanel`). User sees a permission prompt on enable. Plugin loader wraps every API call with a scope check.
3. **No server-side plugin system at v1** — too much complexity, too many security gotchas. If a feature needs a backend, ship it as a fork or PR.
4. **Mark sensitive APIs as plugin-stable** — pick ~30 of the existing 140 `window.__*` helpers, document their signatures, treat the rest as private/refactorable.

This gives you 80% of plugin value with 20% of the engineering. Themes, visualizers, UI panels, and custom hotkey schemes work great. Custom downloaders or DB-touching features stay as core PRs.

If you want full power (custom routes, DB migrations, background workers), look at how Foundry VTT does it — manifest-driven module loading with version compatibility checks. But that's a ~3-week sprint for a system that 5% of users will use.

**My recommendation**: ship a v1 plugin loader that handles **themes + visualizers + UI panels only**. That covers 90% of community demand. Revisit deeper integration after you see what people actually build.

---

## Support the project

Doniixify is built and maintained solo, in spare time, with zero ads, zero tracking, and zero VC pressure to enshittify. If it saves you a Spotify subscription, makes your music collection feel alive again, or just sparks joy on a Tuesday morning — consider buying me a coffee. Every donation goes straight back into hosting bills, new feature time, and the occasional 3am bug-hunt fuel.

<p align="center">
  <a href="https://buymeacoffee.com/doniix">
    <img src="https://img.shields.io/badge/Buy%20me%20a%20coffee-%23FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black" alt="Buy me a coffee">
  </a>
</p>

**☕ https://buymeacoffee.com/doniix**

No paywalled features, no donor-only branches, no "Pro" tier — donations are purely a thank you. The project stays fully usable for everyone, forever (and goes full Apache 2.0 in 2030 regardless).

If money's tight, here are other ways that help just as much:
- ⭐ Star the repo (boosts discoverability)
- 🐛 Open an issue when something breaks (you're literally QA)
- 🔧 Send a PR (even a typo fix counts)
- 📣 Tell a friend who's tired of paying $11/mo to Spotify

---

## License

**Business Source License 1.1 (BUSL-1.1)** — same license used by HashiCorp Terraform, MariaDB MaxScale, Sentry, and CockroachDB.

**TL;DR for users**:
- ✅ You can use Doniixify for personal listening and self-hosting for yourself + family + friends
- ✅ Source code is public, you can read it, fork it, modify it
- ❌ You **cannot** offer Doniixify as a paid hosted service to third parties (no "Doniixify-as-a-Service" SaaS without a commercial license)
- ⏰ On **2030-01-01**, the entire codebase automatically converts to **Apache 2.0** — fully open source, no restrictions

See [`LICENSE`](./LICENSE) for the full text and Additional Use Grant.

---

## Contributing

PRs welcome. Style:

- PHP: strict types, PSR-4, no Composer dependencies (we stay vanilla)
- JS: no transpiler, no framework, target ES2022+, single IIFE in `app.js`
- CSS: BEM-ish, design tokens via custom properties, no preprocessor
- Tests: there aren't any (yet). Open an issue if you want to set them up.

---

## Credits

- BlurHash placeholders (currently disabled) — algorithm by woltapp/blurhash
- iTunes Search API, Deezer API, Spotify Web API — used for cover lookup
- Subsonic protocol — created by Sindre Mehus
