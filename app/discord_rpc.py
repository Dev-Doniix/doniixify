"""Discord Rich Presence — minimal, tylko muzyka.

Pokazuje "Doniixify" + tytuł utworu + artysta + okładka. Bez paska postępu,
bez buttonów. Token API pobierany z webview po zalogowaniu (cache w
%APPDATA%/Doniixify/token).
"""

from __future__ import annotations

import json
import os
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

try:
    from pypresence import Presence, exceptions as rpc_exc
except ImportError:
    Presence = None
    rpc_exc = None

try:
    from config import load as load_config
except ImportError:
    load_config = None

DISCORD_CLIENT_ID_DEFAULT = "1514072315493744840"
SERVER_FETCH_INTERVAL_S = 4.0
RPC_UPDATE_INTERVAL_S = 1.0
LOGO_FALLBACK_URL = "https://music.leszczynowa5.pl/icon-512.png"
BAR_WIDTH = 14


def fmt_time(seconds: float) -> str:
    seconds = max(0, int(seconds))
    return f"{seconds // 60}:{seconds % 60:02d}"


def progress_bar(position: float, duration: float, width: int = BAR_WIDTH) -> str:
    if duration <= 0:
        return fmt_time(position)
    pct = max(0.0, min(1.0, position / duration))
    filled = max(0, min(width - 1, int(pct * width)))
    bar = "━" * filled + "●" + "─" * (width - filled - 1)
    return f"{fmt_time(position)} {bar} {fmt_time(duration)}"


def token_cache_path() -> Path:
    if sys.platform == "win32":
        base = Path(os.environ.get("APPDATA", str(Path.home() / "AppData/Roaming")))
    elif sys.platform == "darwin":
        base = Path.home() / "Library/Application Support"
    else:
        base = Path(os.environ.get("XDG_CONFIG_HOME", str(Path.home() / ".config")))
    return base / "Doniixify" / "token"


def load_token() -> str:
    env = os.environ.get("DONIIX_API_TOKEN", "").strip()
    if env:
        try:
            p = token_cache_path()
            p.parent.mkdir(parents=True, exist_ok=True)
            if not p.exists() or p.read_text(encoding="utf-8").strip() != env:
                p.write_text(env, encoding="utf-8")
        except OSError:
            pass
        return env
    try:
        cached = token_cache_path().read_text(encoding="utf-8").strip()
        if cached:
            return cached
    except (OSError, FileNotFoundError):
        pass
    return ""


def server_base(cfg: dict[str, Any] | None = None) -> str:
    if cfg is not None:
        url = str(cfg.get("url") or "").rstrip("/")
        if url:
            return url
    return "https://music.leszczynowa5.pl"


def fetch_now_playing(token: str, base: str) -> dict[str, Any] | None:
    url = base + "/api/now-playing?token=" + urllib.parse.quote(token)
    try:
        req = urllib.request.Request(url, headers={
            "User-Agent": "Doniixify-DiscordRPC/1.0",
            "Accept": "application/json",
        })
        with urllib.request.urlopen(req, timeout=8) as r:
            if r.status != 200:
                return None
            return json.loads(r.read())
    except urllib.error.HTTPError as exc:
        if exc.code == 401:
            try:
                token_cache_path().unlink(missing_ok=True)
            except OSError:
                pass
            return None
        return None
    except (urllib.error.URLError, OSError, json.JSONDecodeError):
        return None


def run_loop(token: str, base: str, client_id: str) -> None:
    if Presence is None:
        return
    rpc = Presence(client_id)
    last_song_id: int | None = None
    last_cleared = True
    connected = False
    shared: dict[str, Any] = {"data": None, "at": 0.0}
    lock = threading.Lock()

    def fetcher() -> None:
        while True:
            data = fetch_now_playing(token, base)
            if data is not None:
                with lock:
                    shared["data"] = data
                    shared["at"] = time.time()
            time.sleep(SERVER_FETCH_INTERVAL_S)

    threading.Thread(target=fetcher, daemon=True, name="doniixify-rpc-fetch").start()

    while True:
        if not connected:
            try:
                rpc.connect()
                connected = True
                try: rpc.clear(); last_cleared = True
                except Exception: pass
            except (rpc_exc.DiscordNotFound, rpc_exc.InvalidID):
                time.sleep(30)
                continue

        with lock:
            data = shared["data"]
            at = shared["at"]

        if data is None:
            time.sleep(RPC_UPDATE_INTERVAL_S)
            continue

        playing = bool(data.get("playing"))
        song = data.get("song") or {}
        song_id = int(song.get("id") or 0)

        if not playing or not song_id:
            if not last_cleared:
                try: rpc.clear(); last_cleared = True
                except rpc_exc.PipeClosed: connected = False
            last_song_id = None
            time.sleep(RPC_UPDATE_INTERVAL_S)
            continue

        title = str(song.get("title") or "Unknown").strip() or "Unknown"
        artist = str(song.get("artist") or "Doniixify").strip() or "Doniixify"
        album = str(song.get("album") or "").strip()
        cover_url = str(song.get("cover_url") or "").strip()
        duration = float(song.get("duration") or 0)
        base_position = float(song.get("position") or 0)
        position = base_position + max(0.0, time.time() - at)
        if duration > 0:
            position = min(position, duration)

        details = f"{title} — {artist}" if artist else title
        details = details[:128] if len(details) >= 2 else (details + " ·")[:128]
        state_text = progress_bar(position, duration)
        if len(state_text) < 2: state_text = state_text + " ·"
        state_text = state_text[:128]
        large_text = (album or title or "Doniixify")[:128]
        if len(large_text) < 2: large_text = large_text + " ·"

        payload: dict[str, Any] = {
            "details": details,
            "state": state_text,
            "large_image": cover_url or LOGO_FALLBACK_URL,
            "large_text": large_text,
        }
        if base.startswith("https://"):
            payload["buttons"] = [{"label": "Otwórz Doniixify", "url": base}]

        try:
            rpc.update(**payload)
            last_cleared = False
            if song_id != last_song_id:
                print(f"[rpc] {title} — {artist}")
        except (rpc_exc.PipeClosed, rpc_exc.ServerError):
            connected = False
            time.sleep(2)
            continue
        except Exception as exc:
            print(f"[rpc] update failed: {exc}")

        last_song_id = song_id
        time.sleep(RPC_UPDATE_INTERVAL_S)


def start_background(cfg: dict[str, Any] | None = None) -> threading.Thread | None:
    token = load_token()
    if not token or Presence is None:
        return None
    base = server_base(cfg)
    client_id = os.environ.get("DONIIX_DISCORD_CLIENT_ID", "").strip() or DISCORD_CLIENT_ID_DEFAULT
    t = threading.Thread(target=run_loop, args=(token, base, client_id), daemon=True, name="doniixify-rpc")
    t.start()
    return t
