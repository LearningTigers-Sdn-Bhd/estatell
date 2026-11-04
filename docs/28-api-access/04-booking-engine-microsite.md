# Booking engine microsite

## Purpose

**Status: planned, not built.** How the booking engine microsite, API only websites and the demo will sign in to the API. They use the same [token layer](01-api-tokens.md) as kiosks. This file covers access only. The module itself is planned in the [booking engine docs](../29-booking-engine/01-overview.md).

## What differs from a kiosk

| Item              | Kiosk               | Booking client                         |
| ----------------- | ------------------- | -------------------------------------- |
| Owner             | `Kiosk`             | `BookingClient` (to build)             |
| Routes            | `/api/kiosk/v1`     | `/api/booking/v1`                      |
| Route names       | `api.kiosk.v1.*`    | `api.booking.v1.*`                     |
| Abilities         | `kiosk:*`           | `booking:*`, chosen with the endpoints |
| Controllers       | `Api/Kiosk/`        | `Api/Booking/`                         |
| Request attribute | `kiosk`             | `booking_client`                       |
| Property          | Read from the owner | Taken from the path, checked for reach |

A kiosk's owner holds its one property. A Microsite client reaches many, so the property must come from the path.

## Table `booking_clients`

One owner model serves every booking client, so there is one token check and one reach check.

```
booking_clients   id, type (BookingClientType), property_id (nullable), name,
                  last_used_at (nullable), revoked_at (nullable), timestamps
                  CHECK: an API only client has a property; the other types have none
```

| `BookingClientType` | `property_id` | Tokens               | Reaches                                       |
| ------------------- | ------------- | -------------------- | --------------------------------------------- |
| Microsite           | Empty         | One for the app      | Properties in Microsite mode with the flag on |
| API only            | Set           | One per property     | Its one property                              |
| Demo                | Empty         | One fixed test token | Demo properties, on the demo environment only |

Each later client with a booking purpose, such as new-wastays, adds a type and a reach rule. An API with another purpose gets its own owner model and prefix, as kiosks do.

## On each request

1. Sanctum finds the token and its `BookingClient`.
2. `AuthenticateApiToken` checks the ability and sets the `booking_client` request attribute. A missing, wrong or revoked token answers 401 `unauthorized`.
3. The rate limiter reads the attribute.
4. The reach check tests the property in the path. A property the client cannot reach answers 404.
5. The controller runs.

## Limits

| Limit                 | Rule                                                                                                   |
| --------------------- | ------------------------------------------------------------------------------------------------------ |
| API calls             | Per token and property, so one busy hotel cannot slow other microsites                                 |
| API calls, no code    | A route with no property code in the path, such as `holds/{token}`, is limited per token               |
| Open holds, property  | A cap for every client. This is the real protection against a bug or abuse holding every room          |
| Open holds, guest IP  | Counted from the `X-Guest-IP` header, because the request IP is always the client's server             |
| Header from Microsite | Trusted, because estatell runs the microsite. A hold with no header is refused                         |
| Header from others    | Optional. An API only or demo client's value is only an extra limit, because estatell cannot verify it |

## To build

1. A `BookingClient` model with `HasApiTokens`, the `booking_clients` table and `BookingClientType`.
2. `AuthenticateApiToken` accepts a `BookingClient` as well as a `Kiosk`, and sets the right request attribute. Today it accepts only a `Kiosk`.
3. The reach check.
4. The `api-token` limiter reads `booking_client`, and counts per token and property.
5. The owner records its own last use, because Sanctum's `last_used_at` is off.

## Rules that stay

- Follow the [Traps](01-api-tokens.md#traps). Never use `auth:sanctum` on `/api`, and always pass abilities to `createToken`.
- The token stays on the client's server. The API allows no browser calls.
- A kiosk token never reaches `/api/booking/v1`, and a booking client token never reaches `/api/kiosk/v1`.
