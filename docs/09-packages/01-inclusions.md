# Inclusions

## Purpose

A package is a rate plan with inclusions: meals, transfers, activities. The guest sees one price. Accounting sees revenue per department.

## Data model

```
rate_plan_inclusions    id, rate_plan_id, charge_item_id, meal_period_id (nullable),
                        charging_unit (ChargingUnit), quantity, allocated_amount,
                        starts_on (InclusionStart), ends_on (InclusionEnd), position
```

## Built first slice

Staff with `settings.manage` use the Inclusions tab of the rate plan editor (`settings.rate-plans.inclusions.*`, `RatePlanController`). Every plan can have inclusions, Package or not. `SaveRatePlanInclusions` in `app/Actions/Rates/` replaces the whole list in one save: rows with an `id` are updated, new rows are added, rows left out are removed. The list order is `position`.

- **Table built:** `rate_plan_inclusions`, with CHECK constraints on the enum columns, `quantity >= 1` and `allocated_amount >= 0`. A charge item or meal period that has inclusions cannot be deleted (restrict).
- **Form:** one row per inclusion: charge item, optional meal period, charging unit, quantity, amount per unit, starts on, ends on. Picking a charge item prefills its charging unit and default amount. Picking a meal period prefills the dates from the [meal defaults](../07-meals/02-meal-entitlements.md#defaults), matched by the period's name (`DefaultMealPeriod::fromName()`). A renamed period gets arrival day to departure day.
- **Refused:** an archived plan, an `id` from another plan, and a charge item or meal period of another property. An inactive charge item or meal period is refused unless the plan already uses it.
- **Not built yet:** the allocator is not wired into quotes or bookings. The booking and folio items call it.

## Meaning of `allocated_amount`

It is the amount moved off the room for **one unit** of the charging unit. A line is `allocated_amount x quantity x units`. Example: Breakfast, PerGuestNight, RM25, 2 guests = RM50 a night.

## Enums

- `InclusionStart`: ArrivalDay, DayAfterArrival.
- `InclusionEnd`: DepartureDay, NightBeforeDeparture.

Both enums already exist (added with meal entitlements). Each has `label()`, `options()` and the date rule (`firstDate()`, `lastDate()`).

## Service

`InclusionAllocator` (`app/Services/Packages/`): splits a night's amount into lines. Example: RM300 Room + Breakfast for 2 guests posts ROOM RM250 and Breakfast RM50.

`allocate(Money $nightAmount, $inclusions, int $guests, bool $firstNight)` returns an `InclusionSplit`: the `room` remainder and one `InclusionLine` (inclusion, charge item, amount) per inclusion. Lines with a zero amount are left out. The split is refused, with no room and no lines, when there are no guests (`guests_required`) or when the lines add up to more than the night (`exceeds_night_amount`). The room is never negative.

| Charging unit | Units  | Posts            |
| ------------- | ------ | ---------------- |
| PerNight      | 1      | Every night      |
| PerGuestNight | Guests | Every night      |
| PerStay       | 1      | First night only |
| PerItem       | 1      | First night only |
| PerGuest      | Guests | First night only |

`total(Currency $currency, $inclusions, int $guests, bool $firstNight)` returns the sum of the lines of one night, with the same rules. `PackagePricing` uses it to [split a package stay](../08-rates/03-pricing-strategies.md#package-nights-and-inclusions) so each night covers its inclusions. Nightly plans use it to refuse a night priced below its inclusions.

The inclusion dates do not filter nights. They set the meals a unit is owed, through `MealInclusionData::fromInclusion()`.

## Examples

| Package             | Setup                                                    |
| ------------------- | -------------------------------------------------------- |
| Room only           | No inclusions                                            |
| Bed and breakfast   | Breakfast, PerGuestNight, from day after arrival         |
| Full board          | Breakfast, lunch, dinner                                 |
| Full board + hi-tea | Add a Hi-tea inclusion                                   |
| Island package      | Meals + boat transfer (PerStay) + snorkelling (PerGuest) |

## Rules

- PerStay, PerItem and PerGuest inclusions post once, on the first night. A package puts more of its price on the first night to cover them.
- Inclusions with a meal period feed [Meal entitlements](../07-meals/02-meal-entitlements.md).
