# Meal periods

## Purpose

Define when meals are served. Used by package inclusions and the kitchen meal count.

## Data model

```
meal_periods    id, property_id, name, starts_at, ends_at, position, active, timestamps
```

## Enums

`DefaultMealPeriod`: Breakfast, Lunch, Dinner. Used for seeding only.

## Rules

- Breakfast 07:00-10:00, Lunch 12:00-14:30 and Dinner 19:00-22:00 are seeded per property.
- Owners can add periods (for example Hi-tea, Supper) and deactivate periods.
- Periods are rows, not an enum, because each property defines its own.
- Time ranges must not overlap within a property (Form Request check).

## Lessons from wastays

Meal times were three fixed columns on `hotel_boat_settings`, and boat schedules had `has_breakfast`, `has_lunch`, `has_dinner` booleans. A fourth meal needed a migration. If boats return, link them with a `boat_schedule_meal_period` pivot.
