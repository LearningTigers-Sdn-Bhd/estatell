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

## Deployment and rollback

Install the locked Composer dependencies and run `php artisan migrate` before serving the new application. Coordinate the schema and application switch: the old code expects `api_tokens`, while the new code expects `kiosks`.

The forward migration preserves existing unrevoked token hashes, expiry times, kiosk ids, stored idempotency answers and activity subjects. It stops before changing kiosk data if any old credential has a kind other than `kiosk`.

Rollback restores the old schema and copies token hashes back, but it cannot make newly issued Sanctum tokens work with the old application. Those tokens include an `id|` prefix; the old code requires `est_live_` at the start and hashes the entire bearer string. The stored Sanctum hash covers only the secret after `|`. Removing the prefix on the client is not a supported recovery procedure.

If rolling back after any kiosk has paired on this version:

1. Stop API requests while restoring the old schema and application together.
2. Treat kiosks paired on this version as needing re-pairing. In the restored Settings screen, revoke each affected kiosk, add a replacement, and pair it with the new code.
3. Verify `/me` from each affected kiosk before returning it to service.

Legacy tokens carried through the forward migration keep their original format and do not need re-pairing solely because of rollback. Re-pairing creates a new kiosk id, so its rate-limit counters and idempotency history do not carry over. Do not replay an uncertain write under the replacement identity without checking its result first.

## Code

| Piece                                                              | Where                     |
| ------------------------------------------------------------------ | ------------------------- |
| `Kiosk`                                                            | `app/Models/`             |
| `CreateKioskPairing`, `PairKiosk`, `RevokeKiosk`, `RecordKioskUse` | `app/Actions/ApiAccess/`  |
| `ApiSecrets` (pairing codes)                                       | `app/Services/ApiAccess/` |
| `KioskStatus`                                                      | `app/Enums/`              |
| API controllers and resources                                      | `app/Http/`               |
