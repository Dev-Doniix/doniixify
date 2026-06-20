"""Resize user-provided logo into all required icon sizes.

Uses `assets/icons/31d87432-356b-4b04-adbf-95a0b03eb3be.png` (logo bez nazwy)
jako source dla wszystkich ikon aplikacji. Logo z nazwą zostawione dla
splash screen / login page.

Output:
    app/icons/icon-192.png       (PWA, taskbar)
    app/icons/icon-512.png       (PWA, Android launcher)
    app/icons/icon-512-maskable.png  (Android adaptive icon, with safe zone)
    app/icons/favicon.ico        (browser tab icon)

Wgraj wszystkie 4 pliki do roota projektu na serwerze.
"""

from __future__ import annotations

import sys
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    print("Pillow not installed. Run: pip install Pillow")
    sys.exit(1)

import config

ROOT = Path(__file__).parent
PROJECT_ROOT = ROOT.parent
OUT = ROOT / "icons"
OUT.mkdir(exist_ok=True)

CFG = config.load(quiet=True)
BG_HEX = CFG.get("background_color", "#0a0a0d").lstrip("#")
BG_RGB = tuple(int(BG_HEX[i:i + 2], 16) for i in (0, 2, 4))

LOGO_PLAIN = PROJECT_ROOT / "assets" / "icons" / "31d87432-356b-4b04-adbf-95a0b03eb3be.png"
LOGO_WITH_NAME = PROJECT_ROOT / "assets" / "icons" / "37316155-40bf-4587-8a3a-a246d522f5aa.png"


def load_source() -> Image.Image:
    if not LOGO_PLAIN.exists():
        print(f"✗ Source logo not found: {LOGO_PLAIN}")
        sys.exit(1)
    src = Image.open(LOGO_PLAIN).convert("RGBA")
    print(f"[src] {LOGO_PLAIN.name} → {src.size}")
    return src


def resize_with_padding(src: Image.Image, size: int, *, maskable: bool = False) -> Image.Image:
    """Resize logo into square canvas with background, optional safe-zone padding."""
    bg = Image.new("RGBA", (size, size), (*BG_RGB, 255))
    # Maskable: logo zajmuje 70% canvas (safe zone wokoło)
    # Standard:  logo zajmuje 90% canvas (mały oddech)
    target_ratio = 0.70 if maskable else 0.90
    target = int(size * target_ratio)

    src_w, src_h = src.size
    scale = min(target / src_w, target / src_h)
    new_w = int(src_w * scale)
    new_h = int(src_h * scale)
    resized = src.resize((new_w, new_h), Image.LANCZOS)

    offset = ((size - new_w) // 2, (size - new_h) // 2)
    bg.paste(resized, offset, resized)
    return bg


def make_favicon(src: Image.Image) -> None:
    """Generate multi-resolution .ico from source logo."""
    favicon = OUT / "favicon.ico"
    sizes_ico = [(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)]
    # ICO format wymaga listy rozmiarów + jeden Image jako base
    canvas = resize_with_padding(src, 256, maskable=False)
    canvas.save(favicon, format="ICO", sizes=sizes_ico)
    print(f"  ✓ {favicon.name} ({favicon.stat().st_size // 1024} KB, multi-res)")


def main() -> int:
    src = load_source()

    targets = [
        (192, False, "icon-192.png"),
        (512, False, "icon-512.png"),
        (512, True, "icon-512-maskable.png"),
    ]
    for size, maskable, name in targets:
        img = resize_with_padding(src, size, maskable=maskable)
        path = OUT / name
        img.save(path, format="PNG", optimize=True)
        print(f"  ✓ {path.name} ({path.stat().st_size // 1024} KB)")

    make_favicon(src)

    print(f"\nNext steps:")
    print(f"  1. WinSCP: wgraj {OUT}/ → root projektu na serwerze")
    print(f"     ($SITE/icon-192.png, icon-512.png, icon-512-maskable.png, favicon.ico)")
    print(f"  2. sudo chown leszczynowa5-music:leszczynowa5-music $SITE/icon-*.png $SITE/favicon.ico")
    print(f"  3. Hard reload (Ctrl+Shift+R) — favicon się odświeży")
    return 0


if __name__ == "__main__":
    sys.exit(main())
