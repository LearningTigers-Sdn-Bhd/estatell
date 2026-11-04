# Booking engine microsite

## Purpose

**Status: built for the content and offers endpoints.** How the booking engine microsite, API only websites and the demo sign in to the API. They use the same [token layer](01-api-tokens.md) as kiosks. This file covers access only. The module itself is planned in the [booking engine docs](../29-booking-engine/01-overview.md).

## What differs from a kiosk

| Item              | Kiosk               | Booking client                         |
| ----------------- | ------------------- | -------------------------------------- |
| Owner             | `Kiosk`             | `BookingClient`                        |
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
| Microsite           | Empty         | One for the app      | Published sites in Microsite mode, flag on    |
| API only            | Set           | One per property     | Its one property, in API only mode, flag on   |
| Demo                | Empty         | One fixed test token | Demo properties, on the demo environment only |

No type reaches a suspended property. A Demo client reaches nothing until the [demo item](../29-booking-engine/09-api-only-mode.md#test-mode) is built. A partial unique index allows one active Microsite client.

Create the Microsite client and its token with this command. It prints the token once, and refuses when an active client exists.

```bash
php artisan booking-engine:create-microsite-client
```

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
| API calls             | 120 per minute per token and property, so one busy hotel cannot slow other microsites                  |
| API calls, no code    | 120 per minute per token, on a route with no property in the path, such as `holds/{token}`             |
| Open holds, property  | A cap for every client. This is the real protection against a bug or abuse holding every room          |
| Open holds, guest IP  | Counted from the `X-Guest-IP` header, because the request IP is always the client's server             |
| Header from Microsite | Trusted, because estatell runs the microsite. A hold with no header is refused                         |
| Header from others    | Optional. An API only or demo client's value is only an extra limit, because estatell cannot verify it |

## Rules that stay

- Follow the [Traps](01-api-tokens.md#traps). Never use `auth:sanctum` on `/api`, and always pass abilities to `createToken`.
- The token stays on the client's server. The API allows no browser calls.
- A kiosk token never reaches `/api/booking/v1`, and a booking client token never reaches `/api/kiosk/v1`.
