# UI screens, routes, testing and build plan

> **Built (phases 1 to 3).** Part of [Native POS](01-overview.md). Defines all staff, public QR and Posport routes, `DESIGN.md`-compliant screen layouts, Pest test coverage and the lean phased build plan (7-commit Phase 1 MVP inside `estatell`).

## Routes

Loaded from `routes/native-pos.php` under `/p/{property:code}` with `EnsurePropertyAccess::class`, `verified`, `live`, `native-pos`:

### 1. Front-of-house POS & KDS (authorized via `ResolvePosActor` + `pos.can:*` — NEVER browser-session `can:`!)

> **Authorization rule (`01-overview.md`):** Entering `/pos` activates mandatory **Terminal Mode** (`BlockBackOfficeInTerminalMode` blocks `/finance/*`, `/settings/*`, `/front-desk/*`, `/reports/*` until fresh password re-authentication). Every operational route below runs `ResolvePosActor` + `pos.can:{permission}` against the **PIN-selected `$posActor`** (plus inline single-use `VerifyPosApproval` Manager PIN override where applicable).

| Method & URL                                 | Route name                     | Route middleware (`pos.can:*`)                                   | Purpose                                                                              |
| -------------------------------------------- | ------------------------------ | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| `GET /pos`                                   | `pos.index`                    | Authenticated staff                                              | Outlet/register launcher & Pick-Name + PIN pad                                       |
| `GET /pos/outlets/{outlet}/terminal`         | `pos.terminal`                 | `pos.can:pos.sell`                                               | Touch-first POS Register (and Floor Plan in Phase 2)                                 |
| `POST /pos/pin/switch`                       | `pos.pin.switch`               | Throttled PIN (device + user)                                    | Pick user + verify PIN → set `pos_active_actor_id`                                   |
| `POST /pos/clock`                            | `pos.clock`                    | Throttled PIN (device + user)                                    | Staff Clock In / Out (Phase 2)                                                       |
| `POST /pos/shifts/open`                      | `pos.shifts.open`              | `pos.can:pos.shift`                                              | Open register shift (`OpenPosShift`)                                                 |
| `POST /pos/shifts/{shift}/movements`         | `pos.shifts.movements.store`   | `pos.can:pos.shift` (`pos.void` PIN for NoSale)                  | Float in, safe drop, payout, no-sale drawer open                                     |
| `POST /pos/shifts/{shift}/begin-close`       | `pos.shifts.begin-close`       | `pos.can:pos.shift`                                              | Move `Open → ClosingControl` & freeze register trading                               |
| `POST /pos/shifts/{shift}/cancel-close`      | `pos.shifts.cancel-close`      | `pos.can:pos.shift`                                              | Move `ClosingControl → Open` to resume trading                                       |
| `POST /pos/shifts/{shift}/count`             | `pos.shifts.count`             | `pos.can:pos.shift` (`pos.reports` PIN for recount)              | Submit blind count / manager-approved recount (`SubmitBlindCount`)                   |
| `POST /pos/shifts/{shift}/close`             | `pos.shifts.close`             | `pos.can:pos.shift`                                              | Finalize close, stamp `closed_business_date`, freeze Z-report (`FinalizeCloseShift`) |
| `POST /pos/orders`                           | `pos.orders.store`             | `pos.can:pos.sell`                                               | Open counter/table order (`CreatePosOrder`)                                          |
| `POST /pos/orders/{order}/lines`             | `pos.orders.lines.store`       | `pos.can:pos.sell`                                               | Add line + modifiers/combo (`AddOrderLine`)                                          |
| `PATCH /pos/orders/{order}/lines/{line}`     | `pos.orders.lines.update`      | `pos.can:pos.sell`                                               | Edit draft qty/note/course or split item qty                                         |
| `POST /pos/orders/{order}/fire`              | `pos.orders.fire`              | `pos.can:pos.sell`                                               | Fire draft/held lines & decrement stock (`FireOrderLines`)                           |
| `POST /pos/orders/{order}/lines/{line}/void` | `pos.orders.lines.void`        | `pos.can:pos.sell` (`pos.void` PIN if fired/pre-billed)          | Delete draft or PIN-void fired line (`VoidOrderLine`)                                |
| `POST /pos/orders/{order}/void`              | `pos.orders.void`              | `pos.can:pos.sell` (`pos.void` PIN)                              | Void open order (`VoidPosOrder`)                                                     |
| `POST /pos/orders/{order}/discount`          | `pos.orders.discount`          | `pos.can:pos.sell` (`pos.discount` PIN if restricted/pre-billed) | Apply/remove item or order discount                                                  |
| `POST /pos/orders/{order}/split`             | `pos.orders.split`             | `pos.can:pos.sell`                                               | Split order to new bill / AA split (Phase 2)                                         |
| `POST /pos/orders/{order}/move`              | `pos.orders.move`              | `pos.can:pos.sell`                                               | Transfer table or merge checks (Phase 2)                                             |
| `POST /pos/orders/{order}/transfer-register` | `pos.orders.transfer-register` | `pos.can:pos.shift`                                              | Move open order to another open register before shift close                          |
| `POST /pos/orders/{order}/prebill`           | `pos.orders.prebill`           | `pos.can:pos.sell` (`pos.void` PIN on reprint)                   | Print pre-bill & fire/lock draft lines (`PrintPreBill`)                              |
| `POST /pos/orders/{order}/settle`            | `pos.orders.settle`            | `pos.can:pos.sell`                                               | Capture exact tenders / room charge / meal redemption (`SettlePosOrder`)             |
| `POST /pos/orders/{order}/refund`            | `pos.orders.refund`            | `pos.can:pos.sell` (`pos.refund` PIN)                            | Cashier-initiated refund with Manager PIN (`RefundPosOrder`)                         |
| `POST /pos/orders/{order}/change-payment`    | `pos.orders.change-payment`    | `pos.can:pos.sell` (`pos.refund` PIN)                            | Open-shift & open-date tender swap (`ChangePosPaymentMethod`, Phase 2)               |
| `POST /pos/orders/{order}/ecr`               | `pos.orders.ecr`               | `pos.can:pos.sell`                                               | Push amount to Fiuu/Razer LAN ECR via Posport (Phase 4)                              |
| `POST /pos/ecr-requests/{req}/inquire`       | `pos.ecr.inquire`              | `pos.can:pos.sell`                                               | Run terminal status inquiry on an `Unknown` ECR charge (Phase 4)                     |
| `GET /pos/in-house-guests`                   | `pos.in-house-guests`          | `pos.can:pos.sell`                                               | Live search for checked-in rooms + meal entitlements                                 |
| `POST /pos/offline-sync`                     | `pos.offline-sync`             | `pos.can:pos.sell`                                               | Idempotent FIFO replay of walk-in offline orders (Phase 3)                           |
| `GET /pos/kds`                               | `pos.kds.index`                | `pos.can:pos.kds`                                                | Kitchen station & Expo/ODS launcher (Phase 3)                                        |
| `GET /pos/kds/{station}`                     | `pos.kds.show`                 | `pos.can:pos.kds`                                                | Fullscreen Web KDS (`Kitchen` / `Window` mode, Phase 3)                              |
| `GET /pos/kds/{station}/version`             | `pos.kds.version`              | `pos.can:pos.kds`                                                | Cached Redis version hash polling endpoint (Phase 3)                                 |
| `PATCH /pos/kds/lines/{line}/status`         | `pos.kds.lines.status`         | `pos.can:pos.kds`                                                | Transition line `Fired → Preparing → Ready → Served` (Phase 3)                       |
| `GET /pos/ods/{outlet}`                      | `pos.ods.show`                 | `pos.can:pos.kds`                                                | Customer/poolside ODS pickup board (Phase 3)                                         |

### 2. Finance & Back-Office POS (non-terminal back-office session only — blocked in Terminal Mode)

| Method & URL                                      | Route name                          | Permission        | Purpose                                                                                   |
| ------------------------------------------------- | ----------------------------------- | ----------------- | ----------------------------------------------------------------------------------------- |
| `GET /finance/pos-orders`                         | `finance.pos-orders.index`          | `pos.view`        | Paginated settled/voided/refunded receipt index                                           |
| `GET /finance/pos-orders/{order}`                 | `finance.pos-orders.show`           | `pos.view`        | Receipt detail, tax breakdown, folio/e-invoice links, reprint                             |
| `POST /finance/pos-orders/{order}/refund`         | `finance.pos-orders.refund`         | `pos.refund`      | Back-office refund (`RefundPosOrder`)                                                     |
| `POST /finance/pos-orders/{order}/change-payment` | `finance.pos-orders.change-payment` | `pos.refund`      | Back-office open-shift & open-date tender swap (Phase 2)                                  |
| `POST /finance/pos-orders/{order}/einvoice`       | `finance.pos-orders.einvoice`       | `einvoice.manage` | Phase-1 staff individual LHDN e-Invoice issuance (for >RM 10k or corporate requests)      |
| `GET /finance/pos-shifts`                         | `finance.pos-shifts.index`          | `pos.reports`     | Shift log with cash variance flags                                                        |
| `GET /finance/pos-shifts/{shift}`                 | `finance.pos-shifts.show`           | `pos.reports`     | Frozen Z-report, cash movements & audit exceptions                                        |
| `GET /reports/pos-sales`                          | `reports.pos-sales`                 | `pos.reports`     | Sales by outlet, category, item margin, zero-extra meal audit, void/discount/drawer audit |

### 3. Settings (`settings.pos.*` — rendered with `TabLinks` per `DESIGN.md`)

| URL                                                  | Permission      | Covers                                                                                                   |
| ---------------------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------- |
| `GET /settings/pos-outlets` _(shared via `pos-any`)_ | `pos.manage`    | Outlets (`native_pos_enabled`, GL codes, tax/rounding/room-limit settings)                               |
| `GET /settings/pos/menu`                             | `pos.menu`      | Categories, items, meal-period links, variants, `internal_name`, stock countdown                         |
| `GET /settings/pos/modifiers`                        | `pos.menu`      | Modifier groups (`min`/`max`/`free_included`) & addon 86 toggles                                         |
| `GET /settings/pos/dining-options`                   | `pos.menu`      | Dine-In / Takeaway / Room Service SC & packaging rules                                                   |
| `GET /settings/pos/staff-pins`                       | `pos.manage`    | Staff PIN status/revoke, registered `pos_terminal_devices` (`POST /settings/pos/devices`) & void reasons |
| `GET /settings/pos/floors` _(Phase 2)_               | `pos.menu`      | Visual floor plan editor & batch table creation                                                          |
| `GET /settings/pos/schedules` _(Phase 2)_            | `pos.menu`      | Menu schedules & combos                                                                                  |
| `GET /settings/pos/kitchen` _(Phase 3)_              | `pos.menu`      | Kitchen stations (`use_internal_name`, SLA thresholds)                                                   |
| `GET /settings/pos/inventory` _(Phase 3)_            | `pos.inventory` | Ingredients, BOM recipes, purchases, waste & stocktake closing                                           |
| `GET /settings/pos/hardware` _(Phase 4)_             | `pos.hardware`  | Posport pairing, private-LAN printers, failover & ECR terminals                                          |

### 4. Public Guest Routes (no staff auth, rate-limited)

| Method & URL                                   | Phase   | Purpose                                                                                   |
| ---------------------------------------------- | ------- | ----------------------------------------------------------------------------------------- |
| `GET /receipt/{receipt_claim_token}`           | Phase 2 | Walk-in receipt viewer & LHDN e-Invoice self-claim (`10 req/min/IP`)                      |
| `POST /receipt/{receipt_claim_token}/einvoice` | Phase 2 | Confirm total/date & submit LHDN e-Invoice (`5/hr/IP` & `10/token` max, zero PII logging) |
| `GET /q/table/{token}`                         | Phase 3 | Dynamic Table QR mobile menu (`30 req/min/IP`)                                            |
| `POST /q/table/{token}/orders`                 | Phase 3 | Submit Table QR order (`10/15m/session`)                                                  |
| `GET /q/room/{token}`                          | Phase 3 | Per-stay Room QR menu (mandatory 4-digit stay PIN + last name)                            |
| `POST /q/room/{token}/orders`                  | Phase 3 | Submit Room Service order (`10/15m/session`)                                              |

## UI architecture (`DESIGN.md` compliance)

All screens follow [DESIGN.md](../../DESIGN.md): **shadcn/ui Nova** on **Base UI** (`b3ZzDdiJy4`), semantic tokens only (no raw palette classes, no manual `dark:`, no `asChild`, no `sonner`), `tabular-nums` for every price/qty/timer, `PageHeader` with Geist headings, `TabLinks` for settings tabs, `FormOverlay` for responsive dialogs/sheets, and `components/pms/pos/` for POS-only domain components (`pages/pos/*` never define components inline).

- **POS Register Terminal (`pages/pos/terminal.tsx`)**: Full-viewport **Board** workspace. Compact top bar shows Outlet, Business Date, Active Shift #, Active PIN Actor badge (1-tap Pick-Name + PIN switch), and Quick Tools (Cash Movement / Blind Shift Close / Stock Countdown). Left 65%: Dining Option pills, `category-1`..`category-12` tabs, instant client-side search & barcode listener over `OutletMenuSnapshot`, dense touch tiles (`min-h-14`). Right 35%: Order Ticket (`Draft` warning badge vs locked `Fired` lines), Room / Meal Entitlement sheet, exact Subtotal / Discount / SC / Tax / Grand Total, and Checkout Sheet (Cash + change + BNM rounding, Card, DuitNow QR, Payment Voucher, **Charge to Room** with optional signature, and **Redeem Meal**).
- **Web KDS (`pages/pos/kds.tsx`, Phase 3)**: High-contrast 4-column card masonry with `Kitchen` / `Window` / `All-Day Batch` toggle, `success`/`warning`/`destructive` SLA timer headers, single-tap line check-off, single-tap **Bump**, and 20-order **Recall** drawer.
- **Folio Receipt Drill-Down**: Clicking any folio line linked in `pos_order_folio_links` opens a read-only `Sheet` showing the itemized POS receipt without bloating the folio ledger table.

## Testing & architecture boundaries (Pest)

1. **Pest Architecture Tests** (`tests/Architecture/PosArchitectureTest.php`):
    - Enforce `App\Actions\Pos` invokable classes only, `App\Services\Pos` stateless, `App\Models\Pos*` relations/casts/scopes only, and zero direct Eloquent writes into `folio_lines` (room charges go through `PostPrecalculatedCharge`, partial credits through `PostPosFolioCredit`, full reversals through `ReverseLine::forPos()`, meal redemptions through `RedeemMeal` / `UndoMealRedemption::forPos()`; Native POS **never** calls `RecordPayment`).
2. **Financial Math Unit Tests** (`tests/Unit/Pos/PosOrderCalculatorTest.php`):
    - **RMCD Policy 3/2024 Exclusive (`++`) worked example**: RM 100.00 food + 10% SC + 6% SST (`service_charge_taxable = false`) = **RM 116.00** (Net RM 100.00, SC RM 10.00, SST RM 6.00), matching Receipt, Folio and Journal identically across all 4 `price_includes_tax × taxes.is_inclusive` combinations.
    - **RMCD Policy 3/2024 Inclusive (`nett`) sweep**: RM 116.00 nett → Net RM 100.00, SC RM 10.00, SST RM 6.00; PLUS automated sweep across RM 0.01–RM 100.00 verifying `net + sc + SUM(taxes) === displayed_price` and `tax >= 0` for **100% of prices** with single and multiple taxes.
    - Flat taxes (`TaxRateType::Flat`), `foreign_guests_only`, `packaging_fee`, `service_charge_taxable = true` non-MY option, SST missing registration refusal (`sst_registration_missing`).
    - Exact tender enforcement (refuses both underpayment `insufficient_payment` AND excess applied tender `overpayment_not_allowed`, while permitting RM 100 cash handed over for RM 40 bill with RM 60 change).
    - BNM 5-sen rounding across `.00`–`.09` under both `CashLegOnly` and `WholeBillWhenCashPresent`.
3. **Feature, Security & Concurrency Tests**:
    - **Tenant & ownership isolation**: Cross-property `order_id`, mismatched `line_id` on `order_id`, cross-outlet `shift_id`, and `pos-any` middleware access for `NativePos`-only hotels.
    - **PIN authorization**: Manager logs into browser → switches PIN to Cashier → manager-only actions are refused unless action-bound Manager PIN is supplied; Cashier triggers Refund with Manager PIN override and succeeds; 5 failed PIN attempts lock `(property, user, device)` without locking another tablet on the same IP; self-approval refused when `require_dual_control = true`.
    - **Pre-bill anti-fraud**: Printing pre-bill automatically fires & locks draft lines so subsequent removal requires `pos.void` Manager PIN.
    - **2 AM Night Audit rollover**: Order opened at 1:55 AM settles cleanly at 2:10 AM (both Cash and Room Charge) onto the new open `business_date` and shift closes with zero deadlock.
    - **Meal entitlement**: 2× Included Breakfast (`RM 0.00`) + 1× Iced Mocha (`RM 14.00`) produces exact Receipt (`RM 16.24`), balanced Journal (only Iced Mocha), LHDN consolidation (only Iced Mocha), and optional `UndoMealRedemption` on same-day refund.
    - **Room charge & split tenders**: Room Charge routes via `FolioRouter` (Master vs Unit), respects `no_post` & `room_charge_limit`, supports two room tenders on one check (Room 301 + Room 302) with unique idempotency keys, posts ONLY `folio_posted_amount` to Folio, keeps Cash/Card strictly out of Front Desk `PaymentsReport`, and guards POS-linked folio lines against front-desk `ReverseLine`/`SplitLine`.
    - **Refund lineage & concurrency**: Two identical dishes at different discounts refund at their exact original line values via `refund_of_order_line_id`; mixed cash/room refund cannot exceed either original tender's remaining balance (`refund_of_payment_id`); concurrent refund of last item locks row and refuses second caller; partial refund on Open folio posts negative tax lines via `PostPrecalculatedCharge`; refund on Closed folio flags Module 31 Credit Note.
    - **Daily Journal & LHDN**: Café-only day (zero folio lines) produces balanced journal; historical POS date still exports after `NativePos` flag is turned off; `PosDailyJournalBuilder` never leaks POS variance into `Guest ledger`; walk-in orders consolidate cleanly in `MonthlyConsolidationJob` with zero double-filing of room-charged or meal-covered items.

## Ordered build plan (commit-by-commit checklist)

> **Build strategy:** Phase 1 is a tight, shippable **7-commit MVP** 100% inside `estatell` (including statutory LHDN monthly consolidation). Each phase only adds foreign keys to tables that already exist in that phase!

### Phase 1 — Counter & Cafe POS MVP (7 commits, 100% inside `estatell`)

1. **Feature gate, `pos-any`, `DocumentType` & Terminal Mode PIN auth**: `NativePos` Pennant feature (deactivation blocked while open shifts exist), `native-pos`, `pos-any`, `BlockBackOfficeInTerminalMode`, `ResolvePosActor`, `pos.can:*`, `DocumentType::PosOrder` & `PosShift`, `PermissionEnum` + `DefaultRoleEnum`, `pos_terminal_devices`, self-service `pos_staff_pins` (6-digit min for elevated roles + role-change deactivation), throttled Pick-Name + PIN & `VerifyPosApproval`.
2. **Outlet coexistence, `taxes.lhdn_tax_type`, payment methods, Dining Options & core Menu**: add `taxes.lhdn_tax_type` & update `LhdnTaxType`, extend `pos_outlets` (including `default_charge_item_id` nullable + `PostPosCharge` `outlet_not_external` guard + SC/packaging/rounding/cash-variance GL codes), `pos_outlet_payment_methods`, `pos_dining_options` (`service_charge_taxable = false` default), `pos_categories`, `pos_items` (`meal_period_id`, `internal_name`), `pos_item_variants`, `pos_modifier_groups`, `pos_modifier_options`, `pos_outlet_items`, `OutletMenuSnapshot`.
3. **Tax calculation & rounding engine**: `PosOrderCalculator` (`BigDecimal` + `RoundingMode::HalfUp`, `price_includes_tax` single authority across all 4 `price_includes_tax × taxes.is_inclusive` combos, zero-floor nett remainder, flat/foreign taxes, packaging system line, `LhdnTaxType::SERVICE_TAX` reg gate, building `TaxCalculationResult::calculated(...)`) + `MalaysiaCashRounding`, backed by full RM 0.01–RM 100.00 sweep tests.
4. **Registers, shifts & hardened blind cash control**: `pos_registers`, `pos_shifts` (`opened_business_date`, `closed_business_date`, `recount_count`, partial unique open index, `z_report_snapshot`), `pos_cash_movements` (`restrict` FK, `counter_transaction_code_id`, `(property_id, idempotency_key)`), `OpenPosShift`, `RecordCashMovement`, `BeginCloseShift`, `CancelCloseShift`, `SubmitBlindCount`, `FinalizeCloseShift`, Night Audit `nativePosReview` warning + daily stock reset.
5. **Orders, checkout, guarded folio posting & 1:1 meal redemption**: `pos_void_reasons` (created BEFORE `pos_orders`!), `pos_orders` (`business_date` nullable, `settled_on_folio`, `folio_posted_amount`, `UNIQUE (property_id, client_uuid)`), `pos_order_lines` (`line_kind`, `is_entitlement_covered`, `meal_redemption_id`, `refund_of_order_line_id`), `pos_order_line_modifiers`, `pos_order_line_taxes`, `pos_order_discounts` (`FixedPrice`), `pos_order_payments` (`direction: In|Out`, `status: Captured|Replaced|Voided`, `refund_of_payment_id`, `UNIQUE (property_id, idempotency_key)`), `pos_order_folio_links` (root lines only), `reservation_units.no_post`, guarded `PostPrecalculatedCharge` (ascending room lock, `$follows` folio routing, business-date lock), front-desk folio edit guard + move follower + `ReverseLine::forPos()`, `UndoMealRedemption` POS guard, `CreatePosOrder`, `AddOrderLine`, basic `FireOrderLines`, `TransferOrderToRegister`, `SettlePosOrder` (exact tenders, room-charge-only split posting, 1:1 `RedeemMeal` @ RM 0.00), Folio Receipt Drill-Down sheet.
6. **Voids & lineage-safe refunds**: `PrintPreBill` (auto-fires draft lines + locks post-prebill discounts & reprints behind Manager PIN), `VoidOrderLine`, `VoidPosOrder`, `RefundPosOrder` (both `/pos/orders/{order}/refund` with Manager PIN and Finance route; row-locked, `refund_of_order_line_id` + `refund_of_payment_id`, `direction = Out`, `ReverseLine::forPos()` / negative `PostPrecalculatedCharge`, 1:1 `UndoMealRedemption::forPos()`).
7. **Counter POS Terminal UI, browser receipt, double-entry Daily Journal & Phase-1 LHDN**: `pages/pos/terminal.tsx` (`PayFirst`), Finance Orders & Shifts pages, Settings `TabLinks` pages, browser 80mm `@media print` receipt/Z-report, `PosDailyJournalBuilder` merged into `BuildDailyJournal` (flag-independent, café-only safe, double-entry cash shortage/overage/payouts/refunds verified by the full cross-midnight RM 2-short worked acceptance test!), `e_invoice_submission_pos_orders` (`is_active` partial unique index, `restrict` FK) in `MonthlyConsolidationJob`, and staff `POST /finance/pos-orders/{order}/einvoice` individual issuance!

### Phase 2 — Full-Service Dining, Menu Power Features & 30s Receipt QR Self-Claim (100% inside `estatell`)

8. **Visual Floor Plan & `PayLater` workflow**: `pos_floors`, `pos_tables`, add `pos_orders.pos_table_id`, `BatchCreatePosTables`, drag-and-drop editor, `TableFloorState`, `SplitOrderLineQuantity`, `SplitPosOrder` (AA split + multi-bill checkout warning), `MovePosOrderItems`, `MergePosOrders`, Coursing (`Hold`/`Fire`).
9. **Menu schedules, combos, weight pricing, time clocks & open-shift/open-date tender swap**: `pos_menu_schedules`, `pos_combo_slots` / `pos_combo_slot_options`, `sold_by_weight`, `pos_time_clocks` (`ClockInPosStaff` / `ClockOutPosStaff`), `ChangePosPaymentMethod` (strictly while BOTH shift AND `business_date` are `Open` and target payment has zero refunds).
10. **30-Second Walk-In Receipt QR Self-Claim**: hardened public `/receipt/{token}` portal (IP + per-token attempt cap, receipt total/date confirmation step, month-end expiry, strict zero-PII logging) calling `SubmitPosWalkInEInvoice`.

### Phase 3 — Web KDS/ODS, Secure QR Ordering, Walk-In Offline PWA & BOH Inventory (100% inside `estatell`)

11. **Kitchen stations, Web KDS & ODS**: `pos_kitchen_stations`, add `kitchen_station_id` to categories/items/lines, fullscreen Web KDS (`Kitchen` SLA aging + All-Day Batch + `Window/Expo`) with 3–5s Redis `version` polling, browser kitchen/void docket print, and ODS pickup screen.
12. **Dynamic Table QR & Per-Stay Room QR**: `pos_qr_sessions` (single source of truth; property-wide Room QR auto-generated on `StayCheckedIn` with one-time 4-digit stay PIN + regenerate action, auto-revoked on `StayCheckedOut` / `StayCheckInUndone`), public `/q/table/{token}` & `/q/room/{token}` rate-limited ordering apps.
13. **Walk-In PWA Offline Mode**: Service Worker + IndexedDB versioned `OutletMenuSnapshot` (7-day server retention), Cash/Static-DuitNow-only offline queue with UUIDv7 `client_uuid`, `OFF-{register}-{short_uuid}` temporary slip numbering, snapshot-honored sync + price-drift audit + closed-shift overage offset on `/pos/offline-sync`.
14. **BOH Inventory & Recipes**: `pos_ingredients`, `pos_recipes`, `pos_stock_movements` (`restrict` FKs), `pos_stocktakes`, `ReceiveStockPurchase`, `TransferStock`, `RecordStockWaste`, printable Closing Stock List, `ClosePosStocktake`, Cooking Guides on KDS.

### Phase 4 — Optional Hardware Bridge (`estatell-posport`, separate Go repo — only when a property requests hardware)

15. **Posport Bridge & Direct Printing**: separate Go daemon + `/api/posport/v1/*` (unauthenticated IP-throttled `/pair`, 2s short-poll, 30s `claimed_until` lease, RFC1918 private IP/port allowlist, `X-Posport-Secret` local auth), `pos_printers`, `pos_print_jobs` (7-day payload purge), ESC/POS/TSPL/STAR, Printer Lock, connect-failure-only backup failover, RJ11 drawer kick.
16. **External Peripherals**: Fiuu/Razer LAN ECR (`pos_ecr_requests` with `restrict` FK, encrypted `raw_response`, `Unknown` status + mandatory inquiry before retry) and USB Serial scale (ACLAS OS2X).
