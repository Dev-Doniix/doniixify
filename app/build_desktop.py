"""Build Doniixify.exe (Windows) z `main.py` przez PyInstaller.

Wymaga: Python 3.10+, pip install -r requirements.txt
Uruchom: python build_desktop.py
Output:  app/dist/Doniixify.exe
"""

from __future__ import annotations

import shutil
import subprocess
import sys
import urllib.request
from pathlib import Path

import config

ROOT = Path(__file__).parent
ICON_PATH = ROOT / "icon.ico"
DIST = ROOT / "dist"
BUILD = ROOT / "build"
CFG = config.load()
ICON_URL = CFG["icon_512_url"]
APP_NAME = CFG["desktop"]["title"]


def ensure_icon() -> Path | None:
    """Pobierz ikonę z serwera i przekonwertuj do .ico (Pillow)."""
    if ICON_PATH.exists():
        return ICON_PATH
    try:
        png_path = ROOT / "icon.png"
        print(f"[icon] Downloading {ICON_URL} → {png_path}")
        urllib.request.urlretrieve(ICON_URL, png_path)
        try:
            from PIL import Image  # noqa: WPS433

            img = Image.open(png_path).convert("RGBA")
            img.save(
                ICON_PATH,
                format="ICO",
                sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)],
            )
            print(f"[icon] Saved → {ICON_PATH}")
            return ICON_PATH
        except ImportError:
            print("[icon] Pillow not available, skipping .ico conversion (using .png)")
            return png_path
    except Exception as exc:  # noqa: BLE001
        print(f"[icon] FAIL: {exc} — building without custom icon")
        return None


def run_pyinstaller(icon: Path | None) -> None:
    cmd = [
        sys.executable,
        "-m",
        "PyInstaller",
        f"--name={APP_NAME}",
        "--onefile",
        "--windowed",
        "--clean",
        "--noconfirm",
        "--hidden-import=pypresence",
        "--hidden-import=comtypes",
        "--hidden-import=comtypes.client",
        f"--distpath={DIST}",
        f"--workpath={BUILD}",
        f"--specpath={BUILD}",
    ]
    if icon and icon.exists():
        cmd.append(f"--icon={icon}")
    cmd.append(str(ROOT / "main.py"))

    print("[pyinstaller] " + " ".join(cmd))
    subprocess.run(cmd, check=True)


def main() -> int:
    if sys.platform != "win32":
        print("WARNING: nie jesteś na Windows — wynik nie będzie .exe.")

    icon = ensure_icon()
    DIST.mkdir(exist_ok=True)
    BUILD.mkdir(exist_ok=True)

    run_pyinstaller(icon)

    exe = DIST / f"{APP_NAME}.exe"
    if exe.exists():
        print(f"\n✓ Built: {exe} ({exe.stat().st_size // 1024 // 1024} MB)")
        try:
            import shutil
            from pathlib import Path as _Path
            project_root = _Path(__file__).resolve().parents[1]
            builds_dir = project_root / "storage" / "builds"
            builds_dir.mkdir(parents=True, exist_ok=True)
            target = builds_dir / f"{APP_NAME}.exe"
            shutil.copy2(exe, target)
            print(f"  Copied to: {target}")
            print(f"  Upload {target} to SERVER's storage/builds/ for /downloads/{APP_NAME}.exe to work")
        except Exception as exc:
            print(f"  ⚠ Could not copy to storage/builds/: {exc}")
        return 0
    print("\n✗ Build finished but executable not found.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
