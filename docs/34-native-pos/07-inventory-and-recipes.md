# Inventory, recipes and stock closing

> **Built (Phase 3).** Part of [Native POS](01-overview.md). Defines optional back-of-house F&B ingredient tracking, simple vs recipe BOM deductions, immutable stock movement ledger, printable closing stock count sheets and item cost/margin reporting.

## Build status (Phase 3, step 14)

| Part                                                                                                       | State                                                                                             |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `pos_ingredients`, `pos_recipes`, `pos_stock_movements`, `pos_stocktakes`, `pos_stocktake_lines`           | Built, with the CHECK and unique rules of the spec                                                |
| Recipes for an item variant, deducted when a line is fired (`PostRecipeStock`)                             | Built. Modifier-option recipes are deducted too, but the settings page only edits variant recipes |
| Line cost stamped on the first fire, so later cost changes do not rewrite history                          | Built                                                                                             |
| Void with stock returned puts the recipe back (`void_return`)                                              | Built                                                                                             |
| Refund with stock returned (`refund_return`)                                                               | Not built. `RefundPosOrder` does not return stock yet                                             |
| Delivery at a moving-average cost, in purchase units (`ReceiveStockPurchase`)                              | Built                                                                                             |
| Waste with a reason, transfer as a pair of rows                                                            | Built                                                                                             |
| Closing count: each line compares with the ledger as it stood when it was counted, and posts an adjustment | Built (`ClosePosStocktake`)                                                                       |
| Low-stock alerts                                                                                           | Not built. The threshold is saved and shown only                                                  |
| Settings page with ingredients, recipes, stock and the count (`settings/pos/inventory`, `pos.inventory`)   | Built                                                                                             |

Deviations and open items:

- **Balances are summed from the ledger on each read.** No cached balance table yet.
- **Printed closing list** is the browser print of the count panel, not a dedicated sheet.
- **Cooking guide and station** are unchanged by this step.

## Purpose

Give resort restaurants, bars and hotel mini-marts accurate ingredient depletion, waste visibility and true dish food-cost margins — while keeping the module **strictly optional** so small homestays or cafes that only need the lightweight Stock Countdown (`02-menu-catalog.md`) have zero setup overhead.

## Two-tier model (FeedMe + Loyverse composite pattern)

1. **Simple stock item** (1:1): Retail snacks, bottled beers, canned sodas, minibar items — one `pos_item_variant` links directly to one `pos_ingredients` row with `quantity = 1`.
2. **Advanced recipe (BOM)**: Prepared dishes & cocktails (e.g., _Nasi Lemak Royale_ → `180g Rice`, `1 pc Chicken Thigh`, `45g Sambal`, `1 pc Egg`; _Modifier: Extra Shot Espresso_ → `18g Coffee Beans`). Firing an order deducts all recipe & modifier ingredients automatically in base units.

## Data model

```
pos_ingredients         id, property_id, name (varchar 120), sku (varchar 64, nullable),
                        base_unit (varchar 20 -- e.g. 'g', 'ml', 'pcs'),
                        purchase_unit (varchar 20, nullable -- e.g. 'kg', 'box', 'bottle'),
                        purchase_to_base_ratio (decimal 12,4 default 1.0000 -- e.g. 1000g/kg),
                        unit_cost (decimal 12,4 default 0 -- moving average cost per base_unit),
                        low_stock_threshold (decimal 12,3, nullable),
                        active (bool default true), timestamps
                        UNIQUE (property_id, lower(name))

pos_recipes             id, property_id,
                        pos_item_variant_id → pos_item_variants (cascade, nullable),
                        pos_modifier_option_id → pos_modifier_options (cascade, nullable),
                        pos_ingredient_id → pos_ingredients (restrict),
                        quantity_in_base_unit (decimal 12,4),
                        timestamps
                        CHECK ((pos_item_variant_id IS NOT NULL) <> (pos_modifier_option_id IS NOT NULL))
                        CHECK (quantity_in_base_unit > 0)

pos_stock_movements     id, property_id, pos_outlet_id → pos_outlets (restrict),
                        pos_ingredient_id → pos_ingredients (restrict),
                        kind (PosStockMovementKind:
                            Sale|VoidReturn|RefundReturn|PurchaseReceive|TransferIn|TransferOut|Waste|StocktakeAdjustment),
                        quantity_delta (decimal 12,4 -- negative for out, positive for in),
                        unit_cost (decimal 12,4),
                        reference_type (varchar 60, nullable),
                        reference_id (bigint, nullable),
                        reason (varchar 200, nullable),
                        acted_by_user_id → users (restrict, nullable),
                        created_at (timestamp)
                        INDEX (property_id, pos_outlet_id, pos_ingredient_id, created_at)

pos_stocktakes          id, property_id, pos_outlet_id → pos_outlets (restrict),
                        business_date (date),
                        status (Draft|Closed),
                        note (text, nullable),
                        created_by_user_id → users (restrict),
                        closed_by_user_id → users (restrict, nullable),
                        closed_at (timestamp, nullable), timestamps

pos_stocktake_lines     id, pos_stocktake_id → pos_stocktakes (restrict),
                        pos_ingredient_id → pos_ingredients (restrict),
                        counted_at (timestamp -- exact per-line timestamp when staff physically counted this shelf!),
                        expected_quantity (decimal 12,4),
                        counted_quantity (decimal 12,4),
                        variance_quantity (decimal 12,4),
                        variance_cost (decimal 12,2)
```

## Immutable stock ledger (never overwrite `stock_after`)

Unlike Loyverse's `POST /inventory` (`stock_after` absolute overwrite, which races with concurrent sales), Estatell calculates current on-hand balance from immutable `pos_stock_movements` (optionally cached in a materialized `pos_outlet_ingredient_balances` table updated atomically via `UPDATE ... SET balance = balance + ?` inside the same database transaction):

- **On `FireOrderLines`**: Deducts recipe quantities (`kind = Sale`, `quantity_delta = -qty * recipe_qty`), and snapshots computed recipe cost into `pos_order_lines.unit_cost` for accurate historical COGS.
- **On `VoidOrderLine` / `RefundPosOrder`**: Restores ingredient stock (`kind = VoidReturn` or `RefundReturn`) **only when the staff member enables `return_stock`**, matching FeedMe's real-world kitchen rule (a spilled dish is NOT returned to stock; an unopened bottle of Coke IS returned to stock).
- **On `ReceiveStockPurchase`**: Converts `purchase_unit` via `purchase_to_base_ratio`, updates moving-average `unit_cost`, and posts `PurchaseReceive`.
- **On `TransferStock`**: Paired `TransferOut` (from Main Store / Outlet A) and `TransferIn` (to Pool Bar / Outlet B) in a single transaction.
- **On `RecordStockWaste`**: Logs spoilage/breakage with mandatory reason (`Expired`, `Spilled`, `Staff meal`).

## Printable Closing Stock Count (`ClosePosStocktake`, per-line cutoff-safe!)

1. Kitchen or bar manager creates a Draft stocktake for the outlet and optionally clicks **Print Closing List** (prints a clean 80mm/A4 browser checklist in Phase 3, or thermal slip via Posport in Phase 4).
2. As each ingredient shelf is physically counted, the UI stamps **`counted_at`** per `pos_stocktake_lines` row (or accepts an explicit count time when entering paper counts).
3. `ClosePosStocktake` computes each line's `expected_quantity = SUM(pos_stock_movements.quantity_delta) WHERE created_at <= line.counted_at`, calculates `variance_quantity = counted - expected`, and posts `StocktakeAdjustment` delta rows — automatically carrying forward any sales after that item's count time without double-deducting or inventing phantom stock!
