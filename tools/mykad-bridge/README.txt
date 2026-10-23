=======================================================
Estatell MyKad Smart Card Reader Bridge
=======================================================

Most front desks do NOT need this zip.
Use the one-click installer from the "Install Bridge" button in Estatell:
- Windows: estatell-mykad-setup.exe
- Mac:     estatell-mykad.pkg
Open it once. The bridge then starts by itself every time the computer starts.

This zip is the manual fallback (Linux, or troubleshooting).

Compatible hardware:
- ACS ACR39U Smart Card Reader (USB)
- Standard CCID USB contact smart card readers

Requirements:
- Python 3.8+
- The reader plugged into a USB port

Manual start:
1. Windows: double-click "run_bridge.bat".
2. Mac / Linux: open Terminal in this folder and run:
     chmod +x run_bridge.sh
     ./run_bridge.sh
3. Keep the window open while you use Estatell.

In Estatell, the MyKad panel on check-in and guest forms shows "ready".
Insert the guest's MyKad chip first. The form fills by itself.

Notes:
- The bridge listens on http://127.0.0.1:9292. Only this computer can reach it.
- Only Estatell (app.estatell.com, demo.estatell.com, localhost) may read cards.
  Add other addresses with the ESTATELL_BRIDGE_ORIGINS environment variable
  (comma separated).
- Log file:
    Windows: %LOCALAPPDATA%\Estatell\estatell-mykad-bridge.log
    Mac:     ~/Library/Logs/estatell-mykad-bridge.log
