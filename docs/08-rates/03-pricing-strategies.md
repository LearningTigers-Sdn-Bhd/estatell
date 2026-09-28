# Pricing strategies

## Purpose

Calculate the price of a stay without mode branches.

## Design

Interface `StayPricing`: returns one amount breakdown per night (base, extra, supplement, total).

| Strategy          | Source                                         | Formula                                                                                                                                                     |
| ----------------- | ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PerRoomPricing`  | `rates`, `rate_occupancy_prices`               | Occupancy price if set, else room price + extra charges above `base_occupancy`                                                                              |
| `PerGuestPricing` | `rates`, `rate_occupancy_prices`, child prices | Occupancy price for the adults if set, else adult rate x adults. Plus each child by age group. Plus the single supplement when there is no occupancy price. |
| `PerBedPricing`   | `rates`                                        | Bed rate                                                                                                                                                    |
| `PackagePricing`  | `package_prices`                               | Stay price by arrival date, split across nights                                                                                                             |

`PricingBasis::strategy()` returns the strategy class. `RateQuoteService` loops the nights, applies the long-stay discount to the whole stay, and sums.

## Quote steps

1. The strategy prices each night.
2. `StayDiscount` finds the longest [long-stay discount](01-rate-plans.md#long-stay-discounts) the stay qualifies for and takes it off each night from `from_night`. The amount goes to `unit_nights.discount_amount`.
3. The nights are summed.

## Child prices

- A PerGuest quote needs one age per child. The ages come from `reservation_unit_children` (see [Reservation shape](../12-reservations/01-reservation-shape.md)).
- Each age maps to a [property age group](../02-property/04-age-groups.md). The plan's child price for that group is Free, a percent of the **1-adult** price for the night, or a fixed amount.

Example, Full Board: 1 adult RM400, 2 adults RM600. Infant Free, Child 50%. 2 adults + children aged 2 and 8 = 600 + 0 + 200 = RM800 a night.

## Rules

- `PackagePricing` splits the total with `brick/money` `allocate()`, so cents always add up. Example: RM500 over 3 nights = 166.67 + 166.67 + 166.66.
- Quotes are stored on `unit_nights` at booking. Later rate changes do not change existing bookings.
- All money math uses `brick/money`. Columns are `decimal(12,2)`.

## Lessons from wastays

- `Bookings::NightlyPaxPrice` held both modes behind `if sell_mode == "per_person"` branches.
- When child ages were missing, wastays fell back to a flat child multiplier. estatell requires the ages instead.
