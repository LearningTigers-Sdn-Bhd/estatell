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

`MealEntitlementResolver::resolve($arrival, $departure, $guests, $inclusions)` is a stateless service with no tables. Callers pass plain `MealInclusionData` objects (meal period id, its start time for sorting, `InclusionStart`, `InclusionEnd`). It returns `MealEntitlementData` rows (date, meal period id, guests), sorted by date and then meal start time. `MealInclusionData::fromInclusion()` builds the input from a rate plan inclusion row (load its `mealPeriod` first). The reservation commit will call the resolver with a plan's meal inclusions.

Rules:

- A meal is served on every date from `InclusionStart::firstDate()` to `InclusionEnd::lastDate()`, inclusive. If the first date is after the last, nothing is served.
- Every guest counts and every guest gets every meal. Age groups (infants) come later.
- Two inclusions for one period give one row per date, so a guest is never fed twice.
- The resolver does not filter by active periods. A period turned off later keeps the meals of existing bookings.
- Times of day on arrival and departure are ignored. Only the dates count.

## Defaults

These are the values the inclusion form prefills, by meal period name (`DefaultMealPeriod::inclusionStart()` and `inclusionEnd()`). Lunch is arrival day to departure day. They are not resolver logic.

| Meal      | Starts            | Ends                   |
| --------- | ----------------- | ---------------------- |
| Breakfast | Day after arrival | Departure day          |
| Dinner    | Arrival day       | Night before departure |

## Output

The daily kitchen report groups entitlements by date and meal period. It is also part of the morning handoff after the night audit.

The Meal counts report ([core reports](../22-reports/01-core-reports.md#built-meal-counts)) and the night audit message both read `MealCounts`. It counts Confirmed, Checked-in and Checked-out units (an early check-out has already moved the departure date) and leaves out Cancelled and No-show units. It also leaves out day use units: a day use on a per-night plan with breakfast has no meal (see [Day use: entered price](../12-reservations/10-day-use.md#entered-price)). It splits each count into adults and children, with the total beside them.

## Arrival and departure times

Only when the [Boats](../24-boats/02-boat-trips.md#meals) feature is on. `MealCounts` passes the unit's `arrival_time` and `departure_time`, and the resolver trims the first and last day:

- **Arrival day:** a meal counts when `arrival_time` is before the meal's `ends_at`.
- **Departure day:** a meal counts when `departure_time` is after the meal's `starts_at`.
- Days in between are not changed.
- A unit with no boat trip is trimmed too, by its default times (the property's check-in and check-out times).

It only trims what the inclusions already give, and it changes the kitchen counts only, never postings or prices. With the feature off, times of day are ignored as before. wastays did the same for boats with fixed meal flags on each boat schedule.
