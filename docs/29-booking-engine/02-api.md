# Booking engine API

## Purpose

The one API behind the microsite, API only websites and, later, new-wastays. It returns content and offers, and takes holds, payments and cancellations. The server decides availability, prices, tax and refunds.

## Endpoints

| Group           | Endpoint                                             | Microsite     | API only | new-wastays |
| --------------- | ---------------------------------------------------- | ------------- | -------- | ----------- |
| Listing         | `GET /api/booking/v1/properties`                     | Yes (sitemap) | No       | Yes         |
| Marketplace     | `POST /api/booking/v1/search`                        | No            | No       | Yes         |
| Microsite       | `GET /api/booking/v1/sites/{slug}`                   | Yes           | No       | No          |
| Content, search | `GET /api/booking/v1/properties/{code}`              | Yes           | Yes      | Yes         |
|                 | `GET /api/booking/v1/properties/{code}/room-types`   | Yes           | Yes      | Yes         |
|                 | `GET /api/booking/v1/properties/{code}/calendar`     | Yes           | Yes      | Yes         |
|                 | `POST /api/booking/v1/properties/{code}/offers`      | Yes           | Yes      | Yes         |
|                 | `POST /api/booking/v1/properties/{code}/pricing`     | Yes           | Yes      | Yes         |
| Checkout        | `POST /api/booking/v1/properties/{code}/holds`       | Yes           | Yes      | Yes         |
|                 | `POST /api/booking/v1/holds/{token}/payment`         | Yes           | Yes      | Yes         |
|                 | `GET /api/booking/v1/holds/{token}`                  | Yes           | Yes      | Yes         |
|                 | `DELETE /api/booking/v1/holds/{token}`               | Yes           | Yes      | Yes         |
| Manage booking  | `GET /api/booking/v1/bookings/{token}`               | Yes           | Yes      | Yes         |
|                 | `GET /api/booking/v1/bookings/{token}/cancellation`  | Yes           | Yes      | Yes         |
|                 | `POST /api/booking/v1/bookings/{token}/cancellation` | Yes           | Yes      | Yes         |

The marketplace endpoints are built and tested now, and nothing calls them until new-wastays exists. No token has the `booking:search` ability yet.

**Built:** listing, marketplace, microsite, content and search, pricing, holds, `holds/{token}/payment` and manage booking.

## What each returns

| Endpoint                 | Returns                                                                                                                                                                                                                                                                                                                                  |
| ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `properties`             | Code, slug, name and city of the properties the token can reach                                                                                                                                                                                                                                                                          |
| `search`                 | The lowest price per property for the dates and party                                                                                                                                                                                                                                                                                    |
| `sites/{slug}`           | Property code, template key, image slots, main colour, currency, SEO title and description                                                                                                                                                                                                                                               |
| `properties/{code}`      | Name, address, coordinates, Maps link, album photos (cover first), about text, amenities, nearby places, check-in and check-out times as `15:00`, policies                                                                                                                                                                               |
| `room-types`             | Name, description, photos, amenities, guest limits, how it is sold                                                                                                                                                                                                                                                                       |
| `calendar`               | Lowest one-night price before tax and closed dates for each date, at most 62 days at a time                                                                                                                                                                                                                                              |
| `pricing`                | Currency, `online_total`, `pay_at_property` and one offer per line. See [Pricing](#pricing)                                                                                                                                                                                                                                              |
| `offers`                 | Room types, public rate plans, rooms left, `pay_online` and `pay_at_property` with tax lines, cancellation terms, or a blocked reason                                                                                                                                                                                                    |
| `holds` (POST)           | Hold token, expiry, online total, amount to pay at the property                                                                                                                                                                                                                                                                          |
| `holds/{token}/payment`  | Curlec `key_id`, `order_id`, `amount` in sen, `currency`, `callback_url`, and `timeout` in seconds. Refusals: `hold_not_open`, `return_url_not_allowed`, 404 `property_not_found`, and 503 `payment_unavailable`, which is not stored for replay. An API only client sends `return_url`: see [API only mode](09-api-only-mode.md#access) |
| `holds/{token}` (GET)    | Held, Paid, Confirmed or Expired; the booking token once Confirmed                                                                                                                                                                                                                                                                       |
| `holds/{token}` (DELETE) | The hold with its new status, Expired                                                                                                                                                                                                                                                                                                    |
| `bookings/{token}`       | Reference, dates, rooms, guest, currency, amount paid, status, refund status, saved cancellation terms. See [Manage booking](#manage-booking)                                                                                                                                                                                            |
| `cancellation` (GET)     | `can_cancel`, `refusal`, `message`, `paid`, `fee`, `tax` on the fee, `refund`                                                                                                                                                                                                                                                            |
| `cancellation` (POST)    | The new `status` and the `refund`. Takes `expected_refund`, the amount the guest saw                                                                                                                                                                                                                                                     |

## Offers

The body of `offers` and `search` is the stay and the party of one room.

| Field         | Rule                                                                     |
| ------------- | ------------------------------------------------------------------------ |
| `arrival`     | Required, `YYYY-MM-DD`. Outside the booking window: 422 `outside_window` |
| `departure`   | Required, after the arrival                                              |
| `adults`      | Required, 1 to 50                                                        |
| `child_ages`  | Optional, each from 0 to 17                                              |
| `nationality` | Optional in `offers`, a 2-letter country code. `search` ignores it       |

- An offer is the price of one room, or one bed for one adult. A bed ignores the party.
- With no nationality, a guest is priced as a local guest, from the property's country. The hold prices again with the real one, so a change in the online total refuses with `price_changed`.
- Only plans in the `bookableOnline` scope are sold. Auto-added items are in the total.
- `pay_online` has `subtotal`, `taxes` and `total`. `pay_at_property` has `taxes` and `total`. A tax line has `name`, `amount` and `inclusive`. See [Taxes in the online total](03-checkout-and-hold.md#taxes-in-the-online-total).
- A blocked offer has `blocked` with a stable `code` and a `message`, and no prices. The codes are `too_many_adults`, `too_many_children`, `restricted`, `not_available`, `pricing_unavailable`, a rate quote refusal such as `rate_missing`, and a tax refusal.
- Cancellation terms come from the live `CANCEL` policy until a booking saves its own. See [Saved terms](05-cancel-and-refund.md#saved-terms).
- `calendar` is a guide, one night at a time. A package plan opens a date but gives no nightly price. `offers` decides whether a stay can be booked.
- `search` returns the lowest `pay_online` total, and leaves out a property with nothing to sell.

## Pricing

`POST properties/{code}/pricing` prices the rooms a guest chose, before a hold. It reads only and needs no key. It uses the same rules as a hold (`BookingBasket`), so its `online_total` is the `total` a hold accepts until prices or availability change.

| Field         | Rule                                                                    |
| ------------- | ----------------------------------------------------------------------- |
| `arrival`     | Required. One stay for all lines                                        |
| `departure`   | Required, after the arrival                                             |
| `nationality` | Optional, a 2-letter country code in capitals. With none, a local guest |
| `lines`       | Required, 1 to 50, the same shape as a hold's lines                     |

- It answers `currency`, `online_total`, `pay_at_property` and `lines`. Each line is an offer, in line order. The totals are the sum of the lines.
- It refuses with 422 and the same codes as a hold: `outside_window`, `plan_not_available`, `not_available` (including several lines that need more rooms than are left), and a blocked offer's code.
- It does not check the open hold limits, and it keeps no rooms.

## Checkout

The body of `POST properties/{code}/holds`:

| Field                                          | Rule                                                                               |
| ---------------------------------------------- | ---------------------------------------------------------------------------------- |
| `arrival`, `departure`                         | As in `offers`                                                                     |
| `total`                                        | Required, the online total the guest saw, with up to 2 decimals                    |
| `guest.name`                                   | Required, up to 150 characters                                                     |
| `guest.email`                                  | Required                                                                           |
| `guest.phone`                                  | Required. Without a country code, it is read as a number of the property's country |
| `guest.nationality`                            | Required, a 2-letter country code in capitals                                      |
| `special_requests`                             | Optional, up to 1000 characters                                                    |
| `lines`                                        | Required, 1 to 50. One line is one room or bed                                     |
| `lines.*.room_type_id`, `lines.*.rate_plan_id` | Required, from the offer                                                           |
| `lines.*.adults`                               | Required, 1 to 50. A bed ignores the party                                         |
| `lines.*.child_ages`                           | Optional, each from 0 to 17                                                        |

Send the guest's address in the `X-Guest-IP` header. The answer holds `token`, `status`, `expires_at`, `online_total` and `pay_at_property`, and no guest details. Reading or releasing a hold returns the same without `pay_at_property`. See [Checkout and hold](03-checkout-and-hold.md).

## Manage booking

The booking token is the hold's token, so a Confirmed hold opens its booking. Any other hold, or another client's, answers 404 `booking_not_found`. These endpoints do not check the property's reach, because a booking made before the flag went off still works.

| Refusal             | Meaning                                                                                             |
| ------------------- | --------------------------------------------------------------------------------------------------- |
| `already_cancelled` | No room is left to cancel                                                                           |
| `already_arrived`   | The arrival date has come, or a room has checked in. The guest contacts the property                |
| `contact_property`  | The fee and its tax are more than the amount paid, or the setup is incomplete                       |
| `refund_changed`    | The refund differs from `expected_refund`. The answer has `current_refund`, and nothing was written |

See [Cancel and refund](05-cancel-and-refund.md).

## Access

Laravel Sanctum, on the same [token layer](../28-api-access/01-api-tokens.md) as kiosks, with one token for each client. Sanctum is already installed. The token's `booking:*` abilities decide which endpoints it reaches, and its owner decides which properties. The owner is a `BookingClient` with a type and an optional property: see [Booking engine microsite](../28-api-access/04-booking-engine-microsite.md).

| Token               | Can reach                                                                                                                        | Source of bookings |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------ |
| Microsite app       | Published sites in Microsite mode, flag on                                                                                       | Booking Engine     |
| API only            | One property, from the owner's server                                                                                            | Booking Engine     |
| Demo test token     | Demo properties with a booking site, in either mode, on `demo.estatell.com` only: see [Test mode](09-api-only-mode.md#test-mode) | Booking Engine     |
| new-wastays (later) | Properties opted in; the setting comes later                                                                                     | Decided later      |

A property the token cannot reach, one with the flag off, or a suspended one returns 404 `property_not_found`. A booking made before the flag went off still works through its booking token.

| Ability            | Endpoints                                                    | Microsite | API only, Demo |
| ------------------ | ------------------------------------------------------------ | --------- | -------------- |
| `booking:listing`  | `properties`                                                 | Yes       | No             |
| `booking:site`     | `sites/{slug}`                                               | Yes       | No             |
| `booking:content`  | `properties/{code}` and the routes below                     | Yes       | Yes            |
| `booking:search`   | `search`                                                     | No        | No             |
| `booking:checkout` | `holds`, `holds/{token}` and its payment, `bookings/{token}` | Yes       | Yes            |

Sanctum matches abilities exactly, so `booking:*` is not a wildcard. The type's abilities are in `BookingClientType::abilities()`. A token made before `booking:checkout` existed does not have it, so it must be made again.

A client reaches only the holds it made. Another client's hold token answers 404 `hold_not_found`.

## Rules

- **Never trusted.** Availability, price, tax and the refund are worked out again on each write. A changed price refuses the hold with `price_changed` and `current_total`, inside `error`.
- **Server only.** Tokens stay on the client's server, and the API allows no browser calls (no CORS). React parts of the microsite call its own server routes.
- **Idempotent writes.** Creating a hold, starting a payment and cancelling take an `Idempotency-Key` header. A repeat returns the first result, including a refusal, so a client sends a new key for each new attempt. See [Idempotency](../28-api-access/03-kiosk-check-in-endpoints.md#idempotency-for-every-kiosk-write).
- **Rate limits.** Per token and property, tighter on writes than reads, with limits on open holds per property and per guest IP. See [Limits](../28-api-access/04-booking-engine-microsite.md#limits).
- **Guest tokens.** Hold and booking tokens are long and random. A booking number alone opens nothing.
- **Not exposed.** Folios, guest search, staff data and commission.
- **Errors.** Refusals return 422 with a stable `code` and a message, like the Action refusal codes elsewhere. Field errors use the same shape, with the code `invalid_fields` and `fields`: `{ "error": { "code": "invalid_fields", "message": "...", "fields": { "guest.email": ["..."] } } }`. A missing, wrong or revoked token returns 401 `unauthorized`, in the [shared error shape](../28-api-access/01-api-tokens.md#errors).

## Code

- Routes in `routes/api.php`, under `/api/booking/v1`, with names under `api.booking.v1.`. They use `api.token:{scope}`, never `auth:sanctum`: see [Traps](../28-api-access/01-api-tokens.md#traps).
- Controllers in `app/Http/Controllers/Api/Booking/`, thin, returning Eloquent API Resources without the `data` wrapper.
- Payment starts in `StartBookingHoldPayment`. See [Payment](04-payment.md).
- Services in `app/Services/BookingEngine/`: `BookingClientReach`, `BookingContent`, `BookingCalendar`, `BookingOffers`, `OnlineStayPrice`, `BookingSearch`, `BookingWindow`, `HeldUnits` and `BookingHoldLimits`.
- Every query is scoped by `property_id`, taken from the path and checked against the token.
