# ARI sync (outbound)

## Purpose

Send availability, rates and restrictions to Channex quickly and correctly.

## Data model

```
channel_ari_changes    id, channel_connection_id, room_type_id, rate_plan_id (nullable),
                       kind (AriChangeKind), date_from, date_to, sent_at (nullable), created_at
```

## Enums

`AriChangeKind`: Availability, Rates, Restrictions.

## Flow

1. Domain events (`RatesChanged`, `InventoryChanged`, `ReservationChanged`) record date ranges in `channel_ari_changes`.
2. `FlushChannelAriJob` runs about every minute per connection (`ShouldBeUnique`, `WithoutOverlapping`). It merges overlapping ranges, calculates values from `AvailabilityService` and the pricing strategies, sends batched requests and marks `sent_at`.
3. A nightly full refresh re-sends the next 365 days.

## Rules

- No local copy of sent rates. Values are always calculated at send time.
- Long-stay discounts are never sent. Channels get the undiscounted nightly price (see [Rate plans](../08-rates/01-rate-plans.md#long-stay-discounts)).

## Lessons from wastays

`channel_room_rates` kept a copy of rates and availability that could drift. `BufferAriSyncJob` and `FlushAriSyncJob` are replaced by the change table and one flush job.
