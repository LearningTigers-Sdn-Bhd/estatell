# Tax engine, checkout, PMS room charge, meal redemption and refunds

> **Planned.** Part of [Native POS](01-overview.md). Defines Malaysian RMCD Service Tax Policy No. 3/2024 (Amendment No. 1) tax & service charge math (with exclusive and nett worked examples), BNM cash rounding, exact tender enforcement, guarded `PostPrecalculatedCharge` room posting, 1:1 reversible meal redemption, open-shift/open-date tender swaps and lineage-safe refunds.

## Purpose

Guarantee exact sen-level financial math across F&B taxes, service charges, BNM cash rounding, hotel room charges, meal packages and partial refunds — using `Brick\Math\BigDecimal` with `RoundingMode::HalfUp` (`brick/math` 1.0) and Estatell's immutable ledger. **Tax rates are never hardcoded in POS code**: every rate is read dynamically from the property's `taxes` table linked to each `TransactionCode` (`Tax::applicableTransactionCodes`), with explicit `taxes.lhdn_tax_type` (`02` Service Tax, `03` Tourism Tax, `06` Not Applicable) so hotels with multiple SST rates (e.g., 8% rooms and 6% F&B) report every SST line accurately to LHDN MyInvois (`LhdnTaxType::SERVICE_TAX`).

## Data model

> **Immutability rule:** Every financial foreign key uses **`restrict`**, never `cascade`. Every money column uses `decimal(12,2)`. Idempotency keys are scoped per `property_id`.

```
pos_order_line_taxes    id, pos_order_line_id → pos_order_lines (restrict),
                        tax_id → taxes (restrict),
                        transaction_code_id → transaction_codes (restrict),
                        tax_code (varchar 20), tax_name (varchar 100),
                        lhdn_tax_type (varchar 4),
                        rate_type (TaxRateType: Percent|Flat),
                        tax_rate (decimal 5,2),
                        is_inclusive (bool),
                        taxable_base (decimal 12,2),
                        tax_amount (decimal 12,2)

pos_order_payments      id, property_id, pos_order_id → pos_orders (restrict),
                        pos_shift_id → pos_shifts (restrict),
                        idempotency_key (varchar 120),
                        refund_of_payment_id → pos_order_payments (restrict, nullable -- exact original tender lineage!),
                        payment_method_id → payment_methods (restrict, nullable -- REQUIRED for all non-RoomCharge tenders; null ONLY for RoomCharge),
                        direction (PosPaymentDirection: In|Out, default In -- Out records refund payout),
                        tender_type (PosTenderType: Cash|Card|EWallet|DuitNowQr|PaymentVoucher|RoomCharge),
                        reconciliation_status (PosReconciliationStatus: Verified|PendingVerification, default Verified -- PendingVerification for offline Static DuitNow),
                        amount (decimal 12,2 -- non-negative applied bill amount before cash rounding),
                        tendered_amount (decimal 12,2, nullable -- physical cash handed over by guest),
                        change_amount (decimal 12,2, nullable -- cash change returned to guest),
                        rounding_adjustment (decimal 12,2 default 0 -- signed BNM 5-sen/10-sen cash adjustment),
                        reservation_unit_id → reservation_units (restrict, nullable),
                        folio_id → folios (restrict, nullable),
                        reference (varchar 120, nullable -- card auth / DuitNow ref / voucher code),
                        status (PosPaymentStatus: Captured|Replaced|Voided, default Captured -- NO 'Refunded' status; refunds use direction=Out rows!),
                        replaced_by_payment_id → pos_order_payments (restrict, nullable),
                        acted_by_user_id → users (restrict),
                        approved_by_user_id → users (restrict, nullable),
                        paid_at (timestamp), timestamps
                        UNIQUE (property_id, idempotency_key)
                        CHECK (amount >= 0)
                        INDEX (property_id, pos_shift_id, direction, tender_type, status)
                        INDEX (pos_order_id, status)
                        INDEX (refund_of_payment_id)

pos_order_folio_links   id, property_id, pos_order_id → pos_orders (restrict),
                        pos_order_payment_id → pos_order_payments (restrict),
                        folio_id → folios (restrict),
                        folio_line_id → folio_lines (restrict -- ROOT charge folio line only, never child tax lines!),
                        transaction_code_id → transaction_codes (restrict),
                        net_amount (decimal 12,2),
                        tax_amount (decimal 12,2),
                        gross_amount (decimal 12,2), timestamps
                        UNIQUE (pos_order_payment_id, folio_line_id)
                        UNIQUE (folio_line_id)
```

_(Also extends `reservation_units` with `no_post (bool default false)` to block room charges on cash-only/restricted stays. Outlet payment method drawer rules live in `pos_outlet_payment_methods`, defined in [02-menu-catalog.md](02-menu-catalog.md).)_

## Calculation engine (`PosOrderCalculator`)

Pure, stateless service (`App\Services\Pos\PosOrderCalculator`) using `Brick\Math\BigDecimal` (`RoundingMode::HalfUp`). **Sole source of truth for POS math** — folio postings never re-run `TaxCalculator`.

### Service charge & SST rule (RMCD Service Tax Policy No. 3/2024, Amendment No. 1)

- Under RMCD Service Tax Policy No. 3/2024 (Amendment No. 1), restaurant **Service Charge is excluded from the Service Tax (SST) taxable base** (`pos_dining_options.service_charge_taxable = false` by default; properties should confirm exact statutory treatment with their tax advisor). Non-Malaysian SEA properties where VAT applies on service charge may toggle `service_charge_taxable = true`.

### Single inclusive/exclusive authority (`pos_items.price_includes_tax`)

- To prevent `pos_items.price_includes_tax` from fighting `taxes.is_inclusive` on shared property tax rows, **`pos_items.price_includes_tax` is the single pricing authority for Native POS**:
    - When `price_includes_tax = false` (hotel `++` menu): **all** applicable taxes on that line's `TransactionCode` are added on top of the line's net/taxable base (regardless of `taxes.is_inclusive`).
    - When `price_includes_tax = true` (cafe `nett` menu): **all** applicable percentage & flat taxes (plus applicable SC) are extracted _out_ of the displayed nett price so the customer pays the exact displayed price (regardless of `taxes.is_inclusive`).
    - The persisted `pos_order_line_taxes.is_inclusive` column records the effective mode applied (`item.price_includes_tax`), and `CalculatedTax(..., inclusive: $item->price_includes_tax)` passes that exact mode to `PostPrecalculatedCharge`.

### SST registration safety check & `taxes.lhdn_tax_type`

- Resolve enabled `Tax` rows from `line.transaction_code_id` (`Tax::applicableTransactionCodes`), honoring `TaxRateType::Flat` and `foreign_guests_only` (refusing `nationality_required` when missing).
- Update both `App\Services\EInvoice\LhdnTaxType` **and** `App\Services\EInvoice\SupplierDetails::missing()` to check `taxes.lhdn_tax_type` first (falling back to existing `code === 'SST'` / `'TTX'` matching when null), backfill existing `taxes` rows in the migration, and expose `lhdn_tax_type` in Settings › Catalogues › Taxes.
- If any enabled tax with `lhdn_tax_type === LhdnTaxType::SERVICE_TAX` (`'02'`) applies while `effectiveSstRegistrationNumber()` (`pos_outlets.sst_registration_number ?? property_e_invoice_settings.supplier_sst_registration ?? tax.registration_number`) is blank: **refuse checkout with `sst_registration_missing`** (never silently charge 0% on an enabled tax; non-SST properties disable SST in Settings › Catalogues › Taxes).

### Exact calculation steps

1. **Entitled meal lines (`is_entitlement_covered = true`)**: The **base dish price is covered (`base_gross = 0.00`)**, while any **paid modifier upgrades** (`addon_gross = SUM(modifier.charged_price × modifier.quantity) × qty`, e.g., RM 3.00 Smoked Salmon upgrade on an Included Breakfast) remain chargeable through Steps 3–6 (generating exact SC, tax, receipt, journal, and LHDN entries on the RM 3.00 upgrade only!). If no paid modifiers exist, the line totals `RM 0.00` across the board.
2. **Packaging fee system line**: If `pos_dining_option.packaging_fee > 0`, maintain one system `pos_order_lines` row (`line_kind = PackagingFee`, `transaction_code_id = pos_outlets.packaging_transaction_code_id`, `price_includes_tax = false`, `service_charge_exempt = true`) so packaging fees carry normal `pos_order_line_taxes`, folio buckets, and journal lines.
3. **Line gross & pre-tax item discount**:
    - `base_gross = is_entitlement_covered ? 0.00 : (unit_price × qty)`; `addon_gross = SUM(modifier.charged_price × modifier.quantity) × qty`; `line_gross = base_gross + addon_gross`.
    - Discountable base = `base_gross` when `exclude_addons_from_discount = true`, else `line_gross`.
    - Compute `item_discount` (`FixedPercent | FixedAmount | VariablePercent | VariableAmount | FixedPrice | Foc`), capped at discountable base.
    - `line_after_item_discount = line_gross - item_discount`.
4. **Pro-rate order-level discounts (pre-tax — largest-remainder whole-sen allocation)**:
    - Cap `order_discount_total` at `SUM(line_after_item_discount)` across non-voided `Item` lines with `line_after_item_discount > 0`.
    - Allocate whole sen deterministically via **largest-remainder method** (floor each line's exact fractional share to whole sen, then distribute the remaining `R` single-sen units to the `R` lines with the largest fractional remainders, tie-breaking by `line.id` ascending, capped at each line's `line_after_item_discount`). Every line's allocated discount is guaranteed to stay strictly within `[0.00, line_after_item_discount]` — never negative (e.g., four RM 0.01 lines with a RM 0.02 discount receive `0.01, 0.01, 0.00, 0.00`)!
    - `displayed_payable_base = line_after_item_discount - order_discount_share`.
5. **Compute Net, Service Charge & Taxes (single pass — zero double-calculation!)**:
    - Let $r_{\text{sc}}$ be `service_charge_rate / 100` (if `dining_option.apply_service_charge && !item.service_charge_exempt`, else `0`).
    - **Path A — Tax-exclusive (`price_includes_tax = false`, `++` pricing)**:
        - `net_amount = displayed_payable_base`
        - `service_charge_amount = round2(net_amount × r_sc)`
        - `tax_base = service_charge_taxable ? (net_amount + service_charge_amount) : net_amount`
        - Each tax: `Percent` → `round2(tax_base × rate / 100)`; `Flat` → `round2(rate × qty)`.
        - `line_total = net_amount + service_charge_amount + SUM(taxes)`
    - **Path B — Tax-inclusive (`price_includes_tax = true`, `nett` pricing)**:
        - Deduct flat taxes (`flat_total = SUM(round2(rate × qty))`; refuse `inclusive_tax_exceeds_amount` if `> displayed_payable_base`). Let $G = \text{displayed\_payable\_base} - \text{flat\_total}$.
        - Let $R_{\text{pct}} = \sum r_i$ across all percentage taxes.
        - Divisor $D = 1 + r_{\text{sc}} + R_{\text{pct}}$ (when `service_charge_taxable = false`) or $D = (1 + r_{\text{sc}})(1 + R_{\text{pct}})$ (when `service_charge_taxable = true`).
        - `net_amount = round2(G / D)`; `service_charge_amount = round2(net_amount × r_sc)`; `tax_base = service_charge_taxable ? (net_amount + service_charge_amount) : net_amount`.
        - Each percentage tax $i$: `tax_i = round2(tax_base × r_i)`.
        - **Exact sen reconciliation with zero floor**: `remainder = G - net_amount - service_charge_amount - SUM(tax_i)`. Absorb `remainder` into the last percentage tax **only if `last_tax + remainder >= 0.00`**; otherwise clamp `last_tax = 0.00` and absorb the remaining sen into `net_amount` (preventing negative tax lines on tiny multi-tax prices such as RM 0.01!).
        - Guarantee: `net_amount + service_charge_amount + SUM(all_taxes) === displayed_payable_base`.
6. **Order totals**:
    - `order.subtotal = SUM(line.net_amount)` _(always tax-exclusive & SC-exclusive net!)_
    - `order.service_charge_total = SUM(line.service_charge_amount)`
    - `order.tax_total = SUM(line.tax_amount)`
    - `order.grand_total = order.subtotal + order.service_charge_total + order.tax_total`.

### Worked examples (expected test assertions)

- **Exclusive (`++`) Dine-In**: RM 100.00 Food, 10% SC, 6% SST (`service_charge_taxable = false`):
    - `net_amount = 100.00`, `service_charge_amount = 10.00`, `tax_amount = 6.00`, **`grand_total = RM 116.00`**.
    - Folio (100% Room Charge): Root Line 1 (`FNB-FOOD`) Net `100.00` + Tax child (`SST 6%`) `6.00`; Root Line 2 (`SC`) Net `10.00` (following Line 1's folio) → Total **`RM 116.00`**.
    - Journal (100% Cash): Dr Cash `116.00` / Cr Food `100.00`, Cr SC `10.00`, Cr SST 6% `6.00`.
- **Inclusive (`nett`) Cafe**: RM 116.00 Displayed, 10% SC, 6% SST (`service_charge_taxable = false`):
    - $D = 1.16$ → `net_amount = 100.00`, `service_charge_amount = 10.00`, `tax_amount = 6.00` → **`grand_total = RM 116.00`**.

### Exact tender enforcement & BNM 5-sen cash rounding (`MalaysiaCashRounding`)

- `SettlePosOrder` enforces **`SUM(payments[].amount) === order.grand_total`** (refusing `< grand_total` with `insufficient_payment` AND `> grand_total` with `overpayment_not_allowed`).
- Cash handed over (`tendered_amount`) and `change_amount` stay separate: RM 40.00 bill paid with RM 100.00 note → `amount = 40.00`, `tendered_amount = 100.00`, `change_amount = 60.00`.
- **BNM 5-sen rounding**: sen ending `1,2,6,7` → down (`-0.01/-0.02`); `3,4,8,9` → up (`+0.02/+0.01`); `0,5` → `0.00`. Configurable `pos_outlets.rounding_policy`: `CashLegOnly` (default) or `WholeBillWhenCashPresent` (confirm exact policy with accountant/BNM guidance). Stored in `rounding_adjustment`, booked to `pos_outlets.rounding_transaction_code_id`.

## Live PMS Room Charge (`PostPrecalculatedCharge` + `SettlePosOrder`)

### 1. Guarded Folio Action (`App\Actions\Folio\PostPrecalculatedCharge`)

Mirrors `PostCharge`'s complete safety chain while accepting precalculated `TaxCalculationResult::calculated(...)` and an **explicit target `Folio`**:

1. Acquire locks in strict global order to prevent deadlocks: `properties (sharedLock)` → `OpenPosBusinessDate` (`status === Open`, refusing `409 business_date_auditing` if Night Audit is running) → all target `ReservationUnit` rows in **ascending `id` order** (`orderBy('id')->lockForUpdate()`) → `pos_orders` → `pos_shifts`.
2. Acquire `order_number` (`NextDocumentNumber`) immediately after validations pass and before posting, so folio descriptions carry the real receipt number.
3. Validate `UnitStatus` (`CheckedIn`, OR `CheckedOut` strictly on `departure_date === business_date`), `reservation_units.no_post === false` (`room_no_post`), cumulative **per-stay across all outlets** `room_charge_limit` (`room_charge_limit_exceeded` unless Manager PIN override), and guest signature when `require_room_charge_signature = true` (`signature_pad` via `spatie/laravel-medialibrary`).
4. **Explicit Folio Grouping (handles Food & Alcohol routing to different folios!)**:
    - Resolve each item line's destination folio via `FolioRouter::targetFor($locked, $lineCode)` (creating the unit folio if missing via `CreateFolio`).
    - Group item lines by `(resolved_folio_id, transaction_code_id)` AND sum each line's `service_charge_amount` per **`resolved_folio_id`**, passing the explicit resolved `Folio` directly into `PostPrecalculatedCharge` (never relying on `FolioRouter`'s `$follows`, which takes a `TransactionCode` and is overridden by rules!). Thus if Food routes to a Master folio and Alcohol routes to the Guest Unit folio, each folio receives the exact Service Charge belonging to its own lines (and Packaging Fee follows the primary Food folio).
5. Validate `FolioPostability`, post via `PostFolioCharge` with idempotency key `"native-pos:{$order->id}:pay:{$payment->id}:f:{$folio->id}:tc:{$transactionCodeId}"` and `quantity = 1` (integer), link ONLY the **root charge `folio_line_id`** in `pos_order_folio_links`, log `folio.charge_posted`, and call `CloseSettledUnitFolio`.

### 2. Folio edit guard vs front-desk moves & dedicated POS credit/reversal entry points

- **Blocked on Front Desk**: ALL value-changing folio actions — `ReverseLine::__invoke()`, `EditLine`, `SplitLine`, `ApplyDiscount`, `RemoveDiscount`, `UpdateLineTaxes` / `OverrideLineTax`, and `ApplyTaxWaiver` — check `pos_order_folio_links` for the root charge line and refuse with `pos_line_managed_in_pos` (directing staff to refund in POS).
- **Allowed on Front Desk**: Folio moves (`TransferLines` / `MoveCharges`) remain **allowed** (so front desk can move a guest's dinner onto a Company Master folio) and update `pos_order_folio_links.folio_id` and `folio_line_id` to point to the newly created destination root charge line!
- **Two Dedicated Internal POS Refund Entry Points**:
    1. **`ReverseLine::forPos(?User $actor, FolioLine $rootLine)`**: Used **ONLY** when refunding 100% of a `RoomCharge` payment that has **zero prior refunds**! Bypasses the front-desk guard, verifies `folio.status === Open`, and reverses the root charge line once (cascading all child tax lines once).
    2. **`PostPosFolioCredit` (`App\Actions\Folio\`)**: Used for **partial room-charge refunds AND refunding the final remaining balance after a prior partial refund**! Posts negative net + negative `CalculatedTax` children via `PostFolioCharge` directly onto the **linked `pos_order_folio_links.folio_id`**, validating ONLY `property_id`, `folio.status === Open`, and remaining refundable balance (**explicitly skipping** sale-only checks like `no_post`, `departure_date`, `room_charge_limit`, `signature`, and `FolioRouter` so a manager can always credit a charge even if front desk later enabled `no_post`!).

### 3. Split tenders with Room Charge (zero double-counting)

- Native POS **never** calls `RecordPayment` on guest folios.
- Only `folio_posted_amount = SUM(RoomCharge payments.amount)` posts via `PostPrecalculatedCharge` (pro-rated across `TransactionCode` & SC buckets via largest-remainder whole-sen allocation). `settled_on_folio = ($folioPostedAmount === $order->grand_total)`.
- Non-folio walk-in share (`grand_total - folio_posted_amount`) stays strictly in the POS shift, `PosDailyJournalBuilder`, and walk-in LHDN.

## Live Meal Entitlement Redemption (1:1 reversible — zero double-counting)

- **Eligibility**: Only `pos_items` with `meal_period_id` matching the current `MealPeriod` are eligible (1 redeemed guest = 1 unit).
- **Guest confirmation**: When `pos_outlets.require_meal_redemption_confirmation = true`, redeeming requires guest signature in Phase 1 (and guest signature or stay QR PIN in Phase 3) and feeds the daily **Zero-Extra Redeemed Meals Audit Report**.
- **1:1 Reversible Rows**: Redeeming 2 included breakfasts creates **two separate 1-guest `MealRedemption` rows (`redeemed_guests = 1`)** linked 1:1 to two `1×` `pos_order_lines` rows (`is_entitlement_covered = true`, `meal_redemption_id = $redemption->id`).
    - Refunding **1 of 2 breakfasts** calls `UndoMealRedemption::forPos()` on THAT line's 1-guest row — restoring **exactly 1** entitlement while keeping the other served breakfast redeemed!
    - Standard `UndoMealRedemption` (from `/restaurant/meal-check-in`) refuses with `redeemed_in_pos` if an active non-voided/non-refunded `pos_order_lines` row references that redemption.
- **Worked Examples**:
    1. `2× Included Set Breakfast (RM 0.00)` + `1× Iced Mocha (RM 14.00, 10% SC RM 1.40, 6% SST RM 0.84)` → Receipt payable **`RM 16.24`**. Journal & LHDN report only the RM 16.24 Iced Mocha.
    2. `1× Included Set Breakfast (base RM 0.00) + RM 3.00 Smoked Salmon Upgrade (10% SC RM 0.30, 6% SST RM 0.18)` → consumes 1 breakfast entitlement, Receipt payable **`RM 3.48`**, Journal & LHDN report only the RM 3.48 upgrade!

## Open-shift & open-date tender swap (`ChangePosPaymentMethod`, Phase 2)

- Allowed **strictly when ALL three hold**: (a) the order's `pos_shift` is `Open`, (b) the order's `business_date` is STILL the property's locked `Open` `business_date`, AND (c) the target payment has **zero refunds** referencing it (`refund_of_payment_id` count === 0; otherwise refuse with `payment_has_refunds`)!
- Accessed via `/pos/orders/{order}/change-payment` (inline Manager PIN `pos.refund`). Cannot swap into/out of `RoomCharge`.
- Marks original payment `Replaced`, inserts replacement `Captured` row (`replaced_by_payment_id`), recalculates cash `rounding_adjustment` if switching to/from Cash, logs `pos.payment_method_changed`.

## Lineage-safe refunds (`RefundPosOrder`)

- Accessed via `/pos/orders/{order}/refund` (inline Manager PIN `pos.refund`) or Finance Orders (non-terminal back-office session). Requires `(property_id, idempotency_key)`, mandatory refund reason, and locked `OpenPosBusinessDate` (`status === Open`).
- **Concurrency lock**: Acquires `properties (shared)` → `business_dates` → `pos_orders::lockForUpdate()` → `pos_shifts::lockForUpdate()` before checking remaining refundable line quantities, remaining refundable tax buckets, and remaining refundable payment balances.
- **Lineage & split-bill allocation rule**:
    - **Refund by Item**: Each refund line stores `refund_of_order_line_id` (inheriting exact discounted net, SC, and tax rates for the refunded qty!) + optional `return_stock` + optional `restore_meal_entitlement` (calling `UndoMealRedemption::forPos()` on 1:1 lines).
    - **Refund by Amount**: Pro-rates across remaining refundable net/SC/tax buckets using `CreditNoteSplit` math.
    - **Split cash/room tender refunds**: Each refund payout writes `pos_order_payments` with `direction = Out`, `status = Captured`, `refund_of_payment_id`, capped strictly at that original tender's remaining balance. When refunding a mixed cash+room order, the refunded tax buckets are allocated **proportionally to the refunded folio vs walk-in tender ratio** (capped so neither the folio nor the walk-in tax share for any rate can ever go negative!).
- **Tax-aware folio & LHDN reversal**:
    - **Full 100% refund of an untouched Open folio room charge (0 prior refunds)**: calls `ReverseLine::forPos($actor, $rootLine)` once per root charge line in `pos_order_folio_links`.
    - **Partial refund (or remaining-balance refund after a prior partial refund) on an Open folio**: calls **`PostPosFolioCredit`** directly to the linked folio (writing negative net + negative `CalculatedTax` children without sale-only checks!).
    - **Closed or e-invoiced folio / already-consolidated walk-in**: records the POS refund on the locked open `business_date` & open shift, and routes statutory Credit Note (`02`) / Refund Note (`04`) in Module `31-einvoice`.

## Action refusal table

| Action                   | Check                                                                            | Refusal code                                                         |
| ------------------------ | -------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `SettlePosOrder`         | Order not `Open` or 0 active lines                                               | `order_not_settleable`                                               |
| `SettlePosOrder`         | Shift is `ClosingControl` or `Closed`                                            | `shift_not_open`                                                     |
| `SettlePosOrder`         | Enabled SST tax with blank `supplier_sst_registration`                           | `sst_registration_missing`                                           |
| `SettlePosOrder`         | `SUM(payments.amount) < grand_total` / `> grand_total`                           | `insufficient_payment` / `overpayment_not_allowed`                   |
| `SettlePosOrder`         | Unit `no_post`, closed folio, past departure date, or over cumulative stay limit | `room_no_post` / `folio_not_postable` / `room_charge_limit_exceeded` |
| `RefundPosOrder`         | Exceeds remaining balance on `refund_of_order_line_id` or `refund_of_payment_id` | `exceeds_refundable_balance`                                         |
| `ChangePosPaymentMethod` | Shift closed, `business_date` closed, or swapping `RoomCharge`                   | `tender_swap_not_allowed`                                            |
