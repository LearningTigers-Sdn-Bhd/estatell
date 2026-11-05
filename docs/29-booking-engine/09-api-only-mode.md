# API only mode

## Purpose

**Status: built**, except the [test mode](#test-mode) and the developer guide's content. Admins set the mode on the Features tab: see [Mode switch](07-settings-and-permissions.md#mode-switch).

Let an owner with their own website and developer sell rooms online, using the same API as the microsite. estatell still handles rooms, prices, bookings and money.

```
Microsite:  Guest ──► booking.estatell.com/sand-bay ──► estatell API
API only:   Guest ──► www.sandbayresort.com ──► estatell API
                      (owner's server)
```

## What stays and what changes

| Area                          | Microsite                     | API only                                            |
| ----------------------------- | ----------------------------- | --------------------------------------------------- |
| Who builds the site           | estatell                      | Owner's developer                                   |
| Rooms, prices, holds, booking | estatell                      | estatell, no change                                 |
| Payment and refunds           | Curlec, collected by estatell | The same; the guest still pays on Curlec's checkout |
| SEO, speed and design         | estatell                      | Owner's developer                                   |
| Booking source                | Booking Engine                | Booking Engine                                      |

Commission applies as on the microsite, because estatell still provides booking and payment. Commission fees records it as Booking Engine commission.

## Access

```
booking_return_addresses   id, property_id, url (up to 500), timestamps
                           unique (property_id, url), CHECK url starts with https://
gateway_payments           + return_url (nullable): the address the payment named
booking_clients            + partial unique index: one API only client per property
```

| Item             | Rule                                                                                                                                                                                                                                                                                                               |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Token            | One [Sanctum token](02-api.md#access) per property, shown once, replaceable and revocable. The property keeps one client row, so a new token still reaches its holds                                                                                                                                               |
| Use              | Server only, because the API allows no browser calls                                                                                                                                                                                                                                                               |
| Return addresses | Full `https` addresses with no `#` part, up to 10. Most sites need one or two, such as an English and a Malay page; the cap keeps the list short enough for the owner to check. `POST holds/{token}/payment` takes `return_url`; anything else refuses with 422 `return_url_not_allowed`. A retry may name another |
| Limits on holds  | A cap on open holds per property, so a bug cannot hold every room. The guest IP is an optional extra limit: see [Limits](../28-api-access/04-booking-engine-microsite.md#limits)                                                                                                                                   |
| Endpoints        | Content, search, checkout and manage booking only: see [API](02-api.md#endpoints)                                                                                                                                                                                                                                  |

Curlec always returns the guest to estatell's callback, which sends them to the approved address with `hold={token}&payment=confirming|failed` added. The Curlec allowlist therefore holds only estatell's domain. Removing an address stops new payments from using it; a payment that already named it still returns there.

## API tab

```
┌─ API ──────────────────────────────────────────────────┐
│ API address     https://app.estatell.com/api/booking/  │
│                 v1/properties/ES1142          [ Copy ] │
│                                                        │
│ API token       Created 3 Nov 2026, last used today    │
│                 [ Replace token ]  [ Revoke ]          │
│                                                        │
│ Return addresses                                       │
│ [ e.g. https://www.sandbayresort.com/booking/done    ] │
│                                                        │
│ Developer guide                       [ Open API docs ]│
└────────────────────────────────────────────────────────┘
```

Owners manage the token and return addresses, and admins set the mode. Support cannot change them through view as.

| Piece      | Where                                                                                                                                               |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Route      | `/p/{code}/settings/booking-website/api` (`settings.booking-website.api`, `.api.token.*`, `.api.return-addresses.*`), behind `booking-website.api`  |
| Controller | `BookingApiController`. A Microsite property answers 404                                                                                            |
| Actions    | `IssueBookingApiToken` (create or replace), `RevokeBookingApiToken`, `AddBookingReturnAddress`, `RemoveBookingReturnAddress`. Each change is logged |
| New token  | Flashed once as `apiToken` and shown in a dialog, never stored or put in the URL                                                                    |
| Guide link | `estatell.booking_api_docs_url` (`BOOKING_API_DOCS_URL`)                                                                                            |

Sanctum's own `last_used_at` is off, so the token's owner records "last used", as a kiosk does.

## Test mode

Developers test on the [demo environment](../deployment/demo_environment.md), with Curlec's test system. Production has no test path, so no test code or flag reaches real data.

```
              Testing                               Live
Owner's dev site                         Owner's website
      │                                        │
      ▼                                        ▼
demo.estatell.com/api/booking/v1         app.estatell.com/api/booking/v1
fixed test token                         the property's real token
      │                                        │
      ▼                                        ▼
Demo database, reset nightly             Production database
Curlec test keys                         Curlec live keys
```

Going live changes only the API address and token.

| Demo fact                       | Effect                                        | Plan                                                                                                                                    |
| ------------------------------- | --------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| The database resets every night | A token made by hand would disappear          | `DemoSeeder` stores the hash of one fixed token from an environment setting, with its abilities. `createToken` only makes random tokens |
| Demo properties only            | Developers test on sample rooms               | The owner does one real booking and refund before launch                                                                                |
| Mail is only logged             | No confirmation email arrives                 | Check the booking through the API; test email separately                                                                                |
| No scheduler or queue workers   | No job expires holds; the queue is `sync`     | [Availability ignores expired holds](03-checkout-and-hold.md#holds-and-availability)                                                    |
| A shared token                  | Developers can see each other's test activity | Acceptable for sample data; never use real guest details                                                                                |

Demo needs the flag on for its properties, the test token and Curlec test keys, plus a public webhook. Curlec's test mode has test cards and a mock bank page with Success and Failure buttons.

The developer guide is published with the API, and shows the demo address, the test token and the test payment details.

## Page security

The owner's developer adds Curlec's script tag and opens the checkout with the values from `POST holds/{token}/payment`. They need no Curlec account or keys, because estatell collects the money.

estatell cannot secure the owner's page, so a script injected there has the same effects as on the [microsite](08-microsite.md#page-security). The developer guide must tell developers to:

- Keep the API token on their server, and never send it to the browser.
- Escape all content on their pages.
- Use a strict Content Security Policy that allows Curlec's script.
- Leave analytics, chat and other third-party scripts off the payment step.

estatell's own protections still apply: only approved return addresses, a cap on open holds, and only the signed webhook creates a booking.

## Support boundary

estatell supports the API, and the owner's developer supports their website. In API only mode, the owner's website collects guest data before it reaches estatell. The data processing terms must cover what the owner is responsible for.

Source: [Test card details](https://curlec.com/docs/payments/payments/test-card-details).
