#!/usr/bin/env bash
# Starts the Estatell / Wastays MyKad Smart Card Bridge locally
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "===================================================="
echo "  Estatell / Wastays MyKad Smart Card Reader Bridge"
echo "===================================================="
echo ""

if [ -x "/opt/homebrew/bin/python3.11" ]; then
  PYTHON_BIN="/opt/homebrew/bin/python3.11"
elif command -v python3.11 >/dev/null 2>&1; then
  PYTHON_BIN="python3.11"
elif command -v python3 >/dev/null 2>&1; then
  PYTHON_BIN="python3"
else
  echo "[Error] Python 3 is required to run the card bridge."
  echo "Please install Python 3 (e.g. via Homebrew or python.org)."
  exit 1
fi

if ! "$PYTHON_BIN" -c "import smartcard" >/dev/null 2>&1; then
  echo "Installing required library 'pyscard'..."
  "$PYTHON_BIN" -m pip install pyscard
fi

echo "Starting MyKad bridge on http://127.0.0.1:9292..."
echo "Leave this terminal open while using the front desk portal."
echo ""

exec "$PYTHON_BIN" "$DIR/mykad_bridge.py" "$@"
