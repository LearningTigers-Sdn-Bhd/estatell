# ARI sync (outbound)

## Purpose

Send availability, rates and restrictions to Channex as changes happen, in as few calls as possible, within Channex's limits and certification rules.

## Data model

```
channel_ari_changes    id, channel_property_id, room_type_id, rate_plan_id (nullable),
                       kind (AriChangeKind), date_from, date_to,
                       sent_at (nullable), task_id (nullable), attempts, last_error, created_at
```

Timestamps here and on `channel_properties` are `timestamp` (UTC), like most tables. A `timestamptz` column read back wrong on a database whose time zone is not UTC.

## Built

| Piece                                                    | Role                                                                                                                                |
| -------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `RatesChanged`, `InventoryChanged`, `ReservationChanged` | Events in `app/Events`. Each carries the property, the nights (both included), and optionally the room type and plan.               |
| `RecordChannelAriChange`                                 | Turns an event into outbox lines for every mapped pair it touches and starts the delayed flush. Registered in `AppServiceProvider`. |
| `ChannelAriWindow`                                       | Today in the property time zone and the next 499 days. Past and far dates are cut off.                                              |
| `ChannelAriValues`                                       | Calculates availability and per-pair rate and restrictions at send time.                                                            |
| `ChannelAriRanges`                                       | Merges overlapping ranges and joins consecutive equal dates.                                                                        |
| `FlushChannelAri`                                        | Sends one `/restrictions` and one `/availability` call; marks, defers or refuses the lines.                                         |
| `FlushChannelAriJob`                                     | Unique per property and kind, `WithoutOverlapping`. Puts itself back with the wait on a limit.                                      |
| `QueueChannelFullSync`, `channels:full-sync`             | The 500-day full sync: first sync, admin **Full refresh**, nightly 03:00 MYT, 5 s apart.                                            |

### Where the events are dispatched

- `RatesChanged`: `UpsertRates` (every rate and restriction save, with the dates and room type), and the plan-wide rules `SavePackagePricing`, `SaveRatePlanDerivation`, `UpdateRatePlan`, `SaveRatePlanChildPrices` and `ChangeRatePlanBasis` (all 500 days). A change to a parent plan also records its derived plans.
- `InventoryChanged`: room block create, update, end and cancel (the block nights), and room create, generate, archive, restore and room type change (all 500 days).
- `ReservationChanged`: `AddUnit`, `CancelUnit` (also `MarkNoShow`), `ReinstateUnit`, `ChangeUnitDates` and `ProcessEarlyDeparture` (new and old nights).
- Not hooked, so the nightly full sync repairs them: a change to the rate plan's room types, extra adult and child amounts that arrive through other Actions, and beds (dorm beds are not on channels yet).

### Decisions made while building

- A date with no price (or a price of 0) is sent as `stop_sell: true` with no `rate`, because Channex refuses a rate of 0 and estatell cannot sell the date.
- Every restriction field is sent on every range, so a value removed in estatell clears at Channex: a missing min stay is sent as 1 and a missing max stay as 0.
- A flat package is sent as its stay price divided by its nights, rounded to 2 decimals. The total can differ by a few cents when the price does not divide evenly.
- A per-guest plan sends one price for each adult count (1 to the room type's max adults); a per-room plan sends the price for the top count, which is the one option the structure sync creates.
- Channex answers 200 with `meta.warnings` for a rejected value. That counts as a refusal, so the line is not marked sent.
- A refused line keeps `last_error` and is left out of normal flushes. A full run (admin **Full refresh** or nightly) sends it again.
- Admin **Full refresh** is refused inside 24 hours of the last full sync. The nightly run skips a property fully synced in the last 12 hours, such as one that was first synced that evening.
- Backoff after a limit is the larger of `Retry-After` and 60 s × 2^(attempts − 1), up to 15 minutes. The property is paused for that time (`paused_until`) and the admin tab shows the paused line.

## Enums

`AriChangeKind`: Availability, Rates, Restrictions.

## Flow

```
Rate calendar · Bulk edit · Extend rates · Bookings · Room blocks
   │ RatesChanged / InventoryChanged / ReservationChanged
   ▼
channel_ari_changes (outbox: date ranges, sent_at null)
   │ the first unsent change dispatches FlushChannelAriJob with a 10–30 s delay,
   │ unique per Channex property; later changes join the same flush
   ▼
FlushChannelAriJob
   ├─ merge ranges, calculate values at send time
   ├─ consecutive dates with equal values → one date_from/date_to range
   ├─ 1 × POST /restrictions (rates + restrictions, all rate plans)
   ├─ 1 × POST /availability (all room types)
   ├─ success → sent_at, task_id
   └─ failure → see Limits and failures
```

1. Domain events record date ranges in `channel_ari_changes`. Changes are detected as they happen. Nothing polls the rates tables.
2. The flush job is `ShouldBeUnique` and `WithoutOverlapping` per Channex property. It is not a fixed cron.
3. Values come from `AvailabilityService` and the pricing strategies when the job runs.
4. Only properties with `channel_properties.status` Synced are flushed. Changes for a paused property wait.

## Payload

- `/restrictions`: rate (with one price per occupancy for `per_person` plans), `stop_sell`, `closed_to_arrival`, `closed_to_departure`, `min_stay_arrival`, `max_stay`.
- estatell checks min stay on the arrival date (`RestrictionCheck`), so only `min_stay_arrival` is sent.
- `/availability`: free units per room type per date.
- Each message stays under 10 MB. Channex has no limit on the number of changes in one message.

## Full sync

| When                     | What                                                                                 |
| ------------------------ | ------------------------------------------------------------------------------------ |
| First structure sync     | 500 days: 1 × `/availability` (all room types), 1 × `/restrictions` (all rate plans) |
| **Full refresh** (admin) | The same 2 calls                                                                     |
| Nightly                  | The same 2 calls, at about 03:00 MYT, staggered a few seconds per property           |

Channex allows one full sync per property per 24 hours, off-peak. A full sync on a short timer fails certification.

## Limits and failures

- Channex limit: 10 restriction/price calls and 10 availability calls per minute per property.
- `ChannexClient` allows 5 calls per minute per endpoint per property (from wastays' `AriRateLimiter`): any 60-second window spans at most two clock minutes, so it never passes 10.
- `ChannexClient` is stateless. It counts calls with Laravel's `RateLimiter` (key `channex:ari:{channex property id}:{path}`, read from `values[0].property_id`). When the budget is used up, or on a 429 (it honours `Retry-After`), a 5xx or a network error, it sends nothing more and throws `ChannexRetryLater` with `retryAfter` (60 s by default). A 422 or other 4xx throws `ChannexRequestFailed` with the errors and is not retried. The client writes no pause; the flush job catches the exception and does the steps below.
- On 429, 5xx or a network error: `sent_at` stays null, `attempts` goes up, `channel_properties.paused_until` is set 60 seconds ahead, and the retry backs off exponentially. **Changes are never dropped.**
- After repeated failures the admin Channels tab shows "Paused after a rate limit (429). 6 changes waiting, none lost." and an alert.
- A 422 (validation) keeps `last_error` and shows as a Rate error alert with **Retry**.

## Rules

- No local copy of sent rates. Values are always calculated at send time.
- Long-stay discounts are never sent. Channels get the undiscounted nightly price (see [Rate plans](../08-rates/01-rate-plans.md#long-stay-discounts)).
- Unsupported pairs from `ChannelCapability` (day use, dorm beds for now) are never sent.

## Lessons from wastays

`channel_room_rates` kept a copy of rates and availability that could drift. `BufferAriSyncJob` (30 s outbox) and `FlushAriSyncJob` (one `/availability` and one `/restrictions` call, ranges compressed) were right and are kept as one change table and one flush job.
