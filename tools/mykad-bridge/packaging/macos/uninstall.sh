#!/bin/bash
# Removes the Estatell MyKad bridge. Run with:
#   sudo "/Library/Application Support/Estatell/MyKadBridge/uninstall.sh"
set -e
if [ "$(id -u)" != "0" ]; then
    echo "Run with sudo."
    exit 1
fi
LABEL=com.estatell.mykad-bridge
for uid in $(ps -axo uid=,comm= | awk '/loginwindow/ {print $1}' | sort -u); do
    launchctl bootout "gui/$uid/$LABEL" 2>/dev/null || true
done
pkill -f "Estatell/MyKadBridge/estatell-mykad-bridge" 2>/dev/null || true
rm -f "/Library/LaunchAgents/$LABEL.plist"
rm -rf "/Library/Application Support/Estatell/MyKadBridge"
pkgutil --forget "$LABEL" >/dev/null 2>&1 || true
echo "Estatell MyKad bridge removed."
