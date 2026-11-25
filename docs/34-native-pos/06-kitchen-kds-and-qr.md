# Kitchen routing, Web KDS/ODS, QR self-ordering and walk-in offline mode

> **Planned (Phase 3).** Part of [Native POS](01-overview.md). Defines kitchen stations, Web KDS (`version` hash polling — no Reverb dependency), ODS pickup boards, per-stay Room QR + PIN ordering, dynamic Table QR ordering, and walk-in-only IndexedDB PWA offline mode.

## Build status (Phase 3, step 11)

| Part                                                                        | State                                                          |
| --------------------------------------------------------------------------- | -------------------------------------------------------------- |
| `pos_kitchen_stations`, `kitchen_station_id` on categories, items and lines | Built                                                          |
| Routing: item station, else category station; combo parents route nowhere   | Built (`KitchenDisplay::stationIdFor`)                         |
| Web KDS: Kitchen, Window and All day views, Bump, Recall, Served            | Built (`pos/kds`)                                              |
| Version polling (`/kds/{station}/version`, `/ods/{outlet}/version`)         | Built, random token in the cache, not a Redis hash key by hand |
| Void dockets on the screen (struck through, 30 minutes)                     | Built on screen. Printed void docket not built                 |
| ODS pickup board                                                            | Built (`pos/ods`), staff route, not public (see below)         |
| Browser kitchen docket print                                                | Not built, waits for the print step                            |
| Cooking guide popover                                                       | Guide sent to the screen, popover not built yet                |

Deviation: the ODS board sits behind the terminal actor and `pos.kds`, so a pickup monitor needs a signed-in kitchen session. A public board needs its own token, which is not built.

## Purpose

Route fired dishes across multi-station hotel kitchens without paper bottlenecks, let guests self-order safely from dining tables or hotel rooms via revocable QR sessions, and keep walk-in Cash/DuitNow counter checkout working through brief Wi-Fi dropouts.

## Data model (Phase 3 migration)

```
pos_kitchen_stations    id, property_id, pos_outlet_id → pos_outlets (restrict),
                        name (varchar 80 -- e.g. "Hot Kitchen", "Bar", "Cold"),
                        mode (PosStationMode: Kds|BrowserPrint|PosportPrinter|Both, default Kds),
                        use_internal_name (bool default false),
                        warn_after_minutes (smallint default 10),
                        alert_after_minutes (smallint default 20),
                        filter_dining_kinds (varchar 120, nullable),
                        sort_order (smallint default 0), active (bool default true), timestamps
                        UNIQUE (pos_outlet_id, lower(name))
                        -- Phase 4 migration adds: primary_printer_id, backup_printer_id

pos_qr_sessions         id, property_id,
                        pos_outlet_id → pos_outlets (restrict, nullable -- required for Table QR; null for property-wide Room QR!),
                        kind (PosQrSessionKind: Table|Room),
                        pos_table_id → pos_tables (restrict, nullable),
                        reservation_unit_id → reservation_units (restrict, nullable),
                        token (varchar 40, UNIQUE -- single source of truth for dynamic & per-stay QR tokens!),
                        stay_pin_hash (varchar 255, nullable -- mandatory 4-digit PIN for Room QR; never reprinted from hash),
                        single_use (bool default true -- active for the current table session until settled or expired),
                        expires_at (timestamp, nullable),
                        revoked_at (timestamp, nullable),
                        created_by_user_id → users (nullOnDelete, nullable),
                        timestamps
                        CHECK ((kind = 'table' AND pos_table_id IS NOT NULL AND pos_outlet_id IS NOT NULL)
                            OR (kind = 'room' AND reservation_unit_id IS NOT NULL AND pos_outlet_id IS NULL))
                        INDEX (property_id, kind, revoked_at)
```

_(Phase 3 migration also adds nullable `kitchen_station_id → pos_kitchen_stations (nullOnDelete)` to `pos_categories`, `pos_items`, and `pos_order_lines`.)_

## Kitchen stations & ticket routing

When `FireOrderLines` executes:

1. Non-voided `Draft` lines (matching the fired course) group by resolved `kitchen_station_id` (`item.kitchen_station_id ?? category.kitchen_station_id`).
2. Combo child lines route to each child component's own station with header `"[COMBO: Set Lunch A]"`.
3. Each station updates its cached Redis version hash (`pos:kds:{station_id}:version`) and (when printing is configured) produces a kitchen docket using `internal_name` when `use_internal_name = true`.
4. **Void Dockets**: `VoidOrderLine` on any `Fired`/`Preparing`/`Ready` line bumps the station version hash, displays a destructive strikethrough on KDS, and emits a **`*** VOID ***`** docket.

## Web KDS & ODS screens (`DESIGN.md` `version` polling — no Reverb)

Runs in fullscreen browser mode on any kitchen tablet or wall monitor without requiring WebSocket/Reverb infrastructure:

- **Lightweight polling**: Every KDS/ODS screen polls `GET /p/{code}/pos/kds/{station}/version` every **3–5 seconds** (served directly from a single Redis string lookup — zero DB query unless the version hash changed!) and runs an Inertia partial reload only on change.
- **Kitchen Mode (BOH Chefs)**:
    - Color-aging SLA headers: `0 → warn_after_minutes` (`success`), `warn → alert` (`warning`), `>= alert` (`destructive` + subtle audio chime).
    - Tap line → toggle `Preparing` / `Ready`; tap **Bump Order** → mark all station lines `Ready` (last 20 bumped tickets recallable).
    - **All-Day / Batch View**: aggregates identical active dishes across open checks (`5× Nasi Lemak (2× No Anchovies)`).
    - **Cooking Guide Popover**: tap any dish with `cooking_guide` for step-by-step plating instructions.
- **Window / Expo Mode (FOH Runners)**: Lists orders with `Ready` items grouped by Table / Room; tapping **Served** marks lines `Served` and records prep-to-serve durations.
- **ODS (Customer Pickup Screen)**: Two-column public/kiosk board (`/p/{code}/pos/ods/{outlet}`): **Preparing** and **Ready for Pickup**.

## Dynamic Table QR & Per-Stay In-Room Dining QR

### 1. Dynamic Table QR (`/q/table/{token}`)

- Long-press a table on the Floor Plan → **Dynamic QR Code**: creates a `pos_qr_sessions` row (`kind = Table`, TTL = `dynamic_qr_ttl_minutes`, `single_use = true`, revoking any previous open session for that table) and prints a thermal/browser slip.
- **`single_use` semantics**: The token stays active for the table's dining session (allowing the table to submit multiple rounds of orders) and is **automatically revoked upon settlement (`SettlePosOrder`)** or when `expires_at` passes — preventing out-of-store prank orders!
- Static table QR codes (`pos_tables.static_qr_token`) are disabled by default and opt-in per outlet.

### 2. Per-Stay In-Room Dining QR (`/q/room/{token}` — closes wrong-guest room-charge risk!)

- **Never print a static billable token on a permanent room tent card!** A photographed permanent card could charge the next guest checking into that room.
- Instead, **on `StayCheckedIn`**, Estatell generates a **property-wide per-stay `pos_qr_sessions` row** (`kind = Room`, `pos_outlet_id = null`, `reservation_unit_id`, random `token`, and a **4-digit stay PIN** stored hashed in `stay_pin_hash`; the plaintext PIN is shown **once** on the check-in welcome slip / guest portal, and front desk can click **Regenerate Room QR PIN** anytime if lost — never reprinting from hash!). Once authenticated, the guest chooses any outlet offering `RoomService` / `Poolside` ordering.
- **On `StayCheckedOut` or `StayCheckInUndone`**: The listener immediately revokes the session (`revoked_at = now()`). **On `StayRoomChanged`**: Because the session references `reservation_unit_id` (never a copied room string), orders automatically display the guest's new room number!
- **Mandatory guest verification**: Scanning `/q/room/{token}` always requires entering the **4-digit stay PIN + primary guest's last name** (throttled: 5 failed attempts → 15-minute lock on the session) before viewing/submitting a room-charged order.
- Verifies `CheckedIn` status, `reservation_units.no_post === false`, and `FolioPostability` before accepting. Fires to KDS/Kitchen with header **`ROOM 301 — IN-ROOM DINING`**. _(Unaccepted QR orders sit with `pos_shift_id = null` until accepted into an open shift, or auto-attach to the outlet's active shift when online.)_

### 3. Public QR rate limits & acceptance

- `GET /q/*`: `30 requests/minute` per IP; `POST /q/*/orders`: `10 orders / 15 minutes` per QR session & IP.
- `allow_anonymous_qr` defaults to **`false`** for Room QR and configurable for Table QR.
- Outlets choose **Auto-accept** or **Staff-approve** (cashier taps Accept & Fire on the Order Management Bar).

## Walk-In PWA Offline Mode (IndexedDB + UUIDv7)

So a brief internet outage never stops counter cash/DuitNow sales:

1. **Versioned Menu Snapshot Cache (IndexedDB)**: On shift open, the server issues a signed, versioned `OutletMenuSnapshot` (`snapshot_version`, retained server-side for 7 days). Variable-price items (`PosPricingType::Variable`) are disabled offline so every offline item price is verifiable against its signed snapshot!
2. **Walk-in Cash & Static DuitNow QR ONLY**:
    - **Room Charges and Meal Redemptions are strictly disabled offline**.
    - Offline Static DuitNow QR tenders record `reconciliation_status = PendingVerification` on `pos_order_payments` for finance/shift bank-slip reconciliation.
    - Register header displays an **Offline Mode** (`warning`) badge and blocks shift close / logout while unsynced orders remain.
3. **Gap-Free Numbering Rule**:
    - Official gap-free `order_number` values are assigned strictly by PostgreSQL `document_sequences` **on sync**.
    - Any receipt printed while offline prints temporary reference **`OFF-{register_code}-{short_uuid}`** clearly labeled `"OFFLINE RECEIPT — OFFICIAL TAX RECEIPT NUMBER ASSIGNED ON SYNC"`.
4. **Snapshot-Honored Sync & Closed-Shift Reconciliation (`POST /p/{code}/pos/offline-sync`)**:
    - Scoped uniqueness: `UNIQUE (property_id, client_uuid)` absorbs duplicate retries cleanly.
    - **Price-drift & expired-snapshot resolution**: On sync, the server validates `snapshot_version` and honors the verified snapshot's unit prices & tax rules (if live catalog prices changed since, logs `pos.offline_price_drift` without breaking exact-tender rules!). If a tablet stayed offline longer than the 7-day snapshot retention, syncing requires a Manager PIN (`pos.manage`) to approve the recorded offline prices into an audited late-sync batch.
    - **Closed-shift reconciliation (prevents double-shift variance!)**: If the original Shift A was force-closed while the tablet was offline (so the physical RM 10 cash was already counted as an RM 10 overage in Shift A!), syncing does **NOT** inflate Shift B's expected cash — instead, it flags `pos_orders.synced_late = true`, posts the walk-in revenue on the current `OpenPosBusinessDate`, and offsets the cash clearing entry against `cash_variance_transaction_code_id` (reversing Shift A's phantom overage cleanly)!
