# Age groups

## Purpose

Who counts as an infant, a child or an adult at this property. Defined once and used by every rate plan, the booking form and channels.

## Data model

```
property_age_groups    id, property_id, label, min_age, max_age, position, timestamps
                       unique (property_id, min_age)
```

## Rules

- The groups start at age 0 and have no gaps or overlaps. The Form Request checks this.
- A guest counts as an adult from the last group's `max_age + 1`. There is no separate setting.
- `SeedPropertyDefaults` seeds two groups: Infant 0–3 and Child 4–11. Adults start at 12.
- Staff can add, rename, re-range and remove groups.
- Adding a group gives every PerGuest rate plan a child price row for it, set to Free. The rate plans list flags those plans as "Check child prices".
- Removing a group deletes its child price rows.
- Changes apply to new quotes only. Existing bookings keep the amounts stored on `unit_nights`.
- A rate plan never overrides the age ranges. It only prices each group (see [Rate plans](../08-rates/01-rate-plans.md)).

## UI

```
Settings → Guests → Age groups
┌──────────┬──────┬──────┐
│ Label    │ From │ To   │
│ Infant   │ 0    │ 3    │
│ Child    │ 4    │ 11   │
│ + Add age group        │
└────────────────────────┘
Guests aged 12 and over count as adults.
```

## Future

A per-plan age cut-off (for example "kids under 6 stay free" on one promo), only if a client needs it.

## Lessons from wastays

`rate_plan_age_bands` repeated the label and age range on every plan, so five plans meant typing "4–11" five times, and plans could disagree about who is a child.
