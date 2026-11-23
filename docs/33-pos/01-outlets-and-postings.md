# Outlets and POS postings

## Purpose

Let properties connect external Point of Sale (POS) systems to Estatell so guests can charge restaurant, bar, room service or spa bills directly to their room folio. Estatell owns the outlets, folio posting, tax calculation, night audit reconciliation and LHDN e-invoicing; the external POS owns dining operations.

## Scope

| Now                                                                         | Later                                                                     |
| --------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Per-property `PosIntegration` Pennant feature gate                          | Additional POS adapters (Shiji Infrasys, FeedMe, StoreHub, Oracle MICROS) |
| Outlets (`pos_outlets`) mapped to an active `ChargeItem`                    | Per-category menu item mapping (food vs. alcohol split GL accounts)       |
| Summary folio line per POS receipt (`"{Outlet} — Receipt #{number}"`)       | Optional itemized line-by-line folio posting                              |
| Tax recalculated by Estatell `TaxCalculator` from the outlet's `ChargeItem` | Optional pass-through of POS-calculated tax breakdown                     |
| Room Charge only (walk-in cash/card sales stay in the POS)                  | Daily non-resident outlet summary posting to a house folio                |
| Standard inbound `/api/pos/v1` API + Loyverse adapter (`WAS-121`)           | Automated retry from UI for failed outbound guest syncs                   |
| Exception queue (`PendingReview`) surfaced in Night Audit Readiness Panel   | Digital guest signature attachment from POS terminal                      |

## Feature gate

- Pennant feature `PosIntegration` (`pos-integration`), off by default, turned on per property by a platform admin (see [Feature flags](../01-platform/03-feature-flags.md)).
- Middleware alias `pos-integration` (`EnsurePosIntegrationIsActive`) returns `404` when the flag is off.
- When off, all POS settings routes, Restaurant routes, and POS posting queue routes return `404`, sidebar items are hidden, and stay-event sync listeners do nothing.
- `/api/pos/v1/*` returns `403 pos_disabled` when the token's property has the flag turned off.

## Data model

```
pos_outlets             id, property_id, name, code, default_charge_item_id, active (bool), timestamps
                        unique (property_id, code)

pos_connections         id, property_id, provider (PosProvider), status (PosConnectionStatus),
                        external_merchant_id (nullable), access_token (encrypted, nullable),
                        refresh_token (encrypted, nullable), token_expires_at (nullable),
                        webhook_secret (encrypted, nullable), last_synced_at (nullable),
                        last_error (nullable), timestamps
                        unique (property_id, provider)

pos_outlet_mappings     id, property_id, pos_connection_id, pos_outlet_id, external_store_id,
                        external_payment_type_id, active (bool), timestamps
                        unique (pos_connection_id, external_store_id)

pos_postings            id, property_id, pos_outlet_id, pos_connection_id (nullable),
                        reservation_unit_id (nullable), folio_line_id (nullable),
                        external_receipt_id, external_receipt_number,
                        posting_type (PosPostingType), status (PosPostingStatus),
                        amount (decimal 12,2), failure_reason (nullable),
                        raw_payload (jsonb), resolved_by_user_id (nullable),
                        resolved_at (nullable), resolution_note (nullable),
                        posted_at (nullable), timestamps
                        unique (pos_outlet_id, external_receipt_id, posting_type)
                        index (property_id, status)

pos_api_clients         id, property_id, name, last_used_at (nullable), revoked_at (nullable), timestamps
                        (the owner of an inbound API token; see 03-pos-api.md)
```

- `pos_outlets.default_charge_item_id` references `charge_items.id` (same `property_id`). That `ChargeItem` supplies the `TransactionCode` (typically `TransactionCategory::FoodAndBeverage`), GL account code and applicable taxes.
- `pos_postings.raw_payload` is on the [jsonb allowlist](../overview.md#jsonb-allowlist) as a raw provider payload. It stores the original POS receipt (including line items) so front desk staff can inspect what the guest ordered when reviewing a folio or resolving an exception.
- `pos_postings.folio_line_id` links to the parent charge `FolioLine` (for `charge`) or the reversal `FolioLine` (for `void`).
- `pos_outlets.code` is stored uppercase (a CHECK enforces it), so the unique `(property_id, code)` index is case-insensitive in effect. `SavePosOutlet` uppercases the code and allows letters, numbers, `-` and `_` (30 characters).
- `pos_outlets` are never deleted once referenced by a `pos_posting` or `pos_outlet_mapping`; deactivate them instead.

## Enums

- `PosProvider`: `Loyverse` (`loyverse`), `GenericApi` (`generic_api`).
- `PosConnectionStatus`: `Connected` (`connected`), `Error` (`error`), `Disconnected` (`disconnected`).
- `PosPostingType`: `Charge` (`charge`), `Void` (`void`).
- `PosPostingStatus`: `Posted` (`posted`), `Reversed` (`reversed`), `PendingReview` (`pending_review`), `Ignored` (`ignored`).

## Actions

All Actions live in `App\Actions\Pos`, run in a single database transaction, and write nothing on a refusal.

### `SavePosOutlet`

`(User $actor, Property $property, PosOutletData $data, ?PosOutlet $outlet = null): PosOutlet`

- Creates or updates an outlet.
- Checks that `code` is unique per property (case-insensitive, normalized uppercase) and `default_charge_item_id` is an active `ChargeItem` belonging to the property.
- **No double-taxation:** Validates that every enabled tax linked to `default_charge_item`'s `TransactionCode` has `is_inclusive = true` (refuses with `exclusive_tax_not_allowed` otherwise). Because external POS terminals send the final guest-signed receipt total (already including SST/service charge), tax-inclusive extraction guarantees the folio gross matches the POS receipt to the exact sen.
- Logs `pos.outlet_created` or `pos.outlet_updated`.

### `PostPosCharge`

`(PosOutlet $outlet, PosChargeData $data): PosPostingResult`

`PosChargeData`: `external_receipt_id`, `external_receipt_number`, `amount` (`Money`, positive, max 2 decimals — the room-charge tender gross amount), `reservation_unit_id` (nullable), `pos_connection_id` (nullable), `service_date` (nullable), `raw_payload` (`array`), optional `failure_hint` (when the adapter could not match a room).

1. **Idempotency:** Look up `pos_postings` by `(pos_outlet_id, external_receipt_id, Charge)`. If found, return the existing `PosPosting` without writing.
2. **Pre-checks (soft failure → `PendingReview`, never dropped):**
    - Property has an open business date (`no_business_date`).
    - `PosOutlet` is active and its `default_charge_item` is active (`outlet_inactive`).
    - `reservation_unit_id` is present, belongs to the property, and status is `CheckedIn` or `CheckedOut` (`unit_not_postable` / `room_not_matched`).
    - `FolioRouter` resolves an **Open** target folio (`folio_not_open` / `folio_settled_to_city_ledger`).
    - If any check fails: insert `pos_postings` with `status = PendingReview`, `failure_reason`, and `raw_payload`, log `pos.posting_pending_review`, and return.
3. **Posting:** `PostPosCharge` calls `PostCharge` with no actor, the outlet's `TransactionCode` (not the charge item, so the item's fixed-price rules do not apply) and the receipt amount as the unit amount. `PostCharge` does the unit lock, `FolioRouter` folio choice, `TaxCalculator` tax extraction and ledger write, and its refusal code becomes `failure_reason`. In outline:
    - Calculate taxes from `$data->amount` using `TaxCalculator` and the outlet's `default_charge_item` tax rules (gross-inclusive or exclusive per property/item tax configuration).
    - Build description: `"{$outlet->name} — Receipt #{$data->externalReceiptNumber}"`.
    - Call `PostFolioCharge` with `$actor = null` (system), `$key = "pos:{$outlet->id}:{$data->externalReceiptId}"`, `$source = $posting` (or `$unit`), and `$postingDate = $businessDate`.
    - Save `pos_postings` with `status = Posted`, `folio_line_id = $chargeLine->id`, `posted_at = now()`.
    - Because the charge lands as a normal `FolioLine` with `TransactionCode` and `Tax` children, Revenue Reports, Accounting exports, and [LHDN e-Invoicing](../31-einvoice/01-lhdn-myinvois.md) include it automatically.

### `VoidPosCharge`

`(PosOutlet $outlet, PosVoidData $data): PosPostingResult`

- Idempotent on `(pos_outlet_id, external_receipt_id, Void)`.
- Finds the original `Charge` `PosPosting` by `(pos_outlet_id, original_external_receipt_id, Charge)`.
- **Partial refund guard:** If `$data->amount` is provided and is strictly less than the original `PosPosting.amount`, stores the void row as `PendingReview` with `failure_reason = 'partial_refund_manual_review'` so front desk posts the exact adjustment manually.
- If the original posting is `PendingReview`: marks the original posting `Ignored` (with note `"Voided before posting"`) and records the void row as `Reversed`.
- If the original posting is `Posted` and its `FolioLine`'s folio is `Open`: calls `ReverseLine` with `$actor = null` (`ReverseLine` is extended to accept `?User $actor = null`, matching `PostFolioCharge`), marks the original posting `Reversed`, and stores the reversal `FolioLine` id on the void row.
- If the original posting is missing or its folio is already `Closed`: stores the void row as `PendingReview` with `failure_reason` (`original_not_found` or `folio_closed_cannot_void`) so front desk handles the refund/adjustment manually.

### `ResolvePendingPosPosting`

`(User $actor, PosPosting $posting, ResolvePosPostingData $data): PosPostingResult`

- Allowed only when `$posting->status === PosPostingStatus::PendingReview`.
- Two resolutions:
    - **Attach to folio (`attach`):** Staff picks a target `ReservationUnit` (and optional open `Folio`). Runs the same `TaxCalculator` + `PostFolioCharge` step with `$actor`, sets `status = Posted`, `folio_line_id`, `resolved_by_user_id`, `resolved_at`, `resolution_note`.
    - **Ignore (`ignore`):** Requires `resolution_note` (e.g., _"Duplicate test receipt"_ or _"Guest paid cash at front desk"_). Sets `status = Ignored`, `resolved_by_user_id`, `resolved_at`, `resolution_note`.
- Logs `pos.posting_resolved` or `pos.posting_ignored`.

## Night audit integration

- The sheet's JSON (`NightAuditSheetData`) carries `posReview` (`message`, `url`), built by `PendingPosReview`. It is `null` when the feature is off or nothing waits, and the link shows only to a user with `pos.view`.

- The [Night Audit Readiness Panel](../20-night-audit/02-readiness-panel.md) displays a **non-blocking warning** when the property has any `pos_postings` with `status = PendingReview`:
    - Shows count and total amount (e.g., _"2 POS postings need review (RM 142.50)"_) with a direct link to `/p/{code}/finance/pos-postings?status=pending_review`.
    - Never blocks the unattended 2 AM night audit (per [overview.md](../overview.md#product)).

## Routes

Loaded from `routes/pos.php` under `/p/{property:code}` with `EnsurePropertyAccess::class`, `verified`, `live`, `pos-integration`:

| Method & URL                                   | Route name                     | Permission    | Purpose                                   |
| ---------------------------------------------- | ------------------------------ | ------------- | ----------------------------------------- |
| `GET /settings/pos-outlets`                    | `settings.pos-outlets.index`   | `pos.manage`  | List and manage outlets                   |
| `POST /settings/pos-outlets`                   | `settings.pos-outlets.store`   | `pos.manage`  | Create outlet                             |
| `PUT /settings/pos-outlets/{outlet}`           | `settings.pos-outlets.update`  | `pos.manage`  | Update / deactivate outlet                |
| `GET /finance/pos-postings`                    | `finance.pos-postings.index`   | `pos.view`    | POS posting log & Pending Review queue    |
| `GET /finance/pos-postings/{posting}`          | `finance.pos-postings.show`    | `pos.view`    | Inspect receipt breakdown (`raw_payload`) |
| `POST /finance/pos-postings/{posting}/resolve` | `finance.pos-postings.resolve` | `pos.resolve` | Attach pending posting to folio or ignore |

## Permissions

| Permission    | Default roles              | Gives                                                                                 |
| ------------- | -------------------------- | ------------------------------------------------------------------------------------- |
| `pos.view`    | Owner, Manager, Front Desk | View POS postings log and receipt details                                             |
| `pos.manage`  | Owner, Manager             | Manage POS outlets, Loyverse OAuth connection, store/payment mappings, POS API tokens |
| `pos.resolve` | Owner, Manager, Front Desk | Attach `PendingReview` POS postings to folios or mark ignored                         |
