# Kiosk check-in endpoints (first half)

## Purpose

The first three calls of the kiosk flow: find the booking, take the ID, take the guest's corrections. They sit on the [token layer](01-api-tokens.md) and follow the contract `docs/KIOSK_API.md` in the estatell-kiosk repo. This file does not change the contract. The registration card, eligibility, check-in, keys, walk-in and check-out come later.

## Calls

All need `kiosk:*`, are scoped to the token's property and answer with the [error shape](01-api-tokens.md#errors).

| Call                                     | Sends                                                                            | Returns                                                                               |
| ---------------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `POST /api/kiosk/v1/bookings/find`       | `{ reference }` or `{ qr_token }`                                                | `{ stay_id, booking_ref, guest_name, arrival, departure, unit_type, steps_required }` |
| `POST /api/kiosk/v1/stays/{id}/identity` | `{ kind, number, full_name, nationality, date_of_birth?, gender?, expires_on? }` | `{ accepted, nationality_required }`                                                  |
| `POST /api/kiosk/v1/stays/{id}/guest`    | `{ full_name?, nationality? }`                                                   | `{ accepted }`                                                                        |

A **stay** is a reservation unit (`stay_id` is its id). Only a Confirmed stay **inside its check-in window** can be found or changed. The window is one rule, `KioskStays::window()`, shared by the reference lookup and the by-id lookup: the business date is open, the stay day (`StayDay`, as in `CheckInUnit`) is on or after the arrival date, and the stay has not ended. The two writes by id answer a stay outside the window with `booking_not_found`, the same as a missing stay or one of another property.

## Find a booking

- `reference` matches `reservations.reference` of the property, ignoring letter case and spaces at the ends. A reservation with several stays gives the one that arrives first (open: let the guest pick).
- `booking_not_found` (404): no such reference, another property's booking, a stay that is not Confirmed, a stay whose last night has passed, or a body with no `reference`.
- `arrival_not_reached` (409): the stay day is before the arrival date. It uses the same rule as `CheckInUnit` (`StayDay`, business date), so a guest who arrives after midnight while the night audit is pending is not turned away.
- A property with no open business date answers `server_error` (409): nobody can check in yet.
- **No QR token exists in Estatell** (no booking token, no pre-check-in link). A `qr_token` therefore always answers `booking_not_found`. Add it here when Estatell issues one.
- `guest_name` is the stay's primary guest, or the booker when it has none. `unit_type` is the room type name.
- `steps_required.registration_card` is the property setting `card_required_for_check_in`. `steps_required.id` is true when the registration card field `identity_document` is **Required** (the default). Estatell has no separate pre-check-in settings.

## Guessing protection

`bookings/find` counts every lookup that does not find an open booking (unknown reference, early arrival, ended stay, body with no reference) as a failure of the kiosk (`KioskLookupLockout`, cache, per kiosk). After 10 failures within 5 minutes the kiosk gets `429 rate_limited` with `Retry-After` for every lookup, found or not, so the answer says nothing about whether a reference exists. Each lookup takes one attempt first (one atomic increment) and a lookup that finds a booking gives it back; a success never resets the failures, so knowing one valid reference does not help to guess others. This sits on top of the `api-token` limit (120 a minute).

## Take the ID

- `kind` is `my_kad` or `passport` (`IdentityDocumentType`). The number is normalised with `IdentityNumber`; a MyKad needs 12 digits, any number 4 to 40 characters.
- The guest is the stay's primary guest, or the booker when the stay has none (the same person `UnitNationality` reads).
- The document is added with `AddIdentityDocument`. A document of the same type and number is updated with `UpdateIdentityDocument` instead, so a retry does not add a second one, and an expiry the kiosk did not send is kept. Issuing country: `MY` for a MyKad, the nationality for a passport.
- `SetGuestDetails` fills name, nationality, date of birth and gender **only where empty**. A value staff entered stays.
- `nationality` is an ISO 3166-1 alpha-2 code. A value Estatell does not know is treated as not known.
- `nationality_required` is true when an enabled foreign-only tax exists (`RegistrationCardFieldRules::nationalityIsNeededForTax`) and the guest still has no nationality. It is the same test as `CheckInUnit`.
- The contract has no validation error code. A bad `kind`, `number` or `full_name` answers `200 { accepted: false }` and saves nothing. A missing or unparseable `date_of_birth`, `gender` or `expires_on` is left out and the rest is saved. A future or pre-1900 date of birth, a calendar day that does not exist and a gender other than `male` or `female` count as unparseable. A stay of another property or a missing one answers `booking_not_found`, the same for both.
- A guest that was merged into another guest or anonymized answers `accepted: false`.

## Guest corrections

`CorrectStayGuest` sets `full_name` and `nationality` when they are sent and valid. The guest's own value **replaces** the saved one, because the guest is confirming their own details. An empty or unknown value changes nothing. Answer: `{ accepted }`, false only for a bad body or a closed guest.

## Idempotency (for every kiosk write)

| Item       | Rule                                                                                                                                                                                         |
| ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Header     | `Idempotency-Key`, up to 100 characters. A request without one just runs                                                                                                                     |
| Middleware | `api.idempotent` (`ReplayIdempotentRequest`) on the write routes, after `api.token`. `find` reads only and does not use it                                                                   |
| Storage    | Table `api_idempotency_keys`: property, kiosk, key, path, status, body. One row per kiosk, key and path. Only the answer is stored, never the request, so no ID data is kept                 |
| Replay     | The same kiosk, key and path within 24 hours gets the first answer again, with the header `Idempotent-Replayed: true`, even if the body differs. Answers below 500 are stored, 5xx never are |
| Race       | A cache lock per key runs two requests with one key one after the other, so the second replays                                                                                               |
| Cleanup    | `PruneIdempotencyKeys` runs hourly (`prune-api-idempotency-keys`) and deletes rows older than 24 hours                                                                                       |

New kiosk write routes go in the `api.idempotent` group in `routes/api.php`.

## Personal data

The ID comes from the reader to the API and is stored as normal guest data (number encrypted, see [identity documents](../11-guests/02-identity-documents.md)). It is never in a URL, a log or an exception message. A rejected body is not echoed back. The activity log records `kiosk.identity_received` (document type and kiosk id) and `guest.updated` (names of the fields that changed, never values) on the guest.

## Code

| Piece                                                                                                              | Where                          |
| ------------------------------------------------------------------------------------------------------------------ | ------------------------------ |
| `SaveStayIdentity`, `CorrectStayGuest`, `KioskIdentityResult`                                                      | `app/Actions/Kiosk/`           |
| `SetGuestDetails` (shared guest write); `AddIdentityDocument` and `UpdateIdentityDocument` now accept a null actor | `app/Actions/Guests/`          |
| `StoreIdempotentResponse`, `PruneIdempotencyKeys`                                                                  | `app/Actions/ApiAccess/`       |
| `KioskBookingFinder`, `KioskBookingLookup`, `KioskStays`                                                           | `app/Services/Kiosk/`          |
| `IdempotentResponses`                                                                                              | `app/Services/ApiAccess/`      |
| `BookingController`, `StayController`, requests, `V1` resources, `KioskIdentityData`                               | `app/Http/`, `app/Data/Kiosk/` |

## Open

- Not done, needs a contract or product decision: masking `guest_name`, a second factor for a lookup (for example last name or phone), a lookup token between `find` and the writes. Guessing `stay_id` values inside the window is limited only by the `api-token` limit.
- A reservation with several stays: the kiosk gets the first to arrive.
- A QR token needs an Estatell decision (what it is, where it is shown).
- `Accept-Language` is not applied to API messages yet.
