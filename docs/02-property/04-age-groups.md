# Age groups

## Purpose

Who counts as an infant, a child or an adult at this property. Defined once and used by every rate plan, the booking form and channels.

## Data model

```
property_age_groups    id, property_id, label, min_age, max_age, timestamps
                       unique (property_id, min_age), unique (property_id, lower(label))
```

## Built first slice

Staff with `settings.manage` use `/p/{code}/settings/age-groups` (`settings.age-groups`), the Age groups tab of the property settings page (Settings, Property, Details). `AgeGroupController::update` saves with `PUT` on the same URL (`settings.age-groups.update`). One form holds every group and one Save button. `SaveAgeGroups` saves the whole ordered list in one transaction. Rows keep their ids, so rate plan child prices can point at them later. `SeedAgeGroups` seeds Infant 0–3 and Child 4–11 and does nothing once the property has groups.

Not built yet, because rate plans do not exist: the child price rows added or removed with a group, and the "Check child prices" flag. The rate plans commit adds both.

## Rules

- The groups start at age 0 and have no gaps or overlaps. The form never asks for "From". The server derives it: the first group starts at 0 and each next group starts one year after the previous "To". Staff edit only the label, the "To" age and the row order.
- Groups are always listed by `min_age`. There is no stored order.
- "To" is a whole number from 0 to 17 and rises down the list. Labels are unique per property, ignoring case.
- A property may have no groups. Every guest is then an adult.
- Removing a middle group makes the next group absorb its ages. Removing Infant turns Child 4–11 into 0–11.
- A save that changes nothing is not logged.
- A guest counts as an adult from the last group's `max_age + 1`. There is no separate setting.
- `SeedPropertyDefaults` seeds two groups: Infant 0–3 and Child 4–11. Adults start at 12.
- Staff can add, rename, re-range and remove groups.
- Adding a group gives every PerGuest rate plan a child price row for it, set to Free. The rate plans list flags those plans as "Check child prices".
- Removing a group deletes its child price rows.
- Changes apply to new quotes only. Existing bookings keep the amounts stored on `unit_nights`.
- A rate plan never overrides the age ranges. It only prices each group (see [Rate plans](../08-rates/01-rate-plans.md)).

## UI

```
Settings → Property → Details → Age groups tab
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
