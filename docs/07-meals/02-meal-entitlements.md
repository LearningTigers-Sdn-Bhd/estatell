# Meal entitlements

## Purpose

Know how many meals the kitchen must prepare per date and meal period.

## Service

`MealEntitlementResolver`: for a reservation unit, returns entitlements per date, per meal period, per guest count.

Inputs:

- The unit's dates and guest count.
- The rate plan's inclusions that have a `meal_period_id`.
- Each inclusion's `starts_on` and `ends_on`.

## Defaults

| Meal      | Starts            | Ends                   |
| --------- | ----------------- | ---------------------- |
| Breakfast | Day after arrival | Departure day          |
| Dinner    | Arrival day       | Night before departure |

## Output

The daily kitchen report groups entitlements by date and meal period. It is also part of the morning handoff after the night audit.

## Future

Use actual arrival and departure times (arrival catches every meal it lands before; departure catches every meal it leaves after), as wastays did for boats.
