# Pricing strategies

## Purpose

Calculate the price of a stay without mode branches.

## Design

Interface `StayPricing`: `priceStay()` returns one amount breakdown per night (base, extra, supplement, total) for the whole stay. `NightlyPricing` is the base class of the three nightly strategies. It loads the `rates` rows, refuses a night with no price, and calls `priceNight()` for each night. `PackagePricing` prices the stay as a whole.

| Strategy          | Source                                         | Formula                                                                                                                                                     |
| ----------------- | ---------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PerRoomPricing`  | `rates`, `rate_occupancy_prices`               | Occupancy price if set, else room price + extra charges above `base_occupancy`                                                                              |
| `PerGuestPricing` | `rates`, `rate_occupancy_prices`, child prices | Occupancy price for the adults if set, else adult rate x adults. Plus each child by age group. Plus the single supplement when there is no occupancy price. |
| `PerBedPricing`   | `rates`                                        | Bed rate                                                                                                                                                    |
| `PackagePricing`  | `package_prices`                               | Stay price by arrival date, split so each night covers its inclusions                                                                                       |

`PricingBasis::strategy()` returns the strategy class for every basis. `RateQuoteService` asks the strategy for the nights, adjusts a derived plan's nights, applies the long-stay discount to the whole stay, and sums.

## Built first slice

`RateQuoteService::quote(RateQuoteRequest)` in `app/Services/Rates/`. It reads only. Strategies live in `app/Services/Rates/Pricing/` and implement `StayPricing`.

- **Input:** rate plan, room type, arrival, departure (check-out), `adults`, `childAges` (list) and `beds` (default 1, used by PerBed).
- **Output:** `RateQuote` with one `NightPrice` per night (`base`, `extra`, `supplement`, `discountAmount`), a `total` and a `discountAmount`. `StayDiscount` sets the discount from the plan's [long-stay discounts](01-rate-plans.md#long-stay-discounts). A strategy's `discountUnits()` says how many units an Amount is taken off: guests, beds or rooms.
- **Refused, not guessed:** the quote returns a `refusal` reason (and the date, where one applies) for these cases: no nights, no adults, no beds, an archived plan, a room type the plan does not sell, a night with no price, an age group with no child price (PerGuest, and a package charged per guest), a package stay that is not `fixed_nights` long (`stay_length_mismatch`), a package with no price for the arrival date (`package_price_missing`), and a package whose stay price is less than its inclusions (`package_below_inclusions`).
- **Restrictions are not checked here.** `RestrictionCheck` does that.
- **Guests:** each child age maps to the property's age group. An age past the last group counts as an adult, for every basis.
- **PerRoom:** a price for the adult count in `rate_occupancy_prices` wins. Otherwise the room price plus `extra_adult_amount` for each adult above the room type's `base_occupancy`. Children never count toward `base_occupancy`. Each child pays `extra_child_amount` flat, with or without an occupancy price.
- **PerGuest:** the occupancy price for the adult count, else adult rate x adults. `extra_adult_amount` is not used. The `single_supplement` is added for one adult with no occupancy price for 1 adult. A Percent child price is a percent of the 1-adult price for the night (its occupancy price if set, else the rate), rounded half up to 2 decimals.
- **PerBed:** bed rate x `beds`.
- **Package:** `PackagePricing` reads the `package_prices` row for the plan and room type whose period holds the arrival date (both ends included). It needs `fixed_nights` nights. It ignores `rates` rows, which only carry restrictions.
    - Charged per guest: price × adults. Each child by age group: Free, Percent of the adult price, or Fixed, which is an amount **for the stay**, not per night.
    - Charged per room: the room price, plus `extra_adult_amount` for each adult above `base_occupancy`, plus `extra_child_amount` for each child, all per stay.
    - A Bed room type: price × `beds`. Child prices and extra guest amounts do not apply.
    - The adults or room part is `base` and the children or extra guests part is `extra`. Each is split across the nights with `allocate()`, the extra cents going to the first nights. `supplement` is 0.
    - Then `fitInclusions()` splits the stay again so each night covers the inclusions it posts (see [Package nights and inclusions](#package-nights-and-inclusions)).
    - The arrival weekdays are not checked here. `RestrictionCheck` reports them.
- **Derived plans:** `RateQuoteService` prices a derived plan with its parent's strategy and rates, then `DerivedRateResolver` adjusts each night (see [derived rate plans](04-derived-rate-plans.md)). A missing parent rate is refused as `rate_missing`, and an archived parent as `plan_archived`. A derived package is priced by `PackagePricing` from its parent's package prices, then adjusted by a percent only (see [Derived packages](04-derived-rate-plans.md#derived-packages)).

## Quote steps

1. The strategy prices each night.
2. A derived plan adjusts each night.
3. The strategy's `fitInclusions()` gets the nights and the sold plan's inclusions. Nightly strategies return the nights as they are. `PackagePricing` splits the stay again (below).
4. `StayDiscount` finds the longest [long-stay discount](01-rate-plans.md#long-stay-discounts) the stay qualifies for and takes it off each night from `from_night`. The quote returns the amount as `discount_amount`. The booking stores it on `unit_nights.discount_amount`.
5. The nights are summed.

## Package nights and inclusions

[Inclusions](../09-packages/01-inclusions.md) post one-off items (PerStay, PerItem, PerGuest) on the first night. An even split can leave the first night smaller than its inclusions, and then the night audit refuses the room charge (`exceeds_night_amount`). So `PackagePricing::fitInclusions()` splits the stay by what each night includes:

1. `InclusionAllocator::total()` gives the inclusions of each night, with the same rules and guest count as the posting.
2. Room part = stay total − all inclusions, split evenly with `allocate()`.
3. Each night = its inclusions + its room part.
4. `extra` is split by these night amounts with `allocate()`. `base` is the night amount − `extra`. The stay total does not change.

It runs after the derived percent, with the inclusions of the plan being sold, so a derived package with its own inclusions fits too. A plan with no inclusions keeps the even split. When the inclusions add up to more than the stay total, the quote is refused (`package_below_inclusions`).

Example, Dive Package, 2 adults, RM880 per adult, 3 nights: meals RM100 per guest a night, diving RM180 × 2 per guest once. Inclusions: RM920 on night 1, RM200 on nights 2 and 3. Room part RM440 = 146.67 + 146.67 + 146.66. Nights: RM1,066.67 + RM346.67 + RM346.66 = RM1,760. The even split (586.67 a night) could not post night 1.

Only packages are re-split. A nightly plan prices each date on its own, so a large one-off inclusion on a nightly plan can still be refused at posting. That is future work.

## Child prices

- A PerGuest quote needs one age per child. `RateQuoteService` takes the ages as input. The booking passes the ages from `reservation_unit_children` (see [Reservation shape](../12-reservations/01-reservation-shape.md)).
- Each age maps to a [property age group](../02-property/04-age-groups.md). The plan's child price for that group is Free, a percent of the **1-adult** price for the night, or a fixed amount.

Example, Full Board: 1 adult RM400, 2 adults RM600. Infant Free, Child 50%. 2 adults + children aged 2 and 8 = 600 + 0 + 200 = RM800 a night.

## Rules

- `PackagePricing` splits the total with `brick/money` `allocate()` (`AllocationMode::FloorToFirst`), so cents always add up. Example with no inclusions: RM500 over 3 nights = 166.67 + 166.67 + 166.66.
- Quotes are stored on `unit_nights` at booking. Later rate changes do not change existing bookings.
- All money math uses `brick/money`. Columns are `decimal(12,2)`.

## Lessons from wastays

- `Bookings::NightlyPaxPrice` held both modes behind `if sell_mode == "per_person"` branches.
- When child ages were missing, wastays fell back to a flat child multiplier. estatell requires the ages instead.
