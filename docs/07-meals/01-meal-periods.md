# Meal periods

## Purpose

Define when meals are served. Used by package inclusions and the kitchen meal count.

## Built first slice

Every Malaysian property receives Breakfast, Lunch and Dinner through `SeedMealPeriods`. Repeated seeding does nothing once the property has any meal period, so owner changes stay.

Staff with `settings.manage` use `/p/{code}/settings/meal-periods` (Settings, Rates, Meal periods). They can add a period, edit its name and times, and turn it on or off. A Sheet holds the form.

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
- The Form Request checks overlap for field errors. The Action checks it again under a property lock, so two owners cannot save overlapping periods at the same time.
- Periods are never deleted. Historical inclusions and the kitchen report keep their reference. Turn a period off instead.

## Lessons from wastays

Meal times were three fixed columns on `hotel_boat_settings`, and boat schedules had `has_breakfast`, `has_lunch`, `has_dinner` booleans. A fourth meal needed a migration. If boats return, link them with a `boat_schedule_meal_period` pivot.
