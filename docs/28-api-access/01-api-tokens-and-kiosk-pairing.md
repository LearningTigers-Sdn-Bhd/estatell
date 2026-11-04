# API tokens and kiosk pairing

## Purpose

One credential layer for everything that calls Estatell without a staff session. The kiosk is the first user. A public API-key flow can reuse the same table and middleware later. Staff pages stay Inertia; this is the only JSON API.

## Scope

| Now                                                        | Later                                                         |
| ---------------------------------------------------------- | ------------------------------------------------------------- |
| `api_tokens` table, hashed tokens, scopes, revoke          | `integration` tokens, scope choice, API-key screen, `/api/v1` |
| Kiosk pairing: code, `POST /pair`, `GET /me`               | Every other endpoint of the kiosk contract                    |
| Bearer middleware `api.token:{scope}`, rate limits, errors | Idempotency storage, per-token IP allow list                  |
| Staff screen `Settings → Kiosks`: list, add, revoke        | Regenerate a pairing code (today: revoke and add again)       |

The kiosk contract is `docs/KIOSK_API.md` in the estatell-kiosk repo. This file does not change it.

## Table `api_tokens`

| Column                                                  | Notes                                                                                     |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `property_id`                                           | Every credential belongs to one property. Every query is scoped by it                     |
| `kind`                                                  | `kiosk` or `integration` (`ApiTokenKind`)                                                 |
| `name`                                                  | Staff label, for example "Lobby kiosk"                                                    |
| `device_name`                                           | Sent by the kiosk at pairing                                                              |
| `token_hash`                                            | SHA-256 of the token. Null until a kiosk pairs. The plaintext is never stored             |
| `pairing_code_hash`                                     | SHA-256 of the pairing code. Set until the code is used, expired or revoked, then cleared |
| `pairing_expires_at`                                    | Ten minutes after the kiosk was added                                                     |
| `scopes`                                                | Space-separated, for example `kiosk:*`. Plain text, not jsonb                             |
| `paired_at`, `last_used_at`, `expires_at`, `revoked_at` | `last_used_at` is written at most once a minute. `expires_at` is empty for kiosks         |

Tokens look like `est_live_` plus 40 random characters. Pairing codes are 8 characters from an alphabet without `0 1 I L O`, shown as `ABCD-EFGH`. Both are random, so a fast hash is enough.

## Pairing

1. Staff open `Settings → Kiosks` and press **Add kiosk** with a name. `CreateKioskPairing` stores a row with a code hash. The code is shown once, in a dialog, from flash data.
2. At setup the kiosk calls `POST /api/kiosk/v1/pair` with `{ pairing_code, device_name }`. `PairKiosk` locks the row, checks it, writes `token_hash`, clears the code and returns `{ token, property: { id, name } }`. Two calls with one code cannot both win.
3. A wrong, used, expired or revoked code, a missing code, or a suspended property all answer `401 kiosk_unauthorized`. The contract has no separate validation code, so a malformed body is answered the same way.
4. Staff can **Revoke** at any time. The token stops working on the next request, and an unused code dies.

## Requests

| Item        | Rule                                                                                                                                   |
| ----------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Middleware  | `api.token:{scope}` (`AuthenticateApiToken`): finds the token by hash, refuses revoked, expired, unknown and suspended-property tokens |
| Scope       | Exact match on the space-separated list. Kiosk routes need `kiosk:*`. A kiosk token never passes a `v1:*` route, and the reverse       |
| Property    | Read from the token (`$request->attributes->get('api_token')->property`). Never from the URL or body                                   |
| Rate limits | `api-pair`: 10 per minute per IP. `api-token`: 120 per minute per token                                                                |
| Format      | JSON, snake_case, Eloquent API Resources without the `data` wrapper                                                                    |

## Errors

Shape: `{ "error": { "code": "...", "message": "..." } }`. Rendered by `ApiError` and the exception handler for every `/api/*` path.

| Code                 | Status | When                                                                          |
| -------------------- | ------ | ----------------------------------------------------------------------------- |
| `kiosk_unauthorized` | 401    | Token or pairing code missing, wrong, revoked or expired, or wrong scope      |
| `rate_limited`       | 429    | A limit above. `Retry-After` is kept                                          |
| `server_error`       | 500    | Any exception that is not an HTTP or validation error. The message is generic |
| `unauthorized`       | 401    | Same as `kiosk_unauthorized` for non-kiosk scopes (future `/api/v1`)          |

## Endpoints in this slice

| Call                      | Auth      | Returns                             |
| ------------------------- | --------- | ----------------------------------- |
| `POST /api/kiosk/v1/pair` | None      | `{ token, property: { id, name } }` |
| `GET /api/kiosk/v1/me`    | `kiosk:*` | `{ kiosk_name, property_name }`     |

## Staff screen

`GET /p/{code}/settings/kiosks` (`settings.kiosks.index`), `POST .../kiosks` (`store`), `POST .../kiosks/{apiToken}/revoke` (`revoke`). Permission `settings.manage`. Rows show a status (`KioskStatus`): waiting for pairing, paired, code expired, revoked. No secret is ever sent in page props.

## Activity log

`api-access.kiosk-added`, `api-access.kiosk-paired` (no actor: the kiosk), `api-access.token-revoked`.

## Code

| Piece                                                                    | Where                                         |
| ------------------------------------------------------------------------ | --------------------------------------------- |
| `CreateKioskPairing`, `PairKiosk`, `RevokeApiToken`, `RecordApiTokenUse` | `app/Actions/ApiAccess/`                      |
| `ApiSecrets` (make and hash), `ApiTokenGate` (find and scope check)      | `app/Services/ApiAccess/`                     |
| `ApiTokenKind`, `KioskStatus`                                            | `app/Enums/`                                  |
| `AuthenticateApiToken`, `ApiError`, API controllers and resources        | `app/Http/`                                   |
| Routes                                                                   | `routes/api.php` (prefix `/api`, `api` group) |
