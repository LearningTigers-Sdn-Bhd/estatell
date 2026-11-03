# Booking engine API

## Purpose

The one API behind the microsite, API only websites and, later, new-wastays. It returns content and offers, and takes holds, payments and cancellations. The server decides availability, prices, tax and refunds.

## Endpoints

| Group           | Endpoint                                     | Microsite     | API only | new-wastays |
| --------------- | -------------------------------------------- | ------------- | -------- | ----------- |
| Listing         | `GET /api/v1/properties`                     | Yes (sitemap) | No       | Yes         |
| Marketplace     | `POST /api/v1/search`                        | No            | No       | Yes         |
| Microsite       | `GET /api/v1/sites/{slug}`                   | Yes           | No       | No          |
| Content, search | `GET /api/v1/properties/{code}`              | Yes           | Yes      | Yes         |
|                 | `GET /api/v1/properties/{code}/room-types`   | Yes           | Yes      | Yes         |
|                 | `GET /api/v1/properties/{code}/calendar`     | Yes           | Yes      | Yes         |
|                 | `POST /api/v1/properties/{code}/offers`      | Yes           | Yes      | Yes         |
| Checkout        | `POST /api/v1/properties/{code}/holds`       | Yes           | Yes      | Yes         |
|                 | `POST /api/v1/holds/{token}/payment`         | Yes           | Yes      | Yes         |
|                 | `GET /api/v1/holds/{token}`                  | Yes           | Yes      | Yes         |
| Manage booking  | `GET /api/v1/bookings/{token}`               | Yes           | Yes      | Yes         |
|                 | `GET /api/v1/bookings/{token}/cancellation`  | Yes           | Yes      | Yes         |
|                 | `POST /api/v1/bookings/{token}/cancellation` | Yes           | Yes      | Yes         |

The marketplace endpoints are built and tested now, and nothing calls them until new-wastays exists.

## What each returns

| Endpoint                | Returns                                                                                                                               |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `properties`            | Code, slug, name and city of the properties the token can reach                                                                       |
| `search`                | The lowest price per property for the dates and party                                                                                 |
| `sites/{slug}`          | Property code, template key, image slots, main colour, SEO title and description                                                      |
| `properties/{code}`     | Name, address, coordinates, Maps link, photos, about text, amenities, nearby places, check-in times, policies                         |
| `room-types`            | Name, description, photos, amenities, guest limits, how it is sold                                                                    |
| `calendar`              | Lowest price and closed dates for each date                                                                                           |
| `offers`                | Room types, public rate plans, rooms left, `pay_online` and `pay_at_property` with tax lines, cancellation terms, or a blocked reason |
| `holds` (POST)          | Hold token, expiry, online total, amount to pay at the property                                                                       |
| `holds/{token}/payment` | Curlec `key_id`, `order_id`, amount, callback address, checkout timeout                                                               |
| `holds/{token}` (GET)   | Held, Paid, Confirmed or Expired; the booking token once Confirmed                                                                    |
| `bookings/{token}`      | Dates, rooms, guest, amount paid, status, refund status, cancellation terms                                                           |
| `cancellation` (GET)    | Whether it can be cancelled online, the fee, tax on the fee, the refund amount                                                        |
| `cancellation` (POST)   | The new status and the refund amount                                                                                                  |

## Access

Laravel Sanctum, with one token for each client. The token's abilities decide which endpoints it reaches and its scope decides which properties. The user approved adding Sanctum on 2026-11-03. It is installed when this item is built.

| Token               | Can reach                                     | Source of bookings |
| ------------------- | --------------------------------------------- | ------------------ |
| Microsite app       | Properties in Microsite mode with the flag on | Booking Engine     |
| API only            | One property, from the owner's server         | Booking Engine     |
| Demo test token     | Demo properties, on `demo.estatell.com` only  | Booking Engine     |
| new-wastays (later) | Properties opted in; the setting comes later  | Decided later      |

A property the token cannot reach, or one with the flag off, returns 404. A booking made before the flag went off still works through its booking token.

## Rules

- **Never trusted.** Availability, price, tax and the refund are worked out again on each write. A changed price refuses the hold with `price_changed` and the current offer.
- **Server only.** Tokens stay on the client's server, and the API allows no browser calls (no CORS). React parts of the microsite call its own server routes.
- **Idempotent writes.** Creating a hold, starting a payment and cancelling take an `Idempotency-Key` header. A repeat returns the first result.
- **Rate limits.** Per token, tighter on writes than reads, and a limit on open holds per IP address and per property.
- **Guest tokens.** Hold and booking tokens are long and random. A booking number alone opens nothing.
- **Not exposed.** Folios, guest search, staff data and commission.
- **Errors.** Refusals return 422 with a stable `code` and a message, like the Action refusal codes elsewhere.

## Code

- Routes in `routes/api.php`, under `/api/v1`, with names under `api.`.
- Controllers in `app/Http/Controllers/Api/V1/`, thin, returning Eloquent API Resources.
- Every query is scoped by `property_id`, taken from the path and checked against the token.
