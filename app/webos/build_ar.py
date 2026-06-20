#!/usr/bin/env python3
"""Build .ipk (ar archive) without external `ar` tool.

The .ipk format is a Unix `ar` archive containing:
  - debian-binary
  - control.tar.gz
  - data.tar.gz

This script reads pre-built .tar.gz components and packs them into ar format.
Run AFTER you've built control.tar.gz and data.tar.gz (e.g. via tar).
"""
import os
import sys
import time

BUILD_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.build')
OUTPUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pl.leszczynowa5.doniixify_1.0.4_all.ipk')

FILES_ORDER = ['debian-binary', 'control.tar.gz', 'data.tar.gz']


def make_ar_header(filename: str, size: int, mtime: int) -> bytes:
    """Build 60-byte ar file header."""
    name = filename.ljust(16)[:16]
    mtime_s = str(mtime).ljust(12)[:12]
    uid = '0'.ljust(6)
    gid = '0'.ljust(6)
    mode = '100644'.ljust(8)
    size_s = str(size).ljust(10)[:10]
    end = '\x60\x0a'  # 0x60 0x0A
    header = f"{name}{mtime_s}{uid}{gid}{mode}{size_s}{end}"
    return header.encode('ascii')


def main():
    if not os.path.isdir(BUILD_DIR):
        print(f"[!] Missing build dir: {BUILD_DIR}")
        sys.exit(1)

    for f in FILES_ORDER:
        p = os.path.join(BUILD_DIR, f)
        if not os.path.isfile(p):
            print(f"[!] Missing: {p}")
            sys.exit(1)

    mtime = int(time.time())

    with open(OUTPUT, 'wb') as out:
        out.write(b'!<arch>\n')
        for fname in FILES_ORDER:
            path = os.path.join(BUILD_DIR, fname)
            with open(path, 'rb') as f:
                data = f.read()
            out.write(make_ar_header(fname, len(data), mtime))
            out.write(data)
            if len(data) % 2 == 1:
                out.write(b'\n')

    size_kb = os.path.getsize(OUTPUT) // 1024
    print(f"[+] Built: {OUTPUT} ({size_kb}KB)")


if __name__ == '__main__':
    main()
