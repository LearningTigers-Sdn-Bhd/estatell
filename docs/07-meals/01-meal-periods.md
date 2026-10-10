# Meal periods

## Purpose

Define when meals are served. Used by package inclusions and the kitchen meal count.

## Built first slice

Every Malaysian property receives Breakfast, Lunch and Dinner through `SeedMealPeriods`. Repeated seeding does nothing once the property has any meal period, so owner changes stay.

Staff with `settings.manage` use `/p/{code}/settings/meal-periods` (`settings.meal-periods`), the Meal periods tab of the property settings page (Settings, Property, Details). It works like the [Age groups](../02-property/04-age-groups.md) tab: one table holds every period (Served, Name, Starts at, Ends at) and one Save button. `MealPeriodController::update` saves with `PUT` on the same URL (`settings.meal-periods.update`). `SaveMealPeriods` saves the whole list in one transaction. Staff can add a period, edit its name and times, and turn it on or off. A row that is not saved yet can be removed from the form.

## Data model

```
meal_periods    id, property_id, name, starts_at, ends_at, active, timestamps
```

## Enums

`DefaultMealPeriod`: Breakfast, Lunch, Dinner. Used for seeding only.

## Rules

- Breakfast 07:00-10:00, Lunch 12:00-14:30 and Dinner 19:00-22:00 are seeded per property.
- Owners can add periods (for example Hi-tea, Supper) and deactivate periods.
- Periods are rows, not an enum, because each property defines its own.
- Periods are always listed by `starts_at`. There is no stored order and no manual reordering. A new Hi-tea at 15:30 sits between Lunch and Dinner by itself. Inclusions point at a period by id, so no rate plan changes when the list changes.
- A period ends after it starts. It cannot cross midnight.
- Names are unique per property, ignoring case.
- Time ranges must not overlap another **active** period of the property. Ranges that only touch (10:00 end, 10:00 start) are allowed. Inactive periods are ignored, so turning a period on again runs the check again.
- A property may have at most 12 periods.
- The Form Request checks every row of the list (names, times, overlap) for field errors. The Action checks overlap again under a property lock.
- The list must contain every saved period. If another owner added a period meanwhile, the save does nothing and shows an error toast.
- Rows keep their ids, so inclusions and the kitchen report keep their reference. Names can move between rows in one save.
- Periods are never deleted. Turn a period off instead.
- A save that changes nothing is not logged. A real change logs `meal_periods.updated` with the before and after lists.

## Lessons from wastays

Meal times were three fixed columns on `hotel_boat_settings`, and boat schedules had `has_breakfast`, `has_lunch`, `has_dinner` booleans. A fourth meal needed a migration. estatell has no link between boats and meal periods: the boat time sets the unit's expected time, and the meal times decide (see [Boat trips](../24-boats/02-boat-trips.md#meals)).
