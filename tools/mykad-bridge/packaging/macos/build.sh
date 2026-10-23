#!/bin/bash
# Builds dist/estatell-mykad.pkg. Run from anywhere on a Mac.
#   PYTHON=python3.12 TARGET_ARCH=universal2 packaging/macos/build.sh
# TARGET_ARCH is optional. universal2 needs a universal2 Python (the python.org installer).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRIDGE="$(cd "$HERE/../.." && pwd)"
WORK="$BRIDGE/build"
PYTHON="${PYTHON:-python3}"
VERSION="$(sed -n 's/^__version__ = "\(.*\)"/\1/p' "$BRIDGE/mykad_bridge.py")"
LABEL=com.estatell.mykad-bridge
SUPPORT="Library/Application Support/Estatell/MyKadBridge"

rm -rf "$WORK" "$BRIDGE/dist"
"$PYTHON" -m venv "$WORK/venv"
"$WORK/venv/bin/pip" install --quiet -r "$HERE/../requirements-build.txt"

ARCH_FLAG=()
if [ -n "${TARGET_ARCH:-}" ]; then
    ARCH_FLAG=(--target-arch "$TARGET_ARCH")
fi

"$WORK/venv/bin/pyinstaller" --noconfirm --clean --onedir \
    --name estatell-mykad-bridge \
    --distpath "$WORK/pyinstaller-dist" --workpath "$WORK/pyinstaller-work" --specpath "$WORK" \
    "${ARCH_FLAG[@]+"${ARCH_FLAG[@]}"}" \
    "$BRIDGE/mykad_bridge.py"

ROOT="$WORK/pkgroot"
mkdir -p "$ROOT/$SUPPORT" "$ROOT/Library/LaunchAgents"
cp -R "$WORK/pyinstaller-dist/estatell-mykad-bridge/." "$ROOT/$SUPPORT/"
cp "$HERE/uninstall.sh" "$ROOT/$SUPPORT/uninstall.sh"
cp "$HERE/$LABEL.plist" "$ROOT/Library/LaunchAgents/$LABEL.plist"
chmod 755 "$ROOT/$SUPPORT/uninstall.sh" "$HERE/scripts/preinstall" "$HERE/scripts/postinstall"
chmod 644 "$ROOT/Library/LaunchAgents/$LABEL.plist"
# Extended attributes would ship as ._* sidecar files.
xattr -cr "$ROOT"

# Bundles inside the payload (Python.framework) must install where we put them, never relocate.
pkgbuild --analyze --root "$ROOT" "$WORK/components.plist"
i=0
while /usr/libexec/PlistBuddy -c "Print :$i" "$WORK/components.plist" >/dev/null 2>&1; do
    /usr/libexec/PlistBuddy -c "Delete :$i:BundleIsRelocatable" "$WORK/components.plist" 2>/dev/null || true
    /usr/libexec/PlistBuddy -c "Add :$i:BundleIsRelocatable bool false" "$WORK/components.plist"
    i=$((i + 1))
done

mkdir -p "$BRIDGE/dist"
pkgbuild --root "$ROOT" --scripts "$HERE/scripts" --component-plist "$WORK/components.plist" \
    --identifier "$LABEL" --version "$VERSION" --install-location / \
    "$BRIDGE/dist/estatell-mykad.pkg"

echo "Built $BRIDGE/dist/estatell-mykad.pkg (version $VERSION)"
