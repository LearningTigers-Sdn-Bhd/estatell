# Inbound POS API (`/api/pos/v1`)

## Purpose

Provide a clean, provider-agnostic REST API so any modern POS system or hospitality middleware (e.g., Shiji Infrasys, FeedMe, StoreHub, Oracle MICROS bridges, or custom restaurant apps) can look up in-house guests and post/void room charges in Estatell.

## Authentication & scoping

Uses Estatell's existing Sanctum token layer ([API tokens](../28-api-access/01-api-tokens.md)) without a staff session:

- **Token owner:** `PosApiClient` (table `pos_api_clients`; revoking it deletes its token and keeps the row) (plain Eloquent model using `HasApiTokens`, owned by one `Property`, like `Kiosk`).
- **Token ability:** `pos:*` (never reaches `/api/kiosk/v1` or `/api/booking/v1`, and the reverse).
- **Middleware:** `['api.token:pos:*', 'throttle:api-token', EnsurePosApiFeatureActive::class]`.
- **Property scope:** Always resolved from `$request->attributes->get('pos_client')->property`, never from request parameters.
- **Feature gate:** Returns `403 { "error": { "code": "pos_disabled", "message": "..." } }` when `PosIntegration` is off for the property.
- **Idempotency:** `api_idempotency_keys` has a `pos_api_client_id` column; exactly one of the kiosk, booking client and POS client columns is set. Write endpoints (`POST /room-charges`, `POST /room-charges/{external_receipt_id}/void`) use the `api.idempotent` middleware (`Idempotency-Key` header, 24h replay) in addition to `PostPosCharge`'s database uniqueness on `(pos_outlet_id, external_receipt_id, posting_type)`.

## Endpoints

Defined in `routes/api.php` under `Route::prefix('pos/v1')->name('api.pos.v1.')`:

### 1. List active outlets

`GET /api/pos/v1/outlets`

Returns active `pos_outlets` for the property (`id`, `code`, `name`) so an external POS can map its terminals to Estatell outlet codes.

### 2. Look up in-house guests

`GET /api/pos/v1/in-house-guests?q={query}&room={room_number}`

- Searches `CheckedIn` `ReservationUnit` rows for the property (by exact `room` number or partial match `q` on room number, primary guest name, or reservation reference).
- Returns up to 25 matches:

```json
{
    "guests": [
        {
            "reservation_unit_id": 412,
            "reservation_reference": "RES-2026-00841",
            "room_number": "301",
            "guest_name": "Ahmad bin Ali",
            "arrival_date": "2026-11-22",
            "departure_date": "2026-11-25",
            "can_post_charges": true
        }
    ]
}
```

- `can_post_charges` is `true` when the unit is `CheckedIn` and `FolioRouter` resolves an `Open` folio (or can create one).
- Never exposes guest identity document numbers, passport scans, or payment card details.

### 3. Post a room charge

`POST /api/pos/v1/room-charges` (`api.idempotent`)

Request:

```json
{
    "outlet_code": "MAIN-REST",
    "external_receipt_id": "rcpt_9f8a7b6c",
    "external_receipt_number": "1042",
    "reservation_unit_id": 412,
    "amount": "85.50",
    "service_date": "2026-11-23",
    "raw_payload": {
        "items": [
            { "name": "Nasi Lemak Royale", "qty": 2, "amount": "56.00" },
            { "name": "Teh Tarik", "qty": 2, "amount": "16.00" }
        ]
    }
}
```

- Resolves `outlet_code` within the client's property (`404 outlet_not_found` if missing or inactive).
- Calls `PostPosCharge` ([01-outlets-and-postings.md](01-outlets-and-postings.md#postposcharge)).
- Response (`201 Created`, or `200 OK` on idempotent replay):

```json
{
    "posting_id": 901,
    "status": "posted",
    "folio_line_id": 12450,
    "description": "Main Restaurant — Receipt #1042",
    "amount": "85.50"
}
```

- If `PostPosCharge` lands in `PendingReview` (e.g., folio closed or room just checked out), returns `202 Accepted` with `"status": "pending_review"` and `"failure_reason"` so the POS knows the receipt was safely captured for front-desk resolution rather than lost.

### 4. Void / refund a room charge

`POST /api/pos/v1/room-charges/{external_receipt_id}/void` (`api.idempotent`)

Request:

```json
{
    "outlet_code": "MAIN-REST",
    "void_receipt_id": "void_1a2b3c",
    "reason": "Guest disputed item"
}
```

- Calls `VoidPosCharge`.
- Returns `200 OK` with `"status": "reversed"` (or `202 Accepted` with `"status": "pending_review"` if the folio is already closed).

## Error contract

Uses `ApiError` (`{ "error": { "code": "...", "message": "..." } }`):

| Code               | Status | When                                                              |
| ------------------ | ------ | ----------------------------------------------------------------- |
| `unauthorized`     | 401    | Missing, revoked, or wrong-ability Sanctum token                  |
| `pos_disabled`     | 403    | `PosIntegration` feature is off for the property                  |
| `outlet_not_found` | 404    | `outlet_code` does not exist or is inactive                       |
| `invalid_fields`   | 422    | Validation failure (`fields` map included; also for `/api/pos/*`) |
| `rate_limited`     | 429    | Rate limit exceeded (`Retry-After` kept)                          |
| `server_error`     | 500    | Unexpected server error                                           |

## Management UI

- Staff with `pos.manage` manage `PosApiClient` tokens under **Settings → POS → API access** (`/p/{code}/settings/pos-api-clients`): create client (token shown once), view last-used timestamp, and revoke.
