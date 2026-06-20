"""Windows Taskbar Thumbnail Toolbar Buttons (jak Spotify).

3 przyciski (Prev / Play-Pause / Next) pod miniaturą okna w pasku zadań.
Implementacja przez COM ITaskbarList3. Ikony rysowane w runtime przez Pillow.
"""

from __future__ import annotations

import ctypes
import os
import queue
import sys
import tempfile
import threading
import time
from ctypes import POINTER, Structure, c_long, c_void_p
from ctypes.wintypes import BOOL, DWORD, HICON, HWND, LPARAM, UINT, WCHAR, WPARAM
from typing import Callable

try:
    import comtypes
    import comtypes.client
    from PIL import Image, ImageDraw
    _OK = sys.platform == "win32"
except ImportError:
    _OK = False

if not _OK:
    def install(window, handlers):
        print("[taskbar] disabled — pip install comtypes pywin32 Pillow")
        return None
else:
    CLSID_TaskbarList = comtypes.GUID("{56FDF344-FD6D-11D0-958A-006097C9A090}")
    IID_ITaskbarList3 = comtypes.GUID("{EA1AFB91-9E28-4B86-90E9-9E9F8A5EEFAF}")

    THBN_CLICKED = 0x1800
    WM_COMMAND = 0x0111
    THB_ICON = 0x00000002
    THB_TOOLTIP = 0x00000004
    THB_FLAGS = 0x00000008
    THBF_ENABLED = 0x00000000
    GWLP_WNDPROC = -4
    LR_LOADFROMFILE = 0x00000010
    IMAGE_ICON = 1

    BTN_LIKE = 1000
    BTN_PREV = 1001
    BTN_PLAY = 1002
    BTN_NEXT = 1003

    class THUMBBUTTON(Structure):
        _fields_ = [
            ("dwMask", DWORD),
            ("iId", UINT),
            ("iBitmap", UINT),
            ("hIcon", HICON),
            ("szTip", WCHAR * 260),
            ("dwFlags", DWORD),
        ]

    class ITaskbarList3(comtypes.IUnknown):
        _iid_ = IID_ITaskbarList3
        _methods_ = [
            comtypes.COMMETHOD([], comtypes.HRESULT, "HrInit"),
            comtypes.COMMETHOD([], comtypes.HRESULT, "AddTab", (["in"], HWND, "hwnd")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "DeleteTab", (["in"], HWND, "hwnd")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "ActivateTab", (["in"], HWND, "hwnd")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetActiveAlt", (["in"], HWND, "hwnd")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "MarkFullscreenWindow",
                               (["in"], HWND, "hwnd"), (["in"], BOOL, "fullscreen")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetProgressValue",
                               (["in"], HWND, "hwnd"),
                               (["in"], ctypes.c_ulonglong, "complete"),
                               (["in"], ctypes.c_ulonglong, "total")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetProgressState",
                               (["in"], HWND, "hwnd"), (["in"], DWORD, "state")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "RegisterTab",
                               (["in"], HWND, "tab"), (["in"], HWND, "parent")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "UnregisterTab", (["in"], HWND, "tab")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetTabOrder",
                               (["in"], HWND, "tab"), (["in"], HWND, "before")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetTabActive",
                               (["in"], HWND, "tab"), (["in"], HWND, "parent"), (["in"], DWORD, "flags")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "ThumbBarAddButtons",
                               (["in"], HWND, "hwnd"), (["in"], UINT, "count"),
                               (["in"], POINTER(THUMBBUTTON), "buttons")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "ThumbBarUpdateButtons",
                               (["in"], HWND, "hwnd"), (["in"], UINT, "count"),
                               (["in"], POINTER(THUMBBUTTON), "buttons")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "ThumbBarSetImageList",
                               (["in"], HWND, "hwnd"), (["in"], c_void_p, "himl")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetOverlayIcon",
                               (["in"], HWND, "hwnd"), (["in"], HICON, "icon"),
                               (["in"], ctypes.c_wchar_p, "desc")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetThumbnailTooltip",
                               (["in"], HWND, "hwnd"), (["in"], ctypes.c_wchar_p, "tip")),
            comtypes.COMMETHOD([], comtypes.HRESULT, "SetThumbnailClip",
                               (["in"], HWND, "hwnd"), (["in"], c_void_p, "rect")),
        ]

    user32 = ctypes.windll.user32
    user32.SetWindowLongPtrW.argtypes = [HWND, ctypes.c_int, c_void_p]
    user32.SetWindowLongPtrW.restype = c_void_p
    user32.CallWindowProcW.argtypes = [c_void_p, HWND, UINT, WPARAM, LPARAM]
    user32.CallWindowProcW.restype = c_long
    user32.LoadImageW.argtypes = [c_void_p, ctypes.c_wchar_p, UINT, ctypes.c_int, ctypes.c_int, UINT]
    user32.LoadImageW.restype = c_void_p

    def _find_doniixify_hwnd() -> int:
        EnumWindowsProc = ctypes.WINFUNCTYPE(BOOL, HWND, LPARAM)
        result = [0]

        def cb(hwnd, lparam):
            if not user32.IsWindowVisible(hwnd):
                return True
            length = user32.GetWindowTextLengthW(hwnd)
            if length <= 0:
                return True
            buf = ctypes.create_unicode_buffer(length + 1)
            user32.GetWindowTextW(hwnd, buf, length + 1)
            if "Doniixify" in buf.value:
                result[0] = hwnd
                return False
            return True

        user32.EnumWindows(EnumWindowsProc(cb), 0)
        return result[0]

    def _draw_glyph(kind: str) -> Image.Image:
        size = 32
        img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        d = ImageDraw.Draw(img)
        white = (255, 255, 255, 255)
        if kind == "prev":
            d.rectangle([(8, 9), (11, 23)], fill=white)
            d.polygon([(13, 16), (24, 9), (24, 23)], fill=white)
        elif kind == "play":
            d.polygon([(10, 8), (10, 24), (24, 16)], fill=white)
        elif kind == "pause":
            d.rectangle([(10, 8), (14, 24)], fill=white)
            d.rectangle([(18, 8), (22, 24)], fill=white)
        elif kind == "next":
            d.polygon([(8, 9), (8, 23), (19, 16)], fill=white)
            d.rectangle([(21, 9), (24, 23)], fill=white)
        elif kind == "like":
            d.polygon([
                (16, 26), (6, 16), (6, 12), (8, 9), (12, 9),
                (16, 12), (20, 9), (24, 9), (26, 12), (26, 16),
            ], fill=white)
        elif kind == "like-outline":
            d.polygon([
                (16, 26), (6, 16), (6, 12), (8, 9), (12, 9),
                (16, 12), (20, 9), (24, 9), (26, 12), (26, 16),
            ], outline=white, width=2)
        return img

    def _png_to_hicon(img: Image.Image) -> int:
        fd, path = tempfile.mkstemp(suffix=".ico", prefix="doniix_btn_")
        os.close(fd)
        img.save(path, format="ICO", sizes=[(32, 32)])
        hicon = user32.LoadImageW(None, path, IMAGE_ICON, 32, 32, LR_LOADFROMFILE)
        try:
            os.unlink(path)
        except OSError:
            pass
        return hicon or 0

    def _set_tip(btn: "THUMBBUTTON", tip: str) -> None:
        tip = (tip or "")[:259]
        buf = ctypes.create_unicode_buffer(tip, 260)
        addr = ctypes.addressof(btn) + type(btn).szTip.offset
        ctypes.memmove(addr, buf, 260 * ctypes.sizeof(WCHAR))

    def _make_buttons():
        buttons = (THUMBBUTTON * 4)()
        icons = []
        items = [
            (BTN_LIKE, "like-outline", "Ulubione"),
            (BTN_PREV, "prev", "Poprzedni"),
            (BTN_PLAY, "play", "Play / Pause"),
            (BTN_NEXT, "next", "Następny"),
        ]
        for i, (bid, kind, tip) in enumerate(items):
            img = _draw_glyph(kind)
            hicon = _png_to_hicon(img)
            icons.append(hicon)
            buttons[i].dwMask = THB_ICON | THB_FLAGS | THB_TOOLTIP
            buttons[i].iId = bid
            buttons[i].iBitmap = 0
            buttons[i].hIcon = hicon
            buttons[i].dwFlags = THBF_ENABLED
            _set_tip(buttons[i], tip)
        return buttons, icons

    _state = {"set_playing": None, "set_liked": None}

    def update_play_state(playing: bool) -> None:
        fn = _state.get("set_playing")
        if fn:
            try: fn(bool(playing))
            except Exception as e: print(f"[taskbar] update_play_state err: {e}")

    def update_liked_state(liked: bool) -> None:
        fn = _state.get("set_liked")
        if fn:
            try: fn(bool(liked))
            except Exception as e: print(f"[taskbar] update_liked_state err: {e}")

    def install(window, handlers: dict[str, Callable[[], None]]):
        def runner():
            try:
                comtypes.CoInitialize()
            except Exception:
                pass

            hwnd = 0
            for _ in range(30):
                hwnd = _find_doniixify_hwnd()
                if hwnd:
                    break
                time.sleep(0.5)
            if not hwnd:
                print("[taskbar] no Doniixify window found after 15s")
                return

            print(f"[taskbar] found hwnd={hwnd}")

            try:
                taskbar = comtypes.client.CreateObject(CLSID_TaskbarList, interface=ITaskbarList3)
                taskbar.HrInit()
            except Exception as exc:
                print(f"[taskbar] CoCreateInstance failed: {exc}")
                return

            buttons, icons = _make_buttons()
            play_icon = _png_to_hicon(_draw_glyph("play"))
            pause_icon = _png_to_hicon(_draw_glyph("pause"))
            like_filled_icon = _png_to_hicon(_draw_glyph("like"))
            like_outline_icon = _png_to_hicon(_draw_glyph("like-outline"))
            try:
                taskbar.ThumbBarAddButtons(hwnd, 4, buttons)
                print("[taskbar] buttons added successfully")
            except Exception as exc:
                print(f"[taskbar] ThumbBarAddButtons failed: {exc}")
                return

            current_playing = [False]
            current_liked = [False]

            def _refresh():
                try:
                    taskbar.ThumbBarUpdateButtons(hwnd, 4, buttons)
                except Exception as e:
                    print(f"[taskbar] ThumbBarUpdateButtons failed: {e}")

            def set_playing(playing: bool) -> None:
                if current_playing[0] == playing:
                    return
                current_playing[0] = playing
                buttons[2].hIcon = pause_icon if playing else play_icon
                _refresh()

            def set_liked(liked: bool) -> None:
                if current_liked[0] == liked:
                    return
                current_liked[0] = liked
                buttons[0].hIcon = like_filled_icon if liked else like_outline_icon
                _set_tip(buttons[0], "Usuń z ulubionych" if liked else "Dodaj do ulubionych")
                _refresh()

            _state["set_playing"] = set_playing
            _state["set_liked"] = set_liked

            cmd_queue: "queue.Queue[int]" = queue.Queue()

            def cmd_worker():
                while True:
                    cmd_id = cmd_queue.get()
                    cb = {BTN_LIKE: handlers.get("like"),
                          BTN_PREV: handlers.get("prev"),
                          BTN_PLAY: handlers.get("toggle"),
                          BTN_NEXT: handlers.get("next")}.get(cmd_id)
                    if not cb:
                        continue
                    try:
                        cb()
                    except Exception as e:
                        print(f"[taskbar] handler err: {e}")
            threading.Thread(target=cmd_worker, daemon=True, name="doniixify-taskbar-cmd").start()

            WNDPROC = ctypes.WINFUNCTYPE(c_long, HWND, UINT, WPARAM, LPARAM)
            old_proc = [0]

            def new_proc(hwnd2, msg, wparam, lparam):
                if msg == WM_COMMAND:
                    notif = (wparam >> 16) & 0xFFFF
                    cmd_id = wparam & 0xFFFF
                    if notif == THBN_CLICKED:
                        cmd_queue.put_nowait(cmd_id)
                return user32.CallWindowProcW(old_proc[0], hwnd2, msg, wparam, lparam)

            new_proc_ptr = WNDPROC(new_proc)
            try:
                old_proc[0] = user32.SetWindowLongPtrW(
                    hwnd, GWLP_WNDPROC,
                    ctypes.cast(new_proc_ptr, c_void_p)
                )
                install._refs = (new_proc_ptr, buttons, icons, taskbar, play_icon, pause_icon, like_filled_icon, like_outline_icon)
                print("[taskbar] window proc subclassed, ready for clicks")
            except Exception as exc:
                print(f"[taskbar] SetWindowLongPtr failed: {exc} — buttons visible but clicks won't work")
                install._refs = (buttons, icons, taskbar, play_icon, pause_icon, like_filled_icon, like_outline_icon)

        threading.Thread(target=runner, daemon=True, name="doniixify-taskbar").start()
