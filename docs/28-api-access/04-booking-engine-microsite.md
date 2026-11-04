# Booking engine microsite

## Purpose

**Status: planned, not built.** How the booking engine microsite will sign in to the API. It uses the same [token layer](01-api-tokens.md) as kiosks. This file covers access only. The module itself is planned in the [booking engine docs](../29-booking-engine/01-overview.md), and its token list is in [Access](../29-booking-engine/02-api.md#access).

## What differs from a kiosk

| Item      | Kiosk               | Microsite                                      |
| --------- | ------------------- | ---------------------------------------------- |
| Token     | One per device      | One per client                                 |
| Owner     | `Kiosk`             | A new `ApiClient` model (to build)             |
| Reaches   | One property        | Properties in Microsite mode with the flag on  |
| Routes    | `/api/kiosk/v1`     | `/api/v1`                                      |
| Abilities | `kiosk:*`           | `v1:*` abilities, chosen with the endpoints    |
| Property  | Read from the owner | Taken from the path, checked against the token |

A kiosk's owner holds its one property. A microsite token reaches many, so `ApiClient` cannot work the same way. This is the main thing to design.

## To build

1. An `ApiClient` model with `HasApiTokens`.
2. `AuthenticateApiToken` accepts an `ApiClient` as well as a `Kiosk`, and sets the right request attribute. Today it accepts only a `Kiosk`.
3. A check that the property in the path is one the client can reach. A property it cannot reach answers 404.
4. The owner records its own last use, because Sanctum's `last_used_at` is off.

## Open question

API only and demo tokens also need an owner. Decide whether they share `ApiClient` when it is designed. An API only client reaches one property, and a demo token reaches demo properties.

## Rules that stay

- Follow the [Traps](01-api-tokens.md#traps). Never use `auth:sanctum` on `/api`, and always pass abilities to `createToken`.
- The token stays on the microsite's server. The API allows no browser calls.
- A kiosk token never reaches `/api/v1`, and a client token never reaches `/api/kiosk/v1`.
