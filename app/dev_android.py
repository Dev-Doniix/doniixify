"""Doniixify Android dev — uruchom aplikację na podłączonym urządzeniu/emulatorze.

Tryby:
    python dev_android.py             → szybkie: otwiera music.leszczynowa5.pl w Chrome
    python dev_android.py --apk       → install + run zbudowane APK (wymaga build_android.py najpierw)
    python dev_android.py --logcat    → tail logu (debug)
    python dev_android.py --uninstall → odinstaluj testowy APK

Wymaga:
    - adb w PATH (część Android Platform Tools)
      https://developer.android.com/tools/releases/platform-tools
    - Telefon z włączonym USB debugging LUB Android emulator (Android Studio AVD)
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent
APP_URL = "https://music.leszczynowa5.pl/"
PACKAGE_ID_FALLBACK = "pl.leszczynowa5.music"


def require_adb() -> None:
    if shutil.which("adb") is None:
        print("✗ adb nie znalezione w PATH.")
        print("  Zainstaluj Android Platform Tools:")
        print("    https://developer.android.com/tools/releases/platform-tools")
        print("  Rozpakuj, dodaj folder do PATH, restart cmd.")
        sys.exit(1)


def list_devices() -> list[str]:
    out = subprocess.check_output(["adb", "devices"], text=True)
    devices: list[str] = []
    for line in out.strip().splitlines()[1:]:
        parts = line.split()
        if len(parts) >= 2 and parts[1] == "device":
            devices.append(parts[0])
    return devices


def ensure_device(timeout_s: int = 30) -> str:
    devices = list_devices()
    if not devices:
        print("✗ Brak podłączonych urządzeń.")
        print("  Opcje:")
        print("  1) Telefon przez USB z 'USB debugging' (Settings → Developer options).")
        print("     Po podłączeniu telefon zapyta o trust — kliknij Allow.")
        print("  2) Android emulator (Android Studio → Device Manager → start AVD).")
        print(f"\n[adb] czekam max {timeout_s}s na urządzenie...")
        try:
            subprocess.run(
                ["adb", "wait-for-device"],
                check=True,
                timeout=timeout_s,
            )
        except subprocess.TimeoutExpired:
            print("✗ Timeout — nie wykryto urządzenia.")
            sys.exit(1)
        devices = list_devices()
    if not devices:
        sys.exit(1)
    if len(devices) > 1:
        print(f"[adb] wykryto {len(devices)} urządzeń, używam pierwsze: {devices[0]}")
    print(f"[adb] device: {devices[0]}")
    return devices[0]


def detect_package_id() -> str:
    """Próba wyczytania package ID z wygenerowanego twa-manifest.json."""
    manifest = ROOT / "android" / "twa-manifest.json"
    if manifest.exists():
        try:
            import json

            data = json.loads(manifest.read_text(encoding="utf-8"))
            pkg = data.get("packageId")
            if isinstance(pkg, str) and pkg:
                return pkg
        except Exception:  # noqa: BLE001
            pass
    return PACKAGE_ID_FALLBACK


def open_url() -> None:
    device = ensure_device()
    print(f"[adb] otwieram {APP_URL} w Chrome na {device}")
    subprocess.run(
        [
            "adb", "-s", device, "shell",
            "am", "start", "-a", "android.intent.action.VIEW",
            "-d", APP_URL,
        ],
        check=True,
    )
    print("✓ Aplikacja otwarta. Dodaj do Home Screen z menu Chrome (⋮) żeby zainstalować jako PWA.")


def find_apk() -> Path | None:
    candidates = [
        ROOT / "android" / "app-release-signed.apk",
        ROOT / "android" / "app-release-unsigned.apk",
        ROOT / "android" / "app" / "build" / "outputs" / "apk" / "release" / "app-release.apk",
        ROOT / "android" / "app" / "build" / "outputs" / "apk" / "debug" / "app-debug.apk",
    ]
    for c in candidates:
        if c.exists():
            return c
    return None


def install_apk() -> None:
    device = ensure_device()
    apk = find_apk()
    if apk is None:
        print("✗ Brak APK. Najpierw: python build_android.py")
        sys.exit(1)

    pkg = detect_package_id()
    print(f"[adb] install {apk.name} ({apk.stat().st_size // 1024} KB)")
    subprocess.run(["adb", "-s", device, "install", "-r", str(apk)], check=True)
    print(f"[adb] start {pkg}")
    subprocess.run(
        [
            "adb", "-s", device, "shell",
            "monkey", "-p", pkg,
            "-c", "android.intent.category.LAUNCHER", "1",
        ],
        check=True,
    )
    print("✓ APK zainstalowane i uruchomione.")


def uninstall_apk() -> None:
    device = ensure_device()
    pkg = detect_package_id()
    print(f"[adb] uninstall {pkg}")
    subprocess.run(["adb", "-s", device, "uninstall", pkg])


def logcat() -> None:
    device = ensure_device()
    pkg = detect_package_id()
    print(f"[adb] logcat (Ctrl+C aby wyjść) | filter: chromium + {pkg}")
    subprocess.run(
        [
            "adb", "-s", device, "logcat",
            "-s",
            "chromium:V",
            f"{pkg}:V",
            "AndroidRuntime:E",
        ]
    )


def main() -> int:
    require_adb()
    parser = argparse.ArgumentParser(description="Doniixify Android dev launcher")
    parser.add_argument("--apk", action="store_true", help="install + run zbudowane APK")
    parser.add_argument("--logcat", action="store_true", help="tail logu z urządzenia")
    parser.add_argument("--uninstall", action="store_true", help="odinstaluj testowy APK")
    args = parser.parse_args()

    if args.logcat:
        logcat()
    elif args.uninstall:
        uninstall_apk()
    elif args.apk:
        install_apk()
    else:
        open_url()
    return 0


if __name__ == "__main__":
    sys.exit(main())
