# Posport — Optional POS Hardware Bridge (`estatell-posport`)

> **Planned (Phase 4 — deferred until a property requests physical ESC/POS, cash drawer, Fiuu ECR or scale hardware).** Part of [Native POS](01-overview.md). Separate Go repository and binary from `estatell-keyport`.

## Why a separate bridge from `estatell-keyport`

- **Different physical location & lifecycle**: `estatell-keyport` runs on the **Front Desk PC** for USB door keycard encoding (`127.0.0.1:8766`). Restaurant/bar hardware lives at **F&B outlets** and needs independent per-outlet pairing, monitoring and releases.
- **Shared proven architecture**: Single compiled Go binary (macOS, Windows, Linux), **outbound HTTPS only**, local SQLite journal, automatic USB reconnect, zero billing logic, and a small local status page (`http://127.0.0.1:8767`).

```
Estatell Cloud  <--- HTTPS outbound (/api/posport/v1/*) --->  estatell-posport (Outlet PC)
  owns orders, dockets,                                         claims jobs (lease), Printer Lock,
  ECR requests, status                                          SQLite journal, failover
                                                                   ├── TCP :9100 / USB → Receipt Printer + RJ11 Drawer
                                                                   ├── TCP :9100       → Hot Kitchen & Bar Printers (ESC/POS)
                                                                   ├── TCP :9100 / USB → Sticker Printer (TSPL)
                                                                   ├── Private LAN IP  → Fiuu / Razer ECR Card Terminal
                                                                   └── USB Serial      → ACLAS OS2X Weighing Scale
```

## Pairing, authentication & SSRF/network security (`/api/posport/v1/*`)

- **Unauthenticated pairing endpoint (matches `estatell-keyport`)**:
    - `POST /api/posport/v1/pair` sits **outside** `api.token:posport:*` middleware, strictly rate-limited by IP (`10 attempts / 15 min`), exchanging a 10-minute one-time pairing code for a Sanctum `posport:*` bearer token AND a per-device **`local_secret`** (used to authenticate local `127.0.0.1:8767` browser calls).
- **Authenticated bridge endpoints**:
    - Use `['api.token:posport:*', 'throttle:api-token', EnsureNativePosApiActive::class]` backed by `PosportAgent` (`HasApiTokens`, scoped to one `Property` & `PosOutlet`).
- **Private LAN IP & port allowlist (blocks SSRF / arbitrary LAN pivoting)**:
    - `pos_printers.host_address` and `pos_registers.ecr_terminal_ip` are validated both in Laravel Form Requests AND inside the Go agent to allow **RFC1918 private IPv4 addresses only** (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` — rejecting loopback, link-local metadata `169.254.x.x`, and public IPs) and an **explicit port allowlist** (`9100`, `9101`, `8008`, `8443`, plus configured ECR vendor ports).
- **Local `127.0.0.1:8767` hardening**:
    - Every local API endpoint (`/scale/weight`, `/local-print`) requires header `X-Posport-Secret` matching the paired `local_secret`, strict `Host: 127.0.0.1:8767` validation, and strict `Origin` matching the property's Estatell HTTPS origin. Drawer kicks are **never** exposed to unauthenticated browser calls.

## Data model (Phase 4 migration)

> **Immutability & retention:** `pos_ecr_requests` uses **`restrict`** foreign keys and encrypts `raw_response` (`encrypted:array` cast). `pos_print_jobs.payload_base64` is purged automatically after **7 days** by a scheduled cleanup command.

```
posport_agents          id, property_id, pos_outlet_id → pos_outlets (restrict),
                        name (varchar 80), status (Online|Offline),
                        app_version (varchar 30, nullable),
                        local_ip (varchar 45, nullable),
                        last_seen_at (timestamp, nullable),
                        revoked_at (timestamp, nullable), timestamps

pos_printers            id, property_id, pos_outlet_id → pos_outlets (restrict),
                        posport_agent_id → posport_agents (nullOnDelete, nullable),
                        name (varchar 80),
                        protocol (PosPrinterProtocol: EscPos|Tspl|Star, default EscPos),
                        connection_type (PosPrinterConnection: LanTcp|Usb|BrowserFallback),
                        host_address (varchar 45, nullable -- RFC1918 private IPv4 or USB ID),
                        port (int default 9100),
                        paper_width_mm (smallint default 80),
                        active (bool default true), timestamps
                        UNIQUE (pos_outlet_id, lower(name))

pos_print_jobs          id, property_id, pos_outlet_id → pos_outlets (restrict),
                        posport_agent_id → posport_agents (nullOnDelete, nullable),
                        pos_printer_id → pos_printers (restrict),
                        backup_printer_id → pos_printers (nullOnDelete, nullable),
                        job_type (PosPrintJobType:
                            Receipt|PreBill|KitchenDocket|VoidDocket|LabelSticker|ShiftZReport|DrawerSlip|DynamicQr|DrawerKick|TestPrint),
                        idempotency_key (varchar 120, UNIQUE),
                        reference_type (varchar 60, nullable),
                        reference_id (bigint, nullable),
                        payload_base64 (text, nullable -- purged after 7 days),
                        pulse_drawer (bool default false),
                        status (PosPrintJobStatus: Queued|Claimed|Printed|Failed, default Queued),
                        used_backup_printer (bool default false),
                        attempts (smallint default 0),
                        last_error (varchar 255, nullable),
                        claimed_at (timestamp, nullable),
                        claimed_until (timestamp, nullable -- 30s lease; expired leases auto-requeue!),
                        printed_at (timestamp, nullable),
                        created_at (timestamp)
                        INDEX (posport_agent_id, status, claimed_until, created_at)

pos_ecr_requests        id, property_id, pos_outlet_id → pos_outlets (restrict),
                        posport_agent_id → posport_agents (restrict),
                        pos_order_id → pos_orders (restrict),
                        idempotency_key (varchar 120, UNIQUE),
                        terminal_ip (varchar 45), terminal_port (int),
                        amount (decimal 12,2),
                        status (PosEcrStatus: Pending|Claimed|Approved|Declined|Unknown|Cancelled),
                        approval_code (varchar 40, nullable),
                        card_brand (varchar 30, nullable),
                        masked_pan (varchar 24, nullable),
                        raw_response (text, nullable -- encrypted cast; never raw card PAN/CVV),
                        claimed_until (timestamp, nullable),
                        created_at, completed_at (timestamp, nullable)
```

## Reliability, worker safety & hardware protocols

1. **Short-poll (no PHP-FPM worker starvation)**: `estatell-posport` polls `POST /api/posport/v1/jobs/claim` on a fast **2-second short-poll** (returning immediately in `<20 ms`, never holding a PHP worker open for 25s!).
2. **Crash-safe 30s Lease (`claimed_until`) & Dedup Failover**:
    - Claiming a print job sets `claimed_until = now() + 30s` and increments `attempts`. If the bridge crashes mid-flight, the expired lease makes the job claimable again (max 3 attempts → `Failed`); any retry with `attempts > 1` prepends **`[DUPLICATE — RETRY]`** so kitchen chefs never cook a dish twice!
    - **Printer Lock FIFO mutex** serializes writes per printer IP/USB in Go.
    - **Backup Failover**: Only fails over to `backup_printer_id` on TCP **connect timeout / refused** (before any bytes are written to the primary socket), prepending **`[BACKUP PRINT — ORIGINAL: HOT KITCHEN]`**.
3. **Protocols**: `ESC/POS` (58/80mm, UTF-8/bitmap CJK/Malay/Jawi, QR `GS ( k`, auto-cut `GS V 66 0`, drawer kick `ESC p 0 25 250`), `TSPL` (cup/tray sticker labels), and `STAR`.
4. **Fiuu / Razer LAN ECR — Safe Timeout & Audited Recovery**:
    - **Expired ECR leases NEVER auto-requeue** (an expired `claimed_until` transitions strictly `Claimed → Unknown` so a card is never charged twice!).
    - Staff trigger a **Terminal Inquiry** via `POST /p/{code}/pos/ecr-requests/{req}/inquire` (`pos.can:pos.sell`), which dispatches an inquiry job to Posport checking the terminal's last transaction by `idempotency_key` / amount. The completion endpoint accepts **both `Claimed → Approved|Declined|Unknown` AND audited `Unknown → Approved|Declined`** (verified against `$request->posport_agent_id === $agent->id` and exact `amount`, logging `pos.ecr_recovered_from_unknown`).
    - **Late approval after alternate tender**: If an `Unknown` ECR request later resolves `Approved` after the order was already settled via another tender (e.g., Cash), the server marks the ECR request `OverpaidNeedsRefund` and alerts Finance/Manager to reverse the duplicate card charge — never double-settling the order!
5. **USB Serial Weighing Scale**: Reads live weight from **ACLAS OS2X** and serves `GET http://127.0.0.1:8767/scale/weight` (guarded by `X-Posport-Secret`, `Host: 127.0.0.1:8767` and `Origin`).
6. **Single supported local connection model**: Direct loopback `127.0.0.1:8767` calls are supported **strictly on the counter PC running `estatell-posport`**. Waiter tablets never call Posport over HTTP LAN (avoiding browser HTTPS→HTTP mixed-content blocks); tablets always enqueue jobs via Estatell Cloud (2s Posport short-poll) or use browser print fallback when offline.
