# Menu, catalog, pricing and availability

> **Planned.** Part of [Native POS](01-overview.md). Defines the normalized product catalog, variants, modifier rules, meal-entitlement eligibility, combos, multilingual kitchen names, availability states, stock countdown, menu schedules and dining options.

## Purpose

Give hotel F&B outlets a fast, expressive menu engine covering cafes, full-service restaurants, bars and mini-marts without duplicating Estatell's accounting or tax catalogues. Every sold item resolves to an Estatell `TransactionCode` (which owns its GL account and applicable `Tax` rows from the property's `taxes` table — rates are never hardcoded in POS code; properties configure their own SST/tax rates per their accountant or RMCD guidance).

## Data model

> **Migration conventions (`.ai/rules/migrations.md`):** Every money column uses `decimal(12,2)` with no per-row currency, and every new foreign key uses fluent `foreignId(...)->constrained()` with explicit delete behavior. **Phase-safe migrations:** Columns referencing later-phase tables (`kitchen_station_id` in Phase 3) are added in that phase's migration so Phase 1 migrates cleanly with zero broken foreign keys.

### Phase 1 tables

```
pos_outlets (extends 33-pos)
                        ~ default_charge_item_id → charge_items (restrict, nullable when native_pos_enabled = true and no active external mappings)
                        + native_pos_enabled (bool default false)
                        + service_charge_transaction_code_id → transaction_codes (restrict, nullable)
                        + packaging_transaction_code_id → transaction_codes (restrict, nullable)
                        + rounding_transaction_code_id → transaction_codes (restrict, nullable)
                        + cash_variance_transaction_code_id → transaction_codes (restrict, nullable)
                        + operation_mode (PosOperationMode: PayFirst|PayLater, default PayFirst)
                        + close_sales_time (time default '00:00:00')
                        + sst_registration_number (varchar 50, nullable -- falls back to property_e_invoice_settings.supplier_sst_registration)
                        + rounding_mode (PosRoundingMode: None|FiveSen|TenSen, default FiveSen)
                        + rounding_policy (PosRoundingPolicy: CashLegOnly|WholeBillWhenCashPresent, default CashLegOnly)
                        + room_charge_limit (decimal 12,2, nullable -- cumulative per stay across ALL outlets on the property, null = unlimited; stored on property_settings or pos_outlets fallback)
                        + require_room_charge_signature (bool default true -- explicit guest consent on by default)
                        + require_meal_redemption_confirmation (bool default true -- guest signature in Phase 1; signature or stay PIN in Phase 3)
                        + require_reprint_pin (bool default true)
                        + require_dual_control (bool default false -- blocks manager self-approval)
                        + print_receipt_after_payment (bool default true)
                        + print_drawer_slip (bool default true)
                        + require_clock_in (bool default false)
                        + dynamic_qr_single_use (bool default true)
                        + dynamic_qr_ttl_minutes (smallint default 180)
                        + allow_anonymous_qr (bool default false)
                        + allow_qr_customer_remark (bool default true)
                        + qr_ignore_stock (bool default false)

taxes (extends 06-catalogues)
                        + lhdn_tax_type (varchar 4, nullable -- '02' Service Tax, '03' Tourism Tax, '06' Not Applicable;
                                         backfilled for existing SST/TTX rows, editable in Settings › Catalogues › Taxes,
                                         used by both LhdnTaxType and SupplierDetails::missing()!)

pos_outlet_payment_methods
                        id, property_id, pos_outlet_id → pos_outlets (cascade),
                        payment_method_id → payment_methods (restrict -- required; mapped to a TransactionCode for journal export),
                        tender_type (PosTenderType: Cash|Card|EWallet|DuitNowQr|PaymentVoucher),
                        pulse_drawer (bool default false -- true for Cash),
                        active (bool default true), sort_order (smallint default 0), timestamps
                        UNIQUE (pos_outlet_id, payment_method_id)

pos_categories          id, property_id, transaction_code_id → transaction_codes (restrict),
                        name (varchar 100), internal_name (varchar 60, nullable),
                        color_token (varchar 30 default 'category-1'),
                        sort_order (smallint default 0), active (bool default true), timestamps
                        UNIQUE (property_id, lower(name))
                        -- Phase 3 migration adds: kitchen_station_id → pos_kitchen_stations (nullOnDelete, nullable)

pos_items               id, property_id, pos_category_id → pos_categories (restrict),
                        transaction_code_id → transaction_codes (restrict, nullable),
                        meal_period_id → meal_periods (nullOnDelete, nullable -- marks eligible included-meal item!),
                        name (varchar 140), internal_name (varchar 60, nullable),
                        description (text, nullable),
                        sku_prefix (varchar 40, nullable),
                        price_includes_tax (bool default false),
                        service_charge_exempt (bool default false),
                        shared_stock_count (int, nullable -- FeedMe shared variant countdown),
                        daily_shared_stock_reset (int, nullable -- optional Night Audit reset value),
                        cooking_guide (text, nullable -- shown on KDS in Phase 3),
                        sort_order (smallint default 0), active (bool default true), timestamps
                        INDEX (property_id, pos_category_id, active)
                        -- Phase 2 migration adds: sold_by_weight (bool), weight_unit (varchar 20), weight_unit_divisor (decimal 10,3), is_combo (bool)
                        -- Phase 3 migration adds: kitchen_station_id → pos_kitchen_stations (nullOnDelete, nullable)

pos_item_variants       id, property_id, pos_item_id → pos_items (cascade),
                        name (varchar 80 default 'Regular'),
                        sku (varchar 64, nullable), barcode (varchar 64, nullable),
                        pricing_type (PosPricingType: Fixed|Variable, default Fixed),
                        price (decimal 12,2 default 0),
                        cost (decimal 12,2, nullable),
                        sort_order (smallint default 0), active (bool default true), timestamps
                        UNIQUE (property_id, sku) WHERE sku IS NOT NULL
                        UNIQUE (property_id, barcode) WHERE barcode IS NOT NULL
                        CHECK (price >= 0)

pos_modifier_groups     id, property_id, name (varchar 100),
                        min_select (smallint default 0),
                        max_select (smallint, nullable -- null = unlimited),
                        max_per_option (smallint default 1),
                        free_included_count (smallint default 0),
                        sort_order (smallint default 0), active (bool default true), timestamps
                        CHECK (min_select >= 0)
                        CHECK (max_select IS NULL OR max_select >= min_select)

pos_modifier_options    id, property_id, pos_modifier_group_id → pos_modifier_groups (cascade),
                        name (varchar 100), internal_name (varchar 60, nullable),
                        price_delta (decimal 12,2 default 0),
                        availability (PosAvailability: All|PosOnly|None, default All),
                        sort_order (smallint default 0), active (bool default true), timestamps

pos_item_modifier_group pos_item_id → pos_items (cascade),
                        pos_modifier_group_id → pos_modifier_groups (cascade),
                        sort_order (smallint default 0)
                        PRIMARY KEY (pos_item_id, pos_modifier_group_id)

pos_outlet_items        id, property_id, pos_outlet_id → pos_outlets (cascade),
                        pos_item_variant_id → pos_item_variants (cascade),
                        price_override (decimal 12,2, nullable),
                        availability (PosAvailability: All|PosOnly|None, default All),
                        stock_countdown (int, nullable),
                        daily_stock_reset (int, nullable -- optional Night Audit reset value),
                        timestamps
                        UNIQUE (pos_outlet_id, pos_item_variant_id)

pos_dining_options      id, property_id, pos_outlet_id → pos_outlets (cascade),
                        name (varchar 80),
                        kind (PosDiningKind: DineIn|Takeaway|RoomService|Poolside|Minibar|Delivery),
                        apply_service_charge (bool default true),
                        service_charge_rate (decimal 5,2 default 10.00),
                        service_charge_taxable (bool default false -- RMCD Policy 3/2024 (Amendment No. 1): false for Malaysia!),
                        packaging_fee (decimal 12,2 default 0.00),
                        is_default (bool default false), active (bool default true), timestamps
                        UNIQUE (pos_outlet_id, lower(name))
```

### Phase 2 catalog extensions

```
pos_combo_slots         id, property_id, pos_item_id → pos_items (cascade),
                        name (varchar 80 -- e.g. "Choose Main", "Choose Drink"),
                        min_select (smallint default 1), max_select (smallint default 1),
                        sort_order (smallint default 0), timestamps

pos_combo_slot_options  id, property_id, pos_combo_slot_id → pos_combo_slots (cascade),
                        pos_item_variant_id → pos_item_variants (restrict),
                        upcharge (decimal 12,2 default 0), sort_order (smallint default 0)
                        UNIQUE (pos_combo_slot_id, pos_item_variant_id)

pos_menu_schedules      id, property_id, pos_outlet_id → pos_outlets (cascade),
                        meal_period_id → meal_periods (nullOnDelete, nullable),
                        name (varchar 100),
                        days_of_week (smallint default 127 -- bitmask Mon=1..Sun=64),
                        start_time (time), end_time (time),
                        effective_from (date, nullable), effective_until (date, nullable),
                        active (bool default true), timestamps

pos_menu_schedule_items pos_menu_schedule_id → pos_menu_schedules (cascade),
                        pos_category_id → pos_categories (cascade, nullable),
                        pos_item_id → pos_items (cascade, nullable)
                        CHECK ((pos_category_id IS NOT NULL) <> (pos_item_id IS NOT NULL))
```

## Key rules

### 1. Strict `Item → Variant` invariant

- Creating a `PosItem` always creates at least one active `PosItemVariant` (named `"Regular"` when the user enters a single price; UI hides the variant picker when an item has only 1 active variant).
- Multi-variant items (e.g. `Hot / Iced`, `Regular / Large`, `Glass / Bottle`) store distinct `price`, `cost`, `sku` and `barcode` per variant.
- **Variable pricing (`PosPricingType::Variable`)**: Used for market-price seafood or custom cakes; the register prompts for the unit price when adding to an order.
- **Sold by weight (Phase 2, `sold_by_weight = true`)**: Example: `"Oyster w/ Cheese — RM 10.90 per 50g"` (`price = 10.90`, `weight_unit = 'g'`, `weight_unit_divisor = 50`). Entering `260g` computes `quantity = 260 / 50 = 5.200`, gross `5.200 × 10.90 = RM 56.68`. (Folio summary lines always post `quantity = 1` with the computed net amount, respecting `folio_lines.quantity` integer constraint.)

### 2. Meal-entitlement eligible items (`pos_items.meal_period_id`)

- Linking a `PosItem` to an Estatell `MealPeriod` (e.g., _Included Buffet Breakfast_ or _Hotel Set Breakfast_ → `Breakfast`) defines **exactly what a guest's meal entitlement buys** inside POS:
    - 1 redeemed guest entitlement covers **1 unit** of any item linked to that `MealPeriod` (base dish + zero-price modifiers; paid modifier upgrades remain chargeable or configurable).
    - Non-linked items (e.g., _Iced Mocha_, _Fresh Orange Juice_, _Extra Bacon_) are **never** covered by the entitlement and remain payable at normal menu price.

### 3. Tax & kitchen routing resolution (inheritance)

- **TransactionCode** (GL account + applicable `Tax` rows): `pos_items.transaction_code_id ?? pos_categories.transaction_code_id ?? pos_outlets.default_charge_item.transaction_code_id`.
- **Service Charge**: Whenever any active `pos_dining_options` row has `apply_service_charge = true`, `pos_outlets.service_charge_transaction_code_id` is required so folio postings and walk-in daily journals book Service Charge to the **exact same GL code**.
- **KitchenStation (Phase 3)**: `pos_items.kitchen_station_id ?? pos_categories.kitchen_station_id` (null = no kitchen docket).
- **Effective Outlet Price**: `pos_outlet_items.price_override ?? pos_item_variants.price`.

### 4. Modifier groups & free included count

- `min_select > 0` forces selection on POS and QR menus before confirming.
- `free_included_count` prices the first N chosen options at `RM 0.00` (sorted lowest `price_delta` free first, deterministic).
- **Modifier-level 86**: Switching `pos_modifier_options.availability` to `None` immediately hides that option across every item linked to the group.

### 5. `internal_name` (multilingual kitchen shorthand)

- Receipts, folios and guest QR menus always display `name`.
- Kitchen stations with `use_internal_name = true` display `internal_name` (falling back to `name`).

### 6. 3-state availability & Stock Countdown (with daily reset)

- **`All`** (POS + QR), **`PosOnly`** (POS only), **`None`** (unavailable everywhere).
- **Stock Countdown**: Long-pressing an item tile sets per-variant (`pos_outlet_items.stock_countdown`) or shared (`pos_items.shared_stock_count`) remaining count. Decremented atomically at fire (`FireOrderLines`).
    - At `<= 0`: Guest QR displays `"Sold out"` and blocks ordering (unless `qr_ignore_stock = true`). POS staff get an override warning allowing negative counts (`-1 left`).
    - **Reset rule**: During Night Audit rollover, any item/outlet row with a non-null `daily_stock_reset` / `daily_shared_stock_reset` automatically resets its countdown for the new business day; rows with null reset persist until manually restocked.

### 7. Dining options & defaults

When Native POS is enabled on an outlet, Estatell seeds Malaysian hotel defaults (all with `service_charge_taxable = false` per RMCD Service Tax Policy No. 3/2024 (Amendment No. 1)):

| Dining option | `kind`        | Service charge | Rate | SC taxable | Default |
| ------------- | ------------- | -------------- | ---- | ---------- | ------- |
| Dine-In       | `DineIn`      | Yes            | 10%  | **No**     | Yes     |
| Takeaway      | `Takeaway`    | No             | 0%   | **No**     | No      |
| Room Service  | `RoomService` | Yes            | 10%  | **No**     | No      |
| Poolside      | `Poolside`    | Yes            | 10%  | **No**     | No      |

## Actions & services

- `SavePosCategory`, `SavePosItem`, `SavePosModifierGroup`, `SavePosDiningOption`, `SavePosMenuSchedule` (Phase 2).
- `UpdateOutletItemAvailability`, `UpdateStockCountdown`, `ToggleModifierOptionAvailability`.
- `OutletMenuSnapshot` (`App\Services\Pos\`): builds the bounded outlet catalog payload so POS category tabs and search filter locally with zero server requests.

## Media

Item thumbnails use `spatie/laravel-medialibrary` (`pos_item_image` collection, single file, 400×400 WebP). Never store base64 images in database columns.
