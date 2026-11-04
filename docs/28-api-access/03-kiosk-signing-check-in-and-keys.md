# Kiosk signing, check-in, keys, check-out and walk-in

## Purpose

The second half of the kiosk contract (`docs/KIOSK_API.md` in the estatell-kiosk repo), after [find, ID and guest](02-kiosk-check-in-endpoints.md). Same rules: `kiosk:*` token, the token's property only, the [error shape](01-api-tokens-and-kiosk-pairing.md#errors), `Idempotency-Key` on every write. This file does not change the contract.

## Registration card

| Call                                                   | Sends                              | Returns                         |
| ------------------------------------------------------ | ---------------------------------- | ------------------------------- |
| `GET /api/kiosk/v1/stays/{id}/registration-card`       | none                               | `{ terms_version, terms_text }` |
| `POST /api/kiosk/v1/stays/{id}/registration-card/sign` | `{ terms_version, signature_png }` | `{ signed }`                    |

- The stay is found with `KioskStays::find`: Confirmed, inside the [check-in window](02-kiosk-check-in-endpoints.md#calls). Another property's stay, a missing one and one outside the window all answer `booking_not_found` (404).
- **Terms** come from `RegistrationTermsResolver`, the resolver the staff and guest card screens use (the room type's document, else the property default). The text is the Markdown body with the property placeholders filled (`TermsRenderer`), checklist lines (`- [ ] ...`) included. `Accept-Language` (`en`, `ms`, `zh`) picks the language (`KioskLanguage`). Estatell has no Chinese terms (`Locale` is `en` and `ms`), so `zh` falls back to the guest's own or the property's language. A property with no published terms answers `server_error` (409).
- **Sign** is the existing `SignRegistrationCard` action, called with no actor (the activity log has no causer). It signs the **primary guest's** card. The contract sends no checklist ticks, so the guest ticking on the kiosk screen means **every** checklist item counts as ticked. The signer name is the guest's name and the guest's details stay as the kiosk saved them. The card links the guest's newest ID document.
- `terms_version` must be the version the guest saw (`registration_card_terms.version`, in the language the same `Accept-Language` resolves). Another version, a bad body, a signature that is not a PNG or is over 1 MB, a stay without a primary guest and a closed guest all answer `200 { signed: false }` and write nothing. The contract has no validation error code, as for `identity`.
- `signature_png` is a PNG as plain base64 or a `data:image/png;base64,` URL. `SignRegistrationCard` checks the prefix, the PNG magic bytes and the size, then stores it as the card's signature media like the staff flow.
- A card that is already signed answers `signed: true` again and writes nothing, so a retry without a key is safe.
- The signature, terms and name never appear in a log or an exception message.

## By-id lookups and the lockout

Every call with `{id}` goes through `KioskStayController::stay()`. It takes an attempt from the token's lockout ([`KioskLookupLockout`](02-kiosk-check-in-endpoints.md#guessing-protection), the same counter `bookings/find` uses), looks the stay up with `KioskStays::find` (token's property, right status, inside the check-in window) and gives the attempt back when it finds one. A miss, another property's stay and a stay outside the window are the same `booking_not_found` and the same failure. After 10 misses in 5 minutes the token gets `429 rate_limited` for every call that looks up a stay. `identity`, `guest`, the registration card and the key endpoints use it. Check-out uses it with the departure window described below. A body that fails validation is answered before the lookup (`accepted: false`, `signed: false`) and does not count.

## Eligibility and check-in

| Call                                       | Returns                                                                                   |
| ------------------------------------------ | ----------------------------------------------------------------------------------------- |
| `GET /api/kiosk/v1/stays/{id}/eligibility` | `{ eligible: true }` or `{ eligible: false, reason }`                                     |
| `POST /api/kiosk/v1/stays/{id}/check-in`   | `{ room: { number }, key_mode }`                                                          |
| `GET /api/kiosk/v1/config`                 | `{ property_name, languages, key_mode, walk_in_enabled, idle_timeout_seconds, currency }` |

- **Paid enough** is the rule that already exists: `SelfCheckInPayment` (decision D19, see [Check-in and check-out](../18-front-desk/01-check-in-and-check-out.md)). The unit folio must hold completed payments, minus refunds, that cover the taxes flagged _Collect at check-in_ plus the security deposit. `KioskEligibility` returns `deposit_not_paid` when it does not. No rule was invented.
- `balance_not_settled` is **not** returned at check-in: Estatell has no other balance condition before arrival. It is used at check-out. `arrival_not_reached` is not returned by `eligibility` either: a stay outside its window answers `booking_not_found` like every by-id call, and `bookings/find` already tells the kiosk when check-in opens.
- **Check-in** is `CheckInUnit`, called with **no actor** (the chain of actions it calls now accepts a null actor, as the night audit already did for room charges). Its checks are the front desk's: registration card (`card_required_for_check_in`), nationality (`nationality_required`), arrival (`arrival_not_reached`), a room for tonight (`AutoAssignRooms` when the property has it on). The kiosk adds the paid-enough check first, so nothing is written for a guest who still owes. It never skips the card and never overrides a credit limit. `deposit_not_paid` and the others answer `409` with the contract code. Anything the guest cannot fix (no room ready, credit limit, no open business date) answers `server_error` (409) and writes nothing.
- A **stay the kiosk already checked in** answers the same room again within 30 minutes, without a second check-in. After that it answers `booking_not_found`. The kiosk's own check-in is marked with the activity `kiosk.checked_in` (kiosk id only). A stay checked in by the front desk is `booking_not_found` here, so a guessed id never shows a room.
- `room.number` is the room of the check-in night (a bed counts as its room). Estatell keeps no walking directions for a room, so `directions` is always left out.
- `key_mode` is the property's kiosk setting.

## Kiosk settings

Table `property_settings` has three new columns: `kiosk_key_mode` (`pin`, `card` or `both`, default `pin`, `KioskKeyMode`), `kiosk_walk_in_enabled` (default off) and `kiosk_idle_timeout_seconds` (15 to 600, default 60, a database CHECK). Staff change them on `Settings → Kiosks` (`PUT /p/{code}/settings/kiosks/settings`, `settings.kiosks.update`, permission `settings.manage`, `UpdateKioskSettings`, log `api-access.kiosk-settings-updated` with the field names).

`GET /config` reads them. `languages` are the languages Estatell has terms for (`en`, `ms`; no Chinese terms exist yet). `currency` is the property currency.

## Keys and cards

| Call                                  | Sends     | Returns                                                 |
| ------------------------------------- | --------- | ------------------------------------------------------- |
| `GET /api/kiosk/v1/stays/{id}/key`    | none      | `{ pin: { status, code?, valid_from?, valid_until? } }` |
| `POST /api/kiosk/v1/stays/{id}/cards` | `{ uid }` | `{ registered: true, valid_until }`                     |

Both use the shared by-id lookup, require CheckedIn and a `kiosk.checked_in` marker within 30 minutes. A front-desk check-in, another property's stay and a missing stay all answer `booking_not_found`. The shared failed-lookup lockout applies.

- `StayLocks` reads the locks of the stay's rooms, ordered by id. `SyncStayPasscodes` uses it too.
- `KioskPin` reads the newest Pending, Active or Failed PIN whose end is still in the future on each lock. All must be Active to answer `ready`; any Failed answers `failed`, otherwise Pending answers `pending`. Without a row, it waits up to 120 seconds after check-in, then answers `failed`. No lock, door locks feature off or connection off answers `failed`. A PIN with no code also fails. Revoked and expired PINs are never served.
- `ready` includes the first lock's code and RFC 3339 validity times. A multi-lock stay shows only that PIN. The response has `Cache-Control: no-store`. PINs never appear in activity logs.
- `RegisterStayCard` uses `CardNumber` and `PasscodeWindow` to register the UID on every stay lock through `RegisterDoorCard`, from check-in to departure in the property's time zone. It uses `stay:{unit}:{lock}:card:{number}` as the domain idempotency key. Repeating with another HTTP key or another spelling of the same UID does not register again. Failed rows are retried; a Pending row fails closed.
- Registration succeeds only when every card row is Active. Missing lock, disabled feature or connection, no gateway, TTLock failure or timeout, card in use and a fifth card on a stay lock answer `503 lock_unavailable`. That answer is never cached by the idempotency middleware. Registration is serialized per stay so simultaneous requests cannot bypass the four-card limit. HTTP errors and missing or invalid TTLock card IDs fail closed.
- A malformed body or invalid UID answers `404 card_unknown`, without echoing the UID. The machine must hold the card until registration succeeds. Card deletion at check-out is part of the next milestone; no standalone delete endpoint exists in the contract.

## Check-out

| Call                                      | Sends      | Returns                                            |
| ----------------------------------------- | ---------- | -------------------------------------------------- |
| `POST /api/kiosk/v1/cards/lookup`         | `{ uid }`  | `{ known, stay_id?, room_number?, settled? }`      |
| `GET /api/kiosk/v1/stays/{id}/folio`      | none       | `{ balance: { amount_minor, currency }, settled }` |
| `POST /api/kiosk/v1/stays/{id}/check-out` | `{ uid? }` | `{ checked_out, invoice_sent }`                    |

- **Departure window:** the two by-id calls use `KioskStayController::stay()` with the check-out window. An open business date is required; the check-out stay day (`StayDay::forCheckOut`, business date for day use) must be on or after departure. CheckedIn stays qualify, including front-desk check-ins. This window is separate from the check-in window, which ends before departure. Property scoping and the shared lookup lockout remain in force. Before departure, another property's stay and a missing stay all answer `booking_not_found` (404).
- **Card lookup** uses `StayByCard` and the same departure window. Unknown or invalid UIDs, cards of another property, inactive cards and stays outside the window answer `{ known: false }`. It refunds found lookups and counts unknowns in the same per-token lockout as `bookings/find`; after ten failures every lookup answers `429 rate_limited`. It returns the final assigned room, including a bed's room. This POST only reads, so it does not use idempotency storage.
- **Folio** reads the unit's non-Voided Unit folios through `CheckOutSettlement` and `FolioBalance`. Money uses `brick/money`, without floats. `settled` requires every folio to be zero; a charge on one folio cannot cancel a credit on another. The front-desk action still posts any pending night and rechecks settlement under its locks before check-out.
- **Check-out** calls `CheckOutUnit` with no staff actor and preserves its settlement, pending-night, scheduled-charge and folio-close rules. A balance or scheduled charges that still prevent closure answer `409 balance_not_settled`; other front-desk refusals answer `409 server_error`. A refusal rolls back pending-night postings. Successful check-out closes the Unit folios, issues their invoice numbers, marks the room Dirty and dispatches `StayCheckedOut` for the existing queued PIN revocation. No invoice-email action exists: `invoice_sent` is `false`. Migration `allow_automated_folio_closure` allows a closure timestamp without a staff user; a staff user still requires a timestamp. Its rollback refuses once automated closures exist, preserving their attribution.
- A supplied UID must belong to a card of this property and stay, or the answer is `404 card_unknown`; malformed bodies get that code too. Unrelated UID guesses count in the per-token lockout. The UID is never echoed or logged. Every remaining Pending, Active or Failed card of the stay is removed through `RemoveDoorCard`, including when a PIN guest checks out without a UID. Card registration and check-out share a per-stay lock.
- **Remote cleanup:** check-out commits before card removal, because a remote deletion cannot be rolled back. Each successful removal is kept. TTLock failure or timeout, a disabled connection or feature, and no gateway answer `503 lock_unavailable`, never cached; the kiosk must not keep the card on that answer. The stay can already be CheckedOut. A `kiosk.checked_out` activity marker permits only that same kiosk to repeat the check-out call for 30 minutes and finish remaining deletions without repeating check-out. Other kiosks, historical check-outs and folio reads of CheckedOut stays answer `booking_not_found`. Beyond that retry window, staff must finish cleanup. A successful HTTP idempotency replay remains available for 24 hours as usual.
- `bookings/find` also finds CheckedIn stays in the departure window, so a PIN guest can find their stay for check-out. It keeps the existing response shape and first-arriving-stay selection.
