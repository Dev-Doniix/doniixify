"""Build Doniixify.apk (Android) — native WebView wrapper.

Replaces the previous bubblewrap-based TWA with a self-contained WebView APK.
The new APK doesn't depend on Chrome/Brave/any installed browser — it uses
Android System WebView (shipped with every device since Lollipop 2015).

Same packageId / keystore as the old TWA → installs as an UPDATE,
not a separate app.

Usage:    py build_android.py
Output:   app/android/app/build/outputs/apk/release/app-release.apk
          copied to storage/builds/Doniixify.apk

First build ~2-5 min (gradle downloads). Subsequent builds <30s.
After upload via FTP, install on phone — same signature as old TWA,
so Android treats it as a drop-in update (preserves user data).
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import urllib.request
from pathlib import Path

import config

ROOT = Path(__file__).parent
ANDROID_DIR = ROOT / "android"
CFG = config.load()

KEYSTORE_PASSWORD = "doniixify"
KEYSTORE_ALIAS = "android"


def require_binary(name: str, install_hint: str) -> str:
    found = shutil.which(name) or shutil.which(name + ".cmd") or shutil.which(name + ".exe")
    if found is None:
        print(f"[err] Missing {name}. {install_hint}")
        sys.exit(1)
    return found


def find_jdk() -> Path | None:
    """Locate a usable JDK 17+. Prefer JAVA_HOME, fallback to bubblewrap's JDK."""
    jhome = os.environ.get("JAVA_HOME")
    if jhome:
        p = Path(jhome)
        if (p / "bin" / ("javac.exe" if sys.platform == "win32" else "javac")).exists():
            return p
    bw_jdk_root = Path.home() / ".bubblewrap" / "jdk"
    if bw_jdk_root.exists():
        candidates = sorted(
            [p for p in bw_jdk_root.iterdir() if p.is_dir() and p.name.startswith("jdk")],
            reverse=True,
        )
        for c in candidates:
            if (c / "bin" / ("javac.exe" if sys.platform == "win32" else "javac")).exists():
                return c
    return None


def find_android_sdk() -> Path | None:
    candidates = [
        os.environ.get("ANDROID_HOME"),
        os.environ.get("ANDROID_SDK_ROOT"),
        str(Path.home() / "AppData" / "Local" / "Android" / "Sdk"),
        str(Path.home() / ".bubblewrap" / "android_sdk"),
        str(Path.home() / "Android" / "Sdk"),
    ]
    for c in filter(None, candidates):
        p = Path(c)
        if p.exists() and (p / "platforms").exists():
            return p
    return None


def jdk_path_addition() -> dict:
    """Return env dict that adds JDK bin/ to PATH so jarsigner/keytool are found."""
    jdk = find_jdk()
    if jdk is None:
        return {}
    bin_dir = str(jdk / "bin")
    sep = ";" if sys.platform == "win32" else ":"
    new_path = bin_dir + sep + os.environ.get("PATH", "")
    print(f"[jdk] {jdk}")
    return {"PATH": new_path, "JAVA_HOME": str(jdk)}


def accept_android_licenses() -> None:
    home = Path.home()
    sdk_candidates = [
        os.environ.get("ANDROID_HOME"),
        os.environ.get("ANDROID_SDK_ROOT"),
        str(home / "AppData" / "Local" / "Android" / "Sdk"),
        str(home / ".bubblewrap" / "android_sdk"),
        str(home / "Android" / "Sdk"),
    ]
    for sdk_root in filter(None, sdk_candidates):
        sdk_path = Path(sdk_root)
        if not sdk_path.exists():
            continue
        licenses_dir = sdk_path / "licenses"
        licenses_dir.mkdir(parents=True, exist_ok=True)
        license_files = {
            "android-sdk-license": "\n24333f8a63b6825ea9c5514f83c2829b004d1fee\n",
            "android-sdk-preview-license": "\n84831b9409646a918e30573bab4c9c91346d8abd\n",
            "android-sdk-arm-dbt-license": "\n859f317696f67ef3d7f30a50a5560e7834b43903\n",
            "google-gdk-license": "\n33b6a2b64607f11b759f320ef9dff4ae5c47d97a\n",
            "intel-android-extra-license": "\nd975f751698a77b662f1254ddbeed3901e976f5a\n",
            "mips-android-sysimage-license": "\n",
        }
        for name, content in license_files.items():
            f = licenses_dir / name
            try:
                f.write_text(content, encoding="utf-8")
            except Exception:
                pass
        print(f"[license] pre-accepted SDK licenses at {licenses_dir}")
        return


def ensure_keystore() -> Path:
    """Return path to a usable keystore, generate one if missing."""
    local = ANDROID_DIR / "android.keystore"
    if local.exists():
        print(f"[keystore] using {local}")
        return local

    keytool = shutil.which("keytool") or shutil.which("keytool.exe")
    if keytool is None:
        jdk = find_jdk()
        if jdk is not None:
            cand = jdk / "bin" / ("keytool.exe" if sys.platform == "win32" else "keytool")
            if cand.exists():
                keytool = str(cand)
    if keytool is None:
        print("[err] No keystore found and keytool not in PATH. Install JDK 17+ from https://adoptium.net/")
        sys.exit(1)

    name = CFG.get("name", "Doniixify")
    dname = f"CN={name},OU=Builder,O={name},L=Warsaw,S=PL,C=PL"
    print(f"[keystore] generating {local}...")
    subprocess.run([
        keytool, "-genkeypair", "-v",
        "-keystore", str(local),
        "-alias", KEYSTORE_ALIAS,
        "-keyalg", "RSA", "-keysize", "2048", "-validity", "10000",
        "-storepass", KEYSTORE_PASSWORD,
        "-keypass", KEYSTORE_PASSWORD,
        "-dname", dname,
        "-noprompt",
    ], check=True)
    return local


def warn_keystore_backup(keystore: Path) -> None:
    backup_marker = Path.home() / "Doniixify-keystore-backup.txt"
    if backup_marker.exists():
        return
    bar = "=" * 72
    print()
    print(bar)
    print("WARNING: BACKUP YOUR KEYSTORE")
    print(bar)
    print(f"Copy `{keystore}` + password `{KEYSTORE_PASSWORD}` to 3 LOCATIONS:")
    print("  - cloud (Google Drive / Dropbox / iCloud)")
    print("  - USB stick / external drive")
    print("  - email (encrypted attachment to yourself)")
    print()
    print("Without this you CANNOT update the published Android app.")
    print(f"Create `{backup_marker}` to silence this warning after backup.")
    print(bar)
    print()


def check_gradle_wrapper_jar() -> None:
    jar = ANDROID_DIR / "gradle" / "wrapper" / "gradle-wrapper.jar"
    if jar.exists() and jar.stat().st_size > 1000:
        return
    print("[err] Missing gradle-wrapper.jar")
    print(f"     Copy gradle-wrapper.jar from any Android Studio project to:")
    print(f"     {jar}")
    print(f"     Or download from: https://services.gradle.org/distributions/gradle-8.9-bin.zip")
    print(f"     (extract gradle-8.9/lib/plugins/gradle-wrapper-*.jar)")
    sys.exit(1)


def run_gradle(env: dict) -> int:
    if sys.platform == "win32":
        cmd = [str(ANDROID_DIR / "gradlew.bat"), "assembleRelease", "--no-daemon", "--stacktrace"]
    else:
        cmd = ["./gradlew", "assembleRelease", "--no-daemon", "--stacktrace"]
    print(f"[gradle] Running ./gradlew assembleRelease...")
    print(f"         (first run downloads ~150MB gradle distribution + dependencies)")
    run_env = os.environ.copy()
    run_env.update(env)
    result = subprocess.run(
        cmd,
        cwd=str(ANDROID_DIR),
        env=run_env,
        shell=False,
    )
    return result.returncode


def find_output_apk() -> Path | None:
    apk = ANDROID_DIR / "app" / "build" / "outputs" / "apk" / "release" / "app-release.apk"
    if apk.exists():
        return apk
    unsigned = ANDROID_DIR / "app" / "build" / "outputs" / "apk" / "release" / "app-release-unsigned.apk"
    if unsigned.exists():
        return unsigned
    return None


def is_apk_signed(apk: Path, env: dict) -> bool:
    jarsigner = shutil.which("jarsigner") or shutil.which("jarsigner.exe")
    if jarsigner is None:
        jdk = find_jdk()
        if jdk is not None:
            cand = jdk / "bin" / ("jarsigner.exe" if sys.platform == "win32" else "jarsigner")
            if cand.exists():
                jarsigner = str(cand)
    if jarsigner is None:
        return False
    run_env = os.environ.copy()
    run_env.update(env)
    result = subprocess.run(
        [jarsigner, "-verify", str(apk)],
        capture_output=True, text=True, env=run_env,
    )
    return "jar verified" in (result.stdout + result.stderr).lower()


def sign_apk(apk: Path, keystore: Path, env: dict) -> bool:
    jarsigner = shutil.which("jarsigner") or shutil.which("jarsigner.exe")
    if jarsigner is None:
        jdk = find_jdk()
        if jdk is not None:
            cand = jdk / "bin" / ("jarsigner.exe" if sys.platform == "win32" else "jarsigner")
            if cand.exists():
                jarsigner = str(cand)
    if jarsigner is None:
        print("[sign] jarsigner not found — install JDK 17+")
        return False
    print(f"[sign] Signing APK with keystore...")
    run_env = os.environ.copy()
    run_env.update(env)
    result = subprocess.run(
        [
            jarsigner, "-verbose",
            "-sigalg", "SHA256withRSA",
            "-digestalg", "SHA-256",
            "-keystore", str(keystore),
            "-storepass", KEYSTORE_PASSWORD,
            "-keypass", KEYSTORE_PASSWORD,
            str(apk),
            KEYSTORE_ALIAS,
        ],
        env=run_env,
    )
    return result.returncode == 0


def extract_keystore_sha256(keystore: Path, env: dict) -> str | None:
    keytool = shutil.which("keytool") or shutil.which("keytool.exe")
    if keytool is None:
        jdk = find_jdk()
        if jdk is not None:
            cand = jdk / "bin" / ("keytool.exe" if sys.platform == "win32" else "keytool")
            if cand.exists():
                keytool = str(cand)
    if keytool is None:
        return None
    run_env = os.environ.copy()
    run_env.update(env)
    try:
        result = subprocess.run(
            [keytool, "-list", "-v", "-keystore", str(keystore),
             "-storepass", KEYSTORE_PASSWORD, "-alias", KEYSTORE_ALIAS],
            capture_output=True, text=True, timeout=30, env=run_env,
        )
        out = result.stdout + result.stderr
    except Exception as exc:
        print(f"[assetlinks] keytool extraction failed: {exc}")
        return None
    m = re.search(r"SHA256:\s*((?:[0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2})", out)
    if not m:
        return None
    return m.group(1).upper()


def generate_assetlinks(keystore: Path, env: dict) -> Path | None:
    """Keep assetlinks.json published — harmless for WebView, useful if user
    ever reverts to TWA or wants Android App Links."""
    fp = extract_keystore_sha256(keystore, env)
    if fp is None:
        print("[assetlinks] could not extract keystore SHA-256 fingerprint")
        return None
    package_id = CFG.get("package_id", "")
    if not package_id:
        return None
    payload = [{
        "relation": ["delegate_permission/common.handle_all_urls"],
        "target": {
            "namespace": "android_app",
            "package_name": package_id,
            "sha256_cert_fingerprints": [fp],
        },
    }]
    project_root = Path(__file__).resolve().parents[1]
    out_dir = project_root / "storage" / "builds"
    out_dir.mkdir(parents=True, exist_ok=True)
    out_file = out_dir / "assetlinks.json"
    out_file.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    webroot_wk = project_root / ".well-known"
    webroot_wk.mkdir(parents=True, exist_ok=True)
    webroot_file = webroot_wk / "assetlinks.json"
    webroot_file.write_text(json.dumps(payload, indent=2), encoding="utf-8")
    print(f"[assetlinks] wrote {out_file}")
    print(f"[assetlinks] ALSO wrote {webroot_file}")
    print(f"[assetlinks] SHA-256: {fp}")
    print(f"[assetlinks] Package: {package_id}")
    return out_file


def main() -> int:
    if not ANDROID_DIR.exists():
        print(f"[err] {ANDROID_DIR} missing — was the project scaffold created?")
        return 1

    jdk = find_jdk()
    if jdk is None:
        print("[err] No JDK 17+ found. Install from https://adoptium.net/")
        print("      (set JAVA_HOME or install Android Studio bundled JDK)")
        return 1

    sdk = find_android_sdk()
    if sdk is None:
        print("[warn] Android SDK not found in usual paths.")
        print("       Gradle will fail unless ANDROID_HOME points to a valid SDK.")
        print("       Install via Android Studio or run: sdkmanager 'platforms;android-34'")

    check_gradle_wrapper_jar()
    accept_android_licenses()

    keystore = ensure_keystore()
    warn_keystore_backup(keystore)

    env = jdk_path_addition()
    if sdk is not None:
        env["ANDROID_HOME"] = str(sdk)
        env["ANDROID_SDK_ROOT"] = str(sdk)

    generate_assetlinks(keystore, env)

    rc = run_gradle(env)
    apk = find_output_apk()
    if rc != 0 and apk is None:
        print(f"\n[err] Gradle build failed (rc={rc}) and no APK produced.")
        print(f"      Inspect logs above.")
        return 1

    if apk is None:
        print("\n[err] Build finished but no APK file found.")
        return 1

    if not is_apk_signed(apk, env):
        if not sign_apk(apk, keystore, env):
            print("[err] Signing failed.")
            return 1

    project_root = Path(__file__).resolve().parents[1]
    builds_dir = project_root / "storage" / "builds"
    builds_dir.mkdir(parents=True, exist_ok=True)
    target = builds_dir / "Doniixify.apk"
    try:
        shutil.copy2(apk, target)
    except Exception as exc:
        print(f"[err] Could not copy to {target}: {exc}")
        return 1

    size_kb = target.stat().st_size // 1024
    print()
    print(f"[done] APK ready at {target} ({size_kb} KB)")
    print(f"       Source: {apk}")
    print(f"       Upload {target} to SERVER's storage/builds/ for /downloads/Doniixify.apk")
    return 0


if __name__ == "__main__":
    sys.exit(main())
