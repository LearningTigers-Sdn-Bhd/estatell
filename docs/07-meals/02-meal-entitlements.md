# Meal entitlements

## Purpose

Know how many meals the kitchen must prepare per date and meal period.

## Service

`MealEntitlementResolver`: for a reservation unit, returns entitlements per date, per meal period, per guest count.

Inputs:

- The unit's dates and guest count.
- The rate plan's inclusions that have a `meal_period_id`.
- Each inclusion's `starts_on` and `ends_on`.

## Built first slice

`MealEntitlementResolver::resolve($arrival, $departure, $guests, $inclusions)` is a stateless service with no tables. Callers pass plain `MealInclusionData` objects (meal period id, its start time for sorting, `InclusionStart`, `InclusionEnd`). It returns `MealEntitlementData` rows (date, meal period id, guests), sorted by date and then meal start time. The inclusions and reservation commits will build the input from real rows.

Rules:

- A meal is served on every date from `InclusionStart::firstDate()` to `InclusionEnd::lastDate()`, inclusive. If the first date is after the last, nothing is served.
- Every guest counts and every guest gets every meal. Age groups (infants) come later.
- Two inclusions for one period give one row per date, so a guest is never fed twice.
- The resolver does not filter by active periods. A period turned off later keeps the meals of existing bookings.
- Times of day on arrival and departure are ignored. Only the dates count.

## Defaults

These are the values the inclusion form will prefill. They are not resolver logic.

| Meal      | Starts            | Ends                   |
| --------- | ----------------- | ---------------------- |
| Breakfast | Day after arrival | Departure day          |
| Dinner    | Arrival day       | Night before departure |

## Output

The daily kitchen report groups entitlements by date and meal period. It is also part of the morning handoff after the night audit.

## Future

Use actual arrival and departure times (arrival catches every meal it lands before; departure catches every meal it leaves after), as wastays did for boats.
