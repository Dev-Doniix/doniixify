#!/bin/bash
# Build .ipk for webOS without webOS CLI
# Requires: tar, ar (standard on any Linux)
# Usage: ./build.sh
# Output: pl.leszczynowa5.music_1.0.0_all.ipk

set -e

APP_ID="pl.leszczynowa5.doniixify"
VERSION="1.0.3"
ARCH="all"
HERE="$(cd "$(dirname "$0")" && pwd)"
BUILD_DIR="$HERE/.build"
OUTPUT="$HERE/${APP_ID}_${VERSION}_${ARCH}.ipk"

echo "[+] Building $APP_ID v$VERSION"

# Sanity check required files
for f in appinfo.json index.html icon.png largeIcon.png; do
    if [ ! -f "$HERE/$f" ]; then
        echo "[!] Missing required file: $f"
        exit 1
    fi
done

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/data/usr/palm/applications/$APP_ID"
mkdir -p "$BUILD_DIR/control"

# Copy app files into data/usr/palm/applications/<id>/
cp "$HERE/appinfo.json"   "$BUILD_DIR/data/usr/palm/applications/$APP_ID/"
cp "$HERE/index.html"     "$BUILD_DIR/data/usr/palm/applications/$APP_ID/"
cp "$HERE/icon.png"       "$BUILD_DIR/data/usr/palm/applications/$APP_ID/"
cp "$HERE/largeIcon.png"  "$BUILD_DIR/data/usr/palm/applications/$APP_ID/"

# Optional: copy extra assets if present
[ -d "$HERE/assets" ] && cp -r "$HERE/assets" "$BUILD_DIR/data/usr/palm/applications/$APP_ID/"

# Calculate installed size in bytes
INSTALLED_SIZE=$(du -sb "$BUILD_DIR/data/usr" | cut -f1)

# Generate control file
cat > "$BUILD_DIR/control/control" <<EOF
Package: $APP_ID
Version: $VERSION
Section: misc
Priority: optional
Architecture: $ARCH
Installed-Size: $INSTALLED_SIZE
Maintainer: Doniixify <admin@leszczynowa5.pl>
Description: Doniixify — music streaming for webOS
 PWA wrapper for music.leszczynowa5.pl
 Subsonic-compatible library with Spotify integration.
webOS-Package-Format-Version: 2
webOS-Manifest-Version: 1
EOF

# Build data.tar.gz
cd "$BUILD_DIR/data"
tar czf ../data.tar.gz ./usr
cd "$BUILD_DIR"

# Build control.tar.gz
cd control
tar czf ../control.tar.gz ./control
cd ..

# debian-binary version marker
echo "2.0" > debian-binary

# Build the .ipk (ar archive)
rm -f "$OUTPUT"
ar -r "$OUTPUT" debian-binary control.tar.gz data.tar.gz 2>/dev/null

# Clean intermediate
cd "$HERE"
rm -rf "$BUILD_DIR"

if [ -f "$OUTPUT" ]; then
    SIZE_KB=$(du -k "$OUTPUT" | cut -f1)
    echo "[✓] Built: $OUTPUT (${SIZE_KB}KB)"
    echo ""
    echo "=== Install on LG TV (no CLI needed) ==="
    echo "  1. Enable Developer Mode on TV:"
    echo "     Settings → All Settings → General → About this TV → User Agreement"
    echo "     Then install 'Developer Mode' app from LG Content Store"
    echo "     Log in with dev account from https://webostv.developer.lge.com"
    echo "  2. Turn on Developer Mode + Key Server in the app on TV"
    echo "  3. Two install options:"
    echo ""
    echo "  Option A — via web (easiest):"
    echo "     Copy this .ipk to USB, plug into TV"
    echo "     In Developer Mode app → Install from USB → select this file"
    echo ""
    echo "  Option B — via SSH from your PC:"
    echo "     ssh -p 9922 prisoner@<tv-ip> 'mkdir -p /media/developer/apps/usr/palm/applications/$APP_ID'"
    echo "     scp -P 9922 $OUTPUT prisoner@<tv-ip>:/tmp/"
    echo "     ssh -p 9922 prisoner@<tv-ip> 'cd /tmp && opkg install $(basename $OUTPUT)'"
    echo ""
    echo "  Option C — over network via HTTP:"
    echo "     Upload .ipk to a publicly accessible URL"
    echo "     On TV browser visit: http://<your-server>/install.html"
    echo "     (see README.md for install.html template)"
else
    echo "[!] Build failed"
    exit 1
fi
