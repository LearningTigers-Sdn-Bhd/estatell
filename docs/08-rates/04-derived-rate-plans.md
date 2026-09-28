# Derived rate plans

## Purpose

Plans that follow a parent plan's price: Breakfast +RM50, Member −10%, Walk-in −RM20, Corporate −15%.

## Data model

Columns on `rate_plans`: `parent_rate_plan_id`, `derivation` (`RateDerivation`), `derivation_value`.

- `RateDerivation`: Percent, Amount.
- `derivation_value` is signed. −10 with Percent is 10% off. +50 with Amount is RM50 more.

## Service

`DerivedRateResolver`: returns the parent's amount for a date, adjusted by the derivation. A result never goes below zero.

## Rules

- A derived plan has no own `rates.amount`. It follows the parent's price per date, so parent weekend and holiday prices carry through. It can still have its own restriction rows.
- Derivation chains are limited to one level.
- A derived plan can link only room types that its parent is linked to.
- A parent cannot be archived while active plans follow it (see [Rate plans](01-rate-plans.md#guards)).
- Companies can be linked to derived plans (see [Companies](../15-city-ledger/01-companies.md)).

## Eligible parent

A plan can be a parent when all of these hold:

| Condition                                            | Why                                    |
| ---------------------------------------------------- | -------------------------------------- |
| It is a different plan                               | A plan cannot follow itself            |
| It is active                                         | Archived plans have no future prices   |
| It does not follow another plan                      | One level only                         |
| It is not a Package plan                             | Packages have no nightly price         |
| It is in the same family (room plans or bed plans)   | A bed price cannot follow a room price |
| It is linked to every room type the child plan sells | Otherwise some rows have no price      |

## UI

On the Pricing tab, "Follow another plan" asks for the parent, the direction (Down or Up), the value and the unit (% or RM). A preview shows today's price per room type, for example "Deluxe 800 → 850".

The option is disabled, with the reason in a tooltip, in these cases:

| Case                        | Tooltip                                                                 |
| --------------------------- | ----------------------------------------------------------------------- |
| This is the only plan       | "Add another plan first. This is your only rate plan."                  |
| No plan is eligible         | "No other plan sells Deluxe and Spa Villa at a nightly price."          |
| Other plans follow this one | "Breakfast and Corporate follow this plan, so it needs its own prices." |

## Future

Promo codes: a `promotions` table (code, booking window, stay window, minimum nights) that points to a derived rate plan.

## Lessons from wastays

- Replaces the walk-in, corporate and OTA types of `hotel_pricing_rules`.
- "Adjust Standard Rate" adjusted a fixed `room_types.base_price`, so weekend and date prices on the standard plan did not carry through.
