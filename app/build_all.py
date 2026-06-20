"""Build obu pakietów: Desktop (.exe) + Android (.apk) sekwencyjnie."""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent


def run(script: str) -> int:
    print(f"\n{'=' * 60}\n  {script}\n{'=' * 60}")
    rc = subprocess.run([sys.executable, str(ROOT / script)]).returncode
    if rc != 0:
        print(f"[{script}] zwrócił {rc}")
    return rc


def main() -> int:
    desktop_rc = run("build_desktop.py")
    android_rc = run("build_android.py")
    print("\n--- summary ---")
    print(f"  desktop: {'OK' if desktop_rc == 0 else 'FAIL'}")
    print(f"  android: {'OK' if android_rc == 0 else 'FAIL'}")
    return 0 if desktop_rc == 0 and android_rc == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
