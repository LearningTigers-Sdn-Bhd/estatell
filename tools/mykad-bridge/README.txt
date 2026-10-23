=======================================================
Estatell MyKad Smart Card Reader Bridge Setup Guide
=======================================================

Compatible Hardware:
- ACS ACR39U Smart Card Reader (USB)
- Standard CCID USB Contact Smart Card Readers

Requirements:
- Python 3.8+ (Windows, macOS, or Linux)
- Physical ACR39U reader plugged into USB port

Quick Start:
1. Windows:
   - Double-click "run_bridge.bat".
   - If prompted, allow Python to access private networks (localhost only).

2. Mac / Linux:
   - Open Terminal, navigate to this folder, and run:
     chmod +x run_bridge.sh
     ./run_bridge.sh

3. Open Estatell in Google Chrome or Safari:
   - The "MyKad Reader" indicator on check-in or guest forms will show a green dot: "Ready".
   - Insert a guest's MyKad chip-side-up and click "Scan MyKad".
   - The guest's full name, IC number, date of birth, gender, address, and portrait will auto-populate instantly.

Notes:
- The bridge daemon runs on http://127.0.0.1:9292.
- Keep the terminal / command prompt window running while using the front desk portal.
