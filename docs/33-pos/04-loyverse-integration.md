# Loyverse POS integration (`WAS-121`)

## Purpose

Connect a property's Loyverse POS account to Estatell via OAuth 2.0 and webhooks so cashiers can bill restaurant or café orders to a guest's hotel room folio, and list Estatell on the **Loyverse App Marketplace**.

## Scope

| Now                                                                                                      | Later                                                   |
| -------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| OAuth 2.0 public app flow (Loyverse App Marketplace ready)                                               | Multi-property single Loyverse merchant account support |
| Store → `PosOutlet` and custom `"Room Charge"` Payment Type mapping                                      | Loyverse item/menu catalog sync into Estatell           |
| Automatic in-house guest → Loyverse Customer sync (`StayCheckedIn`, `StayRoomChanged`, `StayCheckedOut`) | Periodic reconciliation job polling missed receipts     |
| Webhook ingestion (`receipts.update`) for `SALE` and `REFUND` receipts                                   | Loyverse employee attribution on folio notes            |
| Split-payment support (posts only the `"Room Charge"` tender portion)                                    | Partial refund receipt calculation                      |

## Architectural constraint (Loyverse POS)

Loyverse POS does not support custom checkout UI plugins or real-time PMS lookups inside the cashier app. The integration bridges this cleanly in two halves:

1. **Estatell → Loyverse (Guest Sync):** Whenever a room checks in, moves rooms, or checks out, Estatell syncs a Loyverse Customer formatted as `Room {number} - {guest_name}` (with the reservation reference in the customer note). Cashiers attach that customer at checkout and tender the bill using the mapped `"Room Charge"` custom payment type.
2. **Loyverse → Estatell (Webhook Posting):** Loyverse fires `receipts.update` after checkout. Estatell verifies the HMAC signature, filters for the mapped `"Room Charge"` payment type, resolves the `ReservationUnit` via `pos_guest_links`, and calls `PostPosCharge` (or `VoidPosCharge` on refunds).

## Data model

Reuses `pos_connections`, `pos_outlet_mappings`, and `pos_postings` from [01-outlets-and-postings.md](01-outlets-and-postings.md), plus:

```
pos_guest_links         id, property_id, pos_connection_id, reservation_unit_id,
                        external_customer_id, synced_label, last_synced_at, timestamps
                        unique (pos_connection_id, reservation_unit_id)
                        unique (pos_connection_id, external_customer_id)
```

- `pos_guest_links` maps a checked-in `ReservationUnit` to its temporary Loyverse Customer record.
- `pos_connections.access_token` and `refresh_token` are stored encrypted (`encrypted` cast) and never sent to the browser.

## Configuration

Lives in `config/services.php` under `loyverse`:

- `LOYVERSE_CLIENT_ID`
- `LOYVERSE_CLIENT_SECRET`
- `LOYVERSE_REDIRECT_URI` (e.g., `https://app.estatell.com/webhooks/loyverse/oauth/callback` or signed staff callback)
- `LOYVERSE_BASE_URL` (default `https://api.loyverse.com`)

## OAuth 2.0 flow

1. Staff with `pos.manage` clicks **Connect Loyverse** on `/p/{code}/settings/pos-integrations`.
2. Estatell generates a signed, short-lived `state` token (storing `property_id` and `user_id` in cache for 10 minutes) and redirects to Loyverse OAuth authorize URL with scopes:
    - `CUSTOMERS_READ`, `CUSTOMERS_WRITE`
    - `RECEIPTS_READ`
    - `STORES_READ`
    - `PAYMENT_TYPES_READ`
    - `WEBHOOKS_WRITE` (if registering webhook via API; otherwise configured per app in Loyverse Developer Portal)
3. Callback verifies `state`, exchanges `code` for `access_token` + `refresh_token`, fetches merchant info, upserts `pos_connections` (`provider = Loyverse`, `status = Connected`), and redirects back to `/p/{code}/settings/pos-integrations`.
4. **Token refresh:** `LoyverseClient` checks `token_expires_at` (with a 5-minute buffer) and refreshes the token under a per-connection cache lock before any API call. If refresh fails with `invalid_grant`, sets `pos_connections.status = Error` and notifies property admins.
5. **Disconnect:** Revokes/clears tokens, sets `status = Disconnected`, and stops outbound guest syncs. Existing `pos_postings` history is preserved.

## Store & payment type mapping

On `/p/{code}/settings/pos-integrations`, once connected:

1. Staff clicks **Refresh Loyverse stores & payment types** (`GET /v1.0/stores`, `GET /v1.0/payment_types`).
2. For each Loyverse Store the hotel wants connected:
    - Select the target Estatell `PosOutlet` (from `pos_outlets`).
    - Select the Loyverse custom payment type that represents **Room Charge** (e.g., `"Room Charge"` / `"Bill to Room"`).
    - Toggle active.
3. Saved to `pos_outlet_mappings`. Unmapped stores and non-room-charge payment types (Cash, Card, DuitNow) are ignored by the webhook processor.

## In-house guest sync (Estatell → Loyverse)

Listeners in `App\Listeners\Pos\` react to existing stay events (no-op when `PosIntegration` is off or no active Loyverse connection exists):

| Event             | Queued Job               | Loyverse API Action                                                                                                                                                                                                                    |
| ----------------- | ------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `StayCheckedIn`   | `SyncLoyverseGuestJob`   | `POST /v1.0/customers` (1 customer per checked-in `ReservationUnit`: `"Room {number} - {guest_name}"`, or `"{room_name} / {bed_label} - {guest_name}"` for dorm beds, `note = "Estatell {reservation_ref}"`); upsert `pos_guest_links` |
| `StayRoomChanged` | `SyncLoyverseGuestJob`   | Update existing Loyverse Customer `name` to the new room/bed label (uses current room via unit night logic)                                                                                                                            |
| `StayCheckedOut`  | `RemoveLoyverseGuestJob` | `DELETE /v1.0/customers/{id}` so cashiers never see departed guests (if Loyverse ever rejects delete with 4xx, fall back to renaming `"[Checked Out] ..."`), then delete `pos_guest_links` row                                         |

- **Initial sync button:** Settings page includes a **Sync all in-house guests now** action to backfill currently checked-in rooms right after first connecting Loyverse.
- **Rate limiting:** Loyverse enforces ~60 requests/minute. All outbound Loyverse jobs run on a dedicated Horizon queue with `Redis::throttle('loyverse:'.$connection->id)->allow(45)->every(60)` and exponential backoff on `429`.

## Webhook ingestion (Loyverse → Estatell)

- **Route:** `POST /webhooks/loyverse` (`webhooks.loyverse`, in `routes/webhooks.php`, no CSRF).
- **Verification:** Compute HMAC-SHA1/SHA256 (per Loyverse Developer docs for public apps) of the raw request body using the app's client secret / webhook secret and compare with `X-Loyverse-Webhook-Signature` using `hash_equals`. Reject invalid signatures with `401`.
- **Fast ack:** Immediately dispatch `ProcessLoyverseWebhookJob` and return `200 OK` so Loyverse never times out or disables the webhook.
- **Job processing (`ProcessLoyverseWebhookJob`):**
    1. Resolve `pos_connection` by `merchant_id` (ignore if disconnected or feature off).
    2. For each receipt in the `receipts.update` payload:
        - Find active `pos_outlet_mapping` matching `receipt.store_id`. If none, ignore.
        - Sum `payments[]` where `payment_type_id === mapping.external_payment_type_id`. If the sum is `<= 0` (pure cash/card walk-in), ignore.
        - **Split payments:** Only the `"Room Charge"` tender sum is posted to Estatell (never the cash/card portion).
        - Match `receipt.customer_id` via `pos_guest_links` to get `reservation_unit_id`.
            - _Fallback:_ If `customer_id` is empty or unlinked, inspect `receipt.note` for a room number hint and pass `reservation_unit_id = null` with `failure_hint = "No linked in-house guest on receipt"` so `PostPosCharge` captures it in `PendingReview` instead of guessing wrong.
        - If `receipt.receipt_type === 'SALE'`: call `PostPosCharge` with `external_receipt_id = receipt.receipt_number` (or Loyverse receipt UUID), `amount = roomChargeSum`, `raw_payload = receipt`.
        - If `receipt.receipt_type === 'REFUND'`: call `VoidPosCharge` referencing `receipt.refund_for` (the original receipt number). If the refund is a partial refund that differs from the original charge amount, land in `PendingReview` with `failure_reason = 'partial_refund_manual_review'` for front desk verification.

## Loyverse App Marketplace readiness

To publish Estatell in the Loyverse App Marketplace:

- Public OAuth 2.0 app (never Personal Access Tokens in production).
- **Self-serve connect & feature-gate UX:** Property Owners/Managers connect Loyverse self-serve when `PosIntegration` is enabled. If an authenticated user arrives from the Loyverse App Marketplace install link for a property where `PosIntegration` is still off, show a dedicated **"Contact support to activate POS Integration"** page (never a silent `404`, which fails Marketplace QA).
- Graceful handling of app uninstall / token revocation webhooks (marks `pos_connections.status = Disconnected`).
- Public privacy policy (`/privacy-policy`) and terms (`/terms-and-conditions`) already exist in Estatell ([routing.md](../routing.md#principles)).
- Merchant onboarding guide covering: creating the `"Room Charge"` payment type in Loyverse Back Office, mapping stores in Estatell, and attaching the synced room customer at checkout.

## Routes

In `routes/pos.php` (behind `EnsurePropertyAccess::class`, `verified`, `live`, `pos-integration`, `can:pos.manage`):

| Method & URL                                               | Route name                                           | Purpose                                                          |
| ---------------------------------------------------------- | ---------------------------------------------------- | ---------------------------------------------------------------- |
| `GET /settings/pos-integrations`                           | `settings.pos-integrations.index`                    | Loyverse connection status, store/payment mapping, sync controls |
| `GET /settings/pos-integrations/loyverse/connect`          | `settings.pos-integrations.loyverse.connect`         | Redirect to Loyverse OAuth authorize                             |
| `POST /settings/pos-integrations/loyverse/refresh-catalog` | `settings.pos-integrations.loyverse.refresh-catalog` | Fetch stores & payment types                                     |
| `PUT /settings/pos-integrations/loyverse/mappings`         | `settings.pos-integrations.loyverse.mappings.update` | Save store & payment type mappings                               |
| `POST /settings/pos-integrations/loyverse/sync-guests`     | `settings.pos-integrations.loyverse.sync-guests`     | Queue backfill of all checked-in guests                          |
| `DELETE /settings/pos-integrations/loyverse`               | `settings.pos-integrations.loyverse.disconnect`      | Disconnect Loyverse                                              |

In `routes/webhooks.php` (no auth/CSRF):

| Method & URL                            | Route name                         | Purpose                                   |
| --------------------------------------- | ---------------------------------- | ----------------------------------------- |
| `GET /webhooks/loyverse/oauth/callback` | `webhooks.loyverse.oauth-callback` | OAuth callback (validates cached `state`) |
| `POST /webhooks/loyverse`               | `webhooks.loyverse`                | Incoming Loyverse receipt webhooks        |
