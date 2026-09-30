# Inclusions

## Purpose

A package is a rate plan with inclusions: meals, transfers, activities. The guest sees one price. Accounting sees revenue per department.

## Data model

```
rate_plan_inclusions    id, rate_plan_id, charge_item_id, meal_period_id (nullable),
                        charging_unit (ChargingUnit), quantity, allocated_amount,
                        starts_on (InclusionStart), ends_on (InclusionEnd), position
```

## Enums

- `InclusionStart`: ArrivalDay, DayAfterArrival.
- `InclusionEnd`: DepartureDay, NightBeforeDeparture.

Both enums already exist (added with meal entitlements). Each has `label()`, `options()` and the date rule (`firstDate()`, `lastDate()`).

## Service

`InclusionAllocator`: splits a night's amount into lines. Example: RM300 Room + Breakfast for 2 guests posts ROOM RM250 and Breakfast RM50.

## Examples

| Package             | Setup                                                    |
| ------------------- | -------------------------------------------------------- |
| Room only           | No inclusions                                            |
| Bed and breakfast   | Breakfast, PerGuestNight, from day after arrival         |
| Full board          | Breakfast, lunch, dinner                                 |
| Full board + hi-tea | Add a Hi-tea inclusion                                   |
| Island package      | Meals + boat transfer (PerStay) + snorkelling (PerGuest) |

## Rules

- PerStay inclusions post once, on the first night.
- Inclusions with a meal period feed [Meal entitlements](../07-meals/02-meal-entitlements.md).
