"""Doniixify desktop launcher.

Webview + Discord Rich Presence (tytuł + artysta + okładka).
Token API pobierany z webview po zalogowaniu (cache w %APPDATA%/Doniixify/token).
"""

import platform
import sys

sys.dont_write_bytecode = True

import webview

import config
import discord_rpc
import taskbar_buttons


def build_user_agent(version: str) -> str:
    return (
        f"Doniixify-Desktop/{version} "
        f"({platform.system()} {platform.release()}; pywebview) "
        f"AppleWebKit/537.36 (KHTML, like Gecko)"
    )


class Bridge:
    def __init__(self, cfg):
        self._cfg = cfg
        self._rpc_started = False

    def bind_token(self, token):
        if self._rpc_started or not isinstance(token, str) or len(token.strip()) < 16:
            return False
        try:
            p = discord_rpc.token_cache_path()
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(token.strip(), encoding="utf-8")
        except OSError:
            pass
        t = discord_rpc.start_background(self._cfg)
        self._rpc_started = t is not None
        return self._rpc_started

    def set_playing(self, playing):
        try:
            taskbar_buttons.update_play_state(bool(playing))
        except Exception:
            pass
        return True

    def set_liked(self, liked):
        try:
            taskbar_buttons.update_liked_state(bool(liked))
        except Exception:
            pass
        return True


FETCH_TOKEN_JS = """
(() => {
    if (window.__doniixifyTokenSent) return;
    const run = async () => {
        try {
            const r = await fetch('/api/me/token', { credentials: 'include' });
            if (!r.ok) return;
            const d = await r.json();
            if (!d || !d.token || !window.pywebview?.api) return;
            const ok = await window.pywebview.api.bind_token(d.token);
            if (ok) window.__doniixifyTokenSent = true;
        } catch (e) {}
    };
    if (window.pywebview?.api) run();
    else window.addEventListener('pywebviewready', run, { once: true });
})();
"""

HOOK_AUDIO_JS = """
(() => {
    if (window.__doniixifyAudioHooked) return;
    const setup = () => {
        const audio = document.querySelector('audio') || document.getElementById('audio');
        const fav = document.getElementById('pb-favorite');
        if (!audio || !fav) { setTimeout(setup, 500); return; }
        if (window.__doniixifyAudioHooked) return;
        window.__doniixifyAudioHooked = true;
        const pushPlay = (playing) => {
            try { window.pywebview?.api?.set_playing(playing); } catch (e) {}
        };
        const pushLike = (liked) => {
            try { window.pywebview?.api?.set_liked(liked); } catch (e) {}
        };
        audio.addEventListener('play', () => pushPlay(true));
        audio.addEventListener('pause', () => pushPlay(false));
        audio.addEventListener('ended', () => pushPlay(false));
        pushPlay(!audio.paused);

        const isLiked = () => fav.classList.contains('liked') || fav.dataset.liked === '1';
        const obs = new MutationObserver(() => pushLike(isLiked()));
        obs.observe(fav, { attributes: true, attributeFilter: ['class', 'data-liked'] });
        pushLike(isLiked());
    };
    if (window.pywebview?.api) setup();
    else window.addEventListener('pywebviewready', setup, { once: true });
})();
"""


def main():
    cfg = config.load()

    if discord_rpc.load_token():
        discord_rpc.start_background(cfg)
        token_already = True
    else:
        token_already = False

    bridge = Bridge(cfg)
    bridge._rpc_started = token_already

    window = webview.create_window(
        title=cfg["desktop"]["title"],
        url=cfg["url"],
        width=int(cfg["desktop"]["window_width"]),
        height=int(cfg["desktop"]["window_height"]),
        background_color=cfg["background_color"],
        resizable=True,
        confirm_close=False,
        js_api=bridge,
    )

    def _click(btn_id):
        try:
            window.evaluate_js(f"document.getElementById('{btn_id}')?.click();")
        except Exception:
            pass

    taskbar_buttons.install(window, {
        "like": lambda: _click("pb-favorite"),
        "prev": lambda: _click("pb-prev"),
        "toggle": lambda: _click("pb-play"),
        "next": lambda: _click("pb-next"),
    })

    def on_loaded():
        if not bridge._rpc_started:
            window.evaluate_js(FETCH_TOKEN_JS)
        window.evaluate_js(HOOK_AUDIO_JS)
    window.events.loaded += on_loaded

    webview.start(
        debug=False,
        private_mode=False,
        user_agent=build_user_agent(cfg["version"]),
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
