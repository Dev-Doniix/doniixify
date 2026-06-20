"""Live config loader — pobiera metadata aplikacji z serwera PHP.

Zmieniasz coś w PHP `Env` / `index.php` → kolejny build używa nowych wartości
bez edycji Pythona. Fallback do hardcoded defaults gdy serwer offline.
"""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request
from typing import Any

CONFIG_URL = "https://music.leszczynowa5.pl/api/app-config"
TIMEOUT_S = 8

DEFAULTS: dict[str, Any] = {
    "name": "Doniixify",
    "short_name": "Doniixify",
    "version": "1.0.0",
    "url": "https://music.leszczynowa5.pl/",
    "manifest_url": "https://music.leszczynowa5.pl/manifest.json",
    "icon_192_url": "https://music.leszczynowa5.pl/app/icons/icon-192.png",
    "icon_512_url": "https://music.leszczynowa5.pl/app/icons/icon-512.png",
    "icon_512_maskable_url": "https://music.leszczynowa5.pl/app/icons/icon-512-maskable.png",
    "theme_color": "#0a0a0d",
    "background_color": "#0a0a0d",
    "package_id": "pl.music.music.twa",
    "desktop": {
        "window_width": 1400,
        "window_height": 900,
        "title": "Doniixify",
    },
    "android": {
        "orientation": "any",
        "display_mode": "standalone",
        "status_bar_color": "#0A0A0D",
        "splash_screen_color": "#0A0A0D",
    },
}


def load(quiet: bool = False) -> dict[str, Any]:
    try:
        if not quiet:
            print(f"[config] fetch {CONFIG_URL}")
        req = urllib.request.Request(CONFIG_URL, headers={"User-Agent": "Doniixify-AppBuilder/1.0"})
        with urllib.request.urlopen(req, timeout=TIMEOUT_S) as r:
            if r.status != 200:
                raise OSError(f"HTTP {r.status}")
            cfg = json.loads(r.read())
        merged: dict[str, Any] = json.loads(json.dumps(DEFAULTS))
        merged.update(cfg)
        if not quiet:
            print(f"[config] ✓ {merged.get('name')} v{merged.get('version')} @ {merged.get('url')}")
        return merged
    except (urllib.error.URLError, OSError, json.JSONDecodeError) as exc:
        if not quiet:
            print(f"[config] ✗ {exc} — using local defaults")
        return DEFAULTS


if __name__ == "__main__":
    cfg = load()
    print(json.dumps(cfg, indent=2, ensure_ascii=False))
    sys.exit(0)
