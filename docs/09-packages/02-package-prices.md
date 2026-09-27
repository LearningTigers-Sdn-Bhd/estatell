# Package prices

## Purpose

Let staff set "RM600 for 3D2N" as one price per stay, based on arrival date.

## Data model

```
package_prices               id, rate_plan_id, room_type_id, arrival_from, arrival_to, amount
rate_plan_arrival_weekdays   rate_plan_id, weekday (Weekday)
```

## Why per stay

Package prices depend on the arrival date. A Friday arrival should cost one price, not Friday rate + Saturday rate.

## Meaning of `amount`

- PerGuest-style package: price per adult per stay. Children use the plan's age bands.
- PerRoom-style package: price per room per stay, plus extra guest charges per stay.

## Rules

- Applies to plans with `is_package = true` and `fixed_nights` set.
- The booking form locks departure = arrival + `fixed_nights`.
- `rate_plan_arrival_weekdays` optionally limits arrival days.
- Stop-sell and closed-to-arrival still come from `rates`.
- `PackagePricing` splits the total across nights (see [Pricing strategies](../08-rates/03-pricing-strategies.md)).

## Edge cases

| Case                       | Behaviour                                                                |
| -------------------------- | ------------------------------------------------------------------------ |
| Guest extends by one night | The extra night uses another rate plan on its `unit_nights.rate_plan_id` |
| Guest leaves early         | `early_departure_policy`: ChargeFull or ChargeUsedNights                 |
| Package price edited       | Existing bookings keep their stored nightly amounts                      |

## Example

Island Escape 3D2N, arrival 1-30 Jun: RM600 per adult. 2 adults + 1 child aged 8 (50%) = RM1,500, stored as RM750 + RM750.

## UI

Package plans show a "price per stay by arrival period" grid instead of the nightly calendar.
