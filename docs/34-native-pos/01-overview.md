# Native POS

> **Built (phases 1 to 3).** Full specification for Estatell's built-in hospitality Point of Sale system, designed for hotel restaurants, cafes, bars, pool decks, mini-marts and room service across Malaysia and Southeast Asia. Audited against Estatell's codebase and RMCD Service Tax Policy No. 3/2024 (Amendment No. 1).

## Purpose

Let properties run their restaurants, cafes, bars and retail counters directly inside Estatell without paying for a separate external POS or maintaining fragile guest-sync workarounds. Estatell's Native POS shares the property's live reservations, folios, meal entitlements, transaction codes, taxes, daily accounting journal and LHDN MyInvois e-invoicing while supporting walk-in diners, visual floor plans, kitchen displays, QR ordering and optional local thermal hardware.

## Coexistence with Module 33 (`PosIntegration`)

| Feature flag                  | Purpose                                                                                                                      |
| ----------------------------- | ---------------------------------------------------------------------------------------------------------------------------- |
| `PosIntegration` (`33-pos`)   | External POS bridge (`/api/pos/v1`, Loyverse OAuth/webhooks, `pos_outlets`, `pos_postings`, `meal_redemptions`)              |
| `NativePos` (`34-native-pos`) | Estatell's built-in POS: menu catalog, floor plan, register terminal, shifts, KDS, QR ordering, recipes and optional Posport |

Both modules reuse `pos_outlets`, `meal_redemptions`, `transaction_codes`, `taxes`, `payment_methods`, `folios`, `folio_lines`, `document_sequences`, `accounting` (`26-accounting`) and `einvoice` (`31-einvoice`).

### Shared routes, `pos_outlets` and folio postings

- **Shared middleware (`pos-any`)**: In `routes/pos.php`, `/settings/pos-outlets` and `/restaurant/meal-check-in` currently sit behind `pos-integration`. Introduce middleware alias `pos-any` (passes when **either** `PosIntegration` or `NativePos` is active) for those two shared route groups so a `NativePos`-only property can manage outlets and meal check-in without enabling external integrations.
- **`pos_outlets` schema coexistence**: External POS (`33-pos`) maps an outlet to one `default_charge_item_id` whose `TransactionCode` must use inclusive taxes only (`SavePosOutlet` refuses exclusive taxes). Native POS adds `native_pos_enabled (bool default false)` and `service_charge_transaction_code_id (nullable)` to `pos_outlets` and computes tax per category/item `TransactionCode` (inclusive or exclusive).
    - `default_charge_item_id` becomes **nullable** for outlets where `native_pos_enabled = true` and no active external `pos_outlet_mappings` exist.
    - Whenever an outlet is used by `PosIntegration` (Loyverse mapping or `/api/pos/v1`), `default_charge_item_id` remains mandatory and keeps the existing `PosOutletTaxCheck` inclusive-tax guard so zero existing `33-pos` tests break.
- **Two posting paths (`pos_postings` vs `pos_orders`)**:
    - **External POS** posts asynchronously through `PostPosCharge` → `pos_postings` (soft-failing into `PendingReview`). **Null-item guard**: `PostPosCharge` checks `$outlet->default_charge_item_id !== null` first and refuses native-only outlets with `outlet_not_external` so `/api/pos/v1` can never crash on a native-only outlet.
    - **Native POS** settles room charges synchronously via a shared guarded Folio action **`PostPrecalculatedCharge`** (`App\Actions\Folio\`, using the same locks, status checks, `FolioRouter` routing and `CloseSettledUnitFolio` as `PostCharge`, while accepting an explicit `TaxCalculationResult` from `PosOrderCalculator`), recording root charge links in `pos_order_folio_links`. If a room cannot accept charges, checkout refuses immediately on screen (`folio_not_postable`, `room_no_post`, `room_charge_limit_exceeded`).
    - **Offline mode** supports **Cash and Static DuitNow QR walk-in sales only** (room charges require live online verification and are never queued offline).
    - **Night Audit Readiness Panel**: Surfaces both non-blocking warnings when non-empty — existing `posReview` (`PendingReview` rows in `pos_postings`) and new `nativePosReview` (open `pos_shifts` / open `pos_orders`).

## Feature flag

- Pennant feature `NativePos` (`App\Features\NativePos`, `#[Name('native-pos')]`), off by default, enabled per property by a platform admin (`PlatformFeature::NativePos`).
- Middleware alias `native-pos` (`EnsureNativePosIsActive`) returns `404` for staff pages and `403 native_pos_disabled` for `/api/posport/v1/*` and guest QR routes when off.
- **Safe deactivation guard**: Platform admins cannot turn `NativePos` off while the property has any non-Closed `pos_shifts` (`open_pos_shifts_exist`), preventing stranded registers that staff can no longer close. Once turned off after shifts close, **historical POS orders still export cleanly in `BuildDailyJournal` and `MonthlyConsolidationJob`**.

## Document numbering (`DocumentType`)

Extend `App\Enums\DocumentType` with dedicated Native POS sequence types (`DocumentType::PosOrder` `'pos_order'` code `10`, and `DocumentType::PosShift` `'pos_shift'` code `11` — preserving unused legacy codes `4` and `6`; verify `NextDocumentNumber` reference formatter supports 2-digit type codes before migrating).

- `order_number` is acquired at the start of the short settlement write transaction (after all validations pass, immediately before folio posting) so the folio description carries the real receipt number while holding the `NextDocumentNumber` lock for milliseconds.
- `shift_number` is acquired inside `OpenPosShift` right before commit.

## Architectural decisions

| #   | Decision                                                                                                       | Rationale                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| --- | -------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Separate optional hardware bridge (`estatell-posport`, Phase 4)**                                            | Phases 1–3 run 100% inside `estatell` using browser 80mm `@media print` and Web KDS/ODS. When a property asks for direct ESC/POS, TSPL labels, cash drawer, Fiuu ECR or USB scale hardware, `estatell-posport` ships as its own Go daemon & repository (parallel to `estatell-keyport`), paired per outlet.                                                                                                                                        |
| 2   | **`PosOrderCalculator` is the sole tax source of truth; folio posting uses guarded `PostPrecalculatedCharge`** | `PostFolioCharge` runs with no checks and does not compute tax. A new guarded action `PostPrecalculatedCharge` reuses `PostCharge`'s full safety chain (unit lock, `UnitStatus`, `FolioRouter`, `FolioPostability`, `CreateFolio`, `CloseSettledUnitFolio`) while accepting `TaxCalculationResult::calculated(...)` built directly by `PosOrderCalculator` — guaranteeing zero 1-sen drift and respecting room/company folio routing.              |
| 3   | **Room-charge-only folio posting on split tenders (zero double-counting)**                                     | On a split check (e.g. RM 100 Cash + RM 60 Room Charge), **only the Room Charge portion (`folio_posted_amount`)** posts to the guest Folio via `PostPrecalculatedCharge`. Native POS **never** calls `RecordPayment` on guest folios: restaurant Cash/Card/DuitNow stays 100% inside the POS shift, `PosDailyJournalBuilder` and walk-in LHDN (`grand_total - folio_posted_amount`), keeping Front Desk `PaymentsReport` strictly front-desk-only. |
| 4   | **Entitled meal items post at RM 0.00**                                                                        | Meal packages are already billed and e-invoiced on the guest's Room/Package folio. Eligible items redeemed via `RedeemMeal` inside POS carry `is_entitlement_covered = true` and price `RM 0.00` (zero tax/SC/revenue), firing normally to kitchen & stock while only extra items (e.g. Iced Mocha) are billed, journaled and e-invoiced.                                                                                                          |
| 5   | **Settlement-date accounting (no 2 AM deadlock)**                                                              | Open orders carry `business_date = null` while open and stamp the property's **current open `business_date` at settlement**. A table opened at 1:55 AM and settled at 2:10 AM (after Night Audit rolls over) settles cleanly onto the new day and never deadlocks the register.                                                                                                                                                                    |
| 6   | **Strict `Item → Variant` invariant & immutable financial rows**                                               | Every `pos_items` row has ≥1 `pos_item_variants` row. Order lines snapshot names, prices, costs and taxes at order time. Financial foreign keys (`pos_order_lines`, `pos_order_payments`, `pos_cash_movements`, `pos_order_folio_links`, `pos_ecr_requests`) use **`restrict`**, never `cascade`. All money columns use `decimal(12,2)` and `Brick\Math\BigDecimal` with `RoundingMode::HalfUp`.                                                   |

## Terms

| Term            | Means                                                                                                                              | Do not use                         |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------- |
| Outlet          | A physical revenue center (`pos_outlets`), e.g. Main Restaurant, Pool Bar, Lobby Cafe                                              | store, branch                      |
| Register        | A cashier terminal (`pos_registers`) inside an outlet with an assigned shift                                                       | till                               |
| Shift           | One cash-control session on a register (`pos_shifts`) from open float to blind Z-report close                                      | counter, batch                     |
| Order           | One guest check/ticket (`pos_orders`), either `Sale` or `Refund`, open or settled                                                  | receipt (for open), bill (in code) |
| Line            | One ordered item variant (`pos_order_lines`) with its modifiers, taxes and status                                                  | ticket item                        |
| Dining option   | Order type (`pos_dining_options`: Dine-In, Takeaway, Room Service, Poolside, Minibar) controlling service charge & packaging rules | order mode, channel                |
| Kitchen station | A prep destination (`pos_kitchen_stations`: Hot Kitchen, Bar, Cold) driving KDS and/or printers                                    | prep area, printer group           |
| Internal name   | Short or alternate-language kitchen name (`internal_name`) shown on kitchen dockets & KDS while receipts show the guest name       | kitchen alias                      |
| Payment voucher | A post-tax external/partner voucher tender (`PaymentVoucher`); does not reduce SST taxable base                                    | discount voucher                   |
| Posport         | The optional local Go hardware bridge (`estatell-posport`, Phase 4)                                                                | iot box, keyport                   |

## Files

| File                                                               | Covers                                                                                                                                                            |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [01-overview.md](01-overview.md)                                   | Purpose, coexistence, locked decisions, `DocumentType`, PIN authorization & permissions                                                                           |
| [02-menu-catalog.md](02-menu-catalog.md)                           | Categories, items, variants, modifiers, meal-entitlement items, combos, schedules, availability, stock countdown, dining options                                  |
| [03-floors-and-orders.md](03-floors-and-orders.md)                 | PayFirst/PayLater modes, floor plans, tables, order/line lifecycle, coursing, splits, discounts, anti-fraud pre-bill & voids                                      |
| [04-tax-checkout-and-pms.md](04-tax-checkout-and-pms.md)           | RMCD Policy 3/2024 tax math (exclusive & nett worked examples), BNM rounding, exact tenders, `PostPrecalculatedCharge`, 1:1 meal redemption, lineage-safe refunds |
| [05-shifts-audit-and-einvoice.md](05-shifts-audit-and-einvoice.md) | Registers, shifts, cash movements, blind Z-report, 2 AM rollover, combined `BuildDailyJournal`, LHDN consolidation & 30s receipt QR                               |
| [06-kitchen-kds-and-qr.md](06-kitchen-kds-and-qr.md)               | Kitchen stations, Web KDS/ODS (`version` polling), per-stay room QR + PIN, dynamic table QR, walk-in offline PWA sync                                             |
| [07-inventory-and-recipes.md](07-inventory-and-recipes.md)         | Simple & recipe BOM ingredients, cooking guides, immutable stock ledger, closing count reconciliation                                                             |
| [08-posport-hardware-bridge.md](08-posport-hardware-bridge.md)     | Optional Phase 4 Go daemon (`estatell-posport`), pairing, lease-safe print jobs, ESC/POS/TSPL, failover, cash drawer, Fiuu ECR, scale                             |
| [09-ui-and-build-plan.md](09-ui-and-build-plan.md)                 | Routes, `DESIGN.md` screen archetypes, test matrix and phased commit checklist (lean 7-commit Phase 1 MVP)                                                        |

## Staff POS PINs, actor authorization & security

Shared restaurant tablets cannot log out and re-enter email/password between waiters, **and must never leak a manager's browser session permissions to a cashier**.

```
pos_staff_pins          id, property_id, user_id → users (cascade),
                        pin_hash (varchar 255),
                        pin_length (smallint -- enforces >= 6 when elevated permissions are granted!),
                        active (bool default true), timestamps
                        UNIQUE (property_id, user_id)

pos_terminal_devices    id, property_id, pos_outlet_id → pos_outlets (restrict),
                        name (varchar 80), device_token_hash (varchar 64, UNIQUE),
                        revoked_at (timestamp, nullable), last_seen_at (timestamp, nullable), timestamps

pos_time_clocks (Phase 2)
                        id, property_id, pos_outlet_id → pos_outlets (restrict),
                        user_id → users (restrict),
                        clocked_in_at (timestamp), clocked_out_at (timestamp, nullable),
                        timestamps
                        INDEX (property_id, user_id, clocked_out_at)
```

### Authorization model (closes shared-till privilege escalation & back-office leakage)

1. **Mandatory POS Terminal Device Registration & Route Allowlist (closes back-office session leakage!)**:
    - Dedicated shared tills are registered once by a manager (`pos.manage`) via `POST /settings/pos/devices` (storing `pos_terminal_devices.device_token_hash` and setting a signed HttpOnly cookie on that browser).
    - Entering `/pos` from a registered terminal device (or clicking **Launch Terminal Mode**) locks the browser session (`session('pos_terminal_locked') = true`) to an **explicit allowlist of `/pos/*` routes only** (`BlockBackOfficeInTerminalMode` rejects `/finance/*`, `/settings/*`, `/front-desk/*`, `/reports/*`, `/admin/*`, and `/account/*`). Requests to `/pos/pin/*` without a valid registered device cookie are refused (`403 terminal_device_not_registered`).
    - Non-terminal Front Desk staff who open `/pos` briefly from their own desktop browser without activating Terminal Mode keep their normal desktop access (and remain their own authenticated user; PIN switching is available ONLY in locked Terminal Mode!).
    - Leaving Terminal Mode requires **fresh full password/Fortify re-authentication**. Every cashier/waiter operation — including **Manager-PIN-approved refunds and open-shift tender swaps** — has its own `/pos/orders/{order}/refund` and `/pos/orders/{order}/change-payment` routes inside `/pos/*`!
    - The lock applies to a signed-in user only. A signed-out till can reach the sign-in page; after sign-in the back office stays locked until a password-confirmed exit.
    - Every 403 page on a locked browser (back office, unregistered or revoked till, PIN actor without the permission) replaces **Go home** and **Go back** with **Close terminal**, which opens `/pos`. It uses the property in the URL, or the signed-in user's property on `/`, `/dashboard` and `/account/*`.
    - An unregistered browser shows no PIN pad. In Terminal Mode, `/pos` stays open to any PIN actor, with or without `pos.sell`, so the terminal can always be left.
2. **Self-service PIN setup & runtime elevated-length enforcement**:
    - Staff set and change **their own** PIN under their authenticated account settings (managers with `pos.manage` can only **revoke/reset** another user's PIN, never set it).
    - **Length rule**: 4–6 digits for standard staff; **minimum 6 digits** for any user holding `pos.void`, `pos.refund`, `pos.discount`, or `pos.manage`. Enforced **both** when saving a PIN **and at runtime on every PIN verification** (`VerifyPosPin` / `VerifyPosApproval` checks: if the target user currently holds any elevated POS permission and `pin_length < 6`, immediately refuse with `pin_upgrade_required` — catching every permission grant path including `AssignOwnerRole`, `SyncPermissions`, `AssignStaffRole`, and `UpdateRolePermissions`!).
    - **Hashing**: `Hash::make($pin . config('pos.pin_pepper'))` using dedicated `POS_PIN_PEPPER` (never `APP_KEY`).
3. **Route-level PIN Actor Authorization (`pos.can:{permission}`)**:
    - Following Estatell's route-middleware authorization rule, every `/pos/*` route applies **`ResolvePosActor`** (resolving `pos_active_actor_id` to `$posActor`) plus **`pos.can:{permission}`** (authorizing strictly against `$posActor`, NEVER against the underlying browser user!).
    - Routes supporting inline Manager PIN override (`void`, `discount`, `refund`, `change-payment`, `prebill`, `movements`) authorize `pos.can:pos.sell` at the route boundary and validate the elevated permission (`pos.void`, `pos.refund`, `pos.discount`) against either `$posActor` OR the inline single-use `VerifyPosApproval` approver inside the Action!
4. **Un-resettable brute-force throttling (both quick-switch AND override PINs)**:
    - Both PIN quick-switch AND Manager PIN overrides share the same rate limiter keyed on:
        - **Per server-registered device + target user**: `(property_id, target_user_id, pos_terminal_device_id)` — **5 failures / 5 min → 15-min lockout** (clearing browser cookies cannot reset a server-registered device, and one tablet never locks out another tablet behind the hotel NAT IP!).
        - **Global per target user cap**: `(property_id, target_user_id)` — **12 failures / 15 min → 30-min lockout** across all devices, logging `pos.pin_locked` (never logging guessed PIN values).
5. **Action-bound Manager PIN override & anti-self-approval**:
    - Inline `override_user_id` + `override_pin` is verified atomically in the exact POST request (bound to `action`, `order_id`, `line_id`, `amount`). When `pos_outlets.require_dual_control = true`, refuses `$approver->id === $posActor->id` (`self_approval_not_allowed`).
6. **Strict resource ownership checks**:
    - Every Action verifies inside its transaction that `outlet`, `register`, `shift`, `order`, `line`, `table`, `payment`, `reservation_unit`, and `folio` all belong to `$property->id` **and** to each other (`line.pos_order_id === order.id`, `order.pos_outlet_id === shift.pos_outlet_id`, etc.).

## Permissions

Scoped per property via `spatie/laravel-permission` teams mode, mapped strictly to Estatell's real `DefaultRoleEnum` cases (**`Owner`, `Manager`, `FrontDesk`, `Housekeeping`, `Finance`**; properties may create custom `Cashier` / `Waiter` / `Kitchen` roles in Settings › Staff › Roles):

| Permission      | Default `DefaultRoleEnum` mapping | Allows                                                                                        |
| --------------- | --------------------------------- | --------------------------------------------------------------------------------------------- |
| `pos.sell`      | `Owner`, `Manager`, `FrontDesk`   | Open POS terminal, create/edit/fire orders, settle payments, redeem meals                     |
| `pos.shift`     | `Owner`, `Manager`, `FrontDesk`   | Open/close register shifts, record float in / safe drop / payout                              |
| `pos.void`      | `Owner`, `Manager`                | Void fired lines or open orders, reprint pre-bills, open drawer no-sale                       |
| `pos.discount`  | `Owner`, `Manager`                | Apply restricted/variable discounts, FOC comps, and manual price overrides                    |
| `pos.refund`    | `Owner`, `Manager`                | Issue item/amount refunds on settled orders and change payment method (open shift)            |
| `pos.kds`       | `Owner`, `Manager`, `FrontDesk`   | Open Web KDS Kitchen/Window screens and ODS, update kitchen line statuses                     |
| `pos.menu`      | `Owner`, `Manager`                | Manage categories, items, variants, modifiers, combos, schedules, floors, availability        |
| `pos.inventory` | `Owner`, `Manager`                | Manage ingredients, recipes, purchases, waste and stocktake closing counts                    |
| `pos.hardware`  | `Owner`, `Manager`                | Pair Posport bridges, configure printers, kitchen stations, registers and ECR                 |
| `pos.reports`   | `Owner`, `Manager`, `Finance`     | View POS sales, shift Z-reports, expected cash before blind close, void/discount/drawer audit |

## Navigation placement

Defined in `app/Navigation/` (never hardcoded in React):

- **Front desk area** → **Restaurant / POS** group (visible when `NativePos` is active):
    - **POS Terminal** (`/p/{code}/pos`) (`pos.sell`)
    - **Kitchen Display** (`/p/{code}/pos/kds`) (`pos.kds`, Phase 3)
    - **Meal Check-In** (`/p/{code}/restaurant/meal-check-in`) (`meals.redeem`, visible via `pos-any`)
- **Finance area** → **POS** group:
    - **POS Orders & Receipts** (`/p/{code}/finance/pos-orders`) (`pos.view`)
    - **POS Shifts** (`/p/{code}/finance/pos-shifts`) (`pos.reports`)
    - **POS Postings** (`/p/{code}/finance/pos-postings`) (`pos.view`, when `PosIntegration` is active)
- **POS area** (its own sidebar area, shown while the feature is on; see [App shell](../design-system/09-app-shell.md)):
    - Live: POS register (`pos.sell`), Kitchen display (`pos.kds`).
    - Menu: Menu, Modifiers, Schedules & combos, Dining options (`pos.menu`).
    - Service: Meal check-in (`meals.redeem`), Floor plan, Kitchen stations (`pos.menu`).
    - Inventory: Inventory & recipes (`pos.inventory`).
    - Setup: Outlets, Registers & accounts, Staff & terminals, API access (`pos.manage`).
