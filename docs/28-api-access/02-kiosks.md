# Kiosks

## Purpose

A kiosk is a device of one property that calls the API with its own [token](01-api-tokens.md). This file covers adding, pairing and revoking one. The calls of the check-in flow are in [Kiosk check-in endpoints](03-kiosk-check-in-endpoints.md).

The kiosk contract is `docs/KIOSK_API.md` in the estatell-kiosk repo. This file does not change it.

## Scope

| Now                                          | Later                                                   |
| -------------------------------------------- | ------------------------------------------------------- |
| Kiosk pairing: code, `POST /pair`, `GET /me` | The other endpoints of the kiosk contract               |
| Staff screen `Settings → Kiosks`             | Regenerate a pairing code (today: revoke and add again) |
|                                              | A per-kiosk IP allow list                               |

## Table `kiosks`

| Column                      | Notes                                                                                     |
| --------------------------- | ----------------------------------------------------------------------------------------- |
| `property_id`               | Every kiosk belongs to one property. Every query is scoped by it                          |
| `name`                      | Staff label, for example "Lobby kiosk"                                                    |
| `device_name`               | Sent by the kiosk at pairing                                                              |
| `pairing_code_hash`         | SHA-256 of the pairing code. Set until the code is used, expired or revoked, then cleared |
| `pairing_expires_at`        | Ten minutes after the kiosk was added                                                     |
| `paired_at`, `last_used_at` | `last_used_at` is written at most once a minute                                           |
| `revoked_at`                | The row stays after a revoke, so staff still see it                                       |

The row exists before the kiosk pairs, which is why it is separate from the Sanctum token. A token cannot exist without a value.

Pairing codes are 8 characters from an alphabet without `0 1 I L O`, shown as `ABCD-EFGH`. They are random, so a fast hash is enough.

## Pairing

1. Staff open `Settings → Kiosks` and press **Add kiosk** with a name. `CreateKioskPairing` stores a row with a code hash. The code is shown once, in a dialog, from flash data.
2. At setup the kiosk calls `POST /api/kiosk/v1/pair` with `{ pairing_code, device_name }`. `PairKiosk` locks the row, checks it, creates a Sanctum token with the `kiosk:*` ability, clears the code and returns `{ token, property: { id, name } }`. Two calls with one code cannot both win.
3. A wrong, used, expired or revoked code, a missing code, or a suspended property all answer `401 kiosk_unauthorized`. The contract has no separate validation code, so a malformed body is answered the same way.
4. Staff can **Revoke** at any time. `RevokeKiosk` sets `revoked_at` and deletes the token, so it stops working on the next request, and an unused code dies. Stored idempotency answers stay until they are pruned.

## Requests

A kiosk is refused when its token is unknown or expired, it is revoked, its property is suspended, or the route needs another ability. See [Requests](01-api-tokens.md#requests).

| Call                      | Auth      | Returns                             |
| ------------------------- | --------- | ----------------------------------- |
| `POST /api/kiosk/v1/pair` | None      | `{ token, property: { id, name } }` |
| `GET /api/kiosk/v1/me`    | `kiosk:*` | `{ kiosk_name, property_name }`     |

## Staff screen

`GET /p/{code}/settings/kiosks` (`settings.kiosks.index`), `POST .../kiosks` (`store`), `POST .../kiosks/{kiosk}/revoke` (`revoke`). Permission `settings.manage`. Rows show a status (`KioskStatus`): waiting for pairing, paired, code expired, revoked. No secret is ever sent in page props.

## Activity log

`api-access.kiosk-added`, `api-access.kiosk-paired` (no actor: the kiosk), `api-access.token-revoked`.

## Code

| Piece                                                              | Where                     |
| ------------------------------------------------------------------ | ------------------------- |
| `Kiosk`                                                            | `app/Models/`             |
| `CreateKioskPairing`, `PairKiosk`, `RevokeKiosk`, `RecordKioskUse` | `app/Actions/ApiAccess/`  |
| `ApiSecrets` (pairing codes)                                       | `app/Services/ApiAccess/` |
| `KioskStatus`                                                      | `app/Enums/`              |
| API controllers and resources                                      | `app/Http/`               |
