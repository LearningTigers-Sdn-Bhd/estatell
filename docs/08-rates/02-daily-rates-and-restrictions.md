# Daily rates and restrictions

## Purpose

The price and selling restrictions for each room type, rate plan and date.

## Data model

```
rates                     id, room_type_id, rate_plan_id, date, amount (nullable),
                          min_stay, max_stay, closed_to_arrival, closed_to_departure, stop_sell,
                          timestamps
                          unique (room_type_id, rate_plan_id, date)
rate_occupancy_prices     id, rate_id, adults, amount
                          unique (rate_id, adults)
```

## Meaning of `amount`

| Pricing basis | `amount` means                                  |
| ------------- | ----------------------------------------------- |
| PerRoom       | Room price for the room type's `base_occupancy` |
| PerGuest      | Price for one adult                             |
| PerBed        | Price per bed                                   |
| Package       | Null. Rows carry restrictions only.             |

## Rules

- Rows exist only for a plan and room type pair linked in `rate_plan_room_types` (see [Rate plans](01-rate-plans.md)).
- A derived plan has no own `amount`. Its price follows the parent per date (see [Derived rate plans](04-derived-rate-plans.md)).
- `rate_occupancy_prices` is optional: an explicit price per adult count.
    - PerRoom: the room price for that many adults.
    - PerGuest: the total price for that many adults (for example 1 adult RM400, 2 adults RM600).
- The editor can fill occupancy prices with **Auto**: pick a base adult count, then a step up and a step down (amount or %). Auto only fills the fields. The inputs are not stored.
- Restrictions (`stop_sell`, `closed_to_arrival`, `closed_to_departure`, `min_stay`, `max_stay`) are checked by one service, `RestrictionCheck`, for every channel.

| Channel                   | When a restriction fails                                                                                                           |
| ------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| Public site, agent portal | Blocked. The plan is shown with the reason, for example "Min stay 2 nights".                                                       |
| Front desk                | Warning. Staff with `rates.override-restrictions` can continue after entering a reason. The override is logged on the reservation. |
| Channel bookings          | Accepted. The OTA already checked its own copy of the restrictions.                                                                |

- Weekend and holiday prices are daily rates edited in bulk with the pricing form. No weekday arrays.
- Any change records a channel sync range (see [ARI sync](../21-channels/04-ari-sync.md)).

## Permissions

| Permission                    | Guards                                    | Default roles  |
| ----------------------------- | ----------------------------------------- | -------------- |
| `rates.override-restrictions` | Book past a restriction at the front desk | Owner, Manager |

## Rates ending

- A plan with its own prices shows a warning when a linked room type has no price within the next 60 days. The warning shows on the dashboard and the night audit readiness panel.
- **Extend 12 months** copies the last 7 days (Mon–Sun) of each room type forward: `amount`, occupancy prices, `min_stay` and `max_stay`.
- `stop_sell`, `closed_to_arrival` and `closed_to_departure` are not copied. They are date-specific, for example a maintenance stop sell.
- Derived plans have no warning and no button. They follow the parent.

## Pricing form

One form fills `rates` for a date range. The rate plan editor's Pricing tab ("Set prices directly") and the calendar's Bulk edit both use it.

- Header: date range, and a **Simple | By day** switch.
- **Simple**: a Weekdays column and a Weekend column. "Weekend nights" is preselected from `property_settings.weekend_nights` (see [Property settings](../02-property/02-property-settings.md)). Changing it in the form affects only that apply, not the setting and not other plans.
- **By day**: seven columns, Mon to Sun.
- Simple to By day carries the values over. By day to Simple works only when the prices match a weekday and weekend pattern. Otherwise the form asks to stay on By day.
- The mode and the form's weekend choice are not stored. Only `rates` and `rate_occupancy_prices` are written.
- On a phone, By day stacks one card per room type.

| Pricing basis | Simple                                                                                      | By day                                                         |
| ------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| PerRoom       | Weekdays and Weekend per room type. "Different price by adults" adds a row per adult count. | 7 columns per room type                                        |
| PerGuest      | A row per adult count, Weekdays and Weekend, with Auto fill                                 | A row per adult count, 7 columns, one room type open at a time |
| PerBed        | Weekdays and Weekend per room type                                                          | 7 columns per room type                                        |
| Package       | Not used. Package plans use [package prices](../09-packages/02-package-prices.md).          | Not used                                                       |

Bulk edit adds the restriction fields to the same form.

## UI

- A calendar grid grouped by room type, with each linked plan as a row under it. Virtualized with `@tanstack/react-virtual`.
- Filters: month, room type and plan.
- Click a cell to change one date. Bulk edit uses the pricing form.
- Derived plan rows show the calculated price with a marker and are read-only.
- The rates ending warning shows above the grid with the Extend button.

## Lessons from wastays

- Occupancy prices existed twice: as `room_rates.occupancy_prices` jsonb and as the `room_type_rate_plan_occupancy_prices` table.
- The travel agent portal could book stop-sell, closed-to-arrival/departure and min/max-stay dates because each channel had its own check.
- wastays stored the Auto inputs in a jsonb column (`occupancy_ladder`) so the editor could reopen as Auto.
- A pair-level default price (`room_type_rate_plans.pricing_mode`) never ran out, but it competed with daily rates. estatell keeps only daily rates and warns before they run out.
