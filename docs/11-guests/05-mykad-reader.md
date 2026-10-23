# MyKad reader

## Purpose

Fill guest details straight from the chip on a guest's MyKad. Staff insert the card into a USB reader at the front desk. The guest form fills in under 2 seconds, and staff check it before saving.

## How it works

```
Browser (estatell)  ──HTTP──▶  MyKad bridge on 127.0.0.1:9292  ──PC/SC──▶  ACR39U reader  ──▶  MyKad chip
```

- The browser cannot talk to a USB card reader. A small local program, the **bridge** (`tools/mykad-bridge/mykad_bridge.py`), does it and serves the result over HTTP on `127.0.0.1:9292`.
- The bridge selects the JPN application on the chip with APDU commands. It reads the name, IC number, birth date, gender, address and portrait.
- It maps the address state to the LHDN state code, the same codes as `EInvoice::MalaysiaStates`.
- The bridge only listens on `127.0.0.1`. Other machines on the hotel network cannot reach it.

## Hardware

| Item    | Supported                                                                                                                  |
| ------- | -------------------------------------------------------------------------------------------------------------------------- |
| Readers | ACS ACR39U and other USB CCID contact readers. No driver is needed on Windows 10+ or macOS (to verify on a clean machine). |
| OS      | Windows 10+ (x64, and Arm through emulation), macOS 11+ (Apple Silicon and Intel)                                          |

## Bridge endpoints

| Route                          | What                                                                |
| ------------------------------ | ------------------------------------------------------------------- |
| `GET /status` (`/`, `/health`) | Whether a reader is attached and a card is present                  |
| `GET` or `POST /read`          | Read the card. Returns the fields above as JSON, portrait as base64 |

`/status` also returns the bridge `version`.

The bridge sends Chrome Private Network Access headers, so an HTTPS page can call `127.0.0.1`.

## Security

- **Allowed origins only.** The bridge answers `https://app.estatell.com`, `https://demo.estatell.com`, and any `localhost`, `127.0.0.1` or `*.test` origin for development. Any other `Origin` gets `403` before the card is touched, so another website open in the browser cannot read a card. Add origins with the `ESTATELL_BRIDGE_ORIGINS` environment variable (comma separated).
- **Host check.** A `Host` other than `127.0.0.1:9292` or `localhost:9292` gets `403`. This blocks DNS rebinding.
- **Logs** never hold card data, only requests and errors. Windows: `%LOCALAPPDATA%\Estatell\estatell-mykad-bridge.log`. macOS: `~/Library/Logs/estatell-mykad-bridge.log`. Capped at 1 MB plus 2 old files.

## In the app

- `useMykadReader` (`resources/js/hooks/use-mykad-reader.ts`) polls `/status`. When a card is inserted, it reads the card and fills the active form.
- `MykadScanner` (`resources/js/components/pms/mykad-scanner.tsx`) shows the status: Ready, No reader, or Bridge offline.
- When the bridge is offline, **Install Bridge** offers the file for the staff member's OS (`bridgeDownload()`): the `.exe` on Windows, the `.pkg` on macOS, the zip elsewhere.
- When `/status` reports a version older than `REQUIRED_BRIDGE_VERSION` (or none, as in 1.0), the scanner shows **Install update**. Scanning still works. Bump both versions together when a bridge change needs the new build.
- Touchpoints: the check-in dialog, the guests section on New reservation and Walk-in, the reservation Guests tab, the registration card, and the guest document sheet.
- When staff add a document from a scan, the portrait becomes the `id_front` file. See [Identity documents](02-identity-documents.md).
- The server still validates and stores everything. The bridge only fills the form.

## Install on a front-desk PC

### One-click installer

Staff click **Install Bridge**, open the file once, and forget it. No Python and no terminal.

| OS      | File                       | What it does                                                                                                                                                                                                                                                    |
| ------- | -------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Windows | `estatell-mykad-setup.exe` | One admin prompt. Installs to `Program Files\Estatell MyKad Bridge`. Adds `EstatellMyKadBridge` to `HKLM\Software\Microsoft\Windows\CurrentVersion\Run`, so the bridge starts hidden for every user at login. Starts at once. Uninstall from Apps & features.   |
| macOS   | `estatell-mykad.pkg`       | Admin password once. Installs to `/Library/Application Support/Estatell/MyKadBridge`. Adds `/Library/LaunchAgents/com.estatell.mykad-bridge.plist` (`RunAtLoad`, `KeepAlive` on crash), so it starts for every user and restarts after a crash. Starts at once. |

Why this approach:

- **Python is bundled** (PyInstaller), so the PC needs nothing installed first.
- **Per machine, at login**: front desks often share a PC between staff accounts. The bridge runs in the user's session, where the card reader is reliably available. A Windows service runs in session 0 and is harder to debug.
- **One instance.** If port 9292 is taken, a new copy exits with code 0. launchd does not restart a clean exit. On Windows the bridge claims the port with `SO_EXCLUSIVEADDRUSE`, so two copies cannot share it.
- **Updates**: opening a newer installer stops the old bridge, replaces it and starts the new one.

Uninstall:

- Windows: Settings → Apps → Estatell MyKad Bridge.
- macOS: `sudo "/Library/Application Support/Estatell/MyKadBridge/uninstall.sh"`.

### First install warnings (unsigned)

The installers are not code signed. Staff see a warning the first time:

| OS      | What staff do                                                                                                                                 |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Windows | SmartScreen "Windows protected your PC": click **More info → Run anyway**. Some antivirus tools flag unsigned PyInstaller programs; allow it. |
| macOS   | "cannot be opened": open **System Settings → Privacy & Security** and click **Open Anyway**, then open the pkg again.                         |

### Manual fallback

`public/downloads/mykad-bridge.zip` holds `mykad_bridge.py` and `run_bridge.bat` / `run_bridge.sh`. They need Python 3.8+, install `pyscard` on first run, and stop when the window closes. Use them on Linux or to troubleshoot.

## Build and release

Files live in `tools/mykad-bridge/`:

| File                                  | Purpose                                                                    |
| ------------------------------------- | -------------------------------------------------------------------------- |
| `mykad_bridge.py`                     | The bridge. `__version__` is the release version.                          |
| `packaging/requirements-build.txt`    | Pinned PyInstaller and pyscard. Build time only.                           |
| `packaging/windows/installer.iss`     | Inno Setup script for the `.exe`.                                          |
| `packaging/macos/build.sh`            | PyInstaller and `pkgbuild` for the `.pkg`. Also runs locally on a Mac.     |
| `packaging/macos/*.plist`, `scripts/` | LaunchAgent, preinstall (stop old bridge), postinstall (start new bridge). |
| `packaging/macos/uninstall.sh`        | Installed next to the bridge.                                              |
| `.github/workflows/mykad-bridge.yml`  | CI build.                                                                  |

PyInstaller cannot cross-compile, so CI builds each OS on its own runner:

- **Windows** (`windows-latest`): Python 3.12, PyInstaller `--onedir --noconsole`, then Inno Setup 6.
- **macOS** (`macos-latest`): the python.org Python 3.12 (universal2), PyInstaller `--target-arch universal2`, then `pkgbuild`. One pkg runs on Apple Silicon and Intel Macs.
- Both smoke test the built program: `/status` returns a version, and a foreign origin gets `403`.

Tools and licences: PyInstaller is GPL with an exception that allows shipping closed apps. Inno Setup is free for commercial use; since 6.5.0 the author asks commercial users to buy a licence, but it is not required. `pkgbuild` ships with macOS.

To release:

1. Bump `__version__` in `mykad_bridge.py`. If the app needs the new build, also bump `REQUIRED_BRIDGE_VERSION` in `use-mykad-reader.ts`.
2. In GitHub → Actions → **mykad-bridge** → **Run workflow**, pick the branch.
3. The `publish` job copies `estatell-mykad-setup.exe` and `estatell-mykad.pkg` into `public/downloads/`, rebuilds `mykad-bridge.zip`, and commits them to that branch.

Pull requests that touch `tools/mykad-bridge/` run the build and smoke tests without publishing.

## Open

- Run the workflow once so `public/downloads/` holds the installers. Until then the Install Bridge button returns 404 on Windows and macOS.
- Test both installers on a clean Windows and macOS PC: no driver needed for the ACR39U, start at login, update over an older version, uninstall.
- Code signing is out of scope for now.
