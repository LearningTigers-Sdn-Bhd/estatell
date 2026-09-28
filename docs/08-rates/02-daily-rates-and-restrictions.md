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
| PerGuest      | Price for one adult                 |
| PerBed        | Price per bed                       |
| Package       | Null. Rows carry restrictions only. |

## Rules

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

- Weekend and holiday prices are daily rates edited in bulk with a weekday filter. No weekday arrays.
- Any change records a channel sync range (see [ARI sync](../21-channels/04-ari-sync.md)).

## Permissions

| Permission                    | Guards                                    | Default roles  |
| ----------------------------- | ----------------------------------------- | -------------- |
| `rates.override-restrictions` | Book past a restriction at the front desk | Owner, Manager |

## UI

A calendar grid per room type and rate plan, virtualized with `@tanstack/react-virtual`. Bulk edit by date range and weekdays.

## Lessons from wastays

- Occupancy prices existed twice: as `room_rates.occupancy_prices` jsonb and as the `room_type_rate_plan_occupancy_prices` table.
- The travel agent portal could book stop-sell, closed-to-arrival/departure and min/max-stay dates because each channel had its own check.
- wastays stored the Auto inputs in a jsonb column (`occupancy_ladder`) so the editor could reopen as Auto.
