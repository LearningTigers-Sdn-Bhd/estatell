# Pricing strategies

## Purpose

Calculate the price of a stay without mode branches.

## Design

Interface `StayPricing`: returns one amount breakdown per night (base, extra, supplement, total).

| Strategy          | Source                           | Formula                                                                 |
| ----------------- | -------------------------------- | ----------------------------------------------------------------------- |
| `PerRoomPricing`  | `rates`, `rate_occupancy_prices` | Occupancy price if set, else room price + extra adult and child charges |
| `PerGuestPricing` | `rates`, age bands               | Adult rate x adults + children by age band + single supplement          |
| `PerBedPricing`   | `rates`                          | Bed rate                                                                |
| `PackagePricing`  | `package_prices`                 | Stay price by arrival date, split across nights                         |

`PricingBasis::strategy()` returns the strategy class. `RateQuoteService` loops the nights and sums.

## Rules

- `PackagePricing` splits the total with `brick/money` `allocate()`, so cents always add up. Example: RM500 over 3 nights = 166.67 + 166.67 + 166.66.
- Quotes are stored on `unit_nights` at booking. Later rate changes do not change existing bookings.
- All money math uses `brick/money`. Columns are `decimal(12,2)`.

## Lessons from wastays

`Bookings::NightlyPaxPrice` held both modes behind `if sell_mode == "per_person"` branches.
