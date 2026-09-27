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

| Pricing basis | `amount` means                      |
| ------------- | ----------------------------------- |
| PerRoom       | Room price for the base occupancy   |
| PerGuest      | Price per adult                     |
| PerBed        | Price per bed                       |
| Package       | Null. Rows carry restrictions only. |

## Rules

- `rate_occupancy_prices` is optional and PerRoom only: an explicit price per adult count.
- Weekend and holiday prices are daily rates edited in bulk with a weekday filter. No weekday arrays.
- Any change records a channel sync range (see [ARI sync](../21-channels/04-ari-sync.md)).

## UI

A calendar grid per room type and rate plan, virtualized with `@tanstack/react-virtual`. Bulk edit by date range and weekdays.

## Lessons from wastays

Occupancy prices existed twice: as `room_rates.occupancy_prices` jsonb and as the `room_type_rate_plan_occupancy_prices` table.
